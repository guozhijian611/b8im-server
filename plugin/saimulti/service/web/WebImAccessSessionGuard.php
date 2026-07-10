<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\exception\ApiException;
use Throwable;

final class WebImAccessSessionGuard
{
    private readonly WebImAccessSessionStoreInterface $store;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(?WebImAccessSessionStoreInterface $store = null, ?Closure $clock = null)
    {
        $this->store = $store ?? new ThinkOrmWebImAccessSessionStore();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @param array<string, mixed> $claims */
    public function assertActive(array $claims, int $organization): void
    {
        $jti = $claims['jti'] ?? null;
        $imUserId = $claims['id'] ?? null;
        $userId = $claims['user_id'] ?? null;
        $deviceId = $claims['device_id'] ?? null;
        $tokenExpireAt = $claims['exp'] ?? null;
        if (
            $organization <= 0
            || !is_string($jti)
            || preg_match('/^[a-f0-9]{32}$/', $jti) !== 1
            || !is_int($imUserId)
            || $imUserId <= 0
            || !is_string($userId)
            || trim($userId) === ''
            || !is_string($deviceId)
            || trim($deviceId) === ''
            || !is_int($tokenExpireAt)
        ) {
            throw new ApiException('Web 登录会话无效。', 401);
        }

        try {
            $row = $this->store->findByJti($organization, $jti);
            $rowExpireAt = is_array($row) ? (strtotime((string) ($row['expire_at'] ?? '')) ?: 0) : 0;
            $now = ($this->clock)();
            $active = is_array($row)
                && (int) ($row['organization'] ?? 0) === $organization
                && hash_equals($jti, (string) ($row['jti'] ?? ''))
                && (int) ($row['im_user_id'] ?? 0) === $imUserId
                && hash_equals($userId, (string) ($row['user_id'] ?? ''))
                && hash_equals($deviceId, (string) ($row['device_id'] ?? ''))
                && (int) ($row['status'] ?? 0) === 1
                && ($row['revoked_at'] ?? null) === null
                && $rowExpireAt > $now
                && $tokenExpireAt <= $rowExpireAt;
        } catch (Throwable) {
            $active = false;
        }
        if (!$active) {
            throw new ApiException('Web 登录会话已撤销或过期。', 401);
        }
    }
}
