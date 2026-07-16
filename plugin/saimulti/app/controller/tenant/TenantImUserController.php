<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\app\validate\system\ImUserValidate;
use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\imUser\ImUserManagementService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

final class TenantImUserController extends TenantController
{
    public function __construct()
    {
        $this->validate = new ImUserValidate();
        parent::__construct();
    }

    #[Permission('租户 IM 用户列表', 'saimulti:tenant:im:user:index')]
    public function index(Request $request): Response
    {
        return $this->success((new ImUserManagementService())->index($request->get(), (int) $this->organization));
    }

    #[Permission('租户 IM 用户读取', 'saimulti:tenant:im:user:read')]
    public function read(Request $request): Response
    {
        return $this->success((new ImUserManagementService())->read($this->id($request), (int) $this->organization));
    }

    #[Permission('租户 IM 用户创建', 'saimulti:tenant:im:user:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('tenantSave', $data);
        unset($data['organization']);
        return $this->success((new ImUserManagementService())->create(
            (int) $this->organization,
            $data,
            $this->actor($request),
        ));
    }

    #[Permission('租户 IM 用户更新', 'saimulti:tenant:im:user:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        unset($data['organization']);
        return $this->success((new ImUserManagementService())->update(
            (int) $data['id'],
            (int) $this->organization,
            $data,
            $this->actor($request),
        ));
    }

    #[Permission('租户 IM 用户状态变更', 'saimulti:tenant:im:user:status')]
    public function status(Request $request): Response
    {
        $data = $request->post();
        $this->validate('status', $data);
        return $this->success((new ImUserManagementService())->setStatus(
            (int) $data['id'],
            (int) $this->organization,
            (int) $data['status'],
            $this->actor($request),
        ));
    }

    #[Permission('租户 IM 用户密码重置', 'saimulti:tenant:im:user:reset')]
    public function reset(Request $request): Response
    {
        $data = $request->post();
        $this->validate('reset', $data);
        return $this->success((new ImUserManagementService())->resetPassword(
            (int) $data['id'],
            (int) $this->organization,
            (string) $data['password'],
            $this->actor($request),
        ));
    }

    #[Permission('租户读取 IM 用户席位', 'saimulti:tenant:im:user:quota:read')]
    public function quota(): Response
    {
        return $this->success((new ImUserManagementService())->quota((int) $this->organization));
    }

    private function id(Request $request): int
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            throw new ApiException('IM 用户编号无效。', 422);
        }
        return $id;
    }

    /** @return array{type:string,id:int,username:string,ip:string} */
    private function actor(Request $request): array
    {
        return ['type' => 'tenant', 'id' => $this->tenantId, 'username' => $this->tenantName, 'ip' => $request->getRealIp()];
    }
}
