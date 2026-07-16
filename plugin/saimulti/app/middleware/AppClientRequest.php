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

final class AppClientRequest implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            if (trim((string) $request->header('Origin', '')) !== '') {
                throw new ApiException('原生 App 接口不接受浏览器 Origin。', 403);
            }
            if ($request->method() === 'OPTIONS') {
                return response('', 204);
            }
            (new WebOrganizationResolver())->fromRequest($request);

            return $handler($request);
        } catch (ApiException $exception) {
            return json([
                'code' => $exception->getCode() ?: 400,
                'message' => $exception->getMessage(),
            ])->withStatus($this->httpStatus($exception));
        } catch (Throwable $exception) {
            Log::error('App API request failed', [
                'path' => $request->path(),
                'message' => $exception->getMessage(),
            ]);

            return json(['code' => 500, 'message' => 'Server internal error'])->withStatus(500);
        }
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
