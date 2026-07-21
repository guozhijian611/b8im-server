<?php

declare(strict_types=1);

[$script, $mode, $database, $moduleRoot, $taskId, $argument] = array_pad($argv, 6, '');
if (!str_ends_with($database, '_search_module_migration_test')) {
    throw new RuntimeException('Expiry concurrency worker requires an isolated search module database.');
}
foreach (['DB_NAME' => $database, 'APP_DEBUG' => 'true'] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/support/bootstrap.php';

use plugin\saimulti\service\module\DistributedLockInterface;
use plugin\saimulti\service\module\ManifestCatalog;
use plugin\saimulti\service\module\ModuleAccessCacheInterface;
use plugin\saimulti\service\module\ModuleAccessService;
use plugin\saimulti\service\module\ModuleAuditWriter;
use plugin\saimulti\service\module\ModuleAuthCacheInvalidator;
use plugin\saimulti\service\module\ModuleConfigValidator;
use plugin\saimulti\service\module\ModuleDependencyGuard;
use plugin\saimulti\service\module\ModuleLicenseExpiryScanner;
use plugin\saimulti\service\module\ModuleLifecycleHookRunner;
use plugin\saimulti\service\module\ModuleManager;
use plugin\saimulti\service\module\ModuleMenuRegistrar;
use plugin\saimulti\service\module\ModuleMigrationRunner;
use plugin\saimulti\service\module\SearchLifecycleContextOptionsEnricher;
use plugin\saimulti\service\module\SearchLifecycleFence;
use plugin\saimulti\service\module\ThinkOrmModuleAccessStore;
use support\think\Db;

$config = config('think-orm');
$connectionName = (string) ($config['default'] ?? 'mysql');
$config['connections'][$connectionName]['database'] = $database;
Db::setConfig($config);
if ((string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '') !== $database) {
    throw new RuntimeException('Expiry worker database binding failed.');
}

final class ExpiryWorkerLock implements DistributedLockInterface
{
    /** @var array<string,string> */
    private array $held = [];

    public function acquire(string $key, string $token, int $ttlSeconds): bool
    {
        if (isset($this->held[$key])) {
            return false;
        }
        $this->held[$key] = $token;
        return true;
    }

    public function release(string $key, string $token): void
    {
        if (($this->held[$key] ?? null) === $token) {
            unset($this->held[$key]);
        }
    }
}

$cache = new class implements ModuleAccessCacheInterface {
    public function get(string $key): ?array
    {
        return null;
    }

    public function set(string $key, array $value): void
    {
    }

    public function delete(string $key): void
    {
    }
};
$access = new ModuleAccessService(new ThinkOrmModuleAccessStore(), $cache);
$runner = new ModuleLifecycleHookRunner(
    optionsEnricher: new SearchLifecycleContextOptionsEnricher(new SearchLifecycleFence()),
);
$scanner = new ModuleLicenseExpiryScanner(
    new ExpiryWorkerLock(),
    $access,
    new ModuleAuditWriter(),
    $runner,
    authCacheInvalidator: new ModuleAuthCacheInvalidator(
        static function (): void {},
        static function (?int $organization): void {},
    ),
);

try {
    if ($mode === 'claim') {
        $task = (new ReflectionClass($scanner))->getMethod('claimTask')->invoke($scanner);
        fwrite(STDOUT, json_encode($task === null ? null : [
            'id' => (string) $task['id'],
            'worker_token' => (string) $task['worker_token'],
        ], JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }

    if ($mode === 'execute') {
        $rows = Db::query(
            "SELECT * FROM sm_module_expiry_hook_task WHERE id=? AND status='processing' AND worker_token=?",
            [$taskId, $argument],
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('claimed task token is unavailable');
        }
        $outcome = (new ReflectionClass($scanner))
            ->getMethod('executeClaimedTask')
            ->invoke($scanner, $rows[0]);
        fwrite(STDOUT, json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }

    if ($mode === 'renew') {
        if ($argument !== '') {
            file_put_contents($argument, 'ready');
        }
        $manager = new ModuleManager(
            new ManifestCatalog([$moduleRoot]),
            new ModuleMigrationRunner(),
            $runner,
            new ModuleMenuRegistrar(),
            new ModuleDependencyGuard(),
            $access,
            new ModuleAuditWriter(),
            new ModuleConfigValidator(),
            new ExpiryWorkerLock(),
            authCacheInvalidator: new ModuleAuthCacheInvalidator(
                static function (): void {},
                static function (?int $organization): void {},
            ),
        );
        $result = $manager->grantLicense(
            1,
            'search',
            date('Y-m-d H:i:s', time() + 86400),
            'concurrency renewal',
            ['type' => 'admin', 'id' => 1],
        );
        fwrite(STDOUT, json_encode([
            'status' => $result['status'] ?? null,
            'version' => $result['version'] ?? null,
        ], JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }

    throw new RuntimeException('Unknown expiry worker mode.');
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
