<?php

declare(strict_types=1);

$databaseOverride = getenv('MODULE_ACL_TEST_DB_NAME');
if (is_string($databaseOverride) && $databaseOverride !== '') {
    if (!str_ends_with($databaseOverride, '_module_acl_test')) {
        throw new RuntimeException('MODULE_ACL_TEST_DB_NAME 只允许使用 *_module_acl_test 临时库。');
    }
    $_ENV['DB_NAME'] = $databaseOverride;
    $_SERVER['DB_NAME'] = $databaseOverride;
    putenv('DB_NAME=' . $databaseOverride);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

if (is_string($databaseOverride) && $databaseOverride !== '') {
    $_ENV['DB_NAME'] = $databaseOverride;
    $_SERVER['DB_NAME'] = $databaseOverride;
    putenv('DB_NAME=' . $databaseOverride);
}

use B8im\ModuleSdk\Manifest\Manifest;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use plugin\saimulti\app\cache\TenantUserCache;
use plugin\saimulti\app\logic\tenant\RoleLogic;
use plugin\saimulti\app\logic\tenant\MenuLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\DistributedLockInterface;
use plugin\saimulti\service\module\ManifestCatalog;
use plugin\saimulti\service\module\ModuleAccessCacheInterface;
use plugin\saimulti\service\module\ModuleAccessService;
use plugin\saimulti\service\module\ModuleAuditWriter;
use plugin\saimulti\service\module\ModuleConfigValidator;
use plugin\saimulti\service\module\ModuleDependencyGuard;
use plugin\saimulti\service\module\ModuleLifecycleHookRunner;
use plugin\saimulti\service\module\ModuleManager;
use plugin\saimulti\service\module\ModuleMenuRegistrar;
use plugin\saimulti\service\module\ModuleMigrationRunner;
use plugin\saimulti\service\module\TenantAssignableMenuService;
use plugin\saimulti\service\module\TenantRoleMenuPermissionService;
use plugin\saimulti\service\module\ThinkOrmModuleAccessStore;
use support\think\Db;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tinywan\Jwt\JwtToken;

$database = (string) env('DB_NAME', '');
if (!str_ends_with($database, '_module_acl_test')) {
    throw new RuntimeException('ModuleTenantAclIntegrationTest 只允许在 *_module_acl_test 临时库执行。');
}

$thinkOrmConfig = config('think-orm');
$connectionName = (string) ($thinkOrmConfig['default'] ?? 'mysql');
$thinkOrmConfig['connections'][$connectionName]['database'] = $database;
Db::setConfig($thinkOrmConfig);
$connectionConfig = $thinkOrmConfig['connections'][$connectionName];
$pdo = new PDO(sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $connectionConfig['hostname'],
    (int) $connectionConfig['hostport'],
    $database,
    $connectionConfig['charset'],
), (string) $connectionConfig['username'], (string) $connectionConfig['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdoDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$thinkOrmDatabaseResult = Db::query('SELECT DATABASE() AS database_name');
$thinkOrmDatabase = (string) ($thinkOrmDatabaseResult[0]['database_name'] ?? '');
if ($pdoDatabase !== $database || $thinkOrmDatabase !== $database) {
    throw new RuntimeException(sprintf(
        '测试库隔离断言失败: expected=%s, pdo=%s, thinkorm=%s',
        $database,
        $pdoDatabase,
        $thinkOrmDatabase,
    ));
}

if (getenv('MODULE_ACL_TEST_MIGRATE') === '1') {
    $configPath = dirname(__DIR__) . '/phinx.php';
    $configValues = require $configPath;
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    (new Manager(new Config($configValues, $configPath), $input, new BufferedOutput()))->migrate('default');
}

final class AclArrayModuleCache implements ModuleAccessCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $values = [];

    public function get(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, array $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}

final class AclModuleLock implements DistributedLockInterface
{
    /** @var array<string, string> */
    private array $locks = [];

    public function acquire(string $key, string $token, int $ttlSeconds): bool
    {
        if (isset($this->locks[$key])) {
            return false;
        }
        $this->locks[$key] = $token;

        return true;
    }

    public function release(string $key, string $token): void
    {
        if (($this->locks[$key] ?? null) === $token) {
            unset($this->locks[$key]);
        }
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$assertApiException = static function (
    callable $callback,
    int $code,
    string $contains,
    string $message,
) use ($assert): void {
    try {
        $callback();
        throw new RuntimeException($message);
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, $message . ' code 不正确');
        $assert(str_contains($exception->getMessage(), $contains), $message . ' 错误信息不正确');
    }
};

$access = new ModuleAccessService(new ThinkOrmModuleAccessStore(), new AclArrayModuleCache());
$manager = new ModuleManager(
    new ManifestCatalog(),
    new ModuleMigrationRunner(),
    new ModuleLifecycleHookRunner(),
    new ModuleMenuRegistrar(),
    new ModuleDependencyGuard(),
    $access,
    new ModuleAuditWriter(),
    new ModuleConfigValidator(),
    new AclModuleLock(),
);
$adminActor = ['type' => 'admin', 'id' => 1, 'ip' => '127.0.0.1'];
$tenantActor = ['type' => 'tenant', 'id' => 1, 'ip' => '127.0.0.1'];
$now = date('Y-m-d H:i:s');

Db::table('sm_system_organization')->insert([
    'id' => 2,
    'group_id' => 1,
    'domain' => 'tenant-two.module-acl.test',
    'enterprise_code' => 'module_acl_org_2',
    'deployment_id' => 'b8im-module-acl-test',
    'config_version' => 1,
    'title' => '隔离租户',
    'organization_name' => '隔离租户',
    'status' => 1,
    'create_time' => $now,
    'update_time' => $now,
]);
$organizationTwoRoleId = (int) Db::table('sm_tenant_role')->insertGetId([
    'organization' => 2,
    'name' => '隔离租户普通角色',
    'code' => 'moduleAclOrgTwo',
    'level' => 10,
    'status' => 1,
    'sort' => 1,
    'create_time' => $now,
    'update_time' => $now,
]);
$insertTenantRole = static function (
    int $organization,
    string $name,
    string $code,
    int $level,
) use ($now): int {
    return (int) Db::table('sm_tenant_role')->insertGetId([
        'organization' => $organization,
        'name' => $name,
        'code' => $code,
        'level' => $level,
        'status' => 1,
        'sort' => 1,
        'create_time' => $now,
        'update_time' => $now,
    ]);
};
$insertTenantUser = static function (
    int $organization,
    string $username,
    int $status = 1,
) use ($now): int {
    return (int) Db::table('sm_tenant_user')->insertGetId([
        'organization' => $organization,
        'username' => $username,
        'password' => password_hash('module-acl-test', PASSWORD_DEFAULT),
        'user_type' => '200',
        'nickname' => $username,
        'status' => $status,
        'create_time' => $now,
        'update_time' => $now,
    ]);
};
$attachTenantRole = static function (int $userId, int $roleId): void {
    Db::table('sm_tenant_user_role')->insert([
        'user_id' => $userId,
        'role_id' => $roleId,
    ]);
};

$organizationTwoManagerRoleId = $insertTenantRole(2, '隔离租户角色管理员', 'moduleAclOrgTwoManager', 100);
$organizationTwoActorId = $insertTenantUser(2, 'acl_org2_actor');
$attachTenantRole($organizationTwoActorId, $organizationTwoManagerRoleId);
$sameLevelActorId = $insertTenantUser(1, 'acl_same_actor');
$attachTenantRole($sameLevelActorId, 2);

// A module installation registers global tenant menu rows only. Once this
// organization enables its license, those rows join the assignable set even
// though no group-menu mapping is created.
$manager->discover('announcement', $adminActor);
$manager->install('announcement', $adminActor);
$manager->enableSystem('announcement', $adminActor);
$manager->grantLicense(1, 'announcement', null, '模块 ACL 集成测试', $adminActor);
$manager->enableTenant(1, 'announcement', $tenantActor);

$announcementPageId = (int) Db::table('sm_module_menu_mapping')
    ->where('module_key', 'announcement')
    ->where('scope', 'tenant')
    ->where('manifest_menu_id', 'announcement.tenant')
    ->value('menu_id');
$announcementSaveId = (int) Db::table('sm_module_menu_mapping')
    ->where('module_key', 'announcement')
    ->where('scope', 'tenant')
    ->where('manifest_menu_id', 'announcement.tenant.save')
    ->value('menu_id');
$assert($announcementPageId > 0 && $announcementSaveId > 0, '公告模块菜单/按钮未注册');
$assert(
    (int) Db::table('sm_tenant_group_menu')->where('menu_id', $announcementPageId)->count() === 0,
    '模块安装不应写入机构分组菜单',
);

$coreMenuId = (int) Db::table('sm_tenant_menu')
    ->alias('m')
    ->join('sm_tenant_group_menu gm', 'gm.menu_id = m.id')
    ->where('gm.group_id', 1)
    ->whereNull('m.module_key')
    ->where('m.status', 1)
    ->whereNull('m.delete_time')
    ->order('m.id', 'asc')
    ->value('m.id');
$assignableMenus = new TenantAssignableMenuService($access);
$organizationOneIds = $assignableMenus->ids(1);
$organizationTwoIds = $assignableMenus->ids(2);
$assert(in_array($coreMenuId, $organizationOneIds, true), '分组核心菜单未进入可分配集合');
$assert(in_array($announcementPageId, $organizationOneIds, true), '已启用模块页面未进入可分配集合');
$assert(in_array($announcementSaveId, $organizationOneIds, true), '已启用模块按钮未进入可分配集合');
$assert(!in_array($announcementPageId, $organizationTwoIds, true), '未授权租户获得了其他租户模块菜单');

$request = new \support\Request(
    "GET /saimulti/tenant/menu/index?tree=true HTTP/1.1\r\n"
    . "Host: 127.0.0.1\r\n"
    . "App-Id: 1\r\n\r\n",
);
\Webman\Context::set(\Webman\Http\Request::class, $request);
try {
    $permissionTree = (new MenuLogic())->tree([]);
} finally {
    \Webman\Context::reset();
}
$flattenTreeIds = static function (array $nodes) use (&$flattenTreeIds): array {
    $ids = [];
    foreach ($nodes as $node) {
        $ids[] = (int) $node['id'];
        if (isset($node['children']) && is_array($node['children'])) {
            array_push($ids, ...$flattenTreeIds($node['children']));
        }
    }

    return $ids;
};
$permissionTreeIds = $flattenTreeIds($permissionTree);
$assert(in_array($announcementPageId, $permissionTreeIds, true), '角色权限树未展示已启用模块页面');
$assert(in_array($announcementSaveId, $permissionTreeIds, true), '角色权限树未展示已启用模块按钮');

$rolePermissions = new TenantRoleMenuPermissionService($assignableMenus, static fn (int $roleId): bool => true);
$rolePermissions->save(1, 1, 2, [$coreMenuId, $announcementPageId, $announcementSaveId]);
$assignedSlugs = Db::table('sm_tenant_role_menu')
    ->alias('rm')
    ->join('sm_tenant_menu m', 'm.id = rm.menu_id')
    ->where('rm.role_id', 2)
    ->whereIn('rm.menu_id', [$announcementPageId, $announcementSaveId])
    ->order('m.slug', 'asc')
    ->column('m.slug');
$assert(
    in_array('saimulti:tenant:announcement:index', $assignedSlugs, true),
    '普通角色未获得模块页面/API 列表 slug',
);
$assert(
    in_array('saimulti:tenant:announcement:save', $assignedSlugs, true),
    '普通角色未获得模块按钮/API 写入 slug',
);
$request = new \support\Request(
    "GET /saimulti/tenant/announcement/index HTTP/1.1\r\n"
    . "Host: 127.0.0.1\r\n"
    . "App-Id: 1\r\n\r\n",
);
\Webman\Context::set(\Webman\Http\Request::class, $request);
try {
    $cachedPermissionSlugs = (new MenuLogic())->getAuthByRole([2]);
} finally {
    \Webman\Context::reset();
}
$assert(
    in_array('saimulti:tenant:announcement:index', $cachedPermissionSlugs, true),
    '模块页面承载的列表/API slug 未进入普通角色鉴权缓存',
);
$assert(
    in_array('saimulti:tenant:announcement:save', $cachedPermissionSlugs, true),
    '模块按钮承载的写入 slug 未进入普通角色鉴权缓存',
);
$assertApiException(
    static fn () => $rolePermissions->save(
        2,
        $organizationTwoActorId,
        $organizationTwoRoleId,
        [$announcementPageId],
    ),
    403,
    (string) $announcementPageId,
    '跨租户模块 menu_id 未被拒绝',
);
$assertApiException(
    static fn () => $rolePermissions->save(1, 1, $organizationTwoRoleId, [$coreMenuId]),
    404,
    '不属于当前 organization',
    '跨租户目标角色未被拒绝',
);
$assertApiException(
    static fn () => $rolePermissions->save(1, $sameLevelActorId, 2, [$coreMenuId]),
    403,
    '同级或更高职级',
    '低层级角色可越权修改同级角色菜单',
);
$assertApiException(
    static fn () => $rolePermissions->assignedIds(1, 1, 1),
    403,
    '超级管理员角色',
    '内置超级管理员角色菜单未被保护',
);

$hierarchyActorRoleId = $insertTenantRole(1, '层级测试操作人', 'aclHierarchyActor', 80);
$hierarchyActorId = $insertTenantUser(1, 'acl_hierarchy_actor');
$attachTenantRole($hierarchyActorId, $hierarchyActorRoleId);
$hierarchyLowRoleId = $insertTenantRole(1, '层级测试低角色', 'aclHierarchyLow', 20);
$hierarchyHighRoleId = $insertTenantRole(1, '层级测试高角色', 'aclHierarchyHigh', 90);
$hierarchyEditableRoleId = $insertTenantRole(1, '层级测试可编辑角色', 'aclHierarchyEditable', 15);
$hierarchyDeletableRoleId = $insertTenantRole(1, '层级测试可删除角色', 'aclHierarchyDeletable', 10);
$hierarchyRoleData = static fn (int $id, string $name, string $code, mixed $level): array => [
    'id' => $id,
    'name' => $name,
    'code' => $code,
    'level' => $level,
    'status' => 1,
];
$tenantToken = JwtToken::generateToken([
    'id' => $hierarchyActorId,
    'organization' => 1,
    'username' => 'acl_hierarchy_actor',
    'user_type' => '200',
    'plat' => 'tenant',
    'aud' => 'tenant-api',
]);
$roleRequest = new \support\Request(
    "GET /saimulti/tenant/role/index HTTP/1.1\r\n"
    . "Host: 127.0.0.1\r\n"
    . "App-Id: 1\r\n"
    . 'Authorization: Bearer ' . $tenantToken['access_token'] . "\r\n\r\n",
);
TenantUserCache::clearUserInfo($hierarchyActorId);
\Webman\Context::set(\Webman\Http\Request::class, $roleRequest);
try {
    // RoleLogic 在 actor 降级前构造，故 tenantInfo 依然持有旧的 80 级快照。
    $roleLogic = new RoleLogic();
    $assertApiException(
        static fn () => $roleLogic->edit(
            $hierarchyHighRoleId,
            $hierarchyRoleData($hierarchyHighRoleId, '不应被修改', 'aclHierarchyHigh', 10),
        ),
        403,
        '同级或更高职级',
        '低层级 actor 可通过降低新 level 修改高层级目标角色',
    );
    $assertApiException(
        static fn () => $roleLogic->destroy([$hierarchyHighRoleId]),
        403,
        '同级或更高职级',
        '低层级 actor 可删除高层级目标角色',
    );
    $assertApiException(
        static fn () => $roleLogic->edit(
            1,
            $hierarchyRoleData(1, '不应被修改', 'superAdmin', 10),
        ),
        403,
        '超级管理员角色',
        '租户 actor 可修改 superAdmin 角色',
    );
    $assertApiException(
        static fn () => $roleLogic->destroy([1]),
        403,
        '超级管理员角色',
        '租户 actor 可删除 superAdmin 角色',
    );
    $assertApiException(
        static fn () => $roleLogic->edit(
            $hierarchyLowRoleId,
            $hierarchyRoleData($hierarchyLowRoleId, '不应被修改', 'superAdmin', 10),
        ),
        403,
        '系统保留角色标识',
        '可管理的普通角色可被改为 superAdmin 保留标识',
    );
    $assert(
        Db::table('sm_tenant_role')->where('id', $hierarchyLowRoleId)->value('code') === 'aclHierarchyLow',
        '被拒绝的 superAdmin 改名仍写入了数据库',
    );
    $superAdminCount = (int) Db::table('sm_tenant_role')
        ->where('organization', 1)
        ->where('code', 'superAdmin')
        ->whereNull('delete_time')
        ->count();
    $assertApiException(
        static fn () => $roleLogic->add([
            'name' => '伪造超级管理员',
            'code' => 'superAdmin',
            'level' => 10,
            'status' => 1,
        ]),
        403,
        '系统保留角色标识',
        '普通 actor 可新增 superAdmin 保留标识角色',
    );
    $assert(
        (int) Db::table('sm_tenant_role')
            ->where('organization', 1)
            ->where('code', 'superAdmin')
            ->whereNull('delete_time')
            ->count() === $superAdminCount,
        '被拒绝的 superAdmin 新增仍写入了数据库',
    );
    $assertApiException(
        static fn () => $roleLogic->edit(
            $hierarchyLowRoleId,
            $hierarchyRoleData($hierarchyLowRoleId, '不应被修改', 'aclHierarchyLow', 80),
        ),
        403,
        '必须低于当前账户职级',
        '目标角色的新 level 可越过 actor 职级',
    );
    $missingLevelData = $hierarchyRoleData(
        $hierarchyLowRoleId,
        '不应被修改',
        'aclHierarchyLow',
        10,
    );
    unset($missingLevelData['level']);
    $assertApiException(
        static fn () => $roleLogic->edit($hierarchyLowRoleId, $missingLevelData),
        422,
        '必须为正整数',
        '角色更新可省略 level 绕过层级校验',
    );
    $assert($roleLogic->edit(
        $hierarchyEditableRoleId,
        $hierarchyRoleData(
            $hierarchyEditableRoleId,
            '层级测试已编辑角色',
            'aclHierarchyEditable',
            25,
        ),
    ), '当前 actor 无法修改低层级角色');
    $assert(
        (int) Db::table('sm_tenant_role')->where('id', $hierarchyEditableRoleId)->value('level') === 25,
        '低层级角色更新未写入数据库',
    );
    $assert(
        $roleLogic->destroy([$hierarchyDeletableRoleId]),
        '当前 actor 无法删除低层级角色',
    );
    $assert(
        Db::table('sm_tenant_role')->where('id', $hierarchyDeletableRoleId)->value('delete_time') !== null,
        '低层级角色删除未写入软删除时间',
    );

    Db::table('sm_tenant_role')->where('id', $hierarchyActorRoleId)->update([
        'level' => 10,
        'update_time' => $now,
    ]);
    $assertApiException(
        static fn () => $roleLogic->saveMenuPermission($hierarchyLowRoleId, [$coreMenuId]),
        403,
        '同级或更高职级',
        'actor 降级后仍可使用 TenantUserCache 旧层级修改菜单权限',
    );

    Db::table('sm_tenant_role')->where('id', $hierarchyActorRoleId)->update([
        'level' => 80,
        'update_time' => $now,
    ]);
    Db::table('sm_tenant_user')->where('id', $hierarchyActorId)->update([
        'status' => 2,
        'update_time' => $now,
    ]);
    $assertApiException(
        static fn () => $roleLogic->saveMenuPermission($hierarchyLowRoleId, [$coreMenuId]),
        403,
        '当前账户已停用',
        'actor 停用后仍可使用已签发 token 修改菜单权限',
    );
} finally {
    Db::table('sm_tenant_role')->where('id', $hierarchyActorRoleId)->update([
        'level' => 80,
        'status' => 1,
        'update_time' => $now,
    ]);
    Db::table('sm_tenant_user')->where('id', $hierarchyActorId)->update([
        'status' => 1,
        'update_time' => $now,
    ]);
    TenantUserCache::clearUserInfo($hierarchyActorId);
    \Webman\Context::reset();
}

// Build two system-enabled test modules whose manifests form one dependency
// edge. They use the already installed SDK hook implementation but no routes,
// menus, migrations or capabilities beyond the tenant platform required here.
$announcementManifest = (new ManifestCatalog())->get('announcement')['manifest']->toArray();
$insertModule = static function (string $moduleKey, array $dependencies) use ($announcementManifest, $now): void {
    $manifest = $announcementManifest;
    $manifest['module_key'] = $moduleKey;
    $manifest['name'] = $moduleKey;
    $manifest['description'] = '模块 ACL 依赖集成测试';
    $manifest['is_builtin'] = false;
    $manifest['license_required'] = true;
    $manifest['depends_on'] = $dependencies;
    $manifest['platforms'] = ['server', 'tenant'];
    $manifest['permissions'] = [];
    $manifest['menus'] = [];
    $manifest['routes'] = [];
    $manifest['config'] = [];
    $manifest['migrations'] = [];
    $manifest['capabilities'] = ['server' => [], 'tenant' => []];

    Db::table('sm_module')->insert([
        'module_key' => $moduleKey,
        'name' => $moduleKey,
        'description' => $manifest['description'],
        'category' => $manifest['category'],
        'module_type' => $manifest['module_type'],
        'is_builtin' => 0,
        'license_required' => 1,
        'version' => $manifest['version'],
        'available_version' => $manifest['version'],
        'min_system_version' => $manifest['min_system_version'],
        'platforms_json' => json_encode($manifest['platforms'], JSON_THROW_ON_ERROR),
        'depends_on_json' => json_encode($dependencies, JSON_THROW_ON_ERROR),
        'conflicts_with_json' => '[]',
        'capabilities_json' => json_encode($manifest['capabilities'], JSON_THROW_ON_ERROR),
        'manifest_json' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'manifest_path' => '/tmp/' . $moduleKey . '/module.json',
        'status' => 'ENABLED',
        'lock_version' => 1,
        'installed_at' => $now,
        'enabled_at' => $now,
        'create_time' => $now,
        'update_time' => $now,
    ]);
};
$insertModule('acl_base', []);
$insertModule('acl_feature', [['module_key' => 'acl_base', 'constraint' => '^0.1']]);

foreach ([1, 2] as $organization) {
    $manager->grantLicense($organization, 'acl_base', null, '依赖基础模块', $adminActor);
    $manager->grantLicense($organization, 'acl_feature', null, '依赖方模块', $adminActor);
}

$assertApiException(
    static fn () => $manager->enableTenant(1, 'acl_feature', $tenantActor),
    409,
    'acl_base',
    '依赖未在当前租户启用时依赖方仍可启用',
);
$manager->enableTenant(1, 'acl_base', $tenantActor);
$manager->enableTenant(1, 'acl_feature', $tenantActor);
$manager->enableTenant(2, 'acl_base', $tenantActor);
$manager->enableTenant(2, 'acl_feature', $tenantActor);
$assertApiException(
    static fn () => $manager->disableTenant(1, 'acl_base', $tenantActor),
    409,
    'acl_feature',
    '当前租户存在已启用依赖方时基础模块仍可禁用',
);
$assertApiException(
    static fn () => $manager->revokeLicense(1, 'acl_base', $adminActor),
    409,
    'acl_feature',
    '当前租户存在已启用依赖方时基础授权仍可撤销',
);
$manager->disableTenant(1, 'acl_feature', $tenantActor);
$revoked = $manager->revokeLicense(1, 'acl_base', $adminActor);
$assert($revoked['status'] === 'UNAUTHORIZED', '其他 organization 的依赖方错误阻断本租户撤销');
$assert(
    Db::table('sm_tenant_module_license')
        ->where('organization', 2)
        ->where('module_key', 'acl_feature')
        ->value('status') === 'ENABLED',
    '严格 organization 依赖测试的对照租户状态被意外修改',
);

echo sprintf("ModuleTenantAclIntegrationTest: %d assertions passed on %s\n", $assertions, $database);
