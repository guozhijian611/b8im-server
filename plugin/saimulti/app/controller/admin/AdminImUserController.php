<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\app\validate\system\ImUserValidate;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\imUser\ImUserManagementService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

final class AdminImUserController extends AdminController
{
    public function __construct()
    {
        $this->validate = new ImUserValidate();
        parent::__construct();
    }

    #[Permission('平台 IM 用户列表', 'saimulti:admin:im:user:index')]
    public function index(Request $request): Response
    {
        return $this->success((new ImUserManagementService())->index($request->get()));
    }

    #[Permission('平台 IM 用户读取', 'saimulti:admin:im:user:read')]
    public function read(Request $request): Response
    {
        return $this->success((new ImUserManagementService())->read($this->id($request)));
    }

    #[Permission('平台 IM 用户创建', 'saimulti:admin:im:user:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('adminSave', $data);
        return $this->success((new ImUserManagementService())->create(
            (int) $data['organization'],
            $data,
            $this->actor($request),
        ));
    }

    #[Permission('平台 IM 用户更新', 'saimulti:admin:im:user:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        return $this->success((new ImUserManagementService())->update(
            (int) $data['id'],
            null,
            $data,
            $this->actor($request),
        ));
    }

    #[Permission('平台 IM 用户状态变更', 'saimulti:admin:im:user:status')]
    public function status(Request $request): Response
    {
        $data = $request->post();
        $this->validate('status', $data);
        return $this->success((new ImUserManagementService())->setStatus(
            (int) $data['id'],
            null,
            (int) $data['status'],
            $this->actor($request),
        ));
    }

    #[Permission('平台 IM 用户密码重置', 'saimulti:admin:im:user:reset')]
    public function reset(Request $request): Response
    {
        $data = $request->post();
        $this->validate('reset', $data);
        return $this->success((new ImUserManagementService())->resetPassword(
            (int) $data['id'],
            null,
            (string) $data['password'],
            $this->actor($request),
        ));
    }

    #[Permission('平台读取 IM 用户席位', 'saimulti:admin:im:user:quota:read')]
    public function quota(Request $request): Response
    {
        return $this->success((new ImUserManagementService())->quota($this->organization($request)));
    }

    #[Permission('平台配置 IM 用户席位', 'saimulti:admin:im:user:quota:update')]
    public function updateQuota(Request $request): Response
    {
        $data = $request->post();
        $this->validate('quota', $data);
        return $this->success((new ImUserManagementService())->updateQuota(
            (int) $data['organization'],
            (int) $data['quota_value'],
            $this->actor($request),
        ));
    }

    private function id(Request $request): int
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            throw new ApiException('IM 用户编号无效。', 422);
        }
        return $id;
    }

    private function organization(Request $request): int
    {
        $organization = (int) $request->input('organization', 0);
        if ($organization <= 0) {
            throw new ApiException('机构编号无效。', 422);
        }
        return $organization;
    }

    /** @return array{type:string,id:int,username:string,ip:string} */
    private function actor(Request $request): array
    {
        return ['type' => 'admin', 'id' => $this->adminId, 'username' => $this->adminName, 'ip' => $request->getRealIp()];
    }
}
