<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\service\ModuleRequired;

#[ModuleRequired('moments', 'server', 'moments.app.use')]
final class MomentsController extends \plugin\saimulti\app\controller\web\MomentsController
{
}
