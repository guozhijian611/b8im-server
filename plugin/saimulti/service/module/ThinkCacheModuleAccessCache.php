<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use JsonException;
use RuntimeException;
use support\think\Cache;

final class ThinkCacheModuleAccessCache implements ModuleAccessCacheInterface
{
    private const DEFAULT_TTL_SECONDS = 60;

    private const MAX_TTL_SECONDS = 300;

    /**
     * A request may read an old DB snapshot before a lifecycle transaction
     * commits and reach Redis only after the post-commit invalidation. Keep
     * the greatest (module_lock_version, license version) tuple so that late
     * old writers cannot resurrect permission.
     */
    private const MONOTONIC_SET_LUA = <<<'LUA'
local currentRaw = redis.call('GET', KEYS[1])
if currentRaw then
    local currentOk, current = pcall(cjson.decode, currentRaw)
    local incomingOk, incoming = pcall(cjson.decode, ARGV[1])
    if currentOk and incomingOk then
        local currentModule = tonumber(current['module_lock_version']) or -1
        local incomingModule = tonumber(incoming['module_lock_version']) or -1
        local currentLicense = tonumber(current['version']) or -1
        local incomingLicense = tonumber(incoming['version']) or -1
        if incomingModule < currentModule
            or (incomingModule == currentModule and incomingLicense < currentLicense) then
            return 0
        end
    end
end
redis.call('SETEX', KEYS[1], tonumber(ARGV[2]), ARGV[1])
return 1
LUA;

    private readonly int $ttlSeconds;

    public function __construct(?int $ttlSeconds = null, private readonly ?object $rawHandler = null)
    {
        $configured = $ttlSeconds ?? (int) config(
            'plugin.saimulti.module.access_cache_ttl_seconds',
            self::DEFAULT_TTL_SECONDS,
        );
        $this->ttlSeconds = max(1, min(self::MAX_TTL_SECONDS, $configured));
    }

    public function get(string $key): ?array
    {
        $value = $this->handler()->get($key);
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function set(string $key, array $value): void
    {
        try {
            $encoded = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('模块授权缓存序列化失败: %s', $key), previous: $exception);
        }

        $ttl = $this->ttlSeconds;
        if (isset($value['effective_until']) && is_numeric($value['effective_until'])) {
            $ttl = max(1, min($ttl, (int) $value['effective_until'] - time()));
        }
        $handler = $this->handler();
        if (class_exists(\Predis\ClientInterface::class) && $handler instanceof \Predis\ClientInterface) {
            $result = $handler->eval(self::MONOTONIC_SET_LUA, 1, $key, $encoded, (string) $ttl);
        } elseif ($handler instanceof \Redis || method_exists($handler, 'eval')) {
            $result = $handler->eval(self::MONOTONIC_SET_LUA, [$key, $encoded, (string) $ttl], 1);
        } else {
            // Injectable test doubles may expose only the minimal SETEX API.
            $result = $handler->setex($key, $ttl, $encoded);
        }
        if (!in_array($result, [0, 1, true, 'OK'], true)) {
            throw new RuntimeException(sprintf('模块授权缓存写入失败: %s', $key));
        }
    }

    public function delete(string $key): void
    {
        $result = $this->handler()->del($key);
        if ($result === false) {
            throw new RuntimeException(sprintf('模块授权缓存删除失败: %s', $key));
        }
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
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

        throw new RuntimeException('模块授权缓存需要 Redis 或 Predis 连接。');
    }
}
