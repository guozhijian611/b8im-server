<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use RuntimeException;
use support\think\Cache;

final class RedisDistributedLock implements DistributedLockInterface
{
    public function acquire(string $key, string $token, int $ttlSeconds): bool
    {
        $store = Cache::store('redis');
        $handler = $store->handler();
        $cacheKey = $store->getCacheKey($key);

        if ($handler instanceof \Redis) {
            return $handler->set($cacheKey, $token, ['NX', 'EX' => $ttlSeconds]) === true;
        }
        if (class_exists(\Predis\ClientInterface::class) && $handler instanceof \Predis\ClientInterface) {
            return (string) $handler->set($cacheKey, $token, 'EX', $ttlSeconds, 'NX') === 'OK';
        }

        throw new RuntimeException('模块到期任务需要 Redis 分布式锁。');
    }

    public function release(string $key, string $token): void
    {
        $store = Cache::store('redis');
        $handler = $store->handler();
        $cacheKey = $store->getCacheKey($key);
        $script = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end
return 0
LUA;

        if ($handler instanceof \Redis) {
            $handler->eval($script, [$cacheKey, $token], 1);
            return;
        }
        if (class_exists(\Predis\ClientInterface::class) && $handler instanceof \Predis\ClientInterface) {
            $handler->eval($script, 1, $cacheKey, $token);
            return;
        }

        throw new RuntimeException('无法释放 Redis 分布式锁。');
    }
}
