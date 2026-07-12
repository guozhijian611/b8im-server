<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\Permission;
use plugin\saimulti\service\routing\RoutingConfigService;
use support\Request;
use support\Response;

final class AdminRoutingController extends AdminController
{
    #[Permission('读取接入线路策略', 'saimulti:admin:routing:read')]
    public function read(Request $request): Response
    {
        return $this->success((new RoutingConfigService())->read(
            $this->organization($request->input('organization')),
            (string) $request->input('client_family', ''),
        ));
    }

    #[Permission('发布接入线路策略', 'saimulti:admin:routing:publish')]
    public function publish(Request $request): Response
    {
        return $this->success((new RoutingConfigService())->publish(
            $request->post(),
            ['type' => 'admin', 'id' => $this->adminId, 'ip' => $request->getRealIp()],
        ));
    }

    private function organization(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new ApiException('organization 必须是正整数。', 422);
        }

        return $value;
    }
}
