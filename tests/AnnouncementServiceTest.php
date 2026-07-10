<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\AnnouncementService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, '异常码不匹配');
        return;
    }

    throw new RuntimeException('预期异常未抛出');
};

$base = [
    'title' => ' 第一阶段公告 ',
    'summary' => ' 摘要 ',
    'content' => " 正文\n第二行 ",
    'display_mode' => 'both',
    'priority' => '10',
    'status' => '1',
    'start_time' => '2026-07-10 12:00:00',
    'end_time' => '2026-07-11 12:00:00',
    'organization' => 999,
    'published_at' => '2000-01-01 00:00:00',
];
$normalized = AnnouncementService::normalizePayload($base);
$assert($normalized['title'] === '第一阶段公告', '标题未规范化');
$assert($normalized['summary'] === '摘要', '摘要未规范化');
$assert($normalized['content'] === "正文\n第二行", '正文未规范化');
$assert(!array_key_exists('organization', $normalized), '客户端 organization 未被丢弃');
$assert(!array_key_exists('published_at', $normalized), '客户端 published_at 未被丢弃');

$partial = AnnouncementService::normalizePayload(['status' => 2], $normalized);
$assert($partial['status'] === 2 && $partial['title'] === '第一阶段公告', '部分更新合并失败');

$expectCode(422, static fn () => AnnouncementService::normalizePayload(array_merge($base, ['display_mode' => 'html'])));
$expectCode(422, static fn () => AnnouncementService::normalizePayload(array_merge($base, ['status' => 9])));
$expectCode(422, static fn () => AnnouncementService::normalizePayload(array_merge($base, ['priority' => '1.5'])));
$expectCode(422, static fn () => AnnouncementService::normalizePayload(array_merge($base, ['start_time' => '2026-02-30 12:00:00'])));
$expectCode(422, static fn () => AnnouncementService::normalizePayload(array_merge($base, [
    'start_time' => '2026-07-11 12:00:00',
    'end_time' => '2026-07-10 12:00:00',
])));

echo "Announcement service tests passed\n";
