<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\AnnouncementService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('announcement', 'server', 'announcement.admin.manage')]
final class AnnouncementController extends AdminController
{
    #[Permission('平台公告列表', 'saimulti:admin:announcement:index')]
    public function index(Request $request): Response
    {
        return $this->success((new AnnouncementService())->managementList(0, $request->get()));
    }

    #[Permission('平台公告详情', 'saimulti:admin:announcement:read')]
    public function read(Request $request): Response
    {
        return $this->success((new AnnouncementService())->managementRead(0, $this->id($request)));
    }

    #[Permission('新增平台公告', 'saimulti:admin:announcement:save')]
    public function save(Request $request): Response
    {
        return $this->success((new AnnouncementService())->create(0, $request->post(), $this->adminId));
    }

    #[Permission('更新平台公告', 'saimulti:admin:announcement:update')]
    public function update(Request $request): Response
    {
        return $this->success((new AnnouncementService())->update(
            0,
            $this->id($request),
            $request->post(),
            $this->adminId,
        ));
    }

    #[Permission('删除平台公告', 'saimulti:admin:announcement:destroy')]
    public function destroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new AnnouncementService())->delete(0, $this->ids($request), $this->adminId),
        ]);
    }

    private function id(Request $request): int
    {
        $id = $request->input('id');
        if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
            throw new ApiException('公告编号无效。', 422);
        }

        return (int) $id;
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $ids = $request->input('ids');
        if (!is_array($ids)) {
            throw new ApiException('公告编号列表无效。', 422);
        }

        return array_map(function (mixed $id): int {
            if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
                throw new ApiException('公告编号列表无效。', 422);
            }

            return (int) $id;
        }, $ids);
    }
}
