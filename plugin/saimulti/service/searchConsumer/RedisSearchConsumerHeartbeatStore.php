<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use RuntimeException;
use support\think\Cache;

final class RedisSearchConsumerHeartbeatStore implements SearchConsumerHeartbeatStoreInterface
{
    private const CLAIM_OR_RENEW_LUA = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current then
    if ARGV[1] ~= '' then
        return 0
    end
    redis.call('SETEX', KEYS[1], tonumber(ARGV[3]), ARGV[2])
    return 1
end
if ARGV[1] ~= '' and current == ARGV[1] then
    redis.call('SETEX', KEYS[1], tonumber(ARGV[3]), ARGV[2])
    return 1
end
return 0
LUA;

    private const DELETE_IF_EQUALS_LUA = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    public function __construct(private readonly ?object $rawHandler = null)
    {
    }

    public function claimOrRenew(
        string $key,
        ?string $expectedValue,
        string $newValue,
        int $ttlSeconds,
    ): bool
    {
        $handler = $this->handler();
        $expected = $expectedValue ?? '';
        if (class_exists(\Predis\ClientInterface::class) && $handler instanceof \Predis\ClientInterface) {
            $result = $handler->eval(
                self::CLAIM_OR_RENEW_LUA,
                1,
                $key,
                $expected,
                $newValue,
                (string) $ttlSeconds,
            );
        } elseif ($handler instanceof \Redis || method_exists($handler, 'eval')) {
            $result = $handler->eval(
                self::CLAIM_OR_RENEW_LUA,
                [$key, $expected, $newValue, (string) $ttlSeconds],
                1,
            );
        } else {
            throw new RuntimeException('Search consumer heartbeat store requires Redis EVAL.');
        }

        return $result === 1 || $result === '1';
    }

    public function read(string $key): ?string
    {
        $value = $this->handler()->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function deleteIfEquals(string $key, string $expectedValue): bool
    {
        $handler = $this->handler();
        if (class_exists(\Predis\ClientInterface::class) && $handler instanceof \Predis\ClientInterface) {
            $result = $handler->eval(self::DELETE_IF_EQUALS_LUA, 1, $key, $expectedValue);
        } elseif ($handler instanceof \Redis || method_exists($handler, 'eval')) {
            $result = $handler->eval(self::DELETE_IF_EQUALS_LUA, [$key, $expectedValue], 1);
        } else {
            throw new RuntimeException('Search consumer heartbeat store requires Redis EVAL.');
        }

        return $result === 1 || $result === '1';
    }

    private function handler(): object
    {
        if ($this->rawHandler !== null) {
            return $this->rawHandler;
        }

        return Cache::store('redis')->handler();
    }
}
