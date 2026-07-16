<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\service\WebOrganizationResolver;
use plugin\saimulti\service\web\WebQrLoginService;
use support\Request;
use support\Response;

final class QrLoginController extends WebController
{
    public function scan(Request $request): Response
    {
        return $this->success((new WebQrLoginService())->scan(
            (new WebOrganizationResolver())->fromRequest($request),
            $this->webIdentity,
            (string) $request->input('qr_id', ''),
            (string) $request->input('scan_token', ''),
            $request->getRealIp(),
        ));
    }

    public function confirm(Request $request): Response
    {
        return $this->success((new WebQrLoginService())->confirm(
            (new WebOrganizationResolver())->fromRequest($request),
            $this->webIdentity,
            (string) $request->input('qr_id', ''),
            $request->getRealIp(),
        ));
    }
}
