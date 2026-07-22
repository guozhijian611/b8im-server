<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\FileMediaService;
use plugin\saimulti\service\Permission;
use plugin\saimulti\utils\CanonicalInteger;
use support\Request;
use support\Response;

#[ModuleRequired('file_media', 'server', 'file_media.tenant.manage')]
final class FileMediaController extends TenantController
{
    #[Permission('文件媒体策略读取', 'saimulti:tenant:file_media:quota')]
    public function policyRead(Request $request): Response
    {
        return $this->success((new FileMediaService())->policyRead((int) $this->organization));
    }

    #[Permission('文件媒体策略更新', 'saimulti:tenant:file_media:quota')]
    public function policyUpdate(Request $request): Response
    {
        $input = $request->post();
        $expected = ['max_file_bytes', 'preview_enabled', 'large_file_enabled', 'status'];
        if (!is_array($input)
            || count($input) !== count($expected)
            || array_diff(array_keys($input), $expected) !== []
            || array_diff($expected, array_keys($input)) !== []) {
            throw new ApiException('请求体必须且只能包含完整策略字段。', 422);
        }

        return $this->success((new FileMediaService())->policyUpdate(
            (int) $this->organization,
            $input,
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
        return CanonicalInteger::positive($id, '编号');
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $ids = $request->input('ids');
        if (!is_array($ids)) {
            throw new ApiException('编号列表无效。', 422);
        }

        return array_map(function (mixed $id): int {
            return CanonicalInteger::positive($id, '编号');
        }, $ids);
    }
}
