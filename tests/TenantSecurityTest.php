<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\TenantContext;
use plugin\saimulti\service\TenantUserWritePolicy;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(TenantContext::parseOrganization(1) === 1, '整数 organization 解析失败');
$assert(TenantContext::parseOrganization('42') === 42, '字符串 organization 解析失败');

foreach ([0, -1, '', '01', '1.0', 'tenant-1', null, []] as $invalid) {
    try {
        TenantContext::parseOrganization($invalid);
        throw new RuntimeException('无效 organization 未被拒绝');
    } catch (ApiException $exception) {
        $assert($exception->getCode() === TenantContext::REQUIRED, '无效 organization 错误码不正确');
    }
}

$malicious = [
    'id' => 9,
    'organization' => 999,
    'user_type' => '100',
    'username' => 'operator',
    'password' => 'password123',
    'password_confirm' => 'password123',
    'nickname' => '运营员',
    'dept_id' => 2,
    'role_ids' => [2],
    'post_ids' => [],
    'status' => 1,
    'create_time' => '2000-01-01 00:00:00',
];

$create = TenantUserWritePolicy::forCreate($malicious);
$assert($create['user_type'] === '200', '新用户未强制为普通管理员');
$assert(!array_key_exists('organization', $create), '创建数据仍包含 organization');
$assert(!array_key_exists('id', $create), '创建数据仍包含客户端 id');
$assert(!array_key_exists('password_confirm', $create), '创建数据仍包含确认密码');
$assert(!array_key_exists('create_time', $create), '创建数据仍包含审计字段');

$update = TenantUserWritePolicy::forUpdate($malicious);
$assert($update['id'] === 9, '更新数据丢失 id');
$assert(!array_key_exists('organization', $update), '更新数据仍包含 organization');
$assert(!array_key_exists('user_type', $update), '更新数据仍包含 user_type');
$assert(!array_key_exists('password', $update), '更新数据仍包含 password');

echo "Tenant security tests passed\n";
