<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\service\Permission;
use plugin\saimulti\service\tenantPolicy\TenantImPolicyService;
use support\Request;
use support\Response;

final class TenantImPolicyController extends TenantController
{
    #[Permission('读取 IM 运行策略', 'saimulti:tenant:im:policy:read')]
    public function read(): Response
    {
        return $this->success((new TenantImPolicyService())->read((int) $this->organization));
    }

    #[Permission('更新 IM 运行策略', 'saimulti:tenant:im:policy:update')]
    public function update(Request $request): Response
    {
        return $this->success((new TenantImPolicyService())->update(
            (int) $this->organization,
            $request->post(),
            ['type' => 'tenant', 'id' => $this->tenantId, 'ip' => $request->getRealIp()],
        ));
    }
}
