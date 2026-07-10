<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Manifest\Manifest;
use B8im\ModuleSdk\State\ModuleStateMachine;
use B8im\ModuleSdk\State\SystemModuleStatus;
use B8im\ModuleSdk\State\TenantModuleStatus;
use Composer\Semver\Comparator;
use JsonException;
use plugin\saimulti\exception\ApiException;
use RuntimeException;
use support\think\Db;
use Throwable;

final class ModuleManager
{
    private readonly ModuleLockExecutor $lifecycleLocks;

    private readonly ModuleTransactionExecutor $transactions;

    private readonly ModuleConfigProtector $configProtector;

    private readonly ModuleAuthCacheInvalidator $authCaches;

    public function __construct(
        private readonly ManifestCatalog $catalog,
        private readonly ModuleMigrationRunner $migrations,
        private readonly ModuleLifecycleHookRunner $hooks,
        private readonly ModuleMenuRegistrar $menus,
        private readonly ModuleDependencyGuard $dependencies,
        private readonly ModuleAccessService $access,
        private readonly ModuleAuditWriter $audit,
        private readonly ModuleConfigValidator $configValidator,
        DistributedLockInterface $lifecycleLock,
        ?ModuleTransactionExecutor $transactions = null,
        ?ModuleConfigProtector $configProtector = null,
        ?ModuleAuthCacheInvalidator $authCacheInvalidator = null,
    ) {
        $this->lifecycleLocks = new ModuleLockExecutor(
            $lifecycleLock,
            (int) config('plugin.saimulti.module.lifecycle_lock_ttl_seconds', 900),
        );
        $this->transactions = $transactions ?? new ModuleTransactionExecutor();
        $this->configProtector = $configProtector ?? new ModuleConfigProtector(
            $this->configValidator,
            new ModuleConfigCipher((string) config('plugin.saimulti.module.config_encryption_key', '')),
        );
        $this->authCaches = $authCacheInvalidator ?? new ModuleAuthCacheInvalidator();
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function catalog(bool $refresh = false): array
    {
        $items = [];
        foreach ($this->catalog->all($refresh) as $entry) {
            $manifest = $entry['manifest'];
            $row = $this->moduleRow($manifest->moduleKey(), false);
            $items[] = $this->catalogItem($manifest, $row);
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function detail(string $moduleKey): array
    {
        $entry = $this->catalog->get($this->assertModuleKey($moduleKey), true);

        return $this->catalogItem($entry['manifest'], $this->moduleRow($moduleKey, false));
    }

    /**
     * @param array{type?: string, id?: int|null, ip?: string|null} $actor
     * @return array{items: list<array<string, mixed>>}
     */
    public function discover(?string $moduleKey = null, array $actor = []): array
    {
        if ($moduleKey === null || $moduleKey === '') {
            $items = [];
            foreach (array_keys($this->catalog->all(true)) as $discoveredKey) {
                $items[] = $this->discover($discoveredKey, $actor)['items'][0];
            }

            return ['items' => $items];
        }
        $moduleKey = $this->assertModuleKey($moduleKey);
        if (!$this->lifecycleLocks->isHeld($moduleKey)) {
            return $this->lifecycleLocks->run($moduleKey, fn (): array => $this->discover($moduleKey, $actor));
        }

        $entries = $this->catalog->all(true);
        $entries = [$moduleKey => $this->catalog->get($moduleKey)];

        $items = [];
        foreach ($entries as $entry) {
            $manifest = $entry['manifest'];
            Db::transaction(function () use ($manifest, $entry, $actor): void {
                $row = $this->moduleRow($manifest->moduleKey(), false);
                $now = date('Y-m-d H:i:s');
                $manifestData = $this->manifestData($manifest, $entry['path']);

                if ($row === null) {
                    Db::table('sm_module')->insert($manifestData + [
                        'version' => $manifest->version(),
                        'status' => SystemModuleStatus::DISCOVERED->value,
                        'lock_version' => 1,
                        'created_by' => $actor['id'] ?? null,
                        'updated_by' => $actor['id'] ?? null,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    $this->audit->write(
                        $manifest->moduleKey(),
                        'discover',
                        null,
                        SystemModuleStatus::DISCOVERED->value,
                        null,
                        $manifest->version(),
                        true,
                        $actor,
                    );
                    return;
                }

                // 已安装模块的 manifest 快照代表已运行代码，discover 不得在 upgrade 前替换它。
                $data = ModuleManifestSnapshotPolicy::discoveryUpdate($row['status'], $manifestData);
                $data += [
                    'updated_by' => $actor['id'] ?? null,
                    'update_time' => $now,
                    'lock_version' => (int) $row['lock_version'] + 1,
                    'failure_message' => null,
                ];
                $toStatus = $row['status'];
                if (in_array($row['status'], [SystemModuleStatus::UNINSTALLED->value, SystemModuleStatus::FAILED->value], true)) {
                    $from = SystemModuleStatus::from($row['status']);
                    ModuleStateMachine::assertSystemTransition($from, SystemModuleStatus::DISCOVERED);
                    $toStatus = SystemModuleStatus::DISCOVERED->value;
                    $data['status'] = $toStatus;
                    $data['version'] = $manifest->version();
                }

                $updated = Db::table('sm_module')
                    ->where('id', $row['id'])
                    ->where('lock_version', $row['lock_version'])
                    ->update($data);
                if ($updated !== 1) {
                    throw new RuntimeException('模块发现记录已被并发修改。');
                }

                $this->audit->write(
                    $manifest->moduleKey(),
                    'discover',
                    $row['status'],
                    $toStatus,
                    $row['version'],
                    $manifest->version(),
                    true,
                    $actor,
                );
            });
            $items[] = $this->detail($manifest->moduleKey());
        }

        return ['items' => $items];
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    public function install(string $moduleKey, array $actor = []): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        if (!$this->lifecycleLocks->isHeld($moduleKey)) {
            return $this->lifecycleLocks->run($moduleKey, fn (): array => $this->install($moduleKey, $actor));
        }
        $entry = $this->catalog->get($moduleKey, true);
        $manifest = $entry['manifest'];
        $this->discover($moduleKey, $actor);
        $row = $this->moduleRow($moduleKey);
        if ($row['status'] !== SystemModuleStatus::DISCOVERED->value) {
            throw new ApiException(sprintf('模块当前状态 %s 不可安装。', $row['status']), 409);
        }

        try {
            $this->dependencies->assertInstallable($manifest);
            $migrationOutput = $this->migrations->migrate($manifest, $entry['path']);
            $hook = $this->runNonTransactionalHook(
                $manifest,
                LifecycleOperation::INSTALL,
                options: ['actor' => $actor],
            );

            $this->transactions->run(function () use ($row, $manifest, $entry, $actor, $migrationOutput, &$hook): void {
                $hook ??= $this->hooks->run(
                    $manifest,
                    LifecycleOperation::INSTALL,
                    options: ['actor' => $actor],
                );
                $this->menus->register($manifest);
                $this->transitionSystem($row, SystemModuleStatus::INSTALLED, $this->manifestData($manifest, $entry['path']) + [
                    'version' => $manifest->version(),
                    'installed_at' => date('Y-m-d H:i:s'),
                    'failure_message' => null,
                    'updated_by' => $actor['id'] ?? null,
                ]);
                $this->audit->write(
                    $manifest->moduleKey(),
                    'install',
                    $row['status'],
                    SystemModuleStatus::INSTALLED->value,
                    $row['version'],
                    $manifest->version(),
                    true,
                    $actor,
                    context: ['migration_output' => $migrationOutput, 'hook' => $hook],
                );
            }, [fn () => $this->authCaches->systemStateChanged()]);
        } catch (Throwable $exception) {
            $this->recordSystemFailure($moduleKey, 'install', $actor, $exception);
            throw new ApiException($exception->getMessage(), 400);
        }

        return $this->detail($moduleKey);
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    public function upgrade(string $moduleKey, array $actor = []): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        if (!$this->lifecycleLocks->isHeld($moduleKey)) {
            return $this->lifecycleLocks->run($moduleKey, fn (): array => $this->upgrade($moduleKey, $actor));
        }
        $entry = $this->catalog->get($moduleKey, true);
        $manifest = $entry['manifest'];
        $row = $this->moduleRow($moduleKey);
        $previousStatus = SystemModuleStatus::from($row['status']);
        if (!in_array($previousStatus, [SystemModuleStatus::INSTALLED, SystemModuleStatus::ENABLED, SystemModuleStatus::DISABLED], true)) {
            throw new ApiException(sprintf('模块当前状态 %s 不可升级。', $row['status']), 409);
        }
        if (!Comparator::greaterThan($manifest->version(), (string) $row['version'])) {
            throw new ApiException('目标版本必须高于已安装版本。', 409);
        }

        try {
            if ($previousStatus === SystemModuleStatus::ENABLED) {
                $this->dependencies->assertEnableable($manifest);
            } else {
                $this->dependencies->assertInstallable($manifest);
            }
        } catch (Throwable $exception) {
            throw new ApiException($exception->getMessage(), 400);
        }

        try {
            $this->transactions->run(function () use (&$row, $actor): void {
                $row = $this->transitionSystem($row, SystemModuleStatus::UPGRADING, [
                    'updated_by' => $actor['id'] ?? null,
                ]);
            }, [
                fn () => $this->access->invalidateModule($moduleKey),
                fn () => $this->authCaches->systemStateChanged(),
            ]);

            $migrationOutput = $this->migrations->migrate($manifest, $entry['path']);
            $hook = $this->runNonTransactionalHook(
                $manifest,
                LifecycleOperation::UPGRADE,
                fromVersion: (string) $row['version'],
                options: ['actor' => $actor],
            );

            $this->transactions->run(function () use ($row, $previousStatus, $manifest, $entry, $actor, $migrationOutput, &$hook): void {
                $hook ??= $this->hooks->run(
                    $manifest,
                    LifecycleOperation::UPGRADE,
                    fromVersion: (string) $row['version'],
                    options: ['actor' => $actor],
                );
                $this->menus->register($manifest);
                $this->transitionSystem($row, $previousStatus, $this->manifestData($manifest, $entry['path']) + [
                    'version' => $manifest->version(),
                    'failure_message' => null,
                    'updated_by' => $actor['id'] ?? null,
                ]);
                $this->touchModuleProjectionVersions($manifest->moduleKey());
                $this->audit->write(
                    $manifest->moduleKey(),
                    'upgrade',
                    SystemModuleStatus::UPGRADING->value,
                    $previousStatus->value,
                    $row['version'],
                    $manifest->version(),
                    true,
                    $actor,
                    context: ['migration_output' => $migrationOutput, 'hook' => $hook],
                );
            }, [
                fn () => $this->access->invalidateModule($manifest->moduleKey()),
                fn () => $this->authCaches->systemStateChanged(),
            ]);
        } catch (Throwable $exception) {
            $this->recordSystemFailure($moduleKey, 'upgrade', $actor, $exception, $previousStatus);
            throw new ApiException($exception->getMessage(), 400);
        }

        return $this->detail($moduleKey);
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    public function enableSystem(string $moduleKey, array $actor = []): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        return $this->lifecycleLocks->run($moduleKey, fn (): array => $this->changeSystemEnablement($moduleKey, true, $actor));
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    public function disableSystem(string $moduleKey, array $actor = []): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        return $this->lifecycleLocks->run($moduleKey, fn (): array => $this->changeSystemEnablement($moduleKey, false, $actor));
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    public function uninstall(string $moduleKey, bool $preserveData = true, array $actor = []): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        if (!$this->lifecycleLocks->isHeld($moduleKey)) {
            return $this->lifecycleLocks->run(
                $moduleKey,
                fn (): array => $this->uninstall($moduleKey, $preserveData, $actor),
            );
        }
        $row = $this->moduleRow($moduleKey);
        $manifest = $this->manifestFromRow($row);
        $installedManifestPath = (string) $row['manifest_path'];
        $from = SystemModuleStatus::from($row['status']);
        if (!in_array($from, [SystemModuleStatus::INSTALLED, SystemModuleStatus::DISABLED, SystemModuleStatus::FAILED], true)) {
            throw new ApiException(sprintf('模块当前状态 %s 不可卸载，已启用模块需先禁用。', $row['status']), 409);
        }
        ModuleStateMachine::assertSystemTransition($from, SystemModuleStatus::UNINSTALLED);

        try {
            $this->dependencies->assertNoInstalledDependents($moduleKey);
            $licenses = Db::table('sm_tenant_module_license')
                ->where('module_key', $moduleKey)
                ->whereNull('delete_time')
                ->select()
                ->toArray();
            $tenantHooks = [];
            foreach ($licenses as $license) {
                if ($license['status'] === TenantModuleStatus::ENABLED->value) {
                    $externalHook = $this->runNonTransactionalHook(
                        $manifest,
                        LifecycleOperation::DISABLE,
                        (int) $license['organization'],
                        options: ['actor' => $actor, 'reason' => 'system_uninstall'],
                    );
                    if ($externalHook !== null) {
                        $tenantHooks[(int) $license['organization']] = $externalHook;
                    }
                }
            }
            $hook = $this->runNonTransactionalHook(
                $manifest,
                LifecycleOperation::UNINSTALL,
                preserveData: $preserveData,
                options: ['actor' => $actor],
            );
            $migrationOutput = $preserveData ? '' : $this->migrations->rollback($manifest, $installedManifestPath);

            $afterCommit = [];
            foreach ($licenses as $license) {
                $organization = (int) $license['organization'];
                $afterCommit[] = fn () => $this->access->invalidate($organization, $manifest->moduleKey());
            }
            $afterCommit[] = fn () => $this->authCaches->systemStateChanged();

            $this->transactions->run(function () use ($row, $manifest, $licenses, $actor, $preserveData, &$hook, &$tenantHooks, $migrationOutput): void {
                foreach ($licenses as $license) {
                    $organization = (int) $license['organization'];
                    if ($license['status'] === TenantModuleStatus::ENABLED->value
                        && !array_key_exists($organization, $tenantHooks)) {
                        $tenantHooks[$organization] = $this->hooks->run(
                            $manifest,
                            LifecycleOperation::DISABLE,
                            $organization,
                            options: ['actor' => $actor, 'reason' => 'system_uninstall'],
                        );
                    }
                }
                $hook ??= $this->hooks->run(
                    $manifest,
                    LifecycleOperation::UNINSTALL,
                    preserveData: $preserveData,
                    options: ['actor' => $actor],
                );
                $this->menus->unregister($manifest->moduleKey());
                Db::table('sm_tenant_module_config')->where('module_key', $manifest->moduleKey())->delete();
                Db::table('sm_tenant_module_license')->where('module_key', $manifest->moduleKey())->delete();
                foreach ($licenses as $license) {
                    $this->touchProjectionVersion((int) $license['organization']);
                }
                $this->transitionSystem($row, SystemModuleStatus::UNINSTALLED, [
                    'uninstalled_at' => date('Y-m-d H:i:s'),
                    'failure_message' => null,
                    'updated_by' => $actor['id'] ?? null,
                ]);
                $this->audit->write(
                    $manifest->moduleKey(),
                    'uninstall',
                    $row['status'],
                    SystemModuleStatus::UNINSTALLED->value,
                    $row['version'],
                    $row['version'],
                    true,
                    $actor,
                    context: [
                        'preserve_data' => $preserveData,
                        'migration_output' => $migrationOutput,
                        'hook' => $hook,
                        'tenant_hooks' => $tenantHooks,
                    ],
                );
            }, $afterCommit);
        } catch (Throwable $exception) {
            $this->recordSystemFailure($moduleKey, 'uninstall', $actor, $exception, $from);
            throw new ApiException($exception->getMessage(), 400);
        }

        return $this->detail($moduleKey);
    }

    /**
     * @param array{type?: string, id?: int|null, ip?: string|null} $actor
     * @return array<string, mixed>
     */
    public function grantLicense(
        int $organization,
        string $moduleKey,
        ?string $expireAt,
        ?string $remark,
        array $actor = [],
    ): array {
        $moduleKey = $this->assertModuleKey($moduleKey);
        if (!$this->lifecycleLocks->isHeld($moduleKey)) {
            return $this->lifecycleLocks->run(
                $moduleKey,
                fn (): array => $this->grantLicense($organization, $moduleKey, $expireAt, $remark, $actor),
            );
        }
        $this->assertOrganization($organization);
        $module = $this->moduleRow($moduleKey);
        if (!in_array($module['status'], [
            SystemModuleStatus::INSTALLED->value,
            SystemModuleStatus::ENABLED->value,
            SystemModuleStatus::DISABLED->value,
        ], true)) {
            throw new ApiException('只能为已安装的系统模块授权。', 409);
        }
        $expireAt = ModuleLicenseInputNormalizer::futureExpiry($expireAt);
        $remark = ModuleLicenseInputNormalizer::remark($remark);

        $this->transactions->run(function () use ($organization, $moduleKey, $expireAt, $remark, $actor, $module): void {
            $license = $this->licenseRow($organization, $moduleKey, false);
            $now = date('Y-m-d H:i:s');
            if ($license === null) {
                Db::table('sm_tenant_module_license')->insert([
                    'organization' => $organization,
                    'module_key' => $moduleKey,
                    'status' => TenantModuleStatus::AUTHORIZED->value,
                    'expire_at' => $expireAt,
                    'version' => 1,
                    'granted_by' => $actor['id'] ?? null,
                    'authorized_at' => $now,
                    'remark' => $remark,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                $fromStatus = TenantModuleStatus::UNAUTHORIZED->value;
                $fromVersion = 0;
            } else {
                $fromStatus = $license['status'];
                $fromVersion = (int) $license['version'];
                $target = TenantModuleStatus::AUTHORIZED;
                if (in_array($fromStatus, [TenantModuleStatus::UNAUTHORIZED->value, TenantModuleStatus::EXPIRED->value], true)) {
                    ModuleStateMachine::assertTenantTransition(TenantModuleStatus::from($fromStatus), $target);
                    $newStatus = $target->value;
                } else {
                    $newStatus = $fromStatus;
                }
                $updated = Db::table('sm_tenant_module_license')
                    ->where('id', $license['id'])
                    ->where('version', $license['version'])
                    ->update([
                        'status' => $newStatus,
                        'expire_at' => $expireAt,
                        'version' => $fromVersion + 1,
                        'granted_by' => $actor['id'] ?? null,
                        'revoked_by' => null,
                        'authorized_at' => $now,
                        'revoked_at' => null,
                        'remark' => $remark,
                        'update_time' => $now,
                        'delete_time' => null,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('租户模块授权已被并发修改。');
                }
            }

            $this->touchProjectionVersion($organization);
            $current = $this->licenseRow($organization, $moduleKey);
            $this->audit->write(
                $moduleKey,
                'tenant_grant',
                $fromStatus,
                $current['status'],
                $module['version'],
                $module['version'],
                true,
                $actor,
                $organization,
                context: ['expire_at' => $expireAt, 'license_version_from' => $fromVersion, 'license_version_to' => $current['version']],
            );
        }, [
            fn () => $this->access->invalidate($organization, $moduleKey),
            fn () => $this->authCaches->tenantStateChanged($organization),
        ]);

        return $this->licenseView($this->licenseRow($organization, $moduleKey));
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    public function revokeLicense(int $organization, string $moduleKey, array $actor = []): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        if (!$this->lifecycleLocks->isHeld($moduleKey)) {
            return $this->lifecycleLocks->run(
                $moduleKey,
                fn (): array => $this->revokeLicense($organization, $moduleKey, $actor),
            );
        }
        $license = $this->licenseRow($organization, $moduleKey);
        if ($license['status'] === TenantModuleStatus::UNAUTHORIZED->value) {
            return $this->licenseView($license);
        }
        $manifest = $this->manifestFromRow($this->moduleRow($moduleKey));
        $this->assertTenantDependencyTransition($organization, $moduleKey, $manifest, false);
        $hook = null;
        if ($license['status'] === TenantModuleStatus::ENABLED->value) {
            $hook = $this->runNonTransactionalHook(
                $manifest,
                LifecycleOperation::DISABLE,
                $organization,
                options: ['actor' => $actor, 'reason' => 'revoke'],
            );
        }

        $this->transactions->run(function () use ($organization, $moduleKey, $license, $actor, $manifest, &$hook): void {
            $this->assertTenantDependencyTransition($organization, $moduleKey, $manifest, false);
            if ($license['status'] === TenantModuleStatus::ENABLED->value && $hook === null) {
                $hook = $this->hooks->run(
                    $manifest,
                    LifecycleOperation::DISABLE,
                    $organization,
                    options: ['actor' => $actor, 'reason' => 'revoke'],
                );
            }
            ModuleStateMachine::assertTenantTransition(
                TenantModuleStatus::from($license['status']),
                TenantModuleStatus::UNAUTHORIZED,
            );
            $updated = Db::table('sm_tenant_module_license')
                ->where('id', $license['id'])
                ->where('version', $license['version'])
                ->update([
                    'status' => TenantModuleStatus::UNAUTHORIZED->value,
                    'version' => (int) $license['version'] + 1,
                    'revoked_by' => $actor['id'] ?? null,
                    'revoked_at' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('租户模块授权已被并发修改。');
            }
            $this->touchProjectionVersion($organization);
            $this->audit->write(
                $moduleKey,
                'tenant_revoke',
                $license['status'],
                TenantModuleStatus::UNAUTHORIZED->value,
                null,
                null,
                true,
                $actor,
                $organization,
                context: [
                    'license_version_from' => $license['version'],
                    'license_version_to' => (int) $license['version'] + 1,
                    'hook' => $hook,
                ],
            );
        }, [
            fn () => $this->access->invalidate($organization, $moduleKey),
            fn () => $this->authCaches->tenantStateChanged($organization),
        ]);

        return $this->licenseView($this->licenseRow($organization, $moduleKey));
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    public function enableTenant(int $organization, string $moduleKey, array $actor = []): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        return $this->lifecycleLocks->run(
            $moduleKey,
            fn (): array => $this->changeTenantEnablement($organization, $moduleKey, true, $actor),
        );
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    public function disableTenant(int $organization, string $moduleKey, array $actor = []): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        return $this->lifecycleLocks->run(
            $moduleKey,
            fn (): array => $this->changeTenantEnablement($organization, $moduleKey, false, $actor),
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function availableForTenant(int $organization): array
    {
        $modules = Db::table('sm_module')
            ->where('status', SystemModuleStatus::ENABLED->value)
            ->whereNull('delete_time')
            ->order('module_key', 'asc')
            ->select()
            ->toArray();
        $items = [];
        foreach ($modules as $module) {
            $platforms = $this->decode((string) $module['platforms_json']);
            if (!in_array('tenant', $platforms, true)) {
                continue;
            }
            $manifest = $this->manifestFromRow($module);
            $license = $this->licenseRow($organization, $module['module_key'], false);
            if ($license === null || $license['status'] === TenantModuleStatus::UNAUTHORIZED->value) {
                continue;
            }
            $status = $license['status'];
            $expireAt = $license['expire_at'];
            $items[] = [
                'module_key' => $module['module_key'],
                'name' => $module['name'],
                'description' => $module['description'],
                'version' => $module['version'],
                'platforms' => $platforms,
                'status' => $status,
                'expire_at' => $expireAt,
                'effective' => $status === TenantModuleStatus::ENABLED->value
                    && ($expireAt === null || strtotime((string) $expireAt) > time()),
                'config_schema' => $this->configProtector->publicSchema($manifest),
            ];
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function readTenantConfig(int $organization, string $moduleKey): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        $this->access->assertTenantLicensed($organization, $moduleKey);
        $manifest = $this->manifestFromRow($this->moduleRow($moduleKey));
        $row = Db::table('sm_tenant_module_config')
            ->where('organization', $organization)
            ->where('module_key', $moduleKey)
            ->whereNull('delete_time')
            ->find();
        $stored = $row ? $this->decode((string) $row['config_json']) : [];
        $projection = $this->configProtector->publicProjection($manifest, $stored);

        return [
            'module_key' => $moduleKey,
            'schema' => $this->configProtector->publicSchema($manifest),
            'values' => $projection['values'],
            'configured' => $projection['configured'],
            'version' => (int) ($row['version'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array{type?: string, id?: int|null, ip?: string|null} $actor
     * @return array<string, mixed>
     */
    public function updateTenantConfig(int $organization, string $moduleKey, array $input, array $actor = []): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        if (!$this->lifecycleLocks->isHeld($moduleKey)) {
            return $this->lifecycleLocks->run(
                $moduleKey,
                fn (): array => $this->updateTenantConfig($organization, $moduleKey, $input, $actor),
            );
        }
        $this->access->assertTenantLicensed($organization, $moduleKey);
        $manifest = $this->manifestFromRow($this->moduleRow($moduleKey));
        $row = Db::table('sm_tenant_module_config')
            ->where('organization', $organization)
            ->where('module_key', $moduleKey)
            ->whereNull('delete_time')
            ->find();
        $currentVersion = (int) ($row['version'] ?? 0);
        $stored = $row ? $this->decode((string) $row['config_json']) : [];
        $values = $this->configProtector->prepareForPersistence(
            $manifest,
            $input,
            $stored,
            $organization,
            $moduleKey,
        );
        $changedKeys = array_keys($input);

        $this->transactions->run(function () use ($organization, $moduleKey, $values, $currentVersion, $actor, $changedKeys): void {
            $now = date('Y-m-d H:i:s');
            $encoded = $this->encode($values);
            if ($currentVersion === 0) {
                Db::table('sm_tenant_module_config')->insert([
                    'organization' => $organization,
                    'module_key' => $moduleKey,
                    'config_json' => $encoded,
                    'version' => 1,
                    'created_by' => $actor['id'] ?? null,
                    'updated_by' => $actor['id'] ?? null,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                $newVersion = 1;
            } else {
                $newVersion = $currentVersion + 1;
                $updated = Db::table('sm_tenant_module_config')
                    ->where('organization', $organization)
                    ->where('module_key', $moduleKey)
                    ->where('version', $currentVersion)
                    ->update([
                        'config_json' => $encoded,
                        'version' => $newVersion,
                        'updated_by' => $actor['id'] ?? null,
                        'update_time' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('模块配置已被并发修改。');
                }
            }

            $this->touchProjectionVersion($organization);
            $this->audit->write(
                $moduleKey,
                'tenant_config_update',
                null,
                null,
                null,
                null,
                true,
                $actor,
                $organization,
                context: ['changed_keys' => $changedKeys, 'config_version' => $newVersion],
            );
        }, [fn () => $this->access->invalidate($organization, $moduleKey)]);

        return $this->readTenantConfig($organization, $moduleKey);
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    private function changeSystemEnablement(string $moduleKey, bool $enable, array $actor): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        $row = $this->moduleRow($moduleKey);
        $manifest = $this->manifestFromRow($row);
        $target = $enable ? SystemModuleStatus::ENABLED : SystemModuleStatus::DISABLED;
        if ($row['status'] === $target->value) {
            return $this->detail($moduleKey);
        }
        $allowed = $enable
            ? [SystemModuleStatus::INSTALLED->value, SystemModuleStatus::DISABLED->value]
            : [SystemModuleStatus::ENABLED->value];
        if (!in_array($row['status'], $allowed, true)) {
            throw new ApiException(sprintf('模块当前状态 %s 不可%s。', $row['status'], $enable ? '启用' : '禁用'), 409);
        }

        try {
            if ($enable) {
                $this->dependencies->assertEnableable($manifest);
            } else {
                $this->dependencies->assertNoEnabledDependents($moduleKey);
            }
            ModuleStateMachine::assertSystemTransition(SystemModuleStatus::from($row['status']), $target);
            $operation = $enable ? LifecycleOperation::ENABLE : LifecycleOperation::DISABLE;
            $hook = $this->runNonTransactionalHook(
                $manifest,
                $operation,
                options: ['actor' => $actor],
            );

            $this->transactions->run(function () use ($row, $target, $enable, $actor, $manifest, $operation, &$hook): void {
                $hook ??= $this->hooks->run(
                    $manifest,
                    $operation,
                    options: ['actor' => $actor],
                );
                $this->transitionSystem($row, $target, [
                    $enable ? 'enabled_at' : 'disabled_at' => date('Y-m-d H:i:s'),
                    'failure_message' => null,
                    'updated_by' => $actor['id'] ?? null,
                ]);
                $this->touchModuleProjectionVersions($row['module_key']);
                $this->audit->write(
                    $row['module_key'],
                    $enable ? 'system_enable' : 'system_disable',
                    $row['status'],
                    $target->value,
                    $row['version'],
                    $row['version'],
                    true,
                    $actor,
                    context: ['hook' => $hook],
                );
            }, [
                fn () => $this->access->invalidateModule($row['module_key']),
                fn () => $this->authCaches->systemStateChanged(),
            ]);
        } catch (Throwable $exception) {
            $this->recordSystemFailure(
                $moduleKey,
                $enable ? 'system_enable' : 'system_disable',
                $actor,
                $exception,
                SystemModuleStatus::from($row['status']),
            );
            throw new ApiException($exception->getMessage(), 400);
        }

        return $this->detail($moduleKey);
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor @return array<string, mixed> */
    private function changeTenantEnablement(int $organization, string $moduleKey, bool $enable, array $actor): array
    {
        $moduleKey = $this->assertModuleKey($moduleKey);
        $module = $this->moduleRow($moduleKey);
        if ($module['status'] !== SystemModuleStatus::ENABLED->value) {
            throw new ApiException('系统模块未启用。', 403);
        }
        $license = $this->licenseRow($organization, $moduleKey);
        if ($this->licenseExpired($license)) {
            throw new ApiException('租户模块授权已过期。', 403);
        }
        $target = $enable ? TenantModuleStatus::ENABLED : TenantModuleStatus::DISABLED;
        if ($license['status'] === $target->value) {
            return $this->licenseView($license);
        }
        $allowed = $enable
            ? [TenantModuleStatus::AUTHORIZED->value, TenantModuleStatus::DISABLED->value]
            : [TenantModuleStatus::ENABLED->value];
        if (!in_array($license['status'], $allowed, true)) {
            throw new ApiException(sprintf('租户授权状态 %s 不可%s。', $license['status'], $enable ? '启用' : '禁用'), 409);
        }
        ModuleStateMachine::assertTenantTransition(TenantModuleStatus::from($license['status']), $target);

        $manifest = $this->manifestFromRow($module);
        $this->assertTenantDependencyTransition($organization, $moduleKey, $manifest, $enable);
        $operation = $enable ? LifecycleOperation::ENABLE : LifecycleOperation::DISABLE;
        $hook = $this->runNonTransactionalHook(
            $manifest,
            $operation,
            $organization,
            options: ['actor' => $actor],
        );

        $this->transactions->run(function () use ($organization, $moduleKey, $license, $target, $enable, $actor, &$hook, $module, $manifest, $operation): void {
            $this->assertTenantDependencyTransition($organization, $moduleKey, $manifest, $enable);
            $hook ??= $this->hooks->run(
                $manifest,
                $operation,
                $organization,
                options: ['actor' => $actor],
            );
            $updated = Db::table('sm_tenant_module_license')
                ->where('id', $license['id'])
                ->where('version', $license['version'])
                ->update([
                    'status' => $target->value,
                    'version' => (int) $license['version'] + 1,
                    $enable ? 'enabled_at' : 'disabled_at' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('租户模块状态已被并发修改。');
            }
            $this->touchProjectionVersion($organization);
            $this->audit->write(
                $moduleKey,
                $enable ? 'tenant_enable' : 'tenant_disable',
                $license['status'],
                $target->value,
                $module['version'],
                $module['version'],
                true,
                $actor,
                $organization,
                context: ['hook' => $hook, 'license_version_to' => (int) $license['version'] + 1],
            );
        }, [
            fn () => $this->access->invalidate($organization, $moduleKey),
            fn () => $this->authCaches->tenantStateChanged($organization),
        ]);

        return $this->licenseView($this->licenseRow($organization, $moduleKey));
    }

    private function assertTenantDependencyTransition(
        int $organization,
        string $moduleKey,
        Manifest $manifest,
        bool $enable,
    ): void {
        try {
            if ($enable) {
                $this->dependencies->assertTenantEnableable($organization, $manifest);
            } else {
                $this->dependencies->assertNoTenantEnabledDependents($organization, $moduleKey);
            }
        } catch (Throwable $exception) {
            throw new ApiException($exception->getMessage(), 409);
        }
    }

    /**
     * Non-transactional hooks may perform file, process, MQ, or remote side
     * effects. They intentionally run outside the database transaction and
     * therefore must be idempotent and retry-safe. Transactional hooks return
     * null here and are invoked inside the caller's state/menu/audit transaction.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function runNonTransactionalHook(
        Manifest $manifest,
        LifecycleOperation $operation,
        ?int $organization = null,
        ?string $fromVersion = null,
        bool $preserveData = true,
        array $options = [],
    ): ?array {
        if ($this->hooks->isTransactional($manifest, $operation)) {
            return null;
        }

        return $this->hooks->run(
            $manifest,
            $operation,
            $organization,
            $fromVersion,
            $preserveData,
            $options,
        );
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $extra @return array<string, mixed> */
    private function transitionSystem(array $row, SystemModuleStatus $target, array $extra = []): array
    {
        ModuleStateMachine::assertSystemTransition(SystemModuleStatus::from($row['status']), $target);
        $nextVersion = (int) $row['lock_version'] + 1;
        $updated = Db::table('sm_module')
            ->where('id', $row['id'])
            ->where('lock_version', $row['lock_version'])
            ->update($extra + [
                'status' => $target->value,
                'lock_version' => $nextVersion,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('模块状态已被并发修改。');
        }

        return $this->moduleRow($row['module_key']);
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor */
    private function recordSystemFailure(
        string $moduleKey,
        string $operation,
        array $actor,
        Throwable $exception,
        ?SystemModuleStatus $stableBeforeOperation = null,
    ): void
    {
        try {
            $this->transactions->run(function () use ($moduleKey, $operation, $actor, $exception, $stableBeforeOperation): void {
                $row = $this->moduleRow($moduleKey);
                $from = SystemModuleStatus::from($row['status']);
                $target = ModuleFailureRecoveryPolicy::target($operation, $from, $stableBeforeOperation);
                if ($target !== $from) {
                    ModuleStateMachine::assertSystemTransition($from, $target);
                    $this->transitionSystem($row, $target, [
                        'failure_message' => mb_substr($exception->getMessage(), 0, 4000),
                        'updated_by' => $actor['id'] ?? null,
                    ]);
                } else {
                    $updated = Db::table('sm_module')
                        ->where('id', $row['id'])
                        ->where('lock_version', $row['lock_version'])
                        ->update([
                            'failure_message' => mb_substr($exception->getMessage(), 0, 4000),
                            'lock_version' => (int) $row['lock_version'] + 1,
                            'updated_by' => $actor['id'] ?? null,
                            'update_time' => date('Y-m-d H:i:s'),
                        ]);
                    if ($updated !== 1) {
                        throw new RuntimeException('模块失败状态已被并发修改。');
                    }
                }
                if ($target === SystemModuleStatus::FAILED) {
                    $this->touchModuleProjectionVersions($moduleKey);
                }
                $this->audit->write(
                    $moduleKey,
                    $operation,
                    $row['status'],
                    $target->value,
                    $row['version'],
                    $row['available_version'],
                    false,
                    $actor,
                    errorMessage: mb_substr($exception->getMessage(), 0, 4000),
                );
            }, [
                fn () => $this->access->invalidateModule($moduleKey),
                fn () => $this->authCaches->systemStateChanged(),
            ]);
        } catch (Throwable) {
            // 保留原始生命周期异常，避免审计写入错误覆盖根因。
        }
    }

    /** @return array<string, mixed>|null */
    private function moduleRow(string $moduleKey, bool $required = true): ?array
    {
        $row = Db::table('sm_module')
            ->where('module_key', $moduleKey)
            ->whereNull('delete_time')
            ->find();
        if (!$row && $required) {
            throw new ApiException(sprintf('模块尚未发现: %s', $moduleKey), 404);
        }

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private function licenseRow(int $organization, string $moduleKey, bool $required = true): ?array
    {
        $row = Db::table('sm_tenant_module_license')
            ->where('organization', $organization)
            ->where('module_key', $moduleKey)
            ->whereNull('delete_time')
            ->find();
        if (!$row && $required) {
            throw new ApiException('当前租户未获得该模块授权。', 403);
        }

        return $row ?: null;
    }

    /** @param array<string, mixed> $module @return array<string, mixed> */
    private function catalogItem(Manifest $manifest, ?array $module): array
    {
        $system = $module === null ? null : [
            'status' => $module['status'],
            'version' => $module['version'],
            'available_version' => $module['available_version'],
            'failure_message' => $module['failure_message'],
            'installed_at' => $module['installed_at'],
            'enabled_at' => $module['enabled_at'],
            'disabled_at' => $module['disabled_at'],
            'uninstalled_at' => $module['uninstalled_at'],
            'upgrade_available' => Comparator::greaterThan($manifest->version(), (string) $module['version']),
        ];

        return $this->configProtector->sanitizedManifestData($manifest) + ['system' => $system];
    }

    /** @return array<string, mixed> */
    private function manifestData(Manifest $manifest, string $manifestPath): array
    {
        return [
            'module_key' => $manifest->moduleKey(),
            'name' => $manifest->name(),
            'description' => $manifest->description(),
            'category' => $manifest->category(),
            'module_type' => $manifest->moduleType(),
            'is_builtin' => $manifest->isBuiltin() ? 1 : 0,
            'license_required' => $manifest->licenseRequired() ? 1 : 0,
            'available_version' => $manifest->version(),
            'min_system_version' => $manifest->minSystemVersion(),
            'platforms_json' => $this->encode($manifest->platforms()),
            'depends_on_json' => $this->encode($manifest->dependsOn()),
            'conflicts_with_json' => $this->encode($manifest->conflictsWith()),
            'capabilities_json' => $this->encode($manifest->capabilities()),
            'manifest_json' => $this->encode($this->configProtector->sanitizedManifestData($manifest)),
            'manifest_path' => $manifestPath,
        ];
    }

    /** @param array<string, mixed> $module */
    private function manifestFromRow(array $module): Manifest
    {
        $data = $this->decode((string) $module['manifest_json']);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('模块 %s manifest 快照无效。', $module['module_key']));
        }

        return new Manifest($data);
    }

    /** @param array<string, mixed> $license @return array<string, mixed> */
    private function licenseView(array $license): array
    {
        return [
            'organization' => (int) $license['organization'],
            'module_key' => $license['module_key'],
            'status' => $license['status'],
            'expire_at' => $license['expire_at'],
            'version' => (int) $license['version'],
            'effective' => $license['status'] === TenantModuleStatus::ENABLED->value && !$this->licenseExpired($license),
            'authorized_at' => $license['authorized_at'],
            'enabled_at' => $license['enabled_at'],
            'disabled_at' => $license['disabled_at'],
            'revoked_at' => $license['revoked_at'],
            'remark' => $license['remark'],
        ];
    }

    /** @param array<string, mixed> $license */
    private function licenseExpired(array $license): bool
    {
        return $license['expire_at'] !== null && strtotime((string) $license['expire_at']) <= time();
    }

    private function assertOrganization(int $organization): void
    {
        if ($organization <= 0) {
            throw new ApiException('organization 必须为正整数。', 422);
        }
        $row = Db::table('sm_system_organization')
            ->where('id', $organization)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->find();
        if (!$row) {
            throw new ApiException('目标租户不存在或已停用。', 404);
        }
    }

    private function assertModuleKey(string $moduleKey): string
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $moduleKey)) {
            throw new ApiException('module_key 必须为 snake_case。', 422);
        }

        return $moduleKey;
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('JSON 序列化失败。', previous: $exception);
        }
    }

    private function decode(string $json): array
    {
        $value = json_decode($json, true);

        return is_array($value) ? $value : [];
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

    private function touchModuleProjectionVersions(string $moduleKey): void
    {
        $organizations = Db::table('sm_tenant_module_license')
            ->where('module_key', $moduleKey)
            ->whereNull('delete_time')
            ->distinct(true)
            ->column('organization');
        foreach ($organizations as $organization) {
            $this->touchProjectionVersion((int) $organization);
        }
    }
}
