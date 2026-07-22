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
use B8im\ModuleSdk\Manifest\ManifestLoader;
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

final class IntegrationExpiryHook implements ModuleLifecycleInterface
{
    /** @var array<string,bool> */
    public static array $failOnce = [];

    /** @var array<string,bool> */
    public static array $expireLeaseDuringHook = [];

    public function install(LifecycleContext $context): LifecycleResult
    {
        return LifecycleResult::success();
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
        $options = $context->options();
        $credential = $options[ModuleLicenseExpiryScanner::HOOK_CREDENTIAL_OPTION] ?? null;
        $idempotencyKey = $options[ModuleLicenseExpiryScanner::HOOK_IDEMPOTENCY_OPTION] ?? null;
        $requestDigest = $options[ModuleLicenseExpiryScanner::HOOK_REQUEST_DIGEST_OPTION] ?? null;
        $taskId = $options[ModuleLicenseExpiryScanner::HOOK_TASK_ID_OPTION] ?? null;
        if (!is_array($credential)
            || !is_string($idempotencyKey)
            || preg_match('/^[0-9a-f]{64}$/D', $idempotencyKey) !== 1
            || !is_string($requestDigest)
            || preg_match('/^[0-9a-f]{64}$/D', $requestDigest) !== 1
            || !is_string($taskId)
            || preg_match('/^[1-9][0-9]*$/D', $taskId) !== 1) {
            return LifecycleResult::failure('expiry hook durable credential is invalid');
        }
        $moduleKey = (string) ($credential['module_key'] ?? '');
        Db::table('sm_module_lifecycle_audit')->insert([
            'module_key' => $moduleKey,
            'operation' => 'expiry_effect_sentinel',
            'success' => 1,
            'operator_type' => 'test',
            'context_json' => json_encode([
                'idempotency_key' => $idempotencyKey,
                'request_digest' => $requestDigest,
                'credential' => $credential,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'create_time' => date('Y-m-d H:i:s'),
        ]);
        if ((self::$expireLeaseDuringHook[$moduleKey] ?? false) === true) {
            Db::table('sm_module_expiry_hook_task')->where('id', $taskId)->update([
                'locked_until' => '2000-01-01 00:00:00',
            ]);
        }
        if ((self::$failOnce[$moduleKey] ?? false) === true) {
            self::$failOnce[$moduleKey] = false;
            return LifecycleResult::failure('planned transactional expiry failure');
        }

        return LifecycleResult::success('expiry effect committed', [
            'idempotency_key' => $idempotencyKey,
            'request_digest' => $requestDigest,
            'credential' => $credential,
        ]);
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

$canonicalExpiryStateCheck = <<<'SQL'
(status = 'pending' AND attempt_count = 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NULL AND finished_at IS NULL)
OR (status = 'processing' AND attempt_count > 0
 AND worker_token IS NOT NULL AND worker_token REGEXP '^[0-9a-f]{40}$'
 AND locked_until IS NOT NULL AND next_retry_at IS NULL
 AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NULL AND finished_at IS NULL)
OR (status = 'retry' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NOT NULL
 AND last_error IS NOT NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NULL AND finished_at IS NULL)
OR (status = 'succeeded' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NULL AND receipt_json IS NOT NULL AND receipt_recorded_at IS NOT NULL
 AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL)
OR (status = 'superseded' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL)
OR (status = 'contract_failed' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NOT NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL)
SQL;
$expiryTaskStatuses = [
    'pending',
    'processing',
    'retry',
    'succeeded',
    'superseded',
    'contract_failed',
];
$canonicalExpiryStatusCheck = "status IN ('pending','processing','retry','succeeded','superseded','contract_failed')";
$groupDriftExpiryStateCheck = <<<'SQL'
status = 'pending' AND attempt_count = 0
AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
AND last_error IS NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
AND outcome_audit_id IS NULL AND (finished_at IS NULL
OR status = 'processing') AND attempt_count > 0
AND worker_token IS NOT NULL AND worker_token REGEXP '^[0-9a-f]{40}$'
AND locked_until IS NOT NULL AND next_retry_at IS NULL
AND receipt_json IS NULL AND receipt_recorded_at IS NULL
AND outcome_audit_id IS NULL AND finished_at IS NULL
OR (status = 'retry' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NOT NULL
 AND last_error IS NOT NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NULL AND finished_at IS NULL)
OR (status = 'succeeded' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NULL AND receipt_json IS NOT NULL AND receipt_recorded_at IS NOT NULL
 AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL)
OR (status = 'superseded' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL)
OR (status = 'contract_failed' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NOT NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL)
SQL;
$legacyNormalizeCheck = static fn (string $clause): string => strtolower((string) preg_replace(
    '/[`\\s()]+/',
    '',
    str_replace('\\', '', $clause),
));
$assert(
    $legacyNormalizeCheck($canonicalExpiryStateCheck) === $legacyNormalizeCheck($groupDriftExpiryStateCheck),
    '分组漂移反例必须保持旧归一化所见的 atom/operator token 顺序。',
);
$expiryMigrationRecorded = static fn (): int => (int) $pdo->query(
    'SELECT COUNT(*) FROM phinxlog WHERE version=20260721120000',
)->fetchColumn();
$assert(
    $expiryMigrationRecorded() === 1,
    'durable expiry task migration fresh apply was not recorded by Phinx.',
);
$mysqlExpiryChecks = array_column(
    $pdo->query(
        "SELECT CONSTRAINT_NAME,CHECK_CLAUSE
           FROM information_schema.CHECK_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA=DATABASE()
            AND CONSTRAINT_NAME LIKE 'chk_expiry_task_%'",
    )->fetchAll(PDO::FETCH_ASSOC),
    'CHECK_CLAUSE',
    'CONSTRAINT_NAME',
);
$mysqlIdentityClause = (string) ($mysqlExpiryChecks['chk_expiry_task_identity'] ?? '');
$assert(
    count($mysqlExpiryChecks) === 3
    && str_contains($mysqlIdentityClause, 'regexp_like(')
    && str_contains($mysqlIdentityClause, "_utf8mb4\\'")
    && str_contains($mysqlIdentityClause, str_repeat('\\', 4) . '.'),
    'MySQL 8 CHECK_CLAUSE did not expose the expected regexp/introducer/backslash metadata form.',
);

$shapeConfigPath = dirname(__DIR__) . '/phinx.php';
$newShapeMigrationManager = static function () use ($shapeConfigPath): Manager {
    $shapeInput = new ArrayInput([]);
    $shapeInput->setInteractive(false);

    return new Manager(
        new Config(require $shapeConfigPath, $shapeConfigPath),
        $shapeInput,
        new BufferedOutput(),
    );
};
$shapeMigrationManager = $newShapeMigrationManager();
$shapeMigrations = $shapeMigrationManager->getMigrations('default');
$expiryTaskMigration = $shapeMigrations[20260721120000] ?? null;
if (!$expiryTaskMigration instanceof Phinx\Migration\MigrationInterface) {
    throw new RuntimeException('durable expiry task migration is unavailable.');
}
$expiryTaskMigration->setAdapter($shapeMigrationManager->getEnvironment('default')->getAdapter());
$expiryTaskMigration->up();
$assert(true, 'MySQL 8 information_schema CHECK_CLAUSE 的合法同形重跑失败。');
$normalizeCheck = (new ReflectionClass($expiryTaskMigration))->getMethod('normalizeCheck');
$parserProbeClause = <<<'SQL'
(probe_value = 'O''Reilly\\path (and or)')
OR probe_value REGEXP '^(left\\)|right)$'
SQL;
$pdo->exec('DROP TABLE IF EXISTS sm_expiry_check_parser_probe');
try {
    $pdo->exec(
        'CREATE TABLE sm_expiry_check_parser_probe ('
        . 'id int unsigned NOT NULL PRIMARY KEY,probe_value varchar(100) NOT NULL,'
        . 'CONSTRAINT chk_expiry_parser_probe CHECK (' . $parserProbeClause . ')'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    );
    $parserProbeActual = (string) $pdo->query(
        "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA=DATABASE()
            AND CONSTRAINT_NAME='chk_expiry_parser_probe'",
    )->fetchColumn();
    $assert(
        str_contains($parserProbeActual, str_repeat('\\', 3) . "'Reilly")
        && $normalizeCheck->invoke($expiryTaskMigration, $parserProbeActual)
            === $normalizeCheck->invoke($expiryTaskMigration, $parserProbeClause),
        'CHECK normalization did not preserve MySQL metadata quotes, backslashes, or literal parentheses.',
    );
} finally {
    $pdo->exec('DROP TABLE IF EXISTS sm_expiry_check_parser_probe');
}
$assert(
    $normalizeCheck->invoke($expiryTaskMigration, "from_status = 'ENABLED'")
        !== $normalizeCheck->invoke($expiryTaskMigration, "from_status = 'enabled'")
    && $normalizeCheck->invoke($expiryTaskMigration, "last_error = 'retry now'")
        !== $normalizeCheck->invoke($expiryTaskMigration, "last_error = 'retrynow'")
    && $normalizeCheck->invoke($expiryTaskMigration, 'license_id > 0 AND organization > 0')
        === $normalizeCheck->invoke($expiryTaskMigration, 'organization > 0 AND license_id > 0'),
    'CHECK AST normalization erased literal case or literal whitespace.',
);
$plainPendingCheck = $normalizeCheck->invoke($expiryTaskMigration, "status = 'pending'");
$assert(
    $plainPendingCheck === $normalizeCheck->invoke($expiryTaskMigration, "status = _utf8mb4'pending'")
    && $plainPendingCheck === $normalizeCheck->invoke($expiryTaskMigration, "status = _UTF8MB4'pending'")
    && $plainPendingCheck === $normalizeCheck->invoke(
        $expiryTaskMigration,
        "status = _UtF8Mb4\\'pending\\'",
    ),
    'CHECK normalization did not accept the real MySQL utf8mb4 introducer semantics.',
);
$unsupportedCharsetIntroducers = ['_binary', '_latin1', '_ascii'];
$unsupportedCharsetReflectionRejected = true;
foreach ($unsupportedCharsetIntroducers as $introducer) {
    foreach ([
        "status = {$introducer}'pending'",
        "status = {$introducer}\\'pending\\'",
    ] as $hostileClause) {
        try {
            $normalizeCheck->invoke($expiryTaskMigration, $hostileClause);
            $unsupportedCharsetReflectionRejected = false;
        } catch (Throwable $exception) {
            $unsupportedCharsetReflectionRejected = $unsupportedCharsetReflectionRejected
                && str_contains($exception->getMessage(), 'Unsupported character set introducer');
        }
    }
}
$assert(
    $unsupportedCharsetReflectionRejected,
    'CHECK normalization accepted an unsupported SQL or metadata charset introducer.',
);
$pdo->exec('DELETE FROM phinxlog WHERE version=20260721120000');
$assert(
    $expiryMigrationRecorded() === 0,
    'Unable to construct the exact unrecorded expiry migration retry.',
);
$newShapeMigrationManager()->migrate('default');
$assert(
    $expiryMigrationRecorded() === 1,
    'Phinx did not record an existing exact durable expiry target on retry.',
);

$pdo->exec('DELETE FROM phinxlog WHERE version=20260721120000');
foreach ($unsupportedCharsetIntroducers as $introducer) {
    $hostileExpiryStatusCheck = 'status IN (' . implode(',', array_map(
        static fn (string $status): string => $introducer . "'" . $status . "'",
        $expiryTaskStatuses,
    )) . ')';
    $pdo->exec(
        'ALTER TABLE sm_module_expiry_hook_task '
        . 'DROP CHECK chk_expiry_task_status, '
        . 'ADD CONSTRAINT chk_expiry_task_status CHECK (' . $hostileExpiryStatusCheck . ')',
    );
    $hostileExpiryStatusActual = (string) $pdo->query(
        "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA=DATABASE()
            AND CONSTRAINT_NAME='chk_expiry_task_status'",
    )->fetchColumn();
    $assert(
        str_contains($hostileExpiryStatusActual, $introducer . "\\'"),
        sprintf('MySQL did not retain the hostile %s CHECK introducer in metadata.', $introducer),
    );

    $unsupportedCharsetRetryRejected = false;
    try {
        $newShapeMigrationManager()->migrate('default');
    } catch (Throwable $exception) {
        $unsupportedCharsetRetryRejected = str_contains(
            $exception->getMessage(),
            'Unsupported character set introducer',
        );
    } finally {
        $pdo->exec(
            'ALTER TABLE sm_module_expiry_hook_task '
            . 'DROP CHECK chk_expiry_task_status, '
            . 'ADD CONSTRAINT chk_expiry_task_status CHECK (' . $canonicalExpiryStatusCheck . ')',
        );
    }
    $assert(
        $unsupportedCharsetRetryRejected && $expiryMigrationRecorded() === 0,
        sprintf('Hostile %s CHECK retry was accepted or recorded by Phinx.', $introducer),
    );
}
$newShapeMigrationManager()->migrate('default');
$assert(
    $expiryMigrationRecorded() === 1,
    'Restoring the canonical charset-free CHECK did not make the exact retry recordable.',
);

$pdo->exec('DELETE FROM phinxlog WHERE version=20260721120000');
$pdo->exec(
    'ALTER TABLE sm_module_expiry_hook_task '
    . 'DROP CHECK chk_expiry_task_state, '
    . 'ADD CONSTRAINT chk_expiry_task_state CHECK (' . $groupDriftExpiryStateCheck . ')',
);
$groupDriftRejected = false;
try {
    $newShapeMigrationManager()->migrate('default');
} catch (Throwable $exception) {
    $groupDriftRejected = str_contains($exception->getMessage(), 'CHECK definition drift detected');
} finally {
    $pdo->exec(
        'ALTER TABLE sm_module_expiry_hook_task '
        . 'DROP CHECK chk_expiry_task_state, '
        . 'ADD CONSTRAINT chk_expiry_task_state CHECK (' . $canonicalExpiryStateCheck . ')',
    );
}
$assert(
    $groupDriftRejected && $expiryMigrationRecorded() === 0,
    '相同 atom/operator 顺序的不同 AND/OR 分组漂移未被拒绝或被错误记账。',
);
$newShapeMigrationManager()->migrate('default');
$assert(
    $expiryMigrationRecorded() === 1,
    '恢复 canonical CHECK 后合法同形重跑未被 Phinx 记账。',
);

$access = new ModuleAccessService(new ThinkOrmModuleAccessStore(), new IntegrationArrayModuleCache());
$configuredManifestRoots = config('plugin.saimulti.module.manifest_roots', []);
$announcementManifestRoot = is_array($configuredManifestRoots)
    ? ($configuredManifestRoots[0] ?? null)
    : null;
if (!is_string($announcementManifestRoot) || $announcementManifestRoot === '') {
    throw new RuntimeException('announcement integration manifest root is unavailable.');
}
$catalog = new ManifestCatalog([$announcementManifestRoot]);
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
$assert($expiryResult['tasks_succeeded'] === 1, '到期扫描未消费 durable hook 任务');
$assert($authInvalidations === ['tenant:1'], '授权到期提交后未清理 Tenant 权限缓存');
$assert(
    (int) Db::table('sm_system_organization')->where('id', 1)->value('config_version') === $beforeExpiryVersion + 1,
    '授权到期未递增客户端配置 version',
);

// Durable expiry protocol: exact credential + transactional side effect +
// receipt + terminal audit commit as one fact. External hooks fail closed.
$insertExpiryFixture = static function (
    string $moduleKey,
    string $status,
    int $version,
    bool $transactional = true,
) use ($originalManifest): int {
    $manifestJson = str_replace(
        'announcement',
        $moduleKey,
        json_encode(
            $originalManifest->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ),
    );
    $manifestData = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
    $manifestData['version'] = '1.0.0';
    $manifestData['migrations'] = [];
    $manifestData['hooks']['disable'] = [
        'handler' => IntegrationExpiryHook::class . '::disable',
        'scope' => 'tenant',
        'transactional' => $transactional,
    ];
    $manifest = new Manifest($manifestData);
    $template = Db::table('sm_module')->where('module_key', 'announcement')->find();
    if (!is_array($template)) {
        throw new RuntimeException('expiry fixture module template missing');
    }
    unset($template['id']);
    $template['module_key'] = $moduleKey;
    $template['name'] = 'Expiry ' . $moduleKey;
    $template['version'] = $manifest->version();
    $template['available_version'] = $manifest->version();
    $template['manifest_json'] = json_encode(
        $manifest->toArray(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    $template['status'] = 'ENABLED';
    $template['lock_version'] = 1;
    $template['create_time'] = date('Y-m-d H:i:s');
    $template['update_time'] = date('Y-m-d H:i:s');
    Db::table('sm_module')->insert($template);

    return (int) Db::table('sm_tenant_module_license')->insertGetId([
        'organization' => 1,
        'module_key' => $moduleKey,
        'status' => $status,
        'expire_at' => '2000-01-01 00:00:00',
        'version' => $version,
        'assignment_source' => 'MANUAL',
        'create_time' => date('Y-m-d H:i:s'),
        'update_time' => date('Y-m-d H:i:s'),
    ]);
};
$newExpiryScanner = static fn (): ModuleLicenseExpiryScanner => new ModuleLicenseExpiryScanner(
    new IntegrationModuleLock(),
    $access,
    new ModuleAuditWriter(),
    new ModuleLifecycleHookRunner(),
    authCacheInvalidator: $authCacheInvalidator,
);
$reserveOnly = static function (ModuleLicenseExpiryScanner $scanner, int $licenseId): void {
    $candidate = Db::table('sm_tenant_module_license')->where('id', $licenseId)->find();
    if (!is_array($candidate)
        || (new ReflectionClass($scanner))->getMethod('reserveExpiry')->invoke($scanner, $candidate) !== true) {
        throw new RuntimeException('durable expiry reservation failed');
    }
};
$effectCount = static fn (string $moduleKey): int => (int) Db::table('sm_module_lifecycle_audit')
    ->where('module_key', $moduleKey)
    ->where('operation', 'expiry_effect_sentinel')
    ->count();

$atomicLicense = $insertExpiryFixture('expiry_atomic', 'ENABLED', 11);
$atomicScanner = $newExpiryScanner();
$reserveOnly($atomicScanner, $atomicLicense);
$pendingTask = Db::table('sm_module_expiry_hook_task')->where('license_id', $atomicLicense)->find();
$assert(
    ($pendingTask['status'] ?? null) === 'pending'
    && ($pendingTask['hook_kind'] ?? null) === 'transactional'
    && ($pendingTask['hook_module_version'] ?? null) === '1.0.0'
    && ($pendingTask['hook_handler'] ?? null) === IntegrationExpiryHook::class . '::disable'
    && ($pendingTask['hook_scope'] ?? null) === 'tenant'
    && (int) ($pendingTask['hook_transactional'] ?? 0) === 1
    && hash('sha256', (string) ($pendingTask['hook_contract_json'] ?? ''))
        === ($pendingTask['request_digest'] ?? null)
    && preg_match('/^[0-9a-f]{64}$/D', (string) ($pendingTask['idempotency_key'] ?? '')) === 1
    && preg_match('/^[0-9a-f]{64}$/D', (string) ($pendingTask['request_digest'] ?? '')) === 1,
    'expiry reservation did not freeze its exact durable credential contract',
);
$atomicResult = $atomicScanner->run();
$atomicTask = Db::table('sm_module_expiry_hook_task')->where('license_id', $atomicLicense)->find();
$receipt = json_decode((string) ($atomicTask['receipt_json'] ?? ''), true);
$assert(
    $atomicResult['tasks_succeeded'] === 1
    && ($atomicTask['status'] ?? null) === 'succeeded'
    && ($atomicTask['receipt_recorded_at'] ?? null) !== null
    && is_array($receipt)
    && ($receipt['idempotency_key'] ?? null) === $atomicTask['idempotency_key']
    && ($receipt['credential']['license_id'] ?? null) === (string) $atomicLicense
    && $effectCount('expiry_atomic') === 1,
    'hook side effect, exact receipt and terminal state did not commit together',
);
Db::table('sm_tenant_module_license')->where('id', $atomicLicense)->update([
    'status' => 'AUTHORIZED',
    'expire_at' => null,
    'version' => 13,
]);
$newExpiryScanner()->run();
$assert(
    Db::table('sm_module_expiry_hook_task')->where('id', $atomicTask['id'])->value('status') === 'succeeded'
    && $effectCount('expiry_atomic') === 1,
    'a later renewal overrode the authoritative committed receipt',
);

$renewedLicense = $insertExpiryFixture('expiry_renewed_first', 'ENABLED', 21);
$renewedScanner = $newExpiryScanner();
$reserveOnly($renewedScanner, $renewedLicense);
Db::table('sm_tenant_module_license')->where('id', $renewedLicense)->update([
    'status' => 'AUTHORIZED',
    'expire_at' => null,
    'version' => 23,
]);
$renewedResult = $renewedScanner->run();
$renewedTask = Db::table('sm_module_expiry_hook_task')->where('license_id', $renewedLicense)->find();
$assert(
    $renewedResult['tasks_superseded'] === 1
    && ($renewedTask['status'] ?? null) === 'superseded'
    && ($renewedTask['receipt_json'] ?? null) === null
    && $effectCount('expiry_renewed_first') === 0,
    'renewal that won the license lock did not supersede the stale hook without side effects',
);

$retryLicense = $insertExpiryFixture('expiry_tx_retry', 'ENABLED', 31);
$retryScanner = $newExpiryScanner();
$reserveOnly($retryScanner, $retryLicense);
IntegrationExpiryHook::$failOnce['expiry_tx_retry'] = true;
$retryFailed = false;
try {
    $retryScanner->run();
} catch (RuntimeException $exception) {
    $retryFailed = str_contains($exception->getMessage(), 'planned transactional expiry failure');
}
$retryTask = Db::table('sm_module_expiry_hook_task')->where('license_id', $retryLicense)->find();
$assert(
    $retryFailed
    && ($retryTask['status'] ?? null) === 'retry'
    && ($retryTask['receipt_json'] ?? null) === null
    && $effectCount('expiry_tx_retry') === 0,
    'failed hook did not roll back both its side effect and receipt before retry',
);
$upgradedManifest = json_decode(
    (string) Db::table('sm_module')->where('module_key', 'expiry_tx_retry')->value('manifest_json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$upgradedManifest['version'] = '9.9.9';
$upgradedManifest['hooks']['disable']['handler'] = 'Missing\\UpgradedHook::disable';
Db::table('sm_module')->where('module_key', 'expiry_tx_retry')->update([
    'version' => '9.9.9',
    'manifest_json' => json_encode(
        $upgradedManifest,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ),
]);
Db::table('sm_module_expiry_hook_task')->where('id', $retryTask['id'])->update([
    'next_retry_at' => '2000-01-01 00:00:00',
]);
$assert(
    $newExpiryScanner()->run()['tasks_succeeded'] === 1,
    'retry did not execute its immutable contract after the installed module changed',
);
$assert($effectCount('expiry_tx_retry') === 1, 'transactional retry duplicated or lost its side effect');

$boundaryLicense = $insertExpiryFixture('expiry_boundary', 'ENABLED', 36);
$boundaryVersion = '1.2.3+' . str_repeat('a', 58);
$boundaryClass = 'A' . str_repeat('a', 290);
$boundaryHandler = $boundaryClass . '::disable';
if (strlen($boundaryVersion) !== 64
    || strlen($boundaryHandler) !== 300
    || (!class_exists($boundaryClass, false)
        && !class_alias(IntegrationExpiryHook::class, $boundaryClass))) {
    throw new RuntimeException('Unable to create exact expiry contract boundary identities.');
}
$boundaryManifest = json_decode(
    (string) Db::table('sm_module')->where('module_key', 'expiry_boundary')->value('manifest_json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$boundaryManifest['version'] = $boundaryVersion;
$boundaryManifest['hooks']['disable']['handler'] = $boundaryHandler;
for ($index = 0; ; ++$index) {
    $boundaryManifestJson = json_encode(
        $boundaryManifest,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    if (strlen($boundaryManifestJson) >= 65000) {
        break;
    }
    $boundaryManifest['permissions'][] = [
        'slug' => 'expiry_boundary:padding_' . $index,
        'name' => 'P',
        'scope' => 'tenant',
        'description' => str_repeat('d', 400),
    ];
}
if (strlen($boundaryManifestJson) > 65535) {
    throw new RuntimeException('Boundary manifest exceeded the source TEXT capacity.');
}
(new ManifestLoader())->fromJson($boundaryManifestJson, 'expiry-boundary-module.json');
Db::table('sm_module')->where('module_key', 'expiry_boundary')->update([
    'version' => $boundaryVersion,
    'available_version' => $boundaryVersion,
    'manifest_json' => $boundaryManifestJson,
]);
$boundaryScanner = $newExpiryScanner();
$reserveOnly($boundaryScanner, $boundaryLicense);
$boundaryTask = Db::query(
    'SELECT *,OCTET_LENGTH(hook_contract_json) AS contract_bytes'
    . ' FROM sm_module_expiry_hook_task WHERE license_id=?',
    [$boundaryLicense],
)[0] ?? null;
$assert(
    is_array($boundaryTask)
    && strlen((string) ($boundaryTask['hook_module_version'] ?? '')) === 64
    && strlen((string) ($boundaryTask['hook_handler'] ?? '')) === 300
    && (int) ($boundaryTask['contract_bytes'] ?? 0) > 65535,
    'Expiry reserve truncated a legal version/handler or near-TEXT manifest envelope.',
);
IntegrationExpiryHook::$failOnce['expiry_boundary'] = true;
try {
    $boundaryScanner->run();
    throw new RuntimeException('Boundary expiry hook did not enter retry.');
} catch (RuntimeException $exception) {
    $assert(
        str_contains($exception->getMessage(), 'planned transactional expiry failure'),
        'Boundary expiry first failure returned the wrong error.',
    );
}
$boundaryTask = Db::table('sm_module_expiry_hook_task')->where('license_id', $boundaryLicense)->find();
Db::table('sm_module')->where('module_key', 'expiry_boundary')->update([
    'version' => '9.9.9',
    'manifest_json' => json_encode(
        array_replace($boundaryManifest, [
            'version' => '9.9.9',
            'hooks' => array_replace($boundaryManifest['hooks'], [
                'disable' => array_replace($boundaryManifest['hooks']['disable'], [
                    'handler' => 'Missing\\BoundaryUpgrade::disable',
                ]),
            ]),
        ]),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ),
]);
Db::table('sm_module_expiry_hook_task')->where('id', $boundaryTask['id'])->update([
    'next_retry_at' => '2000-01-01 00:00:00',
]);
$boundaryResult = $newExpiryScanner()->run();
$assert(
    $boundaryResult['tasks_succeeded'] === 1
    && $effectCount('expiry_boundary') === 1
    && Db::table('sm_module_expiry_hook_task')->where('id', $boundaryTask['id'])->value('status')
        === 'succeeded',
    'Boundary reserve→retry did not execute the complete immutable envelope.',
);

$externalLicense = $insertExpiryFixture('expiry_external', 'ENABLED', 41, false);
$externalScanner = $newExpiryScanner();
$reserveOnly($externalScanner, $externalLicense);
$externalFailed = false;
$externalResult = null;
try {
    $externalResult = $externalScanner->run();
} catch (RuntimeException $exception) {
    $externalFailed = str_contains($exception->getMessage(), 'durable receipt');
}
$externalTask = Db::table('sm_module_expiry_hook_task')->where('license_id', $externalLicense)->find();
$assert(
    !$externalFailed
    && ($externalResult['tasks_contract_failed'] ?? 0) === 1
    && ($externalTask['hook_kind'] ?? null) === 'external'
    && ($externalTask['status'] ?? null) === 'contract_failed'
    && ($externalTask['outcome_audit_id'] ?? null) !== null
    && ($externalTask['finished_at'] ?? null) !== null
    && str_contains((string) ($externalTask['last_error'] ?? ''), 'atomic durable receipt')
    && ($externalTask['receipt_json'] ?? null) === null
    && $effectCount('expiry_external') === 0,
    'external hook without an authoritative receipt contract did not fail closed',
);

$crashLicense = $insertExpiryFixture('expiry_reclaim', 'ENABLED', 51);
$crashScanner = $newExpiryScanner();
$reserveOnly($crashScanner, $crashLicense);
$crashTask = Db::table('sm_module_expiry_hook_task')->where('license_id', $crashLicense)->find();
Db::table('sm_module_expiry_hook_task')->where('id', $crashTask['id'])->update([
    'status' => 'processing',
    'attempt_count' => 1,
    'worker_token' => str_repeat('c', 40),
    'locked_until' => '2000-01-01 00:00:00',
]);
$assert(
    $newExpiryScanner()->run()['tasks_succeeded'] === 1
    && $effectCount('expiry_reclaim') === 1,
    'expired crash lease was not reclaimed safely',
);

$leasedLicense = $insertExpiryFixture('expiry_active_lease', 'ENABLED', 61);
$leasedScanner = $newExpiryScanner();
$reserveOnly($leasedScanner, $leasedLicense);
$leasedTask = Db::table('sm_module_expiry_hook_task')->where('license_id', $leasedLicense)->find();
Db::table('sm_module_expiry_hook_task')->where('id', $leasedTask['id'])->update([
    'status' => 'processing',
    'attempt_count' => 1,
    'worker_token' => str_repeat('d', 40),
    'locked_until' => '2999-01-01 00:00:00',
]);
$assert(
    $newExpiryScanner()->run()['tasks_succeeded'] === 0
    && $effectCount('expiry_active_lease') === 0,
    'a second scanner reclaimed an unexpired processing lease',
);

$oldTokenLicense = $insertExpiryFixture('expiry_old_token', 'ENABLED', 71);
$oldTokenScanner = $newExpiryScanner();
$reserveOnly($oldTokenScanner, $oldTokenLicense);
$reflection = new ReflectionClass($oldTokenScanner);
$oldClaim = $reflection->getMethod('claimTask')->invoke($oldTokenScanner);
if (!is_array($oldClaim)) {
    throw new RuntimeException('old token fixture was not claimed');
}
Db::table('sm_module_expiry_hook_task')->where('id', $oldClaim['id'])->update([
    'locked_until' => '2000-01-01 00:00:00',
]);
$newClaim = $reflection->getMethod('claimTask')->invoke($oldTokenScanner);
$staleRetryRejected = false;
$staleExecuteRejected = false;
try {
    $reflection->getMethod('executeClaimedTask')->invoke($oldTokenScanner, $oldClaim);
} catch (RuntimeException) {
    $staleExecuteRejected = true;
}
try {
    $reflection->getMethod('retryClaimedTask')->invoke(
        $oldTokenScanner,
        $oldClaim,
        new RuntimeException('stale retry'),
    );
} catch (RuntimeException) {
    $staleRetryRejected = true;
}
$assert(
    is_array($newClaim)
    && $newClaim['id'] === $oldClaim['id']
    && $newClaim['worker_token'] !== $oldClaim['worker_token']
    && $staleExecuteRejected
    && $staleRetryRejected,
    'reclaim did not rotate the token or stale token could still execute/retry the new claim',
);
$reflection->getMethod('executeClaimedTask')->invoke($oldTokenScanner, $newClaim);

$leaseDuringHookLicense = $insertExpiryFixture('expiry_lease_during_hook', 'ENABLED', 81);
$leaseDuringHookScanner = $newExpiryScanner();
$reserveOnly($leaseDuringHookScanner, $leaseDuringHookLicense);
IntegrationExpiryHook::$expireLeaseDuringHook['expiry_lease_during_hook'] = true;
$leaseDuringHookResult = $leaseDuringHookScanner->run();
$leaseDuringHookTask = Db::table('sm_module_expiry_hook_task')
    ->where('license_id', $leaseDuringHookLicense)
    ->find();
$assert(
    $leaseDuringHookResult['tasks_succeeded'] === 1
    && ($leaseDuringHookTask['status'] ?? null) === 'succeeded'
    && ($leaseDuringHookTask['receipt_json'] ?? null) !== null
    && $effectCount('expiry_lease_during_hook') === 1,
    'lease expiry during the locked effect transaction broke atomic terminal completion',
);

$dualALicense = $insertExpiryFixture('expiry_dual_a', 'ENABLED', 91);
$dualBLicense = $insertExpiryFixture('expiry_dual_b', 'ENABLED', 101);
$dualScannerA = $newExpiryScanner();
$dualScannerB = $newExpiryScanner();
$reserveOnly($dualScannerA, $dualALicense);
$reserveOnly($dualScannerB, $dualBLicense);
$dualClaimA = (new ReflectionClass($dualScannerA))->getMethod('claimTask')->invoke($dualScannerA);
$dualClaimB = (new ReflectionClass($dualScannerB))->getMethod('claimTask')->invoke($dualScannerB);
$assert(
    is_array($dualClaimA)
    && is_array($dualClaimB)
    && $dualClaimA['id'] !== $dualClaimB['id'],
    'two scanners claimed the same durable task',
);
(new ReflectionClass($dualScannerA))->getMethod('executeClaimedTask')->invoke($dualScannerA, $dualClaimA);
(new ReflectionClass($dualScannerB))->getMethod('executeClaimedTask')->invoke($dualScannerB, $dualClaimB);
$assert(
    $effectCount('expiry_dual_a') === 1 && $effectCount('expiry_dual_b') === 1,
    'dual scanner claims did not commit one effect per exact task credential',
);

$manager->grantLicense(1, 'announcement', null, '到期后重新授权', $actor);
$manager->enableTenant(1, 'announcement', $tenantActor);

$badManifestData = $originalManifest->toArray();
$installedVersionParts = array_map('intval', explode('.', $originalManifest->version()));
if (count($installedVersionParts) !== 3) {
    throw new RuntimeException('announcement 测试 manifest 版本必须是三段式 SemVer。');
}
$badManifestData['version'] = sprintf(
    '%d.%d.%d',
    $installedVersionParts[0],
    $installedVersionParts[1],
    $installedVersionParts[2] + 1,
);
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
        $assert(
            str_contains($exception->getMessage(), 'saimulti:config:index'),
            '模块菜单权限碰撞未给出明确错误：' . $exception->getMessage(),
        );
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

// Recover explicitly from FAILED using the installed package; there is
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
$assert(
    !in_array(
        'announcement',
        array_column($manager->availableForTenant(1)['items'], 'module_key'),
        true,
    ),
    '已撤销授权模块仍泄露到租户模块列表',
);
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
