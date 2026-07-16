<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\FileMediaService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('file_media', 'server', 'file_media.tenant.manage')]
final class FileMediaController extends TenantController
{
    #[Permission('租户配额', 'saimulti:tenant:file_media:quota')]
    public function quotaRead(Request $request): Response
    {
        return $this->success((new FileMediaService())->quotaRead((int) $this->organization, true));
    }

    #[Permission('更新租户配额策略', 'saimulti:tenant:file_media:quota')]
    public function quotaUpdate(Request $request): Response
    {
        return $this->success((new FileMediaService())->quotaUpdate(
            (int) $this->organization,
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('目录列表', 'saimulti:tenant:file_media:space')]
    public function folderIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->folderList((int) $this->organization, $request->get(), false));
    }

    #[Permission('创建目录', 'saimulti:tenant:file_media:space')]
    public function folderSave(Request $request): Response
    {
        return $this->success((new FileMediaService())->folderCreate(
            (int) $this->organization,
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('更新目录', 'saimulti:tenant:file_media:space')]
    public function folderUpdate(Request $request): Response
    {
        return $this->success((new FileMediaService())->folderUpdate(
            (int) $this->organization,
            $this->id($request),
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('删除目录', 'saimulti:tenant:file_media:space')]
    public function folderDestroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new FileMediaService())->folderDelete(
                (int) $this->organization,
                $this->ids($request),
                $this->tenantId,
            ),
        ]);
    }

    #[Permission('文件列表', 'saimulti:tenant:file_media:space')]
    public function itemIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->itemList((int) $this->organization, $request->get(), false));
    }

    #[Permission('登记文件', 'saimulti:tenant:file_media:space')]
    public function itemSave(Request $request): Response
    {
        return $this->success((new FileMediaService())->itemCreate(
            (int) $this->organization,
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('更新文件', 'saimulti:tenant:file_media:space')]
    public function itemUpdate(Request $request): Response
    {
        return $this->success((new FileMediaService())->itemUpdate(
            (int) $this->organization,
            $this->id($request),
            is_array($request->post()) ? $request->post() : [],
            $this->tenantId,
        ));
    }

    #[Permission('删除文件', 'saimulti:tenant:file_media:space')]
    public function itemDestroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new FileMediaService())->itemDelete(
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
