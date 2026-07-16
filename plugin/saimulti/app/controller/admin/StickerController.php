<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\StickerService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('sticker', 'server', 'sticker.admin.manage')]
final class StickerController extends AdminController
{
    #[Permission('平台表情包列表', 'saimulti:admin:sticker:index')]
    public function index(Request $request): Response
    {
        return $this->success((new StickerService())->packList(0, $request->get()));
    }

    #[Permission('平台表情包详情', 'saimulti:admin:sticker:index')]
    public function read(Request $request): Response
    {
        return $this->success((new StickerService())->packRead(0, $this->id($request)));
    }

    #[Permission('新增平台表情包', 'saimulti:admin:sticker:save')]
    public function save(Request $request): Response
    {
        return $this->success((new StickerService())->packCreate(0, $request->post(), $this->adminId));
    }

    #[Permission('更新平台表情包', 'saimulti:admin:sticker:update')]
    public function update(Request $request): Response
    {
        return $this->success((new StickerService())->packUpdate(0, $this->id($request), $request->post(), $this->adminId));
    }

    #[Permission('删除平台表情包', 'saimulti:admin:sticker:destroy')]
    public function destroy(Request $request): Response
    {
        return $this->success(['deleted' => (new StickerService())->packDelete(0, $this->ids($request), $this->adminId)]);
    }

    #[Permission('平台表情项列表', 'saimulti:admin:sticker:item')]
    public function itemIndex(Request $request): Response
    {
        return $this->success((new StickerService())->itemList(0, $request->get()));
    }

    #[Permission('新增平台表情项', 'saimulti:admin:sticker:item')]
    public function itemSave(Request $request): Response
    {
        return $this->success((new StickerService())->itemCreate(0, $request->post(), $this->adminId));
    }

    #[Permission('更新平台表情项', 'saimulti:admin:sticker:item')]
    public function itemUpdate(Request $request): Response
    {
        return $this->success((new StickerService())->itemUpdate(0, $this->id($request), $request->post(), $this->adminId));
    }

    #[Permission('删除平台表情项', 'saimulti:admin:sticker:item')]
    public function itemDestroy(Request $request): Response
    {
        return $this->success(['deleted' => (new StickerService())->itemDelete(0, $this->ids($request), $this->adminId)]);
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
