<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Lifecycle\LifecycleContext;
use B8im\ModuleSdk\Lifecycle\LifecycleResult;
use B8im\ModuleSdk\Lifecycle\ModuleLifecycleInterface;
use B8im\ModuleSdk\Manifest\Manifest;
use B8im\ModuleSdk\State\SystemModuleStatus;
use B8im\ModuleSdk\State\TenantModuleStatus;
use Composer\InstalledVersions;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\ModuleRequired;
use plugin\saimulti\service\module\ClientConfigProjectionService;
use plugin\saimulti\service\module\DistributedLockInterface;
use plugin\saimulti\service\module\ManifestCatalog;
use plugin\saimulti\service\module\ModuleAccessCacheInterface;
use plugin\saimulti\service\module\ModuleAccessService;
use plugin\saimulti\service\module\ModuleAccessStoreInterface;
use plugin\saimulti\service\module\ModuleAuthCacheInvalidator;
use plugin\saimulti\service\module\ModuleConfigCipher;
use plugin\saimulti\service\module\ModuleConfigProtector;
use plugin\saimulti\service\module\ModuleConfigValidator;
use plugin\saimulti\service\module\ModuleLifecycleHookRunner;
use plugin\saimulti\service\module\ModuleLicenseInputNormalizer;
use plugin\saimulti\service\module\ModuleLockExecutor;
use plugin\saimulti\service\module\ModuleManifestSnapshotPolicy;
use plugin\saimulti\service\module\ModuleFailureRecoveryPolicy;
use plugin\saimulti\service\module\ModuleMigrationRunner;
use plugin\saimulti\service\module\ModuleTransactionExecutor;
use plugin\saimulti\service\module\ThinkCacheModuleAccessCache;

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
};

final class ModuleTestStore implements ModuleAccessStoreInterface
{
    public bool $fail = false;

    public bool $missing = false;

    public int $tenantSnapshotReads = 0;

    /** @var array<string, mixed> */
    public array $snapshot;

    public function __construct()
    {
        $this->snapshot = [
            'module_key' => 'announcement',
            'module_status' => SystemModuleStatus::ENABLED->value,
            'module_version' => '0.2.0',
            'module_lock_version' => 4,
            'platforms' => ['server', 'admin', 'tenant', 'web', 'android', 'ios'],
            'capabilities' => [
                'server' => ['announcement.web.read', 'announcement.app.read'],
                'web' => ['announcement.web.page', 'announcement.web.popup'],
                'android' => ['announcement.app.page'],
                'ios' => ['announcement.app.page'],
            ],
            'organization' => 1,
            'license_status' => TenantModuleStatus::ENABLED->value,
            'expire_at' => date('Y-m-d H:i:s', time() + 3600),
            'license_version' => 7,
        ];
    }

    public function tenantSnapshot(int $organization, string $moduleKey): ?array
    {
        $this->tenantSnapshotReads++;
        if ($this->fail) {
            throw new RuntimeException('db unavailable');
        }

        if ($this->missing) {
            return null;
        }

        return $moduleKey === 'announcement' ? $this->snapshot : null;
    }

    public function systemSnapshot(string $moduleKey): ?array
    {
        return $moduleKey === 'announcement' ? $this->snapshot : null;
    }

    public function enabledTenantSnapshots(int $organization): array
    {
        return [$this->snapshot];
    }

    public function enabledSystemSnapshots(): array
    {
        return [$this->snapshot];
    }

    public function organizationsForModule(string $moduleKey): array
    {
        return [1];
    }
}

final class ModuleTestCache implements ModuleAccessCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $values = [];

    public bool $failRead = false;

    public function get(string $key): ?array
    {
        if ($this->failRead) {
            throw new RuntimeException('redis unavailable');
        }

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

final class ModuleRollbackTestHook implements ModuleLifecycleInterface
{
    public static int $writes = 0;

    public function install(LifecycleContext $context): LifecycleResult
    {
        self::$writes++;
        throw new RuntimeException('hook failed');
    }

    public function upgrade(LifecycleContext $context): LifecycleResult
    {
        return LifecycleResult::success();
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

final class ModuleTestLock implements DistributedLockInterface
{
    public int $acquires = 0;
    public int $releases = 0;
    public bool $deny = false;

    public function acquire(string $key, string $token, int $ttlSeconds): bool
    {
        $this->acquires++;
        return !$this->deny;
    }

    public function release(string $key, string $token): void
    {
        $this->releases++;
    }
}

final class ModuleRawRedisTestHandler
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public bool $failDelete = false;

    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttl;
        return true;
    }

    public function eval(string $script, array $arguments, int $keyCount): int
    {
        if ($keyCount !== 1 || count($arguments) !== 3) {
            throw new RuntimeException('unexpected Redis EVAL arguments');
        }

        [$key, $incomingJson, $ttl] = $arguments;
        $incoming = json_decode((string) $incomingJson, true, flags: JSON_THROW_ON_ERROR);
        $current = isset($this->values[$key])
            ? json_decode($this->values[$key], true, flags: JSON_THROW_ON_ERROR)
            : null;
        if (is_array($current)) {
            $currentModuleVersion = (int) ($current['module_lock_version'] ?? -1);
            $incomingModuleVersion = (int) ($incoming['module_lock_version'] ?? -1);
            $currentLicenseVersion = (int) ($current['version'] ?? -1);
            $incomingLicenseVersion = (int) ($incoming['version'] ?? -1);
            if ($incomingModuleVersion < $currentModuleVersion
                || ($incomingModuleVersion === $currentModuleVersion
                    && $incomingLicenseVersion < $currentLicenseVersion)) {
                return 0;
            }
        }

        $this->setex((string) $key, (int) $ttl, (string) $incomingJson);
        return 1;
    }

    public function del(string $key): int|false
    {
        if ($this->failDelete) {
            return false;
        }

        $existed = isset($this->values[$key]);
        unset($this->values[$key], $this->ttls[$key]);
        return $existed ? 1 : 0;
    }
}

$originalWorkingDirectory = getcwd();
$isolatedWorkingDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b8im-module-catalog-' . bin2hex(random_bytes(8));
if ($originalWorkingDirectory === false || !mkdir($isolatedWorkingDirectory, 0700)) {
    throw new RuntimeException('无法创建模块发现隔离目录。');
}

try {
    if (!chdir($isolatedWorkingDirectory)) {
        throw new RuntimeException('无法切换模块发现隔离目录。');
    }

    /** @var array{manifest_roots: list<string>} $moduleConfig */
    $moduleConfig = require dirname(__DIR__) . '/plugin/saimulti/config/module.php';
    $catalog = new ManifestCatalog($moduleConfig['manifest_roots']);
    $entries = $catalog->all();
} finally {
    chdir($originalWorkingDirectory);
    rmdir($isolatedWorkingDirectory);
}

$installedSdkRoot = InstalledVersions::getInstallPath('b8im/module-sdk');
$expectedAnnouncementRoot = is_string($installedSdkRoot)
    ? rtrim($installedSdkRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR . 'announcement'
    : '';
$expectedManifestRoots = [$expectedAnnouncementRoot];
foreach ([
    'b8im/module-i18n',
    'b8im/module-favorite',
    'b8im/module-sticker',
    'b8im/module-customer-service',
    'b8im/module-robot-single',
    'b8im/module-file-media',
    'b8im/module-search',
    'b8im/module-moments',
] as $package) {
    $root = InstalledVersions::isInstalled($package)
        ? InstalledVersions::getInstallPath($package)
        : null;
    if (is_string($root) && is_file($root . DIRECTORY_SEPARATOR . 'module.json')) {
        $expectedManifestRoots[] = rtrim($root, DIRECTORY_SEPARATOR);
    }
}
$assert(
    $moduleConfig['manifest_roots'] === $expectedManifestRoots,
    '生产配置未从 Composer 安装路径定位完整模块集合',
);
$assert(isset($entries['announcement']), 'SDK announcement manifest 未被受控 catalog 发现');
$assert(
    $entries['announcement']['root'] === realpath($expectedAnnouncementRoot),
    'catalog 未使用 Composer 安装包内的 announcement 目录',
);
$manifest = $entries['announcement']['manifest'];
$assert($manifest->dependsOn() === [], 'canonical depends_on 读取不正确');
$assert($manifest->conflictsWith() === [], 'canonical conflicts_with 读取不正确');

$secureManifestData = $manifest->toArray();
$secureManifestData['config'][] = [
    'key' => 'api_token',
    'name' => '模块 API Token',
    'description' => '只能由 Server 解密使用。',
    'type' => 'secret',
    'scope' => 'tenant',
    'required' => true,
    'sensitive' => true,
    'default' => 'manifest-secret-must-never-leak',
];
$secureManifest = new Manifest($secureManifestData);
$configValidator = new ModuleConfigValidator();
$assert(
    $configValidator->defaults($manifest) === ['display_mode' => 'list', 'require_read_ack' => false],
    '公告的非敏感默认配置受敏感字段改造影响',
);
$configCipher = new ModuleConfigCipher(str_repeat('test-key-material-', 3));
$configProtector = new ModuleConfigProtector($configValidator, $configCipher);
$storedSecureConfig = $configProtector->prepareForPersistence(
    $secureManifest,
    ['display_mode' => 'popup', 'require_read_ack' => true, 'api_token' => 'tenant-secret-value'],
    [],
    1,
    'announcement',
);
$encryptedToken = $storedSecureConfig['api_token'] ?? null;
$assert(
    is_string($encryptedToken) && str_starts_with($encryptedToken, ModuleConfigCipher::PREFIX),
    '敏感模块配置未使用版本化密文 envelope',
);
$assert(
    !str_contains(json_encode($storedSecureConfig, JSON_THROW_ON_ERROR), 'tenant-secret-value'),
    '敏感模块配置以明文落入 config_json',
);
$assert(
    $configProtector->internalValues($secureManifest, $storedSecureConfig, 1, 'announcement')['api_token']
        === 'tenant-secret-value',
    'Server 内部无法安全解密敏感模块配置',
);
$publicSecureConfig = $configProtector->publicProjection($secureManifest, $storedSecureConfig);
$assert(
    ($publicSecureConfig['values']['api_token'] ?? null) === ''
        && ($publicSecureConfig['configured']['api_token'] ?? false) === true,
    '租户配置读取没有使用空值 + configured 脱敏契约',
);
$publicSecretSchema = array_values(array_filter(
    $configProtector->publicSchema($secureManifest),
    static fn (array $definition): bool => ($definition['key'] ?? null) === 'api_token',
))[0] ?? [];
$assert(!array_key_exists('default', $publicSecretSchema), '敏感默认值通过配置 schema 泄漏');
$sanitizedSecretDefinition = array_values(array_filter(
    $configProtector->sanitizedManifestData($secureManifest)['config'],
    static fn (array $definition): bool => ($definition['key'] ?? null) === 'api_token',
))[0] ?? [];
$assert(
    !array_key_exists('default', $sanitizedSecretDefinition),
    '敏感默认值通过 manifest 快照或管理端目录泄漏',
);

$blankPreservedConfig = $configProtector->prepareForPersistence(
    $secureManifest,
    ['display_mode' => 'both', 'api_token' => '   '],
    $storedSecureConfig,
    1,
    'announcement',
);
$assert(
    $blankPreservedConfig['api_token'] === $encryptedToken,
    '敏感配置留空更新没有保留原密文',
);
$assert($blankPreservedConfig['display_mode'] === 'both', '普通模块配置未正常更新');

$replacedSecureConfig = $configProtector->prepareForPersistence(
    $secureManifest,
    ['api_token' => 'replacement-secret'],
    $blankPreservedConfig,
    1,
    'announcement',
);
$assert($replacedSecureConfig['api_token'] !== $encryptedToken, '非空敏感配置未替换原密文');
$assert(
    $configCipher->decryptValue($replacedSecureConfig['api_token'], 1, 'announcement', 'api_token')
        === 'replacement-secret',
    '替换后的敏感配置解密值不正确',
);

$tampered = $encryptedToken;
$tamperOffset = strlen(ModuleConfigCipher::PREFIX) + 5;
$tampered[$tamperOffset] = $tampered[$tamperOffset] === 'A' ? 'B' : 'A';
try {
    $configCipher->decryptValue($tampered, 1, 'announcement', 'api_token');
    throw new RuntimeException('被篡改的模块配置密文被接受');
} catch (\plugin\saimulti\exception\ApiException $exception) {
    $assert($exception->getCode() === 500, '密文篡改未失败关闭');
}
try {
    $configCipher->decryptValue($encryptedToken, 2, 'announcement', 'api_token');
    throw new RuntimeException('模块配置密文被跨租户复用');
} catch (\plugin\saimulti\exception\ApiException $exception) {
    $assert($exception->getCode() === 500, '跨 organization 密文复用未失败关闭');
}
try {
    $configCipher->decryptValue($encryptedToken, 1, 'announcement', 'another_field');
    throw new RuntimeException('模块配置密文被跨字段复用');
} catch (\plugin\saimulti\exception\ApiException $exception) {
    $assert($exception->getCode() === 500, '跨 field 密文复用未失败关闭');
}
try {
    $configCipher->decryptValue($encryptedToken, 1, 'another_module', 'api_token');
    throw new RuntimeException('模块配置密文被跨模块复用');
} catch (\plugin\saimulti\exception\ApiException $exception) {
    $assert($exception->getCode() === 500, '跨 module_key 密文复用未失败关闭');
}
try {
    (new ModuleConfigProtector($configValidator, new ModuleConfigCipher('weak-key')))
        ->prepareForPersistence($secureManifest, ['api_token' => 'new-secret'], [], 1, 'announcement');
    throw new RuntimeException('弱密钥下仍写入了敏感模块配置');
} catch (\plugin\saimulti\exception\ApiException $exception) {
    $assert($exception->getCode() === 503, '弱密钥下的敏感配置写入未失败关闭');
}
try {
    (new ModuleConfigProtector($configValidator, new ModuleConfigCipher('')))
        ->prepareForPersistence($secureManifest, ['api_token' => 'new-secret'], [], 1, 'announcement');
    throw new RuntimeException('缺少密钥时仍写入了敏感模块配置');
} catch (\plugin\saimulti\exception\ApiException $exception) {
    $assert($exception->getCode() === 503, '缺少密钥时的敏感配置写入未失败关闭');
}
try {
    $configProtector->prepareForPersistence(
        $secureManifest,
        ['api_token' => ''],
        ['display_mode' => 'list', 'require_read_ack' => false, 'api_token' => 'legacy-plaintext'],
        1,
        'announcement',
    );
    throw new RuntimeException('旧明文敏感配置被静默兼容');
} catch (\plugin\saimulti\exception\ApiException $exception) {
    $assert($exception->getCode() === 500, '旧明文敏感配置未失败关闭');
}
$legacyReplacement = $configProtector->prepareForPersistence(
    $secureManifest,
    ['api_token' => 'explicit-secure-replacement'],
    ['display_mode' => 'list', 'require_read_ack' => false, 'api_token' => 'legacy-plaintext'],
    1,
    'announcement',
);
$assert(
    str_starts_with($legacyReplacement['api_token'], ModuleConfigCipher::PREFIX)
        && !str_contains($legacyReplacement['api_token'], 'legacy-plaintext'),
    '显式重新填写没有将旧明文替换为密文',
);
$assert(
    $configCipher->decryptValue($legacyReplacement['api_token'], 1, 'announcement', 'api_token')
        === 'explicit-secure-replacement',
    '旧明文显式替换后的密文内容不正确',
);

$migrationRunner = new ModuleMigrationRunner();
$migrationFiles = $migrationRunner->migrationFiles($manifest, $entries['announcement']['path']);
$assert(count($migrationFiles) === 1, 'announcement 必须声明一个 Server Phinx migration');
$assert(str_contains($migrationFiles[0], '20260710010100_create_announcement_tables.php'), 'announcement migration 必须使用 14 位 Phinx 版本');
$assert($migrationRunner->migrationTable('announcement') === 'phinxlog_module_announcement', 'announcement 未使用独立 Phinx log 表');
$assert(
    $migrationRunner->migrationTable('announcement') !== $migrationRunner->migrationTable('customer_service'),
    '两个模块使用相同 timestamp 时仍共用 Phinx log 表',
);
$sameTimestamp = '20260710010100';
$assert(
    [$migrationRunner->migrationTable('announcement'), $sameTimestamp]
        !== [$migrationRunner->migrationTable('customer_service'), $sameTimestamp],
    '两个模块的相同 migration timestamp 没有形成独立标识',
);
$assert(
    ModuleLicenseInputNormalizer::remark('  集成测试  ') === '集成测试',
    'remark 未去除首尾空白',
);
$assert(
    ModuleLicenseInputNormalizer::futureExpiry('2030-01-02 03:04:05', 1_700_000_000) === '2030-01-02 03:04:05',
    'expire_at 严格时间未被保留',
);
try {
    ModuleLicenseInputNormalizer::futureExpiry('tomorrow', 1_700_000_000);
    throw new RuntimeException('expire_at 接受了相对时间');
} catch (\plugin\saimulti\exception\ApiException $exception) {
    $assert($exception->getCode() === 422, 'expire_at 格式错误未返回 422');
}
try {
    ModuleLicenseInputNormalizer::remark(str_repeat('界', 256));
    throw new RuntimeException('remark 接受了超长文本');
} catch (\plugin\saimulti\exception\ApiException $exception) {
    $assert($exception->getCode() === 422, 'remark 超长未返回 422');
}

$hookResult = (new ModuleLifecycleHookRunner(static fn (callable $callback): mixed => $callback()))
    ->run($manifest, LifecycleOperation::INSTALL);
$assert(($hookResult['module_key'] ?? null) === 'announcement', '受控 install hook 未执行');
$assert(!ModuleManifestSnapshotPolicy::replaceSnapshotOnDiscover(SystemModuleStatus::ENABLED->value), 'discover 会覆盖 ENABLED 模块的已安装 manifest 快照');
$assert(ModuleManifestSnapshotPolicy::replaceSnapshotOnDiscover(SystemModuleStatus::DISCOVERED->value), 'DISCOVERED 模块未更新 manifest 快照');
$activeDiscoveryUpdate = ModuleManifestSnapshotPolicy::discoveryUpdate(SystemModuleStatus::INSTALLED->value, [
    'available_version' => '0.2.0',
    'manifest_path' => '/controlled/module.json',
    'manifest_json' => '{"version":"0.2.0"}',
    'platforms_json' => '["web"]',
    'capabilities_json' => '{"web":["new.capability"]}',
]);
$assert(
    array_keys($activeDiscoveryUpdate) === ['available_version', 'manifest_path'],
    'active discover 仍会提前替换 manifest/platform/capability 已安装快照',
);
$assert(
    ModuleFailureRecoveryPolicy::target('install', SystemModuleStatus::DISCOVERED, null) === SystemModuleStatus::FAILED,
    '首次 install 失败未进入 FAILED',
);
$assert(
    ModuleFailureRecoveryPolicy::target('upgrade', SystemModuleStatus::UPGRADING, SystemModuleStatus::ENABLED) === SystemModuleStatus::FAILED,
    'upgrade 进入 UPGRADING 后失败未保持 FAILED 失败关闭',
);
$assert(
    ModuleFailureRecoveryPolicy::target('system_disable', SystemModuleStatus::ENABLED, SystemModuleStatus::ENABLED) === SystemModuleStatus::ENABLED,
    'disable hook 失败错误污染稳定状态',
);

$rollbackManifestData = $manifest->toArray();
$rollbackManifestData['hooks']['install']['handler'] = ModuleRollbackTestHook::class . '::install';
$rollbackManifest = new Manifest($rollbackManifestData);
$transactionCalled = false;
$transactionRunner = static function (callable $callback) use (&$transactionCalled): mixed {
    $transactionCalled = true;
    $before = ModuleRollbackTestHook::$writes;
    try {
        return $callback();
    } catch (Throwable $exception) {
        ModuleRollbackTestHook::$writes = $before;
        throw $exception;
    }
};
try {
    (new ModuleLifecycleHookRunner($transactionRunner))->run($rollbackManifest, LifecycleOperation::INSTALL);
    throw new RuntimeException('失败 hook 未抛出异常');
} catch (RuntimeException $exception) {
    $assert($exception->getMessage() === 'hook failed', 'hook 异常被错误改写');
}
$assert($transactionCalled, 'transactional hook 未进入事务执行器');
$assert(ModuleRollbackTestHook::$writes === 0, 'transactional hook 异常后未回滚');

$testLock = new ModuleTestLock();
$lockExecutor = new ModuleLockExecutor($testLock, 900);
$nested = $lockExecutor->run('announcement', fn (): string => $lockExecutor->run('announcement', fn (): string => 'ok'));
$assert($nested === 'ok', '模块生命周期锁重入执行失败');
$assert($testLock->acquires === 1 && $testLock->releases === 1, '嵌套 discover/install 重复获取分布式锁');
$testLock->deny = true;
try {
    $lockExecutor->run('announcement', static fn (): null => null);
    throw new RuntimeException('并发生命周期操作未被锁拒绝');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), '正在执行其他'), '锁拒绝异常不正确');
}

$store = new ModuleTestStore();
$cache = new ModuleTestCache();
$access = new ModuleAccessService($store, $cache);
$assert($access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), '有效模块授权被错误拒绝');
$assert($store->tenantSnapshotReads === 1, '首次授权判断未回源 DB');
$assert($access->enabledSystemModuleKeys('admin') === ['announcement'], '平台管理端未投影系统已启用模块');
$cached = $cache->values['module_license:1:announcement'];
$assert(isset($cached['enabled'], $cached['version']) && array_key_exists('effective_until', $cached), '授权缓存缺少必备字段');
$assert(
    array_key_exists('module_version', $cached) && array_key_exists('module_lock_version', $cached),
    '授权缓存与 IM 共享契约缺少模块版本字段',
);
$assert(
    $access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '缓存 allow 回源后错误拒绝 DB 仍有效的授权',
);
$assert($store->tenantSnapshotReads === 2, '缓存 allow 未强制回源 DB');

$cacheKey = 'module_license:1:announcement';
$enabledSnapshot = $store->snapshot;

$cache->values[$cacheKey] = $cached;
$store->snapshot = $enabledSnapshot;
$store->snapshot['module_status'] = SystemModuleStatus::DISABLED->value;
$assert(
    !$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '旧缓存 allow 在 DB 系统模块已禁用时仍被放行',
);

$cache->values[$cacheKey] = $cached;
$store->snapshot = $enabledSnapshot;
$store->snapshot['license_status'] = TenantModuleStatus::DISABLED->value;
$assert(
    !$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '旧缓存 allow 在 DB 租户模块已禁用时仍被放行',
);
$assert(($cache->values[$cacheKey]['enabled'] ?? true) === false, 'DB 禁用快照未回写缓存');

$cache->values[$cacheKey] = $cached;
$store->snapshot = $enabledSnapshot;
$store->snapshot['license_status'] = TenantModuleStatus::UNAUTHORIZED->value;
$assert(
    !$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '旧缓存 allow 在 DB 授权已撤销时仍被放行',
);

$cache->values[$cacheKey] = $cached;
$store->snapshot = $enabledSnapshot;
$store->snapshot['expire_at'] = date('Y-m-d H:i:s', time() - 1);
$assert(
    !$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '旧缓存 allow 在 DB 授权已过期时仍被放行',
);

$cache->values[$cacheKey] = $cached;
$store->snapshot = $enabledSnapshot;
$store->missing = true;
$assert(
    !$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '旧缓存 allow 在 DB 授权记录不存在时仍被放行',
);
$store->missing = false;

$cache->values[$cacheKey] = $cached;
$store->fail = true;
$assert(
    !$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '旧缓存 allow 在 DB 回源失败时未失败关闭',
);
$store->fail = false;

$cache->values[$cacheKey] = $cached;
$store->snapshot = $enabledSnapshot;
$readsBeforeCachedAllow = $store->tenantSnapshotReads;
$assert(
    $access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '旧缓存 allow 在 DB 授权仍有效时被错误拒绝',
);
$assert(
    $store->tenantSnapshotReads === $readsBeforeCachedAllow + 1,
    '旧缓存 allow 未查询 DB 当前快照',
);

$cachedDeny = $cached;
$cachedDeny['enabled'] = false;
$cache->values[$cacheKey] = $cachedDeny;
$readsBeforeCachedDeny = $store->tenantSnapshotReads;
$store->fail = true;
$assert(
    !$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '缓存 deny 被错误放行',
);
$assert(
    $store->tenantSnapshotReads === $readsBeforeCachedDeny,
    '有效缓存 deny 仍回源 DB',
);
$store->fail = false;
$store->snapshot = $enabledSnapshot;

$rawRedis = new ModuleRawRedisTestHandler();
$rawCache = new ThinkCacheModuleAccessCache(999, $rawRedis);
$rawKey = 'module_license:1:announcement';
$rawSnapshot = $cached;
$rawSnapshot['effective_until'] = time() + 30;
$rawCache->set($rawKey, $rawSnapshot);
$assert(array_keys($rawRedis->values) === [$rawKey], 'Server 授权缓存使用了额外物理 key prefix');
$assert(
    $rawRedis->ttls[$rawKey] >= 1 && $rawRedis->ttls[$rawKey] <= 30,
    '授权缓存 TTL 未受 bounded TTL/effective_until 双重限制',
);
$assert($rawCache->get($rawKey) === $rawSnapshot, '授权缓存未使用可互操作 JSON 契约');
$newerSnapshot = $rawSnapshot;
$newerSnapshot['enabled'] = false;
$newerSnapshot['version'] = (int) $rawSnapshot['version'] + 1;
$rawCache->set($rawKey, $newerSnapshot);
$rawCache->set($rawKey, $rawSnapshot);
$assert($rawCache->get($rawKey) === $newerSnapshot, '提交前读取的旧授权在提交后覆盖了新快照');
$newerModuleSnapshot = $newerSnapshot;
$newerModuleSnapshot['module_lock_version'] = (int) $newerSnapshot['module_lock_version'] + 1;
$newerModuleSnapshot['version'] = 0;
$rawCache->set($rawKey, $newerModuleSnapshot);
$lateLicenseSnapshot = $newerSnapshot;
$lateLicenseSnapshot['version'] = 999;
$rawCache->set($rawKey, $lateLicenseSnapshot);
$assert($rawCache->get($rawKey) === $newerModuleSnapshot, '旧模块版本以较大 license version 覆盖了新系统状态');
$rawRedis->failDelete = true;
$deleteFailureReported = false;
try {
    $rawCache->delete($rawKey);
} catch (RuntimeException $exception) {
    $deleteFailureReported = str_contains($exception->getMessage(), '授权缓存删除失败');
}
$assert($deleteFailureReported, 'Redis DEL 返回 false 时未报告授权缓存删除失败');
$assert(isset($rawRedis->values[$rawKey]), 'Redis DEL 失败测试未保留原缓存值');
$rawRedis->failDelete = false;
$rawCache->delete($rawKey);
$assert($rawRedis->values === [], '授权缓存未删除原始 Redis key');
$assert((new ThinkCacheModuleAccessCache(0, $rawRedis))->ttlSeconds() === 1, '授权缓存 TTL 下界未生效');
$assert($rawCache->ttlSeconds() === 300, '授权缓存 TTL 上界未生效');

$authInvalidationEvents = [];
$authInvalidator = new ModuleAuthCacheInvalidator(
    static function () use (&$authInvalidationEvents): void {
        $authInvalidationEvents[] = 'admin';
    },
    static function (?int $organization) use (&$authInvalidationEvents): void {
        $authInvalidationEvents[] = $organization === null ? 'tenant:all' : 'tenant:' . $organization;
    },
);
$authInvalidator->systemStateChanged();
$assert(
    $authInvalidationEvents === ['admin', 'tenant:all'],
    '系统模块状态缓存失效未同时覆盖 Admin/Tenant',
);
$authInvalidationEvents = [];
$authInvalidator->tenantStateChanged(901);
$assert($authInvalidationEvents === ['tenant:901'], '单租户状态变更错误清理 Admin 权限缓存');

$authInvalidationEvents = [];
$rolledBackInvalidationEvents = [];
$rollbackExecutor = new ModuleTransactionExecutor(
    static function (callable $callback) use (&$rolledBackInvalidationEvents): mixed {
        $rolledBackInvalidationEvents[] = 'begin';
        $callback();
        $rolledBackInvalidationEvents[] = 'rollback';
        throw new RuntimeException('rollback sentinel');
    },
);
try {
    $rollbackExecutor->run(
        static function () use (&$rolledBackInvalidationEvents): void {
            $rolledBackInvalidationEvents[] = 'mutation_staged';
        },
        [static function () use ($authInvalidator): void {
            $authInvalidator->systemStateChanged();
        }],
    );
    throw new RuntimeException('事务回滚测试未抛出预期异常');
} catch (RuntimeException $exception) {
    $assert($exception->getMessage() === 'rollback sentinel', '事务回滚测试异常不正确');
}
$assert(
    $rolledBackInvalidationEvents === ['begin', 'mutation_staged', 'rollback'],
    '事务回滚前错误执行了权限缓存失效',
);
$assert($authInvalidationEvents === [], '回滚事务的 after-commit 权限缓存回调仍被执行');

$interleavingStore = new ModuleTestStore();
$interleavingCache = new ModuleTestCache();
$interleavingAccess = new ModuleAccessService($interleavingStore, $interleavingCache);
$assert(
    $interleavingAccess->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '交错测试未预热 enabled 快照',
);
$events = [];
$transactionExecutor = new ModuleTransactionExecutor(
    static function (callable $callback) use (&$events, $interleavingCache, $interleavingAccess, $interleavingStore, $assert): mixed {
        $events[] = 'begin';
        $result = $callback();

        // Simulate TTL expiry and a concurrent request while the mutation is
        // still uncommitted. It refills the old DB snapshot.
        $interleavingCache->values = [];
        $events[] = 'cache_expired_before_commit';
        $assert(
            $interleavingAccess->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
            '交错请求未读到提交前的数据库快照',
        );
        $events[] = 'old_snapshot_refilled';

    $interleavingStore->snapshot['license_status'] = TenantModuleStatus::DISABLED->value;
    $interleavingStore->snapshot['license_version']++;
        $events[] = 'commit';
        return $result;
    },
);
$transactionExecutor->run(
    static function () use (&$events): void {
        $events[] = 'mutation_staged';
    },
    [static function () use (&$events, $interleavingAccess): void {
        $events[] = 'invalidate_after_commit';
        $interleavingAccess->invalidate(1, 'announcement');
    }],
);
$assert(
    $events === [
        'begin',
        'mutation_staged',
        'cache_expired_before_commit',
        'old_snapshot_refilled',
        'commit',
        'invalidate_after_commit',
    ],
    '缓存失效没有严格发生在 DB commit 之后',
);
$assert(
    !$interleavingAccess->isAvailable(1, 'announcement', 'web', 'announcement.web.page'),
    '提交前并发回填的旧授权快照未被提交后失效',
);

$cache->values = [];
$cache->failRead = true;
$assert($access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), 'Redis 故障时未回源 DB');
$store->fail = true;
$cache->values = [];
$assert(!$access->isAvailable(1, 'announcement', 'web', 'announcement.web.page'), 'Redis 和 DB 同时失败时未失败关闭');

$store->fail = false;
$cache->failRead = false;
$cache->values = [];
$projection = new ClientConfigProjectionService(
    $access,
    static fn (): array => [$secureManifest],
    static fn (int $organization): int => 12,
    static fn (int $organization, string $moduleKey): array => [
        'display_mode' => 'both',
        'require_read_ack' => true,
        'api_token' => 'client-projection-must-not-leak',
    ],
);
$projected = $projection->project(1, 'b8im-local', 'web');
$assert(array_keys($projected) === ['version', 'organization', 'deployment_id', 'features', 'modules', 'tabbar'], '客户端配置投影结构发生漂移');
$assert($projected['organization'] === 1 && $projected['deployment_id'] === 'b8im-local', '投影丢失认证上下文');
$assert($projected['version'] === 12, 'Web parser 要求 version 为正整数');
$assert(($projected['features']['announcement'] ?? false) === true, 'features 未使用 module_key => bool 契约');
$emptyProjection = (new ClientConfigProjectionService(
    $access,
    static fn (): array => [],
    static fn (int $organization): int => 1,
))->project(1, 'b8im-module-test', 'app');
$emptyProjectionWire = json_decode(json_encode($emptyProjection, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
$assert(is_object($emptyProjectionWire->features ?? null), '空 features 在线路 JSON 中必须保持 object，不能退化为 []');
$assert(count($projected['modules']) === 1, '投影输出了未授权模块');
$assert(
    array_keys($projected['modules'][0]) === ['module_key', 'version', 'available', 'capabilities', 'permissions', 'config'],
    'modules item 与 Web parser 固定契约不一致',
);
$assert(
    $projected['modules'][0]['config'] === ['display_mode' => 'both', 'require_read_ack' => true],
    '非敏感租户模块配置未投影给 Web，或敏感配置被投影',
);
$defaultProjection = (new ClientConfigProjectionService(
    $access,
    static fn (): array => [$secureManifest],
    static fn (int $organization): int => 12,
    static fn (int $organization, string $moduleKey): ?array => null,
))->project(1, 'b8im-local', 'web');
$assert(
    $defaultProjection['modules'][0]['config'] === ['display_mode' => 'list', 'require_read_ack' => false],
    '租户未保存模块配置时没有投影 manifest 默认值',
);
$invalidListRejected = false;
try {
    (new ClientConfigProjectionService(
        $access,
        static fn (): array => [$secureManifest],
        static fn (int $organization): int => 12,
        static fn (int $organization, string $moduleKey): array => ['invalid-list-item'],
    ))->project(1, 'b8im-local', 'web');
} catch (ApiException $exception) {
    $invalidListRejected = $exception->getCode() === 500;
}
$assert($invalidListRejected, '非空列表模块配置未被拒绝');
$assert(array_is_list($projected['modules'][0]['capabilities']), 'Web capabilities 未投影为扁平数组');
$assert(
    $projected['tabbar'] === [['module_key' => 'announcement', 'title' => '公告']],
    'tabbar 未按 Web parser 的 module_key/title 结构输出',
);
$appProjected = $projection->project(1, 'b8im-local', 'app');
$assert(($appProjected['features']['announcement'] ?? false) === true, 'App 未按组织授权投影 announcement');
$assert(in_array('announcement.app.page', $appProjected['modules'][0]['capabilities'], true), 'App capability 未投影');
$assert(in_array('saimulti:app:announcement:index', $appProjected['modules'][0]['permissions'], true), 'App permission 未投影');
$assert($appProjected['tabbar'] === [['module_key' => 'announcement', 'title' => '公告']], 'App menu 未投影为 tabbar');
$assert($projected === $projection->project(1, 'b8im-local', 'web'), '同一有效投影的 version 不稳定');

try {
    new ModuleRequired('Not-Snake');
    throw new RuntimeException('ModuleRequired 未拒绝非 snake_case module_key');
} catch (InvalidArgumentException) {
    $passed++;
}

echo sprintf("ModuleSystemTest: %d assertions passed\n", $passed);
