<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\exception\ApiException;

final class WebImAvatarService implements WebImAvatarServiceInterface
{
    private WebImUploadAssetStoreInterface $store;

    private WebImAssetUrlSignerInterface $signer;

    private Closure $clock;

    private int $ttlSeconds;

    public function __construct(
        ?WebImUploadAssetStoreInterface $store = null,
        ?WebImAssetUrlSignerInterface $signer = null,
        ?Closure $clock = null,
        ?int $ttlSeconds = null,
    ) {
        $this->store = $store ?? new ThinkOrmWebImUploadAssetStore();
        $this->signer = $signer ?? new S3WebImAssetUrlSigner();
        $this->clock = $clock ?? static fn (): int => time();
        $ttlSeconds ??= (int) env('WEB_IM_AVATAR_URL_TTL_SECONDS', 300);
        $this->ttlSeconds = min(max($ttlSeconds, 60), 900);
    }

    public function assertOwnedImage(int $organization, string $ownerUserId, string $fileId): string
    {
        $fileId = $this->fileId($fileId, 422);
        if ($organization <= 0 || trim($ownerUserId) === '') {
            throw new ApiException('Web 登录上下文无效。', 401);
        }
        if ($this->store->findActiveImage($organization, $fileId, $ownerUserId) === null) {
            throw new ApiException('头像附件不存在或不属于当前用户。', 404);
        }

        return $fileId;
    }

    public function project(int $organization, string $fileId): array
    {
        if ($organization <= 0) {
            throw new ApiException('Web 登录上下文无效。', 401);
        }
        $fileId = $this->fileId($fileId, 409);
        $asset = $this->store->findActiveImage($organization, $fileId);
        if ($asset === null) {
            throw new ApiException('头像附件不存在或已停用。', 409);
        }
        $now = ($this->clock)();
        if (!is_int($now) || $now <= 0) {
            throw new \RuntimeException('Web IM avatar clock returned an invalid timestamp.');
        }
        $expiresAt = $now + $this->ttlSeconds;

        return [
            'avatar_file_id' => $fileId,
            'avatar_url' => $this->signer->sign(
                $organization,
                (string) ($asset['storage_path'] ?? ''),
                $expiresAt,
            ),
            'avatar_expires_at' => $expiresAt,
        ];
    }

    private function fileId(string $fileId, int $code): string
    {
        $fileId = trim($fileId);
        if (preg_match('/^[a-f0-9]{40}$/', $fileId) !== 1) {
            throw new ApiException('avatar_file_id 格式无效。', $code);
        }

        return $fileId;
    }
}
