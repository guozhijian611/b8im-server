<?php

declare(strict_types=1);

/**
 * Integration: switch-gated cross-org friend request/accept + contact company label.
 * Uses a temporary *_web_test database and the real ThinkOrmWebImControlStore.
 *
 * Run: WEB_IM_TEST_DB_NAME=nb8im_cross_org_web_test php tests/CrossOrgSocialFriendIntegrationTest.php
 */

use plugin\saimulti\exception\ApiException;
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
  status tinyint unsigned NOT NULL DEFAULT 1,
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
  UNIQUE KEY uni_rel (organization, user_id, friend_user_id)
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
SQL);

$now = date('Y-m-d H:i:s');
$pdo->exec("INSERT INTO sm_system_organization (id, organization_name, title, status) VALUES (1, '甲公司', '甲公司', 1), (2, '乙公司', '乙公司', 1)");
$pdo->exec("INSERT INTO sm_system_config_group (id, code) VALUES (1, 'social_config')");
$pdo->exec("INSERT INTO sm_system_config (group_id, `key`, `value`) VALUES (1, 'cross_org_social_enabled', '0')");
foreach ([[1, 'u1', 'alice', 'Alice'], [2, 'u2', 'bob', 'Bob']] as [$org, $uid, $acc, $nick]) {
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

// Policy default off
CrossOrganizationSocialPolicy::clearCache();
$assert(CrossOrganizationSocialPolicy::isEnabled() === false, 'policy off by default');

$store = new ThinkOrmWebImControlStore();

// Off: cross-org friend rejected
try {
    $store->sendFriendRequest(1, 'u1', 'u2', 'hi', $now);
    $assert(false, 'off path should throw');
} catch (ApiException $e) {
    $assert($e->getCode() === 403 || str_contains($e->getMessage(), '跨租户'), 'off rejects cross-org friend: ' . $e->getMessage());
} catch (Throwable $e) {
    $assert(str_contains($e->getMessage(), '跨租户') || str_contains($e->getMessage(), '不存在'), 'off rejects: ' . $e->getMessage());
}

// On: friend request + accept
$pdo->exec("UPDATE sm_system_config SET value = '1' WHERE `key` = 'cross_org_social_enabled'");
CrossOrganizationSocialPolicy::clearCache();
$assert(CrossOrganizationSocialPolicy::isEnabled() === true, 'policy on');

$sent = $store->sendFriendRequest(1, 'u1', 'u2', 'hello cross', $now);
$assert(($sent['status'] ?? '') === 'pending', 'friend request pending');
$assert((int) ($sent['_realtime_event_organization'] ?? 0) === 2, 'realtime targets recipient org');

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

// Off again: new pair blocked (use different users would be needed; just verify policy)
$pdo->exec("UPDATE sm_system_config SET value = '0' WHERE `key` = 'cross_org_social_enabled'");
CrossOrganizationSocialPolicy::clearCache();
$assert(CrossOrganizationSocialPolicy::isEnabled() === false, 'policy off again');

// Same-org still works when off
$pdo->prepare('INSERT INTO im_user (organization, user_id, account, password_hash, nickname, status, is_system, create_time, update_time) VALUES (1,?,?,?,?,1,2,?,?)')
    ->execute(['u3', 'carol', 'x', 'Carol', $now, $now]);
$pdo->prepare('INSERT INTO im_user_privacy_setting (organization, user_id, create_time, update_time) VALUES (1,?,?,?)')
    ->execute(['u3', $now, $now]);
$same = $store->sendFriendRequest(1, 'u1', 'u3', 'same org', $now);
$assert(($same['status'] ?? '') === 'pending', 'same-org friend still works when switch off');

echo "\n{$passed} passed, {$failed} failed\n";
$adminPdo->exec('DROP DATABASE IF EXISTS ' . $quoted);
exit($failed > 0 ? 1 : 0);
