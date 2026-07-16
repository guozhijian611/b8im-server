<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\ModuleServiceFactory;
use support\Request;
use support\Response;

final class ClientConfigController extends WebController
{
    public function index(Request $request): Response
    {
        $clientFamily = trim((string) $request->get('client_family', ''));
        $authenticatedFamily = trim((string) ($this->webIdentity['client_family'] ?? ''));
        if (!in_array($clientFamily, ['web', 'app', 'desktop'], true)
            || !hash_equals($authenticatedFamily, $clientFamily)) {
            throw new ApiException('client_family 与认证客户端形态不一致。', 422);
        }

        return $this->success(ModuleServiceFactory::clientConfigProjection()->project(
            $this->organization,
            $this->deploymentId,
            $clientFamily,
        ));
    }
}
