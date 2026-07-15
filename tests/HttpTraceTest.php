<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use plugin\saimulti\app\middleware\HttpTrace;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\trace\ResilientSpanExporter;
use plugin\saimulti\service\TrustedCorsPolicy;
use plugin\saimulti\service\trace\Telemetry;
use support\Request;
use Webman\Http\Response;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pluginMiddleware = require dirname(__DIR__) . '/plugin/saimulti/config/middleware.php';
$assert(
    ($pluginMiddleware[''][0] ?? null) === HttpTrace::class,
    'HttpTrace must remain the first global Saimulti middleware.',
);
$assert(
    in_array('traceparent', TrustedCorsPolicy::ALLOWED_HEADERS, true)
    && in_array('tracestate', TrustedCorsPolicy::ALLOWED_HEADERS, true),
    'Trusted CORS policy does not permit W3C Trace Context headers.',
);

$request = static function (?string $traceparent = null, ?string $tracestate = null): Request {
    $headers = "Host: api.example.test\r\n";
    if ($traceparent !== null) {
        $headers .= "traceparent: {$traceparent}\r\n";
    }
    if ($tracestate !== null) {
        $headers .= "tracestate: {$tracestate}\r\n";
    }
    $request = new Request("GET /saimulti/client/config HTTP/1.1\r\n{$headers}\r\n");
    $request->controller = 'plugin\\saimulti\\app\\controller\\web\\ClientConfigController';
    $request->action = 'index';

    return $request;
};

$exporter = new InMemoryExporter();
Telemetry::setProviderForTesting(TracerProvider::builder()
    ->addSpanProcessor(new SimpleSpanProcessor($exporter))
    ->build());
$middleware = new HttpTrace();
$upstreamTraceId = '0123456789abcdef0123456789abcdef';
$upstreamSpanId = '0123456789abcdef';
$firstRequest = $request("00-{$upstreamTraceId}-{$upstreamSpanId}-01", 'vendor=value');
$firstResponse = $middleware->process($firstRequest, static fn (): Response => new Response(200));

$assert($firstResponse->getHeader('X-Trace-Id') === $upstreamTraceId, 'Response trace id did not continue W3C parent.');
$spans = $exporter->getSpans();
$assert(count($spans) === 1, 'HTTP SERVER span was not exported.');
$first = $spans[0];
$assert($first->getKind() === SpanKind::KIND_SERVER, 'HTTP span kind is not SERVER.');
$assert($first->getTraceId() === $upstreamTraceId, 'HTTP span did not continue upstream trace id.');
$assert($first->getParentSpanId() === $upstreamSpanId, 'HTTP span parent span id is incorrect.');
$assert(($first->getAttributes()->get('b8im.endpoint')) === 'ClientConfigController.index', 'Endpoint is not low-cardinality.');
$assert(($first->getAttributes()->get('http.response.status_code')) === 200, 'HTTP status attribute missing.');
$assert(!Span::getCurrent()->getContext()->isValid(), 'HTTP request context leaked after request completion.');

$businessFailureMessage = "SQLSTATE[42S22]: Unknown column 'enterprise_code'; token=must-not-be-exported";
$businessFailure = new RuntimeException($businessFailureMessage, 10501);
$businessFailureResponse = new Response(
    200,
    ['Content-Type' => 'application/json;charset=utf-8'],
    json_encode([
        'code' => 10501,
        'message' => $businessFailureMessage,
        'type' => 'failed',
    ], JSON_THROW_ON_ERROR),
);
$businessFailureResponse->exception($businessFailure);
$middleware->process($request(), static fn (): Response => $businessFailureResponse);
$spans = $exporter->getSpans();
$businessFailureSpan = $spans[1];
$assert(
    $businessFailureSpan->getStatus()->getCode() === StatusCode::STATUS_ERROR,
    'HTTP 200 business failure was not marked ERROR.',
);
$assert(
    $businessFailureSpan->getAttributes()->get('b8im.response.code') === 10501,
    'Business response code attribute is missing.',
);
$assert(
    $businessFailureSpan->getAttributes()->get('b8im.response.type') === 'failed',
    'Business response type attribute is missing.',
);
$diagnosticMessage = (string) $businessFailureSpan->getAttributes()->get('b8im.response.message');
$assert(str_contains($diagnosticMessage, "Unknown column 'enterprise_code'"), 'Safe diagnostic message was lost.');
$assert(!str_contains($diagnosticMessage, 'must-not-be-exported'), 'Diagnostic message leaked a token.');
$businessFailureEvent = json_encode(
    $businessFailureSpan->getEvents()[0]->getAttributes()->toArray(),
    JSON_THROW_ON_ERROR,
);
$assert(str_contains($businessFailureEvent, '10501'), 'Business error event lost its stable code.');
$assert(str_contains($businessFailureEvent, "Unknown column 'enterprise_code'"), 'Error event lost diagnostic context.');
$assert(!str_contains($businessFailureEvent, 'must-not-be-exported'), 'Business error event leaked a token.');

$secondRequest = $request('invalid-client-value');
$secondResponse = $middleware->process($secondRequest, static fn (): Response => new Response(204));
$secondTraceId = (string) $secondResponse->getHeader('X-Trace-Id');
$assert(preg_match('/^[0-9a-f]{32}$/', $secondTraceId) === 1, 'Invalid traceparent did not safely start a new trace.');
$assert($secondTraceId !== $upstreamTraceId, 'Invalid traceparent incorrectly reused the previous request context.');
$spans = $exporter->getSpans();
$assert(!$spans[2]->getParentContext()->isValid(), 'Second request inherited leaked parent context.');

$errorRequest = $request();
try {
    $middleware->process($errorRequest, static function (): Response {
        throw new ApiException('token=must-not-be-exported', 403);
    });
    throw new RuntimeException('Expected ApiException was not thrown.');
} catch (ApiException $exception) {
    $rendered = $exception->render($errorRequest);
    $assert(
        $rendered?->getHeader('X-Trace-Id') === ($errorRequest->properties[HttpTrace::REQUEST_TRACE_ID] ?? null),
        'Rendered ApiException lost X-Trace-Id.',
    );
}
$spans = $exporter->getSpans();
$errorSpan = $spans[3];
$assert($errorSpan->getStatus()->getCode() === StatusCode::STATUS_ERROR, 'Critical ApiException was not ERROR.');
$eventJson = json_encode($errorSpan->getEvents()[0]->getAttributes()->toArray(), JSON_THROW_ON_ERROR);
$assert(!str_contains($eventJson, 'must-not-be-exported'), 'Critical ApiException leaked its message.');
$assert(!Span::getCurrent()->getContext()->isValid(), 'Error request context leaked after request completion.');

// A failed exporter may drop telemetry, but it must never change the business response.
$failingExporter = new class implements SpanExporterInterface {
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return new ErrorFuture(new RuntimeException('collector unavailable'));
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
};
$warnings = [];
$resilientExporter = new ResilientSpanExporter(
    $failingExporter,
    60,
    static function (string $warning) use (&$warnings): void {
        $warnings[] = $warning;
    },
    static fn (): float => 100.0,
);
$assert($resilientExporter->export([])->await() === false, 'Resilient exporter did not return false.');
$assert($resilientExporter->export([])->await() === false, 'Repeated exporter failure did not remain false.');
$assert(count($warnings) === 1, 'Exporter warning was not rate-limited.');
$assert(
    $warnings[0] === '[b8im:telemetry] code=export_failed exception=RuntimeException',
    'Exporter warning contains unstable or sensitive details.',
);
$assert(!str_contains($warnings[0], 'collector unavailable'), 'Exporter warning leaked exception message.');
$batch = new BatchSpanProcessor($resilientExporter, Clock::getDefault(), 1, 100, 100, 1, true);
Telemetry::setProviderForTesting(TracerProvider::builder()->addSpanProcessor($batch)->build());
$failureResponse = $middleware->process($request(), static fn (): Response => new Response(200, [], 'business-ok'));
$assert($failureResponse->getStatusCode() === 200, 'Exporter failure changed HTTP status.');
$assert((string) $failureResponse->getHeader('X-Trace-Id') !== '', 'Exporter failure removed response trace id.');
$assert(!Span::getCurrent()->getContext()->isValid(), 'Exporter failure leaked request context.');

Telemetry::setProviderForTesting(null);
echo "HTTP trace middleware tests passed.\n";
