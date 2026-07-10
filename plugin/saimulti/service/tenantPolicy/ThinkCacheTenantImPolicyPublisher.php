<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\tenantPolicy;

use plugin\saimulti\service\RealtimeControlEventEnvelope;
use RuntimeException;
use support\think\Cache;

final class ThinkCacheTenantImPolicyPublisher implements TenantImPolicyPublisherInterface
{
    private const POLICY_KEY = 'tenant_im_policy:%d';

    private const REALTIME_EVENT_QUEUE = 'im:events:realtime';

    public function __construct(private readonly ?object $rawHandler = null)
    {
    }

    public function invalidateAndPublish(int $organization, int $version, array $actor): void
    {
        $handler = $this->handler();
        $handler->del(sprintf(self::POLICY_KEY, $organization));
        $event = RealtimeControlEventEnvelope::encode(
            'tenant.policy.changed',
            $organization,
            [
                'version' => $version,
                'actor_type' => (string) ($actor['type'] ?? ''),
                'actor_id' => (int) ($actor['id'] ?? 0),
            ],
            true,
        );
        $result = $handler->rPush(self::REALTIME_EVENT_QUEUE, $event);
        if ((int) $result <= 0) {
            throw new RuntimeException('IM 策略变更事件发布失败。');
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

        throw new RuntimeException('IM 策略变更需要 Redis 或 Predis 连接。');
    }
}
