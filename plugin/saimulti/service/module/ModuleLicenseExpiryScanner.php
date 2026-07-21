<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Manifest\Manifest;
use B8im\ModuleSdk\State\ModuleStateMachine;
use B8im\ModuleSdk\State\TenantModuleStatus;
use JsonException;
use RuntimeException;
use support\think\Db;
use Throwable;

final class ModuleLicenseExpiryScanner
{
    private const LOCK_KEY = 'module_license:expiry_scan:lock';

    private const TASK_TABLE = 'sm_module_expiry_hook_task';

    private const TASK_PENDING = 'pending';

    private const TASK_PROCESSING = 'processing';

    private const TASK_RETRY = 'retry';

    private const TASK_SUCCEEDED = 'succeeded';

    private const TASK_SUPERSEDED = 'superseded';

    private const TASK_CONTRACT_FAILED = 'contract_failed';

    public const HOOK_CREDENTIAL_OPTION = 'module_expiry_credential';

    public const HOOK_IDEMPOTENCY_OPTION = 'module_expiry_idempotency_key';

    public const HOOK_REQUEST_DIGEST_OPTION = 'module_expiry_request_digest';

    public const HOOK_TASK_ID_OPTION = 'module_expiry_task_id';

    private readonly ModuleLockExecutor $lifecycleLocks;

    private readonly ModuleTransactionExecutor $transactions;

    private readonly ModuleAuthCacheInvalidator $authCaches;

    private readonly int $taskLeaseSeconds;

    private readonly int $retryBaseDelaySeconds;

    private readonly int $retryMaxDelaySeconds;

    public function __construct(
        private readonly DistributedLockInterface $lock,
        private readonly ModuleAccessService $access,
        private readonly ModuleAuditWriter $audit,
        private readonly ModuleLifecycleHookRunner $hooks,
        ?ModuleTransactionExecutor $transactions = null,
        ?ModuleAuthCacheInvalidator $authCacheInvalidator = null,
    ) {
        $lifecycleTtl = (int) config('plugin.saimulti.module.lifecycle_lock_ttl_seconds', 900);
        $this->lifecycleLocks = new ModuleLockExecutor($lock, $lifecycleTtl);
        $this->transactions = $transactions ?? new ModuleTransactionExecutor();
        $this->authCaches = $authCacheInvalidator ?? new ModuleAuthCacheInvalidator();
        $this->taskLeaseSeconds = max(
            $lifecycleTtl + 60,
            (int) config('plugin.saimulti.module.expiry_task_lease_seconds', 1800),
        );
        $this->retryBaseDelaySeconds = max(
            1,
            (int) config('plugin.saimulti.module.expiry_task_retry_base_delay_seconds', 5),
        );
        $this->retryMaxDelaySeconds = max(
            $this->retryBaseDelaySeconds,
            (int) config('plugin.saimulti.module.expiry_task_retry_max_delay_seconds', 300),
        );
    }

    /**
     * @return array{
     *   acquired:bool,scanned:int,expired:int,skipped:int,failed:int,
     *   tasks_succeeded:int,tasks_superseded:int,tasks_contract_failed:int,tasks_retried:int
     * }
     */
    public function run(?int $batchSize = null): array
    {
        $batchSize ??= (int) config('plugin.saimulti.module.expiry_scan_batch_size', 200);
        $batchSize = max(1, min(1000, $batchSize));
        $ttl = (int) config('plugin.saimulti.module.expiry_lock_ttl_seconds', 55);
        $token = bin2hex(random_bytes(20));
        if (!$this->lock->acquire(self::LOCK_KEY, $token, $ttl)) {
            return $this->emptyResult(false);
        }

        $result = $this->emptyResult(true);
        $errors = [];
        try {
            foreach ($this->dueLicenseCandidates($batchSize) as $candidate) {
                ++$result['scanned'];
                try {
                    $reserved = $this->lifecycleLocks->run(
                        (string) $candidate['module_key'],
                        fn (): bool => $this->reserveExpiry($candidate),
                    );
                    if ($reserved) {
                        ++$result['expired'];
                    } else {
                        ++$result['skipped'];
                    }
                } catch (ModuleLockUnavailable) {
                    ++$result['skipped'];
                } catch (Throwable $exception) {
                    ++$result['failed'];
                    $errors[] = $exception->getMessage();
                }
            }

            for ($processed = 0; $processed < $batchSize; ++$processed) {
                $task = $this->claimTask();
                if ($task === null) {
                    break;
                }
                try {
                    $outcome = $this->lifecycleLocks->run(
                        (string) $task['module_key'],
                        fn (): string => $this->executeClaimedTask($task),
                    );
                    if ($outcome === self::TASK_SUCCEEDED) {
                        ++$result['tasks_succeeded'];
                    } elseif ($outcome === self::TASK_SUPERSEDED) {
                        ++$result['tasks_superseded'];
                    } else {
                        ++$result['tasks_contract_failed'];
                    }
                } catch (ModuleLockUnavailable $exception) {
                    try {
                        $this->renewClaimedTask($task);
                        $this->retryClaimedTask($task, $exception);
                        ++$result['skipped'];
                        ++$result['tasks_retried'];
                    } catch (Throwable $fencingException) {
                        ++$result['failed'];
                        $errors[] = $fencingException->getMessage();
                    }
                } catch (Throwable $exception) {
                    $committedOutcome = $this->committedTerminalOutcome($task);
                    if ($committedOutcome === self::TASK_SUCCEEDED) {
                        ++$result['tasks_succeeded'];
                        continue;
                    }
                    if ($committedOutcome === self::TASK_SUPERSEDED) {
                        ++$result['tasks_superseded'];
                        continue;
                    }
                    if ($committedOutcome === self::TASK_CONTRACT_FAILED) {
                        ++$result['tasks_contract_failed'];
                        continue;
                    }
                    try {
                        $this->renewClaimedTask($task);
                        $this->retryClaimedTask($task, $exception);
                        ++$result['tasks_retried'];
                    } catch (Throwable) {
                        // A lost lease/token must never mutate the newer claim.
                    }
                    ++$result['failed'];
                    $errors[] = $exception->getMessage();
                }
            }
        } finally {
            $this->lock->release(self::LOCK_KEY, $token);
        }

        if ($result['failed'] > 0) {
            throw new RuntimeException(sprintf(
                '模块授权到期扫描有 %d 条处理失败：%s',
                $result['failed'],
                mb_substr((string) ($errors[0] ?? 'unknown'), 0, 500),
            ));
        }

        return $result;
    }

    /** @param array<string,mixed> $task */
    private function committedTerminalOutcome(array $task): ?string
    {
        try {
            $rows = Db::query(
                'SELECT status,receipt_json,receipt_recorded_at,outcome_audit_id'
                . ' FROM ' . self::TASK_TABLE . ' WHERE id=?',
                [$task['id']],
            );
        } catch (Throwable) {
            return null;
        }
        if (count($rows) !== 1 || ($rows[0]['outcome_audit_id'] ?? null) === null) {
            return null;
        }
        $status = (string) ($rows[0]['status'] ?? '');
        if ($status === self::TASK_SUCCEEDED
            && ($rows[0]['receipt_json'] ?? null) !== null
            && ($rows[0]['receipt_recorded_at'] ?? null) !== null) {
            return self::TASK_SUCCEEDED;
        }
        if ($status === self::TASK_SUPERSEDED
            && ($rows[0]['receipt_json'] ?? null) === null
            && ($rows[0]['receipt_recorded_at'] ?? null) === null) {
            return self::TASK_SUPERSEDED;
        }
        if ($status === self::TASK_CONTRACT_FAILED
            && ($rows[0]['receipt_json'] ?? null) === null
            && ($rows[0]['receipt_recorded_at'] ?? null) === null) {
            return self::TASK_CONTRACT_FAILED;
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function dueLicenseCandidates(int $batchSize): array
    {
        return Db::table('sm_tenant_module_license')
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
    }

    /** @param array<string,mixed> $candidate */
    private function reserveExpiry(array $candidate): bool
    {
        $reserved = null;
        $this->transactions->run(function () use ($candidate, &$reserved): void {
            $row = $this->lockDueCandidate($candidate);
            if ($row === null) {
                return;
            }
            $fromStatus = TenantModuleStatus::from((string) $row['status']);
            ModuleStateMachine::assertTenantTransition($fromStatus, TenantModuleStatus::EXPIRED);
            $expiredVersion = (int) $row['version'] + 1;
            $affected = Db::table('sm_tenant_module_license')
                ->where('id', $row['id'])
                ->where('status', $row['status'])
                ->where('expire_at', $row['expire_at'])
                ->where('version', $row['version'])
                ->update([
                    'status' => TenantModuleStatus::EXPIRED->value,
                    'version' => $expiredVersion,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ($affected !== 1) {
                throw new RuntimeException('授权到期记录已被并发修改。');
            }
            $this->touchProjectionVersion((int) $row['organization']);

            $taskId = null;
            if ($fromStatus === TenantModuleStatus::ENABLED) {
                $manifest = $this->expiryManifest($row);
                $credential = [
                    'license_id' => (string) $row['id'],
                    'organization' => (int) $row['organization'],
                    'module_key' => (string) $row['module_key'],
                    'expired_version' => $expiredVersion,
                ];
                $hookKind = $this->hooks->expiryHookKind($manifest, LifecycleOperation::DISABLE);
                $frozen = ModuleExpiryHookContract::freeze(
                    $manifest,
                    LifecycleOperation::DISABLE,
                    $credential,
                    $hookKind,
                );
                $taskId = (string) Db::table(self::TASK_TABLE)->insertGetId([
                    'license_id' => $row['id'],
                    'organization' => $row['organization'],
                    'module_key' => $row['module_key'],
                    'expired_version' => $expiredVersion,
                    'from_status' => $fromStatus->value,
                    'expire_at' => $row['expire_at'],
                    'hook_kind' => $frozen['hook_kind'],
                    'hook_module_version' => $frozen['module_version'],
                    'hook_handler' => $frozen['handler'],
                    'hook_scope' => $frozen['scope'],
                    'hook_transactional' => $frozen['transactional'] ? 1 : 0,
                    'hook_contract_json' => $frozen['json'],
                    'idempotency_key' => ModuleExpiryHookContract::stableKey($credential),
                    'request_digest' => $frozen['digest'],
                    'status' => self::TASK_PENDING,
                    'attempt_count' => 0,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            }
            $this->audit->write(
                (string) $row['module_key'],
                'tenant_expire',
                $fromStatus->value,
                TenantModuleStatus::EXPIRED->value,
                null,
                null,
                true,
                ['type' => 'system'],
                (int) $row['organization'],
                context: [
                    'expire_at' => $row['expire_at'],
                    'reason' => 'expiry_transition',
                    'license_version_from' => $row['version'],
                    'license_version_to' => $expiredVersion,
                    'hook_task_id' => $taskId,
                    'hook_task_status' => $taskId === null ? 'not_required' : self::TASK_PENDING,
                ],
            );
            $reserved = $row + ['expired_version' => $expiredVersion, 'task_id' => $taskId];
        }, [
            function () use (&$reserved): void {
                if ($reserved !== null) {
                    $this->access->invalidate(
                        (int) $reserved['organization'],
                        (string) $reserved['module_key'],
                    );
                }
            },
            function () use (&$reserved): void {
                if ($reserved !== null) {
                    $this->authCaches->tenantStateChanged((int) $reserved['organization']);
                }
            },
        ]);

        return $reserved !== null;
    }

    /** @return array<string,mixed>|null */
    private function claimTask(): ?array
    {
        return Db::transaction(function (): ?array {
            $rows = Db::query(
                'SELECT id FROM ' . self::TASK_TABLE
                . " WHERE (status='pending')"
                . " OR (status='retry' AND next_retry_at<=NOW())"
                . " OR (status='processing' AND locked_until<=NOW())"
                . ' ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED',
            );
            if ($rows === []) {
                return null;
            }
            $token = bin2hex(random_bytes(20));
            $affected = Db::execute(
                'UPDATE ' . self::TASK_TABLE
                . " SET status='processing',attempt_count=attempt_count+1,worker_token=?,"
                . 'locked_until=TIMESTAMPADD(SECOND,?,NOW()),next_retry_at=NULL,last_error=NULL,update_time=NOW()'
                . ' WHERE id=? AND ('
                . "status='pending' OR (status='retry' AND next_retry_at<=NOW())"
                . " OR (status='processing' AND locked_until<=NOW()))",
                [$token, $this->taskLeaseSeconds, $rows[0]['id']],
            );
            if ($affected !== 1) {
                throw new RuntimeException('授权到期 hook 任务领取失败。');
            }
            $claimed = Db::query(
                'SELECT * FROM ' . self::TASK_TABLE
                . " WHERE id=? AND status='processing' AND worker_token=?"
                . ' AND locked_until>NOW() FOR UPDATE',
                [$rows[0]['id'], $token],
            );
            if (count($claimed) !== 1) {
                throw new RuntimeException('授权到期 hook 任务凭证不一致。');
            }

            return $claimed[0];
        });
    }

    /** @param array<string,mixed> $task */
    private function executeClaimedTask(array $task): string
    {
        $this->renewClaimedTask($task);

        return Db::transaction(function () use ($task): string {
            $licenseRows = Db::query(
                'SELECT status,version FROM sm_tenant_module_license'
                . ' WHERE id=? AND organization=? AND module_key=? AND delete_time IS NULL FOR UPDATE',
                [$task['license_id'], $task['organization'], $task['module_key']],
            );
            $taskRows = Db::query(
                'SELECT *,locked_until>NOW() AS lease_valid FROM ' . self::TASK_TABLE
                . ' WHERE id=? FOR UPDATE',
                [$task['id']],
            );
            $claimed = $this->assertClaimedTaskRow($task, $taskRows, '执行');
            $credential = $this->taskCredential($claimed);
            $expectedKey = ModuleExpiryHookContract::stableKey($credential);
            if (!hash_equals($expectedKey, (string) $claimed['idempotency_key'])) {
                return $this->completeTaskWithinTransaction(
                    $claimed,
                    $licenseRows,
                    self::TASK_CONTRACT_FAILED,
                    null,
                    'stable credential key mismatch',
                );
            }

            $matches = count($licenseRows) === 1
                && (string) ($licenseRows[0]['status'] ?? '') === TenantModuleStatus::EXPIRED->value
                && (int) ($licenseRows[0]['version'] ?? -1) === (int) $claimed['expired_version'];
            if (!$matches) {
                return $this->completeTaskWithinTransaction(
                    $claimed,
                    $licenseRows,
                    self::TASK_SUPERSEDED,
                    null,
                );
            }

            try {
                $frozen = ModuleExpiryHookContract::load(
                    (string) $claimed['hook_contract_json'],
                    (string) $claimed['request_digest'],
                    $credential,
                );
                if ((string) $claimed['hook_kind'] !== $frozen['hook_kind']
                    || (string) $claimed['hook_module_version'] !== $frozen['module_version']
                    || (string) $claimed['hook_handler'] !== $frozen['handler']
                    || (string) $claimed['hook_scope'] !== $frozen['scope']
                    || (bool) $claimed['hook_transactional'] !== $frozen['transactional']) {
                    throw new ModuleExpiryHookContractUnavailable(
                        'Frozen hook contract columns do not match canonical JSON.',
                    );
                }
                if ($frozen['hook_kind'] !== ModuleExpiryHookContract::KIND_TRANSACTIONAL) {
                    throw new ModuleExpiryHookContractUnavailable(sprintf(
                        'Module %s expiry hook has no atomic durable receipt contract.',
                        $claimed['module_key'],
                    ));
                }
            } catch (ModuleExpiryHookContractUnavailable $exception) {
                return $this->completeTaskWithinTransaction(
                    $claimed,
                    $licenseRows,
                    self::TASK_CONTRACT_FAILED,
                    null,
                    mb_substr($exception->getMessage(), 0, 4000),
                );
            }

            try {
                $hook = $this->runExpiryDisableHook(
                    $claimed,
                    $frozen['manifest'],
                    $frozen['operation'],
                );
            } catch (ModuleExpiryHookCredentialSuperseded) {
                return $this->completeTaskWithinTransaction(
                    $claimed,
                    $licenseRows,
                    self::TASK_SUPERSEDED,
                    null,
                );
            } catch (ModuleExpiryHookContractUnavailable $exception) {
                return $this->completeTaskWithinTransaction(
                    $claimed,
                    $licenseRows,
                    self::TASK_CONTRACT_FAILED,
                    null,
                    mb_substr($exception->getMessage(), 0, 4000),
                );
            }
            $receipt = [
                'contract_version' => 1,
                'idempotency_key' => (string) $claimed['idempotency_key'],
                'request_digest' => (string) $claimed['request_digest'],
                'credential' => $credential,
                'hook' => $hook,
            ];

            return $this->completeTaskWithinTransaction(
                $claimed,
                $licenseRows,
                self::TASK_SUCCEEDED,
                $receipt,
            );
        });
    }

    /** @param array<string,mixed> $task */
    private function assertTaskOwnership(array $task): void
    {
        $rows = Db::query(
            'SELECT id FROM ' . self::TASK_TABLE
            . " WHERE id=? AND status='processing' AND worker_token=? AND locked_until>NOW()",
            [$task['id'], $task['worker_token']],
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('授权到期 hook 任务租约已失效。');
        }
    }

    /** @param array<string,mixed> $task */
    private function renewClaimedTask(array $task): void
    {
        $affected = Db::execute(
            'UPDATE ' . self::TASK_TABLE
            . ' SET locked_until=GREATEST('
            . 'TIMESTAMPADD(SECOND,?,NOW()),TIMESTAMPADD(SECOND,1,locked_until)),update_time=NOW()'
            . " WHERE id=? AND status='processing' AND worker_token=? AND locked_until>NOW()",
            [$this->taskLeaseSeconds, $task['id'], $task['worker_token']],
        );
        if ($affected !== 1) {
            throw new RuntimeException('授权到期 hook 任务续租凭证已失效。');
        }
        $this->assertTaskOwnership($task);
    }

    /**
     * @param array<string,mixed> $claimed
     * @param list<array<string,mixed>> $licenseRows
     * @param array<string,mixed>|null $receipt
     */
    private function completeTaskWithinTransaction(
        array $claimed,
        array $licenseRows,
        string $outcome,
        ?array $receipt,
        ?string $terminalError = null,
    ): string {
        if (!in_array($outcome, [
            self::TASK_SUCCEEDED,
            self::TASK_SUPERSEDED,
            self::TASK_CONTRACT_FAILED,
        ], true)
            || ($outcome === self::TASK_SUCCEEDED) !== ($receipt !== null)
            || ($outcome === self::TASK_CONTRACT_FAILED) !== ($terminalError !== null)) {
            throw new RuntimeException('授权到期 hook terminal outcome 与 durable receipt 不一致。');
        }
        $currentStatus = count($licenseRows) === 1
            ? (string) ($licenseRows[0]['status'] ?? '')
            : null;
        $auditId = $this->audit->write(
            (string) $claimed['module_key'],
            'tenant_expire_hook',
            TenantModuleStatus::EXPIRED->value,
            $currentStatus,
            null,
            null,
            $outcome !== self::TASK_CONTRACT_FAILED,
            ['type' => 'system'],
            (int) $claimed['organization'],
            $terminalError,
            context: [
                'expiry_task_id' => (string) $claimed['id'],
                'outcome' => match ($outcome) {
                    self::TASK_SUCCEEDED => 'hook_succeeded',
                    self::TASK_SUPERSEDED => 'superseded_by_renewal',
                    self::TASK_CONTRACT_FAILED => 'immutable_contract_failed',
                },
                'license_id' => (string) $claimed['license_id'],
                'expired_version' => (int) $claimed['expired_version'],
                'attempt_count' => (int) $claimed['attempt_count'],
                'idempotency_key' => (string) $claimed['idempotency_key'],
                'request_digest' => (string) $claimed['request_digest'],
                'receipt' => $receipt,
                'terminal_error' => $terminalError,
            ],
        );
        if (preg_match('/^[1-9][0-9]*$/D', $auditId) !== 1) {
            throw new RuntimeException('授权到期 hook outcome 审计编号无效。');
        }
        $receiptJson = $receipt === null ? null : $this->encode($receipt);
        $affected = Db::execute(
            'UPDATE ' . self::TASK_TABLE
            . ' SET status=?,worker_token=NULL,locked_until=NULL,next_retry_at=NULL,last_error=?,'
            . 'receipt_json=?,receipt_recorded_at=IF(? IS NULL,NULL,NOW()),'
            . 'outcome_audit_id=?,finished_at=NOW(),update_time=NOW()'
            . " WHERE id=? AND status='processing' AND worker_token=?"
            . ' AND receipt_json IS NULL AND receipt_recorded_at IS NULL AND outcome_audit_id IS NULL',
            [
                $outcome,
                $terminalError,
                $receiptJson,
                $receiptJson,
                $auditId,
                $claimed['id'],
                $claimed['worker_token'],
            ],
        );
        if ($affected !== 1) {
            throw new RuntimeException('授权到期 hook receipt 与 outcome 未原子落盘。');
        }

        return $outcome;
    }

    /**
     * @param array<string,mixed> $task
     * @param list<array<string,mixed>> $taskRows
     * @return array<string,mixed>
     */
    private function assertClaimedTaskRow(array $task, array $taskRows, string $action): array
    {
        if (count($taskRows) !== 1
            || (string) ($taskRows[0]['status'] ?? '') !== self::TASK_PROCESSING
            || !hash_equals((string) ($task['worker_token'] ?? ''), (string) ($taskRows[0]['worker_token'] ?? ''))
            || (int) ($taskRows[0]['lease_valid'] ?? 0) !== 1
            || ($taskRows[0]['receipt_json'] ?? null) !== null
            || ($taskRows[0]['receipt_recorded_at'] ?? null) !== null
            || ($taskRows[0]['outcome_audit_id'] ?? null) !== null) {
            throw new RuntimeException(sprintf('授权到期 hook 任务%s凭证已失效。', $action));
        }

        return $taskRows[0];
    }

    /** @param array<string,mixed> $task @return array{license_id:string,organization:int,module_key:string,expired_version:int} */
    private function taskCredential(array $task): array
    {
        return [
            'license_id' => (string) $task['license_id'],
            'organization' => (int) $task['organization'],
            'module_key' => (string) $task['module_key'],
            'expired_version' => (int) $task['expired_version'],
        ];
    }

    /** @param array<string,mixed> $task */
    private function retryClaimedTask(array $task, Throwable $exception): void
    {
        Db::transaction(function () use ($task, $exception): void {
            $rows = Db::query(
                'SELECT attempt_count,receipt_json,receipt_recorded_at,outcome_audit_id,'
                . 'locked_until>NOW() AS lease_valid FROM ' . self::TASK_TABLE
                . " WHERE id=? AND status='processing' AND worker_token=? FOR UPDATE",
                [$task['id'], $task['worker_token']],
            );
            if (count($rows) !== 1
                || (int) ($rows[0]['lease_valid'] ?? 0) !== 1
                || ($rows[0]['receipt_json'] ?? null) !== null
                || ($rows[0]['receipt_recorded_at'] ?? null) !== null
                || ($rows[0]['outcome_audit_id'] ?? null) !== null) {
                throw new RuntimeException('授权到期 hook retry 凭证已失效。', previous: $exception);
            }
            $attempt = max(1, (int) ($rows[0]['attempt_count'] ?? 1));
            $power = min(20, $attempt - 1);
            $delay = min($this->retryMaxDelaySeconds, $this->retryBaseDelaySeconds * (2 ** $power));
            $affected = Db::execute(
                'UPDATE ' . self::TASK_TABLE
                . " SET status='retry',worker_token=NULL,locked_until=NULL,"
                . 'next_retry_at=TIMESTAMPADD(SECOND,?,NOW()),last_error=?,update_time=NOW()'
                . " WHERE id=? AND status='processing' AND worker_token=? AND locked_until>NOW()",
                [
                    $delay,
                    mb_substr($exception->getMessage(), 0, 4000),
                    $task['id'],
                    $task['worker_token'],
                ],
            );
            if ($affected !== 1) {
                throw new RuntimeException('授权到期 hook retry 未原子落盘。', previous: $exception);
            }
        });
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed>|null */
    private function lockDueCandidate(array $candidate): ?array
    {
        $rows = Db::query(
            'SELECT *,NOW() >= expire_at AS expiry_due FROM sm_tenant_module_license'
            . ' WHERE id=? AND delete_time IS NULL FOR UPDATE',
            [$candidate['id']],
        );
        if (count($rows) !== 1
            || !in_array((string) ($rows[0]['status'] ?? ''), [
                TenantModuleStatus::AUTHORIZED->value,
                TenantModuleStatus::ENABLED->value,
                TenantModuleStatus::DISABLED->value,
            ], true)
            || ($rows[0]['expire_at'] ?? null) === null
            || (int) ($rows[0]['expiry_due'] ?? 0) !== 1) {
            return null;
        }

        return $rows[0];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function runExpiryDisableHook(
        array $row,
        Manifest $manifest,
        LifecycleOperation $operation,
    ): array
    {
        $options = [
            'reason' => 'expiry',
            'actor' => ['type' => 'system'],
            self::HOOK_CREDENTIAL_OPTION => $this->taskCredential($row),
            self::HOOK_IDEMPOTENCY_OPTION => (string) $row['idempotency_key'],
            self::HOOK_REQUEST_DIGEST_OPTION => (string) $row['request_digest'],
            self::HOOK_TASK_ID_OPTION => (string) $row['id'],
        ];

        return $this->hooks->invokeExpiryInCurrentTransaction(
            $manifest,
            $operation,
            (int) $row['organization'],
            $row,
            $options,
        );
    }

    /** @param array<string,mixed> $row */
    private function expiryManifest(array $row): Manifest
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

        return new Manifest($manifestData);
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

    /** @return array{acquired:bool,scanned:int,expired:int,skipped:int,failed:int,tasks_succeeded:int,tasks_superseded:int,tasks_contract_failed:int,tasks_retried:int} */
    private function emptyResult(bool $acquired): array
    {
        return [
            'acquired' => $acquired,
            'scanned' => 0,
            'expired' => 0,
            'skipped' => 0,
            'failed' => 0,
            'tasks_succeeded' => 0,
            'tasks_superseded' => 0,
            'tasks_contract_failed' => 0,
            'tasks_retried' => 0,
        ];
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('授权到期 hook 结果序列化失败。', previous: $exception);
        }
    }
}
