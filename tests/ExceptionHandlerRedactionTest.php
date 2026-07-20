<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\app\exception\Handler;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\exception\SearchProjectionIntegrityException;
use Psr\Log\NullLogger;
use Webman\Http\Request;

$redacted = Handler::redact([
    'account' => 'alice',
    'password' => 'plain-password',
    'nested' => [
        'access_token' => 'token-value',
        'profile' => ['privateKey' => 'private-value', 'nickname' => 'Alice'],
    ],
]);
if (
    $redacted['account'] !== 'alice'
    || $redacted['password'] !== '******'
    || $redacted['nested']['access_token'] !== '******'
    || $redacted['nested']['profile']['privateKey'] !== '******'
    || $redacted['nested']['profile']['nickname'] !== 'Alice'
) {
    throw new RuntimeException('异常参数递归脱敏失败。');
}

$request = new Request("GET /saimulti/web/search/messages HTTP/1.1\r\nHost: api.example.test\r\n\r\n");
$handler = new Handler(new NullLogger(), false);
foreach ([
    new SearchProjectionIntegrityException('internal-search-integrity-sentinel'),
    new ApiException('Search backend is unavailable.', 503),
] as $exception) {
    $response = $handler->render($request, $exception);
    $payload = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() !== 503 || ($payload['code'] ?? null) !== 503) {
        throw new RuntimeException('异常处理器未将搜索 503 映射为 HTTP/body 503。');
    }
    if ($exception instanceof SearchProjectionIntegrityException
        && str_contains($response->rawBody(), 'internal-search-integrity-sentinel')) {
        throw new RuntimeException('异常处理器向生产响应泄漏了搜索完整性内部详情。');
    }
}
$debugIntegrityResponse = (new Handler(new NullLogger(), true))->render(
    $request,
    new SearchProjectionIntegrityException('internal-search-integrity-sentinel'),
);
$debugIntegrityPayload = json_decode(
    $debugIntegrityResponse->rawBody(),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
if ($debugIntegrityResponse->getStatusCode() !== 503
    || ($debugIntegrityPayload['code'] ?? null) !== 503
    || array_key_exists('exception_info', $debugIntegrityPayload)
    || str_contains($debugIntegrityResponse->rawBody(), 'internal-search-integrity-sentinel')) {
    throw new RuntimeException('Debug 响应泄漏了搜索完整性异常内部详情。');
}

echo "ExceptionHandlerRedactionTest passed\n";
