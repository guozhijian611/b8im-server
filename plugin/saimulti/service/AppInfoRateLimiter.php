<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

use plugin\saimulti\exception\ApiException;
use support\think\Cache;
use Throwable;

final class AppInfoRateLimiter
{
    public const RATE_LIMITED = 42901;
    public const UNAVAILABLE = 50302;

    public function __construct(
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 60,
    ) {
    }

    public function assertAllowed(string $clientKey): void
    {
        try {
            $clientKey = hash('sha256', $clientKey);
            $name = 'app_info_rate:' . $clientKey;
            $store = Cache::store();
            $cacheKey = $store->getCacheKey($name);
            $handler = $store->handler();

            if ($handler instanceof \Redis) {
                $count = $handler->eval(
                    <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return current
LUA,
                    [$cacheKey, $this->windowSeconds],
                    1,
                );
            } else {
                $count = $store->inc($name);
                if ((int) $count === 1) {
                    $handler->expire($cacheKey, $this->windowSeconds);
                }
            }
        } catch (Throwable $exception) {
            throw new ApiException('发现服务暂不可用', self::UNAVAILABLE, $exception);
        }

        if (!is_numeric($count) || (int) $count > $this->limit) {
            throw new ApiException('请求过于频繁', self::RATE_LIMITED);
        }
    }
}
