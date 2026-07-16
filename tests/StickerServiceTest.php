<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\StickerService;
$expect = static function (int $code, callable $cb): void {
    try { $cb(); throw new RuntimeException('expected'); }
    catch (ApiException $e) { if ($e->getCode() !== $code) throw new RuntimeException('code'); }
};
$s = new StickerService();
$expect(422, static fn () => $s->clientPacks(0));
$expect(422, static fn () => $s->packCreate(0, ['code' => '!!', 'name' => 'x'], 1));
$expect(422, static fn () => $s->packCreate(0, ['code' => 'ok', 'name' => ''], 1));
echo "Sticker service validation tests passed\n";
