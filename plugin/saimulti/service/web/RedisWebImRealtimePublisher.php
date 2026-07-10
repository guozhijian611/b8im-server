<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\service\RealtimeControlEventEnvelope;
use Redis;

final class RedisWebImRealtimePublisher implements WebImRealtimePublisherInterface
{
    private const QUEUE_KEY = 'im:events:realtime';

    private ?object $redis = null;

    public function __construct(?object $rawRedis = null)
    {
        $this->redis = $rawRedis;
    }

    public function publishFriendRequestCreated(int $organization, array $payload): void
    {
        $this->publish('friend_request.created', $organization, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function publish(string $type, int $organization, array $payload): void
    {
        if ($organization <= 0) {
            throw new \InvalidArgumentException('Web IM realtime organization is invalid.');
        }
        $encoded = RealtimeControlEventEnvelope::encode($type, $organization, $payload, true);
        $pushed = $this->redis()->rPush(self::QUEUE_KEY, $encoded);
        if ($pushed === false) {
            throw new \RuntimeException('Web IM realtime event was not queued.');
        }
    }

    private function redis(): object
    {
        if ($this->redis !== null) {
            return $this->redis;
        }
        if (!class_exists(Redis::class)) {
            throw new \RuntimeException('The Redis extension is required for Web IM realtime events.');
        }
        $redis = new Redis();
        $connected = $redis->connect(
            $this->environment('REDIS_HOST', '127.0.0.1'),
            (int) $this->environment('REDIS_PORT', '6379'),
            2.0,
        );
        if (!$connected) {
            throw new \RuntimeException('Web IM realtime Redis connection failed.');
        }
        $password = $this->environment('REDIS_PASSWORD', '');
        if ($password !== '' && !$redis->auth($password)) {
            throw new \RuntimeException('Web IM realtime Redis authentication failed.');
        }
        $database = (int) $this->environment('REDIS_DB', '0');
        if ($database > 0 && !$redis->select($database)) {
            throw new \RuntimeException('Web IM realtime Redis database selection failed.');
        }

        return $this->redis = $redis;
    }

    private function environment(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null ? $default : trim((string) $value);
    }
}
