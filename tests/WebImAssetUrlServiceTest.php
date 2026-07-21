<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\S3WebImAssetUrlSigner;
use plugin\saimulti\service\web\WebImAssetForwardStoreInterface;
use plugin\saimulti\service\web\WebImAssetUrlService;
use plugin\saimulti\service\web\WebImAssetUrlSignerInterface;

final class RecordingAssetAccessStore implements WebImAssetForwardStoreInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public string $storagePath = 'private/organizations/901/im/202607/' . 'a1b2c3d4e5f678901234567890123456' . '.webp';

    public function accessibleAsset(
        int $organization,
        string $userId,
        string $fileId,
        string $conversationId,
        string $messageId,
    ): array {
        $this->calls[] = get_defined_vars();

        return [
            'file_id' => $fileId,
            'user_id' => $userId,
            'kind' => 'image',
            'name' => 'photo.webp',
            'url' => 'https://cdn.example.test/private/photo.webp',
            'storage_path' => $this->storagePath,
            'size_byte' => 1234,
            'mime_type' => 'image/webp',
            'extension' => 'webp',
        ];
    }

    public function deriveVisibleAsset(
        int $organization,
        string $userId,
        string $conversationId,
        string $messageId,
        string $sourceFileId,
        string $kind,
        string $derivedFileId,
        string $now,
    ): array {
        throw new RuntimeException('Not used by the asset-URL service test.');
    }
}

final class RecordingAssetSigner implements WebImAssetUrlSignerInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function sign(int $organization, string $storagePath, int $expiresAt): string
    {
        $this->calls[] = get_defined_vars();

        return 'https://s3.example.test/private-object?X-Amz-Expires=300&X-Amz-Signature=test';
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$expectApiCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, 'ApiException code mismatch.');
        return;
    }
    throw new RuntimeException('Expected ApiException was not thrown.');
};

$store = new RecordingAssetAccessStore();
$signer = new RecordingAssetSigner();
$now = strtotime('2026-07-10 13:00:00');
$service = new WebImAssetUrlService($store, $signer, static fn (): int => $now, 300);
$fileId = str_repeat('a', 40);
$identity = ['organization' => 901, 'user_id' => 'user_c'];

$owned = $service->resolve($identity, $fileId);
$assert(
    $owned === [
        'file_id' => $fileId,
        'url' => 'https://s3.example.test/private-object?X-Amz-Expires=300&X-Amz-Signature=test',
        'expires_at' => $now + 300,
    ],
    'Owner asset URL response contract mismatch.',
);
$visible = $service->resolve($identity, $fileId, 'conversation-1', 'message-1');
$assert($visible['expires_at'] === $now + 300, 'Visible-message asset TTL mismatch.');
$assert(
    $store->calls[0]['conversationId'] === ''
    && $store->calls[0]['messageId'] === ''
    && $store->calls[1]['conversationId'] === 'conversation-1'
    && $store->calls[1]['messageId'] === 'message-1',
    'Owner and visible-message contexts were not delegated exactly.',
);
$assert(
    $signer->calls[0]['organization'] === 901
    && $signer->calls[0]['storagePath'] === $store->storagePath
    && $signer->calls[0]['expiresAt'] === $now + 300,
    'Canonical storage path or expiry was not delegated to the signer.',
);

$callCount = count($store->calls);
$expectApiCode(401, static fn () => $service->resolve([], $fileId));
$expectApiCode(422, static fn () => $service->resolve($identity, strtoupper($fileId)));
$expectApiCode(422, static fn () => $service->resolve($identity, $fileId, 'conversation-1', ''));
$expectApiCode(422, static fn () => $service->resolve($identity, $fileId, '../conversation', 'message-1'));
$assert(count($store->calls) === $callCount, 'Invalid URL resolution input reached the access store.');

$s3Config = [
    'upload_mode' => '5',
    's3_acl' => 'private',
    's3_key' => 'TESTACCESSKEY',
    's3_secret' => 'test-secret-that-is-never-returned',
    's3_bucket' => 'private-bucket',
    's3_dirname' => 'private',
    's3_region' => 'us-east-1',
    's3_version' => 'latest',
    's3_use_path_style_endpoint' => '1',
    's3_endpoint' => 'https://s3.example.test',
];
$realSigner = new S3WebImAssetUrlSigner(static fn (): array => $s3Config);
$signed = $realSigner->sign(901, $store->storagePath, time() + 300);
$signedParts = parse_url($signed);
parse_str((string) ($signedParts['query'] ?? ''), $signedQuery);
$assert(($signedParts['scheme'] ?? '') === 'https' && ($signedParts['host'] ?? '') === 's3.example.test', 'Signer changed the configured HTTPS endpoint.');
$assert(str_contains((string) ($signedParts['path'] ?? ''), '/private-bucket/private/organizations/901/im/202607/'), 'Signer did not use the canonical object key exactly once.');
$signedTtl = (int) ($signedQuery['X-Amz-Expires'] ?? 0);
$assert(
    isset($signedQuery['X-Amz-Signature']) && $signedTtl >= 299 && $signedTtl <= 300,
    'Signer did not create an approximately 300-second SigV4 URL.',
);
$assert(!str_contains($signed, $s3Config['s3_secret']), 'S3 secret leaked into the signed URL.');

$crossOrganizationSigned = $realSigner->sign(
    901,
    'private/organizations/902/im/202607/' . str_repeat('b', 32) . '.webp',
    time() + 300,
);
$assert(
    str_contains(
        (string) (parse_url($crossOrganizationSigned, PHP_URL_PATH) ?: ''),
        '/private-bucket/private/organizations/902/im/202607/',
    ),
    'Signer rejected or rewrote an authorized cross-organization canonical source path.',
);
$expectApiCode(409, static fn () => $realSigner->sign(
    901,
    'private/organizations/901/im/../902/' . str_repeat('b', 32) . '.webp',
    time() + 300,
));
$publicConfig = [...$s3Config, 's3_acl' => 'public-read'];
$publicSigner = new S3WebImAssetUrlSigner(static fn (): array => $publicConfig);
$expectApiCode(503, static fn () => $publicSigner->sign(901, $store->storagePath, time() + 300));

echo sprintf("WebImAssetUrlServiceTest: %d assertions passed\n", $assertions);
