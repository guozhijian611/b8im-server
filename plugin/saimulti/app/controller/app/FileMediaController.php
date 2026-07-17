<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\service\ModuleRequired;

#[ModuleRequired('file_media', 'server', 'file_media.app.use')]
final class FileMediaController extends \plugin\saimulti\app\controller\web\FileMediaController
{
}
