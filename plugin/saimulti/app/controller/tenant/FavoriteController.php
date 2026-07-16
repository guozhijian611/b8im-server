<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\FavoriteService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('favorite', 'server', 'favorite.tenant.manage')]
final class FavoriteController extends TenantController
{
    #[Permission('租户收藏列表', 'saimulti:tenant:favorite:index')]
    public function index(Request $request): Response
    {
        return $this->success((new FavoriteService())->managementList(
            (int) $this->organization,
            $request->get(),
            false,
        ));
    }

    #[Permission('租户收藏详情', 'saimulti:tenant:favorite:read')]
    public function read(Request $request): Response
    {
        return $this->success((new FavoriteService())->managementRead(
            (int) $this->organization,
            $this->id($request),
            false,
        ));
    }

    #[Permission('租户删除收藏', 'saimulti:tenant:favorite:destroy')]
    public function destroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new FavoriteService())->managementDelete(
                (int) $this->organization,
                $this->ids($request),
                $this->tenantId,
                false,
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
