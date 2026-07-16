<?php

declare(strict_types=1);

namespace plugin\saimulti\app\middleware;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\WebOrganizationResolver;
use support\Log;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class WebCors implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $origin = trim((string) $request->header('Origin', ''));
        $allowedOrigin = '';
        try {
            $resolver = new WebOrganizationResolver();
            if ($request->method() === 'OPTIONS') {
                $allowedOrigin = $resolver->assertRegisteredOrigin($origin);
                $response = response('', 204);
                return $this->withCorsHeaders($response, $origin, $allowedOrigin);
            }

            $organization = $resolver->fromRequest($request);
            $allowedOrigin = $origin === '' ? '' : $resolver->registeredWebOrigin($organization);
            if ($origin !== '' && !hash_equals($allowedOrigin, strtolower($origin))) {
                throw new ApiException('当前 Origin 未在目标部署登记。', 403);
            }
            $response = $handler($request);
        } catch (ApiException $exception) {
            $response = json([
                'code' => $exception->getCode() ?: 400,
                'message' => $exception->getMessage(),
            ])->withStatus($this->httpStatus($exception));
        } catch (Throwable $exception) {
            Log::error('Web API request failed', [
                'path' => $request->path(),
                'message' => $exception->getMessage(),
            ]);
            $response = json(['code' => 500, 'message' => 'Server internal error'])->withStatus(500);
        }

        return $this->withCorsHeaders($response, $origin, $allowedOrigin);
    }

    private function withCorsHeaders(Response $response, string $origin, string $allowedOrigin): Response
    {
        $headers = [
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, App-Id, X-Device-Id, X-CS-Guest-Token, Traceparent, Tracestate',
            'Access-Control-Expose-Headers' => 'X-Trace-Id',
            'Access-Control-Max-Age' => '600',
        ];
        if ($origin !== '' && $allowedOrigin !== '' && hash_equals($allowedOrigin, strtolower($origin))) {
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
