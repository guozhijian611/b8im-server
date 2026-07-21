<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\FileMediaService;
use plugin\saimulti\service\Permission;
use plugin\saimulti\utils\CanonicalInteger;
use support\Request;
use support\Response;

final class FileMediaController extends AdminController
{
    #[ModuleRequired('file_media', 'server', 'file_media.admin.manage')]
    #[Permission('文件媒体策略列表', 'saimulti:admin:file_media:index')]
    public function policyIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->policyList($request->get()));
    }

    #[ModuleRequired('file_media', 'server', 'file_media.admin.manage')]
    #[Permission('文件媒体策略详情', 'saimulti:admin:file_media:index')]
    public function policyRead(Request $request): Response
    {
        $org = $this->org($request);

        return $this->success((new FileMediaService())->policyRead($org));
    }

    #[ModuleRequired('file_media', 'server', 'file_media.admin.manage')]
    #[Permission('文件媒体策略更新', 'saimulti:admin:file_media:update')]
    public function policyUpdate(Request $request): Response
    {
        $body = $request->post();
        $expected = [
            'organization',
            'max_file_bytes',
            'preview_enabled',
            'large_file_enabled',
            'status',
        ];
        if (!is_array($body)
            || count($body) !== count($expected)
            || array_diff(array_keys($body), $expected) !== []
            || array_diff($expected, array_keys($body)) !== []) {
            throw new ApiException('请求体必须且只能包含机构与完整策略字段。', 422);
        }
        $org = CanonicalInteger::positive($body['organization'], '机构编号');
        $input = [
            'max_file_bytes' => $body['max_file_bytes'],
            'preview_enabled' => $body['preview_enabled'],
            'large_file_enabled' => $body['large_file_enabled'],
            'status' => $body['status'],
        ];

        return $this->success((new FileMediaService())->policyUpdate(
            $org,
            $input,
            $this->adminId,
        ));
    }

    #[ModuleRequired('file_media', 'server', 'file_media.admin.manage')]
    #[Permission('平台文件列表', 'saimulti:admin:file_media:index')]
    public function itemIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->itemList(0, $request->get(), true));
    }

    #[ModuleRequired('file_media', 'server', 'file_media.admin.manage')]
    #[Permission('平台目录列表', 'saimulti:admin:file_media:index')]
    public function folderIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->folderList(0, $request->get(), true));
    }

    private function org(Request $request): int
    {
        return CanonicalInteger::positive(
            $request->input('organization') ?? $request->get('organization'),
            '机构编号',
        );
    }
}
