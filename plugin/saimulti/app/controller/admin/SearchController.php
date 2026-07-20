<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\SearchService;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

#[ModuleRequired('search', 'server', 'search.admin.manage')]
final class SearchController extends AdminController
{
    #[Permission('平台索引列表', 'saimulti:admin:search:index')]
    public function indexList(Request $request): Response
    {
        return $this->success((new SearchService())->indexList($request->get()));
    }

    #[Permission('平台索引详情', 'saimulti:admin:search:index')]
    public function indexRead(Request $request): Response
    {
        return $this->success((new SearchService())->indexRead($this->org($request), true));
    }

    #[Permission('平台重建任务', 'saimulti:admin:search:job')]
    public function rebuild(Request $request): Response
    {
        return $this->success((new SearchService())->rebuild($this->org($request), $this->adminId));
    }

    #[Permission('平台任务列表', 'saimulti:admin:search:job')]
    public function jobIndex(Request $request): Response
    {
        $org = 0;
        if (isset($request->get()['organization']) && $request->get()['organization'] !== '') {
            $org = $this->org($request);
        }

        return $this->success((new SearchService())->jobList($org, $request->get(), true));
    }

    private function org(Request $request): int
    {
        $org = $request->input('organization') ?? $request->get('organization');
        if (!is_int($org) && (!is_string($org) || !preg_match('/^\d+$/', $org))) {
            throw new ApiException('机构编号无效。', 422);
        }
        $org = (int) $org;
        if ($org <= 0) {
            throw new ApiException('机构编号无效。', 422);
        }

        return $org;
    }
}
