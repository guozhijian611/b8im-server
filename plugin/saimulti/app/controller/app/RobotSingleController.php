<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\service\ModuleRequired;

#[ModuleRequired('robot_single', 'server', 'robot_single.app.use')]
final class RobotSingleController extends \plugin\saimulti\app\controller\web\RobotSingleController
{
}
