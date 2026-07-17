<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\service\ModuleRequired;

#[ModuleRequired('search', 'server', 'search.app.use')]
final class SearchController extends \plugin\saimulti\app\controller\web\SearchController
{
}
