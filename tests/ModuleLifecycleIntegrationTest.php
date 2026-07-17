<?php

declare(strict_types=1);

$databaseOverride = getenv('MODULE_TEST_DB_NAME');
if (is_string($databaseOverride) && $databaseOverride !== '') {
    if (!str_ends_with($databaseOverride, '_module_test')) {
        throw new RuntimeException('MODULE_TEST_DB_NAME 只允许使用 *_module_test 临时库。');
    }
    $_ENV['DB_NAME'] = $databaseOverride;
    $_SERVER['DB_NAME'] = $databaseOverride;
    putenv('DB_NAME=' . $databaseOverride);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

// webman bootstrap 会载入本机 .env，测试库必须在 bootstrap 后再次显式覆盖。
if (is_string($databaseOverride) && $databaseOverride !== '') {
    $_ENV['DB_NAME'] = $databaseOverride;
    $_SERVER['DB_NAME'] = $databaseOverride;
    putenv('DB_NAME=' . $databaseOverride);
}

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use B8im\ModuleSdk\Lifecycle\LifecycleContext;
use B8im\ModuleSdk\Lifecycle\LifecycleResult;
use B8im\ModuleSdk\Lifecycle\ModuleLifecycleInterface;
use B8im\ModuleSdk\Manifest\Manifest;
use plugin\saimulti\app\logic\admin\MenuLogic as AdminMenuLogic;
use plugin\saimulti\service\module\ClientConfigProjectionService;
use plugin\saimulti\service\module\ManifestCatalog;
use plugin\saimulti\service\module\ModuleAccessCacheInterface;
use plugin\saimulti\service\module\ModuleAccessService;
use plugin\saimulti\service\module\ModuleAuditWriter;
use plugin\saimulti\service\module\ModuleAuthCacheInvalidator;
use plugin\saimulti\service\module\ModuleConfigValidator;
use plugin\saimulti\service\module\ModuleDependencyGuard;
use plugin\saimulti\service\module\DistributedLockInterface;
use plugin\saimulti\service\module\ModuleLifecycleHookRunner;
use plugin\saimulti\service\module\ModuleLicenseExpiryScanner;
use plugin\saimulti\service\module\ModuleLockExecutor;
use plugin\saimulti\service\module\ModuleManager;
use plugin\saimulti\service\module\ModuleMenuRegistrar;
use plugin\saimulti\service\module\ModuleMigrationRunner;
use plugin\saimulti\service\module\ThinkOrmModuleAccessStore;
use plugin\saimulti\service\module\TenantModuleAssignmentService;
use support\think\Db;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$database = (string) env('DB_NAME', '');
if (!str_ends_with($database, '_module_test')) {
    throw new RuntimeException('ModuleLifecycleIntegrationTest 只允许在 *_module_test 临时库执行。');
}

$thinkOrmConfig = config('think-orm');
$connectionName = (string) ($thinkOrmConfig['default'] ?? 'mysql');
if (!isset($thinkOrmConfig['connections'][$connectionName])) {
    throw new RuntimeException('ThinkORM 默认连接不存在。');
}
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
if ($pdoDatabase !== $database
    || $thinkOrmDatabase !== $database
    || !str_ends_with($pdoDatabase, '_module_test')
    || !str_ends_with($thinkOrmDatabase, '_module_test')) {
    throw new RuntimeException(sprintf(
        '测试库隔离断言失败: expected=%s, pdo=%s, thinkorm=%s',
        $database,
        $pdoDatabase,
        $thinkOrmDatabase,
    ));
}

if (getenv('MODULE_TEST_MIGRATE') === '1') {
    $configPath = dirname(__DIR__) . '/phinx.php';
    $configValues = require $configPath;
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    $output = new BufferedOutput();
    (new Manager(new Config($configValues, $configPath), $input, $output))->migrate('default');
}

final class IntegrationArrayModuleCache implements ModuleAccessCacheInterface
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

final class IntegrationModuleLock implements DistributedLockInterface
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

final class IntegrationAtomicUpgradeHook implements ModuleLifecycleInterface
{
    public function install(LifecycleContext $context): LifecycleResult
    {
        return LifecycleResult::success();
    }

    public function upgrade(LifecycleContext $context): LifecycleResult
    {
        Db::table('sm_module_lifecycle_audit')->insert([
            'module_key' => 'announcement',
            'operation' => 'atomic_hook_sentinel',
            'success' => 1,
            'operator_type' => 'test',
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        return LifecycleResult::success('upgrade hook wrote inside lifecycle transaction');
    }

    public function enable(LifecycleContext $context): LifecycleResult
    {
        return LifecycleResult::success();
    }

    public function disable(LifecycleContext $context): LifecycleResult
    {
        return LifecycleResult::success();
    }

    public function uninstall(LifecycleContext $context): LifecycleResult
    {
        return LifecycleResult::success();
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$access = new ModuleAccessService(new ThinkOrmModuleAccessStore(), new IntegrationArrayModuleCache());
$catalog = new ManifestCatalog();
$authInvalidations = [];
$authCacheInvalidator = new ModuleAuthCacheInvalidator(
    static function () use (&$authInvalidations): void {
        $authInvalidations[] = 'admin';
    },
    static function (?int $organization) use (&$authInvalidations): void {
        $authInvalidations[] = $organization === null ? 'tenant:all' : 'tenant:' . $organization;
    },
);
$manager = new ModuleManager(
    $catalog,
    new ModuleMigrationRunner(),
    new ModuleLifecycleHookRunner(),
    new ModuleMenuRegistrar(),
    new ModuleDependencyGuard(),
    $access,
    new ModuleAuditWriter(),
    new ModuleConfigValidator(),
    new IntegrationModuleLock(),
    authCacheInvalidator: $authCacheInvalidator,
);
$actor = ['type' => 'admin', 'id' => 1, 'ip' => '127.0.0.1'];

$discovered = $manager->discover('announcement', $actor)['items'][0];
$assert($discovered['system']['status'] === 'DISCOVERED', '模块未进入 DISCOVERED');
$installed = $manager->install('announcement', $actor);
$assert($installed['system']['status'] === 'INSTALLED', '模块未进入 INSTALLED');
$assert(
    (int) Db::table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', $database)
        ->where('TABLE_NAME', 'phinxlog_module_announcement')
        ->count() === 1,
    '模块未使用独立 Phinx log 表',
);
$assert(
    (int) Db::table('phinxlog_module_announcement')->where('version', 20260710010100)->count() === 1,
    '独立 Phinx log 未记录 announcement migration timestamp',
);
$originalManifest = $catalog->get('announcement')['manifest'];
$removedMapping = Db::table('sm_module_menu_mapping')
    ->where('module_key', 'announcement')
    ->where('scope', 'admin')
    ->where('manifest_menu_id', 'announcement.admin.destroy')
    ->find();
$assert((bool) $removedMapping, '安装未注册待测试的公告删除权限');
$removedMenuId = (int) $removedMapping['menu_id'];
Db::table('sm_admin_role_menu')->insert(['role_id' => 2, 'menu_id' => $removedMenuId]);

$reducedManifestData = $originalManifest->toArray();
$reducedManifestData['menus'] = array_values(array_filter(
    $reducedManifestData['menus'],
    static fn (array $menu): bool => $menu['id'] !== 'announcement.admin.destroy',
));
$reducedManifestData['permissions'] = array_values(array_filter(
    $reducedManifestData['permissions'],
    static fn (array $permission): bool => $permission['slug'] !== 'saimulti:admin:announcement:destroy',
));
$registrar = new ModuleMenuRegistrar();
Db::transaction(fn () => $registrar->register(new Manifest($reducedManifestData)));
$assert(
    (int) Db::table('sm_module_menu_mapping')->where('id', $removedMapping['id'])->count() === 0,
    '升级 desired-set 未清理 stale 菜单映射',
);
$assert((int) Db::table('sm_admin_menu')->where('id', $removedMenuId)->count() === 0, '升级未删除 stale 权限菜单');
$assert(
    (int) Db::table('sm_admin_role_menu')->where('menu_id', $removedMenuId)->count() === 0,
    '升级未删除 stale 角色菜单关系',
);
Db::transaction(fn () => $registrar->register($originalManifest));
$assert(
    (int) Db::table('sm_module_menu_mapping')
        ->where('module_key', 'announcement')
        ->where('scope', 'admin')
        ->where('manifest_menu_id', 'announcement.admin.destroy')
        ->count() === 1,
    '恢复 desired-set 后未重新注册权限菜单',
);
$authInvalidations = [];
$enabled = $manager->enableSystem('announcement', $actor);
$assert($enabled['system']['status'] === 'ENABLED', '系统模块未进入 ENABLED');
$assert(
    $authInvalidations === ['admin', 'tenant:all'],
    '系统模块启用提交后未同时清理 Admin/Tenant 权限缓存',
);
$assert($manager->availableForTenant(1)['items'] === [], '无授权模块泄露到租户模块列表');
$adminPageMapping = Db::table('sm_module_menu_mapping')
    ->where('module_key', 'announcement')
    ->where('scope', 'admin')
    ->where('manifest_menu_id', 'announcement.admin')
    ->find();
$assert((bool) $adminPageMapping, '安装未注册平台公告页面菜单');
Db::table('sm_admin_role_menu')->insert([
    'role_id' => 2,
    'menu_id' => (int) $adminPageMapping['menu_id'],
]);
$adminRoleAuth = (new AdminMenuLogic())->getAuthByRole([2]);
$assert(
    in_array('saimulti:admin:announcement:index', $adminRoleAuth, true),
    '普通平台角色勾选模块页面后未获得页面承载的列表接口权限',
);

$authInvalidations = [];
$license = $manager->grantLicense(1, 'announcement', null, '临时库集成测试', $actor);
$assert($license['status'] === 'AUTHORIZED', '平台授权未与租户启用分离');
$assert($authInvalidations === ['tenant:1'], '租户授权提交后未清理 Tenant 权限缓存');
$tenantModules = $manager->availableForTenant(1)['items'];
$assert(
    count($tenantModules) === 1
    && $tenantModules[0]['module_key'] === 'announcement'
    && $tenantModules[0]['status'] === 'AUTHORIZED',
    '租户模块列表未仅返回已授权模块',
);
$authInvalidations = [];
$license = $manager->grantLicense(1, 'announcement', null, '更新授权信息', $actor);
$assert($license['status'] === 'AUTHORIZED', '重新授权错误改变了当前状态');
$assert($authInvalidations === ['tenant:1'], '重新授权提交后未清理 Tenant 权限缓存');
$tenantActor = ['type' => 'tenant', 'id' => 1, 'ip' => '127.0.0.1'];
$authInvalidations = [];
$license = $manager->enableTenant(1, 'announcement', $tenantActor);
$assert($license['status'] === 'ENABLED', '租户模块未进入 ENABLED');
$assert($authInvalidations === ['tenant:1'], '租户模块启用提交后未清理 Tenant 权限缓存');

$assignments = new TenantModuleAssignmentService($manager);
$package = $assignments->updateGroup(1, ['announcement'], $actor);
$assert($package['items'][0]['enabled'] === true, '套餐模块能力未保存');
$organizationModules = $assignments->updateOrganization(1, [[
    'module_key' => 'announcement',
    'mode' => TenantModuleAssignmentService::MODE_INHERIT,
]], $actor);
$assert(
    $organizationModules['items'][0]['effective'] === true
    && $organizationModules['items'][0]['assignment_source'] === TenantModuleAssignmentService::SOURCE_PACKAGE,
    '机构继承套餐后未物化为套餐来源的最终授权',
);
$assignments->updateGroup(1, [], $actor);
$organizationModules = $assignments->organizationCatalog(1);
$assert(
    $organizationModules['items'][0]['effective'] === false
    && $organizationModules['items'][0]['assignment_mode'] === TenantModuleAssignmentService::MODE_INHERIT,
    '套餐移除模块后未同步关闭继承机构',
);
$organizationModules = $assignments->updateOrganization(1, [[
    'module_key' => 'announcement',
    'mode' => TenantModuleAssignmentService::MODE_ENABLED,
    'remark' => '机构单独启用',
]], $actor);
$assert(
    $organizationModules['items'][0]['effective'] === true
    && $organizationModules['items'][0]['assignment_source'] === TenantModuleAssignmentService::SOURCE_MANUAL,
    '机构单独启用未覆盖套餐默认值',
);
$assignments->updateGroup(1, ['announcement'], $actor);
$assignments->updateGroup(1, [], $actor);
$assert($access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), '套餐变更覆盖了机构单独启用');
$organizationModules = $assignments->updateOrganization(1, [[
    'module_key' => 'announcement',
    'mode' => TenantModuleAssignmentService::MODE_DISABLED,
    'remark' => '机构单独停用',
]], $actor);
$assert(
    $organizationModules['items'][0]['effective'] === false
    && $organizationModules['items'][0]['assignment_mode'] === TenantModuleAssignmentService::MODE_DISABLED,
    '机构单独停用未成为最终授权边界',
);
$assignments->updateGroup(1, ['announcement'], $actor);
$assert(!$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), '套餐启用覆盖了机构单独停用');
$organizationModules = $assignments->updateOrganization(1, [[
    'module_key' => 'announcement',
    'mode' => TenantModuleAssignmentService::MODE_INHERIT,
]], $actor);
$assert(
    $organizationModules['items'][0]['effective'] === true
    && $organizationModules['items'][0]['assignment_source'] === TenantModuleAssignmentService::SOURCE_PACKAGE,
    '恢复继承套餐后未重新启用套餐模块',
);
Db::table('sm_system_organization')->where('id', 1)->update(['group_id' => null]);
$assignments->updateOrganization(1, [[
    'module_key' => 'announcement',
    'mode' => TenantModuleAssignmentService::MODE_ENABLED,
]], $actor);
$organizationModules = $assignments->updateOrganization(1, [[
    'module_key' => 'announcement',
    'mode' => TenantModuleAssignmentService::MODE_INHERIT,
]], $actor);
$assert(
    $organizationModules['items'][0]['effective'] === false
    && $organizationModules['items'][0]['assignment_source'] === TenantModuleAssignmentService::SOURCE_PACKAGE,
    '未绑定套餐的机构无法从单独配置恢复继承状态',
);
Db::table('sm_system_organization')->where('id', 1)->update(['group_id' => 1]);
$assignments->syncOrganizationFromGroup(1, $actor);
$assert($access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), '重新绑定套餐后未恢复继承能力');

$config = $manager->updateTenantConfig(1, 'announcement', [
    'display_mode' => 'popup',
    'require_read_ack' => true,
], $tenantActor);
$assert($config['values']['display_mode'] === 'popup' && $config['version'] === 1, '租户模块配置未持久化');
$assert($access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), '完整授权链路未放行 Web capability');

$projection = (new ClientConfigProjectionService($access))->project(1, 'b8im-module-test', 'web');
$assert(count($projection['modules']) === 1, '客户端投影未包含已启用模块');
$assert($projection['version'] > 0 && ($projection['features']['announcement'] ?? false), '客户端投影不符合 Web 固定契约');

$beforeExpiryVersion = (int) Db::table('sm_system_organization')->where('id', 1)->value('config_version');
Db::table('sm_tenant_module_license')
    ->where('organization', 1)
    ->where('module_key', 'announcement')
    ->update(['expire_at' => '2000-01-01 00:00:00']);
$expiryLock = new IntegrationModuleLock();
$expiryScanner = new ModuleLicenseExpiryScanner(
    $expiryLock,
    $access,
    new ModuleAuditWriter(),
    new ModuleLifecycleHookRunner(),
    authCacheInvalidator: $authCacheInvalidator,
);
$authInvalidations = [];
$heldToken = 'held-by-lifecycle';
$assert(
    $expiryLock->acquire(ModuleLockExecutor::key('announcement'), $heldToken, 900),
    '测试未能占用共享模块生命周期锁',
);
$blockedExpiry = $expiryScanner->run();
$assert(
    $blockedExpiry['expired'] === 0 && $blockedExpiry['skipped'] === 1,
    '到期扫描未避让正在执行的模块生命周期操作',
);
$assert($authInvalidations === [], '未提交的授权到期扫描错误清理了权限缓存');
$expiryLock->release(ModuleLockExecutor::key('announcement'), $heldToken);
$expiryResult = $expiryScanner->run();
$assert($expiryResult['expired'] === 1, '到期扫描未将启用授权置为 EXPIRED');
$assert($authInvalidations === ['tenant:1'], '授权到期提交后未清理 Tenant 权限缓存');
$assert(
    (int) Db::table('sm_system_organization')->where('id', 1)->value('config_version') === $beforeExpiryVersion + 1,
    '授权到期未递增客户端配置 version',
);
$manager->grantLicense(1, 'announcement', null, '到期后重新授权', $actor);
$manager->enableTenant(1, 'announcement', $tenantActor);

$badManifestData = $originalManifest->toArray();
$badManifestData['version'] = '0.2.0';
$badManifestData['migrations'] = [];
$badManifestData['hooks']['upgrade']['handler'] = IntegrationAtomicUpgradeHook::class . '::upgrade';
$badManifestData['permissions'][] = [
    'slug' => 'saimulti:config:index',
    'name' => '故意冲突的核心权限',
    'scope' => 'system',
    'description' => '仅用于验证模块权限碰撞拒绝和 hook 事务回滚。',
];
$temporaryModuleRoot = sys_get_temp_dir() . '/b8im_module_upgrade_' . bin2hex(random_bytes(8));
if (!mkdir($temporaryModuleRoot, 0700) && !is_dir($temporaryModuleRoot)) {
    throw new RuntimeException('无法创建临时升级模块目录。');
}
$temporaryManifestPath = $temporaryModuleRoot . '/module.json';
try {
    file_put_contents(
        $temporaryManifestPath,
        json_encode($badManifestData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );
    $badManager = new ModuleManager(
        new ManifestCatalog([$temporaryModuleRoot]),
        new ModuleMigrationRunner(),
        new ModuleLifecycleHookRunner(),
        new ModuleMenuRegistrar(),
        new ModuleDependencyGuard(),
        $access,
        new ModuleAuditWriter(),
        new ModuleConfigValidator(),
        new IntegrationModuleLock(),
    );
    $beforeFailedUpgradeProjectionVersion = (int) Db::table('sm_system_organization')
        ->where('id', 1)
        ->value('config_version');
    try {
        $badManager->upgrade('announcement', $actor);
        throw new RuntimeException('权限碰撞未使升级失败。');
    } catch (\plugin\saimulti\exception\ApiException $exception) {
        $assert(str_contains($exception->getMessage(), 'saimulti:config:index'), '模块菜单权限碰撞未给出明确错误');
    }
    $assert(
        Db::table('sm_module')->where('module_key', 'announcement')->value('status') === 'FAILED',
        '升级进入 UPGRADING 后失败未转为 FAILED 失败关闭',
    );
    $assert(
        (int) Db::table('sm_module_lifecycle_audit')
            ->where('operation', 'atomic_hook_sentinel')
            ->count() === 0,
        'transactional upgrade hook 未与菜单/状态事务一起回滚',
    );
    $assert(!$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), 'FAILED 升级模块仍被授权链路放行');
    $assert(
        (int) Db::table('sm_system_organization')->where('id', 1)->value('config_version')
            === $beforeFailedUpgradeProjectionVersion + 1,
        '升级失败关闭未递增客户端投影 version',
    );
} finally {
    @unlink($temporaryManifestPath);
    @rmdir($temporaryModuleRoot);
}

// Recover explicitly from FAILED using the installed 0.1.0 package; there is
// no compatibility fallback or silent continuation of the failed upgrade.
$manager->discover('announcement', $actor);
$manager->install('announcement', $actor);
$manager->enableSystem('announcement', $actor);
$assert($access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), '显式重装后模块能力未恢复');

$authInvalidations = [];
$manager->disableTenant(1, 'announcement', $tenantActor);
$assert(!$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), '租户禁用后模块仍可访问');
$assert($authInvalidations === ['tenant:1'], '租户模块禁用提交后未清理 Tenant 权限缓存');
$authInvalidations = [];
$manager->revokeLicense(1, 'announcement', $actor);
$assert($authInvalidations === ['tenant:1'], '撤销租户授权提交后未清理 Tenant 权限缓存');
$assert($manager->availableForTenant(1)['items'] === [], '已撤销授权模块仍泄露到租户模块列表');
$authInvalidations = [];
$manager->disableSystem('announcement', $actor);
$assert(
    $authInvalidations === ['admin', 'tenant:all'],
    '系统模块禁用提交后未同时清理 Admin/Tenant 权限缓存',
);
$uninstalled = $manager->uninstall('announcement', true, $actor);
$assert($uninstalled['system']['status'] === 'UNINSTALLED', '模块未进入 UNINSTALLED');
$assert(
    (int) Db::table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', $database)
        ->where('TABLE_NAME', 'sm_announcement')
        ->count() === 1,
    '默认卸载未保留业务数据表',
);
$assert((int) Db::table('sm_module_lifecycle_audit')->where('module_key', 'announcement')->count() >= 8, '生命周期审计不完整');

echo sprintf("ModuleLifecycleIntegrationTest: %d assertions passed on %s\n", $assertions, $database);
