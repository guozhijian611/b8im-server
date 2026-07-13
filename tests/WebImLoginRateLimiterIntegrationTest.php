<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\RedisWebImLoginRateLimiter;
use support\think\Cache;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$expectRateLimited = static function (callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert(
            $exception->getCode() === RedisWebImLoginRateLimiter::RATE_LIMITED,
            'Login limiter returned an unexpected error code.',
        );
        return;
    }

    throw new RuntimeException('Expected the login limiter to reject the request.');
};

$store = Cache::store();
$redis = $store->handler();
if (!$redis instanceof Redis) {
    throw new RuntimeException('WebImLoginRateLimiterIntegrationTest requires the configured Redis cache.');
}

$suffix = bin2hex(random_bytes(8));
$organization = random_int(100000, 999999);
$account = 'rate-account-' . $suffix;
$accountIp = '198.51.100.10';
$ipScope = '198.51.100.11';
$accounts = [$account, 'rate-ip-a-' . $suffix, 'rate-ip-b-' . $suffix, 'rate-ip-c-' . $suffix];
$cacheKeys = [];
foreach ($accounts as $candidate) {
    $cacheKeys[] = $store->getCacheKey('web_im_login:account:' . hash(
        'sha256',
        $organization . ':' . mb_strtolower($candidate),
    ));
}
foreach ([$accountIp, $ipScope] as $ip) {
    $cacheKeys[] = $store->getCacheKey(
        'web_im_login:ip:' . hash('sha256', $ip),
    );
}

try {
    $accountLimiter = new RedisWebImLoginRateLimiter(2, 100, 30);
    $accountLimiter->assertAllowed($organization, $account, $accountIp);
    $accountLimiter->assertAllowed($organization, $account, $accountIp);
    $expectRateLimited(static fn () => $accountLimiter->assertAllowed(
        $organization,
        $account,
        $accountIp,
    ));
    $accountLimiter->resetAccountAttempts($organization, $account);
    $assert($redis->exists($cacheKeys[0]) === 0, 'Account limiter reset did not delete the exact account key.');
    $assert($redis->exists($store->getCacheKey(
        'web_im_login:ip:' . hash('sha256', $accountIp),
    )) === 1, 'Account limiter reset unexpectedly deleted the IP key.');
    $accountLimiter->assertAllowed($organization, $account, $accountIp);

    $ipLimiter = new RedisWebImLoginRateLimiter(100, 2, 30);
    $ipLimiter->assertAllowed($organization, $accounts[1], $ipScope);
    $ipLimiter->assertAllowed($organization, $accounts[2], $ipScope);
    $expectRateLimited(static fn () => $ipLimiter->assertAllowed(
        $organization,
        $accounts[3],
        $ipScope,
    ));

    $accountTtl = $redis->ttl($cacheKeys[0]);
    $ipTtl = $redis->ttl($cacheKeys[array_key_last($cacheKeys)]);
    $assert($accountTtl > 0 && $accountTtl <= 30, 'Account limiter key TTL is invalid.');
    $assert($ipTtl > 0 && $ipTtl <= 30, 'IP limiter key TTL is invalid.');
} finally {
    $redis->del(array_values(array_unique($cacheKeys)));
}

echo sprintf("WebImLoginRateLimiterIntegrationTest: %d assertions passed\n", $assertions);
