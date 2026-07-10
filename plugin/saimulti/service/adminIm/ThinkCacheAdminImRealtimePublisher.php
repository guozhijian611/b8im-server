<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

use plugin\saimulti\service\RealtimeControlEventEnvelope;
use support\think\Cache;
use Throwable;

final class ThinkCacheAdminImRealtimePublisher implements AdminImRealtimePublisherInterface
{
    private const REALTIME_EVENT_QUEUE = 'im:events:realtime';

    public function __construct(private readonly ?object $rawHandler = null)
    {
    }

    public function publish(string $type, array $payload): bool
    {
        if (!in_array($type, [
            'auth.session_revoked',
            'auth.device_disabled',
            'auth.organization_disabled',
            'auth.organization_enabled',
        ], true)) {
            return false;
        }

        try {
            $organization = (int) ($payload['organization'] ?? 0);
            if ($organization <= 0) {
                return false;
            }
            unset($payload['organization']);
            $event = RealtimeControlEventEnvelope::encode($type, $organization, $payload);
            $result = $this->handler()->rPush(self::REALTIME_EVENT_QUEUE, $event);

            return is_int($result) ? $result > 0 : (int) $result > 0;
        } catch (Throwable) {
            return false;
        }
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

        throw new \RuntimeException('IM 实时事件发布需要 Redis 或 Predis 连接。');
    }
}
