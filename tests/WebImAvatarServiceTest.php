<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\WebImAssetUrlSignerInterface;
use plugin\saimulti\service\web\WebImAvatarService;
use plugin\saimulti\service\web\WebImUploadAssetStoreInterface;

final class AvatarAssetStore implements WebImUploadAssetStoreInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public array $assets = [];

    public function create(array $asset): void
    {
        $this->assets[(string) $asset['file_id']] = $asset;
    }

    public function findActiveImage(int $organization, string $fileId, ?string $ownerUserId = null): ?array
    {
        $this->calls[] = get_defined_vars();
        $asset = $this->assets[$fileId] ?? null;
        if ($asset === null
            || (int) $asset['organization'] !== $organization
            || (string) $asset['kind'] !== 'image'
            || (int) $asset['status'] !== 1
            || ($ownerUserId !== null && !hash_equals((string) $asset['user_id'], $ownerUserId))) {
            return null;
        }

        return $asset;
    }
}

final class AvatarSigner implements WebImAssetUrlSignerInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function sign(int $organization, string $storagePath, int $expiresAt): string
    {
        $this->calls[] = get_defined_vars();

        return 'https://s3.example.test/avatar?X-Amz-Expires=300&X-Amz-Signature=test';
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

$fileId = str_repeat('a', 40);
$store = new AvatarAssetStore();
$store->assets[$fileId] = [
    'organization' => 901,
    'file_id' => $fileId,
    'user_id' => 'user_a',
    'kind' => 'image',
    'storage_path' => 'private/organizations/901/im/202607/' . str_repeat('b', 48) . '.webp',
    'status' => 1,
];
$signer = new AvatarSigner();
$now = 1_800_000_000;
$service = new WebImAvatarService($store, $signer, static fn (): int => $now, 300);

$assert(
    $service->assertOwnedImage(901, 'user_a', $fileId) === $fileId,
    'Owned image file_id was not accepted.',
);
$expectApiCode(422, static fn () => $service->assertOwnedImage(901, 'user_a', 'https://example.test/a.png'));
$expectApiCode(404, static fn () => $service->assertOwnedImage(901, 'user_b', $fileId));
$expectApiCode(404, static fn () => $service->assertOwnedImage(902, 'user_a', $fileId));

$view = $service->project(901, $fileId);
$assert(
    array_keys($view) === ['avatar_file_id', 'avatar_url', 'avatar_expires_at']
    && $view['avatar_file_id'] === $fileId
    && str_contains($view['avatar_url'], 'X-Amz-Signature=')
    && $view['avatar_expires_at'] === $now + 300,
    'Avatar read projection contract mismatch.',
);
$assert(
    $signer->calls === [[
        'organization' => 901,
        'storagePath' => $store->assets[$fileId]['storage_path'],
        'expiresAt' => $now + 300,
    ]],
    'Avatar signer did not receive the canonical organization/path/TTL.',
);
$expectApiCode(409, static fn () => $service->project(901, 'https://example.test/a.png'));
$expectApiCode(409, static fn () => $service->project(902, $fileId));

echo sprintf("WebImAvatarServiceTest: %d assertions passed\n", $assertions);
