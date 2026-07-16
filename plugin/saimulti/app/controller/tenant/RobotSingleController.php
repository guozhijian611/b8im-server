<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\RobotSingleService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('robot_single', 'server', 'robot_single.tenant.manage')]
final class RobotSingleController extends TenantController
{
    #[Permission('租户机器人列表', 'saimulti:tenant:robot_single:index')]
    public function index(Request $request): Response
    {
        return $this->success((new RobotSingleService())->robotList((int) $this->organization, $request->get(), false));
    }

    #[Permission('租户机器人详情', 'saimulti:tenant:robot_single:index')]
    public function read(Request $request): Response
    {
        return $this->success((new RobotSingleService())->robotRead((int) $this->organization, $this->id($request), false));
    }

    #[Permission('新增机器人', 'saimulti:tenant:robot_single:save')]
    public function save(Request $request): Response
    {
        return $this->success((new RobotSingleService())->robotCreate(
            (int) $this->organization,
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('更新机器人', 'saimulti:tenant:robot_single:update')]
    public function update(Request $request): Response
    {
        return $this->success((new RobotSingleService())->robotUpdate(
            (int) $this->organization,
            $this->id($request),
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('删除机器人', 'saimulti:tenant:robot_single:destroy')]
    public function destroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new RobotSingleService())->robotDelete(
                (int) $this->organization,
                $this->ids($request),
                $this->tenantId,
            ),
        ]);
    }

    #[Permission('规则列表', 'saimulti:tenant:robot_single:rule')]
    public function ruleIndex(Request $request): Response
    {
        return $this->success((new RobotSingleService())->ruleList((int) $this->organization, $request->get(), false));
    }

    #[Permission('新增规则', 'saimulti:tenant:robot_single:rule')]
    public function ruleSave(Request $request): Response
    {
        return $this->success((new RobotSingleService())->ruleCreate(
            (int) $this->organization,
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('更新规则', 'saimulti:tenant:robot_single:rule')]
    public function ruleUpdate(Request $request): Response
    {
        return $this->success((new RobotSingleService())->ruleUpdate(
            (int) $this->organization,
            $this->id($request),
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('删除规则', 'saimulti:tenant:robot_single:rule')]
    public function ruleDestroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new RobotSingleService())->ruleDelete(
                (int) $this->organization,
                $this->ids($request),
                $this->tenantId,
            ),
        ]);
    }

    #[Permission('知识库列表', 'saimulti:tenant:robot_single:kb')]
    public function kbIndex(Request $request): Response
    {
        return $this->success((new RobotSingleService())->kbList((int) $this->organization, $request->get(), false));
    }

    #[Permission('新增知识库', 'saimulti:tenant:robot_single:kb')]
    public function kbSave(Request $request): Response
    {
        return $this->success((new RobotSingleService())->kbCreate(
            (int) $this->organization,
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('更新知识库', 'saimulti:tenant:robot_single:kb')]
    public function kbUpdate(Request $request): Response
    {
        return $this->success((new RobotSingleService())->kbUpdate(
            (int) $this->organization,
            $this->id($request),
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('删除知识库', 'saimulti:tenant:robot_single:kb')]
    public function kbDestroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new RobotSingleService())->kbDelete(
                (int) $this->organization,
                $this->ids($request),
                $this->tenantId,
            ),
        ]);
    }

    private function id(Request $request): int
    {
        $id = $request->input('id') ?? $request->get('id');
        if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
            throw new ApiException('编号无效。', 422);
        }

        return (int) $id;
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $ids = $request->input('ids');
        if (!is_array($ids)) {
            throw new ApiException('编号列表无效。', 422);
        }

        return array_map(function (mixed $id): int {
            if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
                throw new ApiException('编号列表无效。', 422);
            }

            return (int) $id;
        }, $ids);
    }
}
