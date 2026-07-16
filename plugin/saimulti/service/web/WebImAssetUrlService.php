<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\exception\ApiException;

final class WebImAssetUrlService
{
    private WebImAssetForwardStoreInterface $store;

    private WebImAssetUrlSignerInterface $signer;

    private Closure $clock;

    private int $ttlSeconds;

    public function __construct(
        ?WebImAssetForwardStoreInterface $store = null,
        ?WebImAssetUrlSignerInterface $signer = null,
        ?Closure $clock = null,
        ?int $ttlSeconds = null,
    ) {
        $this->store = $store ?? new ThinkOrmWebImAssetForwardStore();
        $this->signer = $signer ?? new S3WebImAssetUrlSigner();
        $this->clock = $clock ?? static fn (): int => time();
        $ttlSeconds ??= (int) env('WEB_IM_ASSET_URL_TTL_SECONDS', 300);
        $this->ttlSeconds = min(max($ttlSeconds, 60), 900);
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{file_id: string, url: string, expires_at: int}
     */
    public function resolve(
        array $identity,
        string $fileId,
        string $conversationId = '',
        string $messageId = '',
    ): array {
        $organization = (int) ($identity['organization'] ?? 0);
        if ($organization <= 0) {
            throw new ApiException('客户端登录上下文无效。', 401);
        }
        $userId = $this->identifier((string) ($identity['user_id'] ?? ''), 'user_id', 64, 401);
        $fileId = trim($fileId);
        if (preg_match('/^[a-f0-9]{40}$/', $fileId) !== 1) {
            throw new ApiException('file_id 格式无效。', 422);
        }
        $conversationId = $this->optionalIdentifier($conversationId, 'conversation_id', 64);
        $messageId = $this->optionalIdentifier($messageId, 'message_id', 64);
        if (($conversationId === '') !== ($messageId === '')) {
            throw new ApiException('conversation_id 与 message_id 必须同时提供。', 422);
        }

        $asset = $this->store->accessibleAsset(
            $organization,
            $userId,
            $fileId,
            $conversationId,
            $messageId,
        );
        $now = ($this->clock)();
        if (!is_int($now) || $now <= 0) {
            throw new \RuntimeException('Web IM asset URL clock returned an invalid timestamp.');
        }
        $expiresAt = $now + $this->ttlSeconds;

        return [
            'file_id' => $fileId,
            'url' => $this->signer->sign(
                $organization,
                (string) ($asset['storage_path'] ?? ''),
                $expiresAt,
            ),
            'expires_at' => $expiresAt,
        ];
    }

    private function identifier(string $value, string $name, int $maxLength, int $code = 422): string
    {
        $value = trim($value);
        if ($value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/', $value) !== 1) {
            throw new ApiException($name . ' 格式无效。', $code);
        }

        return $value;
    }

    private function optionalIdentifier(string $value, string $name, int $maxLength): string
    {
        $value = trim($value);

        return $value === '' ? '' : $this->identifier($value, $name, $maxLength);
    }
}
