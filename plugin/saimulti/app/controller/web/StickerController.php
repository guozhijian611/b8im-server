<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\web;

use plugin\saimulti\basic\WebController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\StickerService;
use support\Request;
use support\Response;

#[ModuleRequired('sticker', 'server', 'sticker.web.read')]
class StickerController extends WebController
{
    public function packs(): Response
    {
        return $this->success([
            'items' => (new StickerService())->clientPacks($this->organization),
        ]);
    }

    public function items(Request $request): Response
    {
        $packId = $request->get('pack_id');
        $id = null;
        if ($packId !== null && $packId !== '') {
            if (!is_int($packId) && (!is_string($packId) || !preg_match('/^\d+$/', $packId))) {
                throw new ApiException('表情包编号无效。', 422);
            }
            $id = (int) $packId;
        }

        return $this->success([
            'items' => (new StickerService())->clientItems($this->organization, $id),
        ]);
    }
}
