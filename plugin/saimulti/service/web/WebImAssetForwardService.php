<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\exception\ApiException;

final class WebImAssetForwardService
{
    private const KINDS = ['image', 'file', 'voice', 'video'];

    private WebImAssetForwardStoreInterface $store;

    private Closure $clock;

    private string $derivationKey;

    public function __construct(
        ?WebImAssetForwardStoreInterface $store = null,
        ?string $secret = null,
        ?Closure $clock = null,
    ) {
        $this->store = $store ?? new ThinkOrmWebImAssetForwardStore();
        $this->clock = $clock ?? static fn (): int => time();

        if ($secret === null) {
            $jwt = (array) config('plugin.tinywan.jwt.app.jwt', []);
            $secret = (string) ($jwt['access_secret_key'] ?? '');
        }
        if (strlen($secret) < 32) {
            throw new \RuntimeException('Web IM attachment-forward secret must contain at least 32 bytes.');
        }
        $this->derivationKey = hash_hmac(
            'sha256',
            'b8im:web-im:attachment-forward:file-id:v1',
            $secret,
            true,
        );
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{file_id: string, kind: string, name: string, size: int, mime_type: string, extension: string}
     */
    public function derive(
        array $identity,
        string $conversationId,
        string $messageId,
        string $sourceFileId,
        string $kind,
    ): array {
        $organization = (int) ($identity['organization'] ?? 0);
        if ($organization <= 0) {
            throw new ApiException('Web 登录上下文无效。', 401);
        }
        $userId = $this->identifier((string) ($identity['user_id'] ?? ''), 'user_id', 64, 401);
        $conversationId = $this->identifier($conversationId, 'conversation_id', 64);
        $messageId = $this->identifier($messageId, 'message_id', 64);
        $sourceFileId = trim($sourceFileId);
        if (preg_match('/^[a-f0-9]{40}$/', $sourceFileId) !== 1) {
            throw new ApiException('file_id 格式无效。', 422);
        }
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::KINDS, true)) {
            throw new ApiException('附件类型无效。', 422);
        }

        $context = implode("\0", [
            (string) $organization,
            $userId,
            $conversationId,
            $messageId,
            $sourceFileId,
            $kind,
        ]);
        $derivedFileId = substr(hash_hmac('sha256', $context, $this->derivationKey), 0, 40);

        $derived = $this->store->deriveVisibleAsset(
            $organization,
            $userId,
            $conversationId,
            $messageId,
            $sourceFileId,
            $kind,
            $derivedFileId,
            $this->nowText(),
        );
        if (
            !hash_equals($derivedFileId, (string) ($derived['file_id'] ?? ''))
            || (string) ($derived['kind'] ?? '') !== $kind
            || trim((string) ($derived['name'] ?? '')) === ''
            || (int) ($derived['size'] ?? 0) <= 0
            || trim((string) ($derived['extension'] ?? '')) === ''
        ) {
            throw new \RuntimeException('Derived Web IM asset metadata is invalid.');
        }

        return [
            'file_id' => (string) ($derived['file_id'] ?? ''),
            'kind' => (string) ($derived['kind'] ?? ''),
            'name' => (string) ($derived['name'] ?? ''),
            'size' => (int) ($derived['size'] ?? 0),
            'mime_type' => (string) ($derived['mime_type'] ?? ''),
            'extension' => (string) ($derived['extension'] ?? ''),
        ];
    }

    private function identifier(string $value, string $name, int $maxLength, int $code = 422): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/', $value) !== 1
        ) {
            throw new ApiException($name . ' 格式无效。', $code);
        }

        return $value;
    }

    private function nowText(): string
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now <= 0) {
            throw new \RuntimeException('Web IM attachment-forward clock returned an invalid timestamp.');
        }

        return date('Y-m-d H:i:s', $now);
    }
}
