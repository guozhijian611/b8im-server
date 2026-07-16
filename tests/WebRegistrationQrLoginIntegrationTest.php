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
foreach ([
    dirname(__DIR__, 2) . '/b8im-im/phinx.php',
    dirname(__DIR__, 4) . '/b8im-im/phinx.php',
] as $candidate) {
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

$assert((int) Db::table('sm_tenant_account_policy')->where('organization', 1)->value('register_enabled') === 1, 'existing organization was not seeded open');
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
    null,
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

Db::table('sm_tenant_account_policy')->where('organization', 1)->update(['register_enabled' => 0]);
$expectCode(403, static fn () => $registration->register($organization, $inputData, '203.0.113.1'));
Db::table('sm_tenant_account_policy')->where('organization', 1)->update(['register_enabled' => 1]);
$expectCode(422, static fn () => $registration->register($organization, array_replace($inputData, ['code' => 'WXYZ']), '203.0.113.1'));

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
