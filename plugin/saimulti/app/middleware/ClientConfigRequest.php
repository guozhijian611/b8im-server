<?php

declare(strict_types=1);

namespace plugin\saimulti\app\middleware;

use plugin\saimulti\exception\ApiException;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class ClientConfigRequest implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $clientFamily = trim((string) $request->get('client_family', ''));

        return match ($clientFamily) {
            'app' => (new AppClientRequest())->process($request, $handler),
            'web', 'desktop' => (new WebCors())->process($request, $handler),
            default => throw new ApiException('client_family 无效。', 422),
        };
    }
}
