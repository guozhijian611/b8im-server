<?php

declare(strict_types=1);

$database = (string) getenv('STORAGE_QUOTA_TEST_DB_NAME');
if (!str_ends_with($database, '_storage_quota_test')) {
    throw new RuntimeException('unsafe storage quota test database');
}
putenv('DB_NAME=' . $database);
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\ModuleAccessCacheInterface;
use plugin\saimulti\service\module\ModuleAccessService;
use plugin\saimulti\service\module\ModuleAccessStoreInterface;
use plugin\saimulti\service\module\FileMediaPolicyService;
use plugin\saimulti\service\module\FileMediaService;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\quota\StorageQuotaAuthority;
use plugin\saimulti\service\quota\StorageQuotaService;
use plugin\saimulti\service\web\AuthoritativeWebImUploadPolicy;
use plugin\saimulti\service\web\ThinkOrmWebImUploadReservationService;
use plugin\saimulti\service\web\WebImUploadCleanupService;
use plugin\saimulti\service\web\WebImUploadPolicyInterface;
use plugin\saimulti\service\web\WebImUploadStorageInterface;
use support\think\Db;

$config = config('think-orm');
$connection = (string) ($config['default'] ?? 'mysql');
$config['connections'][$connection]['database'] = $database;
Db::setConfig($config);
$connectionConfig = $config['connections'][$connection];
$pdo = new PDO(sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $connectionConfig['hostname'],
    (int) $connectionConfig['hostport'],
    $database,
    $connectionConfig['charset'],
), (string) $connectionConfig['username'], (string) $connectionConfig['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS im_upload_asset (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 organization int unsigned NOT NULL,
 file_id char(40) NOT NULL,
 user_id varchar(64) NOT NULL,
 kind varchar(16) NOT NULL,
 name varchar(255) NOT NULL,
 url varchar(1024) NOT NULL,
 storage_path varchar(512) NOT NULL,
 size_byte bigint unsigned NOT NULL,
 mime_type varchar(255) NOT NULL DEFAULT '',
 extension varchar(32) NOT NULL DEFAULT '',
 status tinyint unsigned NOT NULL DEFAULT 1,
 create_time datetime NOT NULL,
 update_time datetime NOT NULL,
 delete_time datetime NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uni_organization_file(organization,file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS storage_quota_cleanup_log (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 reservation_id bigint unsigned NOT NULL,
 storage_path varchar(512) NOT NULL,
 create_time datetime NOT NULL,
 PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sm_file_media_quota (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 organization int unsigned NOT NULL,
 max_file_bytes bigint unsigned NOT NULL DEFAULT 2147483648,
 preview_enabled tinyint unsigned NOT NULL DEFAULT 1,
 large_file_enabled tinyint unsigned NOT NULL DEFAULT 1,
 status tinyint unsigned NOT NULL DEFAULT 1,
 created_by int unsigned NULL,
 updated_by int unsigned NULL,
 create_time datetime NULL,
 update_time datetime NULL,
 delete_time datetime NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uni_org(organization)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sm_file_media_folder (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 organization int unsigned NOT NULL,
 parent_id bigint unsigned NOT NULL DEFAULT 0,
 owner_user_id varchar(64) NOT NULL DEFAULT '',
 name varchar(100) NOT NULL,
 sort int unsigned NOT NULL DEFAULT 0,
 status tinyint unsigned NOT NULL DEFAULT 1,
 created_by int unsigned NULL,
 updated_by int unsigned NULL,
 create_time datetime NULL,
 update_time datetime NULL,
 delete_time datetime NULL,
 PRIMARY KEY(id),
 KEY idx_org_parent(organization,parent_id,status,id),
 KEY idx_org_owner(organization,owner_user_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sm_file_media_item (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 organization int unsigned NOT NULL,
 folder_id bigint unsigned NOT NULL DEFAULT 0,
 owner_user_id varchar(64) NOT NULL DEFAULT '',
 name varchar(255) NOT NULL,
 file_id varchar(128) NOT NULL DEFAULT '',
 mime_type varchar(128) NOT NULL DEFAULT '',
 kind varchar(20) NOT NULL DEFAULT 'file',
 size_bytes bigint unsigned NOT NULL DEFAULT 0,
 preview_status varchar(20) NOT NULL DEFAULT 'none',
 status tinyint unsigned NOT NULL DEFAULT 1,
 created_by int unsigned NULL,
 updated_by int unsigned NULL,
 create_time datetime NULL,
 update_time datetime NULL,
 delete_time datetime NULL,
 PRIMARY KEY(id),
 KEY idx_org_folder(organization,folder_id,status,id),
 KEY idx_org_owner(organization,owner_user_id,id),
 KEY idx_file_id(file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$assertions = 0;
$assert = static function (bool $value, string $message) use (&$assertions): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$databaseNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
$databaseNowEpoch = strtotime($databaseNow);
$phpNowEpoch = time();
$clockSkewSeconds = $databaseNowEpoch === false
    ? PHP_INT_MAX
    : abs($databaseNowEpoch - $phpNowEpoch);
$assert(
    $clockSkewSeconds <= 60,
    sprintf(
        'Database/PHP timezone drift: DB_NOW=%s PHP_NOW=%s skew=%d seconds.',
        $databaseNow,
        date('Y-m-d H:i:s', $phpNowEpoch),
        $clockSkewSeconds,
    ),
);
$setReservationChecks = static function (array $names, bool $enforced) use ($pdo): void {
    $allowed = [
        'chk_im_upload_reservation_positive',
        'chk_im_upload_reservation_state',
        'chk_im_upload_reservation_state_facts',
    ];
    foreach ($names as $name) {
        if (!in_array($name, $allowed, true)) {
            throw new RuntimeException('unsafe reservation CHECK test target');
        }
        $pdo->exec(sprintf(
            'ALTER TABLE sm_im_upload_reservation ALTER CHECK `%s` %s',
            $name,
            $enforced ? 'ENFORCED' : 'NOT ENFORCED',
        ));
    }
};
$exactRatio = static function (int $quota, int $occupancy): float {
    return (new StorageQuotaAuthority())->format([
        'row' => ['organization' => 1, 'version' => 1, 'update_time' => null],
        'quota_value' => $quota,
        'used_value' => $occupancy,
        'held_value' => 0,
        'occupancy_value' => $occupancy,
        'used_file_count' => 1,
        'held_file_count' => 0,
    ])['usage_ratio'];
};
$assert(
    $exactRatio(8000000000000000000, 2666667999999999999) === 0.333333
    && $exactRatio(8000000000000000000, 3999995999999999999) === 0.499999
    && $exactRatio(8000000000000000000, 987652000000000000) === 0.123457,
    'large quota ratios did not use exact six-decimal half-up arithmetic',
);
$pdo->exec('DELETE FROM sm_im_upload_reservation WHERE organization=1');
$pdo->exec('DELETE FROM im_upload_asset WHERE organization=1');
$pdo->exec('DELETE FROM sm_file_media_item WHERE organization=1');
$pdo->exec('DELETE FROM sm_file_media_folder WHERE organization=1');
$pdo->exec('DELETE FROM sm_file_media_quota WHERE organization=1');
$pdo->exec("UPDATE sm_tenant_quota SET quota_value=10,used_value=0,status='active',
 start_at=NULL,end_at=NULL,delete_time=NULL WHERE organization=1 AND quota_key='storage_bytes'");

$reserveWave = static function (int $count) use ($database): array {
    $processes = [];
    for ($index = 0; $index < $count; $index++) {
        $command = [
            PHP_BINARY,
            dirname(__DIR__) . '/tests/support/storage_quota_reserve_worker.php',
            $database,
            (string) $index,
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('worker start failed');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $reserved = 0;
    $rejected = 0;
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException('reserve worker failed: ' . $error);
        }
        $reserved += $output === 'reserved' ? 1 : 0;
        $rejected += $output === 'rejected:422' ? 1 : 0;
    }
    return [$reserved, $rejected];
};
$heldBytes = static fn (): int => (int) $pdo->query(
    "SELECT COALESCE(SUM(size_bytes),0) FROM sm_im_upload_reservation
      WHERE organization=1 AND state IN ('reserved','uploading','object_uploaded','cleanup_pending')",
)->fetchColumn();

[$reserved, $rejected] = $reserveWave(2);
$assert(
    $reserved === 1 && $rejected === 1 && $heldBytes() === 10,
    'two concurrent reserves overran quota',
);

$pdo->exec('DELETE FROM sm_im_upload_reservation WHERE organization=1');
$pdo->exec("UPDATE sm_tenant_quota SET quota_value=100,used_value=0 WHERE organization=1 AND quota_key='storage_bytes'");
[$reserved, $rejected] = $reserveWave(100);
$held = $heldBytes();
$assert(
    $reserved === 10 && $rejected === 90 && $held === 100,
    "100 concurrent reserves overran quota: reserved={$reserved}, rejected={$rejected}, held={$held}",
);

$pdo->exec('DELETE FROM sm_im_upload_reservation WHERE organization=1');
$pdo->exec("UPDATE sm_tenant_quota SET quota_value=1000,used_value=0 WHERE organization=1 AND quota_key='storage_bytes'");
$centralRows = (int) $pdo->query(
    "SELECT COUNT(*) FROM sm_tenant_quota WHERE organization=1 AND quota_key='storage_bytes'",
)->fetchColumn();
$modulePolicy = (new FileMediaPolicyService())->ensureDefault(1);
$centralRowsAfter = (int) $pdo->query(
    "SELECT COUNT(*) FROM sm_tenant_quota WHERE organization=1 AND quota_key='storage_bytes'",
)->fetchColumn();
$assert(
    (int) $modulePolicy['max_file_bytes'] === 2147483648
    && $centralRowsAfter === $centralRows,
    'module policy ensure mutated or created central quota',
);

$accessFor = static function (string $mode): ModuleAccessService {
    $store = new class($mode) implements ModuleAccessStoreInterface {
        public function __construct(private readonly string $mode) {}
        public function tenantSnapshot(int $organization, string $moduleKey): ?array
        {
            if ($this->mode === 'unavailable') {
                throw new RuntimeException('simulated module authority outage');
            }
            if ($this->mode === 'denied') {
                return null;
            }
            return [
                'module_status' => 'ENABLED',
                'license_status' => 'ENABLED',
                'expire_at' => null,
                'license_version' => 1,
                'module_version' => '0.4.1',
                'module_lock_version' => 1,
                'platforms' => ['server'],
                'capabilities' => [],
            ];
        }
        public function systemSnapshot(string $moduleKey): ?array { return null; }
        public function enabledTenantSnapshots(int $organization): array { return []; }
        public function enabledSystemSnapshots(): array { return []; }
        public function organizationsForModule(string $moduleKey): array { return []; }
    };
    $cache = new class implements ModuleAccessCacheInterface {
        public function get(string $key): ?array { return null; }
        public function set(string $key, array $value): void {}
        public function delete(string $key): void {}
    };
    return new ModuleAccessService($store, $cache);
};
$denied = false;
try {
    (new AuthoritativeWebImUploadPolicy($accessFor('denied')))->assertAllowed(1, 104857601);
} catch (ApiException $exception) {
    $denied = $exception->getCode() === 422;
}
$assert($denied, 'DENIED module state bypassed the base 100MiB limit');
(new AuthoritativeWebImUploadPolicy($accessFor('available')))->assertAllowed(1, 104857601);
$assert(true, 'AVAILABLE module policy unexpectedly rejected an allowed large file');
$unavailable = false;
try {
    (new AuthoritativeWebImUploadPolicy($accessFor('unavailable')))->assertAllowed(1, 1);
} catch (ApiException $exception) {
    $unavailable = $exception->getCode() === 503;
}
$assert($unavailable, 'UNAVAILABLE module authority did not fail closed');

$policy = new class implements WebImUploadPolicyInterface {
    public function assertAllowed(int $organization, int $sizeBytes): void {}
};
$store = new ThinkOrmWebImUploadReservationService($policy);
$now = date('Y-m-d H:i:s');
$active = [
    'organization' => 1,
    'upload_id' => str_repeat('1', 64),
    'idempotency_key' => str_repeat('2', 32),
    'intent_hash' => str_repeat('3', 64),
    'file_id' => str_repeat('4', 40),
    'storage_path' => 'private/organizations/1/im/202607/' . str_repeat('5', 48) . '.txt',
    'user_id' => 'owner',
    'client_family' => 'web',
    'kind' => 'file',
    'filename' => 'active.txt',
    'size_bytes' => 10,
    'mime_type' => 'application/octet-stream',
    'extension' => 'txt',
    'state' => 'reserved',
    'expires_at' => date('Y-m-d H:i:s', time() + 900),
    'create_time' => $now,
    'update_time' => $now,
];
$active['intent_hash'] = hash('sha256', implode("\0", [
    (string) $active['organization'],
    $active['user_id'],
    $active['client_family'],
    $active['kind'],
    $active['filename'],
    (string) $active['size_bytes'],
    $active['mime_type'],
    $active['extension'],
]));
$createdActive = $store->prepare($active);
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET state='uploading',
      expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE),
      upload_lease_token='" . str_repeat('a', 64) . "',
      upload_lease_expires_at=DATE_ADD(NOW(),INTERVAL 30 MINUTE)
      WHERE id=" . (int) $createdActive['id'],
);
$retryActive = $active;
$retryActive['upload_id'] = str_repeat('6', 64);
$retryActive['file_id'] = str_repeat('7', 40);
$retryActive['storage_path'] = 'private/organizations/1/im/202607/' . str_repeat('8', 48) . '.txt';
$activeRowsStatement = $pdo->prepare(
    'SELECT id,upload_id,intent_hash,user_id,client_family,state,version,expires_at,
            upload_lease_token,upload_lease_expires_at,
            expires_at<=NOW() AS prepare_expired,
            upload_lease_expires_at>NOW() AS upload_lease_active
       FROM sm_im_upload_reservation
      WHERE organization=? AND idempotency_key=?',
);
$activeRowsStatement->execute([$active['organization'], $active['idempotency_key']]);
$activeRowsBeforeRetry = $activeRowsStatement->fetchAll(PDO::FETCH_ASSOC);
$activeBeforeRetry = $activeRowsBeforeRetry[0] ?? null;
$assert(
    count($activeRowsBeforeRetry) === 1
    && is_array($activeBeforeRetry)
    && (string) $activeBeforeRetry['intent_hash'] === $active['intent_hash']
    && (string) $activeBeforeRetry['user_id'] === $active['user_id']
    && (string) $activeBeforeRetry['client_family'] === $active['client_family']
    && (string) $activeBeforeRetry['state'] === 'uploading'
    && (int) $activeBeforeRetry['prepare_expired'] === 1
    && (int) $activeBeforeRetry['upload_lease_active'] === 1,
    'active uploading idempotency fixture does not isolate the live-lease contract',
);
$refreshedActive = $store->prepare($retryActive);
$activeRowsStatement->execute([$active['organization'], $active['idempotency_key']]);
$activeRowsAfterRetry = $activeRowsStatement->fetchAll(PDO::FETCH_ASSOC);
$activeAfterRetry = $activeRowsAfterRetry[0] ?? null;
$assert(
    count($activeRowsAfterRetry) === 1
    && is_array($activeAfterRetry)
    && (int) $refreshedActive['id'] === (int) $activeBeforeRetry['id']
    && (string) $refreshedActive['upload_id'] === $active['upload_id']
    && (int) $refreshedActive['version'] === (int) $activeAfterRetry['version']
    && strtotime((string) $refreshedActive['expires_at'])
        === strtotime((string) $activeAfterRetry['expires_at'])
    && (int) $activeAfterRetry['id'] === (int) $activeBeforeRetry['id']
    && (string) $activeAfterRetry['upload_id'] === $active['upload_id']
    && (string) $activeAfterRetry['state'] === 'uploading'
    && (string) $activeAfterRetry['upload_lease_token']
        === (string) $activeBeforeRetry['upload_lease_token']
    && (string) $activeAfterRetry['upload_lease_expires_at']
        === (string) $activeBeforeRetry['upload_lease_expires_at']
    && strtotime((string) $refreshedActive['expires_at']) > time() + 800,
    'active uploading idempotency did not return the original upload id',
);

$expired = $active;
$expired['upload_id'] = str_repeat('9', 64);
$expired['idempotency_key'] = str_repeat('a', 32);
$expired['file_id'] = str_repeat('b', 40);
$expired['storage_path'] = 'private/organizations/1/im/202607/' . str_repeat('c', 48) . '.txt';
$expiredCreated = $store->prepare($expired);
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)
      WHERE id=" . (int) $expiredCreated['id'],
);
$expiredRejected = false;
try {
    $store->prepare($expired);
} catch (ApiException $exception) {
    $expiredRejected = $exception->getCode() === 409;
}
$assert($expiredRejected, 'expired reservation idempotency returned a dead upload id');

$fenceCandidate = $active;
$fenceCandidate['upload_id'] = str_repeat('1', 63) . '2';
$fenceCandidate['idempotency_key'] = str_repeat('2', 31) . '3';
$fenceCandidate['file_id'] = str_repeat('4', 39) . '5';
$fenceCandidate['storage_path'] = 'private/organizations/1/im/202607/'
    . str_repeat('6', 48) . '.txt';
$fenceCreated = $store->prepare($fenceCandidate);
$pdo->exec(
    'UPDATE sm_im_upload_reservation SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)'
    . ' WHERE id=' . (int) $fenceCreated['id'],
);
$claimCleanup = new ReflectionMethod($store, 'claimCleanupCandidate');
$fenceClaim = $claimCleanup->invoke($store, (int) $fenceCreated['id']);
if (!is_array($fenceClaim)) {
    throw new RuntimeException('cleanup fence fixture was not claimed');
}
$stolenToken = str_repeat('7', 64);
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET cleanup_lease_token='{$stolenToken}',version=version+1"
    . ' WHERE id=' . (int) $fenceCreated['id'],
);
$assert(
    !$store->authorizeCleanupDelete(
        (int) $fenceClaim['id'],
        (string) $fenceClaim['cleanup_lease_token'],
        (int) $fenceClaim['cleanup_claimed_version'],
        1,
        (string) $fenceClaim['storage_path'],
    ),
    'cleanup delete authorization ignored a stolen lease/version',
);
$pdo->prepare(
    'UPDATE sm_im_upload_reservation SET cleanup_lease_token=?,version=? WHERE id=?',
)->execute([
    $fenceClaim['cleanup_lease_token'],
    $fenceClaim['cleanup_claimed_version'],
    $fenceCreated['id'],
]);
$pdo->prepare(
    'INSERT INTO im_upload_asset
      (organization,file_id,user_id,kind,name,url,storage_path,size_byte,mime_type,extension,status,create_time,update_time)
     VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?)',
)->execute([
    $fenceCandidate['file_id'],
    $fenceCandidate['user_id'],
    $fenceCandidate['kind'],
    $fenceCandidate['filename'],
    '',
    $fenceCandidate['storage_path'],
    $fenceCandidate['size_bytes'],
    $fenceCandidate['mime_type'],
    $fenceCandidate['extension'],
    1,
    $now,
    $now,
]);
$pdo->exec(
    "UPDATE sm_tenant_quota SET used_value=10
      WHERE organization=1 AND quota_key='storage_bytes'",
);
$assetAfterClaimRejected = false;
try {
    $store->authorizeCleanupDelete(
        (int) $fenceClaim['id'],
        (string) $fenceClaim['cleanup_lease_token'],
        (int) $fenceClaim['cleanup_claimed_version'],
        1,
        (string) $fenceClaim['storage_path'],
    );
} catch (ApiException $exception) {
    $assetAfterClaimRejected = $exception->getCode() === 503;
}
$assert($assetAfterClaimRejected, 'cleanup authorization ignored an asset created after claim');
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET state='confirmed',expires_at='9999-12-31 23:59:59.999999',
      cleanup_lease_token=NULL,cleanup_lease_expires_at=NULL,confirmed_at=NOW()
      WHERE id=" . (int) $fenceCreated['id'],
);
$assert(
    !$store->authorizeCleanupDelete(
        (int) $fenceClaim['id'],
        (string) $fenceClaim['cleanup_lease_token'],
        (int) $fenceClaim['cleanup_claimed_version'],
        1,
        (string) $fenceClaim['storage_path'],
    ),
    'cleanup authorization accepted a confirmed permanent fact',
);
$pdo->exec('DELETE FROM sm_im_upload_reservation WHERE id=' . (int) $fenceCreated['id']);
$pdo->exec(
    "DELETE FROM im_upload_asset WHERE organization=1 AND file_id='"
    . $fenceCandidate['file_id'] . "'",
);
$pdo->exec(
    "UPDATE sm_tenant_quota SET used_value=0
      WHERE organization=1 AND quota_key='storage_bytes'",
);

$cleanupCandidate = $active;
$cleanupCandidate['upload_id'] = str_repeat('d', 64);
$cleanupCandidate['idempotency_key'] = str_repeat('e', 32);
$cleanupCandidate['file_id'] = str_repeat('f', 40);
$cleanupCandidate['storage_path'] = 'private/organizations/1/im/202607/' . str_repeat('0', 48) . '.txt';
$cleanupCreated = $store->prepare($cleanupCandidate);
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)
      WHERE id=" . (int) $cleanupCreated['id'],
);
$pdo->exec('DELETE FROM storage_quota_cleanup_log');
$cleanupWorkers = [];
for ($worker = 0; $worker < 2; $worker++) {
    $pipes = [];
    $process = proc_open([
        PHP_BINARY,
        dirname(__DIR__) . '/tests/support/storage_quota_cleanup_worker.php',
        $database,
        (string) $cleanupCreated['id'],
    ], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('cleanup worker start failed');
    }
    fclose($pipes[0]);
    $cleanupWorkers[] = [$process, $pipes];
}
$cleanupOutputs = [];
foreach ($cleanupWorkers as [$process, $pipes]) {
    $cleanupOutputs[] = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('cleanup worker failed: ' . $error);
    }
}
sort($cleanupOutputs);
$cleanupDeletes = (int) $pdo->query('SELECT COUNT(*) FROM storage_quota_cleanup_log')->fetchColumn();
$cleanupRow = $pdo->query(
    'SELECT state,cleanup_attempts FROM sm_im_upload_reservation WHERE id='
    . (int) $cleanupCreated['id'],
)->fetch(PDO::FETCH_ASSOC);
$assert(
    $cleanupOutputs === ['deleted', 'skipped']
    && $cleanupDeletes === 1
    && is_array($cleanupRow)
    && $cleanupRow['state'] === 'released'
    && (int) $cleanupRow['cleanup_attempts'] === 1,
    'two cleanup claimants acquired more than one lease or physical delete',
);

$pdo->exec('DELETE FROM sm_im_upload_reservation WHERE organization=1');
$uploadId = str_repeat('a', 64);
$confirmedIntentHash = hash('sha256', implode("\0", [
    '1', 'owner', 'web', 'file', 'one.pdf', '25', 'application/pdf', 'pdf',
]));
$pdo->prepare(
    'INSERT INTO sm_im_upload_reservation
     (organization,upload_id,idempotency_key,intent_hash,file_id,storage_path,user_id,client_family,
      kind,filename,size_bytes,mime_type,extension,state,expires_at,version,create_time,update_time)
     VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)',
)->execute([
    $uploadId, str_repeat('b', 32), $confirmedIntentHash, str_repeat('d', 40),
    'private/organizations/1/im/202607/' . str_repeat('e', 48) . '.pdf',
    'owner', 'web', 'file', 'one.pdf', 25, 'application/pdf', 'pdf',
    'object_uploaded', '9999-12-31 23:59:59.999999', $now, $now,
]);
$identity = ['organization' => 1, 'user_id' => 'owner', 'client_family' => 'web'];
$first = $store->confirm($identity, $uploadId);
$second = $store->confirm($identity, $uploadId);
$used = (int) $pdo->query(
    "SELECT used_value FROM sm_tenant_quota WHERE organization=1 AND quota_key='storage_bytes'",
)->fetchColumn();
$assetCount = (int) $pdo->query("SELECT COUNT(*) FROM im_upload_asset WHERE organization=1")->fetchColumn();
$terminalExpiry = (string) $pdo->query(
    "SELECT expires_at FROM sm_im_upload_reservation
      WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
)->fetchColumn();
$assert(
    $first === $second && $used === 25 && $assetCount === 1
    && str_starts_with($terminalExpiry, '9999-12-31 23:59:59'),
    'duplicate confirm charged more than once or lost terminal idempotency',
);

$terminalIntent = [
    'organization' => 1,
    'idempotency_key' => str_repeat('b', 32),
    'intent_hash' => $confirmedIntentHash,
    'user_id' => 'owner',
    'client_family' => 'web',
];
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET filename='drift.pdf'
      WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
);
$terminalPrepareDriftRejected = false;
try {
    $store->findPrepare($terminalIntent);
} catch (ApiException $exception) {
    $terminalPrepareDriftRejected = $exception->getCode() === 503;
}
$assert($terminalPrepareDriftRejected, 'terminal prepare returned drifted local metadata');
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET filename='one.pdf',size_bytes=26
      WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
);
$terminalUploadDriftRejected = false;
try {
    $store->claim($identity, $uploadId);
} catch (ApiException $exception) {
    $terminalUploadDriftRejected = $exception->getCode() === 503;
}
$assert($terminalUploadDriftRejected, 'confirmed upload retry returned drifted local metadata');
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET size_bytes=25
      WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
);
$pdo->exec(
    "UPDATE sm_tenant_quota SET used_value=999
      WHERE organization=1 AND quota_key='storage_bytes'",
);
$terminalPrepareWithoutCentral = $store->findPrepare($terminalIntent);
$terminalUploadWithoutCentral = $store->claim($identity, $uploadId);
$assert(
    $terminalPrepareWithoutCentral['state'] === 'confirmed'
    && $terminalUploadWithoutCentral['state'] === 'confirmed',
    'terminal retries consulted central quota drift after local invariant validation',
);
$pdo->exec(
    "UPDATE sm_tenant_quota SET used_value=25
      WHERE organization=1 AND quota_key='storage_bytes'",
);

$claimAuthorityProbe = [
    'organization' => 1,
    'upload_id' => str_repeat('2', 64),
    'idempotency_key' => str_repeat('3', 32),
    'intent_hash' => hash('sha256', implode("\0", [
        '1', 'owner', 'web', 'file', 'claim.txt', '4', 'text/plain', 'txt',
    ])),
    'file_id' => str_repeat('4', 40),
    'storage_path' => 'private/organizations/1/im/202607/' . str_repeat('5', 48) . '.txt',
    'user_id' => 'owner',
    'client_family' => 'web',
    'kind' => 'file',
    'filename' => 'claim.txt',
    'size_bytes' => 4,
    'mime_type' => 'text/plain',
    'extension' => 'txt',
    'state' => 'reserved',
    'expires_at' => date('Y-m-d H:i:s', time() + 900),
    'create_time' => $now,
    'update_time' => $now,
];
$claimAuthorityCreated = $store->prepare($claimAuthorityProbe);
$pdo->exec(
    "UPDATE sm_tenant_quota SET used_value=24
      WHERE organization=1 AND quota_key='storage_bytes'",
);
$claimDriftRejected = false;
try {
    $store->claim($identity, (string) $claimAuthorityCreated['upload_id']);
} catch (ApiException $exception) {
    $claimDriftRejected = $exception->getCode() === 503;
}
$claimState = (string) $pdo->query(
    'SELECT state FROM sm_im_upload_reservation WHERE id=' . (int) $claimAuthorityCreated['id'],
)->fetchColumn();
$assert(
    $claimDriftRejected && $claimState === 'reserved',
    'reserved claim reached uploading despite central quota drift',
);
$pdo->exec(
    "UPDATE sm_tenant_quota SET used_value=25
      WHERE organization=1 AND quota_key='storage_bytes'",
);
$store->release($identity, (string) $claimAuthorityCreated['upload_id']);

$cleanupOrganization = 999997;
$pdo->exec("DELETE FROM im_upload_asset WHERE organization={$cleanupOrganization}");
$pdo->exec("DELETE FROM sm_im_upload_reservation WHERE organization={$cleanupOrganization}");
$pdo->exec("DELETE FROM sm_tenant_quota WHERE organization={$cleanupOrganization}");
$pdo->exec("DELETE FROM sm_system_organization WHERE id={$cleanupOrganization}");
$cleanupOrganizationRow = $pdo->query(
    'SELECT * FROM sm_system_organization WHERE id=1',
)->fetch(PDO::FETCH_ASSOC);
$cleanupOrganizationRow['id'] = $cleanupOrganization;
$cleanupOrganizationRow['domain'] = null;
$cleanupOrganizationRow['enterprise_code'] = 'QA_STORAGE_CLEANUP_999997';
$cleanupOrganizationRow['deployment_id'] = 'qa-storage-cleanup-999997';
$cleanupOrganizationColumns = array_keys($cleanupOrganizationRow);
$pdo->prepare(sprintf(
    'INSERT INTO sm_system_organization (`%s`) VALUES (%s)',
    implode('`,`', $cleanupOrganizationColumns),
    implode(',', array_fill(0, count($cleanupOrganizationColumns), '?')),
))->execute(array_values($cleanupOrganizationRow));
$pdo->exec(
    "INSERT INTO sm_tenant_quota
      (organization,quota_key,quota_value,used_value,source,status,version,create_time,update_time)
     VALUES ({$cleanupOrganization},'storage_bytes',1000,0,'manual','active',1,NOW(),NOW())",
);
$cleanupBatchLimit = 3;
$additionalBadCleanupIds = [];
for ($index = 0; $index < $cleanupBatchLimit - 1; $index++) {
    $badCleanup = $active;
    $badCleanup['upload_id'] = hash('sha256', "cleanup-budget-upload-{$index}");
    $badCleanup['idempotency_key'] = substr(
        hash('sha256', "cleanup-budget-idempotency-{$index}"),
        0,
        32,
    );
    $badCleanup['file_id'] = hash('sha1', "cleanup-budget-file-{$index}");
    $badCleanup['storage_path'] = 'private/organizations/1/im/202607/'
        . substr(hash('sha256', "cleanup-budget-path-{$index}"), 0, 48) . '.txt';
    $badCleanup['intent_hash'] = hash('sha256', implode("\0", [
        '1',
        $badCleanup['user_id'],
        $badCleanup['client_family'],
        $badCleanup['kind'],
        $badCleanup['filename'],
        (string) $badCleanup['size_bytes'],
        $badCleanup['mime_type'],
        $badCleanup['extension'],
    ]));
    $badCleanupCreated = $store->prepare($badCleanup);
    $additionalBadCleanupIds[] = (int) $badCleanupCreated['id'];
    $pdo->exec(
        'UPDATE sm_im_upload_reservation SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)'
        . ' WHERE id=' . (int) $badCleanupCreated['id'],
    );
}
$laterCleanup = $active;
$laterCleanup['organization'] = $cleanupOrganization;
$laterCleanup['upload_id'] = str_repeat('6', 64);
$laterCleanup['idempotency_key'] = str_repeat('7', 32);
$laterCleanup['file_id'] = str_repeat('8', 40);
$laterCleanup['storage_path'] = 'private/organizations/' . $cleanupOrganization
    . '/im/202607/' . str_repeat('9', 48) . '.txt';
$laterCleanup['intent_hash'] = hash('sha256', implode("\0", [
    (string) $laterCleanup['organization'],
    $laterCleanup['user_id'],
    $laterCleanup['client_family'],
    $laterCleanup['kind'],
    $laterCleanup['filename'],
    (string) $laterCleanup['size_bytes'],
    $laterCleanup['mime_type'],
    $laterCleanup['extension'],
]));
$laterCleanupCreated = $store->prepare($laterCleanup);
$pdo->exec(
    'UPDATE sm_im_upload_reservation SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)'
    . ' WHERE id=' . (int) $laterCleanupCreated['id'],
);
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET state='object_uploaded',
      confirmed_at=NULL,
      update_time=DATE_SUB(NOW(),INTERVAL 31 MINUTE)
      WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
);
$badCleanupReservationId = (int) $pdo->query(
    'SELECT id FROM sm_im_upload_reservation WHERE organization=1 AND upload_id='
    . $pdo->quote($uploadId),
)->fetchColumn();
$badCleanupReservationIds = [
    $badCleanupReservationId,
    ...$additionalBadCleanupIds,
];
$cleanupStorage = new class implements WebImUploadStorageInterface {
    /** @var list<string> */
    public array $deleted = [];
    public function assertReady(): void {}
    public function reservePath(int $organization, string $extension, string $objectId): string
    {
        throw new RuntimeException('not used');
    }
    public function uploadExact(
        int $organization,
        SplFileInfo $file,
        string $storagePath,
        string $mimeType,
        ?callable $heartbeat = null,
    ): void {
        throw new RuntimeException('not used');
    }
    public function inspect(int $organization, string $storagePath): array
    {
        throw new RuntimeException('not used');
    }
    public function delete(int $organization, string $storagePath): void
    {
        $this->deleted[] = $storagePath;
    }
};
$cleanupService = new WebImUploadCleanupService($store, $cleanupStorage);
$firstCleanupRun = $cleanupService->run($cleanupBatchLimit);
$quarantinedCleanupRows = $pdo->query(
    'SELECT id,state,cleanup_attempts,cleanup_next_at,cleanup_error,
            upload_lease_token,upload_lease_expires_at,
            cleanup_lease_token,cleanup_lease_expires_at
       FROM sm_im_upload_reservation
      WHERE id IN (' . implode(',', $badCleanupReservationIds) . ')
      ORDER BY id',
)->fetchAll(PDO::FETCH_ASSOC);
$firstCleanupErrorIds = array_map(
    'intval',
    array_column($firstCleanupRun['errors'], 'reservation_id'),
);
sort($firstCleanupErrorIds, SORT_NUMERIC);
$expectedBadCleanupIds = $badCleanupReservationIds;
sort($expectedBadCleanupIds, SORT_NUMERIC);
$quarantineDurable = count($quarantinedCleanupRows) === $cleanupBatchLimit;
foreach ($quarantinedCleanupRows as $quarantinedCleanupRow) {
    $quarantineDurable = $quarantineDurable
        && $quarantinedCleanupRow['state'] === 'cleanup_pending'
        && (int) $quarantinedCleanupRow['cleanup_attempts'] === 1
        && strtotime((string) $quarantinedCleanupRow['cleanup_next_at']) > time()
        && $quarantinedCleanupRow['cleanup_error'] === 'cleanup claim authority unavailable'
        && $quarantinedCleanupRow['upload_lease_token'] === null
        && $quarantinedCleanupRow['upload_lease_expires_at'] === null
        && $quarantinedCleanupRow['cleanup_lease_token'] === null
        && $quarantinedCleanupRow['cleanup_lease_expires_at'] === null;
}
$assert(
    $firstCleanupRun['scanned'] === $cleanupBatchLimit
    && $firstCleanupRun['claimed'] === 0
    && $firstCleanupRun['released'] === 0
    && $firstCleanupRun['failed'] === $cleanupBatchLimit
    && count($firstCleanupRun['errors']) === $cleanupBatchLimit
    && $firstCleanupErrorIds === $expectedBadCleanupIds
    && $quarantineDurable
    && $cleanupStorage->deleted === [],
    'cleanup candidate/error budget was unbounded or bad candidates were not durably deferred',
);
$cleanupRun = $cleanupService->run($cleanupBatchLimit);
$laterCleanupState = (string) $pdo->query(
    'SELECT state FROM sm_im_upload_reservation WHERE id=' . (int) $laterCleanupCreated['id'],
)->fetchColumn();
$assert(
    $cleanupRun['scanned'] === 1
    && $cleanupRun['claimed'] === 1
    && $cleanupRun['released'] === 1
    && $cleanupRun['failed'] === 0
    && in_array($laterCleanup['storage_path'], $cleanupStorage->deleted, true)
    && $laterCleanupState === 'released'
    && $cleanupRun['errors'] === [],
    'durably deferred authority failures starved the next cleanup batch',
);
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET state='confirmed',confirmed_at=NOW(),
      cleanup_next_at=NULL,cleanup_error='',cleanup_attempts=0,version=version+1,update_time=NOW()
      WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
);
$pdo->exec(
    'DELETE FROM sm_im_upload_reservation WHERE id IN ('
    . implode(',', $additionalBadCleanupIds) . ')',
);

$pdo->exec(
    'UPDATE sm_im_upload_cleanup_cursor SET last_reservation_id='
    . (int) $laterCleanupCreated['id'] . ',update_time=NOW(6) WHERE id=1',
);
$unquarantinableCleanupIds = [];
for ($index = 0; $index < $cleanupBatchLimit; $index++) {
    $unquarantinableCleanup = $active;
    $unquarantinableCleanup['upload_id'] = hash(
        'sha256',
        "cleanup-unquarantinable-upload-{$index}",
    );
    $unquarantinableCleanup['idempotency_key'] = substr(
        hash('sha256', "cleanup-unquarantinable-idempotency-{$index}"),
        0,
        32,
    );
    $unquarantinableCleanup['file_id'] = hash(
        'sha1',
        "cleanup-unquarantinable-file-{$index}",
    );
    $unquarantinableCleanup['storage_path'] = 'private/organizations/1/im/202607/'
        . substr(hash('sha256', "cleanup-unquarantinable-path-{$index}"), 0, 48)
        . '.txt';
    $unquarantinableCleanup['intent_hash'] = hash('sha256', implode("\0", [
        '1',
        $unquarantinableCleanup['user_id'],
        $unquarantinableCleanup['client_family'],
        $unquarantinableCleanup['kind'],
        $unquarantinableCleanup['filename'],
        (string) $unquarantinableCleanup['size_bytes'],
        $unquarantinableCleanup['mime_type'],
        $unquarantinableCleanup['extension'],
    ]));
    $unquarantinableCreated = $store->prepare($unquarantinableCleanup);
    $unquarantinableCleanupIds[] = (int) $unquarantinableCreated['id'];
    $pdo->exec(
        'UPDATE sm_im_upload_reservation
            SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE),version=4294967295
          WHERE id=' . (int) $unquarantinableCreated['id'],
    );
}
$secondLaterCleanup = $laterCleanup;
$secondLaterCleanup['upload_id'] = hash('sha256', 'cleanup-cursor-later-upload');
$secondLaterCleanup['idempotency_key'] = substr(
    hash('sha256', 'cleanup-cursor-later-idempotency'),
    0,
    32,
);
$secondLaterCleanup['file_id'] = hash('sha1', 'cleanup-cursor-later-file');
$secondLaterCleanup['storage_path'] = 'private/organizations/' . $cleanupOrganization
    . '/im/202607/' . substr(hash('sha256', 'cleanup-cursor-later-path'), 0, 48)
    . '.txt';
$secondLaterCleanup['intent_hash'] = hash('sha256', implode("\0", [
    (string) $cleanupOrganization,
    $secondLaterCleanup['user_id'],
    $secondLaterCleanup['client_family'],
    $secondLaterCleanup['kind'],
    $secondLaterCleanup['filename'],
    (string) $secondLaterCleanup['size_bytes'],
    $secondLaterCleanup['mime_type'],
    $secondLaterCleanup['extension'],
]));
$secondLaterCleanupCreated = $store->prepare($secondLaterCleanup);
$pdo->exec(
    'UPDATE sm_im_upload_reservation SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)'
    . ' WHERE id=' . (int) $secondLaterCleanupCreated['id'],
);
$failedQuarantineRun = $cleanupService->run($cleanupBatchLimit);
$failedQuarantineRows = $pdo->query(
    'SELECT id,state,version,cleanup_attempts
       FROM sm_im_upload_reservation
      WHERE id IN (' . implode(',', $unquarantinableCleanupIds) . ')
      ORDER BY id',
)->fetchAll(PDO::FETCH_ASSOC);
$failedQuarantinePhases = array_column($failedQuarantineRun['errors'], 'phase');
$cursorAfterFailedQuarantine = (int) $pdo->query(
    'SELECT last_reservation_id FROM sm_im_upload_cleanup_cursor WHERE id=1',
)->fetchColumn();
$assert(
    $failedQuarantineRun['scanned'] === $cleanupBatchLimit
    && $failedQuarantineRun['claimed'] === 0
    && $failedQuarantineRun['failed'] === $cleanupBatchLimit
    && $failedQuarantinePhases === array_fill(
        0,
        $cleanupBatchLimit,
        'claim_quarantine',
    )
    && count($failedQuarantineRows) === $cleanupBatchLimit
    && array_column($failedQuarantineRows, 'state')
        === array_fill(0, $cleanupBatchLimit, 'reserved')
    && array_map('intval', array_column($failedQuarantineRows, 'version'))
        === array_fill(0, $cleanupBatchLimit, 4294967295)
    && $cursorAfterFailedQuarantine === $unquarantinableCleanupIds[array_key_last(
        $unquarantinableCleanupIds,
    )],
    'failed quarantine prefix was not bounded and durably rotated',
);
$deletesBeforeCursorAdvance = count($cleanupStorage->deleted);
$cursorAdvanceRun = $cleanupService->run($cleanupBatchLimit);
$secondLaterCleanupState = (string) $pdo->query(
    'SELECT state FROM sm_im_upload_reservation WHERE id='
    . (int) $secondLaterCleanupCreated['id'],
)->fetchColumn();
$assert(
    $cursorAdvanceRun['scanned'] === 1
    && $cursorAdvanceRun['claimed'] === 1
    && $cursorAdvanceRun['released'] === 1
    && $cursorAdvanceRun['failed'] === 0
    && $secondLaterCleanupState === 'released'
    && count($cleanupStorage->deleted) === $deletesBeforeCursorAdvance + 1
    && $cleanupStorage->deleted[array_key_last($cleanupStorage->deleted)]
        === $secondLaterCleanup['storage_path'],
    'persistent cleanup cursor did not advance past an unquarantinable prefix',
);
$pdo->exec(
    'DELETE FROM sm_im_upload_reservation WHERE id IN ('
    . implode(',', $unquarantinableCleanupIds) . ')',
);
$pdo->exec("DELETE FROM sm_im_upload_reservation WHERE organization={$cleanupOrganization}");
$pdo->exec("DELETE FROM sm_tenant_quota WHERE organization={$cleanupOrganization}");
$pdo->exec("DELETE FROM sm_system_organization WHERE id={$cleanupOrganization}");

$physicalBeforeLateCleanup = (int) $pdo->query(
    'SELECT COALESCE(SUM(path_size),0)
       FROM (
         SELECT storage_path,MAX(size_byte) AS path_size
           FROM im_upload_asset
          WHERE organization=1
       GROUP BY storage_path
       ) physical_paths',
)->fetchColumn();
$quotaBeforeLateCleanup = (int) $pdo->query(
    "SELECT quota_value FROM sm_tenant_quota
      WHERE organization=1 AND quota_key='storage_bytes'",
)->fetchColumn();
$fillerBytes = $quotaBeforeLateCleanup - $physicalBeforeLateCleanup;
if ($fillerBytes <= 0) {
    throw new RuntimeException('late cleanup fixture has no quota headroom');
}
$lateCleanup = $active;
$lateCleanup['upload_id'] = hash('sha256', 'late-cleanup-upload');
$lateCleanup['idempotency_key'] = substr(hash('sha256', 'late-cleanup-idempotency'), 0, 32);
$lateCleanup['file_id'] = hash('sha1', 'late-cleanup-file');
$lateCleanup['storage_path'] = 'private/organizations/1/im/202607/'
    . substr(hash('sha256', 'late-cleanup-path'), 0, 48) . '.bin';
$lateCleanup['filename'] = 'late-cleanup.bin';
$lateCleanup['size_bytes'] = 10;
$lateCleanup['mime_type'] = 'application/octet-stream';
$lateCleanup['extension'] = 'bin';
$lateCleanup['expires_at'] = date('Y-m-d H:i:s', time() + 900);
$lateCleanup['intent_hash'] = hash('sha256', implode("\0", [
    '1', 'owner', 'web', 'file', $lateCleanup['filename'],
    (string) $lateCleanup['size_bytes'], $lateCleanup['mime_type'], $lateCleanup['extension'],
]));
$lateCleanupCreated = $store->prepare($lateCleanup);
$store->release($identity, (string) $lateCleanupCreated['upload_id']);

$capacityFiller = $active;
$capacityFiller['upload_id'] = hash('sha256', 'late-cleanup-filler-upload');
$capacityFiller['idempotency_key'] = substr(
    hash('sha256', 'late-cleanup-filler-idempotency'),
    0,
    32,
);
$capacityFiller['file_id'] = hash('sha1', 'late-cleanup-filler-file');
$capacityFiller['storage_path'] = 'private/organizations/1/im/202607/'
    . substr(hash('sha256', 'late-cleanup-filler-path'), 0, 48) . '.bin';
$capacityFiller['filename'] = 'capacity-filler.bin';
$capacityFiller['size_bytes'] = $fillerBytes;
$capacityFiller['mime_type'] = 'application/octet-stream';
$capacityFiller['extension'] = 'bin';
$capacityFiller['expires_at'] = date('Y-m-d H:i:s', time() + 900);
$capacityFiller['intent_hash'] = hash('sha256', implode("\0", [
    '1', 'owner', 'web', 'file', $capacityFiller['filename'],
    (string) $capacityFiller['size_bytes'],
    $capacityFiller['mime_type'], $capacityFiller['extension'],
]));
$capacityFillerCreated = $store->prepare($capacityFiller);
$store->registerObjectCleanup(
    (int) $lateCleanupCreated['id'],
    'late_object_after_release',
);
$lateCleanupHeld = $pdo->query(
    'SELECT state,released_at,release_reason,cleanup_next_at
       FROM sm_im_upload_reservation
      WHERE id=' . (int) $lateCleanupCreated['id'],
)->fetch(PDO::FETCH_ASSOC);
$strictOverCapacityRejected = false;
try {
    (new StorageQuotaService())->read(1);
} catch (ApiException $exception) {
    $strictOverCapacityRejected = $exception->getCode() === 503;
}
$deletesBeforeLateCleanup = count($cleanupStorage->deleted);
$lateCleanupRun = $cleanupService->run(1);
$lateCleanupState = (string) $pdo->query(
    'SELECT state FROM sm_im_upload_reservation WHERE id=' . (int) $lateCleanupCreated['id'],
)->fetchColumn();
$authorityAfterLateCleanup = (new StorageQuotaService())->read(1);
$assert(
    is_array($lateCleanupHeld)
    && $lateCleanupHeld['state'] === 'cleanup_pending'
    && $lateCleanupHeld['released_at'] === null
    && $lateCleanupHeld['release_reason'] === ''
    && strtotime((string) $lateCleanupHeld['cleanup_next_at']) <= time()
    && $strictOverCapacityRejected
    && $lateCleanupRun['scanned'] === 1
    && $lateCleanupRun['claimed'] === 1
    && $lateCleanupRun['released'] === 1
    && $lateCleanupRun['failed'] === 0
    && count($cleanupStorage->deleted) === $deletesBeforeLateCleanup + 1
    && $cleanupStorage->deleted[array_key_last($cleanupStorage->deleted)]
        === $lateCleanup['storage_path']
    && $lateCleanupState === 'released'
    && $authorityAfterLateCleanup['occupancy_value'] === (string) $quotaBeforeLateCleanup,
    'late object cleanup could not reduce held usage while strict authority was over capacity',
);
$store->release($identity, (string) $capacityFillerCreated['upload_id']);
$authorityAfterFillerRelease = (new StorageQuotaService())->read(1);
$assert(
    $authorityAfterFillerRelease['occupancy_value'] === (string) $physicalBeforeLateCleanup,
    'strict storage authority did not recover after late-object cleanup and filler release',
);
$pdo->exec(
    'DELETE FROM sm_im_upload_reservation WHERE id IN ('
    . (int) $lateCleanupCreated['id'] . ',' . (int) $capacityFillerCreated['id'] . ')',
);

$storagePath = (string) $pdo->query(
    "SELECT storage_path FROM im_upload_asset WHERE organization=1 LIMIT 1",
)->fetchColumn();
$alias = $pdo->prepare(
    'INSERT INTO im_upload_asset
      (organization,file_id,user_id,kind,name,url,storage_path,size_byte,mime_type,extension,status,create_time,update_time)
     VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?)',
);
$alias->execute([
    str_repeat('f', 40), 'owner', 'file', 'alias.pdf', '', $storagePath, 25,
    'application/pdf', 'pdf', 1, $now, $now,
]);
$storageService = new StorageQuotaService();
$storageProjection = $storageService->read(1);
$assert(
    array_keys($storageProjection) === [
        'organization', 'quota_key', 'quota_value', 'used_value', 'held_value',
        'occupancy_value', 'remaining_value', 'unlimited', 'used_file_count',
        'held_file_count', 'usage_ratio', 'version', 'update_time',
    ]
    && $storageProjection['used_value'] === '25'
    && $storageProjection['used_file_count'] === 1,
    'core storage DTO drifted or counted a physical-path alias twice',
);
$indexProjection = $storageService->index(['organization' => '1']);
$assert(
    $indexProjection['total'] === 1
    && $indexProjection['data'] === [$storageProjection],
    'core storage index did not enumerate the active organization authority',
);

$authorityProbe = $active;
$authorityProbe['upload_id'] = str_repeat('8', 64);
$authorityProbe['idempotency_key'] = str_repeat('7', 32);
$authorityProbe['file_id'] = str_repeat('5', 40);
$authorityProbe['storage_path'] = 'private/organizations/1/im/202607/'
    . str_repeat('4', 48) . '.txt';
$authorityProbe['size_bytes'] = 3;
$authorityProbe['intent_hash'] = hash('sha256', implode("\0", [
    '1', 'owner', 'web', 'file', 'active.txt', '3', 'application/octet-stream', 'txt',
]));
$authorityProbe['expires_at'] = date('Y-m-d H:i:s', time() + 900);
$authorityProbeCreated = $store->prepare($authorityProbe);
$expectAuthority503 = static function () use ($storageService, $assert): void {
    try {
        $storageService->read(1);
    } catch (ApiException $exception) {
        $assert($exception->getCode() === 503, 'authority corruption returned wrong status');
        return;
    }
    throw new RuntimeException('authority corruption was accepted');
};
$driftChecks = [
    'chk_im_upload_reservation_positive',
    'chk_im_upload_reservation_state',
    'chk_im_upload_reservation_state_facts',
];
$setReservationChecks($driftChecks, false);
$driftChecksRestored = false;
try {
    $pdo->exec(
        "UPDATE sm_im_upload_reservation SET state='unknown_state'
          WHERE id=" . (int) $authorityProbeCreated['id'],
    );
    $expectAuthority503();
    $pdo->exec(
        "UPDATE sm_im_upload_reservation SET state='reserved',version=0
          WHERE id=" . (int) $authorityProbeCreated['id'],
    );
    $expectAuthority503();
    $pdo->exec(
        "UPDATE sm_im_upload_reservation SET version=1,storage_path='legacy/not-canonical'
          WHERE id=" . (int) $authorityProbeCreated['id'],
    );
    $setReservationChecks($driftChecks, true);
    $driftChecksRestored = true;
} finally {
    if (!$driftChecksRestored) {
        $pdo->prepare(
            "UPDATE sm_im_upload_reservation
                SET state='reserved',version=1,storage_path=?
              WHERE id=?",
        )->execute([
            $authorityProbe['storage_path'],
            (int) $authorityProbeCreated['id'],
        ]);
        $setReservationChecks($driftChecks, true);
    }
}
$expectAuthority503();
$pdo->prepare(
    'UPDATE sm_im_upload_reservation SET storage_path=? WHERE id=?',
)->execute([$authorityProbe['storage_path'], (int) $authorityProbeCreated['id']]);
$releasedProbe = $store->release(
    ['organization' => 1, 'user_id' => 'owner', 'client_family' => 'web'],
    (string) $authorityProbeCreated['upload_id'],
);
$releasedProjection = $storageService->read(1);
$assert(
    $releasedProbe === ['released' => true, 'state' => 'released']
    && $releasedProjection['held_value'] === '0',
    'released reservation remained charged as held occupancy',
);

$pdo->exec(
    "UPDATE sm_im_upload_reservation SET file_id='" . str_repeat('9', 40) . "'
      WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
);
$expectAuthority503();
$pdo->exec(
    "UPDATE sm_im_upload_reservation SET file_id='" . str_repeat('d', 40) . "'
      WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
);

$updatedStorage = $storageService->update(
    1,
    '1000',
    $storageProjection['version'],
    1,
);
$staleUpdateRejected = false;
try {
    $storageService->update(1, '1000', $storageProjection['version'], 1);
} catch (ApiException $exception) {
    $staleUpdateRejected = $exception->getCode() === 409;
}
$assert($staleUpdateRejected, 'central storage quota CAS accepted a stale version');
$invalidQuotaTypeRejected = false;
try {
    $storageService->update(1, 1000, $updatedStorage['version'], 1);
} catch (ApiException $exception) {
    $invalidQuotaTypeRejected = $exception->getCode() === 422;
}
$assert($invalidQuotaTypeRejected, 'central quota_value accepted a non-string value');
$belowOccupancyRejected = false;
try {
    $storageService->update(1, '24', $updatedStorage['version'], 1);
} catch (ApiException $exception) {
    $belowOccupancyRejected = $exception->getCode() === 422;
}
$assert($belowOccupancyRejected, 'central storage quota dropped below occupancy');

$fileMedia = new FileMediaService();
$policyProjection = $fileMedia->policyRead(1);
$assert(
    array_keys($policyProjection) === [
        'max_file_bytes', 'preview_enabled', 'large_file_enabled', 'status',
    ],
    'file-media policy read leaked central storage or organization fields',
);
$policyList = $fileMedia->policyList(['organization' => '1', 'status' => '1']);
$assert(
    array_keys($policyList['data'][0] ?? []) === [
        'organization', 'max_file_bytes', 'preview_enabled',
        'large_file_enabled', 'status',
    ],
    'file-media policy list lost its resource organization or leaked quota fields',
);
$usageProjection = $fileMedia->usage(1);
$assert(
    array_keys($usageProjection) === ['storage', 'policy']
    && array_keys($usageProjection['policy']) === array_keys($policyProjection)
    && $usageProjection['storage']['used_value'] === '25',
    'file-media usage is not the locked nested storage/policy contract',
);
$validPolicyInput = $policyProjection;

foreach ([true, 'true', '0', 2] as $invalidFlag) {
    $rejected = false;
    try {
        $invalidPolicyInput = $validPolicyInput;
        $invalidPolicyInput['status'] = $invalidFlag;
        $fileMedia->policyUpdate(1, $invalidPolicyInput, 1);
    } catch (ApiException $exception) {
        $rejected = $exception->getCode() === 422;
    }
    $assert($rejected, 'file-media policy accepted a non-JSON-integer flag');
}
$invalidPolicyFilter = false;
try {
    $fileMedia->policyList(['status' => '2']);
} catch (ApiException $exception) {
    $invalidPolicyFilter = $exception->getCode() === 422;
}
$assert($invalidPolicyFilter, 'file-media policy list coerced an invalid status filter');
$coreFieldRejected = false;
try {
    $fileMedia->policyUpdate(1, $validPolicyInput + ['max_storage_bytes' => '1000'], 1);
} catch (ApiException $exception) {
    $coreFieldRejected = $exception->getCode() === 422;
}
$assert($coreFieldRejected, 'module policy endpoint accepted a central capacity field');

$factoryAccess = new ReflectionProperty(ModuleServiceFactory::class, 'access');
$factoryAccess->setAccessible(true);
try {
    $factoryAccess->setValue(null, $accessFor('available'));
    $allowed = $fileMedia->checkUpload(1, 10);
    $assert(
        array_keys($allowed) === [
            'allowed', 'reason', 'size_bytes', 'storage', 'policy',
        ]
        && $allowed['allowed'] === true
        && array_keys($allowed['policy']) === array_keys($policyProjection),
        'checkUpload allowed response drifted from the nested contract',
    );
    $disabledLargePolicy = $validPolicyInput;
    $disabledLargePolicy['large_file_enabled'] = 0;
    $fileMedia->policyUpdate(1, $disabledLargePolicy, 1);
    $policyDenied = $fileMedia->checkUpload(1, 104857601);
    $assert(
        $policyDenied['allowed'] === false
        && $policyDenied['reason'] !== ''
        && isset($policyDenied['storage'], $policyDenied['policy']),
        'business policy denial did not return allowed=false contract',
    );
    $fileMedia->policyUpdate(1, $validPolicyInput, 1);
    $capacity = $storageService->update(1, '30', $updatedStorage['version'], 1);
    $capacityDenied = $fileMedia->checkUpload(1, 10);
    $assert(
        $capacityDenied['allowed'] === false
        && $capacityDenied['reason'] === '存储配额不足。',
        'capacity denial did not return allowed=false contract',
    );
    $updatedStorage = $storageService->update(1, '1000', $capacity['version'], 1);
    $factoryAccess->setValue(null, $accessFor('unavailable'));
    $moduleUnavailable = false;
    try {
        $fileMedia->checkUpload(1, 10);
    } catch (ApiException $exception) {
        $moduleUnavailable = $exception->getCode() === 503;
    }
    $assert($moduleUnavailable, 'module authority outage did not remain HTTP 503');
    $invalidBytes = false;
    try {
        $fileMedia->checkUpload(1, 2147483649);
    } catch (ApiException $exception) {
        $invalidBytes = $exception->getCode() === 422;
    }
    $assert($invalidBytes, 'checkUpload accepted bytes above the 2GiB business maximum');
} finally {
    $factoryAccess->setValue(null, null);
}

$ownedFolder = $fileMedia->folderCreate(
    1,
    ['name' => 'owner folder', 'owner_user_id' => 'spoofed'],
    1,
    'owner',
);
$assert(
    $ownedFolder['owner_user_id'] === 'owner'
    && $fileMedia->folderList(1, [], false, 'other')['total'] === 0,
    'web folder owner scope was spoofable or leaked in same organization',
);
$otherFolderMutation = false;
try {
    $fileMedia->folderUpdate(1, (int) $ownedFolder['id'], ['name' => 'stolen'], 2, 'other');
} catch (ApiException $exception) {
    $otherFolderMutation = $exception->getCode() === 404;
}
$assert($otherFolderMutation, 'same-organization user mutated another owner folder');
$ownedItem = $fileMedia->itemCreate(
    1,
    ['folder_id' => $ownedFolder['id'], 'file_id' => $first['file_id']],
    1,
    'owner',
);
$assert(
    $fileMedia->itemList(1, [], false, 'other')['total'] === 0
    && $fileMedia->itemDelete(1, [$ownedItem['id']], 2, 'other') === 0
    && $fileMedia->itemList(1, [], false, 'owner')['total'] === 1,
    'same-organization file owner scope leaked or deleted another user item',
);
$otherItemMutation = false;
try {
    $fileMedia->itemUpdate(1, (int) $ownedItem['id'], ['name' => 'stolen.pdf'], 2, 'other');
} catch (ApiException $exception) {
    $otherItemMutation = $exception->getCode() === 404;
}
$assert($otherItemMutation, 'same-organization user updated another owner item');

$pdo->exec(
    "UPDATE im_upload_asset SET status=0,delete_time=NOW()
      WHERE organization=1 AND storage_path=" . $pdo->quote($storagePath),
);
$softDeletedUsage = $storageService->read(1);
$assert(
    $softDeletedUsage['used_value'] === '25'
    && $softDeletedUsage['used_file_count'] === 1,
    'soft-deleted/status-off physical facts escaped storage accounting',
);
$pdo->exec(
    "UPDATE im_upload_asset SET status=1,delete_time=NULL
      WHERE organization=1 AND storage_path=" . $pdo->quote($storagePath),
);

$intersectionRejected = false;
$stateFactsCheck = ['chk_im_upload_reservation_state_facts'];
$setReservationChecks($stateFactsCheck, false);
try {
    $pdo->exec(
        "UPDATE sm_im_upload_reservation SET state='reserved',expires_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE)
          WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
    );
    $storageService->read(1);
} catch (ApiException $exception) {
    $intersectionRejected = $exception->getCode() === 503;
} finally {
    $pdo->exec(
        "UPDATE sm_im_upload_reservation SET state='confirmed',
          expires_at='9999-12-31 23:59:59.999999'
          WHERE organization=1 AND upload_id=" . $pdo->quote($uploadId),
    );
    $setReservationChecks($stateFactsCheck, true);
}
$assert($intersectionRejected, 'physical/held storage_path intersection did not fail closed');

$pdo->exec(
    "UPDATE sm_tenant_quota SET used_value=24 WHERE organization=1 AND quota_key='storage_bytes'",
);
$usedDriftRejected = false;
try {
    $storageService->read(1);
} catch (ApiException $exception) {
    $usedDriftRejected = $exception->getCode() === 503;
}
$assert($usedDriftRejected, 'central used_value drift did not fail closed');
$pdo->exec(
    "UPDATE sm_tenant_quota SET used_value=25 WHERE organization=1 AND quota_key='storage_bytes'",
);
$pdo->exec(
    "UPDATE sm_tenant_quota SET quota_value=24 WHERE organization=1 AND quota_key='storage_bytes'",
);
$capacityDriftRejected = false;
try {
    $storageService->read(1);
} catch (ApiException $exception) {
    $capacityDriftRejected = $exception->getCode() === 503;
}
$assert($capacityDriftRejected, 'central capacity below occupancy did not fail closed');
$pdo->exec(
    "UPDATE sm_tenant_quota SET quota_value=1000 WHERE organization=1 AND quota_key='storage_bytes'",
);

$centralRow = $pdo->query(
    "SELECT * FROM sm_tenant_quota WHERE organization=1 AND quota_key='storage_bytes'",
)->fetch(PDO::FETCH_ASSOC);
$pdo->exec("DELETE FROM sm_tenant_quota WHERE organization=1 AND quota_key='storage_bytes'");
$missingCentralRejected = false;
try {
    $storageService->index([]);
} catch (ApiException $exception) {
    $missingCentralRejected = $exception->getCode() === 503;
}
$assert($missingCentralRejected, 'active organization missing storage authority was hidden from index');
$columns = array_keys($centralRow);
$restoreCentral = $pdo->prepare(sprintf(
    'INSERT INTO sm_tenant_quota (`%s`) VALUES (%s)',
    implode('`,`', $columns),
    implode(',', array_fill(0, count($columns), '?')),
));
$restoreCentral->execute(array_values($centralRow));
$pdo->exec(
    "INSERT INTO sm_tenant_quota
      (organization,quota_key,quota_value,used_value,source,status,version,create_time,update_time)
     VALUES (999999,'storage_bytes',1000,0,'manual','active',1,NOW(),NOW())",
);
$orphanRejected = false;
try {
    $storageService->index([]);
} catch (ApiException $exception) {
    $orphanRejected = $exception->getCode() === 503;
}
$assert($orphanRejected, 'orphan central storage authority did not fail closed');
$pdo->exec("DELETE FROM sm_tenant_quota WHERE organization=999999 AND quota_key='storage_bytes'");

$freshOrganization = 999998;
$pdo->exec("DELETE FROM im_upload_asset WHERE organization={$freshOrganization}");
$pdo->exec("DELETE FROM sm_im_upload_reservation WHERE organization={$freshOrganization}");
$pdo->exec("DELETE FROM sm_file_media_quota WHERE organization={$freshOrganization}");
$pdo->exec(
    "DELETE FROM sm_tenant_quota
      WHERE organization={$freshOrganization} AND quota_key='storage_bytes'",
);
$pdo->exec("DELETE FROM sm_system_organization WHERE id={$freshOrganization}");
$organizationTwo = $pdo->query('SELECT * FROM sm_system_organization WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$organizationTwo['id'] = $freshOrganization;
$organizationTwo['domain'] = null;
$organizationTwo['enterprise_code'] = 'QA_STORAGE_ORG_999998';
$organizationTwo['deployment_id'] = 'qa-storage-org-999998';
$organizationColumns = array_keys($organizationTwo);
$insertOrganization = $pdo->prepare(sprintf(
    'INSERT INTO sm_system_organization (`%s`) VALUES (%s)',
    implode('`,`', $organizationColumns),
    implode(',', array_fill(0, count($organizationColumns), '?')),
));
$insertOrganization->execute(array_values($organizationTwo));
$freshPolicyCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM sm_file_media_quota WHERE organization={$freshOrganization}",
)->fetchColumn();
$freshPolicyList = $fileMedia->policyList(['organization' => (string) $freshOrganization]);
$freshPolicyUpdated = $fileMedia->policyUpdate(
    $freshOrganization,
    [
        'max_file_bytes' => '123456789',
        'preview_enabled' => 0,
        'large_file_enabled' => 1,
        'status' => 1,
    ],
    1,
);
$assert(
    $freshPolicyCount === 0
    && $freshPolicyList['total'] === 1
    && ($freshPolicyList['data'][0]['organization'] ?? null) === $freshOrganization
    && $freshPolicyUpdated['max_file_bytes'] === '123456789'
    && $freshPolicyUpdated['preview_enabled'] === 0,
    'fresh active organization was absent or not configurable in policyIndex',
);
$pdo->exec(sprintf(
    "INSERT INTO sm_tenant_quota
      (organization,quota_key,quota_value,used_value,source,status,version,create_time,update_time)
     VALUES (%d,'storage_bytes',1000,25,'manual','active',1,NOW(),NOW())",
    $freshOrganization,
));
$crossOrgAsset = $pdo->prepare(
    'INSERT INTO im_upload_asset
      (organization,file_id,user_id,kind,name,url,storage_path,size_byte,mime_type,extension,status,create_time,update_time)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
);
foreach ([str_repeat('1', 40), str_repeat('2', 40)] as $crossFileId) {
    $crossOrgAsset->execute([
        $freshOrganization, $crossFileId, 'target-owner', 'file', 'cross.pdf', '', $storagePath, 25,
        'application/pdf', 'pdf', 1, $now, $now,
    ]);
}
$crossUsage = $storageService->read($freshOrganization);
$assert(
    $crossUsage['used_value'] === '25' && $crossUsage['used_file_count'] === 1,
    'cross-organization physical alias was rejected or charged twice in target organization',
);
$pdo->exec("DELETE FROM im_upload_asset WHERE organization={$freshOrganization}");
$pdo->exec("DELETE FROM sm_file_media_quota WHERE organization={$freshOrganization}");
$pdo->exec(
    "DELETE FROM sm_tenant_quota
      WHERE organization={$freshOrganization} AND quota_key='storage_bytes'",
);
$pdo->exec("DELETE FROM sm_system_organization WHERE id={$freshOrganization}");

$fabricatedRejected = false;
try {
    (new FileMediaService())->itemCreate(1, ['file_id' => str_repeat('0', 40)], 1);
} catch (ApiException $exception) {
    $fabricatedRejected = $exception->getCode() === 422;
}
$assert($fabricatedRejected, 'fabricated file id was accepted');

echo sprintf("StorageQuotaUploadIntegrationTest: %d assertions passed\n", $assertions);
