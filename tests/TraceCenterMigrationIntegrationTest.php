<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

$database = getenv('ROUTING_TEST_DB_NAME');
if (!is_string($database) || !str_ends_with($database, '_routing_test')) {
    throw new RuntimeException('Trace 菜单迁移测试拒绝连接非隔离测试库。');
}
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('DB_NAME=' . $database);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

$config = config('think-orm');
$connection = (string) ($config['default'] ?? 'mysql');
$databaseConfig = $config['connections'][$connection];
$pdo = new PDO(sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $databaseConfig['hostname'],
    (int) $databaseConfig['hostport'],
    $database,
    $databaseConfig['charset'],
), $databaseConfig['username'], $databaseConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$page = $pdo->query(
    "SELECT m.id, m.path, m.component, m.name, p.code AS parent_code
       FROM sm_admin_menu m
       JOIN sm_admin_menu p ON p.id = m.parent_id
      WHERE m.code = 'TraceCenter' AND m.delete_time IS NULL
      LIMIT 1",
)->fetch(PDO::FETCH_ASSOC);
$assert(is_array($page), '链路中心菜单未创建');
$assert($page['name'] === '链路中心', '链路中心中文名称错误');
$assert($page['path'] === '/system/trace', '链路中心动态路由错误');
$assert($page['component'] === '/system/trace/index', '链路中心组件路径错误');
$assert($page['parent_code'] === 'system', '链路中心未归入系统管理');

$permissions = $pdo->query(sprintf(
    'SELECT slug FROM sm_admin_menu WHERE parent_id = %d AND type = 3 ORDER BY slug',
    (int) $page['id'],
))->fetchAll(PDO::FETCH_COLUMN);
$assert($permissions === [
    'saimulti:system:trace:read',
    'saimulti:system:trace:search',
    'saimulti:system:trace:services',
], '链路中心权限菜单不完整');

$superGrants = (int) $pdo->query(sprintf(
    "SELECT COUNT(*)
       FROM sm_admin_role_menu rm
       JOIN sm_admin_role r ON r.id = rm.role_id
       JOIN sm_admin_menu m ON m.id = rm.menu_id
      WHERE r.code = 'superAdmin' AND (m.id = %d OR m.parent_id = %d)",
    (int) $page['id'],
    (int) $page['id'],
))->fetchColumn();
$assert($superGrants === 4, '链路中心未完整授权系统超级管理员角色');

$ordinaryGrants = (int) $pdo->query(sprintf(
    "SELECT COUNT(*)
       FROM sm_admin_role_menu rm
       JOIN sm_admin_role r ON r.id = rm.role_id
       JOIN sm_admin_menu m ON m.id = rm.menu_id
      WHERE r.code <> 'superAdmin' AND (m.id = %d OR m.parent_id = %d)",
    (int) $page['id'],
    (int) $page['id'],
))->fetchColumn();
$assert($ordinaryGrants === 0, '链路中心被错误授权普通平台角色');

fwrite(STDOUT, "Trace center migration integration tests passed on {$database}.\n");
