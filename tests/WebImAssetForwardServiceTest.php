<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\WebImAssetForwardService;
use plugin\saimulti\service\web\WebImAssetForwardStoreInterface;

final class RecordingWebImAssetForwardStore implements WebImAssetForwardStoreInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function accessibleAsset(
        int $organization,
        string $userId,
        string $fileId,
        string $conversationId,
        string $messageId,
    ): array {
        throw new RuntimeException('Not used by the forward-service test.');
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
        $this->calls[] = get_defined_vars();

        return [
            'file_id' => $derivedFileId,
            'kind' => $kind,
            'name' => 'photo.webp',
            'url' => 'https://cdn.example.test/photo.webp',
            'size' => 1234,
            'mime_type' => 'image/webp',
            'extension' => 'webp',
        ];
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

$secret = str_repeat('attachment-forward-secret-', 2);
$store = new RecordingWebImAssetForwardStore();
$service = new WebImAssetForwardService(
    $store,
    $secret,
    static fn (): int => strtotime('2026-07-10 13:00:00'),
);
$identity = ['organization' => 901, 'user_id' => 'user_c'];
$sourceFileId = str_repeat('a', 40);
$first = $service->derive($identity, 'group_conversation_1', 'message-1', $sourceFileId, 'IMAGE');
$second = $service->derive($identity, 'group_conversation_1', 'message-1', $sourceFileId, 'image');
$purposeKey = hash_hmac(
    'sha256',
    'b8im:web-im:attachment-forward:file-id:v1',
    $secret,
    true,
);
$expectedId = substr(hash_hmac(
    'sha256',
    implode("\0", ['901', 'user_c', 'group_conversation_1', 'message-1', $sourceFileId, 'image']),
    $purposeKey,
), 0, 40);
$assert($first['file_id'] === $expectedId, 'Derived file_id does not use the exact purpose-separated HMAC contract.');
$assert($second['file_id'] === $expectedId, 'Repeated derivation is not idempotent.');
$assert(preg_match('/^[a-f0-9]{40}$/', $expectedId) === 1, 'Derived file_id is not a 40-hex identifier.');
$assert(!array_key_exists('url', $first), 'Derived asset response leaked a raw storage URL.');
$assert(count($store->calls) === 2, 'Valid derivations were not delegated to the store.');
$assert(
    $store->calls[0]['organization'] === 901
    && $store->calls[0]['userId'] === 'user_c'
    && $store->calls[0]['conversationId'] === 'group_conversation_1'
    && $store->calls[0]['messageId'] === 'message-1'
    && $store->calls[0]['sourceFileId'] === $sourceFileId
    && $store->calls[0]['kind'] === 'image'
    && $store->calls[0]['now'] === '2026-07-10 13:00:00',
    'Store delegation did not preserve the authenticated source-message context.',
);

$differentUser = $service->derive(
    ['organization' => 901, 'user_id' => 'user_d'],
    'group_conversation_1',
    'message-1',
    $sourceFileId,
    'image',
);
$assert($differentUser['file_id'] !== $expectedId, 'Derived file_id was not bound to the authenticated user.');
$differentMessage = $service->derive(
    $identity,
    'group_conversation_1',
    'message-2',
    $sourceFileId,
    'image',
);
$assert($differentMessage['file_id'] !== $expectedId, 'Derived file_id was not bound to the source message.');

$callCount = count($store->calls);
$expectApiCode(401, static fn () => $service->derive([], 'conversation', 'message', $sourceFileId, 'image'));
$expectApiCode(401, static fn () => $service->derive(
    ['organization' => 901, 'user_id' => '../user'],
    'conversation',
    'message',
    $sourceFileId,
    'image',
));
$expectApiCode(422, static fn () => $service->derive($identity, '../conversation', 'message', $sourceFileId, 'image'));
$expectApiCode(422, static fn () => $service->derive($identity, 'conversation', '', $sourceFileId, 'image'));
$expectApiCode(422, static fn () => $service->derive($identity, 'conversation', 'message', 'A' . substr($sourceFileId, 1), 'image'));
$expectApiCode(422, static fn () => $service->derive($identity, 'conversation', 'message', $sourceFileId, 'avatar'));
$assert(count($store->calls) === $callCount, 'Invalid derivation input reached the persistence boundary.');

try {
    new WebImAssetForwardService($store, 'weak-secret');
    throw new RuntimeException('Weak attachment-forward secret was accepted.');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), '32 bytes'), 'Weak-secret error mismatch.');
}

echo sprintf("WebImAssetForwardServiceTest: %d assertions passed\n", $assertions);
