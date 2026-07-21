<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\Module\Search\Lifecycle\LifecycleFence;
use B8im\Module\Search\Rebuild\Config as RebuildConfig;
use B8im\Module\Search\Rebuild\WorkerReadiness;
use plugin\saimulti\exception\SearchProjectionIntegrityException;
use plugin\saimulti\service\searchConsumer\RedisSearchConsumerHeartbeatStore;
use plugin\saimulti\service\searchConsumer\SearchConsumerConfig;
use plugin\saimulti\service\searchConsumer\SearchConsumerReadinessReader;
use plugin\saimulti\service\searchConsumer\SystemClock;
use RuntimeException;
use support\think\Db;

final class SearchLifecycleFence implements LifecycleFence
{
    public function __construct(private readonly bool $callerOwnsTransaction = false)
    {
    }

    public function inCurrentTransaction(): self
    {
        return new self(true);
    }

    public function assertReadyForEnable(?int $organization): void
    {
        $this->assertRuntimeSchema();
        [$consumer, $rebuild] = $this->runtimeConfigs();
        $store = new RedisSearchConsumerHeartbeatStore();
        $clock = new SystemClock();
        if (!SearchConsumerReadinessReader::fromConfig($store, $clock, $consumer)->isReady()) {
            throw new RuntimeException('Search consumer heartbeat is not ready.');
        }
        if (!(new WorkerReadiness($store, $clock, $rebuild))->isReady()) {
            throw new RuntimeException('Search rebuild heartbeat is not ready.');
        }
    }

    public function clearLifecycleFenceForEnable(?int $organization): void
    {
        $this->transaction(function () use ($organization): void {
            if ($organization !== null) {
                $this->ensureIndex($organization);
            }
            $sql = "UPDATE sm_search_index SET lifecycle_fenced=0,status=IF(rebuild_required=1,'idle',status),"
                . "last_error=IF(rebuild_required=1,'Full rebuild required before search is ready.',''),update_time=NOW()"
                . ' WHERE delete_time IS NULL';
            $bindings = [];
            if ($organization !== null) {
                $sql .= ' AND organization=?';
                $bindings[] = $organization;
            }
            Db::execute($sql, $bindings);
            $this->assertScopeFence($organization, 0);
        });
    }

    public function fenceForUpgrade(string $fromVersion, string $targetVersion): void
    {
        if ($fromVersion === '' || $targetVersion === '') {
            throw new RuntimeException('Search upgrade versions are required.');
        }
        // This hook executes before the destructive v0.3 migration. Use only
        // columns shared by v0.1/v0.2 and v0.3.
        $this->transaction(function (): void {
            Db::execute(
                "UPDATE sm_search_index SET status='error',"
                . "last_error='Search upgrade fenced; full rebuild required.',update_time=NOW()"
                . ' WHERE delete_time IS NULL',
            );
            $flagColumns = Db::query(
                'SELECT COLUMN_NAME AS column_name FROM information_schema.columns'
                . " WHERE table_schema=DATABASE() AND table_name='sm_search_index'"
                . " AND column_name IN ('rebuild_required','lifecycle_fenced')",
            );
            if (count($flagColumns) === 2) {
                Db::execute(
                    'UPDATE sm_search_index SET rebuild_required=1,lifecycle_fenced=1,'
                    . 'update_time=NOW() WHERE delete_time IS NULL',
                );
            }
            Db::execute(
                "UPDATE sm_search_job SET status='failed',"
                . "error_message='Search upgrade fenced active job.',finished_at=NOW(),update_time=NOW()"
                . " WHERE status IN ('pending','running')",
            );
            $active = Db::query(
                "SELECT COUNT(*) AS aggregate FROM sm_search_job WHERE status IN ('pending','running')",
            );
            if ((int) ($active[0]['aggregate'] ?? -1) !== 0) {
                throw new SearchProjectionIntegrityException('Search upgrade did not terminate active jobs.');
            }
        });
    }

    public function fenceForDisable(?int $organization): void
    {
        $this->fenceRuntimeScope($organization, 'Search module disabled; full rebuild required.');
    }

    public function fenceForUninstall(bool $preserveData): void
    {
        $this->fenceRuntimeScope(null, 'Search module uninstall fenced active state.');
    }

    private function fenceRuntimeScope(?int $organization, string $reason): void
    {
        $this->assertRuntimeSchema();
        $this->transaction(function () use ($organization, $reason): void {
            $indexSql = "UPDATE sm_search_index SET status='error',rebuild_required=1,lifecycle_fenced=1,"
                . 'last_error=?,update_time=NOW() WHERE delete_time IS NULL';
            $jobSql = "UPDATE sm_search_job SET status='failed',error_message=?,finished_at=NOW(),"
                . 'worker_id=NULL,claim_token=NULL,locked_until=NULL,next_retry_at=NULL,update_time=NOW()'
                . " WHERE status IN ('pending','running')";
            $bindings = [$reason];
            $jobBindings = [$reason];
            if ($organization !== null) {
                $indexSql .= ' AND organization=?';
                $jobSql .= ' AND organization=?';
                $bindings[] = $organization;
                $jobBindings[] = $organization;
            }
            Db::execute($indexSql, $bindings);
            Db::execute($jobSql, $jobBindings);
            $this->assertScopeFence($organization, 1);
            $activeSql = "SELECT COUNT(*) AS aggregate FROM sm_search_job WHERE status IN ('pending','running')";
            $activeBindings = [];
            if ($organization !== null) {
                $activeSql .= ' AND organization=?';
                $activeBindings[] = $organization;
            }
            $active = Db::query($activeSql, $activeBindings);
            if ((int) ($active[0]['aggregate'] ?? -1) !== 0) {
                throw new SearchProjectionIntegrityException('Search lifecycle did not terminate active jobs.');
            }
        });
    }

    private function assertRuntimeSchema(): void
    {
        $required = [
            'sm_search_index' => ['rebuild_required', 'lifecycle_fenced'],
            'sm_search_job' => ['source_event_cut', 'barrier_event_cut', 'barrier_deadline_at'],
            'sm_search_projection_checkpoint' => ['reconciled_through_event_seq'],
            'sm_search_projection_receipt' => ['source_event_seq', 'event_id'],
            'im_organization_message_sequence' => ['last_search_event_seq'],
            'im_message_outbox' => ['source_event_seq'],
        ];
        foreach ($required as $table => $columns) {
            $rows = Db::query(
                'SELECT COLUMN_NAME AS column_name FROM information_schema.columns'
                . ' WHERE table_schema=DATABASE() AND table_name=?',
                [$table],
            );
            $present = array_map(
                static fn (array $row): string => (string) ($row['column_name'] ?? ''),
                $rows,
            );
            foreach ($columns as $column) {
                if (!in_array($column, $present, true)) {
                    throw new RuntimeException(sprintf('Search runtime schema is missing %s.%s.', $table, $column));
                }
            }
        }
    }

    private function transaction(callable $callback): void
    {
        if ($this->callerOwnsTransaction) {
            $callback();
            return;
        }
        Db::transaction($callback);
    }

    /** @return array{SearchConsumerConfig,RebuildConfig} */
    private function runtimeConfigs(): array
    {
        $consumerValues = config('plugin.saimulti.search');
        $rebuildValues = config('plugin.saimulti.search_rebuild');
        if (!is_array($consumerValues) || !is_array($rebuildValues)) {
            throw new RuntimeException('Search runtime configuration is missing.');
        }
        $consumer = SearchConsumerConfig::fromArray($consumerValues);
        $rebuild = RebuildConfig::fromArray($rebuildValues);
        if (!$consumer->enabled || !$rebuild->enabled) {
            throw new RuntimeException('Search consumer and rebuild processes must both be enabled.');
        }

        return [$consumer, $rebuild];
    }

    private function ensureIndex(int $organization): void
    {
        if ($organization < 1) {
            throw new RuntimeException('Search lifecycle organization is invalid.');
        }
        Db::execute(
            <<<'SQL'
INSERT INTO sm_search_index
       (organization,backend,status,doc_count,last_built_at,last_error,
        rebuild_required,lifecycle_fenced,create_time,update_time)
VALUES (?,'mysql','idle',0,NULL,'Full rebuild required before search is ready.',1,1,NOW(),NOW())
ON DUPLICATE KEY UPDATE id=id
SQL,
            [$organization],
        );
    }

    private function assertScopeFence(?int $organization, int $expected): void
    {
        $sql = 'SELECT organization,lifecycle_fenced,rebuild_required FROM sm_search_index'
            . ' WHERE delete_time IS NULL';
        $bindings = [];
        if ($organization !== null) {
            $sql .= ' AND organization=?';
            $bindings[] = $organization;
        }
        $sql .= ' FOR UPDATE';
        $rows = Db::query($sql, $bindings);
        if ($organization !== null && count($rows) !== 1) {
            throw new SearchProjectionIntegrityException('Search lifecycle index scope is missing.');
        }
        foreach ($rows as $row) {
            if ((int) ($row['lifecycle_fenced'] ?? -1) !== $expected
                || (int) ($row['rebuild_required'] ?? -1) !== 1) {
                throw new SearchProjectionIntegrityException('Search lifecycle fence did not persist.');
            }
        }
    }
}
