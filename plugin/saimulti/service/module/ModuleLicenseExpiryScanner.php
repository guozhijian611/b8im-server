<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Manifest\Manifest;
use B8im\ModuleSdk\State\ModuleStateMachine;
use B8im\ModuleSdk\State\TenantModuleStatus;
use RuntimeException;
use support\think\Db;
use Throwable;

final class ModuleLicenseExpiryScanner
{
    private const LOCK_KEY = 'module_license:expiry_scan:lock';

    private readonly ModuleLockExecutor $lifecycleLocks;

    private readonly ModuleTransactionExecutor $transactions;

    private readonly ModuleAuthCacheInvalidator $authCaches;

    public function __construct(
        private readonly DistributedLockInterface $lock,
        private readonly ModuleAccessService $access,
        private readonly ModuleAuditWriter $audit,
        private readonly ModuleLifecycleHookRunner $hooks,
        ?ModuleTransactionExecutor $transactions = null,
        ?ModuleAuthCacheInvalidator $authCacheInvalidator = null,
    ) {
        $this->lifecycleLocks = new ModuleLockExecutor(
            $lock,
            (int) config('plugin.saimulti.module.lifecycle_lock_ttl_seconds', 900),
        );
        $this->transactions = $transactions ?? new ModuleTransactionExecutor();
        $this->authCaches = $authCacheInvalidator ?? new ModuleAuthCacheInvalidator();
    }

    /** @return array{acquired: bool, scanned: int, expired: int, skipped: int, failed: int} */
    public function run(?int $batchSize = null): array
    {
        $batchSize ??= (int) config('plugin.saimulti.module.expiry_scan_batch_size', 200);
        $batchSize = max(1, min(1000, $batchSize));
        $ttl = (int) config('plugin.saimulti.module.expiry_lock_ttl_seconds', 55);
        $token = bin2hex(random_bytes(20));
        if (!$this->lock->acquire(self::LOCK_KEY, $token, $ttl)) {
            return ['acquired' => false, 'scanned' => 0, 'expired' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $result = ['acquired' => true, 'scanned' => 0, 'expired' => 0, 'skipped' => 0, 'failed' => 0];
        try {
            $rows = Db::table('sm_tenant_module_license')
                ->whereIn('status', [
                    TenantModuleStatus::AUTHORIZED->value,
                    TenantModuleStatus::ENABLED->value,
                    TenantModuleStatus::DISABLED->value,
                ])
                ->whereNotNull('expire_at')
                ->where('expire_at', '<=', date('Y-m-d H:i:s'))
                ->whereNull('delete_time')
                ->order('expire_at', 'asc')
                ->limit($batchSize)
                ->select()
                ->toArray();

            foreach ($rows as $row) {
                $result['scanned']++;
                try {
                    $outcome = $this->lifecycleLocks->run(
                        (string) $row['module_key'],
                        fn (): array => $this->expireRow($row),
                    );
                    if (!$outcome['updated']) {
                        $result['skipped']++;
                        continue;
                    }
                    $result['expired']++;
                    if ($outcome['hook_error'] !== null) {
                        $result['failed']++;
                    }
                } catch (ModuleLockUnavailable) {
                    // A lifecycle operation is changing this module. The row is
                    // retried by the next scan after that operation commits.
                    $result['skipped']++;
                } catch (Throwable) {
                    $result['failed']++;
                }
            }
        } finally {
            $this->lock->release(self::LOCK_KEY, $token);
        }

        if ($result['failed'] > 0) {
            throw new RuntimeException(sprintf('模块授权到期扫描有 %d 条处理失败。', $result['failed']));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array{updated: bool, hook_error: ?string}
     */
    private function expireRow(array $candidate): array
    {
        $updated = false;
        $hookError = null;
        $result = $this->transactions->run(function () use ($candidate, &$updated, &$hookError): array {
            $row = Db::table('sm_tenant_module_license')
                ->where('id', $candidate['id'])
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if (!$row
                || !in_array($row['status'], [
                    TenantModuleStatus::AUTHORIZED->value,
                    TenantModuleStatus::ENABLED->value,
                    TenantModuleStatus::DISABLED->value,
                ], true)
                || $row['expire_at'] === null
                || strtotime((string) $row['expire_at']) > time()) {
                return ['updated' => false, 'hook_error' => null];
            }

            ModuleStateMachine::assertTenantTransition(
                TenantModuleStatus::from($row['status']),
                TenantModuleStatus::EXPIRED,
            );

            $hook = null;
            if ($row['status'] === TenantModuleStatus::ENABLED->value) {
                try {
                    $hook = $this->runExpiryDisableHook($row);
                } catch (Throwable $exception) {
                    // Transactional hook writes are rolled back to their nested
                    // savepoint. The authorization still expires fail-closed.
                    $hookError = mb_substr($exception->getMessage(), 0, 4000);
                }
            }

            $affected = Db::table('sm_tenant_module_license')
                ->where('id', $row['id'])
                ->where('status', $row['status'])
                ->where('expire_at', $row['expire_at'])
                ->where('version', $row['version'])
                ->update([
                    'status' => TenantModuleStatus::EXPIRED->value,
                    'version' => (int) $row['version'] + 1,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ($affected !== 1) {
                throw new RuntimeException('授权到期记录已被并发修改。');
            }

            $this->audit->write(
                $row['module_key'],
                'tenant_expire',
                $row['status'],
                TenantModuleStatus::EXPIRED->value,
                null,
                null,
                $hookError === null,
                ['type' => 'system'],
                (int) $row['organization'],
                $hookError,
                [
                    'expire_at' => $row['expire_at'],
                    'reason' => $hookError === null ? 'expiry' : 'expiry_disable_hook_failed',
                    'license_version_from' => $row['version'],
                    'license_version_to' => (int) $row['version'] + 1,
                    'hook' => $hook,
                ],
            );
            $this->touchProjectionVersion((int) $row['organization']);
            $updated = true;

            return ['updated' => true, 'hook_error' => $hookError];
        }, [
            function () use (&$updated, $candidate): void {
                if ($updated) {
                    $this->access->invalidate((int) $candidate['organization'], (string) $candidate['module_key']);
                }
            },
            function () use (&$updated, $candidate): void {
                if ($updated) {
                    $this->authCaches->tenantStateChanged((int) $candidate['organization']);
                }
            },
        ]);

        return $result;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function runExpiryDisableHook(array $row): array
    {
        $module = Db::table('sm_module')
            ->where('module_key', $row['module_key'])
            ->whereNull('delete_time')
            ->find();
        if (!$module) {
            throw new RuntimeException(sprintf('到期授权对应模块不存在: %s', $row['module_key']));
        }
        $manifestData = json_decode((string) $module['manifest_json'], true);
        if (!is_array($manifestData)) {
            throw new RuntimeException(sprintf('已安装模块 manifest 快照无效: %s', $row['module_key']));
        }

        return $this->hooks->run(
            new Manifest($manifestData),
            LifecycleOperation::DISABLE,
            (int) $row['organization'],
            options: ['reason' => 'expiry', 'actor' => ['type' => 'system']],
        );
    }

    private function touchProjectionVersion(int $organization): void
    {
        $updated = Db::table('sm_system_organization')
            ->where('id', $organization)
            ->whereNull('delete_time')
            ->inc('config_version')
            ->update();
        if ($updated !== 1) {
            throw new RuntimeException(sprintf('无法更新租户 %d 的客户端配置版本。', $organization));
        }
    }
}
