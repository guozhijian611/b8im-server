<?php

declare(strict_types=1);

namespace plugin\saimulti\app\middleware;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\TrustedCorsPolicy;
use support\Log;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * CORS for public guest APIs (customer-service widget / entry links).
 *
 * Organization is resolved from entry code or guest token inside the controller,
 * not from App-Id. Origin is reflected when well-formed; entry-level origin
 * allowlists are enforced by the business service.
 */
final class PublicGuestCors implements MiddlewareInterface
{
    private const ALLOW_METHODS = 'GET, POST, PUT, DELETE, OPTIONS';
    private const ALLOW_HEADERS = 'Authorization, Content-Type, X-CS-Guest-Token, X-Device-Id, Traceparent, Tracestate';

    public function process(Request $request, callable $handler): Response
    {
        $origin = trim((string) $request->header('Origin', ''));
        $allowedOrigin = $this->normalizeOrigin($origin);

        try {
            if ($request->method() === 'OPTIONS') {
                if ($allowedOrigin === null) {
                    throw new ApiException('Web 预检请求缺少有效 Origin。', 403);
                }
                $response = response('', 204);

                return $this->withCorsHeaders($response, $allowedOrigin);
            }

            $response = $handler($request);
        } catch (ApiException $exception) {
            $response = json([
                'code' => $exception->getCode() ?: 400,
                'message' => $exception->getMessage(),
            ])->withStatus($this->httpStatus($exception));
        } catch (Throwable $exception) {
            Log::error('Public guest API request failed', [
                'path' => $request->path(),
                'message' => $exception->getMessage(),
            ]);
            $response = json(['code' => 500, 'message' => 'Server internal error'])->withStatus(500);
        }

        return $this->withCorsHeaders($response, $allowedOrigin);
    }

    private function normalizeOrigin(string $origin): ?string
    {
        if ($origin === '') {
            return null;
        }

        return TrustedCorsPolicy::normalizeOrigin($origin);
    }

    private function withCorsHeaders(Response $response, ?string $allowedOrigin): Response
    {
        $headers = [
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => self::ALLOW_METHODS,
            'Access-Control-Allow-Headers' => self::ALLOW_HEADERS,
            'Access-Control-Expose-Headers' => 'X-Trace-Id',
            'Access-Control-Max-Age' => '600',
        ];
        if ($allowedOrigin !== null) {
            $headers['Access-Control-Allow-Origin'] = $allowedOrigin;
        }

        return $response->withHeaders($headers);
    }

    private function httpStatus(ApiException $exception): int
    {
        return match ($exception->getCode()) {
            401 => 401,
            403, 41003, 42101 => 403,
            404 => 404,
            409 => 409,
            422 => 422,
            429 => 429,
            50301 => 503,
            default => 400,
        };
    }
}
