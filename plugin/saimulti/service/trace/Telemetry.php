<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\trace;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use plugin\saimulti\exception\ApiException;
use Throwable;

/**
 * Process-wide OpenTelemetry runtime for Webman/Workerman long-lived workers.
 *
 * The provider and bounded batch queue live for the worker lifetime. Request
 * contexts never live here: every request/operation must activate and detach
 * its own scope in a finally block.
 */
final class Telemetry
{
    public const SERVICE_NAME = 'b8im-server';

    private const INSTRUMENTATION_NAME = 'b8im.server';

    private static ?TracerProviderInterface $provider = null;

    private static bool $managedProvider = false;

    private static bool $shutdownRegistered = false;

    private static bool $initializing = false;

    private static ?PeriodicFlushScheduler $flushScheduler = null;

    public static function tracer(): TracerInterface
    {
        return self::provider()->getTracer(self::INSTRUMENTATION_NAME, self::serviceVersion());
    }

    public static function provider(): TracerProviderInterface
    {
        if (self::$provider !== null) {
            return self::$provider;
        }
        if (self::$initializing) {
            return new NoopTracerProvider();
        }

        self::$initializing = true;
        try {
            self::$provider = self::buildProvider();
            self::$managedProvider = self::$provider instanceof TracerProvider;
            if (self::$managedProvider) {
                self::startPeriodicFlush();
            }
            if (self::$managedProvider && !self::$shutdownRegistered) {
                register_shutdown_function(self::shutdown(...));
                self::$shutdownRegistered = true;
            }
        } catch (Throwable $exception) {
            // Telemetry initialization is never allowed to make a worker fail.
            self::$provider = new NoopTracerProvider();
            self::$managedProvider = false;
            self::warn('initialization_failed', $exception);
        } finally {
            self::$initializing = false;
        }

        return self::$provider;
    }

    /**
     * @param array<string, bool|int|float|string|array|null> $attributes
     */
    public static function startSpan(
        string $name,
        string $operation,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
        ContextInterface|false|null $parent = null,
    ): SpanInterface {
        try {
            return self::tracer()
                ->spanBuilder($name)
                ->setSpanKind($kind)
                ->setParent($parent)
                ->setAttributes(self::safeAttributes($attributes + [
                    'b8im.operation' => $operation,
                ]))
                ->startSpan();
        } catch (Throwable $exception) {
            self::warn('span_start_failed', $exception);

            return (new NoopTracerProvider())->getTracer(self::INSTRUMENTATION_NAME)
                ->spanBuilder($name)
                ->startSpan();
        }
    }

    /**
     * @template T
     * @param array<string, bool|int|float|string|array|null> $attributes
     * @param Closure(SpanInterface): T $callback
     * @return T
     */
    public static function inSpan(
        string $name,
        string $operation,
        array $attributes,
        Closure $callback,
        bool $apiExceptionsAreErrors = true,
        int $kind = SpanKind::KIND_INTERNAL,
    ): mixed {
        $span = self::startSpan($name, $operation, $attributes, $kind, Context::getCurrent());
        $scope = $span->storeInContext(Context::getCurrent())->activate();
        try {
            return $callback($span);
        } catch (Throwable $exception) {
            if ($apiExceptionsAreErrors || !$exception instanceof ApiException) {
                self::recordError($span, $exception, $operation);
            }
            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /** @param array<string, bool|int|float|string|null> $context */
    public static function recordError(
        SpanInterface $span,
        Throwable $exception,
        string $operation,
        int $retryCount = 0,
        string|int|null $errorCode = null,
        ?string $diagnosticMessage = null,
        array $context = [],
    ): void {
        try {
            $attributes = [
                'error.code' => $errorCode === null
                    ? self::errorCode($exception)
                    : self::safeText((string) $errorCode, 64),
                'error.type' => $exception::class,
                'service' => self::SERVICE_NAME,
                'operation' => self::safeText($operation, 160),
                'retry_count' => max(0, $retryCount),
            ];
            if ($diagnosticMessage !== null && trim($diagnosticMessage) !== '') {
                $attributes['b8im.response.message'] = TraceDataPolicy::sanitizeDiagnosticText($diagnosticMessage);
            }
            foreach ($context as $key => $value) {
                $key = (string) $key;
                if (!str_starts_with($key, 'b8im.response.')
                    || TraceDataPolicy::isSensitiveKey($key)
                    || (!is_scalar($value) && $value !== null)) {
                    continue;
                }
                $attributes[$key] = is_string($value)
                    ? TraceDataPolicy::sanitizeDiagnosticText($value)
                    : $value;
            }
            // Deliberately do not call recordException(): exception messages and
            // stack traces can contain SQL values, request bodies or secrets.
            $span->setStatus(StatusCode::STATUS_ERROR)
                ->setAttributes($attributes)
                ->addEvent('exception', $attributes);
        } catch (Throwable $telemetryFailure) {
            self::warn('error_record_failed', $telemetryFailure);
        }
    }

    /** @return array{traceparent?: string, tracestate?: string} */
    public static function currentTraceHeaders(): array
    {
        try {
            $carrier = [];
            TraceContextPropagator::getInstance()->inject($carrier, context: Context::getCurrent());
            $traceparent = $carrier['traceparent'] ?? null;
            if (!is_string($traceparent)
                || strlen($traceparent) !== 55
                || preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/', $traceparent) !== 1) {
                return [];
            }

            $result = ['traceparent' => $traceparent];
            $tracestate = $carrier['tracestate'] ?? null;
            if (is_string($tracestate) && $tracestate !== '' && strlen($tracestate) <= 512) {
                $result['tracestate'] = $tracestate;
            }

            return $result;
        } catch (Throwable $exception) {
            self::warn('context_inject_failed', $exception);

            return [];
        }
    }

    public static function shutdown(): void
    {
        self::stopPeriodicFlush();
        if (!self::$managedProvider || !self::$provider instanceof TracerProvider) {
            return;
        }
        try {
            self::$provider->shutdown();
        } catch (Throwable $exception) {
            self::warn('shutdown_failed', $exception);
        } finally {
            self::$managedProvider = false;
        }
    }

    /** @internal tests only */
    public static function setProviderForTesting(?TracerProviderInterface $provider): void
    {
        self::stopPeriodicFlush();
        if (self::$managedProvider && self::$provider instanceof TracerProvider) {
            try {
                self::$provider->shutdown();
            } catch (Throwable) {
            }
        }
        self::$provider = $provider;
        self::$managedProvider = false;
        self::$initializing = false;
    }

    private static function startPeriodicFlush(): void
    {
        if (!self::$provider instanceof TracerProvider) {
            return;
        }
        $delayMillis = self::intEnvironment('OTEL_BSP_SCHEDULE_DELAY', 5_000, 100, 60_000);
        self::$flushScheduler ??= new PeriodicFlushScheduler();
        self::$flushScheduler->start(
            $delayMillis / 1_000,
            static function (): void {
                try {
                    if (self::$managedProvider && self::$provider instanceof TracerProvider) {
                        self::$provider->forceFlush();
                    }
                } catch (Throwable) {
                    // ResilientSpanExporter owns sanitized/rate-limited alerts.
                }
            },
        );
    }

    private static function stopPeriodicFlush(): void
    {
        self::$flushScheduler?->stop();
        self::$flushScheduler = null;
    }

    private static function buildProvider(): TracerProviderInterface
    {
        if (!self::boolEnvironment('OTEL_TRACES_ENABLED', true)
            || self::boolEnvironment('OTEL_SDK_DISABLED', false)) {
            return new NoopTracerProvider();
        }

        // The SDK internal writer includes exception messages and stack traces.
        // Export failures are handled by ResilientSpanExporter with a sanitized,
        // rate-limited warning instead.
        Logging::disable();

        $endpoint = self::endpoint();
        $timeoutMillis = self::intEnvironment('OTEL_EXPORTER_OTLP_TRACES_TIMEOUT', 250, 50, 2_000);
        $httpFactory = new HttpFactory();
        $transport = (new PsrTransportFactory(
            new Client([
                'connect_timeout' => min(0.2, $timeoutMillis / 1000),
                'timeout' => $timeoutMillis / 1000,
                'http_errors' => false,
            ]),
            $httpFactory,
            $httpFactory,
        ))->create(
            $endpoint,
            ContentTypes::PROTOBUF,
            [],
            null,
            $timeoutMillis / 1000,
            0,
            0,
        );
        $maxQueueSize = self::intEnvironment('OTEL_BSP_MAX_QUEUE_SIZE', 2_048, 64, 16_384);
        $maxExportBatchSize = min(
            $maxQueueSize,
            self::intEnvironment('OTEL_BSP_MAX_EXPORT_BATCH_SIZE', 128, 1, 512),
        );
        $processor = new BatchSpanProcessor(
            new ResilientSpanExporter(new SpanExporter($transport)),
            \OpenTelemetry\API\Common\Time\Clock::getDefault(),
            $maxQueueSize,
            self::intEnvironment('OTEL_BSP_SCHEDULE_DELAY', 5_000, 100, 60_000),
            $timeoutMillis,
            $maxExportBatchSize,
            true,
        );
        $resource = ResourceInfo::create(Attributes::create(self::resourceAttributes()));
        $ratio = self::floatEnvironment('OTEL_TRACES_SAMPLER_ARG', 1.0, 0.0, 1.0);

        return TracerProvider::builder()
            ->setResource($resource)
            ->setSampler(new ParentBased(new TraceIdRatioBasedSampler($ratio)))
            ->addSpanProcessor($processor)
            ->build();
    }

    /** @return array<string, string> */
    private static function resourceAttributes(): array
    {
        return [
            'service.name' => self::serviceName(),
            'service.version' => self::serviceVersion(),
            'service.namespace' => 'b8im',
            'deployment.environment.name' => self::safeText(
                self::environment(
                    'OTEL_DEPLOYMENT_ENVIRONMENT',
                    self::environment('APP_ENV', config('app.debug', false) ? 'development' : 'production'),
                ),
                64,
            ),
            'b8im.deployment_id' => self::safeText(
                self::environment('DEPLOYMENT_ID', 'b8im-local'),
                128,
            ),
        ];
    }

    private static function serviceName(): string
    {
        $configured = self::environment('OTEL_SERVICE_NAME', self::SERVICE_NAME);
        if ($configured !== self::SERVICE_NAME) {
            // Configuration values are not logged: an operator may
            // accidentally place a secret in this variable.
            error_log('[b8im:telemetry] code=invalid_service_name');
        }

        // Jaeger service cardinality is part of the deployment contract. This
        // process is never allowed to announce itself under an arbitrary name.
        return self::SERVICE_NAME;
    }

    private static function serviceVersion(): string
    {
        return self::safeText(self::environment('OTEL_SERVICE_VERSION', 'dev'), 64);
    }

    private static function endpoint(): string
    {
        $endpoint = self::environment('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', '');
        if ($endpoint === '') {
            $endpoint = rtrim(self::environment('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://otel-collector:4318'), '/')
                . '/v1/traces';
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('OTLP traces endpoint is invalid.');
        }

        return $endpoint;
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private static function safeAttributes(array $attributes): array
    {
        $safe = [];
        foreach ($attributes as $key => $value) {
            $key = (string) $key;
            if ($key === '' || TraceDataPolicy::isSensitiveKey($key) || $value === null) {
                continue;
            }
            if (is_string($value)) {
                $value = self::safeText($value, 256);
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    private static function errorCode(Throwable $exception): string
    {
        if ($exception instanceof ApiException && $exception->getCode() !== 0) {
            return (string) $exception->getCode();
        }

        $short = (new \ReflectionClass($exception))->getShortName();
        $code = strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));

        return $code !== '' ? 'UNHANDLED_' . $code : 'UNHANDLED_EXCEPTION';
    }

    private static function safeText(string $value, int $maxLength): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));

        return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
    }

    private static function environment(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null || trim((string) $value) === ''
            ? $default
            : trim((string) $value);
    }

    private static function boolEnvironment(string $key, bool $default): bool
    {
        $value = self::environment($key, $default ? 'true' : 'false');
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    private static function intEnvironment(string $key, int $default, int $min, int $max): int
    {
        $value = self::environment($key, (string) $default);
        $parsed = preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;

        return max($min, min($max, $parsed));
    }

    private static function floatEnvironment(string $key, float $default, float $min, float $max): float
    {
        $value = self::environment($key, (string) $default);
        $parsed = is_numeric($value) ? (float) $value : $default;

        return max($min, min($max, $parsed));
    }

    private static function warn(string $code, Throwable $exception): void
    {
        error_log(sprintf(
            '[b8im:telemetry] %s type=%s',
            $code,
            $exception::class,
        ));
    }
}
