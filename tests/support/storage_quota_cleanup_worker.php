<?php

declare(strict_types=1);

$database = (string) ($argv[1] ?? '');
$reservationId = (int) ($argv[2] ?? 0);
if (!str_ends_with($database, '_storage_quota_test') || $reservationId <= 0) {
    fwrite(STDERR, 'unsafe cleanup worker arguments');
    exit(2);
}
putenv('DB_NAME=' . $database);
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/support/bootstrap.php';

use plugin\saimulti\service\web\ThinkOrmWebImUploadReservationService;
use plugin\saimulti\service\web\WebImUploadPolicyInterface;
use plugin\saimulti\service\web\WebImUploadStorageInterface;
use support\think\Db;

final class CleanupLogStorage implements WebImUploadStorageInterface
{
    public function __construct(private readonly int $reservationId) {}
    public function assertReady(): void {}
    public function reservePath(int $organization, string $extension, string $objectId): string
    {
        throw new LogicException('not used');
    }
    public function uploadExact(
        int $organization,
        SplFileInfo $file,
        string $storagePath,
        string $mimeType,
        ?callable $heartbeat = null,
    ): void {
        throw new LogicException('not used');
    }
    public function inspect(int $organization, string $storagePath): array
    {
        throw new LogicException('not used');
    }
    public function delete(int $organization, string $storagePath): void
    {
        Db::table('storage_quota_cleanup_log')->insert([
            'reservation_id' => $this->reservationId,
            'storage_path' => $storagePath,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }
}

$config = config('think-orm');
$connection = (string) ($config['default'] ?? 'mysql');
$config['connections'][$connection]['database'] = $database;
Db::setConfig($config);

$policy = new class implements WebImUploadPolicyInterface {
    public function assertAllowed(int $organization, int $sizeBytes): void {}
};
$store = new ThinkOrmWebImUploadReservationService($policy);
$claim = new ReflectionMethod($store, 'claimCleanupCandidate');
$row = $claim->invoke($store, $reservationId);
if (!is_array($row)) {
    echo 'skipped';
    exit(0);
}

if (!$store->authorizeCleanupDelete(
    $reservationId,
    (string) $row['cleanup_lease_token'],
    (int) $row['cleanup_claimed_version'],
    (int) $row['organization'],
    (string) $row['storage_path'],
)) {
    echo 'skipped';
    exit(0);
}

(new CleanupLogStorage($reservationId))->delete(
    (int) $row['organization'],
    (string) $row['storage_path'],
);
$store->cleanupSucceeded(
    $reservationId,
    (string) $row['cleanup_lease_token'],
    (int) $row['cleanup_claimed_version'],
);
echo 'deleted';
