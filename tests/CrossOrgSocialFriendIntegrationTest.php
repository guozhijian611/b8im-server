<?php

declare(strict_types=1);

/**
 * Integration: switch-gated cross-org friend request/accept + contact company label.
 * Uses a temporary *_web_test database and the real ThinkOrmWebImControlStore.
 *
 * Run: WEB_IM_TEST_DB_NAME=nb8im_cross_org_web_test php tests/CrossOrgSocialFriendIntegrationTest.php
 */

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\app\logic\system\SystemConfigGroupLogic;
use plugin\saimulti\app\logic\system\SystemConfigLogic;
use plugin\saimulti\app\logic\system\SystemOrganizationLogic;
use plugin\saimulti\service\adminIm\OrganizationImAccessService;
use plugin\saimulti\service\web\CrossOrganizationSocialConfigService;
use plugin\saimulti\service\web\CrossOrganizationSocialPolicy;
use plugin\saimulti\service\web\ThinkOrmWebImControlStore;
use support\think\Db;

$database = trim((string) (getenv('WEB_IM_TEST_DB_NAME') ?: 'nb8im_cross_org_web_test'));
if (preg_match('/^[A-Za-z0-9_]+_web_test$/', $database) !== 1) {
    throw new RuntimeException('only *_web_test temp DB allowed');
}
foreach (['DB_NAME' => $database] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

foreach (['DB_NAME' => $database] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

$thinkOrmConfig = config('think-orm');
$connectionName = (string) ($thinkOrmConfig['default'] ?? 'mysql');
$connection = $thinkOrmConfig['connections'][$connectionName];
$host = (string) $connection['hostname'];
$port = (int) $connection['hostport'];
$charset = (string) $connection['charset'];
$username = (string) $connection['username'];
$password = (string) $connection['password'];
$adminPdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$quoted = '`' . $database . '`';
$adminPdo->exec('DROP DATABASE IF EXISTS ' . $quoted);
$adminPdo->exec('CREATE DATABASE ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$pdo->exec(<<<'SQL'
CREATE TABLE sm_system_organization (
  id int unsigned NOT NULL PRIMARY KEY,
  organization_name varchar(255) NULL,
  title varchar(255) NULL,
  enterprise_code varchar(64) NOT NULL,
  deployment_id varchar(64) NOT NULL,
  domain varchar(64) NULL,
  config_version bigint unsigned NOT NULL DEFAULT 1,
  status tinyint unsigned NOT NULL DEFAULT 1,
  update_time datetime NULL,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE sm_system_config_group (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code varchar(100) NOT NULL,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE sm_system_config (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id int unsigned NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text NULL,
  name varchar(100) NOT NULL DEFAULT '',
  input_type varchar(30) NOT NULL DEFAULT 'input',
  config_select_data text NULL,
  sort int NOT NULL DEFAULT 0,
  remark varchar(255) NULL,
  created_by int unsigned NOT NULL DEFAULT 1,
  updated_by int unsigned NOT NULL DEFAULT 1,
  create_time datetime NULL,
  update_time datetime NULL,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_user (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  user_id varchar(64) NOT NULL,
  account varchar(64) NOT NULL,
  password_hash varchar(255) NOT NULL DEFAULT '',
  nickname varchar(64) NOT NULL,
  avatar varchar(255) NULL,
  mobile varchar(32) NULL,
  email varchar(120) NULL,
  gender tinyint unsigned NOT NULL DEFAULT 0,
  is_system tinyint unsigned NOT NULL DEFAULT 2,
  system_code varchar(64) NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  remark varchar(255) NULL,
  login_time datetime NULL,
  create_time datetime NULL,
  update_time datetime NULL,
  delete_time datetime NULL,
  im_short_no varchar(32) NULL,
  UNIQUE KEY uni_org_user (organization, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_user_profile (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  user_id varchar(64) NOT NULL,
  signature varchar(255) NULL,
  moments_cover_url varchar(500) NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  create_time datetime NULL,
  update_time datetime NULL,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_user_privacy_setting (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  user_id varchar(64) NOT NULL,
  allow_add_by_mobile tinyint unsigned NOT NULL DEFAULT 1,
  allow_add_by_short_no tinyint unsigned NOT NULL DEFAULT 1,
  allow_add_by_username tinyint unsigned NOT NULL DEFAULT 1,
  create_time datetime NULL,
  update_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_friend_relation (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  user_id varchar(64) NOT NULL,
  friend_user_id varchar(64) NOT NULL,
  friend_organization int unsigned NOT NULL DEFAULT 0,
  add_method varchar(32) NOT NULL,
  added_at datetime NOT NULL,
  remark_name varchar(64) NULL,
  card_remark varchar(255) NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  create_time datetime NULL,
  update_time datetime NULL,
  delete_time datetime NULL,
  UNIQUE KEY uni_rel (organization, user_id, friend_organization, friend_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_friend_request (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  from_organization int unsigned NOT NULL DEFAULT 0,
  to_organization int unsigned NOT NULL DEFAULT 0,
  from_user_id varchar(64) NOT NULL,
  to_user_id varchar(64) NOT NULL,
  add_method varchar(32) NOT NULL,
  message varchar(120) NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  handle_time datetime NULL,
  create_time datetime NULL,
  update_time datetime NULL,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_conversation (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  conversation_id varchar(64) NOT NULL,
  conversation_type tinyint unsigned NOT NULL DEFAULT 1,
  title varchar(100) NULL,
  avatar varchar(255) NULL,
  owner_user_id varchar(64) NULL,
  owner_organization int unsigned NOT NULL DEFAULT 0,
  next_message_seq bigint unsigned NOT NULL DEFAULT 1,
  last_message_seq bigint unsigned NOT NULL DEFAULT 0,
  next_change_seq bigint unsigned NOT NULL DEFAULT 1,
  last_change_seq bigint unsigned NOT NULL DEFAULT 0,
  last_message_id varchar(40) NULL,
  last_message_time datetime NULL,
  last_message_summary varchar(255) NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  create_time datetime NULL,
  update_time datetime NULL,
  delete_time datetime NULL,
  UNIQUE KEY uni_org_conv (organization, conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_cross_organization_conversation (
  conversation_id varchar(64) NOT NULL PRIMARY KEY,
  left_organization int unsigned NOT NULL,
  left_user_id varchar(64) NOT NULL,
  right_organization int unsigned NOT NULL,
  right_user_id varchar(64) NOT NULL,
  next_message_seq bigint unsigned NOT NULL DEFAULT 1,
  status tinyint unsigned NOT NULL DEFAULT 1,
  create_time datetime NOT NULL,
  update_time datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_conversation_member (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  conversation_id varchar(64) NOT NULL,
  user_id varchar(64) NOT NULL,
  member_organization int unsigned NOT NULL,
  member_role varchar(16) NOT NULL DEFAULT 'member',
  inviter_user_id varchar(64) NULL,
  inviter_organization int unsigned NOT NULL DEFAULT 0,
  status tinyint unsigned NOT NULL DEFAULT 1,
  mute_status tinyint unsigned NOT NULL DEFAULT 0,
  mute_until datetime NULL,
  last_read_message_id varchar(40) NULL,
  last_read_seq bigint unsigned NOT NULL DEFAULT 0,
  unread_count int unsigned NOT NULL DEFAULT 0,
  is_pinned tinyint unsigned NOT NULL DEFAULT 2,
  is_muted tinyint unsigned NOT NULL DEFAULT 2,
  conversation_remark varchar(100) NULL,
  message_group_id bigint unsigned NOT NULL DEFAULT 0,
  access_version bigint unsigned NOT NULL DEFAULT 1,
  join_at datetime NULL,
  create_time datetime NULL,
  update_time datetime NULL,
  delete_time datetime NULL,
  UNIQUE KEY uni_org_member (organization, conversation_id, member_organization, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_conversation_membership_period (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  conversation_id varchar(64) NOT NULL,
  user_id varchar(64) NOT NULL,
  member_organization int unsigned NOT NULL,
  period_no int unsigned NOT NULL DEFAULT 1,
  visible_from_message_seq bigint unsigned NOT NULL DEFAULT 1,
  visible_until_message_seq bigint unsigned NULL,
  join_at datetime NULL,
  leave_at datetime NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  create_time datetime NULL,
  update_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_group_profile (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  conversation_id varchar(64) NOT NULL,
  description varchar(500) NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_message_group (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  user_id varchar(64) NOT NULL,
  name varchar(40) NOT NULL,
  sort int NOT NULL DEFAULT 0,
  status tinyint unsigned NOT NULL DEFAULT 1,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_message_index (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  global_seq bigint unsigned NOT NULL,
  message_id varchar(40) NOT NULL,
  conversation_id varchar(64) NOT NULL,
  message_seq bigint unsigned NOT NULL,
  sender_id varchar(64) NOT NULL,
  sender_organization int unsigned NOT NULL,
  client_msg_id varchar(80) NOT NULL,
  storage_node varchar(64) NOT NULL,
  shard_table varchar(64) NOT NULL,
  create_time datetime NULL,
  UNIQUE KEY uni_org_msg (organization, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_message_2026_000001 (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  conversation_id varchar(64) NOT NULL,
  conversation_type tinyint unsigned NOT NULL,
  message_id varchar(40) NOT NULL,
  message_seq bigint unsigned NOT NULL,
  client_msg_id varchar(80) NOT NULL,
  sender_id varchar(64) NOT NULL,
  sender_organization int unsigned NOT NULL,
  message_type int NOT NULL,
  content text NOT NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  edit_time datetime NULL,
  edit_count int unsigned NOT NULL DEFAULT 0,
  create_time datetime NULL,
  update_time datetime NULL,
  delete_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_message_user_delete (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  conversation_id varchar(64) NOT NULL,
  message_id varchar(40) NOT NULL,
  user_id varchar(64) NOT NULL,
  user_organization int unsigned NOT NULL,
  create_time datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_message_receipt (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  conversation_id varchar(64) NOT NULL,
  message_id varchar(40) NOT NULL,
  user_id varchar(64) NOT NULL,
  user_organization int unsigned NOT NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  delivered_time datetime NULL,
  read_time datetime NULL,
  create_time datetime NULL,
  update_time datetime NULL,
  UNIQUE KEY uni_home_receipt_identity_status
    (organization, message_id, user_organization, user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_message_change (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization int unsigned NOT NULL,
  conversation_id varchar(64) NOT NULL,
  change_seq bigint unsigned NOT NULL,
  message_id varchar(40) NOT NULL,
  message_seq bigint unsigned NOT NULL,
  change_type varchar(32) NOT NULL,
  target_user_id varchar(64) NULL,
  payload_json text NOT NULL,
  create_time datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE im_message_outbox (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id char(64) NOT NULL,
  organization int unsigned NOT NULL,
  event_type varchar(50) NOT NULL,
  routing_key varchar(100) NOT NULL,
  message_id varchar(40) NOT NULL,
  change_seq bigint unsigned NOT NULL DEFAULT 0,
  conversation_id varchar(64) NOT NULL,
  conversation_type tinyint unsigned NOT NULL DEFAULT 1,
  payload_json longtext NOT NULL,
  traceparent varchar(55) NULL,
  tracestate varchar(512) NULL,
  status tinyint unsigned NOT NULL DEFAULT 1,
  retry_count int unsigned NOT NULL DEFAULT 0,
  next_retry_at datetime NULL,
  locked_until datetime NULL,
  worker_id varchar(64) NULL,
  claim_token char(40) NULL,
  published_at datetime NULL,
  last_error varchar(500) NULL,
  create_time datetime NULL,
  update_time datetime NULL,
  UNIQUE KEY uni_outbox_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

$now = date('Y-m-d H:i:s');
$pdo->exec("INSERT INTO sm_system_organization
    (id, organization_name, title, enterprise_code, deployment_id, status, update_time)
 VALUES
    (1, '甲公司', '甲公司', 'org_one', 'cross-org-test', 1, NOW()),
    (2, '乙公司', '乙公司', 'org_two', 'cross-org-test', 1, NOW()),
    (3, '丙公司', '丙公司', 'org_three', 'cross-org-test', 1, NOW())");
$pdo->exec("INSERT INTO sm_system_config_group (id, code) VALUES
    (1, 'social_config'),
    (2, 'ordinary_config')");
$pdo->exec("INSERT INTO sm_system_config (id, group_id, `key`, `value`, name, input_type, create_time, update_time)
    VALUES
      (1, 1, 'cross_org_social_enabled', '0', '允许跨租户好友与单聊', 'radio', NOW(), NOW()),
      (2, 1, 'cross_org_access_snapshot_id', '0', '跨租户社交访问快照序号', 'hidden', NOW(), NOW()),
      (4, 2, 'ordinary_setting', '1', 'ordinary', 'input', NOW(), NOW())");
foreach ([
    [1, 'u1', 'alice', 'Alice'],
    [2, 'u2', 'bob', 'Bob'],
    [1, 'shared', 'shared-a', 'Shared A'],
    [2, 'shared', 'shared-b', 'Shared B'],
] as [$org, $uid, $acc, $nick]) {
    $pdo->prepare('INSERT INTO im_user (organization, user_id, account, password_hash, nickname, status, is_system, create_time, update_time) VALUES (?,?,?,?,?,1,2,?,?)')
        ->execute([$org, $uid, $acc, 'x', $nick, $now, $now]);
    $pdo->prepare('INSERT INTO im_user_privacy_setting (organization, user_id, create_time, update_time) VALUES (?,?,?,?)')
        ->execute([$org, $uid, $now, $now]);
}

$thinkOrmConfig['connections'][$connectionName]['database'] = $database;
Db::setConfig($thinkOrmConfig);
$thinkOrmDatabase = (string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '');
if ($thinkOrmDatabase !== $database) {
    throw new RuntimeException('ThinkORM not bound to temp DB: ' . $thinkOrmDatabase);
}

$passed = 0;
$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "PASS $msg\n";
        return;
    }
    $failed++;
    echo "FAIL $msg\n";
};
$expectApiCode = static function (int $code, callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message . ' (no exception)');
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, $message . ' (code ' . $exception->getCode() . ')');
    }
};
$expectRuntime = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message . ' (no exception)');
    } catch (RuntimeException) {
        $assert(true, $message);
    }
};

// Policy default off
CrossOrganizationSocialPolicy::clearCache();
$assert(CrossOrganizationSocialPolicy::isEnabled() === false, 'policy off by default');
$pdo->exec("UPDATE sm_system_config SET value = '1' WHERE id = 1");
$assert(
    CrossOrganizationSocialPolicy::isEnabled() === false,
    'enabled switch with snapshot zero fails closed without a process-local cache',
);
$pdo->exec("UPDATE sm_system_config SET value = '0' WHERE id = 1");

$store = new ThinkOrmWebImControlStore();

// Off: cross-org friend rejected
try {
    $store->sendFriendRequest(1, 'u1', 2, 'u2', 'hi', $now);
    $assert(false, 'off path should throw');
} catch (ApiException $e) {
    $assert($e->getCode() === 403 || str_contains($e->getMessage(), '跨租户'), 'off rejects cross-org friend: ' . $e->getMessage());
} catch (Throwable $e) {
    $assert(str_contains($e->getMessage(), '跨租户') || str_contains($e->getMessage(), '不存在'), 'off rejects: ' . $e->getMessage());
}

// On: config transition advances the snapshot, then friend request + accept.
(new CrossOrganizationSocialConfigService())->batchUpdate(1, [[
    'id' => 1,
    'name' => '允许跨租户好友与单聊',
    'key' => 'cross_org_social_enabled',
    'value' => '1',
]]);
CrossOrganizationSocialPolicy::clearCache();
$assert(CrossOrganizationSocialPolicy::isEnabled() === true, 'policy on');
$assert(CrossOrganizationSocialPolicy::accessSnapshotId() === '1', 'on transition advances access snapshot');
$pdo->exec(
    "INSERT INTO sm_system_organization
        (id, organization_name, title, enterprise_code, deployment_id, status, update_time)
     VALUES (10, '十号公司', '十号公司', 'org_ten', 'cross-org-test', 1, NOW())",
);
foreach ([
    [2, 'lock_user', 'lock-user-two', 'Lock User Two'],
    [10, 'lock_user', 'lock-user-ten', 'Lock User Ten'],
] as [$lockOrg, $lockUserId, $lockAccount, $lockNickname]) {
    $pdo->prepare(
        'INSERT INTO im_user
            (organization, user_id, account, password_hash, nickname, status, is_system, create_time, update_time)
         VALUES (?, ?, ?, "x", ?, 1, 2, ?, ?)',
    )->execute([$lockOrg, $lockUserId, $lockAccount, $lockNickname, $now, $now]);
    $pdo->prepare(
        'INSERT INTO im_user_privacy_setting
            (organization, user_id, create_time, update_time)
         VALUES (?, ?, ?, ?)',
    )->execute([$lockOrg, $lockUserId, $now, $now]);
}
$friendDirectionMethod = (new ReflectionClass(ThinkOrmWebImControlStore::class))
    ->getMethod('canonicalFriendDirections');
$friendDirectionMethod->setAccessible(true);
$friendDirectionsFromTwo = $friendDirectionMethod->invoke(
    null,
    2,
    'lock_user',
    10,
    'lock_user',
);
$friendDirectionsFromTen = $friendDirectionMethod->invoke(
    null,
    10,
    'lock_user',
    2,
    'lock_user',
);
$assert(
    $friendDirectionsFromTwo === $friendDirectionsFromTen
    && array_column($friendDirectionsFromTwo, 'organization') === [10, 2],
    '2/10 relation/request lock and upsert order follows canonical UTF-8 identity bytes',
);
if (function_exists('proc_open')) {
    Db::transaction(function () use (
        $host,
        $port,
        $database,
        $charset,
        $username,
        $password,
        $assert,
    ): void {
        Db::query('SELECT `value` FROM sm_system_config WHERE id = 1');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset,
        );
        $childCode = <<<'PHP'
$pdo = new PDO($argv[1], $argv[2], $argv[3], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("UPDATE sm_system_config SET value = '0' WHERE id = 1");
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $childCode, $dsn, $username, $password],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start policy lock regression.');
        }
        $childOutput = stream_get_contents($pipes[1]);
        $childError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $assert(
            $exitCode === 0,
            sprintf(
                'concurrent revoke committed after the old RR view: %s%s',
                $childOutput,
                $childError,
            ),
        );
        $lockedPolicy = CrossOrganizationSocialPolicy::lockSharedInsideTransaction();
        $assert(
            $lockedPolicy['enabled'] === false,
            'shared policy locking read ignores an old RR read view',
        );
    });
    $pdo->exec("UPDATE sm_system_config SET value = '1' WHERE id = 1");

    $barrier = sys_get_temp_dir() . '/b8im-friend-lock-' . bin2hex(random_bytes(8));
    $resultPaths = [
        $barrier . '-2-to-10.json',
        $barrier . '-10-to-2.json',
    ];
    $childCode = <<<'PHP'
$root = $argv[1];
$database = $argv[2];
$barrier = $argv[3];
$result = $argv[4];
$fromOrganization = (int) $argv[5];
$toOrganization = (int) $argv[6];
foreach (['DB_NAME' => $database] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}
require $root . '/vendor/autoload.php';
require $root . '/support/bootstrap.php';
try {
    $deadline = microtime(true) + 10;
    while (!is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('friend lock-order barrier timeout');
        }
        usleep(1000);
    }
    $response = (new \plugin\saimulti\service\web\ThinkOrmWebImControlStore())
        ->sendFriendRequest(
            $fromOrganization,
            'lock_user',
            $toOrganization,
            'lock_user',
            'reverse concurrent lock-order probe',
            date('Y-m-d H:i:s'),
        );
    file_put_contents($result, json_encode($response, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($result, json_encode([
        'error' => get_class($exception) . ': ' . $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP;
    $processes = [];
    foreach ([[2, 10], [10, 2]] as $index => [$fromOrganization, $toOrganization]) {
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $childCode,
                dirname(__DIR__),
                $database,
                $barrier,
                $resultPaths[$index],
                (string) $fromOrganization,
                (string) $toOrganization,
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start reverse friend request process.');
        }
        $processes[] = [$process, $pipes];
    }
    touch($barrier);
    $childErrors = [];
    foreach ($processes as [$process, $pipes]) {
        $childOutput = stream_get_contents($pipes[1]);
        $childError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $childErrors[] = $childOutput . $childError;
        }
    }
    $reverseResults = array_map(
        static fn (string $path): array => is_file($path)
            ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : ['error' => 'missing child result'],
        $resultPaths,
    );
    @unlink($barrier);
    foreach ($resultPaths as $resultPath) {
        @unlink($resultPath);
    }
    $reverseStatuses = array_column($reverseResults, 'status');
    sort($reverseStatuses, SORT_STRING);
    $reverseRelations = (int) $pdo->query(
        "SELECT COUNT(*) FROM im_friend_relation
          WHERE user_id = 'lock_user'
            AND friend_user_id = 'lock_user'
            AND (
                (organization = 2 AND friend_organization = 10)
                OR
                (organization = 10 AND friend_organization = 2)
            )
            AND status = 1
            AND delete_time IS NULL",
    )->fetchColumn();
    $acceptedReverseRequests = (int) $pdo->query(
        "SELECT COUNT(*) FROM im_friend_request
          WHERE (
                (from_organization = 2 AND to_organization = 10)
                OR
                (from_organization = 10 AND to_organization = 2)
            )
            AND from_user_id = 'lock_user'
            AND to_user_id = 'lock_user'
            AND status = 2
            AND delete_time IS NULL",
    )->fetchColumn();
    $reverseProbePassed = $childErrors === []
        && $reverseStatuses === ['accepted', 'pending']
        && $reverseRelations === 2
        && $acceptedReverseRequests === 1;
    $assert(
        $reverseProbePassed,
        '2/10 reverse concurrent friend requests serialize without deadlock or duplicate gaps: '
        . ($reverseProbePassed ? 'ok' : json_encode([
            'results' => $reverseResults,
            'relations' => $reverseRelations,
            'accepted_requests' => $acceptedReverseRequests,
            'child_errors' => $childErrors,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    );

    foreach ([
        [1, 'toctou_old', 'toctou-old', 'TOCTOU Old'],
        [1, 'toctou_new', 'toctou-new', 'TOCTOU New'],
        [2, 'toctou_target', 'toctou-target', 'TOCTOU Target'],
    ] as [$toctouOrg, $toctouUserId, $toctouAccount, $toctouNickname]) {
        $pdo->prepare(
            'INSERT INTO im_user
                (organization, user_id, account, password_hash, nickname, status, is_system, create_time, update_time)
             VALUES (?, ?, ?, "x", ?, 1, 2, ?, ?)',
        )->execute([$toctouOrg, $toctouUserId, $toctouAccount, $toctouNickname, $now, $now]);
    }
    $pdo->prepare(
        'INSERT INTO im_friend_request
            (organization, from_organization, to_organization, from_user_id, to_user_id,
             add_method, message, status, create_time, update_time)
         VALUES (2, 1, 2, "toctou_old", "toctou_target",
                 "username", "identity revalidation probe", 1, ?, ?)',
    )->execute([$now, $now]);
    $toctouRequestId = (int) $pdo->lastInsertId();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $port,
        $database,
        $charset,
    );
    $toctouPrefix = sys_get_temp_dir() . '/b8im-friend-toctou-' . bin2hex(random_bytes(8));
    $toctouStartPath = $toctouPrefix . '-start';
    $toctouStatePath = $toctouPrefix . '-state.json';
    $toctouResultPath = $toctouPrefix . '-result.json';
    $toctouChildCode = <<<'PHP'
$root = $argv[1];
$database = $argv[2];
$startPath = $argv[3];
$statePath = $argv[4];
$resultPath = $argv[5];
$requestId = (int) $argv[6];
foreach (['DB_NAME' => $database] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}
require $root . '/vendor/autoload.php';
require $root . '/support/bootstrap.php';
try {
    $deadline = microtime(true) + 10;
    while (!is_file($startPath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('TOCTOU start barrier timeout.');
        }
        usleep(1000);
    }
    file_put_contents($statePath, json_encode([
        'ready' => true,
    ], JSON_THROW_ON_ERROR));
    $response = (new \plugin\saimulti\service\web\ThinkOrmWebImControlStore())
        ->handleFriendRequest(
            2,
            'toctou_target',
            $requestId,
            'accept',
            date('Y-m-d H:i:s'),
        );
    file_put_contents($resultPath, json_encode([
        'unexpected_response' => $response,
    ], JSON_THROW_ON_ERROR));
    exit(2);
} catch (\plugin\saimulti\exception\ApiException $exception) {
    file_put_contents($resultPath, json_encode([
        'api_code' => $exception->getCode(),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'error' => get_class($exception) . ': ' . $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP;
    $toctouProcess = proc_open(
        [
            PHP_BINARY,
            '-r',
            $toctouChildCode,
            dirname(__DIR__),
            $database,
            $toctouStartPath,
            $toctouStatePath,
            $toctouResultPath,
            (string) $toctouRequestId,
        ],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $toctouPipes,
    );
    if (!is_resource($toctouProcess)) {
        throw new RuntimeException('Unable to start friend request TOCTOU regression.');
    }

    // Open the blocker only after proc_open so the child cannot inherit and
    // close its MySQL socket during exec.
    $identityBlocker = new PDO(
        $dsn,
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $identityBlocker->beginTransaction();
    $lockedUserId = $identityBlocker->query(
        'SELECT id
           FROM im_user
          WHERE organization = 1
            AND user_id = "toctou_old"
            AND status = 1
            AND delete_time IS NULL
          LIMIT 1
          FOR UPDATE',
    )->fetchColumn();
    if ((int) $lockedUserId <= 0) {
        $identityBlocker->rollBack();
        touch($toctouStartPath);
        throw new RuntimeException('Unable to lock preview user for TOCTOU regression.');
    }
    $identityBlockerConnectionId = (int) $identityBlocker
        ->query('SELECT CONNECTION_ID()')
        ->fetchColumn();
    $blockerProbe = $pdo->prepare(
        'SELECT trx_state
           FROM information_schema.innodb_trx
          WHERE trx_mysql_thread_id = ?',
    );
    $blockerActive = false;
    $blockerDeadline = microtime(true) + 2;
    while (microtime(true) < $blockerDeadline) {
        $blockerProbe->execute([$identityBlockerConnectionId]);
        if (($blockerProbe->fetchColumn() ?: '') === 'RUNNING') {
            $blockerActive = true;
            break;
        }
        usleep(1000);
    }
    if (!$blockerActive) {
        $identityBlocker->rollBack();
        touch($toctouStartPath);
        throw new RuntimeException('Preview-user blocker transaction is not active.');
    }
    touch($toctouStartPath);

    $toctouBarrierError = '';
    $toctouUpdatedRows = 0;
    try {
        $deadline = microtime(true) + 10;
        $toctouState = null;
        while (microtime(true) < $deadline) {
            if (is_file($toctouStatePath)) {
                $candidate = json_decode((string) file_get_contents($toctouStatePath), true);
                if (($candidate['ready'] ?? false) === true) {
                    $toctouState = $candidate;
                    break;
                }
            }
            usleep(1000);
        }
        if (($toctouState['ready'] ?? false) !== true) {
            throw new RuntimeException('Child did not publish its readiness state.');
        }

        $waitProbe = $pdo->prepare(
            'SELECT id, state, info
               FROM information_schema.processlist
              WHERE db = ?
                AND id <> ?
                AND id <> CONNECTION_ID()
                AND command IN ("Query", "Execute")
                AND info LIKE "%FROM im_user u%"
                AND info LIKE "%FOR UPDATE%"',
        );
        $observedIdentityWait = false;
        while (microtime(true) < $deadline) {
            $waitProbe->execute([$database, $identityBlockerConnectionId]);
            $waitingProcess = $waitProbe->fetch(PDO::FETCH_ASSOC);
            if (is_array($waitingProcess)) {
                $observedIdentityWait = true;
                break;
            }
            usleep(1000);
        }
        if (!$observedIdentityWait) {
            $transactions = $pdo->query(
                'SELECT trx_state, trx_mysql_thread_id, trx_query
                   FROM information_schema.innodb_trx',
            )->fetchAll(PDO::FETCH_ASSOC);
            $processes = $pdo->prepare(
                'SELECT id, command, state, info
                   FROM information_schema.processlist
                  WHERE db = ?',
            );
            $processes->execute([$database]);
            throw new RuntimeException(
                'Child did not reach the preview-user row-lock barrier: '
                . json_encode([
                    'blocker_connection_id' => $identityBlockerConnectionId,
                    'transactions' => $transactions,
                    'processes' => $processes->fetchAll(PDO::FETCH_ASSOC),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        $mutateRequest = $identityBlocker->prepare(
            'UPDATE im_friend_request
                SET from_user_id = "toctou_new", update_time = ?
              WHERE id = ?
                AND from_organization = 1
                AND from_user_id = "toctou_old"
                AND to_organization = 2
                AND to_user_id = "toctou_target"
                AND status = 1',
        );
        $mutateRequest->execute([$now, $toctouRequestId]);
        $toctouUpdatedRows = $mutateRequest->rowCount();
    } catch (Throwable $exception) {
        $toctouBarrierError = get_class($exception) . ': ' . $exception->getMessage();
    } finally {
        if ($identityBlocker->inTransaction()) {
            $identityBlocker->commit();
        }
    }

    $toctouChildOutput = stream_get_contents($toctouPipes[1]);
    $toctouChildError = stream_get_contents($toctouPipes[2]);
    fclose($toctouPipes[1]);
    fclose($toctouPipes[2]);
    $toctouExitCode = proc_close($toctouProcess);
    $toctouResult = is_file($toctouResultPath)
        ? json_decode(
            (string) file_get_contents($toctouResultPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )
        : ['error' => 'missing child result'];
    @unlink($toctouStartPath);
    @unlink($toctouStatePath);
    @unlink($toctouResultPath);

    $toctouRelationCount = (int) $pdo->query(
        "SELECT COUNT(*)
           FROM im_friend_relation
          WHERE status = 1
            AND delete_time IS NULL
            AND (
                (
                    organization = 1
                    AND user_id IN ('toctou_old', 'toctou_new')
                    AND friend_organization = 2
                    AND friend_user_id = 'toctou_target'
                )
                OR
                (
                    organization = 2
                    AND user_id = 'toctou_target'
                    AND friend_organization = 1
                    AND friend_user_id IN ('toctou_old', 'toctou_new')
                )
            )",
    )->fetchColumn();
    $toctouRequest = $pdo->query(
        'SELECT from_user_id, status
           FROM im_friend_request
          WHERE id = ' . $toctouRequestId,
    )->fetch(PDO::FETCH_ASSOC);
    $toctouProbePassed = $toctouBarrierError === ''
        && $toctouUpdatedRows === 1
        && $toctouExitCode === 0
        && in_array((int) ($toctouResult['api_code'] ?? 0), [404, 409], true)
        && ($toctouRequest['from_user_id'] ?? '') === 'toctou_new'
        && (int) ($toctouRequest['status'] ?? 0) === 1
        && $toctouRelationCount === 0;
    $assert(
        $toctouProbePassed,
        'friend accept rejects an identity changed after preview without creating old/new relations: '
        . ($toctouProbePassed ? 'ok' : json_encode([
            'barrier_error' => $toctouBarrierError,
            'updated_rows' => $toctouUpdatedRows,
            'exit_code' => $toctouExitCode,
            'child_output' => $toctouChildOutput,
            'child_error' => $toctouChildError,
            'result' => $toctouResult,
            'request' => $toctouRequest,
            'relations' => $toctouRelationCount,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    );
} else {
    echo "SKIP proc_open policy lock regression\n";
}
$configLogic = new SystemConfigLogic();
$expectApiCode(403, static fn () => $configLogic->add([
    'group_id' => 1,
    'key' => 'cross_org_social_enabled',
    'value' => '0',
    'name' => 'spoofed managed switch',
]), 'ordinary config add cannot forge the managed switch');
$expectApiCode(403, static fn () => $configLogic->destroy([1]), 'ordinary config destroy cannot remove the managed switch');
$expectApiCode(403, static fn () => $configLogic->destroy([2]), 'ordinary config destroy cannot remove the managed snapshot');
$expectApiCode(
    403,
    static fn () => (new SystemConfigGroupLogic())->destroy([1]),
    'ordinary config-group destroy cannot remove social_config',
);
$expectApiCode(
    403,
    static fn () => (new SystemConfigGroupLogic())->edit(1, ['code' => 'renamed_social_config']),
    'ordinary config-group edit cannot rename social_config',
);
$expectApiCode(
    403,
    static fn () => (new SystemConfigGroupLogic())->add(['code' => 'social_config']),
    'ordinary config-group add cannot forge social_config',
);
$expectApiCode(422, static fn () => $configLogic->edit(1, [
    'group_id' => 1,
    'key' => 'cross_org_social_enabled',
    'value' => '1',
    'name' => 'renamed managed switch',
]), 'ordinary config edit cannot mutate managed switch metadata');
$pdo->exec(
    "INSERT INTO sm_system_config
        (id, group_id, `key`, `value`, name, input_type, create_time, update_time)
     VALUES (3, 1, 'ordinary_social_setting', '1', 'ordinary', 'input', NOW(), NOW())",
);
$expectApiCode(422, static fn () => $configLogic->edit(3, [
    'group_id' => 1,
    'key' => 'cross_org_access_snapshot_id',
    'value' => '999',
    'name' => 'ordinary',
]), 'ordinary social config cannot be renamed into a managed key');
$expectApiCode(422, static fn () => (new CrossOrganizationSocialConfigService())->batchUpdate(1, [
    ['id' => 1, 'key' => 'cross_org_social_enabled', 'value' => '1'],
    ['id' => 1, 'key' => 'cross_org_social_enabled', 'value' => '0'],
]), 'batch update rejects duplicate managed ids');
$expectApiCode(422, static fn () => (new CrossOrganizationSocialConfigService())->batchUpdate(1, [[
    'id' => 2,
    'key' => 'renamed_snapshot_key',
    'value' => '999',
]]), 'managed snapshot key cannot be renamed or overwritten');
$expectApiCode(403, static fn () => $configLogic->batchUpdate(2, [[
    'id' => 1,
    'name' => 'spoofed moved switch',
    'key' => 'cross_org_social_enabled',
    'value' => '0',
]]), 'ordinary batch update cannot move a managed row through another group');
$expectApiCode(403, static fn () => $configLogic->batchUpdate(2, [[
    'id' => 4,
    'name' => 'ordinary',
    'key' => 'cross_org_access_snapshot_id',
    'value' => '999',
]]), 'ordinary batch update cannot rename a row into a managed key');
$managedSwitchStillHome = $pdo->query(
    "SELECT group_id, `key` FROM sm_system_config WHERE id = 1",
)->fetch(PDO::FETCH_ASSOC);
$assert(
    (int) ($managedSwitchStillHome['group_id'] ?? 0) === 1
    && ($managedSwitchStillHome['key'] ?? '') === 'cross_org_social_enabled',
    'batch spoof rejection preserves the managed switch row',
);

$crossSearch = $store->searchUsers(1, 'u1', 'bob');
$assert(
    count($crossSearch) === 1
    && (int) ($crossSearch[0]['organization'] ?? 0) === 2
    && ($crossSearch[0]['user_id'] ?? '') === 'u2',
    'cross-org exact user search executes with a bound parameter for every placeholder',
);

$sent = $store->sendFriendRequest(1, 'u1', 2, 'u2', 'hello cross', $now);
$assert(($sent['status'] ?? '') === 'pending', 'friend request pending');
$assert((int) ($sent['_realtime_event_organization'] ?? 0) === 2, 'realtime targets recipient org');
$assert(
    ($sent['_realtime_event']['cross_org_access_snapshot_id'] ?? '') === '1',
    'cross-org friend realtime event binds the transaction access snapshot',
);

$requests = $store->friendRequests(2, 'u2');
$assert(count($requests) >= 1, 'recipient sees request');
$requestId = (int) $requests[0]['id'];
$accepted = $store->handleFriendRequest(2, 'u2', $requestId, 'accept', $now);
$assert(($accepted['status'] ?? '') === 'accepted', 'accept ok');

// Accept again is idempotent
$acceptedAgain = $store->handleFriendRequest(2, 'u2', $requestId, 'accept', $now);
$assert(($acceptedAgain['status'] ?? '') === 'accepted', 'accept idempotent');

$contacts1 = $store->contacts(1, 'u1', '');
$assert(count($contacts1) === 1, 'org1 has one contact');
$assert(($contacts1[0]['user_id'] ?? '') === 'u2', 'peer is u2');
$assert(($contacts1[0]['is_cross_organization'] ?? false) === true, 'marked cross-org');
$assert(($contacts1[0]['company_name'] ?? '') === '乙公司', 'company name projected');
$assert(($contacts1[0]['display_name'] ?? '') === 'Bob · 乙公司', 'display name has company');

$contacts2 = $store->contacts(2, 'u2', '');
$assert(count($contacts2) === 1, 'org2 has one contact');
$assert(($contacts2[0]['display_name'] ?? '') === 'Alice · 甲公司', 'reverse display name');

$sameIdSent = $store->sendFriendRequest(1, 'shared', 2, 'shared', 'same id cross org', $now);
$assert(($sameIdSent['status'] ?? '') === 'pending', 'same user_id cross-org request is not mistaken for self');
$sameIdRequests = $store->friendRequests(2, 'shared');
$sameIdRequestId = (int) ($sameIdRequests[0]['id'] ?? 0);
$assert($sameIdRequestId > 0, 'same user_id recipient sees request');
$sameIdAccepted = $store->handleFriendRequest(2, 'shared', $sameIdRequestId, 'accept', $now);
$assert(($sameIdAccepted['status'] ?? '') === 'accepted', 'same user_id cross-org request accepted');
$sameIdRelations = (int) $pdo->query(
    "SELECT COUNT(*) FROM im_friend_relation WHERE user_id = 'shared' AND friend_user_id = 'shared' AND friend_organization > 0",
)->fetchColumn();
$assert($sameIdRelations === 2, 'same user_id relations remain distinct by organization');

// Dual-home HTTP history: message body stored under recipient org with peer sender_id.
$crossConversationId = \plugin\saimulti\service\web\SingleConversationIdentity::conversationId(1, 'u1', 2, 'u2');
$messageId = '2026071700000000000000000001abcd';
$clientMsgId = 'http-hist-' . bin2hex(random_bytes(4));
$pdo->prepare(
    'INSERT INTO im_cross_organization_conversation
        (conversation_id, left_organization, left_user_id, right_organization, right_user_id,
         next_message_seq, status, create_time, update_time)
     VALUES (?, 1, "u1", 2, "u2", 2, 1, ?, ?)',
)->execute([$crossConversationId, $now, $now]);
foreach ([1, 2] as $homeOrg) {
    $pdo->prepare(
        'INSERT INTO im_conversation
            (organization, conversation_id, conversation_type, title, owner_user_id, owner_organization,
             status, create_time, update_time)
         VALUES (?, ?, 1, "", ?, 1, 1, ?, ?)',
    )->execute([$homeOrg, $crossConversationId, 'u1', $now, $now]);
    foreach ([[1, 'u1'], [2, 'u2']] as [$memberOrg, $memberId]) {
        $pdo->prepare(
            'INSERT INTO im_conversation_member
                (organization, conversation_id, user_id, member_organization, member_role, status,
                 join_at, create_time, update_time)
             VALUES (?, ?, ?, ?, "member", 1, ?, ?, ?)',
        )->execute([$homeOrg, $crossConversationId, $memberId, $memberOrg, $now, $now, $now]);
        $pdo->prepare(
            'INSERT INTO im_conversation_membership_period
                (organization, conversation_id, user_id, member_organization, period_no,
                 visible_from_message_seq, status, join_at, create_time, update_time)
             VALUES (?, ?, ?, ?, 1, 1, 1, ?, ?, ?)',
        )->execute([$homeOrg, $crossConversationId, $memberId, $memberOrg, $now, $now, $now]);
    }
    $pdo->prepare(
        'UPDATE im_conversation_member
            SET unread_count = 4
          WHERE organization = ? AND conversation_id = ?
            AND member_organization = 2 AND user_id = "u2"',
    )->execute([$homeOrg, $crossConversationId]);
}
// Both homes carry the same logical message and home-specific global_seq.
foreach ([1 => 11, 2 => 21] as $homeOrg => $globalSeq) {
    $pdo->prepare(
        'INSERT INTO im_message_2026_000001
            (organization, conversation_id, conversation_type, message_id, message_seq, client_msg_id,
             sender_id, sender_organization, message_type, content, status, create_time, update_time)
         VALUES (?, ?, 1, ?, 1, ?, "u1", 1, 1, ?, 1, ?, ?)',
    )->execute([
        $homeOrg,
        $crossConversationId,
        $messageId,
        $clientMsgId,
        json_encode(['text' => 'hello dual-home'], JSON_UNESCAPED_UNICODE),
        $now,
        $now,
    ]);
    $pdo->prepare(
        'INSERT INTO im_message_index
            (organization, global_seq, message_id, conversation_id, message_seq, sender_id,
             sender_organization, client_msg_id, storage_node, shard_table, create_time)
         VALUES (?, ?, ?, ?, 1, "u1", 1, ?, "mysql-primary", "im_message_2026_000001", ?)',
    )->execute([$homeOrg, $globalSeq, $messageId, $crossConversationId, $clientMsgId, $now]);
}

// Real HTTP history path used by Flutter: messages(viewerOrg=2, viewer=u2, peer=u1)
$history = $store->messages(2, 'u2', '', 1, 'u1', 0, 0, 20);
$histMessages = $history['messages'] ?? [];
$assert(count($histMessages) === 1, 'recipient history has dual-home message');
$hist = $histMessages[0];
$assert((int) ($hist['organization'] ?? 0) === 2, 'history message home organization is explicit');
$assert(($hist['global_seq'] ?? '') === '21', 'history restores recipient-home global_seq as a decimal string');
$assert(($hist['sender_id'] ?? '') === 'u1', 'history sender_id is peer');
$assert((int) ($hist['sender_organization'] ?? 0) === 1, 'history sender organization is explicit');
$assert(is_array($hist['sender_user'] ?? null), 'history sender_user projected (not null)');
$assert(($hist['sender_user']['user_id'] ?? '') === 'u1', 'history sender_user is u1');
$assert(($hist['sender_user']['is_cross_organization'] ?? false) === true, 'history sender marked cross-org for viewer org2');
$assert(($hist['sender_user']['display_name'] ?? '') === 'Alice · 甲公司', 'history sender display_name has company for viewer');
$assert(($hist['sender_user']['company_name'] ?? '') === '甲公司', 'history sender company_name for viewer');
$recipientConversations = $store->conversations(2, 'u2');
$assert(count($recipientConversations) === 1, 'recipient has one projected conversation');
$peerUser = $recipientConversations[0]['peer_user'] ?? null;
$assert(is_array($peerUser), 'conversation exposes peer_user');
$assert((int) ($peerUser['organization'] ?? 0) === 1, 'conversation peer organization is explicit');
$assert(($peerUser['user_id'] ?? '') === 'u1', 'conversation peer user id is explicit');
$assert(($peerUser['company_name'] ?? '') === '甲公司', 'conversation peer company is projected');
$pdo->prepare(
    'UPDATE im_friend_relation
        SET status = 2
      WHERE (organization = 1 AND user_id = "u1" AND friend_organization = 2 AND friend_user_id = "u2")
         OR (organization = 2 AND user_id = "u2" AND friend_organization = 1 AND friend_user_id = "u1")',
)->execute();
$conversationWithoutRelation = $store->conversations(2, 'u2')[0] ?? null;
$assert(
    is_array($conversationWithoutRelation)
    && (int) ($conversationWithoutRelation['peer_user']['organization'] ?? 0) === 1
    && ($conversationWithoutRelation['peer_user']['user_id'] ?? '') === 'u1'
    && ($conversationWithoutRelation['peer_user']['relation_status'] ?? '') === 'none',
    'single peer identity comes from canonical membership rather than friend-relation guessing',
);
$pdo->prepare(
    'UPDATE im_friend_relation
        SET status = 1
      WHERE (organization = 1 AND user_id = "u1" AND friend_organization = 2 AND friend_user_id = "u2")
         OR (organization = 2 AND user_id = "u2" AND friend_organization = 1 AND friend_user_id = "u1")',
)->execute();
$pdo->prepare(
    'INSERT INTO im_conversation_member
        (organization, conversation_id, user_id, member_organization, member_role, status,
         join_at, create_time, update_time)
     VALUES (2, ?, "shared", 1, "member", 1, ?, ?, ?)',
)->execute([$crossConversationId, $now, $now, $now]);
try {
    $store->conversations(2, 'u2');
    $assert(false, 'malformed single conversation with three identities must fail closed');
} catch (RuntimeException) {
    $assert(true, 'malformed single conversation with three identities fails closed');
}
$pdo->prepare(
    'DELETE FROM im_conversation_member
      WHERE organization = 2 AND conversation_id = ?
        AND member_organization = 1 AND user_id = "shared"',
)->execute([$crossConversationId]);
$pdo->prepare(
    'UPDATE im_cross_organization_conversation SET status = 2 WHERE conversation_id = ?',
)->execute([$crossConversationId]);
$expectRuntime(
    static fn () => $store->conversations(2, 'u2'),
    'single conversation without an active canonical row fails closed in list',
);
$expectRuntime(
    static fn () => $store->messages(2, 'u2', $crossConversationId, 0, '', 0, 0, 20),
    'single conversation without an active canonical row fails closed in history',
);
$expectRuntime(
    static fn () => $store->searchMessages(2, 'u2', $crossConversationId, '', 0, 20),
    'single conversation without an active canonical row fails closed in search',
);
$expectRuntime(
    static fn () => $store->markRead(2, 'u2', $crossConversationId, false, $now),
    'single conversation without an active canonical row fails closed in markRead',
);
$pdo->prepare(
    'UPDATE im_cross_organization_conversation SET status = 1 WHERE conversation_id = ?',
)->execute([$crossConversationId]);
$pdo->prepare(
    'UPDATE im_conversation SET status = 2 WHERE organization = 1 AND conversation_id = ?',
)->execute([$crossConversationId]);
$expectRuntime(
    static fn () => $store->messages(2, 'u2', $crossConversationId, 0, '', 0, 0, 20),
    'cross-org history rejects a missing peer-home conversation projection',
);
$expectRuntime(
    static fn () => $store->searchMessages(2, 'u2', $crossConversationId, '', 0, 20),
    'cross-org search rejects a missing peer-home conversation projection',
);
$expectRuntime(
    static fn () => $store->markRead(2, 'u2', $crossConversationId, false, $now),
    'cross-org markRead rejects a missing peer-home conversation projection',
);
$pdo->prepare(
    'UPDATE im_conversation SET status = 1 WHERE organization = 1 AND conversation_id = ?',
)->execute([$crossConversationId]);

$searchedMessages = $store->searchMessages(2, 'u2', $crossConversationId, 'dual-home', 0, 20);
$assert(
    count($searchedMessages) === 1 && ($searchedMessages[0]['global_seq'] ?? '') === '21',
    'message search restores recipient-home global_seq',
);

$read = $store->markRead(2, 'u2', $crossConversationId, false, $now);
$assert(($read['updated'] ?? 0) === 1, 'HTTP cross-org markRead updates current home');
$readMembers = $pdo->query(
    "SELECT organization, last_read_message_id, last_read_seq, unread_count
       FROM im_conversation_member
      WHERE conversation_id = " . $pdo->quote($crossConversationId) . "
        AND member_organization = 2 AND user_id = 'u2'
   ORDER BY organization",
)->fetchAll(PDO::FETCH_ASSOC);
$assert(
    count($readMembers) === 2
    && array_column($readMembers, 'last_read_message_id') === [$messageId, $messageId]
    && array_map('intval', array_column($readMembers, 'last_read_seq')) === [1, 1]
    && array_map('intval', array_column($readMembers, 'unread_count')) === [0, 0],
    'HTTP cross-org markRead mirrors the reader cursor to both homes',
);
$receiptHomes = $pdo->query(
    "SELECT organization
       FROM im_message_receipt
      WHERE conversation_id = " . $pdo->quote($crossConversationId) . "
        AND message_id = " . $pdo->quote($messageId) . "
        AND user_organization = 2 AND user_id = 'u2' AND status = 3
   ORDER BY organization",
)->fetchAll(PDO::FETCH_COLUMN);
$assert(array_map('intval', $receiptHomes) === [1, 2], 'HTTP cross-org markRead mirrors read receipts');
$readOutboxes = $pdo->query(
    "SELECT organization, event_id, payload_json
       FROM im_message_outbox
      WHERE conversation_id = " . $pdo->quote($crossConversationId) . "
        AND event_type = 'conversation.read'
   ORDER BY organization",
)->fetchAll(PDO::FETCH_ASSOC);
$assert(count($readOutboxes) === 2, 'HTTP cross-org markRead writes one reliable event per home');
$readSnapshotId = (string) $pdo->query(
    "SELECT value FROM sm_system_config WHERE `key` = 'cross_org_access_snapshot_id'",
)->fetchColumn();
$readEpochRows = [];
foreach ($readOutboxes as $readOutbox) {
    $payload = json_decode((string) $readOutbox['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $home = (int) $readOutbox['organization'];
    $expectedEventId = hash('sha256', implode('|', [
        $home, 'conversation.read', $crossConversationId, 2, 'u2', 1, $readSnapshotId,
    ]));
    $expectedClientId = 'web-http-read-' . substr(hash(
        'sha256',
        '2|u2|' . $crossConversationId . '|1|' . $readSnapshotId,
    ), 0, 32);
    $assert(
        ($payload['event_id'] ?? '') === $readOutbox['event_id']
        && $readOutbox['event_id'] === $expectedEventId
        && ($payload['organization'] ?? 0) === $home
        && ($payload['origin_organization'] ?? 0) === 2
        && ($payload['origin_client_id'] ?? '') === $expectedClientId
        && ($payload['cross_org_access_snapshot_id'] ?? '') === $readSnapshotId
        && ($payload['read_state']['cross_org_access_snapshot_id'] ?? '') === $readSnapshotId
        && ($payload['user_organization'] ?? 0) === 2
        && ($payload['read_state']['last_read_seq'] ?? 0) === 1
        && ($payload['recipient_identities'] ?? []) === [['organization' => $home, 'user_id' => $home === 1 ? 'u1' : 'u2']],
        'conversation.read payload is home-specific and uses composite identities for home ' . $home,
    );
    $readEpochRows[$home] = [
        'event_id' => (string) $readOutbox['event_id'],
        'origin_client_id' => (string) ($payload['origin_client_id'] ?? ''),
    ];
}
$store->markRead(2, 'u2', $crossConversationId, false, $now);
$readOutboxCountAfterRetry = (int) $pdo->query(
    "SELECT COUNT(*) FROM im_message_outbox
      WHERE conversation_id = " . $pdo->quote($crossConversationId) . "
        AND event_type = 'conversation.read'",
)->fetchColumn();
$assert($readOutboxCountAfterRetry === 2, 'repeated HTTP markRead is outbox-idempotent');

// The fixture established N=1 above; use its canonical decimal N+2 without
// converting an epoch through a finite-width integer.
$jumpedReadSnapshotId = '3';
$pdo->prepare(
    "UPDATE sm_system_config SET `value` = ? WHERE `key` = 'cross_org_access_snapshot_id'",
)->execute([$jumpedReadSnapshotId]);
CrossOrganizationSocialPolicy::clearCache();
$store->markRead(2, 'u2', $crossConversationId, false, $now);
$readOutboxesAfterEpochJump = $pdo->query(
    "SELECT organization, event_id, payload_json
       FROM im_message_outbox
      WHERE conversation_id = " . $pdo->quote($crossConversationId) . "
        AND event_type = 'conversation.read'
   ORDER BY organization, id",
)->fetchAll(PDO::FETCH_ASSOC);
$jumpedReadOutboxCount = 0;
foreach ($readOutboxesAfterEpochJump as $readOutboxAfterEpochJump) {
    $payload = json_decode(
        (string) $readOutboxAfterEpochJump['payload_json'], true, 512, JSON_THROW_ON_ERROR,
    );
    if (($payload['cross_org_access_snapshot_id'] ?? '') !== $jumpedReadSnapshotId) {
        continue;
    }
    ++$jumpedReadOutboxCount;
    $home = (int) $readOutboxAfterEpochJump['organization'];
    $expectedEventId = hash('sha256', implode('|', [
        $home, 'conversation.read', $crossConversationId, 2, 'u2', 1, $jumpedReadSnapshotId,
    ]));
    $expectedClientId = 'web-http-read-' . substr(hash(
        'sha256',
        '2|u2|' . $crossConversationId . '|1|' . $jumpedReadSnapshotId,
    ), 0, 32);
    $assert(
        ($payload['read_state']['last_read_seq'] ?? 0) === 1
        && ($payload['read_state']['cross_org_access_snapshot_id'] ?? '') === $jumpedReadSnapshotId
        && $readOutboxAfterEpochJump['event_id'] === $expectedEventId
        && ($payload['event_id'] ?? '') === $expectedEventId
        && ($payload['origin_client_id'] ?? '') === $expectedClientId
        && $expectedEventId !== ($readEpochRows[$home]['event_id'] ?? '')
        && $expectedClientId !== ($readEpochRows[$home]['origin_client_id'] ?? ''),
        'conversation.read epoch jump changes event and origin client IDs for home ' . $home,
    );
}
$assert(
    count($readOutboxesAfterEpochJump) === 4 && $jumpedReadOutboxCount === 2,
    'direct read epoch N to N+2 writes one new event per home at the same read sequence',
);
$store->markRead(2, 'u2', $crossConversationId, false, $now);
$jumpedReadOutboxCountAfterRetry = (int) $pdo->query(
    "SELECT COUNT(*) FROM im_message_outbox
      WHERE conversation_id = " . $pdo->quote($crossConversationId) . "
        AND event_type = 'conversation.read'",
)->fetchColumn();
$assert(
    $jumpedReadOutboxCountAfterRetry === 4,
    'repeated HTTP markRead remains outbox-idempotent after an N+2 epoch jump',
);
$pdo->prepare(
    "UPDATE sm_system_config SET `value` = ? WHERE `key` = 'cross_org_access_snapshot_id'",
)->execute([$readSnapshotId]);
CrossOrganizationSocialPolicy::clearCache();

$organizationLogic = new SystemOrganizationLogic(new OrganizationImAccessService(new class() {
    public function setex(string $key, int $ttl, string $value): bool
    {
        return true;
    }

    public function del(string $key): int
    {
        return 1;
    }

    public function rPush(string $key, string $value): int
    {
        return 1;
    }
}));
$pdo->prepare(
    'INSERT INTO im_user
        (organization, user_id, account, password_hash, nickname, status, is_system, create_time, update_time)
     VALUES (3, "u4", "dave", "x", "Dave", 1, 2, ?, ?)',
)->execute([$now, $now]);
$pdo->prepare(
    'INSERT INTO im_user_privacy_setting (organization, user_id, create_time, update_time)
     VALUES (3, "u4", ?, ?)',
)->execute([$now, $now]);
$deleteRequest = $store->sendFriendRequest(1, 'u1', 3, 'u4', 'delete edge', $now);
$deleteRequestId = (int) ($store->friendRequests(3, 'u4')[0]['id'] ?? 0);
$assert(
    ($deleteRequest['status'] ?? '') === 'pending'
    && ($store->handleFriendRequest(3, 'u4', $deleteRequestId, 'accept', $now)['status'] ?? '') === 'accepted',
    'organization-delete fixture creates an active cross-org edge',
);
$deletedConversationId = \plugin\saimulti\service\web\SingleConversationIdentity::conversationId(
    1,
    'u1',
    3,
    'u4',
);
$assert(
    $organizationLogic->destroy([3])
    && CrossOrganizationSocialPolicy::accessSnapshotId() === '2',
    'active organization delete advances the access snapshot through SystemOrganizationLogic',
);
$deletedPeerEvent = $pdo->query(
    "SELECT payload_json
       FROM im_message_outbox
      WHERE organization = 1
        AND conversation_id = " . $pdo->quote($deletedConversationId) . "
        AND event_type = 'conversation.access_changed'
   ORDER BY id DESC
      LIMIT 1",
)->fetchColumn();
$deletedPeerPayload = json_decode((string) $deletedPeerEvent, true, 512, JSON_THROW_ON_ERROR);
$assert(
    ($deletedPeerPayload['cross_org_access_snapshot_id'] ?? '') === '2'
    && ($deletedPeerPayload['allowed'] ?? true) === false
    && ($deletedPeerPayload['target_organization'] ?? 0) === 1
    && ($deletedPeerPayload['target_user_id'] ?? '') === 'u1'
    && ($deletedPeerPayload['peer_organization'] ?? 0) === 3
    && ($deletedPeerPayload['peer_user_id'] ?? '') === 'u4',
    'organization delete sends an exact revocation to the still-active peer',
);
$assert(
    $pdo->query('SELECT delete_time FROM sm_system_organization WHERE id = 3')->fetchColumn() !== null
    && count($store->contacts(1, 'u1', '')) === 1,
    'deleted organization is immediately excluded from cross-org access',
);

$assert(
    $organizationLogic->edit(2, ['status' => 2]) === 1
    && CrossOrganizationSocialPolicy::accessSnapshotId() === '3',
    'organization disable advances the access snapshot once through SystemOrganizationLogic',
);
$disabledPeerEvent = $pdo->query(
    "SELECT payload_json
       FROM im_message_outbox
      WHERE organization = 1
        AND conversation_id = " . $pdo->quote($crossConversationId) . "
        AND event_type = 'conversation.access_changed'
   ORDER BY id DESC
      LIMIT 1",
)->fetchColumn();
$disabledPeerPayload = json_decode((string) $disabledPeerEvent, true, 512, JSON_THROW_ON_ERROR);
$assert(
    ($disabledPeerPayload['cross_org_access_snapshot_id'] ?? '') === '3'
    && ($disabledPeerPayload['allowed'] ?? true) === false
    && ($disabledPeerPayload['target_organization'] ?? 0) === 1
    && ($disabledPeerPayload['target_user_id'] ?? '') === 'u1'
    && ($disabledPeerPayload['peer_organization'] ?? 0) === 2
    && ($disabledPeerPayload['peer_user_id'] ?? '') === 'u2'
    && ($disabledPeerPayload['recipient_identities'] ?? []) === [[
        'organization' => 1,
        'user_id' => 'u1',
    ]],
    'organization disable sends an exact revocation to the still-active peer',
);
$assert($store->contacts(1, 'u1', '') === [], 'inactive peer organization is filtered from contacts');
$assert($store->friendRequests(1, 'u1') === [], 'inactive peer organization is filtered from requests');
$assert($store->conversations(1, 'u1') === [], 'inactive peer organization is filtered from conversations');
$assert($store->searchUsers(1, 'u1', 'bob') === [], 'inactive peer organization is filtered from search');
$expectApiCode(
    403,
    static fn () => $store->messages(1, 'u1', $crossConversationId, 0, '', 0, 0, 20),
    'inactive peer organization rejects direct history',
);
$expectApiCode(
    403,
    static fn () => $store->searchMessages(1, 'u1', $crossConversationId, '', 0, 20),
    'inactive peer organization rejects message search',
);
$expectApiCode(
    403,
    static fn () => $store->markRead(1, 'u1', $crossConversationId, false, $now),
    'inactive peer organization rejects markRead',
);
$assert(
    ($store->markRead(1, 'u1', '', true, $now)['updated'] ?? -1) === 0,
    'inactive peer organization is skipped by mark-all',
);
$expectApiCode(
    403,
    static fn () => $store->sendFriendRequest(1, 'u1', 2, 'u2', 'retry', $now),
    'inactive peer organization rejects friend writes',
);
$expectApiCode(
    403,
    static fn () => $store->updateFriendRemark(1, 'u1', 2, 'u2', 'blocked', $now),
    'inactive peer organization rejects friend remark writes',
);
$expectApiCode(
    403,
    static fn () => $store->handleFriendRequest(2, 'u2', $requestId, 'accept', $now),
    'inactive organization rejects idempotent friend-request handling',
);

$assert(
    $organizationLogic->edit(2, ['status' => 1]) === 1
    && CrossOrganizationSocialPolicy::accessSnapshotId() === '4',
    'organization restore advances the access snapshot once through SystemOrganizationLogic',
);
$enabledPeerEvent = $pdo->query(
    "SELECT payload_json
       FROM im_message_outbox
      WHERE organization = 1
        AND conversation_id = " . $pdo->quote($crossConversationId) . "
        AND event_type = 'conversation.access_changed'
   ORDER BY id DESC
      LIMIT 1",
)->fetchColumn();
$enabledPeerPayload = json_decode((string) $enabledPeerEvent, true, 512, JSON_THROW_ON_ERROR);
$assert(
    ($enabledPeerPayload['cross_org_access_snapshot_id'] ?? '') === '4'
    && ($enabledPeerPayload['allowed'] ?? false) === true
    && ($enabledPeerPayload['target_organization'] ?? 0) === 1
    && ($enabledPeerPayload['peer_organization'] ?? 0) === 2,
    'organization restore sends an exact allow event when both peers and the global switch are active',
);
$accessEventsBeforeNoop = (int) $pdo->query(
    "SELECT COUNT(*) FROM im_message_outbox WHERE event_type = 'conversation.access_changed'",
)->fetchColumn();
$organizationLogic->edit(2, ['status' => 1]);
$accessEventsAfterNoop = (int) $pdo->query(
    "SELECT COUNT(*) FROM im_message_outbox WHERE event_type = 'conversation.access_changed'",
)->fetchColumn();
$assert(
    CrossOrganizationSocialPolicy::accessSnapshotId() === '4'
    && $accessEventsAfterNoop === $accessEventsBeforeNoop,
    'organization status retry with no availability change is snapshot and outbox idempotent',
);
$assert(count($store->contacts(1, 'u1', '')) === 1, 'restored organization restores cross-org contact access');
$assert(
    count($store->messages(1, 'u1', $crossConversationId, 0, '', 0, 0, 20)['messages'] ?? []) === 1,
    'restored organization restores cross-org history access',
);
$pdo->exec("DELETE FROM im_message_outbox WHERE event_type = 'conversation.access_changed'");

// Off transition: snapshot advances and both homes receive access revocation events.
(new CrossOrganizationSocialConfigService())->batchUpdate(1, [[
    'id' => 1,
    'name' => '允许跨租户好友与单聊',
    'key' => 'cross_org_social_enabled',
    'value' => '0',
]]);
CrossOrganizationSocialPolicy::clearCache();
$assert(CrossOrganizationSocialPolicy::isEnabled() === false, 'policy off again');
$assert(CrossOrganizationSocialPolicy::accessSnapshotId() === '5', 'off transition advances access snapshot');
$accessOutboxes = $pdo->query(
    "SELECT organization, event_id, payload_json
       FROM im_message_outbox
      WHERE conversation_id = " . $pdo->quote($crossConversationId) . "
        AND event_type = 'conversation.access_changed'
   ORDER BY organization",
)->fetchAll(PDO::FETCH_ASSOC);
$assert(count($accessOutboxes) === 2, 'switch transition writes access_changed to both homes');
foreach ($accessOutboxes as $accessOutbox) {
    $payload = json_decode((string) $accessOutbox['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $home = (int) $accessOutbox['organization'];
    $assert(
        ($payload['event_id'] ?? '') === $accessOutbox['event_id']
        && ($payload['cross_org_access_snapshot_id'] ?? '') === '5'
        && ($payload['allowed'] ?? true) === false
        && ($payload['target_organization'] ?? 0) === $home
        && ($payload['target_user_id'] ?? '') === ($home === 1 ? 'u1' : 'u2')
        && ($payload['peer_organization'] ?? 0) === ($home === 1 ? 2 : 1)
        && ($payload['peer_user_id'] ?? '') === ($home === 1 ? 'u2' : 'u1')
        && ($payload['recipient_identities'] ?? []) === [['organization' => $home, 'user_id' => $home === 1 ? 'u1' : 'u2']],
        'access_changed payload revokes the exact home identity for home ' . $home,
    );
}
$sameIdConversationId = \plugin\saimulti\service\web\SingleConversationIdentity::conversationId(
    1,
    'shared',
    2,
    'shared',
);
$sameIdAccessOutboxes = $pdo->query(
    "SELECT organization, payload_json
       FROM im_message_outbox
      WHERE conversation_id = " . $pdo->quote($sameIdConversationId) . "
        AND event_type = 'conversation.access_changed'
   ORDER BY organization",
)->fetchAll(PDO::FETCH_ASSOC);
$assert(
    count($sameIdAccessOutboxes) === 2
    && !in_array(
        $sameIdConversationId,
        $pdo->query('SELECT conversation_id FROM im_cross_organization_conversation')
            ->fetchAll(PDO::FETCH_COLUMN),
        true,
    ),
    'access transition covers cross-org friends that have no conversation row',
);
$beforeSameValueSnapshot = (string) $pdo->query(
    "SELECT value FROM sm_system_config WHERE `key` = 'cross_org_access_snapshot_id'",
)->fetchColumn();
$beforeSameValueAccessEvents = (int) $pdo->query(
    "SELECT COUNT(*) FROM im_message_outbox WHERE event_type = 'conversation.access_changed'",
)->fetchColumn();
(new CrossOrganizationSocialConfigService())->batchUpdate(1, [[
    'id' => 1,
    'name' => '允许跨租户好友与单聊',
    'key' => 'cross_org_social_enabled',
    'value' => '0',
]]);
$sameValueSnapshot = $pdo->query(
    "SELECT value FROM sm_system_config WHERE `key` = 'cross_org_access_snapshot_id'",
)->fetchColumn();
$sameValueAccessEvents = (int) $pdo->query(
    "SELECT COUNT(*) FROM im_message_outbox WHERE event_type = 'conversation.access_changed'",
)->fetchColumn();
$assert(
    (string) $sameValueSnapshot === $beforeSameValueSnapshot
    && $sameValueAccessEvents === $beforeSameValueAccessEvents,
    'saving an unchanged switch value does not advance snapshot or duplicate access events',
);

$eventsBeforeOffDisable = (int) $pdo->query(
    "SELECT COUNT(*) FROM im_message_outbox WHERE event_type = 'conversation.access_changed'",
)->fetchColumn();
$organizationLogic->edit(2, ['status' => 2]);
$assert(
    CrossOrganizationSocialPolicy::accessSnapshotId() === '5'
    && (int) $pdo->query(
        "SELECT COUNT(*) FROM im_message_outbox WHERE event_type = 'conversation.access_changed'",
    )->fetchColumn() === $eventsBeforeOffDisable,
    'organization disable while the global switch is off does not version unchanged access',
);
(new CrossOrganizationSocialConfigService())->batchUpdate(1, [[
    'id' => 1,
    'name' => '允许跨租户好友与单聊',
    'key' => 'cross_org_social_enabled',
    'value' => '1',
]]);
$disabledPeerToggleEvent = $pdo->query(
    "SELECT payload_json
       FROM im_message_outbox
      WHERE organization = 1
        AND conversation_id = " . $pdo->quote($crossConversationId) . "
        AND event_type = 'conversation.access_changed'
   ORDER BY id DESC
      LIMIT 1",
)->fetchColumn();
$disabledPeerTogglePayload = json_decode(
    (string) $disabledPeerToggleEvent,
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$assert(
    CrossOrganizationSocialPolicy::accessSnapshotId() === '6'
    && ($disabledPeerTogglePayload['cross_org_access_snapshot_id'] ?? '') === '6'
    && ($disabledPeerTogglePayload['allowed'] ?? true) === false
    && ($disabledPeerTogglePayload['target_organization'] ?? 0) === 1
    && ($disabledPeerTogglePayload['peer_organization'] ?? 0) === 2,
    'toggle-on keeps an edge denied when its peer organization is inactive',
);
(new CrossOrganizationSocialConfigService())->batchUpdate(1, [[
    'id' => 1,
    'name' => '允许跨租户好友与单聊',
    'key' => 'cross_org_social_enabled',
    'value' => '0',
]]);
$organizationLogic->edit(2, ['status' => 1]);
$assert(
    CrossOrganizationSocialPolicy::accessSnapshotId() === '7'
    && CrossOrganizationSocialPolicy::isEnabled() === false,
    'test fixture restores the peer while retaining a versioned global deny',
);
$pdo->exec("UPDATE im_user SET status = 2 WHERE organization = 2 AND user_id = 'u2'");
(new CrossOrganizationSocialConfigService())->batchUpdate(1, [[
    'id' => 1,
    'name' => '允许跨租户好友与单聊',
    'key' => 'cross_org_social_enabled',
    'value' => '1',
]]);
$inactiveUserTogglePayload = json_decode(
    (string) $pdo->query(
        "SELECT payload_json
           FROM im_message_outbox
          WHERE organization = 1
            AND conversation_id = " . $pdo->quote($crossConversationId) . "
            AND event_type = 'conversation.access_changed'
       ORDER BY id DESC
          LIMIT 1",
    )->fetchColumn(),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$activeUsersTogglePayload = json_decode(
    (string) $pdo->query(
        "SELECT payload_json
           FROM im_message_outbox
          WHERE organization = 1
            AND conversation_id = " . $pdo->quote($sameIdConversationId) . "
            AND event_type = 'conversation.access_changed'
       ORDER BY id DESC
          LIMIT 1",
    )->fetchColumn(),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$assert(
    CrossOrganizationSocialPolicy::accessSnapshotId() === '8'
    && ($inactiveUserTogglePayload['cross_org_access_snapshot_id'] ?? '') === '8'
    && ($inactiveUserTogglePayload['allowed'] ?? true) === false,
    'toggle-on keeps an edge denied when one user is inactive despite active organizations',
);
$assert(
    ($activeUsersTogglePayload['cross_org_access_snapshot_id'] ?? '') === '8'
    && ($activeUsersTogglePayload['allowed'] ?? false) === true,
    'toggle-on allows only an edge whose organizations and users are all active',
);
(new CrossOrganizationSocialConfigService())->batchUpdate(1, [[
    'id' => 1,
    'name' => '允许跨租户好友与单聊',
    'key' => 'cross_org_social_enabled',
    'value' => '0',
]]);
$pdo->exec("UPDATE im_user SET status = 1 WHERE organization = 2 AND user_id = 'u2'");
$assert(
    CrossOrganizationSocialPolicy::accessSnapshotId() === '9'
    && CrossOrganizationSocialPolicy::isEnabled() === false,
    'inactive-user toggle fixture returns to a versioned global deny',
);

$assert($store->contacts(2, 'u2', '') === [], 'hot-off filters historical cross-org contacts');
$assert($store->friendRequests(2, 'u2') === [], 'hot-off filters historical cross-org requests');
$assert($store->conversations(2, 'u2') === [], 'hot-off filters historical cross-org conversations');
$assert($store->searchUsers(1, 'u1', 'bob') === [], 'hot-off filters cross-org user search');
$expectApiCode(
    403,
    static fn () => $store->messages(2, 'u2', $crossConversationId, 0, '', 0, 0, 20),
    'hot-off rejects direct cross-org history',
);
$expectApiCode(
    403,
    static fn () => $store->searchMessages(2, 'u2', $crossConversationId, '', 0, 20),
    'hot-off rejects cross-org message search',
);
$expectApiCode(
    403,
    static fn () => $store->markRead(2, 'u2', $crossConversationId, false, $now),
    'hot-off rejects cross-org markRead',
);
$markAllOff = $store->markRead(2, 'u2', '', true, $now);
$assert(($markAllOff['updated'] ?? -1) === 0, 'hot-off mark-all skips revoked cross-org conversations');

// Same-org still works when off
$pdo->prepare('INSERT INTO im_user (organization, user_id, account, password_hash, nickname, status, is_system, create_time, update_time) VALUES (1,?,?,?,?,1,2,?,?)')
    ->execute(['u3', 'carol', 'x', 'Carol', $now, $now]);
$pdo->prepare('INSERT INTO im_user_privacy_setting (organization, user_id, create_time, update_time) VALUES (1,?,?,?)')
    ->execute(['u3', $now, $now]);
$same = $store->sendFriendRequest(1, 'u1', 1, 'u3', 'same org', $now);
$assert(($same['status'] ?? '') === 'pending', 'same-org friend still works when switch off');
$assert(
    !array_key_exists('cross_org_access_snapshot_id', $same['_realtime_event'] ?? []),
    'same-org friend realtime event does not claim a cross-org epoch',
);

$pdo->exec(
    "UPDATE sm_system_config
        SET `value` = CASE `key`
            WHEN 'cross_org_social_enabled' THEN '0'
            WHEN 'cross_org_access_snapshot_id' THEN '99999999999999999999'
            ELSE `value`
        END
      WHERE group_id = 1",
);
try {
    (new CrossOrganizationSocialConfigService())->batchUpdate(1, [[
        'id' => 1,
        'name' => '允许跨租户好友与单聊',
        'key' => 'cross_org_social_enabled',
        'value' => '1',
    ]]);
    $assert(false, 'snapshot overflow must throw');
} catch (OverflowException) {
    $configAfterOverflow = $pdo->query(
        "SELECT `key`, `value`
           FROM sm_system_config
          WHERE group_id = 1
            AND `key` IN ('cross_org_social_enabled', 'cross_org_access_snapshot_id')",
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert(
        ($configAfterOverflow['cross_org_social_enabled'] ?? null) === '0'
        && ($configAfterOverflow['cross_org_access_snapshot_id'] ?? null) === '99999999999999999999',
        'snapshot overflow rolls back both switch and snapshot writes',
    );
}

echo "\n{$passed} passed, {$failed} failed\n";
$adminPdo->exec('DROP DATABASE IF EXISTS ' . $quoted);
exit($failed > 0 ? 1 : 0);
