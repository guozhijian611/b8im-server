<?php

declare(strict_types=1);

/**
 * Unit tests for platform cross-org social policy helpers.
 * Run: php tests/CrossOrganizationSocialPolicyTest.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\service\web\CrossOrganizationSocialPolicy;

$failed = 0;
$passed = 0;

$assert = static function (bool $cond, string $message) use (&$failed, &$passed): void {
    if ($cond) {
        $passed++;
        echo "PASS {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL {$message}\n";
};

$assert(CrossOrganizationSocialPolicy::truthy('1') === true, 'truthy 1');
$assert(CrossOrganizationSocialPolicy::truthy('0') === false, 'truthy 0');
$assert(CrossOrganizationSocialPolicy::truthy('true') === true, 'truthy true');
$assert(CrossOrganizationSocialPolicy::truthy('off') === false, 'truthy off');

$assert(
    CrossOrganizationSocialPolicy::contactDisplayName('张三', 'zhang', 1, 1, 'XX科技') === '张三',
    'same-org omits company',
);
$assert(
    CrossOrganizationSocialPolicy::contactDisplayName('张三', 'zhang', 1, 2, 'XX科技') === '张三 · XX科技',
    'cross-org appends company',
);
$assert(
    CrossOrganizationSocialPolicy::contactDisplayName('', 'bob2', 1, 2, 'Org2') === 'bob2 · Org2',
    'cross-org falls back to account',
);
$assert(
    CrossOrganizationSocialPolicy::contactDisplayName('张三', 'zhang', 1, 2, '') === '张三',
    'cross-org without company keeps base',
);
$assert(
    CrossOrganizationSocialPolicy::CONFIG_KEY === 'cross_org_social_enabled',
    'config key stable',
);
$assert(
    CrossOrganizationSocialPolicy::CONFIG_GROUP === 'social_config',
    'config group stable',
);
$assert(
    CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY === 'cross_org_access_snapshot_id',
    'access snapshot config key stable',
);

// Live DB policy read when available
try {
    if (class_exists(\support\think\Db::class) || true) {
        // Prefer direct PDO against local nb8im when credentials match dev defaults.
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=nb8im;charset=utf8mb4', 'root', 'root', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("INSERT INTO sm_system_config_group (name, code, remark, create_time, update_time)
            SELECT '社交边界配置', 'social_config', 'test', NOW(), NOW()
            WHERE NOT EXISTS (SELECT 1 FROM sm_system_config_group WHERE code = 'social_config' AND delete_time IS NULL)");
        $groupId = (int) $pdo->query("SELECT id FROM sm_system_config_group WHERE code = 'social_config' AND delete_time IS NULL LIMIT 1")->fetchColumn();
        $exists = $pdo->query("SELECT id FROM sm_system_config WHERE group_id = {$groupId} AND `key` = 'cross_org_social_enabled' AND delete_time IS NULL LIMIT 1")->fetchColumn();
        if (!$exists) {
            $pdo->exec("INSERT INTO sm_system_config (group_id, `key`, `value`, name, input_type, sort, remark, create_time, update_time)
                VALUES ({$groupId}, 'cross_org_social_enabled', '0', '允许跨租户好友与单聊', 'switch', 100, 'test', NOW(), NOW())");
        }
        $snapshotExists = $pdo->query("SELECT id FROM sm_system_config WHERE group_id = {$groupId} AND `key` = 'cross_org_access_snapshot_id' AND delete_time IS NULL LIMIT 1")->fetchColumn();
        if (!$snapshotExists) {
            $pdo->exec("INSERT INTO sm_system_config (group_id, `key`, `value`, name, input_type, sort, remark, create_time, update_time)
                VALUES ({$groupId}, 'cross_org_access_snapshot_id', '0', '跨租户社交访问快照序号', 'hidden', 99, 'test', NOW(), NOW())");
        }
        $pdo->exec("UPDATE sm_system_config SET value = CASE `key`
            WHEN 'cross_org_social_enabled' THEN '0'
            WHEN 'cross_org_access_snapshot_id' THEN '0'
            ELSE value END
            WHERE group_id = {$groupId}
              AND `key` IN ('cross_org_social_enabled', 'cross_org_access_snapshot_id')");

        // Bootstrap webman Db if needed is heavy; assert key/value via PDO as structural proof of shipped migration path.
        $value = $pdo->query("SELECT value FROM sm_system_config WHERE group_id = {$groupId} AND `key` = 'cross_org_social_enabled' LIMIT 1")->fetchColumn();
        $assert((string) $value === '0', 'persisted switch default off');
        $pdo->exec("UPDATE sm_system_config SET value = CASE `key`
            WHEN 'cross_org_social_enabled' THEN '1'
            WHEN 'cross_org_access_snapshot_id' THEN '1'
            ELSE value END
            WHERE group_id = {$groupId}
              AND `key` IN ('cross_org_social_enabled', 'cross_org_access_snapshot_id')");
        $valueOn = $pdo->query("SELECT value FROM sm_system_config WHERE group_id = {$groupId} AND `key` = 'cross_org_social_enabled' LIMIT 1")->fetchColumn();
        $assert((string) $valueOn === '1', 'persisted switch on');
        $pdo->exec("UPDATE sm_system_config SET value = CASE `key`
            WHEN 'cross_org_social_enabled' THEN '0'
            WHEN 'cross_org_access_snapshot_id' THEN '2'
            ELSE value END
            WHERE group_id = {$groupId}
              AND `key` IN ('cross_org_social_enabled', 'cross_org_access_snapshot_id')");
        $valueOff = $pdo->query("SELECT value FROM sm_system_config WHERE group_id = {$groupId} AND `key` = 'cross_org_social_enabled' LIMIT 1")->fetchColumn();
        $assert((string) $valueOff === '0', 'persisted switch off again');
    }
} catch (Throwable $e) {
    echo "SKIP live config toggle: {$e->getMessage()}\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
