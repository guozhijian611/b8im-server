<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\OpenController;
use plugin\saimulti\service\WebOrganizationResolver;
use plugin\saimulti\service\web\WebRegistrationService;
use support\Request;
use support\Response;

final class WebAccountController extends OpenController
{
    public function policy(Request $request): Response
    {
        return $this->success((new WebRegistrationService())->accountPolicy(
            (new WebOrganizationResolver())->fromRequest($request),
        ));
    }

    public function register(Request $request): Response
    {
        return $this->success((new WebRegistrationService())->register(
            (new WebOrganizationResolver())->fromRequest($request),
            $request->post(),
            $request->getRealIp(),
        ));
    }
}
