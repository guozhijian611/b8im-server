<?php

declare(strict_types=1);

$database = trim((string) getenv('IM_USER_MGMT_TEST_DB_NAME'));
if (preg_match('/^nb8im_[a-f0-9]{8,24}_im_user_mgmt_test$/', $database) !== 1) {
    throw new RuntimeException('IM user management integration requires an isolated *_im_user_mgmt_test database.');
}
foreach (['DB_NAME' => $database, 'IM_MESSAGE_SHARD_BUCKETS' => '1'] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

foreach (['DB_NAME' => $database, 'IM_MESSAGE_SHARD_BUCKETS' => '1'] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\imUser\ImUserManagementService;
use support\think\Db;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$thinkOrmConfig = config('think-orm');
$connectionName = (string) ($thinkOrmConfig['default'] ?? 'mysql');
$thinkOrmConfig['connections'][$connectionName]['database'] = $database;
Db::setConfig($thinkOrmConfig);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};
$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, 'ApiException code mismatch: ' . $exception->getCode());
        return;
    }
    throw new RuntimeException('Expected ApiException was not thrown.');
};

$imConfigPath = dirname(__DIR__, 2) . '/b8im-im/phinx.php';
$input = new ArrayInput([]);
$input->setInteractive(false);
(new Manager(new Config(require $imConfigPath, $imConfigPath), $input, new BufferedOutput()))->migrate('development');

$service = new ImUserManagementService();
$actor = ['type' => 'admin', 'id' => 1, 'username' => 'admin', 'ip' => '127.0.0.1'];
$adminRootId = (int) Db::table('sm_admin_menu')->where('code', 'im')->value('id');
$tenantRootId = (int) Db::table('sm_tenant_menu')->where('organization', 0)->where('code', 'im')->value('id');
$assert($adminRootId > 0 && (int) Db::table('sm_admin_menu')->where('parent_id', $adminRootId)->where('code', 'im/user')->count() === 1, 'Admin IM user menu was not seeded.');
$assert((int) Db::table('sm_admin_menu')->where('parent_id', $adminRootId)->where('code', 'im/operations')->count() === 1, 'Admin IM operations menu was not moved under IM management.');
$assert($tenantRootId > 0 && (int) Db::table('sm_tenant_menu')->where('parent_id', $tenantRootId)->where('code', 'im/user')->count() === 1, 'Tenant IM user menu was not seeded.');
$assert((int) Db::table('sm_tenant_group_menu')->where('menu_id', $tenantRootId)->count() >= 1, 'Tenant package groups cannot assign the IM menu.');
$initialQuota = $service->quota(1);
$assert($initialQuota['configured'] === true && $initialQuota['quota_value'] === 0, 'Initial quota was not seeded safely.');

$quota = $service->updateQuota(1, 1, $actor);
$assert($quota['quota_value'] === 1 && $quota['used_value'] === 0, 'Seat quota update failed.');

$first = $service->create(1, [
    'account' => 'im_management_a',
    'password' => 'Password123!',
    'nickname' => '管理测试 A',
    'im_short_no' => '99000001',
    'mobile' => '13900000001',
    'email' => 'im-a@example.test',
    'gender' => 1,
    'status' => 1,
    'signature' => '初始签名',
    'remark' => 'integration',
], $actor);
$assert((int) $first['organization'] === 1 && $first['account'] === 'im_management_a', 'IM user create failed.');
$assert((int) Db::table('im_user_profile')->where('user_id', $first['user_id'])->count() === 1, 'Profile was not initialized.');
$assert((int) Db::table('im_user_privacy_setting')->where('user_id', $first['user_id'])->count() === 1, 'Privacy settings were not initialized.');
$assert((int) Db::table('im_user_security_policy')->where('user_id', $first['user_id'])->count() === 1, 'Security policy was not initialized.');
$expectCode(404, static fn () => $service->read((int) $first['id'], 2));

$expectCode(422, static fn () => $service->create(1, [
    'account' => 'im_management_b',
    'password' => 'Password123!',
    'nickname' => '管理测试 B',
    'status' => 1,
], $actor));

$service->updateQuota(1, 2, $actor);
$second = $service->create(1, [
    'account' => 'im_management_b',
    'password' => 'Password123!',
    'nickname' => '管理测试 B',
    'status' => 1,
], $actor);
$assert((int) $second['id'] > 0, 'Second IM user create failed after quota increase.');

$updated = $service->update((int) $first['id'], 1, [
    'account' => 'im_management_a2',
    'nickname' => '管理测试 A2',
    'im_short_no' => '99000001',
    'mobile' => '13900000001',
    'email' => 'im-a2@example.test',
    'gender' => 2,
    'signature' => '更新签名',
    'remark' => 'updated',
], $actor);
$assert($updated['account'] === 'im_management_a2' && $updated['signature'] === '更新签名', 'IM user update failed.');

$service->setStatus((int) $second['id'], 1, 2, $actor);
$afterDisable = $service->quota(1);
$assert($afterDisable['used_value'] === 1 && $afterDisable['remaining_value'] === 1, 'Disabling user did not release a seat.');
$service->updateQuota(1, 1, $actor);
$expectCode(422, static fn () => $service->setStatus((int) $second['id'], 1, 1, $actor));

$service->resetPassword((int) $first['id'], 1, 'NewPassword123!', $actor);
$hash = (string) Db::table('im_user')->where('id', (int) $first['id'])->value('password_hash');
$assert(password_verify('NewPassword123!', $hash), 'Password reset was not persisted.');
$assert((int) Db::table('sm_tool_oper_log')->where('remark', 'IM 用户管理操作')->count() >= 6, 'Management audit records are missing.');

$page = $service->index(['keyword' => 'management', 'page' => 1, 'limit' => 20], 1);
$assert($page['total'] === 2 && count($page['data']) === 2, 'Tenant-scoped user list is incorrect.');

fwrite(STDOUT, sprintf("IM user management integration (%s): %d assertions passed.\n", $database, $assertions));
