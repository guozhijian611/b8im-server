<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\SearchService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('search', 'server', 'search.tenant.manage')]
final class SearchController extends TenantController
{
    #[Permission('租户索引状态', 'saimulti:tenant:search:index')]
    public function indexRead(Request $request): Response
    {
        return $this->success((new SearchService())->indexRead((int) $this->organization, true));
    }

    #[Permission('租户重建索引', 'saimulti:tenant:search:index')]
    public function rebuild(Request $request): Response
    {
        return $this->success((new SearchService())->rebuild((int) $this->organization, $this->tenantId));
    }

    #[Permission('租户任务列表', 'saimulti:tenant:search:index')]
    public function jobIndex(Request $request): Response
    {
        return $this->success((new SearchService())->jobList((int) $this->organization, $request->get(), false));
    }

    #[Permission('租户写入文档', 'saimulti:tenant:search:index')]
    public function docUpsert(Request $request): Response
    {
        return $this->success((new SearchService())->upsertDocument(
            (int) $this->organization,
            is_array($request->post()) ? $request->post() : [],
        ));
    }
}
