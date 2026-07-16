<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\MomentsService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('moments', 'server', 'moments.tenant.manage')]
final class MomentsController extends TenantController
{
    #[Permission('租户动态列表', 'saimulti:tenant:moments:index')]
    public function index(Request $request): Response
    {
        return $this->success((new MomentsService())->postList((int) $this->organization, $request->get(), false));
    }

    #[Permission('租户动态详情', 'saimulti:tenant:moments:index')]
    public function read(Request $request): Response
    {
        return $this->success((new MomentsService())->postRead((int) $this->organization, $this->id($request), false));
    }

    #[Permission('租户删除动态', 'saimulti:tenant:moments:destroy')]
    public function destroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new MomentsService())->postDelete(
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
