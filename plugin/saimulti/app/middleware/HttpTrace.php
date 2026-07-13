<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\middleware;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\trace\Telemetry;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class HttpTrace implements MiddlewareInterface
{
    public const REQUEST_TRACE_ID = 'b8im.trace_id';

    public function process(Request $request, callable $handler): Response
    {
        $parent = TraceContextPropagator::getInstance()->extract([
            'traceparent' => (string) $request->header('traceparent', ''),
            'tracestate' => (string) $request->header('tracestate', ''),
        ], context: Context::getRoot());
        $operation = $this->operation($request);
        $span = Telemetry::startSpan(
            'HTTP ' . strtoupper($request->method()) . ' ' . $this->endpoint($request),
            $operation,
            [
                'http.request.method' => strtoupper($request->method()),
                'b8im.endpoint' => $this->endpoint($request),
            ],
            SpanKind::KIND_SERVER,
            $parent,
        );
        $scope = $span->storeInContext($parent)->activate();
        $traceId = $span->getContext()->getTraceId();
        if ($span->getContext()->isValid()) {
            $request->properties[self::REQUEST_TRACE_ID] = $traceId;
        }

        try {
            $response = $handler($request);
            $status = $response->getStatusCode();
            $span->setAttribute('http.response.status_code', $status);
            if ($status >= 500) {
                Telemetry::recordError(
                    $span,
                    new \RuntimeException('HTTP server response failed.', $status),
                    $operation,
                );
            }

            return $traceId !== '' ? $response->withHeader('X-Trace-Id', $traceId) : $response;
        } catch (Throwable $exception) {
            if ($this->isCritical($exception)) {
                Telemetry::recordError($span, $exception, $operation);
            }
            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function endpoint(Request $request): string
    {
        if (is_string($request->controller) && $request->controller !== ''
            && is_string($request->action) && $request->action !== '') {
            $class = strrchr($request->controller, '\\');

            return ($class === false ? $request->controller : substr($class, 1)) . '.' . $request->action;
        }

        return 'unmatched';
    }

    private function operation(Request $request): string
    {
        return strtoupper($request->method()) . ' ' . $this->endpoint($request);
    }

    private function isCritical(Throwable $exception): bool
    {
        if (!$exception instanceof ApiException) {
            return true;
        }

        return in_array($exception->getCode(), [401, 403, 41001, 41002, 41003, 42101, 429, 50301], true)
            || $exception->getCode() >= 500;
    }
}
