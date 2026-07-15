<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\I18nService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
        throw new RuntimeException('预期异常未抛出');
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, '异常码不匹配: ' . $exception->getMessage());
    }
};

$service = new I18nService();

$expectCode(422, static fn () => $service->localeList(-1, []));
$expectCode(422, static fn () => $service->clientLocales(0));
$expectCode(422, static fn () => $service->clientMessages(0, 'zh-CN'));
$expectCode(422, static fn () => $service->localeCreate(0, ['code' => '!!', 'name' => 'x'], 1));
$expectCode(422, static fn () => $service->localeCreate(0, ['code' => 'zh-CN', 'name' => ''], 1));
$expectCode(422, static fn () => $service->localeCreate(0, ['code' => 'zh-CN', 'name' => '中文'], 0));
$expectCode(422, static fn () => $service->entryCreate(0, [
    'locale_code' => 'zh-CN',
    'msg_key' => '1bad',
    'msg_value' => 'x',
], 1));

echo "I18n service validation tests passed\n";
