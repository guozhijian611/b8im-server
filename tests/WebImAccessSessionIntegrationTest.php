<?php

declare(strict_types=1);

$database = trim((string) getenv('WEB_IM_ACCESS_TEST_DB_NAME'));
if (preg_match('/^nb8im_web_access_[a-f0-9]{8,24}_test$/', $database) !== 1) {
    throw new RuntimeException('Web access integration requires nb8im_web_access_<random>_test.');
}
foreach (['DB_NAME' => $database, 'IM_MESSAGE_SHARD_BUCKETS' => '1'] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

// Server and IM .env loaders must never redirect this destructive test after
// bootstrap; assert the isolated name again before configuring either stack.
foreach (['DB_NAME' => $database, 'IM_MESSAGE_SHARD_BUCKETS' => '1'] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\app\logic\system\SystemOrganizationLogic;
use plugin\saimulti\service\WebTokenService;
use plugin\saimulti\service\adminIm\AdminImOperationsService;
use plugin\saimulti\service\adminIm\AdminImRealtimePublisherInterface;
use plugin\saimulti\service\adminIm\AdminImSessionCacheInterface;
use plugin\saimulti\service\adminIm\AdminImStorageInspectorInterface;
use plugin\saimulti\service\adminIm\OrganizationImAccessService;
use plugin\saimulti\service\adminIm\ThinkOrmAdminImStore;
use plugin\saimulti\service\web\ImChallengeTokenService;
use plugin\saimulti\service\web\ThinkOrmWebImAccessSessionStore;
use plugin\saimulti\service\web\ThinkOrmWebImAuthStore;
use plugin\saimulti\service\web\ThinkOrmWebImPolicyStore;
use plugin\saimulti\service\web\WebImAccessSessionGuard;
use plugin\saimulti\service\web\WebImAuthService;
use plugin\saimulti\service\web\WebImAvatarServiceInterface;
use plugin\saimulti\service\web\WebImLoginRateLimiterInterface;
use plugin\saimulti\service\web\WebImPolicyGuard;
use support\think\Db;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class AccessIntegrationRateLimiter implements WebImLoginRateLimiterInterface
{
    public function assertAllowed(int $organization, string $account, string $clientIp): void
    {
    }
}

final class AccessIntegrationAvatar implements WebImAvatarServiceInterface
{
    public function assertOwnedImage(int $organization, string $ownerUserId, string $fileId): string
    {
        throw new RuntimeException('Avatar writes are outside this integration test.');
    }

    public function project(int $organization, string $fileId): array
    {
        throw new RuntimeException('Avatar projection is outside this integration test.');
    }
}

final class AccessIntegrationSessionCache implements AdminImSessionCacheInterface
{
    /** @var list<array{0: int, 1: string}> */
    public array $invalidated = [];

    public function status(): array
    {
        return ['status' => 'up', 'max_stale_seconds' => 3];
    }

    public function invalidate(int $organization, string $sessionId): bool
    {
        $this->invalidated[] = [$organization, $sessionId];

        return true;
    }

    public function maxStaleSeconds(): int
    {
        return 3;
    }
}

final class AccessIntegrationRealtime implements AdminImRealtimePublisherInterface
{
    /** @var list<array{type: string, payload: array<string, mixed>}> */
    public array $events = [];

    public function publish(string $type, array $payload): bool
    {
        $this->events[] = compact('type', 'payload');

        return true;
    }
}

final class AccessIntegrationStorage implements AdminImStorageInspectorInterface
{
    public function inspect(): array
    {
        return ['status' => 'ready', 'mode' => 'test', 'configured' => true, 'missing' => []];
    }
}

$thinkOrmConfig = config('think-orm');
$connectionName = (string) ($thinkOrmConfig['default'] ?? 'mysql');
$connection = $thinkOrmConfig['connections'][$connectionName] ?? null;
if (!is_array($connection)) {
    throw new RuntimeException('ThinkORM MySQL connection is unavailable.');
}
$adminPdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        (string) $connection['hostname'],
        (int) $connection['hostport'],
        (string) $connection['charset'],
    ),
    (string) $connection['username'],
    (string) $connection['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$quotedDatabase = '`' . $database . '`';
$adminPdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
$adminPdo->exec('CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) $connection['hostname'],
        (int) $connection['hostport'],
        $database,
        (string) $connection['charset'],
    ),
    (string) $connection['username'],
    (string) $connection['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);
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
        $assert($exception->getCode() === $code, 'ApiException code mismatch');
        return;
    }
    throw new RuntimeException('Expected ApiException was not thrown');
};

try {
    $pdo->exec(<<<'SQL'
CREATE TABLE sm_system_organization (
  id int unsigned NOT NULL PRIMARY KEY,
  organization_name varchar(100) NOT NULL,
  enterprise_code varchar(64) NOT NULL,
  deployment_id varchar(64) NOT NULL,
  domain varchar(64) NULL,
  title varchar(100) NOT NULL,
  copyright varchar(255) NULL,
  api_server_url varchar(255) NOT NULL,
  im_server_url varchar(255) NOT NULL,
  upload_server_url varchar(255) NOT NULL,
  web_server_url varchar(255) NOT NULL,
  config_version bigint unsigned NOT NULL DEFAULT 1,
  status tinyint unsigned NOT NULL DEFAULT 1,
  create_time datetime NULL,
  update_time datetime NULL,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    $pdo->exec(
        'INSERT INTO sm_system_organization
            (id, organization_name, enterprise_code, deployment_id, domain, title,
             api_server_url, im_server_url, upload_server_url, web_server_url,
             config_version, status, create_time, update_time)
         VALUES
            (7, "Access Test", "access_test", "access-test-deployment", "access.example.test", "Access Test",
             "https://api.example.test", "wss://im.example.test", "https://upload.example.test", "https://web.example.test",
             1, 1, NOW(), NOW())',
    );

    $configPath = dirname(__DIR__, 2) . '/b8im-im/phinx.php';
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    $migrationOutput = new BufferedOutput();
    $manager = new Manager(new Config(require $configPath, $configPath), $input, $migrationOutput);
    $manager->migrate('development');

    $selectedPdo = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $selectedThink = (string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '');
    $assert($selectedPdo === $database && $selectedThink === $database, 'isolated database binding failed');
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM im_phinxlog WHERE version = '20260710060000'")->fetchColumn() === 1,
        'Web access migration version was not recorded',
    );
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'im_web_access_session'")->fetchColumn() === 1,
        'im_web_access_session table is missing',
    );
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'im_auth_session' AND COLUMN_NAME = 'web_access_jti'")->fetchColumn() === 1,
        'im_auth_session.web_access_jti is missing',
    );
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'im_auth_session' AND INDEX_NAME = 'idx_web_access_status'")->fetchColumn() >= 1,
        'Web access auth-session index is missing',
    );

    $pdo->exec(<<<'SQL'
CREATE TABLE sm_tenant_im_policy (
  organization int unsigned NOT NULL PRIMARY KEY,
  allowed_client_families_json longtext NOT NULL,
  status varchar(16) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    $insertPolicy = $pdo->prepare(
        'INSERT INTO sm_tenant_im_policy (organization, allowed_client_families_json, status) VALUES (7, ?, "ENABLED")',
    );
    $insertPolicy->execute([json_encode(['web', 'app'], JSON_THROW_ON_ERROR)]);
    $pdo->exec(<<<'SQL'
CREATE TABLE sm_tool_oper_log (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  username varchar(100) NOT NULL,
  app varchar(50) NOT NULL,
  method varchar(20) NOT NULL,
  router varchar(255) NOT NULL,
  service_name varchar(255) NOT NULL,
  ip varchar(45) NOT NULL,
  ip_location varchar(255) NOT NULL,
  request_data longtext NOT NULL,
  remark varchar(255) NOT NULL,
  created_by bigint unsigned NOT NULL,
  updated_by bigint unsigned NOT NULL,
  create_time datetime NOT NULL,
  update_time datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    $organizationLogic = new SystemOrganizationLogic();
    $assert($organizationLogic->editTenantProfile(7, ['title' => 'Profile Version 2']) === 1, 'first profile update failed');
    $assert($organizationLogic->editTenantProfile(7, ['copyright' => 'Profile Version 3']) === 1, 'second profile update failed');
    $versionedOrganization = Db::table('sm_system_organization')->where('id', 7)->find();
    $assert(
        is_array($versionedOrganization)
        && (int) $versionedOrganization['config_version'] === 3
        && $versionedOrganization['title'] === 'Profile Version 2'
        && $versionedOrganization['copyright'] === 'Profile Version 3'
        && $versionedOrganization['deployment_id'] === 'access-test-deployment'
        && (int) $versionedOrganization['status'] === 1,
        'partial profile updates did not atomically advance config_version or preserved trust fields',
    );

    $now = time();
    $nowText = date('Y-m-d H:i:s', $now);
    $passwordHash = password_hash('correct-password', PASSWORD_DEFAULT);
    $insertUser = $pdo->prepare(
        'INSERT INTO im_user
            (organization, user_id, account, password_hash, nickname, avatar, is_system, status, create_time, update_time)
         VALUES (7, "user_9", "alice", ?, "Alice", "", 2, 1, ?, ?)',
    );
    $insertUser->execute([$passwordHash, $nowText, $nowText]);

    $webTokens = new WebTokenService(base64_encode(random_bytes(48)), 'HS256');
    $authStore = new ThinkOrmWebImAuthStore();
    $auth = new WebImAuthService(
        $authStore,
        $webTokens,
        new ImChallengeTokenService(base64_encode(random_bytes(48)), 300),
        static fn (): int => $now,
        new AccessIntegrationRateLimiter(),
        new AccessIntegrationAvatar(),
        new WebImPolicyGuard(new ThinkOrmWebImPolicyStore()),
    );
    $organization = ['id' => 7, 'deployment_id' => 'access-test-deployment'];
    $login = $auth->login($organization, 'alice', 'correct-password', 'web-device-a', '203.0.113.10');
    $claims = $webTokens->verifyAccess($login['token']['access_token'], 7, 'access-test-deployment', $now);
    $jti = (string) $claims['jti'];
    $assert(preg_match('/^[a-f0-9]{32}$/', $jti) === 1, 'issued access jti is not canonical');
    $assert(!array_key_exists('jti', $login['token']) && !array_key_exists('jti', $login['user']), 'jti leaked as an API response field');
    $accessRow = Db::table('im_web_access_session')->where('organization', 7)->where('jti', $jti)->find();
    $assert(
        is_array($accessRow)
        && (int) $accessRow['im_user_id'] === (int) $claims['id']
        && $accessRow['user_id'] === 'user_9'
        && $accessRow['device_id'] === 'web-device-a'
        && strtotime((string) $accessRow['expire_at']) === (int) $claims['exp'],
        'login transaction did not persist the exact bearer binding',
    );

    $guard = new WebImAccessSessionGuard(
        new ThinkOrmWebImAccessSessionStore(),
        static fn (): int => $now,
    );
    $guard->assertActive($claims, 7);
    $assert(true, 'persisted bearer was rejected');

    $identity = [
        'id' => (int) $claims['id'],
        'organization' => 7,
        'deployment_id' => 'access-test-deployment',
        'user_id' => 'user_9',
        'device_id' => 'web-device-a',
        'token_exp' => (int) $claims['exp'],
        'web_access_jti' => $jti,
    ];
    $expectCode(401, static fn () => $auth->issueImToken(
        $identity,
        'other-device',
        'gateway-mismatch',
        '203.0.113.11',
    ));
    $auth->issueImToken($identity, 'web-device-a', 'gateway-client-a', '203.0.113.11');
    $firstRow = Db::table('im_auth_session')->where('organization', 7)->where('client_id', 'gateway-client-a')->find();
    $assert(is_array($firstRow) && $firstRow['web_access_jti'] === $jti, 'IM credential was not linked to bearer jti');
    Db::table('im_auth_session')->where('id', (int) $firstRow['id'])->update([
        'status' => 2,
        'revoked_at' => $nowText,
        'update_time' => $nowText,
    ]);
    $expectCode(409, static fn () => $auth->issueImToken(
        $identity,
        'web-device-a',
        'gateway-client-a',
        '203.0.113.12',
    ));
    $assert((int) Db::table('im_auth_session')->where('id', (int) $firstRow['id'])->value('status') === 2, 'revoked client_id was revived');

    Db::table('im_web_access_session')->where('organization', 7)->where('jti', $jti)->update([
        'status' => 2,
        'revoked_at' => $nowText,
        'update_time' => $nowText,
    ]);
    $expectCode(401, static fn () => $auth->issueImToken(
        $identity,
        'web-device-a',
        'gateway-rollback',
        '203.0.113.13',
    ));
    $assert(
        Db::table('im_auth_session')->where('organization', 7)->where('client_id', 'gateway-rollback')->count() === 0,
        'failed locked access-session recheck did not roll back the auth row',
    );
    Db::table('im_web_access_session')->where('organization', 7)->where('jti', $jti)->update([
        'status' => 1,
        'revoked_at' => null,
        'update_time' => $nowText,
    ]);

    $auth->issueImToken($identity, 'web-device-a', 'gateway-client-b', '203.0.113.14');
    $auth->issueImToken($identity, 'web-device-a', 'gateway-client-c', '203.0.113.15');
    $secondRowId = (int) Db::table('im_auth_session')->where('organization', 7)->where('client_id', 'gateway-client-b')->value('id');
    $cache = new AccessIntegrationSessionCache();
    $realtime = new AccessIntegrationRealtime();
    $admin = new AdminImOperationsService(
        new ThinkOrmAdminImStore(),
        $cache,
        new AccessIntegrationStorage(),
        static fn (): string => date('Y-m-d H:i:s', $now),
        $realtime,
    );
    $actor = ['id' => 1, 'username' => 'admin', 'ip' => '127.0.0.1'];
    $revoked = $admin->revokeSession($secondRowId, $actor);
    $assert($revoked['revoked_session_count'] === 2, 'admin session revoke did not cover bearer-linked IM sessions');
    $assert(
        Db::table('im_auth_session')->where('organization', 7)->where('web_access_jti', $jti)->where('status', 1)->count() === 0,
        'admin session revoke left a bearer-linked IM session active',
    );
    $assert(
        (int) Db::table('im_web_access_session')->where('organization', 7)->where('jti', $jti)->value('status') === 2,
        'admin session revoke did not revoke the linked bearer',
    );
    $assert(count($cache->invalidated) === 2 && count($realtime->events) === 2, 'linked sessions were not invalidated and published exactly once');
    $expectCode(401, static fn () => $guard->assertActive($claims, 7));

    $sessionList = $admin->sessions(['organization' => 7]);
    $assert(!str_contains(json_encode($sessionList, JSON_THROW_ON_ERROR), $jti), 'admin session list leaked jti');
    $auditPayloads = Db::table('sm_tool_oper_log')->column('request_data');
    $assert(!str_contains(json_encode($auditPayloads, JSON_THROW_ON_ERROR), $jti), 'admin operation audit leaked jti');

    $loginB = $auth->login($organization, 'alice', 'correct-password', 'web-device-b', '203.0.113.20');
    $claimsB = $webTokens->verifyAccess($loginB['token']['access_token'], 7, 'access-test-deployment', $now);
    $jtiB = (string) $claimsB['jti'];
    $identityB = [
        'id' => (int) $claimsB['id'],
        'organization' => 7,
        'deployment_id' => 'access-test-deployment',
        'user_id' => 'user_9',
        'device_id' => 'web-device-b',
        'token_exp' => (int) $claimsB['exp'],
        'web_access_jti' => $jtiB,
    ];
    $auth->issueImToken($identityB, 'web-device-b', 'gateway-client-d', '203.0.113.21');
    $deviceBId = (int) Db::table('im_user_device')
        ->where('organization', 7)
        ->where('user_id', 'user_9')
        ->where('device_id', 'web-device-b')
        ->value('id');
    $admin->setDeviceStatus($deviceBId, 2, $actor);
    $assert(
        (int) Db::table('im_web_access_session')->where('organization', 7)->where('jti', $jtiB)->value('status') === 2,
        'admin device disable did not revoke its bearer',
    );

    $loginC = $auth->login($organization, 'alice', 'correct-password', 'web-device-c', '203.0.113.30');
    $claimsC = $webTokens->verifyAccess($loginC['token']['access_token'], 7, 'access-test-deployment', $now);
    $jtiC = (string) $claimsC['jti'];
    $identityC = [
        'id' => (int) $claimsC['id'],
        'organization' => 7,
        'deployment_id' => 'access-test-deployment',
        'user_id' => 'user_9',
        'device_id' => 'web-device-c',
        'token_exp' => (int) $claimsC['exp'],
        'web_access_jti' => $jtiC,
    ];
    $auth->issueImToken($identityC, 'web-device-c', 'gateway-client-e', '203.0.113.31');
    $organizationAccess = new OrganizationImAccessService(new class() {
        public function setex(string $key, int $ttl, string $value): bool { return true; }
        public function del(string $key): int { return 1; }
        public function rPush(string $key, string $value): int { return 1; }
    });
    $revokedIds = Db::transaction(static function () use ($organizationAccess, $nowText): array {
        Db::table('sm_system_organization')->where('id', 7)->update(['status' => 2]);

        return $organizationAccess->revokeInsideTransaction(7, $nowText);
    });
    $assert($revokedIds !== [], 'organization revoke did not collect active IM sessions');
    $assert(
        Db::table('im_web_access_session')->where('organization', 7)->where('status', 1)->count() === 0,
        'organization disable left active Web bearer sessions',
    );
    $assert(
        Db::table('im_auth_session')->where('organization', 7)->where('status', 1)->count() === 0,
        'organization disable left active IM credential sessions',
    );
    $retryIds = Db::transaction(
        static fn (): array => $organizationAccess->revokeInsideTransaction(7, $nowText),
    );
    $assert($retryIds === [], 'explicit inactive-status retry was not idempotent');

    $staleLoginJti = md5('stale-login-after-organization-disable');
    $accessCountBeforeStaleLogin = (int) Db::table('im_web_access_session')->where('organization', 7)->count();
    $expectCode(403, static fn () => $authStore->recordSuccessfulLogin(
        7,
        (int) $claimsC['id'],
        $nowText,
        [],
        [
            'organization' => 7,
            'jti' => $staleLoginJti,
            'im_user_id' => (int) $claimsC['id'],
            'user_id' => 'user_9',
            'device_id' => 'stale-device',
            'status' => 1,
            'expire_at' => date('Y-m-d H:i:s', $now + 300),
            'revoked_at' => null,
            'create_time' => $nowText,
            'update_time' => $nowText,
        ],
    ));
    $assert(
        (int) Db::table('im_web_access_session')->where('organization', 7)->count() === $accessCountBeforeStaleLogin
        && Db::table('im_web_access_session')->where('organization', 7)->where('jti', $staleLoginJti)->count() === 0,
        'stale in-flight login inserted a bearer after organization disable',
    );

    Db::table('sm_system_organization')->where('id', 7)->update(['status' => 1]);
    Db::table('sm_system_organization')->where('id', 7)->update(['delete_time' => $nowText]);
    $deletedLoginJti = md5('stale-login-after-organization-delete');
    $expectCode(403, static fn () => $authStore->recordSuccessfulLogin(
        7,
        (int) $claimsC['id'],
        $nowText,
        [],
        [
            'organization' => 7,
            'jti' => $deletedLoginJti,
            'im_user_id' => (int) $claimsC['id'],
            'user_id' => 'user_9',
            'device_id' => 'deleted-race-device',
            'status' => 1,
            'expire_at' => date('Y-m-d H:i:s', $now + 300),
            'revoked_at' => null,
            'create_time' => $nowText,
            'update_time' => $nowText,
        ],
    ));
    $assert(
        Db::table('im_web_access_session')->where('organization', 7)->where('jti', $deletedLoginJti)->count() === 0,
        'stale in-flight login inserted a bearer after organization delete',
    );
    Db::table('sm_system_organization')->where('id', 7)->update(['delete_time' => null]);
    $expectCode(401, static fn () => (new WebImAccessSessionGuard(
        new ThinkOrmWebImAccessSessionStore(),
        static fn (): int => $now,
    ))->assertActive($claimsC, 7));

    Db::table('sm_tenant_im_policy')->where('organization', 7)->update(['status' => 'DISABLED']);
    $accessCount = (int) Db::table('im_web_access_session')->where('organization', 7)->count();
    $expectCode(403, static fn () => $auth->login(
        $organization,
        'alice',
        'correct-password',
        'web-device-policy-denied',
        '203.0.113.40',
    ));
    $assert(
        (int) Db::table('im_web_access_session')->where('organization', 7)->count() === $accessCount,
        'disabled tenant policy still created a Web access session',
    );

    $manager->rollback('development', '20260710050000');
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'im_web_access_session'")->fetchColumn() === 0,
        'Web access migration rollback retained its table',
    );
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'im_auth_session' AND COLUMN_NAME = 'web_access_jti'")->fetchColumn() === 0,
        'Web access migration rollback retained its auth-session column',
    );

    fwrite(STDOUT, sprintf("Web IM access-session migration/integration: %d assertions passed.\n", $assertions));
} finally {
    $pdo = null;
    $adminPdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
}
