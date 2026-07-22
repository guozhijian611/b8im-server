<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\app\validate\system\TenantAccountPolicyValidate;
use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\Permission;
use plugin\saimulti\service\web\TenantAccountPolicyService;
use support\Request;
use support\Response;

final class TenantAccountPolicyController extends TenantController
{
    #[Permission('读取账号注册策略', 'saimulti:tenant:account:policy:read')]
    public function read(): Response
    {
        return $this->success((new TenantAccountPolicyService())->read((int) $this->organization));
    }

    #[Permission('更新账号注册策略', 'saimulti:tenant:account:policy:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $validator = (new TenantAccountPolicyValidate())->scene('update');
        if (!$validator->check($data)) {
            throw new ApiException((string) $validator->getError(), 422);
        }

        return $this->success((new TenantAccountPolicyService())->update((int) $this->organization, $data));
    }
}
