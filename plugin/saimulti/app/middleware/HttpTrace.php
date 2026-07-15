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
use JsonException;
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
            $businessCode = $this->businessCode($response);
            if ($businessCode !== null) {
                $span->setAttribute('b8im.response.code', $businessCode);
            }
            $businessType = $this->businessType($response);
            if ($businessType !== null) {
                $span->setAttribute('b8im.response.type', $businessType);
            }

            if ($businessCode !== null && $businessCode !== 200) {
                $exception = $response->exception()
                    ?? new ApiException('Business response failed.', $businessCode);
                Telemetry::recordError(
                    $span,
                    $exception,
                    $operation,
                    errorCode: $businessCode,
                );
            } elseif ($status >= 500) {
                Telemetry::recordError(
                    $span,
                    $response->exception()
                        ?? new \RuntimeException('HTTP server response failed.', $status),
                    $operation,
                    errorCode: $status,
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

    private function businessCode(Response $response): ?int
    {
        $payload = $this->jsonPayload($response);
        $code = $payload['code'] ?? null;

        return is_int($code) || (is_string($code) && preg_match('/^-?[0-9]+$/D', $code) === 1)
            ? (int) $code
            : null;
    }

    private function businessType(Response $response): ?string
    {
        $type = $this->jsonPayload($response)['type'] ?? null;

        return is_string($type) && preg_match('/^[a-zA-Z0-9._-]{1,32}$/D', $type) === 1
            ? $type
            : null;
    }

    /** @return array<string, mixed> */
    private function jsonPayload(Response $response): array
    {
        $body = $response->rawBody();
        if ($body === '' || strlen($body) > 65536 || $body[0] !== '{') {
            return [];
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }
}
