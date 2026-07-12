<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\service\Permission;
use plugin\saimulti\service\routing\RoutingConfigService;
use support\Request;
use support\Response;

final class TenantRoutingController extends TenantController
{
    #[Permission('读取接入线路策略', 'saimulti:tenant:routing:read')]
    public function read(Request $request): Response
    {
        return $this->success((new RoutingConfigService())->read(
            (int) $this->organization,
            (string) $request->input('client_family', ''),
        ));
    }
}
