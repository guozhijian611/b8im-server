<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Composer\InstalledVersions;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use plugin\saimulti\service\trace\Telemetry;
use plugin\saimulti\service\trace\PeriodicFlushScheduler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    InstalledVersions::getPrettyVersion('open-telemetry/exporter-otlp') === '1.3.4',
    'OTLP exporter must remain pinned to the PHP 8.1 compatible 1.3.4 release.',
);
$sdkVersion = InstalledVersions::getPrettyVersion('open-telemetry/sdk');
$assert(is_string($sdkVersion) && str_starts_with($sdkVersion, '1.'), 'OpenTelemetry SDK must stay on major 1.');

$registeredInterval = null;
$registeredCallback = null;
$deletedTimerIds = [];
$scheduler = new PeriodicFlushScheduler(
    static function (float $interval, callable $callback) use (&$registeredInterval, &$registeredCallback): int {
        $registeredInterval = $interval;
        $registeredCallback = $callback;

        return 73;
    },
    static function (int $timerId) use (&$deletedTimerIds): bool {
        $deletedTimerIds[] = $timerId;

        return true;
    },
);
$flushCount = 0;
$assert($scheduler->start(5.0, static function () use (&$flushCount): void {
    ++$flushCount;
}), 'Periodic flush timer was not registered.');
$assert($scheduler->isStarted(), 'Periodic flush scheduler did not retain timer state.');
$assert($registeredInterval === 5.0 && is_callable($registeredCallback), 'Periodic flush interval/callback mismatch.');
$registeredCallback();
$assert($flushCount === 1, 'Periodic flush callback did not run.');
$scheduler->stop();
$assert(!$scheduler->isStarted() && $deletedTimerIds === [73], 'Periodic flush timer was not cleaned up.');

$cliScheduler = new PeriodicFlushScheduler(
    static function (): never {
        throw new RuntimeException('no event loop');
    },
);
$assert(!$cliScheduler->start(5.0, static fn (): bool => true), 'CLI timer failure did not degrade safely.');
$defaultCliScheduler = new PeriodicFlushScheduler();
$assert(
    !$defaultCliScheduler->start(5.0, static fn (): bool => true),
    'Default Workerman timer did not degrade safely without an event loop.',
);

$originalEnvironment = [];
$setEnvironment = static function (string $key, ?string $value) use (&$originalEnvironment): void {
    if (!array_key_exists($key, $originalEnvironment)) {
        $previous = getenv($key);
        $originalEnvironment[$key] = $previous === false ? null : $previous;
    }
    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        return;
    }
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
};

$setEnvironment('OTEL_TRACES_ENABLED', 'false');
Telemetry::setProviderForTesting(null);
$assert(Telemetry::provider() instanceof NoopTracerProvider, 'OTEL_TRACES_ENABLED=false must disable tracing.');

$setEnvironment('OTEL_TRACES_ENABLED', 'true');
$setEnvironment('OTEL_SERVICE_NAME', 'not-allowed');
$setEnvironment('OTEL_SERVICE_VERSION', '2026.07.14-test');
$setEnvironment('OTEL_DEPLOYMENT_ENVIRONMENT', 'qa');
$errorLogPath = tempnam(sys_get_temp_dir(), 'b8im-otel-test-');
$assert(is_string($errorLogPath), 'Unable to create telemetry warning capture file.');
$previousErrorLog = ini_get('error_log');
ini_set('error_log', $errorLogPath);
$resourceMethod = new ReflectionMethod(Telemetry::class, 'resourceAttributes');
$resource = $resourceMethod->invoke(null);
ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
$configurationWarning = (string) file_get_contents($errorLogPath);
unlink($errorLogPath);
$assert(($resource['service.name'] ?? '') === Telemetry::SERVICE_NAME, 'Service name must remain b8im-server.');
$assert(($resource['service.version'] ?? '') === '2026.07.14-test', 'Configured service version was ignored.');
$assert(($resource['deployment.environment.name'] ?? '') === 'qa', 'Configured deployment environment was ignored.');
$assert(
    str_contains($configurationWarning, '[b8im:telemetry] code=invalid_service_name'),
    'Invalid service name did not emit the stable warning code.',
);
$assert(!str_contains($configurationWarning, 'not-allowed'), 'Invalid service name warning leaked configured value.');
$assert(!str_contains($configurationWarning, Telemetry::SERVICE_NAME), 'Invalid service name warning leaked expected value.');

$setEnvironment('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', null);
$setEnvironment('OTEL_EXPORTER_OTLP_ENDPOINT', null);
$endpointMethod = new ReflectionMethod(Telemetry::class, 'endpoint');
$assert(
    $endpointMethod->invoke(null) === 'http://otel-collector:4318/v1/traces',
    'Default OTLP endpoint must target the Collector, not a storage/query backend.',
);

$exporter = new InMemoryExporter();
$provider = TracerProvider::builder()
    ->addSpanProcessor(new SimpleSpanProcessor($exporter))
    ->build();
Telemetry::setProviderForTesting($provider);

$headers = [];
try {
    Telemetry::inSpan(
        'test.safe-span',
        'test.safe-span',
        [
            'b8im.organization' => 7,
            'authorization' => 'Bearer should-never-export',
            'message.content' => 'private message body',
            'db.statement' => 'SELECT * FROM users WHERE password = secret',
            'http.request.body' => 'private request',
            'url.full' => 'https://example.test/?token=secret',
            'file.name' => 'private.pdf',
            'user.email' => 'user@example.test',
        ],
        function () use (&$headers): void {
            $headers = Telemetry::currentTraceHeaders();
            throw new RuntimeException('password=should-never-export');
        },
    );
} catch (RuntimeException) {
}

$assert(
    preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $headers['traceparent'] ?? '') === 1,
    'Current context did not inject a valid sampled W3C traceparent.',
);
$spans = $exporter->getSpans();
$assert(count($spans) === 1, 'Telemetry span was not exported exactly once.');
$span = $spans[0];
$attributes = $span->getAttributes()->toArray();
$assert($span->getStatus()->getCode() === StatusCode::STATUS_ERROR, 'Unhandled exception did not set ERROR.');
$assert(($attributes['error.code'] ?? '') === 'UNHANDLED_RUNTIME_EXCEPTION', 'Stable error.code missing.');
$assert(($attributes['error.type'] ?? '') === RuntimeException::class, 'Stable error.type missing.');
$assert(($attributes['service'] ?? '') === Telemetry::SERVICE_NAME, 'Error service missing.');
$assert(($attributes['operation'] ?? '') === 'test.safe-span', 'Error operation missing.');
$assert(($attributes['retry_count'] ?? -1) === 0, 'Error retry_count missing.');
foreach ([
    'authorization',
    'message.content',
    'db.statement',
    'http.request.body',
    'url.full',
    'file.name',
    'user.email',
] as $forbidden) {
    $assert(!array_key_exists($forbidden, $attributes), "Sensitive attribute leaked: {$forbidden}");
}
$events = $span->getEvents();
$assert(count($events) === 1 && $events[0]->getName() === 'exception', 'Sanitized exception event missing.');
$eventJson = json_encode($events[0]->getAttributes()->toArray(), JSON_THROW_ON_ERROR);
$assert(!str_contains($eventJson, 'should-never-export'), 'Exception event leaked a sensitive value.');
$assert(!Span::getCurrent()->getContext()->isValid(), 'Span context leaked after scope detach.');

Telemetry::setProviderForTesting(null);
foreach ($originalEnvironment as $key => $value) {
    $setEnvironment($key, $value);
}
echo "Telemetry tests passed.\n";
