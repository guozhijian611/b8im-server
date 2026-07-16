<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\MomentsService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('moments', 'server', 'moments.admin.manage')]
final class MomentsController extends AdminController
{
    #[Permission('平台动态列表', 'saimulti:admin:moments:index')]
    public function index(Request $request): Response
    {
        return $this->success((new MomentsService())->postList(0, $request->get(), true));
    }

    #[Permission('平台动态详情', 'saimulti:admin:moments:index')]
    public function read(Request $request): Response
    {
        return $this->success((new MomentsService())->postRead(0, $this->id($request), true));
    }

    #[Permission('平台删除动态', 'saimulti:admin:moments:destroy')]
    public function destroy(Request $request): Response
    {
        $org = (int) ($request->input('organization') ?? 0);
        if ($org <= 0) {
            throw new ApiException('机构编号无效。', 422);
        }

        return $this->success([
            'deleted' => (new MomentsService())->postDelete($org, $this->ids($request), $this->adminId),
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
