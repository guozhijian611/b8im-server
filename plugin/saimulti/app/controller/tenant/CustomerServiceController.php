<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\CustomerServiceService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('customer_service', 'server', 'customer_service.tenant.manage')]
final class CustomerServiceController extends TenantController
{
    private function svc(): CustomerServiceService
    {
        return new CustomerServiceService();
    }

    private function org(): int
    {
        return (int) $this->organization;
    }

    // queue
    #[Permission('租户队列列表', 'saimulti:tenant:customer_service:queue')]
    public function queueIndex(Request $request): Response
    {
        return $this->success($this->svc()->queueList($this->org(), $request->get()));
    }

    #[Permission('新增队列', 'saimulti:tenant:customer_service:queue')]
    public function queueSave(Request $request): Response
    {
        return $this->success($this->svc()->queueCreate($this->org(), $request->post(), $this->tenantId));
    }

    #[Permission('更新队列', 'saimulti:tenant:customer_service:queue')]
    public function queueUpdate(Request $request): Response
    {
        return $this->success($this->svc()->queueUpdate($this->org(), $this->id($request), $request->post(), $this->tenantId));
    }

    #[Permission('删除队列', 'saimulti:tenant:customer_service:queue')]
    public function queueDestroy(Request $request): Response
    {
        return $this->success(['deleted' => $this->svc()->queueDelete($this->org(), $this->ids($request), $this->tenantId)]);
    }

    // entry
    #[Permission('租户入口列表', 'saimulti:tenant:customer_service:entry')]
    public function entryIndex(Request $request): Response
    {
        return $this->success($this->svc()->entryList($this->org(), $request->get()));
    }

    #[Permission('新增入口', 'saimulti:tenant:customer_service:entry')]
    public function entrySave(Request $request): Response
    {
        return $this->success($this->svc()->entryCreate($this->org(), $request->post(), $this->tenantId));
    }

    #[Permission('更新入口', 'saimulti:tenant:customer_service:entry')]
    public function entryUpdate(Request $request): Response
    {
        return $this->success($this->svc()->entryUpdate($this->org(), $this->id($request), $request->post(), $this->tenantId));
    }

    #[Permission('删除入口', 'saimulti:tenant:customer_service:entry')]
    public function entryDestroy(Request $request): Response
    {
        return $this->success(['deleted' => $this->svc()->entryDelete($this->org(), $this->ids($request), $this->tenantId)]);
    }

    // agent
    #[Permission('租户坐席列表', 'saimulti:tenant:customer_service:agent')]
    public function agentIndex(Request $request): Response
    {
        return $this->success($this->svc()->agentList($this->org(), $request->get()));
    }

    #[Permission('新增坐席', 'saimulti:tenant:customer_service:agent')]
    public function agentSave(Request $request): Response
    {
        return $this->success($this->svc()->agentCreate($this->org(), $request->post(), $this->tenantId));
    }

    #[Permission('更新坐席', 'saimulti:tenant:customer_service:agent')]
    public function agentUpdate(Request $request): Response
    {
        return $this->success($this->svc()->agentUpdate($this->org(), $this->id($request), $request->post(), $this->tenantId));
    }

    #[Permission('删除坐席', 'saimulti:tenant:customer_service:agent')]
    public function agentDestroy(Request $request): Response
    {
        return $this->success(['deleted' => $this->svc()->agentDelete($this->org(), $this->ids($request), $this->tenantId)]);
    }

    // conversation
    #[Permission('租户会话列表', 'saimulti:tenant:customer_service:conversation')]
    public function conversationIndex(Request $request): Response
    {
        return $this->success($this->svc()->conversationList($this->org(), $request->get()));
    }

    #[Permission('租户会话详情', 'saimulti:tenant:customer_service:conversation')]
    public function conversationRead(Request $request): Response
    {
        return $this->success($this->svc()->conversationRead($this->org(), $this->id($request)));
    }

    #[Permission('更新会话', 'saimulti:tenant:customer_service:conversation')]
    public function conversationUpdate(Request $request): Response
    {
        return $this->success($this->svc()->conversationUpdate($this->org(), $this->id($request), $request->post(), $this->tenantId));
    }

    private function id(Request $request): int
    {
        $id = $request->input('id') ?? $request->get('id');
        if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
            throw new ApiException('编号无效。', 422);
        }

        return (int) $id;
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $ids = $request->input('ids');
        if (!is_array($ids)) {
            throw new ApiException('编号列表无效。', 422);
        }

        return array_map(function (mixed $id): int {
            if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
                throw new ApiException('编号列表无效。', 422);
            }

            return (int) $id;
        }, $ids);
    }
}
