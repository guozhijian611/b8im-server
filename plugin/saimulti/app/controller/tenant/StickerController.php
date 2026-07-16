<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\StickerService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('sticker', 'server', 'sticker.tenant.manage')]
final class StickerController extends TenantController
{
    #[Permission('租户表情包列表', 'saimulti:tenant:sticker:index')]
    public function index(Request $request): Response
    {
        return $this->success((new StickerService())->packList((int) $this->organization, $request->get()));
    }

    #[Permission('租户表情包详情', 'saimulti:tenant:sticker:index')]
    public function read(Request $request): Response
    {
        return $this->success((new StickerService())->packRead((int) $this->organization, $this->id($request)));
    }

    #[Permission('新增租户表情包', 'saimulti:tenant:sticker:save')]
    public function save(Request $request): Response
    {
        return $this->success((new StickerService())->packCreate((int) $this->organization, $request->post(), $this->tenantId));
    }

    #[Permission('更新租户表情包', 'saimulti:tenant:sticker:update')]
    public function update(Request $request): Response
    {
        return $this->success((new StickerService())->packUpdate((int) $this->organization, $this->id($request), $request->post(), $this->tenantId));
    }

    #[Permission('删除租户表情包', 'saimulti:tenant:sticker:destroy')]
    public function destroy(Request $request): Response
    {
        return $this->success(['deleted' => (new StickerService())->packDelete((int) $this->organization, $this->ids($request), $this->tenantId)]);
    }

    #[Permission('租户表情项列表', 'saimulti:tenant:sticker:item')]
    public function itemIndex(Request $request): Response
    {
        return $this->success((new StickerService())->itemList((int) $this->organization, $request->get()));
    }

    #[Permission('新增租户表情项', 'saimulti:tenant:sticker:item')]
    public function itemSave(Request $request): Response
    {
        return $this->success((new StickerService())->itemCreate((int) $this->organization, $request->post(), $this->tenantId));
    }

    #[Permission('更新租户表情项', 'saimulti:tenant:sticker:item')]
    public function itemUpdate(Request $request): Response
    {
        return $this->success((new StickerService())->itemUpdate((int) $this->organization, $this->id($request), $request->post(), $this->tenantId));
    }

    #[Permission('删除租户表情项', 'saimulti:tenant:sticker:item')]
    public function itemDestroy(Request $request): Response
    {
        return $this->success(['deleted' => (new StickerService())->itemDelete((int) $this->organization, $this->ids($request), $this->tenantId)]);
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
