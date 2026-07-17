<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\AnnouncementService;
use support\Request;
use support\Response;

#[ModuleRequired('announcement', 'server', 'announcement.web.read')]
class AnnouncementController extends WebController
{
    public function index(Request $request): Response
    {
        return $this->success((new AnnouncementService())->publishedList(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            $this->positiveInteger($request->get('page', 1), '页码'),
            $this->positiveInteger($request->get('limit', 50), '每页数量'),
        ));
    }

    public function read(Request $request): Response
    {
        return $this->success((new AnnouncementService())->publishedRead(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            $this->positiveInteger($request->get('id'), '公告编号'),
        ));
    }

    public function acknowledge(Request $request): Response
    {
        return $this->success((new AnnouncementService())->acknowledge(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            $this->positiveInteger($request->input('id'), '公告编号'),
        ));
    }

    private function positiveInteger(mixed $value, string $name): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value)) {
            $integer = (int) $value;
        } else {
            throw new ApiException($name . '无效。', 422);
        }
        if ($integer <= 0) {
            throw new ApiException($name . '无效。', 422);
        }

        return $integer;
    }
}
