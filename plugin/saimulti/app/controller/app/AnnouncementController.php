<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\service\ModuleRequired;

#[ModuleRequired('announcement', 'server', 'announcement.app.read')]
final class AnnouncementController extends \plugin\saimulti\app\controller\web\AnnouncementController
{
}
