<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\OpenController;
use plugin\saimulti\service\WebOrganizationResolver;
use plugin\saimulti\service\web\WebQrLoginService;
use support\Request;
use support\Response;

final class WebQrLoginController extends OpenController
{
    public function create(Request $request): Response
    {
        return $this->success((new WebQrLoginService())->create(
            (new WebOrganizationResolver())->fromRequest($request),
            (string) $request->input('device_id', ''),
            (string) $request->header('Origin', ''),
        ));
    }

    public function poll(Request $request): Response
    {
        return $this->success((new WebQrLoginService())->poll(
            (new WebOrganizationResolver())->fromRequest($request),
            (string) $request->input('qr_id', ''),
            (string) $request->input('browser_token', ''),
            (string) $request->input('device_id', ''),
            $request->getRealIp(),
        ));
    }

    public function cancel(Request $request): Response
    {
        return $this->success((new WebQrLoginService())->cancel(
            (new WebOrganizationResolver())->fromRequest($request),
            (string) $request->input('qr_id', ''),
            (string) $request->input('browser_token', ''),
            (string) $request->input('device_id', ''),
        ));
    }
}
