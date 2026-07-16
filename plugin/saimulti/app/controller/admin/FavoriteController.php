<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\FavoriteService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('favorite', 'server', 'favorite.admin.manage')]
final class FavoriteController extends AdminController
{
    #[Permission('平台收藏列表', 'saimulti:admin:favorite:index')]
    public function index(Request $request): Response
    {
        return $this->success((new FavoriteService())->managementList(0, $request->get(), true));
    }

    #[Permission('平台收藏详情', 'saimulti:admin:favorite:read')]
    public function read(Request $request): Response
    {
        return $this->success((new FavoriteService())->managementRead(0, $this->id($request), true));
    }

    #[Permission('平台删除收藏', 'saimulti:admin:favorite:destroy')]
    public function destroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new FavoriteService())->managementDelete(0, $this->ids($request), $this->adminId, true),
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
