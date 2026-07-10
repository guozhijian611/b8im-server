<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\adminIm\AdminImOperationsService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

final class AdminImOperationsController extends AdminController
{
    #[Permission('IM 运行总览', 'saimulti:admin:im:overview')]
    public function overview(): Response
    {
        return $this->success((new AdminImOperationsService())->overview());
    }

    #[Permission('IM 用户列表', 'saimulti:admin:im:user:index')]
    public function users(Request $request): Response
    {
        return $this->success((new AdminImOperationsService())->users($request->get()));
    }

    #[Permission('IM 设备列表', 'saimulti:admin:im:device:index')]
    public function devices(Request $request): Response
    {
        return $this->success((new AdminImOperationsService())->devices($request->get()));
    }

    #[Permission('IM 会话列表', 'saimulti:admin:im:session:index')]
    public function sessions(Request $request): Response
    {
        return $this->success((new AdminImOperationsService())->sessions($request->get()));
    }

    #[Permission('IM 登录审计列表', 'saimulti:admin:im:audit:index')]
    public function loginAudits(Request $request): Response
    {
        return $this->success((new AdminImOperationsService())->loginAudits($request->get()));
    }

    #[Permission('IM 设备状态变更', 'saimulti:admin:im:device:status')]
    public function deviceStatus(Request $request): Response
    {
        return $this->success((new AdminImOperationsService())->setDeviceStatus(
            $this->positiveInteger($request->input('id'), '设备记录'),
            $this->positiveInteger($request->input('status'), '设备状态'),
            $this->actor($request),
        ));
    }

    #[Permission('IM 会话撤销', 'saimulti:admin:im:session:revoke')]
    public function revokeSession(Request $request): Response
    {
        return $this->success((new AdminImOperationsService())->revokeSession(
            $this->positiveInteger($request->input('id'), '会话记录'),
            $this->actor($request),
        ));
    }

    /** @return array{id: int, username: string, ip: string} */
    private function actor(Request $request): array
    {
        return [
            'id' => $this->adminId,
            'username' => $this->adminName,
            'ip' => $request->getRealIp(),
        ];
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $parsed = (int) $value;
        } else {
            throw new ApiException($label . '编号无效。', 422);
        }

        if ($parsed <= 0) {
            throw new ApiException($label . '编号无效。', 422);
        }

        return $parsed;
    }
}
