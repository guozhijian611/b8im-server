<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use support\think\Cache;
use Throwable;

final class RedisWebImLoginRateLimiter implements WebImLoginRateLimiterInterface
{
    public const RATE_LIMITED = 429;
    public const UNAVAILABLE = 50301;

    private readonly int $accountLimit;

    private readonly int $ipLimit;

    private readonly int $windowSeconds;

    public function __construct(?int $accountLimit = null, ?int $ipLimit = null, ?int $windowSeconds = null)
    {
        $this->accountLimit = max(1, min(100, $accountLimit ?? (int) env('WEB_IM_LOGIN_ACCOUNT_LIMIT', 5)));
        $this->ipLimit = max(1, min(1000, $ipLimit ?? (int) env('WEB_IM_LOGIN_IP_LIMIT', 30)));
        $this->windowSeconds = max(10, min(3600, $windowSeconds ?? (int) env('WEB_IM_LOGIN_WINDOW_SECONDS', 300)));
    }

    public function assertAllowed(int $organization, string $account, string $clientIp): void
    {
        if ($organization <= 0 || $account === '' || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Web IM login limiter scope is invalid.');
        }

        try {
            $store = Cache::store();
            $handler = $store->handler();
            if (!$handler instanceof \Redis) {
                throw new \RuntimeException('Redis is required for atomic Web IM login limiting.');
            }
            $accountName = self::accountCacheName($organization, $account);
            $ipName = 'web_im_login:ip:' . hash('sha256', $clientIp);
            $counts = $handler->eval(
                <<<'LUA'
local account_count = redis.call('INCR', KEYS[1])
if account_count == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
local ip_count = redis.call('INCR', KEYS[2])
if ip_count == 1 then
    redis.call('EXPIRE', KEYS[2], ARGV[1])
end
return {account_count, ip_count}
LUA,
                [
                    $store->getCacheKey($accountName),
                    $store->getCacheKey($ipName),
                    $this->windowSeconds,
                ],
                2,
            );
        } catch (Throwable $exception) {
            throw new ApiException('登录服务暂不可用。', self::UNAVAILABLE, $exception);
        }

        if (
            !is_array($counts)
            || count($counts) !== 2
            || !is_numeric($counts[0])
            || !is_numeric($counts[1])
        ) {
            throw new ApiException('登录服务暂不可用。', self::UNAVAILABLE);
        }
        if ((int) $counts[0] > $this->accountLimit || (int) $counts[1] > $this->ipLimit) {
            throw new ApiException('登录尝试过于频繁，请稍后再试。', self::RATE_LIMITED);
        }
    }

    /** Reset only one exact account scope; IP limits are intentionally untouched. */
    public function resetAccountAttempts(int $organization, string $account): void
    {
        if ($organization <= 0 || trim($account) === '') {
            throw new \InvalidArgumentException('Web IM login limiter account scope is invalid.');
        }

        try {
            $store = Cache::store();
            $handler = $store->handler();
            if (!$handler instanceof \Redis) {
                throw new \RuntimeException('Redis is required for Web IM login limiter reset.');
            }
            $handler->del($store->getCacheKey(self::accountCacheName($organization, $account)));
        } catch (Throwable $exception) {
            throw new ApiException('登录服务暂不可用。', self::UNAVAILABLE, $exception);
        }
    }

    private static function accountCacheName(int $organization, string $account): string
    {
        return 'web_im_login:account:' . hash(
            'sha256',
            $organization . ':' . mb_strtolower($account),
        );
    }
}
