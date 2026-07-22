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
    'SEARCH_CONSUMER_ENABLED' => 'true',
    'SEARCH_REBUILD_ENABLED' => 'true',
    'DEPLOYMENT_ID' => 'search-migration-' . substr(hash('sha256', $database), 0, 16),
    'B8IM_SYSTEM_VERSION' => '0.2.0',
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
use plugin\saimulti\service\module\ModuleLicenseExpiryScanner;
use plugin\saimulti\service\module\ModuleManager;
use plugin\saimulti\service\module\ModuleMenuRegistrar;
use plugin\saimulti\service\module\ModuleMigrationRunner;
use plugin\saimulti\service\module\SearchLifecycleContextOptionsEnricher;
use plugin\saimulti\service\module\SearchLifecycleFence;
use plugin\saimulti\service\module\ThinkOrmModuleAccessStore;
use plugin\saimulti\service\searchConsumer\SearchConsumerConfig;
use plugin\saimulti\service\searchConsumer\SearchConsumerTopology;
use B8im\Module\Search\Rebuild\Config as SearchRebuildConfig;
use B8im\Module\Search\Rebuild\WorkerHeartbeat;
use support\think\Db;
use support\think\Cache;
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
        && is_file($real . '/server/database/migrations/20260720193000_require_search_sender_organization.php')
        && is_file($real . '/server/database/migrations/20260721130000_require_search_conversation_type.php')) {
        $moduleRoot = $real;
        break;
    }
}
if ($moduleRoot === null) {
    throw new RuntimeException(
        'Search module v0.4 source was not found; set B8IM_SEARCH_MODULE_ROOT or provide the CI sibling checkout.',
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
$sessionSqlModes = array_values(array_filter(array_map(
    static fn (string $mode): string => strtoupper(trim($mode)),
    explode(',', (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()),
)));
if (array_intersect(['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES'], $sessionSqlModes) === []) {
    $sessionSqlModes[] = 'STRICT_TRANS_TABLES';
    $pdo->exec('SET SESSION sql_mode=' . $pdo->quote(implode(',', $sessionSqlModes)));
}
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
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS im_organization_message_sequence (
 organization int unsigned PRIMARY KEY,
 next_global_seq bigint unsigned NOT NULL DEFAULT 1,
 last_search_event_seq bigint unsigned NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS im_message_outbox (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 organization int unsigned NOT NULL,
 source_event_seq bigint unsigned NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS im_conversation (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 organization int unsigned NOT NULL,
 conversation_id varchar(64) NOT NULL,
 conversation_type tinyint unsigned NOT NULL,
 status tinyint unsigned NOT NULL,
 UNIQUE KEY uni_organization_conversation (organization,conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS im_message_index (
 id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 organization int unsigned NOT NULL,
 global_seq bigint unsigned NOT NULL,
 message_id varchar(40) NOT NULL,
 conversation_id varchar(64) NOT NULL,
 message_seq bigint unsigned NOT NULL,
 sender_id varchar(64) NOT NULL,
 sender_organization int unsigned NOT NULL,
 UNIQUE KEY uni_organization_message (organization,message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

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
        'source_event_cut' => ['bigint unsigned', 'NO', null],
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
            AND TABLE_NAME=\'sm_search_job\' AND INDEX_NAME=\'idx_pending_due\'',
    )->fetchColumn();
    $running = $pdo->query(
        'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=' . $pdo->quote($database) . '
            AND TABLE_NAME=\'sm_search_job\' AND INDEX_NAME=\'idx_running_lease\'',
    )->fetchColumn();
    $assert(
        $pending === 'status,next_retry_at,id'
        && $running === 'status,locked_until,id',
        $context . ' has invalid pending/running claim indexes.',
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
$assertV4DocSchema = static function (string $context) use ($pdo, $database, $assert): void {
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_COMMENT'
        . ' FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'sm_search_doc\' ORDER BY ORDINAL_POSITION',
    );
    $statement->execute([$database]);
    $columns = $statement->fetchAll();
    $names = array_column($columns, 'COLUMN_NAME');
    $position = array_search('conversation_type', $names, true);
    $column = $position === false ? null : $columns[$position];
    $check = $pdo->prepare(
        'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS'
        . ' WHERE CONSTRAINT_SCHEMA=?'
        . ' AND CONSTRAINT_NAME=\'chk_search_doc_conversation_type\'',
    );
    $check->execute([$database]);
    $normalizedCheck = strtolower((string) preg_replace(
        '/[[:space:]\x60()]+/',
        '',
        (string) $check->fetchColumn(),
    ));
    $index = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'sm_search_doc\''
        . ' AND COLUMN_NAME=\'conversation_type\'',
    );
    $index->execute([$database]);
    $assert(
        is_array($column)
        && $position === array_search('conversation_id', $names, true) + 1
        && strtolower((string) $column['COLUMN_TYPE']) === 'tinyint unsigned'
        && (string) $column['IS_NULLABLE'] === 'NO'
        && ($column['COLUMN_DEFAULT'] ?? null) === null
        && (string) $column['COLUMN_COMMENT'] === '1单聊,2群聊'
        && $normalizedCheck === 'conversation_typein1,2'
        && (int) $index->fetchColumn() === 0,
        $context . ' has an invalid strict sm_search_doc.conversation_type contract.',
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
         (organization,message_id,conversation_id,conversation_type,'
        . 'sender_organization,sender_user_id,
          message_type,message_seq,content,visibility)
         VALUES (101,?,"constraint-conversation",2,202,?,1,1,"x",1)',
    );
    try {
        $statement->execute(['invalid-' . bin2hex(random_bytes(5)), $senderUserId]);
    } catch (PDOException) {
        $assert(true, $label);
        return;
    }
    throw new RuntimeException($label . ' did not reject the invalid sender identity.');
};

$expiryTaskColumns = $pdo->query(
    'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT'
    . ' FROM information_schema.COLUMNS'
    . ' WHERE TABLE_SCHEMA=' . $pdo->quote($database)
    . ' AND TABLE_NAME="sm_module_expiry_hook_task" ORDER BY ORDINAL_POSITION',
)->fetchAll();
$expiryUnique = $pdo->query(
    'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)'
    . ' FROM information_schema.STATISTICS'
    . ' WHERE TABLE_SCHEMA=' . $pdo->quote($database)
    . ' AND TABLE_NAME="sm_module_expiry_hook_task"'
    . ' AND INDEX_NAME="uk_expiry_license_version" AND NON_UNIQUE=0',
)->fetchColumn();
$expiryIdempotencyUnique = $pdo->query(
    'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)'
    . ' FROM information_schema.STATISTICS'
    . ' WHERE TABLE_SCHEMA=' . $pdo->quote($database)
    . ' AND TABLE_NAME="sm_module_expiry_hook_task"'
    . ' AND INDEX_NAME="uk_expiry_idempotency_key" AND NON_UNIQUE=0',
)->fetchColumn();
$expiryColumnNames = array_column($expiryTaskColumns, 'COLUMN_NAME');
$expiryColumnsByName = array_column($expiryTaskColumns, null, 'COLUMN_NAME');
$assert(
    $tableExists('sm_module_expiry_hook_task')
    && $expiryUnique === 'license_id,expired_version'
    && $expiryIdempotencyUnique === 'idempotency_key'
    && array_diff([
        'hook_kind',
        'hook_module_version',
        'hook_handler',
        'hook_scope',
        'hook_transactional',
        'hook_contract_json',
        'idempotency_key',
        'request_digest',
        'status',
        'attempt_count',
        'worker_token',
        'locked_until',
        'next_retry_at',
        'last_error',
        'receipt_json',
        'receipt_recorded_at',
        'outcome_audit_id',
        'finished_at',
    ], $expiryColumnNames) === []
    && strtolower((string) ($expiryColumnsByName['hook_module_version']['COLUMN_TYPE'] ?? ''))
        === 'varchar(64)'
    && strtolower((string) ($expiryColumnsByName['hook_handler']['COLUMN_TYPE'] ?? ''))
        === 'varchar(300)'
    && strtolower((string) ($expiryColumnsByName['hook_contract_json']['COLUMN_TYPE'] ?? ''))
        === 'mediumtext',
    'Server migration did not install the durable expiry task lease/retry/outcome contract.',
);
$serverMigrationInput = new ArrayInput([]);
$serverMigrationInput->setInteractive(false);
$serverMigrationManager = new PhinxManager(
    new Config(require $configPath, $configPath),
    $serverMigrationInput,
    new BufferedOutput(),
);
$serverMigrationManager->rollback('default', 20260720020000, false);
$assert(
    !$tableExists('sm_module_expiry_hook_task'),
    'Server migration rollback retained the durable expiry task table.',
);
$pdo->exec('CREATE TABLE sm_module_expiry_hook_task (id bigint unsigned PRIMARY KEY) ENGINE=InnoDB');
$driftRejected = false;
try {
    $serverMigrationManager->migrate('default');
} catch (Throwable $exception) {
    $driftRejected = str_contains($exception->getMessage(), 'shape drift');
}
$assert($driftRejected, 'Server migration silently accepted a drifted same-name expiry task table.');
$pdo->exec('DROP TABLE sm_module_expiry_hook_task');
$serverMigrationManager->migrate('default');
$assert(
    $tableExists('sm_module_expiry_hook_task'),
    'Server migration re-apply did not restore the durable expiry task table.',
);
$expiryKeyA = str_repeat('a', 64);
$expiryKeyB = str_repeat('b', 64);
$expiryVersion65 = '1.2.3+' . str_repeat('a', 59);
$expiryHandler301 = 'A' . str_repeat('a', 291) . '::disable';
$invalidExpiryRows = [
    "(1,1,'migration_bad_from',1,'AUTHORIZED',NOW(),'transactional','1.0.0','FixtureHook::disable','tenant',1,'{}','$expiryKeyA','$expiryKeyB',"
        . "'pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NOW(),NOW(),NULL)",
    "(2,1,'migration_bad_status',1,'ENABLED',NOW(),'transactional','1.0.0','FixtureHook::disable','tenant',1,'{}','$expiryKeyB','$expiryKeyA',"
        . "'unknown',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NOW(),NOW(),NULL)",
    "(3,1,'migration_bad_pending',1,'ENABLED',NOW(),'transactional','1.0.0','FixtureHook::disable','tenant',1,'{}','" . str_repeat('c', 64) . "','"
        . str_repeat('d', 64) . "','pending',0,'" . str_repeat('e', 40)
        . "',NULL,NULL,NULL,NULL,NULL,NULL,NOW(),NOW(),NULL)",
    "(4,1,'migration_bad_retry',1,'ENABLED',NOW(),'transactional','1.0.0','FixtureHook::disable','tenant',1,'{}','" . str_repeat('f', 64) . "','"
        . str_repeat('1', 64) . "','retry',1,NULL,NULL,NULL,'retry',NULL,NULL,NULL,NOW(),NOW(),NULL)",
    "(5,1,'migration_bad_processing',1,'ENABLED',NOW(),'transactional','1.0.0','FixtureHook::disable','tenant',1,'{}','" . str_repeat('2', 64) . "','"
        . str_repeat('3', 64) . "','processing',1,'bad',NOW(),NULL,NULL,NULL,NULL,NULL,NOW(),NOW(),NULL)",
    "(6,1,'migration_bad_success',1,'ENABLED',NOW(),'transactional','1.0.0','FixtureHook::disable','tenant',1,'{}','" . str_repeat('4', 64) . "','"
        . str_repeat('5', 64) . "','succeeded',1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NOW(),NOW(),NOW())",
    "(8,1,'migration_bad_version',1,'ENABLED',NOW(),'transactional','$expiryVersion65','FixtureHook::disable','tenant',1,'{}','"
        . str_repeat('8', 64) . "','" . str_repeat('9', 64)
        . "','pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NOW(),NOW(),NULL)",
    "(9,1,'migration_bad_handler',1,'ENABLED',NOW(),'transactional','1.0.0','$expiryHandler301','tenant',1,'{}','"
        . str_repeat('a', 63) . "8','" . str_repeat('b', 63)
        . "9','pending',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NOW(),NOW(),NULL)",
];
$originalSqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
try {
    foreach ($invalidExpiryRows as $invalidExpiryIndex => $values) {
        try {
            $pdo->exec(
                'INSERT INTO sm_module_expiry_hook_task '
                . '(license_id,organization,module_key,expired_version,from_status,expire_at,'
                . 'hook_kind,hook_module_version,hook_handler,hook_scope,hook_transactional,hook_contract_json,'
                . 'idempotency_key,request_digest,status,attempt_count,worker_token,locked_until,'
                . 'next_retry_at,last_error,receipt_json,receipt_recorded_at,outcome_audit_id,'
                . 'create_time,update_time,finished_at) '
                . 'VALUES ' . $values,
            );
            throw new RuntimeException(sprintf(
                'Expiry task CHECK accepted invalid state fixture #%d.',
                $invalidExpiryIndex,
            ));
        } catch (PDOException) {
            $assert(true, 'Expiry task CHECK rejected an invalid state row.');
        }
    }
} finally {
    $pdo->exec('SET SESSION sql_mode=' . $pdo->quote($originalSqlMode));
}
$pdo->exec(
    "INSERT INTO sm_module_expiry_hook_task"
    . " (license_id,organization,module_key,expired_version,from_status,expire_at,"
    . "hook_kind,hook_module_version,hook_handler,hook_scope,hook_transactional,hook_contract_json,"
    . "idempotency_key,request_digest,status,attempt_count,next_retry_at,last_error,create_time,update_time)"
    . " VALUES (7,1,'migration_valid_retry',1,'ENABLED',NOW(),'transactional',"
    . "'1.0.0','FixtureHook::disable','tenant',1,'{}','"
    . str_repeat('6', 64) . "','" . str_repeat('7', 64) . "','retry',1,NOW(),'retry',NOW(),NOW())",
);
$assert(
    (int) $pdo->query(
        "SELECT COUNT(*) FROM sm_module_expiry_hook_task WHERE status='retry' AND last_error='retry'",
    )->fetchColumn() === 1,
    'Expiry task CHECK rejected the valid retry state.',
);
$pdo->exec('DELETE FROM sm_module_expiry_hook_task');

$actualManifest = json_decode(
    (string) file_get_contents($moduleRoot . '/module.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
if (($actualManifest['version'] ?? null) !== '0.4.0') {
    throw new RuntimeException('Search lifecycle integration requires the v0.4.0 module package.');
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
$conversationTypeMigrationPath = $migrationDirectory
    . '/20260721130000_require_search_conversation_type.php';
if (!copy(
    $moduleRoot . '/server/database/migrations/20260721130000_require_search_conversation_type.php',
    $conversationTypeMigrationPath,
)) {
    throw new RuntimeException('Unable to copy the current Search conversation_type migration fixture.');
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
    new ModuleLifecycleHookRunner(
        optionsEnricher: new SearchLifecycleContextOptionsEnricher(new SearchLifecycleFence()),
    ),
    new ModuleMenuRegistrar(),
    new ModuleDependencyGuard(),
    $access,
    new ModuleAuditWriter(),
    new ModuleConfigValidator(),
    new SearchLifecycleLock(),
    authCacheInvalidator: $authCaches,
);
$expiryWorkerPath = __DIR__ . '/support/module_expiry_concurrency_worker.php';
$startExpiryWorker = static function (
    string $mode,
    string $root,
    string $taskId = '',
    string $argument = '',
) use ($database, $expiryWorkerPath): array {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $expiryWorkerPath, $mode, $database, $root, $taskId, $argument],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start expiry concurrency worker.');
    }
    fclose($pipes[0]);

    return [$process, $pipes];
};
$finishExpiryWorker = static function (array $worker, bool $expectSuccess = true): array {
    [$process, $pipes] = $worker;
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($expectSuccess && $exit !== 0) {
        throw new RuntimeException('Expiry worker failed: ' . trim((string) $stderr));
    }
    if (!$expectSuccess && $exit === 0) {
        throw new RuntimeException('Expiry worker unexpectedly succeeded.');
    }

    return [
        'exit' => $exit,
        'stdout' => trim((string) $stdout),
        'stderr' => trim((string) $stderr),
    ];
};
$runExpiryWorker = static function (
    string $mode,
    string $root,
    string $taskId = '',
    string $argument = '',
    bool $expectSuccess = true,
) use ($startExpiryWorker, $finishExpiryWorker): array {
    return $finishExpiryWorker(
        $startExpiryWorker($mode, $root, $taskId, $argument),
        $expectSuccess,
    );
};
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
$migrateSearchToCurrent = static function () use ($configPath, $migrationDirectory): void {
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
    ))->migrate($environment);
};
$actor = ['type' => 'admin', 'id' => 1, 'ip' => '127.0.0.1'];

try {
    $freshManager = $managerFor($temporaryModuleRoot);
    $freshDiscovered = $freshManager->discover('search', $actor)['items'][0];
    $assert(
        $freshDiscovered['system']['status'] === 'DISCOVERED'
        && $freshDiscovered['system']['version'] === '0.4.0',
        'Fresh search v0.4 package did not enter DISCOVERED.',
    );
    $freshInstalled = $freshManager->install('search', $actor);
    $assert(
        $freshInstalled['system']['status'] === 'INSTALLED'
        && $freshInstalled['system']['version'] === '0.4.0',
        'Fresh search v0.4 package did not enter INSTALLED.',
    );
    $assert(
        $tableExists('sm_search_doc')
        && $columnExists('sender_organization')
        && $phinxVersions() === [20260716070000, 20260720193000, 20260721130000],
        'Fresh search v0.4 install did not apply its complete schema/history.',
    );
    $assertV3JobSchema('Fresh search v0.4 install');
    $assertV4DocSchema('Fresh search v0.4 install');
    $freshUninstalled = $freshManager->uninstall('search', false, $actor);
    $assert(
        $freshUninstalled['system']['status'] === 'UNINSTALLED'
        && !$tableExists('sm_search_doc')
        && $phinxVersions() === [],
        'Fresh search v0.4 fixture did not cleanly uninstall before the upgrade scenario.',
    );
    if (!unlink($upgradeMigrationPath) || !unlink($conversationTypeMigrationPath)) {
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
    $assert(
        !$columnExists('sender_organization') && !$columnExists('conversation_type'),
        'Search v0.2 unexpectedly had the v0.3/v0.4 document identity.',
    );
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
        'INSERT INTO im_conversation
         (organization,conversation_id,conversation_type,status)
         VALUES (101,"old-conversation",2,1)',
    );
    $pdo->exec(
        'INSERT INTO im_message_index
         (organization,global_seq,message_id,conversation_id,message_seq,sender_id,sender_organization)
         VALUES (101,1,"old-message","old-conversation",1,"same",101)',
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
    Db::table('sm_module')->where('module_key', 'search')->update(['status' => 'ENABLED']);

    $consumerConfig = SearchConsumerConfig::fromArray((array) config('plugin.saimulti.search'));
    $rebuildConfig = SearchRebuildConfig::fromArray((array) config('plugin.saimulti.search_rebuild'));
    $redis = Cache::store('redis')->handler();
    $now = time();
    $redis->setex($consumerConfig->heartbeatRedisKey, $consumerConfig->heartbeatTtlSeconds, json_encode([
        'deployment' => $consumerConfig->deploymentId,
        'instance' => 'search-migration-consumer',
        'queue' => $consumerConfig->topology->mainQueue,
        'topology' => SearchConsumerTopology::VERSION,
        'status' => 'ready',
        'updated_at' => $now,
    ], JSON_THROW_ON_ERROR));
    $redis->setex($rebuildConfig->heartbeatKey, $rebuildConfig->heartbeatTtlSeconds, json_encode([
        'deployment' => $rebuildConfig->deploymentId,
        'worker' => 'search-migration-rebuild',
        'topology' => WorkerHeartbeat::TOPOLOGY,
        'fingerprint' => $rebuildConfig->fingerprint,
        'status' => 'ready',
        'updated_at' => $now,
    ], JSON_THROW_ON_ERROR));

    if (!copy(
        $moduleRoot . '/server/database/migrations/20260720193000_require_search_sender_organization.php',
        $upgradeMigrationPath,
    )) {
        throw new RuntimeException('Unable to copy the search v0.3 migration fixture.');
    }
    if (!copy(
        $moduleRoot . '/server/database/migrations/20260721130000_require_search_conversation_type.php',
        $conversationTypeMigrationPath,
    )) {
        throw new RuntimeException('Unable to copy the search v0.4 migration fixture.');
    }
    file_put_contents(
        $temporaryManifestPath,
        json_encode($actualManifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );
    $currentManager = $managerFor($temporaryModuleRoot);
    $upgraded = $currentManager->upgrade('search', $actor);
    $assert(
        $upgraded['system']['status'] === 'ENABLED'
        && $upgraded['system']['version'] === '0.4.0',
        'Enabled Search v0.2→v0.4 did not restore through readiness + enable.',
    );
    $assert($columnExists('sender_organization'), 'Search v0.3 upgrade did not add sender_organization.');
    $assert(
        $phinxVersions() === [20260716070000, 20260720193000, 20260721130000],
        'Search v0.4 upgrade did not update the independent Phinx log.',
    );
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sm_search_doc')->fetchColumn() === 0, 'Upgrade retained old docs.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sm_search_job')->fetchColumn() === 0, 'Upgrade retained old jobs.');
    $assertV3JobSchema('Search v0.2→v0.4 upgrade');
    $assertV4DocSchema('Search v0.2→v0.4 upgrade');
    $index = $pdo->query(
        'SELECT status,doc_count,last_built_at,last_error,rebuild_required,lifecycle_fenced'
        . ' FROM sm_search_index WHERE organization=101',
    )->fetch();
    $assert(
        ($index['status'] ?? '') === 'idle'
        && (int) ($index['doc_count'] ?? -1) === 0
        && ($index['last_built_at'] ?? null) === null
        && (int) ($index['rebuild_required'] ?? 0) === 1
        && (int) ($index['lifecycle_fenced'] ?? 1) === 0,
        'Search v0.4 post-upgrade restore did not clear only lifecycle fence.',
    );

    // Real expiry concurrency and Search atomicity regression. Every worker
    // owns an independent ThinkORM/PDO connection and transaction.
    $expiryRunner = new ModuleLifecycleHookRunner(
        optionsEnricher: new SearchLifecycleContextOptionsEnricher(new SearchLifecycleFence()),
    );
    $expiryScanner = new ModuleLicenseExpiryScanner(
        new SearchLifecycleLock(),
        $access,
        new ModuleAuditWriter(),
        $expiryRunner,
        authCacheInvalidator: $authCaches,
    );
    $reserveSearchExpiry = static function () use ($expiryScanner): array {
        Db::table('sm_tenant_module_license')
            ->where('organization', 1)
            ->where('module_key', 'search')
            ->update(['expire_at' => '2000-01-01 00:00:00']);
        $candidate = Db::table('sm_tenant_module_license')
            ->where('organization', 1)
            ->where('module_key', 'search')
            ->find();
        if (!is_array($candidate)
            || (new ReflectionClass($expiryScanner))
                ->getMethod('reserveExpiry')
                ->invoke($expiryScanner, $candidate) !== true) {
            throw new RuntimeException('Unable to reserve real Search expiry task.');
        }
        $task = Db::table('sm_module_expiry_hook_task')
            ->where('license_id', $candidate['id'])
            ->where('expired_version', (int) $candidate['version'] + 1)
            ->find();
        if (!is_array($task)) {
            throw new RuntimeException('Reserved Search expiry task is missing.');
        }

        return $task;
    };
    $prepareSearchExpiry = static function () use ($currentManager, $actor, $pdo): array {
        $currentManager->grantLicense(
            1,
            'search',
            date('Y-m-d H:i:s', time() + 86400),
            'real expiry concurrency',
            $actor,
        );
        $pdo->exec(
            "UPDATE sm_search_index SET status='error',rebuild_required=1,lifecycle_fenced=1,"
            . "last_error='Search test enable fence.',update_time=NOW() WHERE organization=1",
        );
        $currentManager->enableTenant(1, 'search', ['type' => 'tenant', 'id' => 1]);
        $pdo->exec(
            "UPDATE sm_search_index SET status='ready',rebuild_required=0,lifecycle_fenced=0,"
            . "last_error='',update_time=NOW() WHERE organization=1",
        );
        $pdo->exec('DELETE FROM sm_search_job WHERE organization=1');
        $pdo->exec(
            "INSERT INTO sm_search_job"
            . " (organization,job_type,status,processed,total,cursor_global_seq,"
            . "high_water_global_seq,source_event_cut,error_message)"
            . " VALUES (1,'rebuild','pending',0,0,0,0,0,'')",
        );

        return Db::table('sm_tenant_module_license')
            ->where('organization', 1)
            ->where('module_key', 'search')
            ->find();
    };
    $decodeWorker = static function (array $result): mixed {
        return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    };

    $prepareSearchExpiry();
    $abaTask = $reserveSearchExpiry();
    $firstClaim = $decodeWorker($runExpiryWorker(
        'claim',
        $temporaryModuleRoot,
    ));
    $assert(
        is_array($firstClaim) && $firstClaim['id'] === (string) $abaTask['id'],
        'First independent worker did not claim the real Search expiry task.',
    );
    Db::table('sm_module_expiry_hook_task')->where('id', $abaTask['id'])->update([
        'locked_until' => '2000-01-01 00:00:00',
    ]);
    $claimWorkers = [
        $startExpiryWorker('claim', $temporaryModuleRoot),
        $startExpiryWorker('claim', $temporaryModuleRoot),
    ];
    $reclaims = array_map(
        static fn (array $worker): mixed => $decodeWorker($finishExpiryWorker($worker)),
        $claimWorkers,
    );
    $reclaims = array_values(array_filter($reclaims, 'is_array'));
    $assert(
        count($reclaims) === 1
        && $reclaims[0]['id'] === (string) $abaTask['id']
        && $reclaims[0]['worker_token'] !== $firstClaim['worker_token'],
        'Two independent claim transactions did not enforce one ABA-safe token rotation.',
    );
    $staleToken = $runExpiryWorker(
        'execute',
        $temporaryModuleRoot,
        (string) $abaTask['id'],
        (string) $firstClaim['worker_token'],
        false,
    );
    $assert(
        str_contains($staleToken['stderr'], 'claimed task token is unavailable'),
        'An old worker token executed the reclaimed Search expiry task.',
    );

    // Renewal wins before the hook transaction: the exact task is superseded
    // and Search runtime rows remain untouched.
    $currentManager->grantLicense(
        1,
        'search',
        date('Y-m-d H:i:s', time() + 86400),
        'renewal wins before hook',
        $actor,
    );
    $superseded = $decodeWorker($runExpiryWorker(
        'execute',
        $temporaryModuleRoot,
        (string) $abaTask['id'],
        (string) $reclaims[0]['worker_token'],
    ));
    $assert(
        ($superseded['outcome'] ?? null) === 'superseded'
        && (int) $pdo->query(
            'SELECT lifecycle_fenced FROM sm_search_index WHERE organization=1',
        )->fetchColumn() === 0
        && (string) $pdo->query(
            'SELECT status FROM sm_search_job WHERE organization=1 ORDER BY id DESC LIMIT 1',
        )->fetchColumn() === 'pending',
        'A stale exact credential mutated Search after a real ModuleManager renewal.',
    );

    // A trigger fails after the index write and before active jobs are fenced.
    // The hook transaction must roll back Search rows, receipt and terminal audit.
    $prepareSearchExpiry();
    $rollbackTask = $reserveSearchExpiry();
    $rollbackClaim = $decodeWorker($runExpiryWorker('claim', $temporaryModuleRoot));
    $pdo->exec('DROP TRIGGER IF EXISTS trg_expiry_search_hook_failure');
    $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_expiry_search_hook_failure
BEFORE UPDATE ON sm_search_job
FOR EACH ROW
BEGIN
  IF OLD.organization=1 AND OLD.status IN ('pending','running') AND NEW.status='failed' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced Search expiry hook failure';
  END IF;
END
SQL);
    $hookFailure = $runExpiryWorker(
        'execute',
        $temporaryModuleRoot,
        (string) $rollbackTask['id'],
        (string) $rollbackClaim['worker_token'],
        false,
    );
    $pdo->exec('DROP TRIGGER trg_expiry_search_hook_failure');
    $rollbackState = $pdo->query(
        'SELECT status,receipt_json,outcome_audit_id FROM sm_module_expiry_hook_task'
        . ' WHERE id=' . (int) $rollbackTask['id'],
    )->fetch();
    $assert(
        str_contains($hookFailure['stderr'], 'forced Search expiry hook failure')
        && ($rollbackState['status'] ?? null) === 'processing'
        && ($rollbackState['receipt_json'] ?? null) === null
        && ($rollbackState['outcome_audit_id'] ?? null) === null
        && (int) $pdo->query(
            'SELECT lifecycle_fenced FROM sm_search_index WHERE organization=1',
        )->fetchColumn() === 0
        && (string) $pdo->query(
            'SELECT status FROM sm_search_job WHERE organization=1 ORDER BY id DESC LIMIT 1',
        )->fetchColumn() === 'pending',
        'Real Search hook failure did not roll back fence, receipt and audit together.',
    );
    Db::table('sm_module_expiry_hook_task')->where('id', $rollbackTask['id'])->update([
        'locked_until' => '2000-01-01 00:00:00',
    ]);
    $rollbackRetry = $decodeWorker($runExpiryWorker('claim', $temporaryModuleRoot));
    $success = $decodeWorker($runExpiryWorker(
        'execute',
        $temporaryModuleRoot,
        (string) $rollbackTask['id'],
        (string) $rollbackRetry['worker_token'],
    ));
    $successState = $pdo->query(
        'SELECT t.status,t.receipt_json,t.outcome_audit_id,l.status AS license_status,'
        . 'i.lifecycle_fenced,i.rebuild_required,a.success AS audit_success'
        . ' FROM sm_module_expiry_hook_task t'
        . ' JOIN sm_tenant_module_license l ON l.id=t.license_id'
        . ' JOIN sm_search_index i ON i.organization=t.organization'
        . ' JOIN sm_module_lifecycle_audit a ON a.id=t.outcome_audit_id'
        . ' WHERE t.id=' . (int) $rollbackTask['id'],
    )->fetch();
    $assert(
        ($success['outcome'] ?? null) === 'succeeded'
        && ($successState['status'] ?? null) === 'succeeded'
        && ($successState['license_status'] ?? null) === 'EXPIRED'
        && ($successState['receipt_json'] ?? null) !== null
        && (int) ($successState['outcome_audit_id'] ?? 0) > 0
        && (int) ($successState['lifecycle_fenced'] ?? 0) === 1
        && (int) ($successState['rebuild_required'] ?? 0) === 1
        && (int) ($successState['audit_success'] ?? 0) === 1
        && (int) $pdo->query(
            "SELECT COUNT(*) FROM sm_search_job WHERE organization=1"
            . " AND status IN ('pending','running')",
        )->fetchColumn() === 0,
        'Search fence, EXPIRED license, receipt and audit did not commit as one fact.',
    );

    // Hold the Search index row so the worker is inside the hook while still
    // owning license->task locks. A real ModuleManager renewal must block.
    $prepareSearchExpiry();
    $blockedTask = $reserveSearchExpiry();
    $blockedClaim = $decodeWorker($runExpiryWorker('claim', $temporaryModuleRoot));
    $blocker = new PDO(
        str_replace(';charset=', ';dbname=' . $database . ';charset=', $adminDsn),
        (string) $connection['username'],
        (string) $connection['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $blocker->beginTransaction();
    $blocker->query(
        'SELECT id FROM sm_search_index WHERE organization=1 FOR UPDATE',
    )->fetchColumn();
    $executeWorker = $startExpiryWorker(
        'execute',
        $temporaryModuleRoot,
        (string) $blockedTask['id'],
        (string) $blockedClaim['worker_token'],
    );
    $deadline = microtime(true) + 8;
    $executeWaiting = false;
    do {
        $waiting = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.PROCESSLIST'
            . ' WHERE DB=? AND INFO LIKE ?',
        );
        $waiting->execute([$database, 'UPDATE sm_search_index SET status=%']);
        $executeWaiting = (int) $waiting->fetchColumn() > 0;
        if (!$executeWaiting) {
            usleep(20_000);
        }
    } while (!$executeWaiting && microtime(true) < $deadline);
    $assert($executeWaiting, 'Search expiry worker did not reach the blocked real fence update.');
    $renewReady = tempnam(sys_get_temp_dir(), 'b8im-expiry-renew-');
    if (!is_string($renewReady)) {
        throw new RuntimeException('Unable to create renewal readiness barrier.');
    }
    unlink($renewReady);
    $renewWorker = $startExpiryWorker('renew', $temporaryModuleRoot, '', $renewReady);
    $deadline = microtime(true) + 5;
    while (!is_file($renewReady) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    usleep(300_000);
    $renewStatus = proc_get_status($renewWorker[0]);
    $assert(
        is_file($renewReady) && ($renewStatus['running'] ?? false) === true,
        'ModuleManager renewal was not blocked by the expiry hook license row lock.',
    );
    $blocker->commit();
    $blockedExecution = $decodeWorker($finishExpiryWorker($executeWorker));
    $blockedRenewal = $decodeWorker($finishExpiryWorker($renewWorker));
    @unlink($renewReady);
    $blockedFinal = $pdo->query(
        'SELECT t.status,t.receipt_json,l.status AS license_status,l.version AS license_version'
        . ' FROM sm_module_expiry_hook_task t'
        . ' JOIN sm_tenant_module_license l ON l.id=t.license_id'
        . ' WHERE t.id=' . (int) $blockedTask['id'],
    )->fetch();
    $assert(
        ($blockedExecution['outcome'] ?? null) === 'succeeded'
        && ($blockedRenewal['status'] ?? null) === 'AUTHORIZED'
        && ($blockedFinal['status'] ?? null) === 'succeeded'
        && ($blockedFinal['receipt_json'] ?? null) !== null
        && ($blockedFinal['license_status'] ?? null) === 'AUTHORIZED',
        'Blocked renewal did not serialize after the authoritative Search expiry receipt.',
    );

    $indexColumns = $pdo->query(
        'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=' . $pdo->quote($database) . '
            AND TABLE_NAME="sm_search_doc" AND INDEX_NAME="idx_org_sender"',
    )->fetchColumn();
    $assert(
        $indexColumns === 'organization,sender_organization,sender_user_id,visibility,sent_at',
        'Search v0.4 compound sender index shape is invalid.',
    );
    $uint64Max = '18446744073709551615';
    $maxJob = $pdo->prepare(
        'INSERT INTO sm_search_job
            (organization,job_type,status,processed,total,cursor_global_seq,
             high_water_global_seq,source_event_cut,error_message)
         VALUES (101,\'rebuild\',\'pending\',0,0,?,?,?,\'\')',
    );
    $maxJob->execute([$uint64Max, $uint64Max, $uint64Max]);
    $maxRoundTrip = $pdo->query(
        'SELECT cursor_global_seq,high_water_global_seq,source_event_cut
           FROM sm_search_job ORDER BY id DESC LIMIT 1',
    )->fetch();
    $assert(
        ($maxRoundTrip['cursor_global_seq'] ?? null) === $uint64Max
        && ($maxRoundTrip['high_water_global_seq'] ?? null) === $uint64Max
        && ($maxRoundTrip['source_event_cut'] ?? null) === $uint64Max,
        'Search job UINT64 cursor/high-water/source cut did not round-trip as decimal strings.',
    );
    $cursorCheckRejected = false;
    try {
        $pdo->exec(
            'INSERT INTO sm_search_job
                (organization,job_type,status,cursor_global_seq,
                 high_water_global_seq,source_event_cut)
             VALUES (102,\'rebuild\',\'pending\',2,1,0)',
        );
        throw new RuntimeException('Search job cursor/high-water CHECK accepted cursor overflow.');
    } catch (PDOException $exception) {
        $cursorCheckRejected = (int) ($exception->errorInfo[1] ?? 0) === 3819
            && str_contains($exception->getMessage(), 'chk_search_job_cursor_high_water');
    }
    $assert(
        $cursorCheckRejected,
        'Search job cursor/high-water fixture was not rejected by its intended CHECK.',
    );
    try {
        $pdo->exec(
            'INSERT INTO sm_search_doc
             (organization,message_id,conversation_id,conversation_type,'
            . 'sender_organization,sender_user_id,
              message_type,message_seq,content,visibility)
             VALUES (101,"invalid-org","c",2,0,"same",1,1,"x",1)',
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
         (organization,message_id,conversation_id,conversation_type,'
        . 'sender_organization,sender_user_id,
          message_type,message_seq,content,visibility)
         VALUES (101,"v4-valid","c",2,202,"same",1,1,"x",1)',
    );
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM sm_search_doc WHERE message_id="v4-valid"')->fetchColumn() === 1,
        'Search v0.4 rejected a canonical sender/type identity.',
    );
    foreach ([0, 3] as $invalidConversationType) {
        try {
            $pdo->exec(
                'INSERT INTO sm_search_doc'
                . ' (organization,message_id,conversation_id,conversation_type,'
                . 'sender_organization,sender_user_id,message_type,message_seq,content,visibility)'
                . ' VALUES (101,"invalid-type-' . $invalidConversationType
                . '","c",' . $invalidConversationType . ',202,"same",1,1,"x",1)',
            );
            throw new RuntimeException('conversation_type CHECK accepted an invalid value.');
        } catch (PDOException) {
            $assert(true, 'conversation_type CHECK rejected an invalid value.');
        }
    }
    $auditUpgrade = $pdo->query(
        'SELECT from_version,target_version,success FROM sm_module_lifecycle_audit
          WHERE module_key="search" AND operation="upgrade" ORDER BY id DESC LIMIT 1',
    )->fetch();
    $assert(
        ($auditUpgrade['from_version'] ?? '') === '0.2.0'
        && ($auditUpgrade['target_version'] ?? '') === '0.4.0'
        && (int) ($auditUpgrade['success'] ?? 0) === 1,
        'Search upgrade lifecycle audit is incomplete.',
    );

    $pdo->exec(
        'UPDATE sm_search_index
            SET status="ready",doc_count=1,last_built_at="2026-07-20 20:00:00",
                last_error="v0.4 state"
          WHERE organization=101',
    );
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM sm_search_doc')->fetchColumn() > 0
        && (int) $pdo->query('SELECT COUNT(*) FROM sm_search_job')->fetchColumn() > 0,
        'Targeted rollback fixture did not contain destructive current state.',
    );
    $rollbackFence = $currentManager->disableSystem('search', $actor);
    $assert(
        ($rollbackFence['system']['status'] ?? null) === 'DISABLED'
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM sm_search_index'
            . ' WHERE delete_time IS NULL AND lifecycle_fenced<>1',
        )->fetchColumn() === 0
        && (int) $pdo->query(
            "SELECT COUNT(*) FROM sm_search_job WHERE status IN ('pending','running')",
        )->fetchColumn() === 0,
        'ModuleManager lifecycle fence did not close Search runtime before targeted rollback.',
    );
    $rollbackSearchToV2();
    $assert(
        $phinxVersions() === [20260716070000]
        && !$columnExists('sender_organization')
        && !$columnExists('conversation_type')
        && !$columnExists('source_change_seq'),
        'Targeted v0.4 rollback did not retain exactly the historical migration.',
    );
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM sm_search_doc')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM sm_search_job')->fetchColumn() === 0,
        'Targeted v0.4 rollback retained incompatible documents or jobs.',
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
        'Targeted v0.4 rollback did not reset the search index state.',
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
        'Targeted v0.4 rollback did not restore the exact sender_user_id default.',
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
                    "chk_search_doc_sender_user_id",
                    "chk_search_doc_conversation_type"
                )) AS sender_constraints',
    )->fetch();
    $assert(
        (int) ($removedDocState['sender_index'] ?? -1) === 0
        && (int) ($removedDocState['sender_constraints'] ?? -1) === 0,
        'Targeted v0.4 rollback retained sender/type indexes or constraints.',
    );
    $assertV2JobSchema('Targeted search v0.4 rollback');

    $migrateSearchToCurrent();
    Db::table('sm_module')->where('module_key', 'search')->update(['status' => 'DISABLED']);
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
        && $rediscovered['system']['version'] === '0.4.0',
        'Search v0.4 reinstall did not rediscover the current package.',
    );
    $reinstalled = $currentManager->install('search', $actor);
    $assert(
        $reinstalled['system']['status'] === 'INSTALLED'
        && $reinstalled['system']['version'] === '0.4.0',
        'Search v0.4 fresh reinstall did not enter INSTALLED.',
    );
    $assert(
        $tableExists('sm_search_doc')
        && $columnExists('sender_organization')
        && $phinxVersions() === [20260716070000, 20260720193000, 20260721130000],
        'Search v0.4 fresh reinstall did not restore the complete schema/history.',
    );
    $assertV3JobSchema('Search v0.4 reinstall');
    $assertV4DocSchema('Search v0.4 reinstall');
    $assert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM sm_module_lifecycle_audit
              WHERE module_key="search" AND operation="install" AND success=1',
        )->fetchColumn() === 3,
        'Search fresh install/historical install/reinstall lifecycle audits are incomplete.',
    );

    // Missing runtime heartbeats after an enabled upgrade must leave both the
    // module and search data plane fail closed; it never silently restores the
    // prior ENABLED state.
    $pdo->exec(
        'INSERT INTO sm_search_index'
        . ' (organization,backend,status,doc_count,last_error,rebuild_required,lifecycle_fenced)'
        . ' VALUES (101,"mysql","ready",0,"",0,0)',
    );
    Db::table('sm_module')->where('module_key', 'search')->update(['status' => 'ENABLED']);
    $futureManifest = $actualManifest;
    $futureManifest['version'] = '0.5.0';
    file_put_contents(
        $temporaryManifestPath,
        json_encode($futureManifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );
    $redis->del($consumerConfig->heartbeatRedisKey, $rebuildConfig->heartbeatKey);
    try {
        $managerFor($temporaryModuleRoot)->upgrade('search', $actor);
        throw new RuntimeException('Enabled Search upgrade restored without runtime heartbeats.');
    } catch (plugin\saimulti\exception\ApiException) {
        $assert(true, 'Missing heartbeat rejected enabled Search post-upgrade restore.');
    }
    $failedIndex = $pdo->query(
        'SELECT rebuild_required,lifecycle_fenced FROM sm_search_index WHERE organization=101',
    )->fetch();
    $assert(
        Db::table('sm_module')->where('module_key', 'search')->value('status') === 'FAILED'
        && (int) ($failedIndex['rebuild_required'] ?? 0) === 1
        && (int) ($failedIndex['lifecycle_fenced'] ?? 0) === 1,
        'Failed enabled Search upgrade did not remain fail closed.',
    );

    fwrite(STDOUT, sprintf(
        "SearchModuleMigrationIntegrationTest: %d assertions passed\n",
        $assertions,
    ));
} finally {
    $pdo = null;
    $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
}
