<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\SearchService;
use support\Request;
use support\Response;

#[ModuleRequired('search', 'server', 'search.web.use')]
class SearchController extends WebController
{
    public function messages(Request $request): Response
    {
        return $this->success((new SearchService())->searchMessages(
            $this->organization,
            $request->get(),
        ));
    }

    public function indexStatus(Request $request): Response
    {
        return $this->success((new SearchService())->indexRead($this->organization, true));
    }
}
