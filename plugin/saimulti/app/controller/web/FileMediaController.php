<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\FileMediaService;
use plugin\saimulti\utils\CanonicalInteger;
use support\Request;
use support\Response;

#[ModuleRequired('file_media', 'server', 'file_media.web.use')]
class FileMediaController extends WebController
{
    public function usage(Request $request): Response
    {
        return $this->success((new FileMediaService())->usage($this->organization));
    }

    public function checkUpload(Request $request): Response
    {
        $input = is_array($request->post()) ? $request->post() : [];
        if (array_keys($input) !== ['size_bytes']) {
            throw new ApiException('请求体必须且只能包含 size_bytes。', 422);
        }

        return $this->success((new FileMediaService())->checkUpload(
            $this->organization,
            CanonicalInteger::positive($input['size_bytes'], '文件大小'),
        ));
    }

    public function folderIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->folderList(
            $this->organization,
            $request->get(),
            false,
            (string) $this->webIdentity['user_id'],
        ));
    }

    public function folderSave(Request $request): Response
    {
        return $this->success((new FileMediaService())->folderCreate(
            $this->organization,
            is_array($request->post()) ? $request->post() : [],
            (int) $this->webIdentity['id'],
            (string) $this->webIdentity['user_id'],
        ));
    }

    public function itemIndex(Request $request): Response
    {
        return $this->success((new FileMediaService())->itemList(
            $this->organization,
            $request->get(),
            false,
            (string) $this->webIdentity['user_id'],
        ));
    }

    public function itemSave(Request $request): Response
    {
        return $this->success((new FileMediaService())->itemCreate(
            $this->organization,
            is_array($request->post()) ? $request->post() : [],
            (int) $this->webIdentity['id'],
            (string) $this->webIdentity['user_id'],
        ));
    }

    public function itemDestroy(Request $request): Response
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
        $ids = array_map(function (mixed $id): int {
            return CanonicalInteger::positive($id, '编号');
        }, $ids);

        return $this->success([
            'deleted' => (new FileMediaService())->itemDelete(
                $this->organization,
                $ids,
                (int) $this->webIdentity['id'],
                (string) $this->webIdentity['user_id'],
            ),
        ]);
    }

}
