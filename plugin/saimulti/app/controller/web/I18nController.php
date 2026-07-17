<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\I18nService;
use support\Request;
use support\Response;

#[ModuleRequired('i18n', 'server', 'i18n.web.read')]
class I18nController extends WebController
{
    public function locales(): Response
    {
        return $this->success([
            'items' => (new I18nService())->clientLocales($this->organization),
        ]);
    }

    public function messages(Request $request): Response
    {
        $locale = trim((string) $request->get('locale', ''));
        if ($locale === '') {
            throw new ApiException('locale 必填。', 422);
        }

        return $this->success((new I18nService())->clientMessages($this->organization, $locale));
    }
}
