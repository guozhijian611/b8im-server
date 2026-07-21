<?php

declare(strict_types=1);

$database = (string) ($argv[1] ?? '');
$index = (int) ($argv[2] ?? -1);
if (!str_ends_with($database, '_storage_quota_test') || $index < 0) {
    exit(2);
}
putenv('DB_NAME=' . $database);
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/support/bootstrap.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\ThinkOrmWebImUploadReservationService;
use plugin\saimulti\service\web\WebImUploadPolicyInterface;
use support\think\Db;

$config = config('think-orm');
$name = (string) ($config['default'] ?? 'mysql');
$config['connections'][$name]['database'] = $database;
Db::setConfig($config);
$policy = new class implements WebImUploadPolicyInterface {
    public function assertAllowed(int $organization, int $sizeBytes): void {}
};
$service = new ThinkOrmWebImUploadReservationService($policy);
$now = date('Y-m-d H:i:s');
$filename = 'file-' . $index . '.pdf';
$intentHash = hash('sha256', implode("\0", [
    '1', 'concurrent', 'web', 'file', $filename, '10', 'application/pdf', 'pdf',
]));
try {
    $service->prepare([
        'organization' => 1,
        'upload_id' => hash('sha256', 'upload-' . $index),
        'idempotency_key' => substr(hash('sha256', 'idem-' . $index), 0, 32),
        'intent_hash' => $intentHash,
        'file_id' => sha1('file-' . $index),
        'storage_path' => 'private/organizations/1/im/202607/' . substr(hash('sha256', 'path-' . $index), 0, 48) . '.pdf',
        'user_id' => 'concurrent',
        'client_family' => 'web',
        'kind' => 'file',
        'filename' => $filename,
        'size_bytes' => 10,
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'state' => 'reserved',
        'expires_at' => date('Y-m-d H:i:s', time() + 900),
        'create_time' => $now,
        'update_time' => $now,
    ]);
    echo 'reserved';
} catch (ApiException $exception) {
    echo 'rejected:' . $exception->getCode();
}
