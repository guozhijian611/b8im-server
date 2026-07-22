<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\SearchService;
use support\Request;
use support\Response;

#[ModuleRequired('search', 'server', 'search.web.use')]
class SearchController extends WebController
{
    public function messages(Request $request): Response
    {
        $userId = $this->webIdentity['user_id'] ?? null;
        if (!is_string($userId)) {
            throw new ApiException('Web 登录上下文无效。', 401);
        }

        return $this->success((new SearchService())->searchMessages(
            $this->organization,
            $userId,
            $request->get(),
        ));
    }

    public function indexStatus(Request $request): Response
    {
        return $this->success((new SearchService())->indexRead($this->organization, true));
    }
}
