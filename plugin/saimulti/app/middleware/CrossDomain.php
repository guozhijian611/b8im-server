<?php

declare(strict_types=1);

namespace plugin\saimulti\app\middleware;

use plugin\saimulti\service\TrustedCorsPolicy;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class CrossDomain implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $path = '/' . ltrim($request->path(), '/');
        if (!$this->appliesTo($path)) {
            return $handler($request);
        }

        $origin = (string) $request->header('Origin', '');
        if ($origin === '') {
            return $request->method() === 'OPTIONS'
                ? json(['code' => 403, 'message' => 'CORS Origin 缺失。'])->withStatus(403)
                : $handler($request);
        }

        $policy = new TrustedCorsPolicy();
        $allowedOrigin = $policy->allowedOrigin($origin);
        if ($allowedOrigin === null) {
            return json(['code' => 403, 'message' => 'CORS Origin 未授权。'])->withStatus(403)
                ->withHeader('Vary', 'Origin');
        }

        if ($request->method() === 'OPTIONS') {
            $requestedMethod = (string) $request->header('Access-Control-Request-Method', '');
            $requestedHeaders = (string) $request->header('Access-Control-Request-Headers', '');
            if (!$policy->allowsMethod($requestedMethod) || !$policy->allowsRequestedHeaders($requestedHeaders)) {
                return json(['code' => 403, 'message' => 'CORS 预检请求未授权。'])->withStatus(403)
                    ->withHeader('Vary', 'Origin');
            }
            return $this->withCorsHeaders(response('', 204), $allowedOrigin);
        }

        return $this->withCorsHeaders($handler($request), $allowedOrigin);
    }

    private function appliesTo(string $path): bool
    {
        return str_starts_with($path, '/saimulti/')
            && $path !== '/saimulti/appInfo'
            && !str_starts_with($path, '/saimulti/web/');
    }

    private function withCorsHeaders(Response $response, string $allowedOrigin): Response
    {
        return $response->withHeaders([
            'Vary' => 'Origin',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Origin' => $allowedOrigin,
            'Access-Control-Allow-Methods' => implode(', ', TrustedCorsPolicy::ALLOWED_METHODS),
            'Access-Control-Allow-Headers' => implode(', ', array_map(
                static fn (string $header): string => implode('-', array_map('ucfirst', explode('-', $header))),
                TrustedCorsPolicy::ALLOWED_HEADERS,
            )),
            'Access-Control-Max-Age' => '600',
        ]);
    }
}
