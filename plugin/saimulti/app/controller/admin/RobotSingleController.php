<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\RobotSingleService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('robot_single', 'server', 'robot_single.admin.manage')]
final class RobotSingleController extends AdminController
{
    #[Permission('平台机器人列表', 'saimulti:admin:robot_single:index')]
    public function index(Request $request): Response
    {
        return $this->success((new RobotSingleService())->robotList(0, $request->get(), true));
    }

    #[Permission('平台机器人详情', 'saimulti:admin:robot_single:read')]
    public function read(Request $request): Response
    {
        return $this->success((new RobotSingleService())->robotRead(0, $this->id($request), true));
    }

    #[Permission('平台规则列表', 'saimulti:admin:robot_single:read')]
    public function ruleIndex(Request $request): Response
    {
        return $this->success((new RobotSingleService())->ruleList(0, $request->get(), true));
    }

    #[Permission('平台知识库列表', 'saimulti:admin:robot_single:read')]
    public function kbIndex(Request $request): Response
    {
        return $this->success((new RobotSingleService())->kbList(0, $request->get(), true));
    }

    private function id(Request $request): int
    {
        $id = $request->input('id') ?? $request->get('id');
        if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
            throw new ApiException('编号无效。', 422);
        }

        return (int) $id;
    }
}
