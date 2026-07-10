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
        if ($clientFamily !== 'web') {
            throw new ApiException('Web 客户端只允许 client_family=web。', 422);
        }

        return $this->success(ModuleServiceFactory::clientConfigProjection()->project(
            $this->organization,
            $this->deploymentId,
            $clientFamily,
        ));
    }
}
