<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

use support\think\Cache;
use Throwable;

final class ThinkCacheAdminImSessionCache implements AdminImSessionCacheInterface
{
    private const ACTIVE_SESSION_KEY = 'im:auth:active:%d:%s';

    private const DEFAULT_STALE_SECONDS = 3;

    private readonly int $maxStaleSeconds;

    public function __construct(private readonly ?object $rawHandler = null, ?int $maxStaleSeconds = null)
    {
        $configured = $maxStaleSeconds;
        if ($configured === null) {
            $value = $_ENV['IM_AUTH_REVALIDATE_TTL_SECONDS']
                ?? $_SERVER['IM_AUTH_REVALIDATE_TTL_SECONDS']
                ?? getenv('IM_AUTH_REVALIDATE_TTL_SECONDS');
            $configured = $value === false || $value === null
                ? self::DEFAULT_STALE_SECONDS
                : (int) $value;
        }
        $this->maxStaleSeconds = max(1, min(30, $configured));
    }

    public function status(): array
    {
        try {
            $pong = $this->handler()->ping();
            $available = $pong === true || strtoupper((string) $pong) === 'PONG' || (string) $pong === '+PONG';

            return [
                'status' => $available ? 'up' : 'down',
                'max_stale_seconds' => $this->maxStaleSeconds,
            ];
        } catch (Throwable) {
            return ['status' => 'down', 'max_stale_seconds' => $this->maxStaleSeconds];
        }
    }

    public function invalidate(int $organization, string $sessionId): bool
    {
        if ($organization <= 0 || trim($sessionId) === '') {
            return false;
        }

        try {
            $this->handler()->del(sprintf(self::ACTIVE_SESSION_KEY, $organization, $sessionId));

            return true;
        } catch (Throwable) {
            // MySQL remains authoritative. The IM guard falls back to MySQL when
            // Redis is unavailable and never caches an active decision for more
            // than maxStaleSeconds.
            return false;
        }
    }

    public function maxStaleSeconds(): int
    {
        return $this->maxStaleSeconds;
    }

    private function handler(): object
    {
        if ($this->rawHandler !== null) {
            return $this->rawHandler;
        }

        $handler = Cache::store('redis')->handler();
        if ($handler instanceof \Redis) {
            return $handler;
        }
        if (class_exists(\Predis\ClientInterface::class) && $handler instanceof \Predis\ClientInterface) {
            return $handler;
        }

        throw new \RuntimeException('IM 运维需要 Redis 或 Predis 连接。');
    }
}
