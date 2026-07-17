<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\service\ModuleRequired;

#[ModuleRequired('i18n', 'server', 'i18n.app.read')]
final class I18nController extends \plugin\saimulti\app\controller\web\I18nController
{
}
