<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\CustomerServiceService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('customer_service', 'server', 'customer_service.admin.manage')]
final class CustomerServiceController extends AdminController
{
    #[Permission('平台客服会话列表', 'saimulti:admin:customer_service:index')]
    public function conversationIndex(Request $request): Response
    {
        return $this->success((new CustomerServiceService())->conversationList(0, $request->get(), true));
    }

    #[Permission('平台客服会话详情', 'saimulti:admin:customer_service:read')]
    public function conversationRead(Request $request): Response
    {
        return $this->success((new CustomerServiceService())->conversationRead(0, $this->id($request), true));
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
