<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\FavoriteService;

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

$service = new FavoriteService();
$expectCode(422, static fn () => $service->userList(0, 'u1', []));
$expectCode(422, static fn () => $service->userList(1, '', []));
$expectCode(422, static fn () => $service->userCreate(1, 'u1', [
    'target_type' => 'unknown',
    'target_id' => 'm1',
    'title' => 'x',
]));
$expectCode(422, static fn () => $service->userCreate(1, 'u1', [
    'target_type' => 'message',
    'target_id' => '',
    'title' => 'x',
]));
$expectCode(422, static fn () => $service->userCreate(1, 'u1', [
    'target_type' => 'link',
    'target_id' => 'https://example.com',
    'title' => '',
]));

echo "Favorite service validation tests passed\n";
