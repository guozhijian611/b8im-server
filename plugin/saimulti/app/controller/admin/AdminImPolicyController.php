<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\Permission;
use plugin\saimulti\service\tenantPolicy\TenantImPolicyService;
use support\Request;
use support\Response;

final class AdminImPolicyController extends AdminController
{
    #[Permission('平台读取租户 IM 运行策略', 'saimulti:admin:im:policy:read')]
    public function read(Request $request): Response
    {
        return $this->success((new TenantImPolicyService())->read($this->organization($request)));
    }

    #[Permission('平台更新租户 IM 运行策略', 'saimulti:admin:im:policy:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        unset($data['organization']);

        return $this->success((new TenantImPolicyService())->update(
            $this->organization($request),
            $data,
            ['type' => 'admin', 'id' => $this->adminId, 'ip' => $request->getRealIp()],
        ));
    }

    private function organization(Request $request): int
    {
        $value = $request->input('organization');
        if (is_int($value)) {
            $organization = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $organization = (int) $value;
        } else {
            throw new ApiException('organization 必须是正整数。', 422);
        }
        if ($organization <= 0) {
            throw new ApiException('organization 必须是正整数。', 422);
        }

        return $organization;
    }
}
