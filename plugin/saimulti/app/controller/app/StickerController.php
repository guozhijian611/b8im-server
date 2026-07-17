<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\app;

use plugin\saimulti\service\ModuleRequired;

#[ModuleRequired('sticker', 'server', 'sticker.app.read')]
final class StickerController extends \plugin\saimulti\app\controller\web\StickerController
{
}
