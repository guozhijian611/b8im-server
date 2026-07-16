<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\WebOrganizationResolver;
use plugin\saimulti\service\WebTokenService;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, '异常码不匹配');
        return;
    }
    throw new RuntimeException('预期异常未抛出');
};

$secret = str_repeat('test-secret-', 4);
$service = new WebTokenService($secret, 'HS256');
$token = $service->issueAccess([
    'id' => 9,
    'user_id' => 'user_9',
    'account' => 'alice',
], 1, 'b8im-local', 'web-window_9', 'web', 'browser');
$assert($token['expires_in'] >= 300 && $token['refresh_token'] === '', 'Web token 返回模型无效');
$claims = $service->verifyAccess($token['access_token'], 1, 'b8im-local', 'web');
$assert($claims['aud'] === 'web-api', 'Web token audience 无效');
$assert($claims['organization'] === 1 && $claims['deployment_id'] === 'b8im-local', 'Web token 租户上下文无效');
$assert($claims['device_id'] === 'web-window_9', 'Web token 设备上下文无效');
$expectCode(401, static fn () => $service->verifyAccess($token['access_token'], 2, 'b8im-local', 'web'));
$expectCode(401, static fn () => $service->verifyAccess($token['access_token'], 1, 'other-deployment', 'web'));
$expectCode(401, static fn () => $service->verifyAccess($token['access_token'], 1, 'b8im-local', 'app'));
$appToken = $service->issueAccess([
    'id' => 9,
    'user_id' => 'user_9',
    'account' => 'alice',
], 1, 'b8im-local', 'app-device_9', 'app', 'ios');
$appClaims = $service->verifyAccess($appToken['access_token'], 1, 'b8im-local', 'app');
$assert($appClaims['aud'] === 'app-api' && $appClaims['os'] === 'ios', 'App token runtime context 无效');
$expectCode(401, static fn () => $service->extractBearer('Basic ' . $token['access_token']));
$assert($service->extractBearer('Bearer ' . $token['access_token']) === $token['access_token'], 'Bearer 解析失败');
$assert(WebOrganizationResolver::originFromUrl('https://IM.Example.com/path') === 'https://im.example.com', 'Origin 规范化失败');
$assert(WebOrganizationResolver::originFromUrl('http://127.0.0.1:16988/') === 'http://127.0.0.1:16988', '开发 Origin 规范化失败');
$assert(WebOrganizationResolver::originFromUrl('https://IM.Example.com:443/path') === 'https://im.example.com', 'HTTPS 默认端口未规范化');
$assert(WebOrganizationResolver::originFromUrl('http://IM.Example.com:80/path') === 'http://im.example.com', 'HTTP 默认端口未规范化');

echo sprintf("WebTrustBoundaryTest: %d assertions passed\n", $assertions);
