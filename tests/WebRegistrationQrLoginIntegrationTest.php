<?php

declare(strict_types=1);

$database = trim((string) getenv('WEB_REGISTER_QR_TEST_DB_NAME'));
if (preg_match('/^nb8im_[a-f0-9]{8,24}_web_register_qr_test$/', $database) !== 1) {
    throw new RuntimeException('Web registration/QR integration requires an isolated *_web_register_qr_test database.');
}
foreach (['DB_NAME' => $database, 'PHINX_DB_NAME' => $database, 'IM_MESSAGE_SHARD_BUCKETS' => '1'] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\WebTokenService;
use plugin\saimulti\service\web\TenantAccountPolicyService;
use plugin\saimulti\service\web\ImChallengeTokenService;
use plugin\saimulti\service\web\ThinkOrmTenantAccountPolicyStore;
use plugin\saimulti\service\web\ThinkOrmWebImAuthStore;
use plugin\saimulti\service\web\ThinkOrmWebImPolicyStore;
use plugin\saimulti\service\web\ThinkOrmWebQrLoginStore;
use plugin\saimulti\service\web\WebImAuthService;
use plugin\saimulti\service\web\WebImPolicyGuard;
use plugin\saimulti\service\web\WebQrLoginService;
use plugin\saimulti\service\web\WebRegistrationService;
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

$imConfigPath = null;
$imCandidates = [
    dirname(__DIR__, 2) . '/b8im-im/phinx.php',
    dirname(__DIR__, 4) . '/b8im-im/phinx.php',
];
$configuredImRoot = trim((string) getenv('B8IM_IM_ROOT'));
if ($configuredImRoot !== '') {
    array_unshift($imCandidates, $configuredImRoot . '/phinx.php');
}
foreach ($imCandidates as $candidate) {
    if (is_file($candidate)) {
        $imConfigPath = $candidate;
        break;
    }
}
if ($imConfigPath === null) {
    throw new RuntimeException('b8im-im phinx.php was not found for the isolated integration database.');
}
$input = new ArrayInput([]);
$input->setInteractive(false);
(new Manager(new Config(require $imConfigPath, $imConfigPath), $input, new BufferedOutput()))->migrate('development');

$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 1)->value('register_enabled') === 0, 'fresh installation seeded registration open');
$columnDefault = static fn (): int => (int) Db::query(
    "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sm_tenant_account_policy' AND COLUMN_NAME = 'register_enabled'",
)[0]['COLUMN_DEFAULT'];
$assert($columnDefault() === 0, 'fresh installation schema default is not fail closed');
$serverConfigPath = dirname(__DIR__) . '/phinx.php';
$serverManager = new Manager(new Config(require $serverConfigPath, $serverConfigPath), $input, new BufferedOutput());
$serverMigrations = $serverManager->getMigrations('default');
$registrationCloseMigration = $serverMigrations[20260721130000] ?? null;
$assert($registrationCloseMigration instanceof Phinx\Migration\MigrationInterface, 'registration closure migration is unavailable');
$registrationCloseMigration->setAdapter($serverManager->getEnvironment('default')->getAdapter());
$permissionMigration = $serverMigrations[20260721131000] ?? null;
$assert($permissionMigration instanceof Phinx\Migration\MigrationInterface, 'account policy permission migration is unavailable');
$permissionMigration->setAdapter($serverManager->getEnvironment('default')->getAdapter());
$page = Db::table('sm_tenant_menu')->where('organization', 0)
    ->where('code', 'system/account-policy')->whereNull('delete_time')->find();
$assert(is_array($page) && (string) $page['component'] === '/system/account-policy/index', 'account policy page seed is missing');
$buttons = Db::table('sm_tenant_menu')->where('organization', 0)
    ->where('parent_id', (int) $page['id'])->where('type', 3)->select()->toArray();
$assert(count($buttons) === 2, 'account policy permission buttons were not seeded exactly');
$menuIds = array_merge([(int) $page['id']], array_map(
    static fn (array $row): int => (int) $row['id'], $buttons,
));
$groupCount = (int) Db::table('sm_tenant_group')->where('status', 1)->whereNull('delete_time')->count();
$assert((int) Db::table('sm_tenant_group_menu')->whereIn('menu_id', $menuIds)->count() === 3 * $groupCount, 'active group mappings are incomplete');
$inactiveGroupId = (int) Db::table('sm_tenant_group')->insertGetId([
    'group_name' => 'Account Policy Inactive Fixture', 'status' => 2,
    'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
]);
Db::table('sm_tenant_group_menu')->insert(['group_id' => $inactiveGroupId, 'menu_id' => $menuIds[0]]);
$permissionMigration->up();
$assert((int) Db::table('sm_tenant_menu')->whereIn('id', $menuIds)->count() === 3, 'permission replay duplicated or removed menus');
Db::table('sm_tenant_group_menu')->where('group_id', $inactiveGroupId)->delete();
Db::table('sm_tenant_group')->where('id', $inactiveGroupId)->delete();
$activeGroupId = (int) Db::table('sm_tenant_group')->where('status', 1)->whereNull('delete_time')->value('id');
Db::table('sm_tenant_group_menu')->insert(['group_id' => $activeGroupId, 'menu_id' => $menuIds[0]]);
Db::table('sm_tenant_menu')->where('id', $menuIds[0])->update(['name' => 'Rollback Fixture']);
$seedRollbackFailure = null;
try {
    $permissionMigration->up();
} catch (Throwable $throwable) {
    $seedRollbackFailure = $throwable;
}
$assert($seedRollbackFailure instanceof Throwable, 'duplicate active group mapping was accepted');
$assert((string) Db::table('sm_tenant_menu')->where('id', $menuIds[0])->value('name') === 'Rollback Fixture', 'failed seed did not roll back menu writes');
Db::table('sm_tenant_group_menu')->where('group_id', $activeGroupId)->where('menu_id', $menuIds[0])->order('id', 'desc')->limit(1)->delete();
$permissionMigration->up();
$permissionMigration->down();
$assert((int) Db::table('sm_tenant_menu')->whereIn('id', $menuIds)->count() === 0, 'permission down retained owned menus');
$assert((int) Db::table('sm_tenant_group_menu')->whereIn('menu_id', $menuIds)->count() === 0, 'permission down retained mappings');
$permissionMigration->up();
Db::execute("ALTER TABLE `sm_tenant_account_policy` MODIFY COLUMN `register_enabled` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否开放注册'");
Db::execute('ALTER TABLE `sm_tenant_account_policy` DROP COLUMN `version`');
$shapeFailure = null;
try {
    $registrationCloseMigration->up();
} catch (Throwable $throwable) {
    $shapeFailure = $throwable;
} finally {
    Db::execute(<<<'SQL'
ALTER TABLE `sm_tenant_account_policy`
  ADD COLUMN `version` bigint(20) UNSIGNED NOT NULL DEFAULT 1 COMMENT '乐观锁版本' AFTER `status`
SQL);
}
$assert($shapeFailure instanceof Throwable, 'partial account policy schema was accepted');
$assert(str_contains($shapeFailure->getMessage(), 'column shape drift'), 'partial schema failure did not identify column shape drift');
$assert($columnDefault() === 1, 'partial schema failure mutated the legacy default');
$expectShapeFailure = static function (string $mutate, string $restore, string $message) use (
    $registrationCloseMigration, $columnDefault, $assert,
): void {
    Db::execute($mutate);
    $beforeDefault = $columnDefault();
    $failure = null;
    try {
        $registrationCloseMigration->up();
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }
    $afterFailureDefault = $columnDefault();
    Db::execute($restore);
    $assert($failure instanceof Throwable, $message . ' was accepted');
    $assert($afterFailureDefault === $beforeDefault, $message . ' mutated the registration default before failing');
};
$expectShapeFailure(
    "ALTER TABLE sm_tenant_account_policy MODIFY invite_required smallint UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否要求邀请码'",
    "ALTER TABLE sm_tenant_account_policy MODIFY invite_required tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否要求邀请码'",
    'wrong column type',
);
$expectShapeFailure(
    "ALTER TABLE sm_tenant_account_policy MODIFY version bigint UNSIGNED NULL DEFAULT 1 COMMENT '乐观锁版本'",
    "ALTER TABLE sm_tenant_account_policy MODIFY version bigint(20) UNSIGNED NOT NULL DEFAULT 1 COMMENT '乐观锁版本'",
    'nullable version',
);
$expectShapeFailure(
    "ALTER TABLE sm_tenant_account_policy MODIFY register_enabled tinyint(3) UNSIGNED NOT NULL DEFAULT 2 COMMENT '是否开放注册'",
    "ALTER TABLE sm_tenant_account_policy MODIFY register_enabled tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否开放注册'",
    'unsupported registration default',
);
$expectShapeFailure(
    'ALTER TABLE sm_tenant_account_policy DROP INDEX idx_tenant_account_policy_status',
    'ALTER TABLE sm_tenant_account_policy ADD INDEX idx_tenant_account_policy_status (status) USING BTREE',
    'missing policy index',
);
$expectShapeFailure(
    "ALTER TABLE sm_tenant_account_policy COMMENT='drift'",
    "ALTER TABLE sm_tenant_account_policy COMMENT='租户账号准入策略'",
    'wrong table shape',
);
$expectShapeFailure(
    'CREATE TRIGGER account_policy_hostile BEFORE UPDATE ON sm_tenant_account_policy FOR EACH ROW SET NEW.update_time=NEW.update_time',
    'DROP TRIGGER account_policy_hostile',
    'unexpected policy trigger',
);
$expectShapeFailure(
    'ALTER TABLE sm_tenant_account_policy ADD CONSTRAINT chk_account_policy_hostile CHECK (version >= 1)',
    'ALTER TABLE sm_tenant_account_policy DROP CHECK chk_account_policy_hostile',
    'unexpected policy check constraint',
);
$expectDataFailure = static function (int $enabled, string $version, string $message) use (
    $registrationCloseMigration, $columnDefault, $assert,
): void {
    Db::table('sm_tenant_account_policy')->where('organization', 1)->update([
        'register_enabled' => $enabled, 'version' => $version,
    ]);
    $failure = null;
    try {
        $registrationCloseMigration->up();
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }
    $assert($failure instanceof Throwable, $message . ' was accepted');
    $assert($columnDefault() === 1, $message . ' mutated the default before failing');
};
$expectDataFailure(0, '0', 'zero policy version');
$expectDataFailure(1, '9007199254740991', 'non-incrementable policy version');
$expectDataFailure(0, '9007199254740992', 'JS-unsafe policy version');
Db::table('sm_tenant_account_policy')->where('organization', 1)->update([
    'register_enabled' => 1,
    'version' => 41,
]);
$legacyPolicy = Db::table('sm_tenant_account_policy')->where('organization', 1)->find();
unset($legacyPolicy['id']);
Db::table('sm_tenant_account_policy')->insert(array_replace($legacyPolicy, [
    'organization' => 2,
    'register_enabled' => 2,
    'version' => 51,
]));
Db::table('sm_tenant_account_policy')->insert(array_replace($legacyPolicy, [
    'organization' => 3,
    'register_enabled' => 0,
    'version' => 61,
]));
$registrationCloseMigration->up();
$assert($columnDefault() === 0, 'destructive migration did not restore the closed schema default');
$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 1)->value('register_enabled') === 0, 'legacy open policy was not closed');
$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 1)->value('version') === 42, 'legacy open policy version was not advanced once');
$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 2)->value('register_enabled') === 0, 'invalid non-zero policy was not closed');
$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 2)->value('version') === 52, 'invalid non-zero policy version was not advanced once');
$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 3)->value('version') === 61, 'already closed policy version changed');
$registrationCloseMigration->up();
$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 1)->value('version') === 42, 'forward replay advanced a closed policy version');
$registrationCloseMigration->down();
$assert($columnDefault() === 0, 'failed rollback reopened the schema default');
$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 1)->value('register_enabled') === 0, 'failed rollback reopened an existing policy');
$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 1)->value('version') === 42, 'fail-closed rollback changed an already closed policy version');
$assert((int) Db::table('im_web_qr_login')->count() === 0, 'QR table was not created cleanly');
Db::table('sm_tenant_quota')->where('organization', 1)->where('quota_key', 'im_user_seats')->update([
    'quota_value' => 2,
    'used_value' => 0,
    'update_time' => date('Y-m-d H:i:s'),
]);

$now = time();
$tokens = new WebTokenService(str_repeat('integration-secret-', 4));
$auth = new WebImAuthService(
    new ThinkOrmWebImAuthStore(),
    $tokens,
    new ImChallengeTokenService(base64_encode(random_bytes(48)), 300),
    static function () use (&$now): int { return $now; },
    null,
    null,
    new WebImPolicyGuard(new ThinkOrmWebImPolicyStore()),
);
$policy = new TenantAccountPolicyService(new ThinkOrmTenantAccountPolicyStore());
$registration = new WebRegistrationService(
    $policy,
    new ImUserManagementService(),
    $auth,
    static fn (string $uuid, string $code): bool => $uuid === '00000000-0000-4000-8000-000000000001' && $code === 'ABCD',
);
$organization = ['id' => 1, 'deployment_id' => 'b8im-local'];
$inputData = [
    'organization' => 999,
    'account' => 'web_register_a',
    'password' => 'Password123!',
    'password_confirm' => 'Password123!',
    'nickname' => 'Web Register A',
    'device_id' => 'browser-register-a',
    'uuid' => '00000000-0000-4000-8000-000000000001',
    'code' => 'ABCD',
];

$expectCode(403, static fn () => $registration->register($organization, $inputData, '203.0.113.1'));
$currentPolicy = $policy->read(1);
$policy->update(1, ['register_enabled' => true, 'version' => $currentPolicy['version']]);
$expectCode(422, static fn () => $registration->register($organization, array_replace($inputData, ['code' => 'WXYZ']), '203.0.113.1'));

$registrationAtomicTables = [
    'im_user',
    'im_user_profile',
    'im_user_privacy_setting',
    'im_user_security_policy',
    'im_user_group_access_state',
    'im_web_access_session',
    'im_user_login_audit',
];
$registrationAtomicCounts = [];
foreach ($registrationAtomicTables as $table) {
    $registrationAtomicCounts[$table] = (int) Db::table($table)->count();
}
$quotaBeforeAtomicRegistration = (int) Db::table('sm_tenant_quota')
    ->where('organization', 1)
    ->where('quota_key', 'im_user_seats')
    ->value('used_value');
Db::execute(
    "CREATE TRIGGER web_registration_login_audit_fail_test
     BEFORE INSERT ON im_user_login_audit
     FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced registration login audit failure'",
);
$registrationAtomicFailure = null;
try {
    $registration->register($organization, array_replace($inputData, [
        'account' => 'web_register_atomic_failure',
        'nickname' => 'Web Register Atomic Failure',
        'device_id' => 'browser-register-atomic-failure',
    ]), '203.0.113.9');
} catch (Throwable $throwable) {
    $registrationAtomicFailure = $throwable;
} finally {
    Db::execute('DROP TRIGGER IF EXISTS web_registration_login_audit_fail_test');
}
$assert($registrationAtomicFailure instanceof Throwable, 'Forced registration login audit failure did not abort registration.');
foreach ($registrationAtomicCounts as $table => $count) {
    $assert((int) Db::table($table)->count() === $count, "Registration rollback left rows in {$table}.");
}
$assert(
    (int) Db::table('sm_tenant_quota')
        ->where('organization', 1)
        ->where('quota_key', 'im_user_seats')
        ->value('used_value') === $quotaBeforeAtomicRegistration,
    'Registration rollback changed the seat quota.',
);

$registered = $registration->register($organization, $inputData, '203.0.113.1');
$assert($registered['organization'] === 1 && $registered['deployment_id'] === 'b8im-local', 'request-body organization affected registration scope');
$assert(isset($registered['token']['access_token']) && $registered['user']['account'] === 'web_register_a', 'registration did not issue a Web session');
$claims = $tokens->verifyAccess($registered['token']['access_token'], 1, 'b8im-local', 'web', $now);
$assert($claims['device_id'] === 'browser-register-a' && $claims['client_family'] === 'web' && $claims['os'] === 'browser', 'registration bearer binding mismatch');
$userId = (string) $claims['user_id'];
$imUserId = (int) $claims['id'];
$assert((int) Db::table('im_user_profile')->where('organization', 1)->where('user_id', $userId)->count() === 1, 'registration profile missing');
$assert((int) Db::table('im_user_privacy_setting')->where('organization', 1)->where('user_id', $userId)->count() === 1, 'registration privacy defaults missing');
$assert((int) Db::table('im_user_security_policy')->where('organization', 1)->where('user_id', $userId)->count() === 1, 'registration security defaults missing');
$registrationGroupAccessState = Db::table('im_user_group_access_state')
    ->where('organization', 1)
    ->where('user_id', $userId)
    ->select()
    ->toArray();
$assert(count($registrationGroupAccessState) === 1, 'registration group access state missing or duplicated');
$assert((string) $registrationGroupAccessState[0]['access_snapshot_id'] === '1', 'registration group access snapshot must start at 1');
$assert((int) Db::table('sm_tenant_quota')->where('organization', 1)->where('quota_key', 'im_user_seats')->value('used_value') === 1, 'registration seat usage was not synchronized');
$assert(Db::table('im_user_login_audit')->where('organization', 1)->where('user_id', $userId)->where('audit_scope', 'register')->where('login_result', 'success')->count() === 1, 'registration audit method missing');
$expectCode(422, static fn () => $registration->register($organization, $inputData, '203.0.113.1'));

$secondInput = $inputData;
$secondInput['account'] = 'web_register_b';
$secondInput['nickname'] = 'Web Register B';
$secondInput['device_id'] = 'browser-register-b';
$second = $registration->register($organization, $secondInput, '203.0.113.2');
$assert(isset($second['token']['access_token']), 'second seat registration failed unexpectedly');
$thirdInput = $inputData;
$thirdInput['account'] = 'web_register_c';
$thirdInput['nickname'] = 'Web Register C';
$thirdInput['device_id'] = 'browser-register-c';
$expectCode(422, static fn () => $registration->register($organization, $thirdInput, '203.0.113.3'));

$qr = new WebQrLoginService(
    new ThinkOrmWebQrLoginStore(),
    $auth,
    static function () use (&$now): int { return $now; },
);
$created = $qr->create($organization, 'browser-qr-a', 'https://web.example.test');
$stored = Db::table('im_web_qr_login')->where('qr_id', $created['qr_id'])->find();
$assert(is_array($stored) && !str_contains(json_encode($stored, JSON_THROW_ON_ERROR), $created['browser_token']), 'QR row leaked browser token');
parse_str((string) parse_url($created['qr_content'], PHP_URL_QUERY), $qrQuery);
$scanToken = (string) ($qrQuery['scan_token'] ?? '');
$assert($scanToken !== '' && !str_contains($created['qr_content'], $created['browser_token']), 'QR token separation contract mismatch');
$appIdentity = [
    'id' => $imUserId,
    'organization' => 1,
    'deployment_id' => 'b8im-local',
    'user_id' => $userId,
    'device_id' => 'app-device-a',
    'client_family' => 'app',
    'os' => 'android',
];
$expectCode(403, static fn () => $qr->scan($organization, $appIdentity, $created['qr_id'], str_repeat('X', 43), '203.0.113.4'));
$scanned = $qr->scan($organization, $appIdentity, $created['qr_id'], $scanToken, '203.0.113.4');
$assert($scanned['qr_id'] === $created['qr_id'] && $scanned['status'] === 'scanned', 'integration scan response mismatch');
$confirmed = $qr->confirm($organization, $appIdentity, $created['qr_id'], '203.0.113.4');
$assert($confirmed === ['qr_id' => $created['qr_id'], 'status' => 'confirmed'], 'integration confirm response mismatch');
$firstPoll = $qr->poll($organization, $created['qr_id'], $created['browser_token'], 'browser-qr-a', '203.0.113.5');
$assert($firstPoll['status'] === 'confirmed' && isset($firstPoll['token'], $firstPoll['user']), 'first poll response mismatch');
$assert(Db::table('im_web_qr_login')->where('qr_id', $created['qr_id'])->value('status') === 'consumed', 'first poll did not persist consumed');
$repeatPoll = $qr->poll($organization, $created['qr_id'], $created['browser_token'], 'browser-qr-a', '203.0.113.5');
$assert($repeatPoll === ['status' => 'consumed'], 'repeat poll returned credentials');
$expectCode(404, static fn () => $qr->poll(['id' => 2, 'deployment_id' => 'b8im-local'], $created['qr_id'], $created['browser_token'], 'browser-qr-a', '203.0.113.5'));
$expectCode(404, static fn () => $qr->poll(['id' => 1, 'deployment_id' => 'other-deployment'], $created['qr_id'], $created['browser_token'], 'browser-qr-a', '203.0.113.5'));

$expired = $qr->create($organization, 'browser-qr-expired', 'https://web.example.test');
$now += 121;
$expectCode(409, static fn () => $qr->poll($organization, $expired['qr_id'], $expired['browser_token'], 'browser-qr-expired', '203.0.113.6'));
$assert(Db::table('im_web_qr_login')->where('qr_id', $expired['qr_id'])->value('status') === 'expired', 'expired QR did not close');

fwrite(STDOUT, sprintf("Web registration/QR integration (%s): %d assertions passed.\n", $database, $assertions));
