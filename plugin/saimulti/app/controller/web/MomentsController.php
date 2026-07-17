<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\MomentsService;
use support\Request;
use support\Response;

#[ModuleRequired('moments', 'server', 'moments.web.use')]
class MomentsController extends WebController
{
    public function feed(Request $request): Response
    {
        return $this->success((new MomentsService())->feed(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            $request->get(),
        ));
    }

    public function read(Request $request): Response
    {
        return $this->success((new MomentsService())->postRead(
            $this->organization,
            $this->id($request),
            false,
            (string) $this->webIdentity['user_id'],
        ));
    }

    public function save(Request $request): Response
    {
        return $this->success((new MomentsService())->postCreate(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            is_array($request->post()) ? $request->post() : [],
            (int) $this->webIdentity['id'],
        ));
    }

    public function destroy(Request $request): Response
    {
        return $this->success([
            'deleted' => (new MomentsService())->postDelete(
                $this->organization,
                $this->ids($request),
                (int) $this->webIdentity['id'],
                (string) $this->webIdentity['user_id'],
            ),
        ]);
    }

    public function commentIndex(Request $request): Response
    {
        $postId = $this->intVal($request->get('post_id') ?? $request->input('post_id'), '动态编号');

        return $this->success((new MomentsService())->commentList(
            $this->organization,
            $postId,
            $request->get(),
            (string) $this->webIdentity['user_id'],
        ));
    }

    public function commentSave(Request $request): Response
    {
        return $this->success((new MomentsService())->commentCreate(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            is_array($request->post()) ? $request->post() : [],
            (int) $this->webIdentity['id'],
        ));
    }

    public function likeToggle(Request $request): Response
    {
        $postId = $this->intVal($request->input('post_id') ?? $request->post('post_id'), '动态编号');

        return $this->success((new MomentsService())->likeToggle(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            $postId,
        ));
    }

    public function profileRead(Request $request): Response
    {
        $userId = trim((string) ($request->get('user_id') ?? $this->webIdentity['user_id']));

        return $this->success((new MomentsService())->profileRead($this->organization, $userId));
    }

    public function profileUpdate(Request $request): Response
    {
        $input = is_array($request->post()) ? $request->post() : [];

        return $this->success((new MomentsService())->profileUpdate(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            (string) ($input['cover_url'] ?? ''),
        ));
    }

    private function id(Request $request): int
    {
        return $this->intVal($request->input('id') ?? $request->get('id'), '编号');
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

        return array_map(fn (mixed $id): int => $this->intVal($id, '编号'), $ids);
    }

    private function intVal(mixed $value, string $label): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) && (int) $value > 0) {
            return (int) $value;
        }
        throw new ApiException($label . '无效。', 422);
    }
}
