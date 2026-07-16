<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\FileMediaService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('file_media', 'server', 'file_media.admin.manage')]
final class FileMediaController extends AdminController
{
    #[Permission('平台配额列表', 'saimulti:admin:file_media:index')]
    public function quotaIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->quotaList($request->get()));
    }

    #[Permission('平台配额详情', 'saimulti:admin:file_media:index')]
    public function quotaRead(Request $request): Response
    {
        $org = $this->org($request);

        return $this->success((new FileMediaService())->quotaRead($org, true));
    }

    #[Permission('平台调整配额', 'saimulti:admin:file_media:update')]
    public function quotaUpdate(Request $request): Response
    {
        $org = $this->org($request);
        $input = is_array($request->post()) ? $request->post() : [];

        return $this->success((new FileMediaService())->quotaUpdate($org, $input, $this->adminId));
    }

    #[Permission('平台文件列表', 'saimulti:admin:file_media:index')]
    public function itemIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->itemList(0, $request->get(), true));
    }

    #[Permission('平台目录列表', 'saimulti:admin:file_media:index')]
    public function folderIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->folderList(0, $request->get(), true));
    }

    private function org(Request $request): int
    {
        $org = $request->input('organization') ?? $request->get('organization');
        if (!is_int($org) && (!is_string($org) || !preg_match('/^\d+$/', $org))) {
            throw new ApiException('机构编号无效。', 422);
        }
        $org = (int) $org;
        if ($org <= 0) {
            throw new ApiException('机构编号无效。', 422);
        }

        return $org;
    }
}
