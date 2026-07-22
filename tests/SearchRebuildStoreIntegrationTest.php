<?php

declare(strict_types=1);

$database = trim((string) getenv('SEARCH_REBUILD_STORE_TEST_DB_NAME'));
if ($database === '') {
    $database = 'nb8im_' . bin2hex(random_bytes(6)) . '_search_rebuild_store_test';
}
if (preg_match('/^nb8im_[a-f0-9]{8,24}_search_rebuild_store_test$/D', $database) !== 1) {
    throw new RuntimeException('Search rebuild integration requires an isolated random database.');
}
foreach (['DB_NAME' => $database, 'APP_DEBUG' => 'true'] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

use B8im\Module\Search\Rebuild\Claim;
use B8im\Module\Search\Rebuild\Projection;
use B8im\Module\Search\Lifecycle\LifecycleFence;
use plugin\saimulti\service\module\SearchExpiryCredentialFence;
use plugin\saimulti\service\module\ModuleExpiryHookCredentialSuperseded;
use plugin\saimulti\service\module\SearchService;
use plugin\saimulti\service\searchConsumer\SearchConsumerGateInterface;
use plugin\saimulti\service\searchRebuild\ThinkOrmSearchRebuildStore;
use support\think\Db;

$thinkConfig = config('think-orm');
$connectionName = (string) ($thinkConfig['default'] ?? 'mysql');
$connection = $thinkConfig['connections'][$connectionName] ?? null;
if (!is_array($connection)) {
    throw new RuntimeException('ThinkORM MySQL connection is unavailable.');
}
$adminDsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    (string) $connection['hostname'],
    (int) $connection['hostport'],
    (string) $connection['charset'],
);
$username = (string) $connection['username'];
$password = (string) $connection['password'];
$databaseDsn = str_replace(';charset=', ';dbname=' . $database . ';charset=', $adminDsn);
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$admin = new PDO($adminDsn, $username, $password, $pdoOptions);
$quotedDatabase = chr(96) . $database . chr(96);
register_shutdown_function(static function () use ($admin, $quotedDatabase): void {
    try {
        $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    } catch (Throwable) {
    }
});
$admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
$admin->exec('CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO(
    $databaseDsn,
    $username,
    $password,
    $pdoOptions + [PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
);
$pdo->exec(<<<'SQL'
CREATE TABLE sm_module (
 module_key varchar(64) PRIMARY KEY,
 status varchar(20) NOT NULL,
 delete_time datetime NULL
) ENGINE=InnoDB;
CREATE TABLE sm_tenant_module_license (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 organization int unsigned NOT NULL,
 module_key varchar(64) NOT NULL,
 status varchar(20) NOT NULL,
 version int unsigned NOT NULL,
 delete_time datetime NULL,
 UNIQUE KEY uni_license (organization,module_key)
) ENGINE=InnoDB;
CREATE TABLE sm_search_index (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 organization int unsigned NOT NULL,
 backend varchar(32) NOT NULL,
 status varchar(20) NOT NULL,
 doc_count bigint unsigned NOT NULL DEFAULT 0,
 last_built_at datetime NULL,
 last_error varchar(500) NOT NULL DEFAULT '',
 rebuild_required tinyint unsigned NOT NULL DEFAULT 1 CHECK (rebuild_required IN (0,1)),
 lifecycle_fenced tinyint unsigned NOT NULL DEFAULT 1 CHECK (lifecycle_fenced IN (0,1)),
 created_by int unsigned NULL,
 updated_by int unsigned NULL,
 create_time datetime NULL,
 update_time datetime NULL,
 delete_time datetime NULL,
 UNIQUE KEY uni_search_index_org (organization)
) ENGINE=InnoDB;
CREATE TABLE sm_search_job (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 organization int unsigned NOT NULL,
 job_type varchar(20) NOT NULL,
 status varchar(20) NOT NULL,
 processed bigint unsigned NOT NULL DEFAULT 0,
 total bigint unsigned NOT NULL DEFAULT 0,
 cursor_global_seq bigint unsigned NOT NULL DEFAULT 0,
 high_water_global_seq bigint unsigned NOT NULL DEFAULT 0,
 source_event_cut bigint unsigned NOT NULL DEFAULT 0,
 cleanup_cursor_doc_id bigint unsigned NOT NULL DEFAULT 0,
 cleanup_high_water_doc_id bigint unsigned NOT NULL DEFAULT 0,
 barrier_event_cut bigint unsigned NULL,
 barrier_deadline_at datetime NULL,
 finalized_checkpoint_event_seq bigint unsigned NULL,
 worker_id varchar(64) NULL,
 claim_token char(40) NULL,
 locked_until datetime NULL,
 retry_count int unsigned NOT NULL DEFAULT 0,
 next_retry_at datetime NULL,
 error_message varchar(500) NOT NULL DEFAULT '',
 created_by int unsigned NULL,
 updated_by int unsigned NULL,
 started_at datetime NULL,
 finished_at datetime NULL,
 create_time datetime NULL,
 update_time datetime NULL,
 active_rebuild_organization int unsigned GENERATED ALWAYS AS (
   CASE WHEN job_type='rebuild' AND status IN ('pending','running') THEN organization ELSE NULL END
 ) STORED,
 UNIQUE KEY uni_search_active_rebuild (active_rebuild_organization),
 KEY idx_search_claim (job_type,status,next_retry_at,locked_until,id),
 CONSTRAINT chk_success_search_job CHECK (
   status <> 'success' OR (
     processed=total
     AND cursor_global_seq=high_water_global_seq
     AND cleanup_cursor_doc_id=cleanup_high_water_doc_id
     AND barrier_event_cut IS NOT NULL
     AND barrier_deadline_at IS NOT NULL
     AND finalized_checkpoint_event_seq IS NOT NULL
     AND finalized_checkpoint_event_seq >= barrier_event_cut
   )
 )
) ENGINE=InnoDB;
CREATE TABLE im_organization_message_sequence (
 organization int unsigned PRIMARY KEY,
 next_global_seq bigint unsigned NOT NULL DEFAULT 1,
 last_search_event_seq bigint unsigned NOT NULL DEFAULT 0,
 create_time datetime NULL,
 update_time datetime NULL
) ENGINE=InnoDB;
CREATE TABLE im_message_index (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 organization int unsigned NOT NULL,
 message_id varchar(64) NOT NULL,
 global_seq bigint unsigned NOT NULL,
 UNIQUE KEY uni_message_identity (organization,message_id),
 UNIQUE KEY uni_message_global_seq (organization,global_seq)
) ENGINE=InnoDB;
CREATE TABLE sm_search_doc (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 organization int unsigned NOT NULL,
 message_id varchar(64) NOT NULL,
 conversation_type tinyint unsigned NOT NULL COMMENT '1单聊,2群聊',
 visibility tinyint unsigned NOT NULL DEFAULT 1,
 UNIQUE KEY uni_search_doc_message (organization,message_id),
 CONSTRAINT chk_search_doc_conversation_type CHECK (conversation_type IN (1,2))
) ENGINE=InnoDB;
CREATE TABLE sm_search_projection_receipt (
 organization int unsigned NOT NULL,
 source_event_seq bigint unsigned NOT NULL,
 event_id char(64) NOT NULL,
 create_time datetime NULL,
 PRIMARY KEY (organization,source_event_seq),
 UNIQUE KEY uni_search_receipt_event (event_id)
) ENGINE=InnoDB;
CREATE TABLE sm_search_projection_checkpoint (
 organization int unsigned PRIMARY KEY,
 reconciled_through_event_seq bigint unsigned NOT NULL DEFAULT 0,
 update_time datetime NULL
) ENGINE=InnoDB;
INSERT INTO sm_module (module_key,status) VALUES ('search','ENABLED');
SQL);

$thinkConfig['connections'][$connectionName]['database'] = $database;
Db::setConfig($thinkConfig);
if ((string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '') !== $database) {
    throw new RuntimeException('ThinkORM did not bind to the isolated database.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

final class SearchRebuildAlwaysGate implements SearchConsumerGateInterface
{
    public function __construct(private readonly bool $allowed = true)
    {
    }

    public function canFetch(): bool
    {
        return $this->allowed;
    }
}

final class SearchRebuildSqlProjection implements Projection
{
    /** @var list<array{int,string}> */
    public array $writes = [];

    public function projectMessageDocumentLocked(int $organization, string $messageId): void
    {
        $this->writes[] = [$organization, $messageId];
        Db::execute(
            'INSERT INTO sm_search_doc (organization,message_id,conversation_type,visibility)'
            . ' VALUES (?,?,1,1) ON DUPLICATE KEY UPDATE visibility=1',
            [$organization, $messageId],
        );
    }
}

final class SearchExpiryInnerFenceFake implements LifecycleFence
{
    public int $disableCalls = 0;

    public function assertReadyForEnable(?int $organization): void
    {
    }

    public function clearLifecycleFenceForEnable(?int $organization): void
    {
    }

    public function fenceForUpgrade(string $fromVersion, string $targetVersion): void
    {
    }

    public function fenceForDisable(?int $organization): void
    {
        $this->disableCalls++;
    }

    public function fenceForUninstall(bool $preserveData): void
    {
    }
}

$seedOrganization = static function (
    int $organization,
    int $nextGlobalSeq,
    int $lastSearchEventSeq,
    array $messages,
) use ($pdo): void {
    $statement = $pdo->prepare(
        'INSERT INTO im_organization_message_sequence'
        . ' (organization,next_global_seq,last_search_event_seq,create_time,update_time)'
        . ' VALUES (?,?,?,NOW(),NOW())',
    );
    $statement->execute([$organization, $nextGlobalSeq, $lastSearchEventSeq]);
    $messageStatement = $pdo->prepare(
        'INSERT INTO im_message_index (organization,message_id,global_seq) VALUES (?,?,?)',
    );
    foreach ($messages as $globalSeq => $messageId) {
        $messageStatement->execute([$organization, $messageId, $globalSeq]);
    }
};

$store = new ThinkOrmSearchRebuildStore(new SearchRebuildAlwaysGate());
$projection = new SearchRebuildSqlProjection();

try {
    $seedOrganization(101, 3, 2, [1 => 'm-1', 2 => 'm-2']);
    $enqueued = $store->enqueue(101, 9);
    $assert($enqueued['job']['high_water_global_seq'] === '2', 'enqueue 未冻结 source high-water H');
    $assert($enqueued['job']['source_event_cut'] === '2', 'enqueue 未冻结 source event cut B');
    $assert($enqueued['index']['rebuild_required'] === 1, 'enqueue 未设置 rebuild-required fence');
    $assert($enqueued['index']['lifecycle_fenced'] === 0, 'enqueue 越权设置 lifecycle fence');

    $claim = $store->claim('sql-worker', 30);
    $assert($claim instanceof Claim, 'pending rebuild was not claimed');
    $renewed = $store->renew($claim, 30);
    $assert($renewed->claimToken === $claim->claimToken, 'same-second renew lost ownership');

    $batch = $store->processBatch($renewed, 100, 30, $projection);
    $assert($batch->scanComplete, 'frozen high-water scan did not converge');
    $assert($batch->claim->cursorGlobalSeq === '2' && $batch->claim->processed === '2', 'scan progress not atomic');
    $assert($projection->writes === [[101, 'm-1'], [101, 'm-2']], 'rebuild did not project frozen source rows');
    $cleanup = $store->cleanupBatch($batch->claim, 100, 30);
    $assert($cleanup->complete && $cleanup->claim->cleanupCursorDocId === '0', 'empty frozen cleanup no-op failed');

    $pdo->exec('UPDATE im_organization_message_sequence SET last_search_event_seq=4 WHERE organization=101');
    $pdo->exec(
        "INSERT INTO sm_search_projection_receipt"
        . " (organization,source_event_seq,event_id,create_time) VALUES (101,4,'"
        . str_repeat('4', 64) . "',NOW())",
    );
    $barrierClaim = $store->captureBarrier($cleanup->claim, 300);
    $assert($barrierClaim->barrierEventCut === '4', 'capture did not freeze first post-rebuild cut C');
    $checkpoint = (string) $pdo->query(
        'SELECT reconciled_through_event_seq FROM sm_search_projection_checkpoint WHERE organization=101',
    )->fetchColumn();
    $assert($checkpoint === '2', 'capture did not advance checkpoint baseline to B');
    $pdo->exec('UPDATE im_organization_message_sequence SET last_search_event_seq=5 WHERE organization=101');
    $recaptured = $store->captureBarrier($barrierClaim, 600);
    $assert($recaptured->barrierEventCut === '4', 'barrier C changed after durable capture');

    $barrier = $store->barrierStatus($barrierClaim);
    $assert(!$barrier->satisfied && !$barrier->timedOut && $barrier->nextMissing === '3', 'out-of-order receipt crossed a gap');
    $pdo->exec(
        "INSERT INTO sm_search_projection_receipt"
        . " (organization,source_event_seq,event_id,create_time) VALUES (101,3,'"
        . str_repeat('3', 64) . "',NOW())",
    );
    $barrier = $store->barrierStatus($barrierClaim);
    $assert($barrier->satisfied && $barrier->reconciledThrough === '4', 'gap-free receipts did not advance checkpoint through C');

    $pdo->exec('UPDATE sm_search_index SET lifecycle_fenced=1 WHERE organization=101');
    try {
        $store->finalize($barrierClaim);
        $assert(false, 'finalize crossed lifecycle fence');
    } catch (RuntimeException) {
        $assert(true, 'lifecycle fence rejected finalize');
    }
    $pdo->exec('UPDATE sm_search_index SET lifecycle_fenced=0 WHERE organization=101');
    $store->finalize($barrierClaim);
    $job = $pdo->query(
        'SELECT status,processed,total,cursor_global_seq,high_water_global_seq,'
        . 'cleanup_cursor_doc_id,cleanup_high_water_doc_id,barrier_event_cut,'
        . 'finalized_checkpoint_event_seq,worker_id,claim_token,locked_until'
        . ' FROM sm_search_job WHERE organization=101',
    )->fetch();
    $index = $pdo->query(
        'SELECT status,doc_count,rebuild_required,lifecycle_fenced FROM sm_search_index WHERE organization=101',
    )->fetch();
    $assert(
        ($job['status'] ?? null) === 'success'
        && (string) ($job['processed'] ?? '') === (string) ($job['total'] ?? '')
        && (string) ($job['cursor_global_seq'] ?? '') === (string) ($job['high_water_global_seq'] ?? '')
        && (string) ($job['cleanup_cursor_doc_id'] ?? '') === (string) ($job['cleanup_high_water_doc_id'] ?? '')
        && (string) ($job['barrier_event_cut'] ?? '') === '4'
        && (string) ($job['finalized_checkpoint_event_seq'] ?? '') === '4'
        && ($job['worker_id'] ?? null) === null
        && ($job['claim_token'] ?? null) === null
        && ($job['locked_until'] ?? null) === null,
        'final job state did not persist exact terminal gates',
    );
    $assert(
        ($index['status'] ?? null) === 'ready'
        && (string) ($index['doc_count'] ?? '') === '2'
        && (int) ($index['rebuild_required'] ?? 1) === 0
        && (int) ($index['lifecycle_fenced'] ?? 1) === 0,
        'final index state did not atomically clear only rebuild-required',
    );
    $jobApi = (new SearchService())->jobList(101, [], true);
    $assert(
        ($jobApi['data'][0]['finalized_checkpoint_event_seq'] ?? null) === '4',
        'job API omitted or numerically coerced finalized checkpoint',
    );

    $seedOrganization(102, 1, 0, []);
    $store->enqueue(102, 9);
    $timeoutClaim = $store->claim('timeout-worker', 30);
    $assert($timeoutClaim instanceof Claim && $timeoutClaim->organization === 102, 'timeout rebuild was not claimed');
    $timeoutCleanup = $store->cleanupBatch($timeoutClaim, 100, 30);
    $pdo->exec('UPDATE im_organization_message_sequence SET last_search_event_seq=1 WHERE organization=102');
    $timeoutClaim = $store->captureBarrier($timeoutCleanup->claim, 300);
    $pdo->exec("UPDATE sm_search_job SET barrier_deadline_at=TIMESTAMPADD(SECOND,-1,NOW()) WHERE organization=102");
    $timeout = $store->barrierStatus($timeoutClaim);
    $assert($timeout->timedOut && !$timeout->satisfied && $timeout->nextMissing === '1', 'barrier timeout did not use DB time');

    $disabledGateStore = new ThinkOrmSearchRebuildStore(new SearchRebuildAlwaysGate(false));
    $assert($disabledGateStore->claim('must-not-claim', 30) === null, 'system lifecycle gate did not stop claim fetch');

    // Both rebuild failure and lifecycle fencing must acquire index -> job.
    // The worker holds index and then asks for job; fail() must wait without
    // holding job, otherwise MySQL detects the historical reverse-order cycle.
    $pdo->exec(
        "INSERT INTO sm_search_index"
        . " (organization,backend,status,doc_count,rebuild_required,lifecycle_fenced)"
        . " VALUES (103,'mysql','building',0,1,0)",
    );
    $lockToken = str_repeat('b', 40);
    $statement = $pdo->prepare(
        "INSERT INTO sm_search_job"
        . " (organization,job_type,status,processed,total,cursor_global_seq,high_water_global_seq,"
        . " source_event_cut,cleanup_cursor_doc_id,cleanup_high_water_doc_id,worker_id,claim_token,"
        . " locked_until,retry_count,error_message)"
        . " VALUES (103,'rebuild','running',0,0,0,0,0,0,0,'lock-worker',?,"
        . " TIMESTAMPADD(SECOND,30,NOW()),0,'')",
    );
    $statement->execute([$lockToken]);
    $lockJobId = (string) $pdo->lastInsertId();
    $lockedUntil = (string) $pdo->query(
        'SELECT locked_until FROM sm_search_job WHERE id=' . $lockJobId,
    )->fetchColumn();
    $lockClaim = new Claim(
        $lockJobId,
        103,
        '0',
        '0',
        '0',
        '0',
        '0',
        '0',
        '0',
        null,
        null,
        0,
        'lock-worker',
        $lockToken,
        $lockedUntil,
    );
    $readyFile = sys_get_temp_dir() . '/b8im-search-rebuild-lock-ready-' . bin2hex(random_bytes(8));
    $releaseFile = $readyFile . '-release';
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            __DIR__ . '/support/search_rebuild_lock_order_worker.php',
            $databaseDsn,
            $username,
            $password,
            '103',
            $readyFile,
            $releaseFile,
        ],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start rebuild lock-order worker.');
    }
    try {
        $deadline = microtime(true) + 10;
        while (!is_file($readyFile)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Rebuild lock-order worker did not acquire index.');
            }
            usleep(10_000);
        }
        file_put_contents($releaseFile, 'go');
        $store->fail($lockClaim, 'lock-order-regression');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $process = null;
        $lockState = $pdo->query(
            'SELECT j.status AS job_status,i.status AS index_status'
            . ' FROM sm_search_job j JOIN sm_search_index i ON i.organization=j.organization'
            . ' WHERE j.id=' . $lockJobId,
        )->fetch();
        $assert(
            $exitCode === 0
            && trim((string) $stdout) === 'ok'
            && trim((string) $stderr) === ''
            && ($lockState['job_status'] ?? null) === 'failed'
            && ($lockState['index_status'] ?? null) === 'error',
            'rebuild fail/lifecycle index-to-job lock order deadlocked or lost terminal state',
        );
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
        @unlink($readyFile);
        @unlink($releaseFile);
    }

    // Deterministic T1 reserve -> T2 renew -> T1 hook ordering: an old expiry
    // credential must become an atomic no-op before any search fence mutation.
    $pdo->exec(
        "INSERT INTO sm_tenant_module_license"
        . " (organization,module_key,status,version) VALUES (101,'search','EXPIRED',2)",
    );
    $licenseId = (string) $pdo->lastInsertId();
    $innerFence = new SearchExpiryInnerFenceFake();
    $credentialFence = new SearchExpiryCredentialFence($innerFence, [
        'license_id' => $licenseId,
        'organization' => 101,
        'module_key' => 'search',
        'expired_version' => 2,
    ]);
    $pdo->exec(
        "UPDATE sm_tenant_module_license SET status='ENABLED',version=3 WHERE id=" . $licenseId,
    );
    $superseded = false;
    try {
        $credentialFence->fenceForDisable(101);
    } catch (ModuleExpiryHookCredentialSuperseded) {
        $superseded = true;
    }
    $assert(
        $superseded && $innerFence->disableCalls === 0,
        'stale expiry credential fenced a renewed Search license',
    );
    $pdo->exec(
        "UPDATE sm_tenant_module_license SET status='EXPIRED',version=4 WHERE id=" . $licenseId,
    );
    $freshFence = new SearchExpiryCredentialFence($innerFence, [
        'license_id' => $licenseId,
        'organization' => 101,
        'module_key' => 'search',
        'expired_version' => 4,
    ]);
    $freshFence->fenceForDisable(101);
    $assert($innerFence->disableCalls === 1, 'current expiry credential did not execute Search fence');
} finally {
    // The registered shutdown handler removes the isolated database even on failure.
}

echo sprintf("SearchRebuildStoreIntegrationTest: %d assertions passed\n", $assertions);
