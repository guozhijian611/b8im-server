<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\app\middleware\CrossDomain;
use plugin\saimulti\service\TrustedCorsPolicy;
use Webman\Http\Request;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$policy = new TrustedCorsPolicy('https://extra.example.com, https://tenant.idev.love');
$assert($policy->allowedOrigin('https://tenant.idev.love') === 'https://tenant.idev.love', 'Public tenant origin was rejected.');
$assert($policy->allowedOrigin('http://127.0.0.1:16888') === 'http://127.0.0.1:16888', 'Local tenant origin was rejected.');
$assert($policy->allowedOrigin('https://extra.example.com') === 'https://extra.example.com', 'Environment origin extension was rejected.');
$assert($policy->allowedOrigin('https://evil.example.com') === null, 'Unknown origin was accepted.');
$assert($policy->allowedOrigin('*') === null, 'Wildcard origin was accepted.');
$assert($policy->allowedOrigin('https://tenant.idev.love.evil.example') === null, 'Suffix-confusion origin was accepted.');
$assert($policy->allowsRequestedHeaders('content-type, app-id, authorization'), 'Trusted request headers were rejected.');
$assert(!$policy->allowsRequestedHeaders('content-type, x-internal-secret'), 'Unknown request header was accepted.');

$request = static fn (string $raw): Request => new Request(str_replace("\n", "\r\n", trim($raw) . "\n\n"));
$handlerCalls = 0;
$handler = static function () use (&$handlerCalls) {
    $handlerCalls++;
    return response('handled', 200);
};
$middleware = new CrossDomain();

$allowedPreflight = $middleware->process($request(<<<'HTTP'
OPTIONS /saimulti/captcha HTTP/1.1
Host: 127.0.0.1:18888
Origin: http://127.0.0.1:16888
Access-Control-Request-Method: GET
Access-Control-Request-Headers: Content-Type, App-Id
HTTP), $handler);
$assert($allowedPreflight->getStatusCode() === 204, 'Allowed preflight did not return 204.');
$assert($allowedPreflight->getHeader('Access-Control-Allow-Origin') === 'http://127.0.0.1:16888', 'Allowed preflight ACAO is wrong.');
$assert($allowedPreflight->getHeader('Access-Control-Allow-Credentials') === 'true', 'Allowed preflight credentials header is missing.');
$assert($handlerCalls === 0, 'Preflight reached the business handler.');

$rejectedPreflight = $middleware->process($request(<<<'HTTP'
OPTIONS /saimulti/tenant/login HTTP/1.1
Host: api.idev.love
Origin: https://evil.example.com
Access-Control-Request-Method: POST
Access-Control-Request-Headers: Content-Type, App-Id
HTTP), $handler);
$assert($rejectedPreflight->getStatusCode() === 403, 'Unknown preflight origin was not rejected.');
$assert($rejectedPreflight->getHeader('Access-Control-Allow-Origin') === null, 'Rejected preflight leaked ACAO.');

$actual = $middleware->process($request(<<<'HTTP'
GET /saimulti/captcha HTTP/1.1
Host: api.idev.love
Origin: https://tenant.idev.love
HTTP), $handler);
$assert($actual->getStatusCode() === 200 && $handlerCalls === 1, 'Allowed actual request did not reach handler.');
$assert($actual->getHeader('Access-Control-Allow-Origin') === 'https://tenant.idev.love', 'Actual response ACAO is wrong.');
$assert($actual->getHeader('Access-Control-Allow-Origin') !== '*', 'Credentialed response used wildcard ACAO.');

$rejectedActual = $middleware->process($request(<<<'HTTP'
GET /saimulti/captcha HTTP/1.1
Host: api.idev.love
Origin: https://evil.example.com
HTTP), $handler);
$assert($rejectedActual->getStatusCode() === 403 && $handlerCalls === 1, 'Unknown actual origin reached handler.');

echo "TrustedCorsPolicyTest: {$assertions} assertions passed\n";
