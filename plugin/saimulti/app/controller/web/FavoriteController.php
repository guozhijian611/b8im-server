<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\FavoriteService;
use support\Request;
use support\Response;

#[ModuleRequired('favorite', 'server', 'favorite.web.manage')]
final class FavoriteController extends WebController
{
    public function index(Request $request): Response
    {
        return $this->success((new FavoriteService())->userList(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            $request->get(),
        ));
    }

    public function read(Request $request): Response
    {
        return $this->success((new FavoriteService())->userRead(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            $this->id($request),
        ));
    }

    public function save(Request $request): Response
    {
        return $this->success((new FavoriteService())->userCreate(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            $request->post(),
        ));
    }

    public function destroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new FavoriteService())->userDelete(
                $this->organization,
                (string) $this->webIdentity['user_id'],
                $this->ids($request),
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
        if ($ids === null) {
            $single = $request->input('id');
            if ($single !== null) {
                $ids = [$single];
            }
        }
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
