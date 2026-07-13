<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\app\controller\system\TraceController;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\trace\GuzzleJaegerHttpClient;
use plugin\saimulti\service\trace\JaegerHttpClientInterface;
use plugin\saimulti\service\trace\JaegerTraceQueryService;
use plugin\saimulti\service\trace\JaegerTraceNotFoundException;

final class FakeJaegerHttpClient implements JaegerHttpClientInterface
{
    /** @var list<array{path: string, query: array<string, scalar>}> */
    public array $calls = [];

    /** @param array<string, array<string, mixed>> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    public function get(string $path, array $query = []): array
    {
        $this->calls[] = compact('path', 'query');
        if (!array_key_exists($path, $this->responses)) {
            throw new RuntimeException('Unexpected Jaeger path: ' . $path);
        }
        return $this->responses[$path];
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$traceId = '0123456789abcdef0123456789abcdef';
$trace = [
    'traceID' => $traceId,
    'processes' => [
        'p1' => ['serviceName' => 'b8im-server', 'tags' => []],
        'p2' => ['serviceName' => 'b8im-im-business', 'tags' => []],
    ],
    'spans' => [
        [
            'traceID' => $traceId,
            'spanID' => '1111111111111111',
            'operationName' => 'POST /messages',
            'references' => [],
            'startTime' => 1_700_000_000_000_000,
            'duration' => 30_000,
            'processID' => 'p1',
            'tags' => [
                ['key' => 'b8im.organization', 'type' => 'string', 'value' => '7'],
                ['key' => 'b8im.message_id', 'type' => 'string', 'value' => 'msg-7'],
                ['key' => 'authorization', 'type' => 'string', 'value' => 'Bearer secret'],
            ],
            'logs' => [],
        ],
        [
            'traceID' => $traceId,
            'spanID' => '2222222222222222',
            'operationName' => 'consume message',
            'references' => [[
                'refType' => 'CHILD_OF',
                'traceID' => $traceId,
                'spanID' => '1111111111111111',
            ]],
            'startTime' => 1_700_000_000_010_000,
            'duration' => 50_000,
            'processID' => 'p2',
            'tags' => [
                ['key' => 'error', 'type' => 'bool', 'value' => true],
                ['key' => 'otel.status_code', 'type' => 'string', 'value' => 'ERROR'],
            ],
            'logs' => [[
                'timestamp' => 1_700_000_000_055_000,
                'fields' => [['key' => 'event', 'type' => 'string', 'value' => 'exception']],
            ]],
        ],
    ],
];

$client = new FakeJaegerHttpClient([
    '/api/services' => ['data' => ['b8im-server', 'b8im-im-business', 'b8im-server']],
    '/api/traces' => ['data' => [$trace]],
    '/api/traces/' . $traceId => ['data' => [$trace]],
]);
$service = new JaegerTraceQueryService($client);

$services = $service->services();
$assert($services === [
    'items' => [['name' => 'b8im-im-business'], ['name' => 'b8im-server']],
    'total' => 2,
], '服务列表未完成去重和排序');

$search = $service->search([
    'service' => 'b8im-server',
    'operation' => 'POST /messages',
    'organization' => '7',
    'message_id' => 'msg-7',
    'error_only' => 'true',
    'min_duration_ms' => '10',
    'start_time' => '1699999999000',
    'end_time' => '1700000001000',
    'limit' => '10',
]);
$assert($search['total'] === 1 && $search['limit'] === 10, '搜索分页摘要不正确');
$summary = $search['items'][0];
$assert($summary['trace_id'] === $traceId, 'Trace ID 未归一化');
$assert($summary['root_service'] === 'b8im-server', '根服务识别错误');
$assert($summary['root_operation'] === 'POST /messages', '根操作识别错误');
$assert($summary['start_time_ms'] === 1_700_000_000_000.0, '开始时间未转换为毫秒');
$assert($summary['end_time_ms'] === 1_700_000_000_060.0, '结束时间计算错误');
$assert($summary['duration_ms'] === 60.0, '链路耗时计算错误');
$assert($summary['span_count'] === 2 && $summary['error_count'] === 1, 'Span 统计错误');
$assert($summary['organization'] === '7' && $summary['message_id'] === 'msg-7', 'b8im 关联字段未提取');

$searchCall = $client->calls[1];
$assert($searchCall['path'] === '/api/traces', '搜索未请求 Jaeger traces API');
$assert($searchCall['query']['start'] === 1_699_999_999_000_000, '开始时间未转换为微秒');
$assert($searchCall['query']['end'] === 1_700_000_001_000_000, '结束时间未转换为微秒');
$assert($searchCall['query']['minDuration'] === '10ms', '最小耗时未转换为 Jaeger 格式');
$queryTags = json_decode((string) $searchCall['query']['tags'], true, 512, JSON_THROW_ON_ERROR);
$assert($queryTags === [
    'b8im.organization' => '7',
    'b8im.message_id' => 'msg-7',
    'error' => 'true',
], '搜索标签未正确下推 Jaeger');

$detail = $service->read(strtoupper($traceId));
$assert(count($detail['spans']) === 2, '链路详情 Span 数量错误');
$assert($detail['spans'][0]['parent_span_id'] === null, '根 Span 父级应为 null');
$assert($detail['spans'][1]['parent_span_id'] === '1111111111111111', '子 Span 父级识别错误');
$assert($detail['spans'][1]['error'] === true && $detail['spans'][1]['status'] === 'error', '错误 Span 状态未归一化');
$assert($detail['spans'][0]['tags']['authorization'] === '[REDACTED]', '敏感 Trace 标签未脱敏');
$assert($detail['spans'][1]['logs'][0]['time_ms'] === 1_700_000_000_055.0, 'Span 日志时间未转换为毫秒');

$traceSearch = $service->search(['trace_id' => strtoupper($traceId), 'limit' => 1]);
$assert($traceSearch['items'][0]['trace_id'] === $traceId, 'Trace ID 直查失败');
$assert(end($client->calls)['path'] === '/api/traces/' . $traceId, 'Trace ID 未使用详情端点');

$expectedValidation = false;
try {
    $service->search(['service' => 'b8im-server', 'start_time' => '1700000001000', 'end_time' => '1700000000000']);
} catch (ApiException $exception) {
    $expectedValidation = $exception->getCode() === 422;
}
$assert($expectedValidation, '非法时间范围未被拒绝');

$notFoundService = new JaegerTraceQueryService(new FakeJaegerHttpClient([
    '/api/traces/' . $traceId => ['data' => []],
]));
$notFound = false;
try {
    $notFoundService->read($traceId);
} catch (ApiException $exception) {
    $notFound = $exception->getCode() === 404;
}
$assert($notFound, '不存在的 Trace 未返回 404');

// Jaeger v2 实际缺失响应：HTTP 404 且 body 为 data:null。客户端必须保留 not-found 语义，
// 不能被通用上游异常转成 502。
$mockHandler = new MockHandler([
    new GuzzleResponse(404, ['Content-Type' => 'application/json'], '{"data":null,"errors":null}'),
    new GuzzleResponse(404, ['Content-Type' => 'application/json'], '{"data":null,"errors":null}'),
    new GuzzleResponse(404, ['Content-Type' => 'application/json'], '{"data":null,"errors":null}'),
    new GuzzleResponse(200, ['Content-Type' => 'application/json'], '{"data":["b8im-server"]}'),
    new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode(['data' => [$trace]], JSON_THROW_ON_ERROR)),
]);
$realShapeClient = new GuzzleJaegerHttpClient(
    'http://jaeger:16686',
    new Client(['handler' => HandlerStack::create($mockHandler), 'http_errors' => false]),
);
$explicitNotFound = false;
try {
    $realShapeClient->get('/api/traces/' . $traceId);
} catch (JaegerTraceNotFoundException) {
    $explicitNotFound = true;
}
$assert($explicitNotFound, 'Jaeger v2 HTTP 404 + data:null 未保留 Trace not-found 语义');

$realShapeService = new JaegerTraceQueryService($realShapeClient);
$realShapeRead404 = false;
try {
    $realShapeService->read($traceId);
} catch (ApiException $exception) {
    $realShapeRead404 = $exception->getCode() === 404;
}
$assert($realShapeRead404, 'Jaeger v2 HTTP 404 未在 read 归一为 API 404');
$realShapeSearch404 = false;
try {
    $realShapeService->search(['trace_id' => $traceId]);
} catch (ApiException $exception) {
    $realShapeSearch404 = $exception->getCode() === 404;
}
$assert($realShapeSearch404, 'Jaeger v2 HTTP 404 未在 trace_id search 归一为 API 404');
$assert($realShapeService->services()['total'] === 1, 'Trace 404 处理影响 services 查询');
$assert($realShapeService->search([
    'service' => 'b8im-server',
    'start_time' => '1699999999000',
    'end_time' => '1700000001000',
])['total'] === 1, 'Trace 404 处理影响普通 search 查询');

$upstreamFailureClient = new GuzzleJaegerHttpClient(
    'http://jaeger:16686',
    new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new GuzzleResponse(500, ['Content-Type' => 'application/json'], '{"data":null,"errors":[{"msg":"boom"}]}'),
        ])),
        'http_errors' => false,
    ]),
);
$upstreamFailure = false;
try {
    $upstreamFailureClient->get('/api/services');
} catch (ApiException $exception) {
    $upstreamFailure = $exception->getCode() === 502;
}
$assert($upstreamFailure, '非 Trace-404 的 Jaeger 非 2xx 响应应继续归一为 502');

$controllerReflection = new ReflectionClass(TraceController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$adminId = $controllerReflection->getParentClass()->getProperty('adminId');
$adminInfo = $controllerReflection->getParentClass()->getProperty('adminInfo');
$guard = $controllerReflection->getMethod('assertSuperAdministrator');
$adminId->setValue($controller, 2);
$adminInfo->setValue($controller, ['user_type' => '200']);
$forbidden = false;
try {
    $guard->invoke($controller);
} catch (ApiException $exception) {
    $forbidden = $exception->getCode() === 403;
}
$assert($forbidden, '普通平台管理员未被 Trace 二次鉴权拒绝');
$adminInfo->setValue($controller, ['user_type' => '100']);
$guard->invoke($controller);

fwrite(STDOUT, "Jaeger trace query service tests passed.\n");
