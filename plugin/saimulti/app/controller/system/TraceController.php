<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\app\controller\system;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\Permission;
use plugin\saimulti\service\trace\JaegerTraceQueryService;
use support\Request;
use support\Response;

final class TraceController extends AdminController
{
    #[Permission('链路服务列表', 'saimulti:system:trace:services')]
    public function services(): Response
    {
        $this->assertSuperAdministrator();
        return $this->success((new JaegerTraceQueryService())->services());
    }

    #[Permission('链路查询', 'saimulti:system:trace:search')]
    public function search(Request $request): Response
    {
        $this->assertSuperAdministrator();
        return $this->success((new JaegerTraceQueryService())->search($request->get()));
    }

    #[Permission('链路详情', 'saimulti:system:trace:read')]
    public function read(Request $request): Response
    {
        $this->assertSuperAdministrator();
        return $this->success((new JaegerTraceQueryService())->read($request->input('trace_id')));
    }

    private function assertSuperAdministrator(): void
    {
        if ($this->adminId !== 1 && (int) ($this->adminInfo['user_type'] ?? 0) !== 100) {
            throw new ApiException('仅平台超级管理员可查看全链路数据。', 403);
        }
    }
}
