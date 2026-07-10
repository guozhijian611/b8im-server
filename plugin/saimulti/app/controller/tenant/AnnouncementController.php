<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\AnnouncementService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('announcement', 'server', 'announcement.tenant.manage')]
final class AnnouncementController extends TenantController
{
    #[Permission('租户公告列表', 'saimulti:tenant:announcement:index')]
    public function index(Request $request): Response
    {
        return $this->success((new AnnouncementService())->managementList(
            (int) $this->organization,
            $request->get(),
        ));
    }

    #[Permission('租户公告详情', 'saimulti:tenant:announcement:read')]
    public function read(Request $request): Response
    {
        return $this->success((new AnnouncementService())->managementRead(
            (int) $this->organization,
            $this->id($request),
        ));
    }

    #[Permission('新增租户公告', 'saimulti:tenant:announcement:save')]
    public function save(Request $request): Response
    {
        return $this->success((new AnnouncementService())->create(
            (int) $this->organization,
            $request->post(),
            $this->tenantId,
        ));
    }

    #[Permission('更新租户公告', 'saimulti:tenant:announcement:update')]
    public function update(Request $request): Response
    {
        return $this->success((new AnnouncementService())->update(
            (int) $this->organization,
            $this->id($request),
            $request->post(),
            $this->tenantId,
        ));
    }

    #[Permission('删除租户公告', 'saimulti:tenant:announcement:destroy')]
    public function destroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new AnnouncementService())->delete(
                (int) $this->organization,
                $this->ids($request),
                $this->tenantId,
            ),
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
