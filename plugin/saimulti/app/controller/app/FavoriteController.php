<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\service\ModuleRequired;

#[ModuleRequired('favorite', 'server', 'favorite.app.manage')]
final class FavoriteController extends \plugin\saimulti\app\controller\web\FavoriteController
{
}
