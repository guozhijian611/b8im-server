<?php

declare(strict_types=1);

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$database = (string) getenv('STORAGE_QUOTA_TEST_DB_NAME');
if (!str_ends_with($database, '_storage_quota_test')) {
    throw new RuntimeException('unsafe storage quota migration test database');
}
putenv('DB_NAME=' . $database);
putenv('PHINX_DB_NAME=' . $database);
$_ENV['DB_NAME'] = $database;
$_ENV['PHINX_DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
$_SERVER['PHINX_DB_NAME'] = $database;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$configPath = $root . '/phinx.php';
$phinxConfig = new Config(require $configPath, $configPath);
$input = new ArrayInput([]);
$input->setInteractive(false);
$manager = new Manager($phinxConfig, $input, new BufferedOutput());
$environment = $phinxConfig->getEnvironment('default');
$pdo = new PDO(sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $environment['host'],
    (int) $environment['port'],
    $database,
    $environment['charset'],
), (string) $environment['user'], (string) $environment['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$tableExists = static function (string $table) use ($pdo, $database): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
    );
    $statement->execute([$database, $table]);

    return (int) $statement->fetchColumn() === 1;
};
$menuCount = static fn (): int => (int) $pdo->query(
    "SELECT COUNT(*) FROM sm_admin_menu
      WHERE code='storage-quota'
         OR slug IN ('saimulti:admin:storage_quota:index','saimulti:admin:storage_quota:update')",
)->fetchColumn();
$migrationApplied = static fn (): bool => (int) $pdo->query(
    'SELECT COUNT(*) FROM phinxlog WHERE version=20260722100000',
)->fetchColumn() === 1;
$faultedMigrate = static function (string $fault) use ($manager): Throwable {
    putenv('B8IM_STORAGE_QUOTA_MIGRATION_FAULT=' . $fault);
    try {
        $manager->migrate('default');
    } catch (Throwable $exception) {
        return $exception;
    } finally {
        putenv('B8IM_STORAGE_QUOTA_MIGRATION_FAULT');
    }
    throw new RuntimeException("expected migration fault {$fault}");
};
$failedMigrate = static function () use ($manager): Throwable {
    try {
        $manager->migrate('default');
    } catch (Throwable $exception) {
        $manager->getEnvironment('default')->getAdapter()->rollbackTransaction();
        return $exception;
    }
    throw new RuntimeException('expected migration contract failure');
};
$failedRollback = static function () use ($manager): Throwable {
    try {
        $manager->rollback('default', 20260721131000, false);
    } catch (Throwable $exception) {
        $manager->getEnvironment('default')->getAdapter()->rollbackTransaction();
        return $exception;
    }
    throw new RuntimeException('expected rollback contract failure');
};
$migrationWriteTables = [
    'sm_tenant_quota',
    'sm_admin_menu',
    'sm_admin_role_menu',
    'sm_tenant_menu',
    'sm_tenant_group_menu',
    'sm_tenant_role_menu',
    'sm_im_upload_reservation',
    'sm_im_upload_cleanup_cursor',
    'phinxlog',
];
/**
 * Snapshot every row and every column of every table this migration or Phinx
 * may write. Constraint and trigger metadata are deliberately excluded because
 * each hostile schema mutation is the input under test.
 *
 * @return array<string,array{
 *   exists:bool,auto_increment:?string,columns:list<string>,rows:list<array<string,mixed>>
 * }>
 */
$migrationWriteSnapshot = static function () use (
    $pdo,
    $database,
    $migrationWriteTables,
): array {
    $snapshot = [];
    $tableStatement = $pdo->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES
          WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',
    );
    $columnsStatement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',
    );
    foreach ($migrationWriteTables as $table) {
        if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException("unsafe snapshot table {$table}");
        }
        $tableStatement->execute([$database, $table]);
        $tableState = $tableStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($tableState)) {
            $snapshot[$table] = [
                'exists' => false,
                'auto_increment' => null,
                'columns' => [],
                'rows' => [],
            ];
            continue;
        }
        $autoIncrement = $tableState['AUTO_INCREMENT'];
        if ($autoIncrement !== null) {
            $autoIncrement = (string) $autoIncrement;
        }
        $columnsStatement->execute([$database, $table]);
        $columns = array_map('strval', $columnsStatement->fetchAll(PDO::FETCH_COLUMN));
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            ksort($row, SORT_STRING);
        }
        unset($row);
        usort($rows, static function (array $left, array $right): int {
            $flags = JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR;

            return strcmp(json_encode($left, $flags), json_encode($right, $flags));
        });
        $snapshot[$table] = [
            'exists' => true,
            'auto_increment' => $autoIncrement,
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    return $snapshot;
};
$createPartialCursor = static function (bool $hostileCheck = false) use ($pdo): void {
    $check = $hostileCheck
        ? ",\n  CONSTRAINT chk_hostile_cursor_frozen CHECK (last_reservation_id=0)"
        : '';
    $pdo->exec(<<<SQL
CREATE TABLE sm_im_upload_cleanup_cursor (
  id tinyint UNSIGNED NOT NULL,
  last_reservation_id bigint UNSIGNED NOT NULL DEFAULT 0,
  update_time datetime(6) NOT NULL,
  PRIMARY KEY (id){$check}
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Upload cleanup scan cursor'
SQL);
    $pdo->exec(
        'INSERT INTO sm_im_upload_cleanup_cursor (id,last_reservation_id,update_time)
         VALUES (1,0,NOW(6))',
    );
};

$pdo->exec(
    "UPDATE sm_tenant_quota
        SET quota_value=123456,used_value=0,version=version+1
      WHERE organization=1 AND quota_key='storage_bytes'",
);
$preserved = $pdo->query(
    "SELECT quota_value,used_value,version FROM sm_tenant_quota
      WHERE organization=1 AND quota_key='storage_bytes'",
)->fetch(PDO::FETCH_ASSOC);
$assert(is_array($preserved), 'central storage row fixture is missing');

$manager->rollback('default', 20260721131000, false);
$afterDown = $pdo->query(
    "SELECT quota_value,used_value,version FROM sm_tenant_quota
      WHERE organization=1 AND quota_key='storage_bytes'",
)->fetch(PDO::FETCH_ASSOC);
$assert(
    $afterDown === $preserved
    && !$tableExists('sm_im_upload_reservation')
    && !$tableExists('sm_im_upload_cleanup_cursor')
    && $menuCount() === 0
    && !$migrationApplied(),
    'down migration changed central facts or retained owned runtime objects',
);

$createPartialCursor(true);
$hostileCursorCheckBefore = $migrationWriteSnapshot();
$hostileCursorCheck = $failedMigrate();
$assert(
    str_contains($hostileCursorCheck->getMessage(), 'constraint set')
    && $migrationWriteSnapshot() === $hostileCursorCheckBefore,
    'partial cursor extra CHECK was adopted or caused writes before up rejection',
);
$pdo->exec('DROP TABLE sm_im_upload_cleanup_cursor');

$createPartialCursor();
$pdo->exec(<<<'SQL'
CREATE TRIGGER trg_hostile_cursor_before_update
BEFORE UPDATE ON sm_im_upload_cleanup_cursor
FOR EACH ROW SET NEW.last_reservation_id=OLD.last_reservation_id
SQL);
$hostileCursorTriggerBefore = $migrationWriteSnapshot();
$hostileCursorTrigger = $failedMigrate();
$assert(
    str_contains($hostileCursorTrigger->getMessage(), 'must not define triggers')
    && $migrationWriteSnapshot() === $hostileCursorTriggerBefore,
    'partial cursor trigger was adopted or caused writes before up rejection',
);
$pdo->exec('DROP TRIGGER trg_hostile_cursor_before_update');
$pdo->exec('DROP TABLE sm_im_upload_cleanup_cursor');

$fault = $faultedMigrate('after_create');
$afterCreateFault = $pdo->query(
    "SELECT quota_value,used_value,version FROM sm_tenant_quota
      WHERE organization=1 AND quota_key='storage_bytes'",
)->fetch(PDO::FETCH_ASSOC);
$assert(
    str_contains($fault->getMessage(), 'after_create')
    && $tableExists('sm_im_upload_reservation')
    && $tableExists('sm_im_upload_cleanup_cursor')
    && (int) $pdo->query('SELECT COUNT(*) FROM sm_im_upload_reservation')->fetchColumn() === 0
    && (int) $pdo->query(
        'SELECT last_reservation_id FROM sm_im_upload_cleanup_cursor WHERE id=1',
    )->fetchColumn() === 0
    && $afterCreateFault === $preserved
    && $menuCount() === 0
    && !$migrationApplied(),
    'after_create fault was not safely resumable',
);

$pdo->exec(
    'ALTER TABLE sm_im_upload_reservation
       ADD CONSTRAINT fk_hostile_upload_organization
       FOREIGN KEY (organization) REFERENCES sm_system_organization(id)',
);
$hostileReservationForeignKeyBefore = $migrationWriteSnapshot();
$hostileReservationForeignKey = $failedMigrate();
$assert(
    str_contains($hostileReservationForeignKey->getMessage(), 'constraint set')
    && $migrationWriteSnapshot() === $hostileReservationForeignKeyBefore,
    'partial reservation foreign key was adopted or caused writes before up rejection',
);
$pdo->exec(
    'ALTER TABLE sm_im_upload_reservation DROP FOREIGN KEY fk_hostile_upload_organization',
);

$pdo->exec(<<<'SQL'
CREATE TRIGGER trg_hostile_reservation_before_insert
BEFORE INSERT ON sm_im_upload_reservation
FOR EACH ROW SET NEW.cleanup_error=NEW.cleanup_error
SQL);
$hostileReservationTriggerBefore = $migrationWriteSnapshot();
$hostileReservationTrigger = $failedMigrate();
$assert(
    str_contains($hostileReservationTrigger->getMessage(), 'must not define triggers')
    && $migrationWriteSnapshot() === $hostileReservationTriggerBefore,
    'partial reservation trigger was adopted or caused writes before up rejection',
);
$pdo->exec('DROP TRIGGER trg_hostile_reservation_before_insert');

$pdo->exec(
    'ALTER TABLE sm_im_upload_reservation
       DROP CHECK chk_im_upload_reservation_positive,
       ADD CONSTRAINT chk_im_upload_reservation_positive
       CHECK (size_bytes >= 0 AND version > 0)',
);
$weakenedReservationCheckBefore = $migrationWriteSnapshot();
$weakenedReservationCheck = $failedMigrate();
$assert(
    str_contains($weakenedReservationCheck->getMessage(), 'authoritative CHECK contract')
    && $migrationWriteSnapshot() === $weakenedReservationCheckBefore,
    'partial reservation weakened owned CHECK was adopted or caused up writes',
);
$pdo->exec(
    'ALTER TABLE sm_im_upload_reservation
       DROP CHECK chk_im_upload_reservation_positive,
       ADD CONSTRAINT chk_im_upload_reservation_positive
       CHECK (size_bytes > 0 AND version > 0)',
);

$manager->migrate('default');
$assert($migrationApplied() && $menuCount() === 3, 'after_create resume did not converge');

$pdo->exec(
    'ALTER TABLE sm_im_upload_cleanup_cursor
       ADD CONSTRAINT chk_hostile_cursor_frozen CHECK (last_reservation_id=0)',
);
$hostileCursorCheckDownBefore = $migrationWriteSnapshot();
$hostileCursorCheckDown = $failedRollback();
$assert(
    str_contains($hostileCursorCheckDown->getMessage(), 'constraint set')
    && $migrationWriteSnapshot() === $hostileCursorCheckDownBefore,
    'cursor extra CHECK allowed rollback writes or runtime table drops',
);
$pdo->exec(
    'ALTER TABLE sm_im_upload_cleanup_cursor DROP CHECK chk_hostile_cursor_frozen',
);

$pdo->exec(<<<'SQL'
CREATE TRIGGER trg_hostile_cursor_before_update
BEFORE UPDATE ON sm_im_upload_cleanup_cursor
FOR EACH ROW SET NEW.last_reservation_id=OLD.last_reservation_id
SQL);
$hostileCursorTriggerDownBefore = $migrationWriteSnapshot();
$hostileCursorTriggerDown = $failedRollback();
$assert(
    str_contains($hostileCursorTriggerDown->getMessage(), 'must not define triggers')
    && $migrationWriteSnapshot() === $hostileCursorTriggerDownBefore,
    'cursor trigger allowed rollback writes or runtime table drops',
);
$pdo->exec('DROP TRIGGER trg_hostile_cursor_before_update');

$pdo->exec(
    'ALTER TABLE sm_im_upload_reservation
       ADD CONSTRAINT fk_hostile_upload_organization
       FOREIGN KEY (organization) REFERENCES sm_system_organization(id)',
);
$hostileReservationForeignKeyDownBefore = $migrationWriteSnapshot();
$hostileReservationForeignKeyDown = $failedRollback();
$assert(
    str_contains($hostileReservationForeignKeyDown->getMessage(), 'constraint set')
    && $migrationWriteSnapshot() === $hostileReservationForeignKeyDownBefore,
    'reservation foreign key allowed rollback writes or runtime table drops',
);
$pdo->exec(
    'ALTER TABLE sm_im_upload_reservation DROP FOREIGN KEY fk_hostile_upload_organization',
);

$pdo->exec(<<<'SQL'
CREATE TRIGGER trg_hostile_reservation_before_insert
BEFORE INSERT ON sm_im_upload_reservation
FOR EACH ROW SET NEW.cleanup_error=NEW.cleanup_error
SQL);
$hostileReservationTriggerDownBefore = $migrationWriteSnapshot();
$hostileReservationTriggerDown = $failedRollback();
$assert(
    str_contains($hostileReservationTriggerDown->getMessage(), 'must not define triggers')
    && $migrationWriteSnapshot() === $hostileReservationTriggerDownBefore,
    'reservation trigger allowed rollback writes or runtime table drops',
);
$pdo->exec('DROP TRIGGER trg_hostile_reservation_before_insert');

$pdo->exec(
    'ALTER TABLE sm_im_upload_reservation
       DROP CHECK chk_im_upload_reservation_positive,
       ADD CONSTRAINT chk_im_upload_reservation_positive
       CHECK (size_bytes >= 0 AND version > 0)',
);
$weakenedReservationCheckDownBefore = $migrationWriteSnapshot();
$weakenedReservationCheckDown = $failedRollback();
$assert(
    str_contains($weakenedReservationCheckDown->getMessage(), 'authoritative CHECK contract')
    && $migrationWriteSnapshot() === $weakenedReservationCheckDownBefore,
    'reservation weakened owned CHECK allowed rollback writes or runtime table drops',
);
$pdo->exec(
    'ALTER TABLE sm_im_upload_reservation
       DROP CHECK chk_im_upload_reservation_positive,
       ADD CONSTRAINT chk_im_upload_reservation_positive
       CHECK (size_bytes > 0 AND version > 0)',
);

$cursorColumns = $pdo->query(
    "SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_im_upload_cleanup_cursor'
      ORDER BY ORDINAL_POSITION",
)->fetchAll(PDO::FETCH_ASSOC);
$cursorIndexes = $pdo->query(
    "SELECT INDEX_NAME,NON_UNIQUE,
            GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_im_upload_cleanup_cursor'
   GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME",
)->fetchAll(PDO::FETCH_ASSOC);
$cursorRow = $pdo->query(
    'SELECT id,last_reservation_id FROM sm_im_upload_cleanup_cursor',
)->fetchAll(PDO::FETCH_ASSOC);
$assert(
    array_column($cursorColumns, 'COLUMN_NAME') === [
        'id', 'last_reservation_id', 'update_time',
    ]
    && array_column($cursorColumns, 'COLUMN_TYPE') === [
        'tinyint unsigned', 'bigint unsigned', 'datetime(6)',
    ]
    && array_column($cursorColumns, 'IS_NULLABLE') === ['NO', 'NO', 'NO']
    && $cursorIndexes === [[
        'INDEX_NAME' => 'PRIMARY',
        'NON_UNIQUE' => 0,
        'columns_list' => 'id',
    ]]
    && count($cursorRow) === 1
    && (int) $cursorRow[0]['id'] === 1
    && (int) $cursorRow[0]['last_reservation_id'] === 0,
    'cleanup scan cursor schema or singleton seed drifted',
);

$indexRows = $pdo->query(
    "SELECT INDEX_NAME,NON_UNIQUE,
            GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_im_upload_reservation'
   GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME",
)->fetchAll(PDO::FETCH_ASSOC);
$actualIndexes = [];
foreach ($indexRows as $indexRow) {
    $actualIndexes[(string) $indexRow['INDEX_NAME']] = [
        (int) $indexRow['NON_UNIQUE'],
        (string) $indexRow['columns_list'],
    ];
}
$expectedIndexes = [
    'PRIMARY' => [0, 'id'],
    'idx_cleanup' => [1, 'state,cleanup_next_at,cleanup_lease_expires_at,id'],
    'idx_expiry' => [1, 'state,expires_at,id'],
    'idx_object_uploaded' => [1, 'state,update_time,id'],
    'idx_org_state' => [1, 'organization,state,id'],
    'idx_upload_lease' => [1, 'state,upload_lease_expires_at,id'],
    'uni_org_file' => [0, 'organization,file_id'],
    'uni_org_idempotency' => [0, 'organization,idempotency_key'],
    'uni_org_storage_path' => [0, 'organization,storage_path'],
    'uni_org_upload' => [0, 'organization,upload_id'],
];
ksort($actualIndexes);
ksort($expectedIndexes);
$assert($actualIndexes === $expectedIndexes, 'reservation exact index contract drifted');

$checkRows = $pdo->query(
    "SELECT CONSTRAINT_NAME,ENFORCED
       FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA=DATABASE()
        AND TABLE_NAME='sm_im_upload_reservation'
        AND CONSTRAINT_TYPE='CHECK'
   ORDER BY CONSTRAINT_NAME",
)->fetchAll(PDO::FETCH_ASSOC);
$assert(
    array_column($checkRows, 'CONSTRAINT_NAME') === [
        'chk_im_upload_reservation_identity',
        'chk_im_upload_reservation_positive',
        'chk_im_upload_reservation_state',
        'chk_im_upload_reservation_state_facts',
    ]
    && array_column($checkRows, 'ENFORCED') === ['YES', 'YES', 'YES', 'YES'],
    'reservation CHECK contract is missing or not enforced',
);

$pdo->exec(
    "INSERT INTO sm_im_upload_reservation
      (organization,upload_id,idempotency_key,intent_hash,file_id,storage_path,user_id,
       client_family,kind,filename,size_bytes,mime_type,extension,state,expires_at,
       create_time,update_time)
     VALUES
      (1,REPEAT('a',64),REPEAT('b',32),REPEAT('c',64),REPEAT('d',40),
       CONCAT('private/organizations/1/im/202607/',REPEAT('e',48),'.txt'),
       'migration-check','web','file','check.txt',1,'text/plain','txt','reserved',
       DATE_ADD(NOW(6), INTERVAL 1 HOUR),NOW(6),NOW(6))",
);
$checkId = (int) $pdo->lastInsertId();
$rejectedMutations = 0;
foreach ([
    "upload_id=REPEAT('A',64)",
    'size_bytes=0',
    "state='rogue'",
    'upload_lease_token=REPEAT(\'f\',64)',
] as $mutation) {
    try {
        $pdo->exec("UPDATE sm_im_upload_reservation SET {$mutation} WHERE id={$checkId}");
    } catch (PDOException) {
        $rejectedMutations++;
    }
}
$checkFixture = $pdo->query(
    "SELECT upload_id,size_bytes,state,upload_lease_token
       FROM sm_im_upload_reservation WHERE id={$checkId}",
)->fetch(PDO::FETCH_ASSOC);
$assert(
    $rejectedMutations === 4
    && is_array($checkFixture)
    && $checkFixture['upload_id'] === str_repeat('a', 64)
    && (int) $checkFixture['size_bytes'] === 1
    && $checkFixture['state'] === 'reserved'
    && $checkFixture['upload_lease_token'] === null,
    'reservation CHECK constraints did not reject hostile mutations atomically',
);
$pdo->exec("DELETE FROM sm_im_upload_reservation WHERE id={$checkId}");

$page = $pdo->query(
    "SELECT id,parent_id,path,component,is_hidden,module_key,status,remark
       FROM sm_admin_menu WHERE code='storage-quota'",
)->fetch(PDO::FETCH_ASSOC);
$assert(
    is_array($page)
    && $page['path'] === 'storage-quota'
    && $page['component'] === '/storage-quota/index'
    && (int) $page['is_hidden'] === 0
    && $page['module_key'] === null
    && (int) $page['status'] === 1,
    'admin storage quota page contract drifted',
);
$pageId = (int) $page['id'];
$adminPermissions = $pdo->query(sprintf(
    'SELECT slug,method,is_hidden,module_key FROM sm_admin_menu
      WHERE parent_id=%d AND slug IS NOT NULL ORDER BY slug',
    $pageId,
))->fetchAll(PDO::FETCH_ASSOC);
$assert(
    array_column($adminPermissions, 'slug') === [
        'saimulti:admin:storage_quota:index',
        'saimulti:admin:storage_quota:update',
    ]
    && array_column($adminPermissions, 'method') === ['GET', 'PUT']
    && array_map('intval', array_column($adminPermissions, 'is_hidden')) === [1, 1]
    && array_column($adminPermissions, 'module_key') === [null, null],
    'admin storage quota permission contract drifted',
);
$adminGrantRows = $pdo->query(sprintf(
    "SELECT r.code AS role_code,m.slug,m.code
       FROM sm_admin_role_menu rm
       JOIN sm_admin_role r ON r.id=rm.role_id
       JOIN sm_admin_menu m ON m.id=rm.menu_id
      WHERE m.id=%d OR m.parent_id=%d ORDER BY m.id",
    $pageId,
    $pageId,
))->fetchAll(PDO::FETCH_ASSOC);
$assert(
    count($adminGrantRows) === 3
    && array_values(array_unique(array_column($adminGrantRows, 'role_code'))) === ['superAdmin'],
    'central storage quota permissions were granted beyond unique superAdmin',
);
$tenantPermission = $pdo->query(
    "SELECT id,module_key,is_hidden FROM sm_tenant_menu
      WHERE organization=0 AND slug='saimulti:tenant:storage_quota:read'",
)->fetch(PDO::FETCH_ASSOC);
$assert(
    is_array($tenantPermission)
    && $tenantPermission['module_key'] === null
    && (int) $tenantPermission['is_hidden'] === 1,
    'tenant storage quota read permission contract drifted',
);
$tenantPermissionId = (int) $tenantPermission['id'];
$activeGroups = (int) $pdo->query(
    'SELECT COUNT(*) FROM sm_tenant_group WHERE status=1 AND delete_time IS NULL',
)->fetchColumn();
$groupGrants = (int) $pdo->query(
    "SELECT COUNT(*) FROM sm_tenant_group_menu WHERE menu_id={$tenantPermissionId}",
)->fetchColumn();
$roleGrants = (int) $pdo->query(
    "SELECT COUNT(*) FROM sm_tenant_role_menu WHERE menu_id={$tenantPermissionId}",
)->fetchColumn();
$assert(
    $groupGrants === $activeGroups && $roleGrants === 0,
    'tenant storage quota permission was not bound exactly to active groups',
);

$manager->rollback('default', 20260721131000, false);
$pdo->exec(
    "UPDATE sm_tenant_quota SET used_value=1,version=version+1
      WHERE organization=1 AND quota_key='storage_bytes'",
);
$beforeDmlFault = $pdo->query(
    "SELECT quota_value,used_value,version FROM sm_tenant_quota
      WHERE organization=1 AND quota_key='storage_bytes'",
)->fetch(PDO::FETCH_ASSOC);
$fault = $faultedMigrate('after_first_central_dml');
$afterDmlFault = $pdo->query(
    "SELECT quota_value,used_value,version FROM sm_tenant_quota
      WHERE organization=1 AND quota_key='storage_bytes'",
)->fetch(PDO::FETCH_ASSOC);
$assert(
    str_contains($fault->getMessage(), 'after_first_central_dml')
    && $afterDmlFault === $beforeDmlFault
    && $tableExists('sm_im_upload_reservation')
    && $tableExists('sm_im_upload_cleanup_cursor')
    && $menuCount() === 0
    && !$migrationApplied(),
    'first central DML fault leaked a partial reconciliation',
);
$manager->migrate('default');
$reconciledUsed = (int) $pdo->query(
    "SELECT used_value FROM sm_tenant_quota
      WHERE organization=1 AND quota_key='storage_bytes'",
)->fetchColumn();
$assert($reconciledUsed === 0 && $migrationApplied(), 'DML fault resume did not reconcile usage');

$pageId = (int) $pdo->query(
    "SELECT id FROM sm_admin_menu WHERE code='storage-quota'",
)->fetchColumn();
$pdo->exec(sprintf(
    "INSERT INTO sm_admin_menu
      (parent_id,name,code,slug,module_key,type,path,component,method,is_hidden,status,
       remark,create_time,update_time)
     VALUES (%d,'Hostile child','hostile-storage-child',NULL,NULL,2,'hostile-child',
             '/hostile-child/index',NULL,0,1,'not-owned',NOW(),NOW())",
    $pageId,
));
$hostileChildId = (int) $pdo->lastInsertId();
$childBlocked = false;
try {
    $manager->rollback('default', 20260721131000, false);
} catch (Throwable $exception) {
    $childBlocked = str_contains($exception->getMessage(), 'unowned child');
}
$assert(
    $childBlocked
    && $tableExists('sm_im_upload_reservation')
    && $tableExists('sm_im_upload_cleanup_cursor')
    && $menuCount() === 3
    && $migrationApplied(),
    'unowned admin-page child did not block rollback before destructive writes',
);
$pdo->exec("DELETE FROM sm_admin_menu WHERE id={$hostileChildId}");

$pdo->exec(
    "INSERT INTO sm_im_upload_reservation
      (organization,upload_id,idempotency_key,intent_hash,file_id,storage_path,user_id,
       client_family,kind,filename,size_bytes,mime_type,extension,state,expires_at,
       create_time,update_time,released_at,release_reason)
     VALUES
      (1,REPEAT('1',64),REPEAT('2',32),REPEAT('3',64),REPEAT('4',40),
       CONCAT('private/organizations/1/im/202607/',REPEAT('5',48),'.txt'),
       'migration-owner','web','file','history.txt',1,'text/plain','txt','released',
       '9999-12-31 23:59:59.999999',NOW(6),NOW(6),NOW(6),'migration_history')",
);
$historyBlocked = false;
try {
    $manager->rollback('default', 20260721131000, false);
} catch (Throwable $exception) {
    $historyBlocked = str_contains($exception->getMessage(), 'upload history');
}
$assert(
    $historyBlocked
    && $tableExists('sm_im_upload_reservation')
    && $menuCount() === 3
    && $migrationApplied(),
    'rollback history blocker performed partial destructive writes',
);
$pdo->exec("DELETE FROM sm_im_upload_reservation WHERE user_id='migration-owner'");

$pdo->exec(
    "UPDATE sm_admin_menu SET component='/hostile' WHERE code='storage-quota'",
);
$driftBlocked = false;
try {
    $manager->rollback('default', 20260721131000, false);
} catch (Throwable $exception) {
    $driftBlocked = str_contains($exception->getMessage(), 'hostile schema drift');
}
$assert(
    $driftBlocked
    && $tableExists('sm_im_upload_reservation')
    && $migrationApplied(),
    'hostile core-menu drift did not block rollback before writes',
);
$pdo->exec(
    "UPDATE sm_admin_menu SET component='/storage-quota/index' WHERE code='storage-quota'",
);

echo sprintf("StorageQuotaMigrationIntegrationTest: %d assertions passed\n", $assertions);
