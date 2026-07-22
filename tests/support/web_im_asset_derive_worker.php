<?php

declare(strict_types=1);

$database = (string) ($argv[1] ?? '');
$organization = (int) ($argv[2] ?? 0);
$userId = (string) ($argv[3] ?? '');
$conversationId = (string) ($argv[4] ?? '');
$messageId = (string) ($argv[5] ?? '');
$sourceFileId = (string) ($argv[6] ?? '');
$readyPath = (string) ($argv[7] ?? '');
$startPath = (string) ($argv[8] ?? '');
$resultPath = (string) ($argv[9] ?? '');
if (preg_match('/^[A-Za-z0-9_]+_web_test$/', $database) !== 1
    || $organization <= 0 || $userId === '' || $conversationId === ''
    || $messageId === '' || preg_match('/^[a-f0-9]{40}$/', $sourceFileId) !== 1
    || $readyPath === '' || $startPath === '' || $resultPath === '') {
    throw new RuntimeException('invalid derive worker arguments');
}
foreach (['DB_NAME' => $database, 'IM_MESSAGE_SHARD_BUCKETS' => '1'] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/support/bootstrap.php';

try {
    file_put_contents($readyPath, 'ready', LOCK_EX);
    $deadline = microtime(true) + 10;
    while (!is_file($startPath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('derive start barrier timeout');
        }
        usleep(1000);
    }
    $service = new \plugin\saimulti\service\web\WebImAssetForwardService(
        new \plugin\saimulti\service\web\ThinkOrmWebImAssetForwardStore(),
        str_repeat('integration-forward-secret-', 2),
        static fn (): int => strtotime('2026-07-10 13:00:00'),
    );
    $asset = $service->derive(
        [
            'organization' => $organization,
            'user_id' => $userId,
            'client_family' => 'web',
        ],
        $conversationId,
        $messageId,
        $sourceFileId,
        'image',
    );
    file_put_contents($resultPath, json_encode([
        'status' => 'derived',
        'asset' => $asset,
    ], JSON_THROW_ON_ERROR), LOCK_EX);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'error' => $exception::class . ': ' . $exception->getMessage(),
        'code' => $exception->getCode(),
    ], JSON_THROW_ON_ERROR), LOCK_EX);
    exit(1);
}
