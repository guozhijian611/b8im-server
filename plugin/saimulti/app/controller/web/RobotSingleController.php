<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\RobotSingleService;
use support\Request;
use support\Response;

#[ModuleRequired('robot_single', 'server', 'robot_single.web.use')]
class RobotSingleController extends WebController
{
    public function index(Request $request): Response
    {
        return $this->success((new RobotSingleService())->userRobotList(
            (int) $this->organization,
            $request->get(),
        ));
    }

    public function read(Request $request): Response
    {
        return $this->success((new RobotSingleService())->robotRead(
            (int) $this->organization,
            $this->id($request),
            false,
        ));
    }

    public function match(Request $request): Response
    {
        $input = $request->post();
        if (!is_array($input)) {
            $input = [];
        }
        $robotId = $input['robot_id'] ?? $request->input('robot_id');
        $text = (string) ($input['text'] ?? $input['message'] ?? '');

        return $this->success((new RobotSingleService())->matchReply(
            (int) $this->organization,
            $this->idValue($robotId, '机器人编号'),
            $text,
        ));
    }

    private function id(Request $request): int
    {
        return $this->idValue($request->input('id') ?? $request->get('id'), '编号');
    }

    private function idValue(mixed $id, string $label): int
    {
        if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
            throw new ApiException($label . '无效。', 422);
        }

        return (int) $id;
    }
}
