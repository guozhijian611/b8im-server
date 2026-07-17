<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\FileMediaService;
use support\Request;
use support\Response;

#[ModuleRequired('file_media', 'server', 'file_media.web.use')]
class FileMediaController extends WebController
{
    public function usage(Request $request): Response
    {
        return $this->success((new FileMediaService())->quotaRead($this->organization, true));
    }

    public function checkUpload(Request $request): Response
    {
        $input = is_array($request->post()) ? $request->post() : [];
        $size = $input['size_bytes'] ?? $input['size'] ?? 0;

        return $this->success((new FileMediaService())->checkUpload(
            $this->organization,
            $this->intVal($size, '文件大小'),
        ));
    }

    public function folderIndex(Request $request): Response
    {
        $filters = $request->get();
        $filters['owner_user_id'] = (string) $this->webIdentity['user_id'];

        return $this->success((new FileMediaService())->folderList($this->organization, $filters, false));
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
        $filters = $request->get();
        $filters['owner_user_id'] = (string) $this->webIdentity['user_id'];

        return $this->success((new FileMediaService())->itemList($this->organization, $filters, false));
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
            if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
                throw new ApiException('编号列表无效。', 422);
            }

            return (int) $id;
        }, $ids);

        return $this->success([
            'deleted' => (new FileMediaService())->itemDelete(
                $this->organization,
                $ids,
                (int) $this->webIdentity['id'],
            ),
        ]);
    }

    private function intVal(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        throw new ApiException($label . '无效。', 422);
    }
}
