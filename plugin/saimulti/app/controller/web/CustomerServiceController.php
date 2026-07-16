<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\CustomerServiceService;
use support\Request;
use support\Response;

#[ModuleRequired('customer_service', 'server', 'customer_service.web.use')]
final class CustomerServiceController extends WebController
{
    public function conversationIndex(Request $request): Response
    {
        $filters = $request->get();
        $filters['customer_subject_type'] = 'im_user';
        $filters['customer_subject_id'] = (string) $this->webIdentity['user_id'];

        return $this->success((new CustomerServiceService())->conversationList(
            $this->organization,
            $filters,
        ));
    }

    public function conversationRead(Request $request): Response
    {
        $row = (new CustomerServiceService())->conversationRead($this->organization, $this->id($request));
        if ($row['customer_subject_type'] !== 'im_user' || (string) $row['customer_subject_id'] !== (string) $this->webIdentity['user_id']) {
            throw new ApiException('会话不存在。', 404);
        }

        return $this->success($row);
    }

    public function conversationSave(Request $request): Response
    {
        return $this->success((new CustomerServiceService())->conversationCreateByUser(
            $this->organization,
            (string) $this->webIdentity['user_id'],
            $request->post(),
        ));
    }

    private function id(Request $request): int
    {
        $id = $request->input('id') ?? $request->get('id');
        if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
            throw new ApiException('编号无效。', 422);
        }

        return (int) $id;
    }
}
