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
use plugin\saimulti\service\qa\QaFixtureService;
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

$imRoot = trim((string) (getenv('B8IM_IM_ROOT') ?: dirname(__DIR__, 2) . '/b8im-im'));
$imConfigPath = $imRoot . '/phinx.php';
if (!is_file($imConfigPath)) {
    throw new RuntimeException('b8im-im phinx.php was not found for the isolated integration database.');
}
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
$groupAccessState = Db::table('im_user_group_access_state')
    ->where('organization', 1)
    ->where('user_id', (string) $first['user_id'])
    ->select()
    ->toArray();
$assert(count($groupAccessState) === 1, 'Group access state was not initialized exactly once.');
$assert((string) $groupAccessState[0]['access_snapshot_id'] === '1', 'Initial group access snapshot must be 1.');
$expectCode(404, static fn () => $service->read((int) $first['id'], 2));

$atomicTables = [
    'im_user',
    'im_user_profile',
    'im_user_privacy_setting',
    'im_user_security_policy',
    'im_user_group_access_state',
];
$atomicCounts = [];
foreach ($atomicTables as $table) {
    $atomicCounts[$table] = (int) Db::table($table)->count();
}
Db::execute(
    "CREATE TRIGGER im_user_group_access_state_fail_test
     BEFORE INSERT ON im_user_group_access_state
     FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced group access state failure'",
);
$atomicFailure = null;
try {
    $service->create(1, [
        'account' => 'im_management_atomic_rollback',
        'password' => 'Password123!',
        'nickname' => '原子回滚测试',
        'status' => 2,
    ], $actor);
} catch (Throwable $throwable) {
    $atomicFailure = $throwable;
} finally {
    Db::execute('DROP TRIGGER IF EXISTS im_user_group_access_state_fail_test');
}
$assert($atomicFailure instanceof Throwable, 'Forced group access state failure did not abort provisioning.');
$assert(
    (int) Db::table('im_user')->where('organization', 1)->where('account', 'im_management_atomic_rollback')->count() === 0,
    'Failed group access state initialization left the IM user visible.',
);
foreach ($atomicCounts as $table => $count) {
    $assert((int) Db::table($table)->count() === $count, "Provision rollback left rows in {$table}.");
}

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

$qaFixture = new QaFixtureService();
$upsertQaUser = new ReflectionMethod(QaFixtureService::class, 'upsertImUser');
$qaNow = date('Y-m-d H:i:s');
$upsertQaUser->invoke($qaFixture, 1, 'qa-state-user', 'qa_state_user', 'QA State User', '99009991', $qaNow);
$assert(
    (string) Db::table('im_user_group_access_state')
        ->where('organization', 1)
        ->where('user_id', 'qa-state-user')
        ->value('access_snapshot_id') === '1',
    'QA fixture did not initialize a missing group access state.',
);
Db::table('im_user_group_access_state')
    ->where('organization', 1)
    ->where('user_id', 'qa-state-user')
    ->update(['access_snapshot_id' => 7]);
$upsertQaUser->invoke($qaFixture, 1, 'qa-state-user', 'qa_state_user', 'QA State User', '99009991', $qaNow);
$assert(
    (string) Db::table('im_user_group_access_state')
        ->where('organization', 1)
        ->where('user_id', 'qa-state-user')
        ->value('access_snapshot_id') === '7',
    'QA fixture reset an existing positive group access snapshot.',
);
Db::table('im_group_member_access_audit')->insert([
    'event_id' => hash('sha256', 'qa-state-user-access-snapshot-7'),
    'organization' => 1,
    'conversation_id' => 'group_qa_state_recovery',
    'member_organization' => 1,
    'user_id' => 'qa-state-user',
    'access_snapshot_id' => 7,
    'access_version' => 1,
    'access_state' => 'revoked',
    'last_message_seq' => 0,
    'last_change_seq' => 0,
    'periods_json' => '[]',
    'reason' => 'history_revoke',
    'actor_organization' => 1,
    'actor_user_id' => 'system',
    'create_time' => $qaNow,
]);
Db::table('im_user_group_access_state')
    ->where('organization', 1)
    ->where('user_id', 'qa-state-user')
    ->delete();
$upsertQaUser->invoke($qaFixture, 1, 'qa-state-user', 'qa_state_user', 'QA State User', '99009991', $qaNow);
$assert(
    (string) Db::table('im_user_group_access_state')
        ->where('organization', 1)
        ->where('user_id', 'qa-state-user')
        ->value('access_snapshot_id') === '7',
    'QA fixture did not recover the immutable audited group access snapshot.',
);

$upsertQaUser->invoke(
    $qaFixture,
    1,
    'qa-orphan-state-user',
    'qa_orphan_state_user',
    'QA Orphan State User',
    '99009994',
    $qaNow,
);
Db::table('im_user_group_access_state')
    ->where('organization', 1)
    ->where('user_id', 'qa-orphan-state-user')
    ->delete();
$orphanUserBefore = Db::table('im_user')
    ->where('organization', 1)
    ->where('user_id', 'qa-orphan-state-user')
    ->find();
Db::table('im_conversation_member')->insert([
    'organization' => 1,
    'conversation_id' => 'orphan_group_member_state',
    'member_organization' => 1,
    'user_id' => 'qa-orphan-state-user',
    'member_role' => 'member',
    'status' => 3,
    'access_version' => 1,
    'access_state' => 'revoked',
    'join_at' => $qaNow,
    'create_time' => $qaNow,
    'update_time' => $qaNow,
]);
$orphanMemberFailure = null;
try {
    Db::transaction(static function () use ($upsertQaUser, $qaFixture, $qaNow): void {
        $upsertQaUser->invoke(
            $qaFixture,
            1,
            'qa-orphan-state-user',
            'qa_orphan_state_user',
            'Must Roll Back',
            '99009994',
            $qaNow,
        );
    });
} catch (Throwable $throwable) {
    $orphanMemberFailure = $throwable;
}
$assert($orphanMemberFailure instanceof RuntimeException, 'QA fixture trusted an orphan group member without audit.');
$assert(
    (int) Db::table('im_user_group_access_state')
        ->where('organization', 1)
        ->where('user_id', 'qa-orphan-state-user')
        ->count() === 0,
    'Failed orphan-member recovery left a new group access state.',
);
$orphanUserAfter = Db::table('im_user')
    ->where('organization', 1)
    ->where('user_id', 'qa-orphan-state-user')
    ->find();
$assert(
    is_array($orphanUserBefore)
        && is_array($orphanUserAfter)
        && hash_equals((string) $orphanUserBefore['nickname'], (string) $orphanUserAfter['nickname'])
        && hash_equals((string) $orphanUserBefore['password_hash'], (string) $orphanUserAfter['password_hash']),
    'Failed orphan-member recovery did not roll back the QA user update.',
);
Db::table('im_conversation_member')
    ->where('organization', 1)
    ->where('conversation_id', 'orphan_group_member_state')
    ->where('user_id', 'qa-orphan-state-user')
    ->delete();
Db::table('im_conversation_membership_period')->insert([
    'organization' => 1,
    'conversation_id' => 'orphan_group_period_state',
    'member_organization' => 1,
    'user_id' => 'qa-orphan-state-user',
    'period_no' => 1,
    'visible_from_message_seq' => 1,
    'visible_until_message_seq' => 1,
    'join_at' => $qaNow,
    'leave_at' => $qaNow,
    'status' => 1,
    'create_time' => $qaNow,
    'update_time' => $qaNow,
]);
$orphanPeriodFailure = null;
try {
    Db::transaction(static function () use ($upsertQaUser, $qaFixture, $qaNow): void {
        $upsertQaUser->invoke(
            $qaFixture,
            1,
            'qa-orphan-state-user',
            'qa_orphan_state_user',
            'Must Also Roll Back',
            '99009994',
            $qaNow,
        );
    });
} catch (Throwable $throwable) {
    $orphanPeriodFailure = $throwable;
}
$assert($orphanPeriodFailure instanceof RuntimeException, 'QA fixture trusted an orphan group period without audit.');
$assert(
    (int) Db::table('im_user_group_access_state')
        ->where('organization', 1)
        ->where('user_id', 'qa-orphan-state-user')
        ->count() === 0,
    'Failed orphan-period recovery left a new group access state.',
);
$assert(
    (string) Db::table('im_user')
        ->where('organization', 1)
        ->where('user_id', 'qa-orphan-state-user')
        ->value('nickname') === 'QA Orphan State User',
    'Failed orphan-period recovery did not roll back the QA user update.',
);

$qaCollisionFailure = null;
try {
    $upsertQaUser->invoke(
        $qaFixture,
        1,
        (string) $first['user_id'],
        'im_management_a2',
        'Must Not Overwrite',
        '99009992',
        $qaNow,
    );
} catch (ReflectionException|RuntimeException $throwable) {
    $qaCollisionFailure = $throwable;
}
$assert($qaCollisionFailure instanceof RuntimeException, 'QA fixture overwrote a non-QA account.');
$assert(
    (string) Db::table('im_user')->where('id', (int) $first['id'])->value('nickname') === '管理测试 A2',
    'Rejected QA collision changed non-QA user data.',
);

$qaIdentityFailure = null;
try {
    $upsertQaUser->invoke(
        $qaFixture,
        1,
        (string) $first['user_id'],
        'qa_identity_collision',
        'Must Not Reuse Identity',
        '99009993',
        $qaNow,
    );
} catch (ReflectionException|RuntimeException $throwable) {
    $qaIdentityFailure = $throwable;
}
$assert($qaIdentityFailure instanceof RuntimeException, 'QA fixture reused an occupied user identity.');
$assert(
    (int) Db::table('im_user')->where('organization', 1)->where('account', 'qa_identity_collision')->count() === 0,
    'Rejected QA identity collision left a user row.',
);

fwrite(STDOUT, sprintf("IM user management integration (%s): %d assertions passed.\n", $database, $assertions));
