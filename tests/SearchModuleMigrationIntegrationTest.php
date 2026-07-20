<?php

declare(strict_types=1);

$database = trim((string) getenv('SEARCH_MODULE_MIGRATION_TEST_DB_NAME'));
if (preg_match('/^nb8im_[a-f0-9]{8,24}_search_module_migration_test$/D', $database) !== 1) {
    throw new RuntimeException(
        'Search module migration integration requires a random *_search_module_migration_test database.',
    );
}
foreach ([
    'DB_NAME' => $database,
    'PHINX_DB_NAME' => $database,
    'APP_DEBUG' => 'true',
] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

use Phinx\Config\Config;
use Phinx\Migration\Manager as PhinxManager;
use plugin\saimulti\service\module\DistributedLockInterface;
use plugin\saimulti\service\module\ManifestCatalog;
use plugin\saimulti\service\module\ModuleAccessCacheInterface;
use plugin\saimulti\service\module\ModuleAccessService;
use plugin\saimulti\service\module\ModuleAuditWriter;
use plugin\saimulti\service\module\ModuleAuthCacheInvalidator;
use plugin\saimulti\service\module\ModuleConfigValidator;
use plugin\saimulti\service\module\ModuleDependencyGuard;
use plugin\saimulti\service\module\ModuleLifecycleHookRunner;
use plugin\saimulti\service\module\ModuleManager;
use plugin\saimulti\service\module\ModuleMenuRegistrar;
use plugin\saimulti\service\module\ModuleMigrationRunner;
use plugin\saimulti\service\module\ThinkOrmModuleAccessStore;
use support\think\Db;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// webman bootstrap may load a local .env; the isolated test database remains authoritative.
foreach (['DB_NAME', 'PHINX_DB_NAME'] as $key) {
    putenv($key . '=' . $database);
    $_ENV[$key] = $database;
    $_SERVER[$key] = $database;
}

$configuredRoot = trim((string) getenv('B8IM_SEARCH_MODULE_ROOT'));
$moduleRoot = null;
foreach (array_values(array_filter([
    $configuredRoot,
    dirname(__DIR__, 2) . '/b8im-module-search',
])) as $candidate) {
    $real = realpath($candidate);
    if ($real !== false
        && is_file($real . '/module.json')
        && is_file($real . '/server/database/migrations/20260716070000_create_search_tables.php')
        && is_file($real . '/server/database/migrations/20260720193000_require_search_sender_organization.php')) {
        $moduleRoot = $real;
        break;
    }
}
if ($moduleRoot === null) {
    throw new RuntimeException(
        'Search module v0.3 source was not found; set B8IM_SEARCH_MODULE_ROOT or provide the CI sibling checkout.',
    );
}

$thinkConfig = config('think-orm');
$connectionName = (string) ($thinkConfig['default'] ?? 'mysql');
$connection = $thinkConfig['connections'][$connectionName] ?? null;
if (!is_array($connection)) {
    throw new RuntimeException('ThinkORM MySQL connection is unavailable.');
}
$adminDsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    (string) $connection['hostname'],
    (int) $connection['hostport'],
    (string) $connection['charset'],
);
$admin = new PDO($adminDsn, (string) $connection['username'], (string) $connection['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$quotedDatabase = '`' . $database . '`';
$temporaryModuleRoot = sys_get_temp_dir() . '/b8im_search_lifecycle_' . bin2hex(random_bytes(8));
register_shutdown_function(static function () use ($admin, $quotedDatabase, $temporaryModuleRoot): void {
    try {
        $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    } catch (Throwable) {
    }
    $iterator = is_dir($temporaryModuleRoot)
        ? new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporaryModuleRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        )
        : null;
    if ($iterator !== null) {
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($temporaryModuleRoot);
    }
});
$admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
$admin->exec('CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo = new PDO(
    str_replace(';charset=', ';dbname=' . $database . ';charset=', $adminDsn),
    (string) $connection['username'],
    (string) $connection['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ],
);
$snapshot = file_get_contents(dirname(__DIR__) . '/db/saimulti.sql');
if (!is_string($snapshot) || $snapshot === '') {
    throw new RuntimeException('Server schema snapshot is unavailable.');
}
$pdo->exec($snapshot);

$configPath = dirname(__DIR__) . '/phinx.php';
$input = new ArrayInput([]);
$input->setInteractive(false);
(new PhinxManager(
    new Config(require $configPath, $configPath),
    $input,
    new BufferedOutput(),
))->migrate('default');

$thinkConfig['connections'][$connectionName]['database'] = $database;
Db::setConfig($thinkConfig);
if ((string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '') !== $database) {
    throw new RuntimeException('ThinkORM did not bind to the isolated search lifecycle database.');
}

final class SearchLifecycleArrayCache implements ModuleAccessCacheInterface
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

final class SearchLifecycleLock implements DistributedLockInterface
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
    ++$assertions;
};
$tableExists = static function (string $table) use ($pdo, $database): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
    );
    $statement->execute([$database, $table]);
    return (int) $statement->fetchColumn() === 1;
};
$columnExists = static function (string $column) use ($pdo, $database): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=? AND TABLE_NAME="sm_search_doc" AND COLUMN_NAME=?',
    );
    $statement->execute([$database, $column]);
    return (int) $statement->fetchColumn() === 1;
};
$jobColumns = static function () use ($pdo, $database): array {
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'sm_search_job\'
       ORDER BY ORDINAL_POSITION',
    );
    $statement->execute([$database]);
    $columns = [];
    foreach ($statement->fetchAll() as $column) {
        $columns[(string) $column['COLUMN_NAME']] = $column;
    }
    return $columns;
};
$assertV3JobSchema = static function (string $context) use (
    $pdo,
    $database,
    $jobColumns,
    $assert,
): void {
    $columns = $jobColumns();
    $expected = [
        'cursor_global_seq' => ['bigint unsigned', 'NO', '0'],
        'high_water_global_seq' => ['bigint unsigned', 'NO', null],
        'worker_id' => ['varchar(64)', 'YES', null],
        'claim_token' => ['char(40)', 'YES', null],
        'locked_until' => ['datetime', 'YES', null],
        'retry_count' => ['int unsigned', 'NO', '0'],
        'next_retry_at' => ['datetime', 'YES', null],
    ];
    foreach ($expected as $name => [$type, $nullable, $default]) {
        $column = $columns[$name] ?? null;
        $defaultMatches = $default === null
            ? ($column['COLUMN_DEFAULT'] ?? null) === null
            : (string) ($column['COLUMN_DEFAULT'] ?? '') === $default;
        $assert(
            is_array($column)
            && strtolower((string) $column['COLUMN_TYPE']) === $type
            && (string) $column['IS_NULLABLE'] === $nullable
            && $defaultMatches,
            $context . ' has an invalid sm_search_job.' . $name . ' contract: '
                . json_encode($column, JSON_UNESCAPED_SLASHES),
        );
    }
    $pending = $pdo->query(
        'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=' . $pdo->quote($database) . '
            AND TABLE_NAME=\'sm_search_job\' AND INDEX_NAME=\'idx_pending\'',
    )->fetchColumn();
    $assert(
        $pending === 'status,next_retry_at,locked_until,id',
        $context . ' has an invalid idx_pending order.',
    );
    $checkStatement = $pdo->prepare(
        'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME=\'chk_search_job_cursor_high_water\'',
    );
    $checkStatement->execute([$database]);
    $check = strtolower((string) $checkStatement->fetchColumn());
    $normalizedCheck = preg_replace('/[[:space:]\x60()]+/', '', $check);
    $assert(
        $normalizedCheck === 'cursor_global_seq<=high_water_global_seq',
        $context . ' has an invalid cursor/high-water CHECK.',
    );
};
$assertV2JobSchema = static function (string $context) use ($pdo, $database, $jobColumns, $assert): void {
    $columns = $jobColumns();
    $assert(
        array_keys($columns) === [
            'id',
            'organization',
            'job_type',
            'status',
            'processed',
            'total',
            'error_message',
            'created_by',
            'updated_by',
            'started_at',
            'finished_at',
            'create_time',
            'update_time',
        ],
        $context . ' did not restore the exact v0.2 job columns.',
    );
    $expected = [
        'id' => ['bigint unsigned', 'NO', null],
        'organization' => ['int unsigned', 'NO', null],
        'job_type' => ['varchar(32)', 'NO', 'rebuild'],
        'status' => ['varchar(20)', 'NO', 'pending'],
        'processed' => ['bigint unsigned', 'NO', '0'],
        'total' => ['bigint unsigned', 'NO', '0'],
        'error_message' => ['varchar(500)', 'NO', ''],
        'created_by' => ['int unsigned', 'YES', null],
        'updated_by' => ['int unsigned', 'YES', null],
        'started_at' => ['datetime', 'YES', null],
        'finished_at' => ['datetime', 'YES', null],
        'create_time' => ['datetime', 'YES', null],
        'update_time' => ['datetime', 'YES', null],
    ];
    foreach ($expected as $name => [$type, $nullable, $default]) {
        $column = $columns[$name] ?? [];
        $defaultMatches = $default === null
            ? ($column['COLUMN_DEFAULT'] ?? null) === null
            : (string) ($column['COLUMN_DEFAULT'] ?? '') === $default;
        $assert(
            strtolower((string) ($column['COLUMN_TYPE'] ?? '')) === $type
            && (string) ($column['IS_NULLABLE'] ?? '') === $nullable
            && $defaultMatches,
            $context . ' has an invalid historical job column ' . $name . '.',
        );
    }
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'sm_search_job\' AND INDEX_NAME=\'idx_pending\'',
    );
    $statement->execute([$database]);
    $checkStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=\'sm_search_job\'
            AND CONSTRAINT_NAME=\'chk_search_job_cursor_high_water\'',
    );
    $checkStatement->execute([$database]);
    $baseIndex = $pdo->prepare(
        'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'sm_search_job\'
            AND INDEX_NAME=\'idx_org_status\'',
    );
    $baseIndex->execute([$database]);
    $assert(
        (int) $statement->fetchColumn() === 0
        && (int) $checkStatement->fetchColumn() === 0,
        $context . ' retained v0.3 job index or CHECK state.',
    );
    $assert(
        $baseIndex->fetchColumn() === 'organization,status,id',
        $context . ' did not restore the exact v0.2 job index.',
    );
};
$phinxVersions = static function () use ($pdo): array {
    return array_map(
        'intval',
        $pdo->query('SELECT version FROM phinxlog_module_search ORDER BY version')->fetchAll(PDO::FETCH_COLUMN),
    );
};
$expectInvalidSender = static function (string $senderUserId, string $label) use ($pdo, $assert): void {
    $statement = $pdo->prepare(
        'INSERT INTO sm_search_doc
         (organization,message_id,conversation_id,sender_organization,sender_user_id,
          message_type,message_seq,content,visibility)
         VALUES (101,?,"constraint-conversation",202,?,1,1,"x",1)',
    );
    try {
        $statement->execute(['invalid-' . bin2hex(random_bytes(5)), $senderUserId]);
    } catch (PDOException) {
        $assert(true, $label);
        return;
    }
    throw new RuntimeException($label . ' did not reject the invalid sender identity.');
};

$actualManifest = json_decode(
    (string) file_get_contents($moduleRoot . '/module.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
if (($actualManifest['version'] ?? null) !== '0.3.0') {
    throw new RuntimeException('Search lifecycle integration requires the v0.3.0 module package.');
}
$oldManifest = $actualManifest;
$oldManifest['version'] = '0.2.0';
$oldManifest['migrations'] = array_values(array_filter(
    $actualManifest['migrations'] ?? [],
    static fn (array $migration): bool => ($migration['id'] ?? '') === '20260716070000_create_search_tables',
));
if (count($oldManifest['migrations']) !== 1) {
    throw new RuntimeException('Unable to construct the historical search v0.2 migration package.');
}
$migrationDirectory = $temporaryModuleRoot . '/server/database/migrations';
if (!mkdir($migrationDirectory, 0700, true) && !is_dir($migrationDirectory)) {
    throw new RuntimeException('Unable to create the historical search module fixture.');
}
$oldMigrationPath = $migrationDirectory . '/20260716070000_create_search_tables.php';
if (!copy(
    $moduleRoot . '/server/database/migrations/20260716070000_create_search_tables.php',
    $oldMigrationPath,
)) {
    throw new RuntimeException('Unable to copy the historical search migration fixture.');
}
$upgradeMigrationPath = $migrationDirectory . '/20260720193000_require_search_sender_organization.php';
if (!copy(
    $moduleRoot . '/server/database/migrations/20260720193000_require_search_sender_organization.php',
    $upgradeMigrationPath,
)) {
    throw new RuntimeException('Unable to copy the current search migration fixture.');
}
$temporaryManifestPath = $temporaryModuleRoot . '/module.json';
file_put_contents(
    $temporaryManifestPath,
    json_encode($actualManifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
);

$access = new ModuleAccessService(new ThinkOrmModuleAccessStore(), new SearchLifecycleArrayCache());
$authCaches = new ModuleAuthCacheInvalidator(static function (): void {}, static function (?int $org): void {});
$managerFor = static fn (string $root): ModuleManager => new ModuleManager(
    new ManifestCatalog([$root]),
    new ModuleMigrationRunner(),
    new ModuleLifecycleHookRunner(),
    new ModuleMenuRegistrar(),
    new ModuleDependencyGuard(),
    $access,
    new ModuleAuditWriter(),
    new ModuleConfigValidator(),
    new SearchLifecycleLock(),
    authCacheInvalidator: $authCaches,
);
$rollbackSearchToV2 = static function () use ($configPath, $migrationDirectory): void {
    $configValues = require $configPath;
    $configValues['paths']['migrations'] = [$migrationDirectory];
    $environment = (string) config('plugin.saimulti.module.migration_environment', 'default');
    $configValues['environments'][$environment]['migration_table'] = 'phinxlog_module_search';
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    (new PhinxManager(
        new Config($configValues, $configPath),
        $input,
        new BufferedOutput(),
    ))->rollback($environment, 20260716070000, false);
};
$actor = ['type' => 'admin', 'id' => 1, 'ip' => '127.0.0.1'];

try {
    $freshManager = $managerFor($temporaryModuleRoot);
    $freshDiscovered = $freshManager->discover('search', $actor)['items'][0];
    $assert(
        $freshDiscovered['system']['status'] === 'DISCOVERED'
        && $freshDiscovered['system']['version'] === '0.3.0',
        'Fresh search v0.3 package did not enter DISCOVERED.',
    );
    $freshInstalled = $freshManager->install('search', $actor);
    $assert(
        $freshInstalled['system']['status'] === 'INSTALLED'
        && $freshInstalled['system']['version'] === '0.3.0',
        'Fresh search v0.3 package did not enter INSTALLED.',
    );
    $assert(
        $tableExists('sm_search_doc')
        && $columnExists('sender_organization')
        && $phinxVersions() === [20260716070000, 20260720193000],
        'Fresh search v0.3 install did not apply its complete schema/history.',
    );
    $assertV3JobSchema('Fresh search v0.3 install');
    $freshUninstalled = $freshManager->uninstall('search', false, $actor);
    $assert(
        $freshUninstalled['system']['status'] === 'UNINSTALLED'
        && !$tableExists('sm_search_doc')
        && $phinxVersions() === [],
        'Fresh search v0.3 fixture did not cleanly uninstall before the upgrade scenario.',
    );
    if (!unlink($upgradeMigrationPath)) {
        throw new RuntimeException('Unable to prepare the historical search v0.2 package.');
    }
    file_put_contents(
        $temporaryManifestPath,
        json_encode($oldManifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );

    $oldManager = $managerFor($temporaryModuleRoot);
    $discovered = $oldManager->discover('search', $actor)['items'][0];
    $assert(
        $discovered['system']['status'] === 'DISCOVERED'
        && $discovered['system']['version'] === '0.2.0',
        'Search v0.2 did not enter DISCOVERED through ModuleManager.',
    );
    $installed = $oldManager->install('search', $actor);
    $assert(
        $installed['system']['status'] === 'INSTALLED'
        && $installed['system']['version'] === '0.2.0',
        'Search v0.2 did not enter INSTALLED through ModuleManager.',
    );
    $assert($tableExists('sm_search_doc'), 'Search v0.2 install did not create its data tables.');
    $assert(!$columnExists('sender_organization'), 'Search v0.2 unexpectedly had composite sender identity.');
    $assert(
        $tableExists('phinxlog_module_search')
        && $phinxVersions() === [20260716070000],
        'Search v0.2 did not use its independent Phinx log.',
    );
    $pdo->exec(
        'INSERT INTO sm_search_index
         (organization,backend,status,doc_count,last_built_at,last_error)
         VALUES (101,"mysql","ready",1,"2026-07-20 10:00:00","old error")',
    );
    $pdo->exec(
        'INSERT INTO sm_search_doc
         (organization,message_id,conversation_id,sender_user_id,message_type,message_seq,
          content,visibility,sent_at)
         VALUES (101,"old-message","old-conversation","same",1,1,"old",1,NOW())',
    );
    $pdo->exec(
        'INSERT INTO sm_search_job
         (organization,job_type,status,processed,total,error_message)
         VALUES (101,"rebuild","success",1,1,"")',
    );

    if (!copy(
        $moduleRoot . '/server/database/migrations/20260720193000_require_search_sender_organization.php',
        $upgradeMigrationPath,
    )) {
        throw new RuntimeException('Unable to copy the search v0.3 migration fixture.');
    }
    file_put_contents(
        $temporaryManifestPath,
        json_encode($actualManifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );
    $currentManager = $managerFor($temporaryModuleRoot);
    $upgraded = $currentManager->upgrade('search', $actor);
    $assert(
        $upgraded['system']['status'] === 'INSTALLED'
        && $upgraded['system']['version'] === '0.3.0',
        'Search v0.2→v0.3 did not complete through ModuleManager.',
    );
    $assert($columnExists('sender_organization'), 'Search v0.3 upgrade did not add sender_organization.');
    $assert(
        $phinxVersions() === [20260716070000, 20260720193000],
        'Search v0.3 upgrade did not update the independent Phinx log.',
    );
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sm_search_doc')->fetchColumn() === 0, 'Upgrade retained old docs.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sm_search_job')->fetchColumn() === 0, 'Upgrade retained old jobs.');
    $assertV3JobSchema('Search v0.2→v0.3 upgrade');
    $index = $pdo->query(
        'SELECT status,doc_count,last_built_at,last_error FROM sm_search_index WHERE organization=101',
    )->fetch();
    $assert(
        ($index['status'] ?? '') === 'idle'
        && (int) ($index['doc_count'] ?? -1) === 0
        && ($index['last_built_at'] ?? null) === null
        && ($index['last_error'] ?? 'x') === '',
        'Search v0.3 upgrade did not reset index state.',
    );
    $indexColumns = $pdo->query(
        'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=' . $pdo->quote($database) . '
            AND TABLE_NAME="sm_search_doc" AND INDEX_NAME="idx_org_sender"',
    )->fetchColumn();
    $assert(
        $indexColumns === 'organization,sender_organization,sender_user_id,visibility,sent_at',
        'Search v0.3 compound sender index shape is invalid.',
    );
    $uint64Max = '18446744073709551615';
    $maxJob = $pdo->prepare(
        'INSERT INTO sm_search_job
            (organization,job_type,status,processed,total,cursor_global_seq,
             high_water_global_seq,error_message)
         VALUES (101,\'rebuild\',\'pending\',0,0,?,?,\'\')',
    );
    $maxJob->execute([$uint64Max, $uint64Max]);
    $maxRoundTrip = $pdo->query(
        'SELECT cursor_global_seq,high_water_global_seq
           FROM sm_search_job ORDER BY id DESC LIMIT 1',
    )->fetch();
    $assert(
        ($maxRoundTrip['cursor_global_seq'] ?? null) === $uint64Max
        && ($maxRoundTrip['high_water_global_seq'] ?? null) === $uint64Max,
        'Search job UINT64 cursor/high-water did not round-trip as decimal strings.',
    );
    try {
        $pdo->exec(
            'INSERT INTO sm_search_job
                (organization,job_type,status,cursor_global_seq,high_water_global_seq)
             VALUES (101,\'rebuild\',\'pending\',2,1)',
        );
        throw new RuntimeException('Search job cursor/high-water CHECK accepted cursor overflow.');
    } catch (PDOException) {
        $assert(true, 'Search job cursor/high-water CHECK rejected cursor overflow.');
    }
    try {
        $pdo->exec(
            'INSERT INTO sm_search_doc
             (organization,message_id,conversation_id,sender_organization,sender_user_id,
              message_type,message_seq,content,visibility)
             VALUES (101,"invalid-org","c",0,"same",1,1,"x",1)',
        );
        throw new RuntimeException('sender_organization CHECK accepted zero.');
    } catch (PDOException) {
        $assert(true, 'sender_organization CHECK rejected zero.');
    }
    foreach ([
        'leading space' => ' same',
        'trailing space' => 'same ',
        'TAB' => "bad\tidentity",
        'LF' => "bad\nidentity",
        'VT' => "bad\videntity",
        'CR' => "bad\ridentity",
        'NUL' => "bad\0identity",
        'pipe' => 'bad|identity',
    ] as $label => $invalidSender) {
        $expectInvalidSender($invalidSender, 'sender_user_id CHECK: ' . $label);
    }
    $pdo->exec(
        'INSERT INTO sm_search_doc
         (organization,message_id,conversation_id,sender_organization,sender_user_id,
          message_type,message_seq,content,visibility)
         VALUES (101,"v3-valid","c",202,"same",1,1,"x",1)',
    );
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM sm_search_doc WHERE message_id="v3-valid"')->fetchColumn() === 1,
        'Search v0.3 rejected a canonical composite sender identity.',
    );
    $auditUpgrade = $pdo->query(
        'SELECT from_version,target_version,success FROM sm_module_lifecycle_audit
          WHERE module_key="search" AND operation="upgrade" ORDER BY id DESC LIMIT 1',
    )->fetch();
    $assert(
        ($auditUpgrade['from_version'] ?? '') === '0.2.0'
        && ($auditUpgrade['target_version'] ?? '') === '0.3.0'
        && (int) ($auditUpgrade['success'] ?? 0) === 1,
        'Search upgrade lifecycle audit is incomplete.',
    );

    $pdo->exec(
        'UPDATE sm_search_index
            SET status="ready",doc_count=1,last_built_at="2026-07-20 20:00:00",
                last_error="v0.3 state"
          WHERE organization=101',
    );
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM sm_search_doc')->fetchColumn() > 0
        && (int) $pdo->query('SELECT COUNT(*) FROM sm_search_job')->fetchColumn() > 0,
        'Targeted rollback fixture did not contain destructive v0.3 state.',
    );
    $rollbackSearchToV2();
    $assert(
        $phinxVersions() === [20260716070000]
        && !$columnExists('sender_organization')
        && !$columnExists('source_change_seq'),
        'Targeted v0.3 rollback did not retain exactly the historical migration.',
    );
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM sm_search_doc')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM sm_search_job')->fetchColumn() === 0,
        'Targeted v0.3 rollback retained incompatible documents or jobs.',
    );
    $rolledBackIndex = $pdo->query(
        'SELECT status,doc_count,last_built_at,last_error
           FROM sm_search_index WHERE organization=101',
    )->fetch();
    $assert(
        ($rolledBackIndex['status'] ?? '') === 'idle'
        && (int) ($rolledBackIndex['doc_count'] ?? -1) === 0
        && ($rolledBackIndex['last_built_at'] ?? null) === null
        && ($rolledBackIndex['last_error'] ?? 'x') === '',
        'Targeted v0.3 rollback did not reset the search index state.',
    );
    $senderColumn = $pdo->query(
        'SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=' . $pdo->quote($database) . '
            AND TABLE_NAME="sm_search_doc" AND COLUMN_NAME="sender_user_id"',
    )->fetch();
    $assert(
        strtolower((string) ($senderColumn['COLUMN_TYPE'] ?? '')) === 'varchar(64)'
        && ($senderColumn['IS_NULLABLE'] ?? '') === 'NO'
        && ($senderColumn['COLUMN_DEFAULT'] ?? null) === '',
        'Targeted v0.3 rollback did not restore the exact sender_user_id default.',
    );
    $removedDocState = $pdo->query(
        'SELECT
            (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=' . $pdo->quote($database) . '
                AND TABLE_NAME="sm_search_doc" AND INDEX_NAME="idx_org_sender") AS sender_index,
            (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA=' . $pdo->quote($database) . '
                AND TABLE_NAME="sm_search_doc"
                AND CONSTRAINT_NAME IN (
                    "chk_search_doc_sender_organization",
                    "chk_search_doc_sender_user_id"
                )) AS sender_constraints',
    )->fetch();
    $assert(
        (int) ($removedDocState['sender_index'] ?? -1) === 0
        && (int) ($removedDocState['sender_constraints'] ?? -1) === 0,
        'Targeted v0.3 rollback retained sender indexes or constraints.',
    );
    $assertV2JobSchema('Targeted search v0.3 rollback');

    $uninstalled = $currentManager->uninstall('search', false, $actor);
    $assert($uninstalled['system']['status'] === 'UNINSTALLED', 'Search did not enter UNINSTALLED.');
    $assert(
        !$tableExists('sm_search_doc')
        && !$tableExists('sm_search_job')
        && !$tableExists('sm_search_index'),
        'Search uninstall(preserve_data=false) retained module data tables.',
    );
    $assert(
        $tableExists('phinxlog_module_search') && $phinxVersions() === [],
        'Search uninstall did not roll back its independent Phinx history.',
    );
    $auditUninstall = $pdo->query(
        'SELECT context_json,success FROM sm_module_lifecycle_audit
          WHERE module_key="search" AND operation="uninstall" ORDER BY id DESC LIMIT 1',
    )->fetch();
    $uninstallContext = json_decode((string) ($auditUninstall['context_json'] ?? ''), true);
    $assert(
        (int) ($auditUninstall['success'] ?? 0) === 1
        && is_array($uninstallContext)
        && ($uninstallContext['preserve_data'] ?? null) === false,
        'Search destructive uninstall audit did not record preserve_data=false.',
    );

    $rediscovered = $currentManager->discover('search', $actor)['items'][0];
    $assert(
        $rediscovered['system']['status'] === 'DISCOVERED'
        && $rediscovered['system']['version'] === '0.3.0',
        'Search v0.3 reinstall did not rediscover the current package.',
    );
    $reinstalled = $currentManager->install('search', $actor);
    $assert(
        $reinstalled['system']['status'] === 'INSTALLED'
        && $reinstalled['system']['version'] === '0.3.0',
        'Search v0.3 fresh reinstall did not enter INSTALLED.',
    );
    $assert(
        $tableExists('sm_search_doc')
        && $columnExists('sender_organization')
        && $phinxVersions() === [20260716070000, 20260720193000],
        'Search v0.3 fresh reinstall did not restore the complete schema/history.',
    );
    $assertV3JobSchema('Search v0.3 reinstall');
    $assert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM sm_module_lifecycle_audit
              WHERE module_key="search" AND operation="install" AND success=1',
        )->fetchColumn() === 3,
        'Search fresh install/historical install/reinstall lifecycle audits are incomplete.',
    );

    fwrite(STDOUT, sprintf(
        "SearchModuleMigrationIntegrationTest: %d assertions passed\n",
        $assertions,
    ));
} finally {
    $pdo = null;
    $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
}
