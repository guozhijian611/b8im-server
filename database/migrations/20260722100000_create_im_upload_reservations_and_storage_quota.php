<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateImUploadReservationsAndStorageQuota extends AbstractMigration
{
    private const QUOTA_KEY = 'storage_bytes';
    private const SEED_ORDER = 'storage-quota-p1-20260722';
    private const SEED_REMARK = 'Server P1 authoritative physical storage quota';
    private const RESERVATION_TABLE = 'sm_im_upload_reservation';
    private const CLEANUP_CURSOR_TABLE = 'sm_im_upload_cleanup_cursor';
    private const FAULT_ENV = 'B8IM_STORAGE_QUOTA_MIGRATION_FAULT';
    private const MENU_REMARK = 'storage-quota-core-p1-20260722';
    private const ADMIN_PAGE_CODE = 'storage-quota';
    private const ADMIN_INDEX_SLUG = 'saimulti:admin:storage_quota:index';
    private const ADMIN_UPDATE_SLUG = 'saimulti:admin:storage_quota:update';
    private const TENANT_READ_SLUG = 'saimulti:tenant:storage_quota:read';

    public function up(): void
    {
        foreach (['sm_system_organization', 'sm_tenant_quota'] as $table) {
            if (!$this->hasTable($table)) {
                throw new RuntimeException("Required table {$table} is missing.");
            }
        }
        $this->assertCentralQuotaContract();
        $this->storageQuotaPlan(false);
        $this->assertCorePermissionPreflight();
        $reservationExists = $this->hasTable(self::RESERVATION_TABLE);
        $cursorExists = $this->hasTable(self::CLEANUP_CURSOR_TABLE);

        // Validate every pre-existing runtime object before creating, updating,
        // or adopting anything. A partial object may be resumed only when its
        // complete owned schema is authoritative.
        if ($reservationExists) {
            $this->assertReservationContract();
            $row = $this->fetchRow('SELECT COUNT(*) AS aggregate FROM ' . self::RESERVATION_TABLE);
            if ((int) ($row['aggregate'] ?? 0) !== 0) {
                throw new RuntimeException(
                    'A partially applied reservation table must be empty before migration resume.',
                );
            }
        }
        if ($cursorExists) {
            $this->assertCleanupCursorContract();
        }

        $createdRuntimeTable = false;
        if (!$reservationExists) {
            $this->createReservationTable();
            $this->assertReservationContract();
            $createdRuntimeTable = true;
        }
        if (!$cursorExists) {
            $this->createCleanupCursorTable();
            $this->assertCleanupCursorContract();
            $createdRuntimeTable = true;
        }
        if ($createdRuntimeTable) {
            $this->injectFault('after_create');
        }
        $this->applyStorageQuotaPlan();
        $this->seedCorePermissions();
    }

    public function down(): void
    {
        if (!$this->hasTable('sm_tenant_quota')) {
            throw new RuntimeException('sm_tenant_quota is required for safe rollback.');
        }
        $this->assertCentralQuotaContract();
        $permissionContext = $this->corePermissionContext();
        $this->assertCorePermissionBindings($permissionContext, true);
        $this->assertNoUnownedAdminPageChildren($permissionContext);
        $reservationExists = $this->hasTable(self::RESERVATION_TABLE);
        $cursorExists = $this->hasTable(self::CLEANUP_CURSOR_TABLE);
        if ($reservationExists !== $cursorExists) {
            throw new RuntimeException('Upload cleanup runtime table set is incomplete.');
        }
        if ($reservationExists) {
            $this->assertReservationContract();
            $this->assertCleanupCursorContract();
            $history = $this->fetchRow(
                'SELECT COUNT(*) AS aggregate FROM ' . self::RESERVATION_TABLE,
            );
            if ((int) ($history['aggregate'] ?? 0) !== 0) {
                throw new RuntimeException(
                    'Cannot roll back a reservation table containing upload history.',
                );
            }
        }

        // storage_bytes is an audited central fact. Rollback never reverses or
        // deletes reconciled usage, administrator capacity changes, or versions.
        $this->physicalUsageByOrganization();
        if ($reservationExists) {
            $this->execute('DROP TABLE ' . self::RESERVATION_TABLE);
            $this->execute('DROP TABLE ' . self::CLEANUP_CURSOR_TABLE);
        }
        $this->removeCorePermissions($permissionContext);
    }

    private function assertCorePermissionPreflight(): void
    {
        $this->corePermissionContext();
    }

    /**
     * @return array{
     *   admin_parent:int,tenant_parent:int,super_admin:int,
     *   admin_page:?int,admin_index:?int,admin_update:?int,tenant_read:?int
     * }
     */
    private function corePermissionContext(): array
    {
        $contracts = [
            'sm_admin_menu' => [
                'id', 'parent_id', 'name', 'code', 'slug', 'module_key', 'type',
                'path', 'component', 'method', 'is_hidden', 'status', 'remark', 'delete_time',
            ],
            'sm_admin_role' => ['id', 'code', 'status', 'delete_time'],
            'sm_admin_role_menu' => ['role_id', 'menu_id'],
            'sm_tenant_menu' => [
                'id', 'organization', 'parent_id', 'name', 'code', 'slug',
                'module_key', 'type', 'path', 'component', 'method', 'is_hidden',
                'status', 'remark', 'delete_time',
            ],
            'sm_tenant_role_menu' => ['role_id', 'menu_id'],
            'sm_tenant_group' => ['id', 'status', 'delete_time'],
            'sm_tenant_group_menu' => ['group_id', 'menu_id'],
        ];
        foreach ($contracts as $table => $columns) {
            if (!$this->hasTable($table)) {
                throw new RuntimeException("Required core permission table {$table} is missing.");
            }
            foreach ($columns as $column) {
                if (!$this->table($table)->hasColumn($column)) {
                    throw new RuntimeException("{$table}.{$column} is required.");
                }
            }
        }

        $adminParent = $this->singleMenu(
            'sm_admin_menu',
            "code='panel/organizationList' AND module_key IS NULL
             AND status=1 AND delete_time IS NULL",
            'admin organization page',
        );
        $tenantParent = $this->singleMenu(
            'sm_tenant_menu',
            "organization=0 AND code='system' AND module_key IS NULL
             AND status=1 AND delete_time IS NULL",
            'tenant system page',
        );
        if ($adminParent === null || $tenantParent === null) {
            throw new RuntimeException('Core storage quota permission parents are missing.');
        }
        $superAdmins = $this->fetchAll(
            "SELECT id FROM sm_admin_role
              WHERE code='superAdmin' AND status=1 AND delete_time IS NULL ORDER BY id",
        );
        if (count($superAdmins) !== 1) {
            throw new RuntimeException('Exactly one active superAdmin role is required.');
        }
        $superAdminId = (int) $superAdmins[0]['id'];

        $adminPage = $this->singleMenu(
            'sm_admin_menu',
            'code=' . $this->quote(self::ADMIN_PAGE_CODE),
            'admin storage quota page',
        );
        if ($adminPage !== null) {
            $this->assertMenuRow($adminPage, [
                'parent_id' => (string) $adminParent['id'],
                'name' => '存储配额',
                'code' => self::ADMIN_PAGE_CODE,
                'slug' => null,
                'module_key' => null,
                'type' => '2',
                'path' => 'storage-quota',
                'component' => '/storage-quota/index',
                'method' => null,
                'is_hidden' => '0',
                'status' => '1',
                'remark' => self::MENU_REMARK,
                'delete_time' => null,
            ], 'admin storage quota page');
        }
        $adminPageId = $adminPage === null ? null : (int) $adminPage['id'];

        $adminIndex = $this->permissionRow(
            'sm_admin_menu',
            self::ADMIN_INDEX_SLUG,
            $adminPageId,
            '存储配额查看',
            'GET',
        );
        $adminUpdate = $this->permissionRow(
            'sm_admin_menu',
            self::ADMIN_UPDATE_SLUG,
            $adminPageId,
            '存储配额更新',
            'PUT',
        );
        $tenantRead = $this->singleMenu(
            'sm_tenant_menu',
            'slug=' . $this->quote(self::TENANT_READ_SLUG),
            'tenant storage quota permission',
        );
        if ($tenantRead !== null) {
            $this->assertMenuRow($tenantRead, [
                'organization' => '0',
                'parent_id' => (string) $tenantParent['id'],
                'name' => '存储配额读取',
                'code' => null,
                'slug' => self::TENANT_READ_SLUG,
                'module_key' => null,
                'type' => '3',
                'path' => '',
                'component' => '',
                'method' => 'GET',
                'is_hidden' => '1',
                'status' => '1',
                'remark' => self::MENU_REMARK,
                'delete_time' => null,
            ], 'tenant storage quota permission');
        }

        $context = [
            'admin_parent' => (int) $adminParent['id'],
            'tenant_parent' => (int) $tenantParent['id'],
            'super_admin' => $superAdminId,
            'admin_page' => $adminPageId,
            'admin_index' => $adminIndex === null ? null : (int) $adminIndex['id'],
            'admin_update' => $adminUpdate === null ? null : (int) $adminUpdate['id'],
            'tenant_read' => $tenantRead === null ? null : (int) $tenantRead['id'],
        ];
        $this->assertCorePermissionBindings($context, false);

        return $context;
    }

    /** @param array<string,int|null> $context */
    private function assertCorePermissionBindings(array $context, bool $complete): void
    {
        foreach (array_filter([
            $context['admin_page'],
            $context['admin_index'],
            $context['admin_update'],
        ]) as $menuId) {
            $bindings = $this->fetchAll(sprintf(
                'SELECT role_id FROM sm_admin_role_menu WHERE menu_id=%d ORDER BY role_id',
                $menuId,
            ));
            if (count($bindings) > 1
                || ($bindings !== []
                    && (int) $bindings[0]['role_id'] !== $context['super_admin'])
                || ($complete && count($bindings) !== 1)) {
                throw new RuntimeException(
                    'Core storage quota admin permission must belong only to superAdmin.',
                );
            }
        }
        if ($context['tenant_read'] === null) {
            return;
        }
        $tenantMenuId = (int) $context['tenant_read'];
        if ($this->fetchRow(sprintf(
            'SELECT role_id FROM sm_tenant_role_menu WHERE menu_id=%d LIMIT 1',
            $tenantMenuId,
        ))) {
            throw new RuntimeException(
                'Core tenant storage quota permission must use group bindings only.',
            );
        }
        if ($this->fetchRow(sprintf(
            'SELECT group_id FROM sm_tenant_group_menu WHERE menu_id=%d
              GROUP BY group_id HAVING COUNT(*)>1 LIMIT 1',
            $tenantMenuId,
        ))) {
            throw new RuntimeException('Duplicate tenant group storage quota binding.');
        }
        if (!$complete) {
            return;
        }
        $active = $this->fetchRow(
            'SELECT COUNT(*) AS aggregate FROM sm_tenant_group
              WHERE status=1 AND delete_time IS NULL',
        );
        $bound = $this->fetchRow(sprintf(
            'SELECT COUNT(*) AS aggregate FROM sm_tenant_group_menu gm
              INNER JOIN sm_tenant_group g ON g.id=gm.group_id
             WHERE gm.menu_id=%d AND g.status=1 AND g.delete_time IS NULL',
            $tenantMenuId,
        ));
        $all = $this->fetchRow(sprintf(
            'SELECT COUNT(*) AS aggregate FROM sm_tenant_group_menu WHERE menu_id=%d',
            $tenantMenuId,
        ));
        if ((int) ($active['aggregate'] ?? -1) !== (int) ($bound['aggregate'] ?? -2)
            || (int) ($bound['aggregate'] ?? -1) !== (int) ($all['aggregate'] ?? -2)) {
            throw new RuntimeException(
                'Core tenant storage quota permission bindings are incomplete or over-broad.',
            );
        }
    }

    /** @param array<string,int|null> $context */
    private function assertNoUnownedAdminPageChildren(array $context): void
    {
        if ($context['admin_page'] === null) {
            return;
        }
        $ownedChildren = array_values(array_filter([
            $context['admin_index'],
            $context['admin_update'],
        ]));
        $ownedClause = $ownedChildren === []
            ? ''
            : ' AND id NOT IN (' . implode(',', $ownedChildren) . ')';
        if ($this->fetchRow(sprintf(
            'SELECT id FROM sm_admin_menu WHERE parent_id=%d%s LIMIT 1',
            (int) $context['admin_page'],
            $ownedClause,
        ))) {
            throw new RuntimeException(
                'Cannot roll back storage quota permissions while the owned page has an unowned child.',
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function singleMenu(string $table, string $where, string $label): ?array
    {
        $rows = $this->fetchAll("SELECT * FROM {$table} WHERE {$where} ORDER BY id");
        if (count($rows) > 1) {
            throw new RuntimeException("Ambiguous {$label}.");
        }

        return $rows[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    private function permissionRow(
        string $table,
        string $slug,
        ?int $parentId,
        string $name,
        string $method,
    ): ?array {
        $row = $this->singleMenu(
            $table,
            'slug=' . $this->quote($slug),
            $slug,
        );
        if ($row !== null) {
            if ($parentId === null) {
                throw new RuntimeException("Permission {$slug} exists without its owned page.");
            }
            $this->assertMenuRow($row, [
                'parent_id' => (string) $parentId,
                'name' => $name,
                'code' => null,
                'slug' => $slug,
                'module_key' => null,
                'type' => '3',
                'path' => '',
                'component' => '',
                'method' => $method,
                'is_hidden' => '1',
                'status' => '1',
                'remark' => self::MENU_REMARK,
                'delete_time' => null,
            ], $slug);
        }

        return $row;
    }

    /** @param array<string,?string> $expected @param array<string,mixed> $row */
    private function assertMenuRow(
        array $row,
        array $expected,
        string $label,
    ): void {
        foreach ($expected as $column => $value) {
            $actual = $row[$column] ?? null;
            if ($value === null ? $actual !== null : (string) $actual !== $value) {
                throw new RuntimeException("Core menu {$label} has hostile schema drift.");
            }
        }
    }

    private function seedCorePermissions(): void
    {
        $connection = $this->getAdapter()->getConnection();
        $ownsTransaction = $this->beginScopedTransaction(
            $connection,
            'storage_quota_permissions',
        );
        try {
            $context = $this->corePermissionContext();
            if ($context['admin_page'] === null) {
                $this->insertMenu('sm_admin_menu', [
                    'parent_id' => $context['admin_parent'],
                    'name' => '存储配额',
                    'code' => self::ADMIN_PAGE_CODE,
                    'slug' => null,
                    'module_key' => null,
                    'type' => 2,
                    'path' => 'storage-quota',
                    'component' => '/storage-quota/index',
                    'method' => null,
                    'icon' => 'ri:hard-drive-3-line',
                    'sort' => 90,
                    'is_hidden' => 0,
                    'status' => 1,
                    'remark' => self::MENU_REMARK,
                ]);
                $context = $this->corePermissionContext();
            }
            if ($context['admin_index'] === null) {
                $this->insertPermission(
                    'sm_admin_menu',
                    (int) $context['admin_page'],
                    '存储配额查看',
                    self::ADMIN_INDEX_SLUG,
                    'GET',
                    [],
                );
            }
            if ($context['admin_update'] === null) {
                $this->insertPermission(
                    'sm_admin_menu',
                    (int) $context['admin_page'],
                    '存储配额更新',
                    self::ADMIN_UPDATE_SLUG,
                    'PUT',
                    [],
                );
            }
            if ($context['tenant_read'] === null) {
                $this->insertPermission(
                    'sm_tenant_menu',
                    $context['tenant_parent'],
                    '存储配额读取',
                    self::TENANT_READ_SLUG,
                    'GET',
                    ['organization' => 0],
                );
            }
            $context = $this->corePermissionContext();
            foreach ([$context['admin_page'], $context['admin_index'], $context['admin_update']] as $menuId) {
                $this->execute(sprintf(
                    'INSERT INTO sm_admin_role_menu (role_id,menu_id)
                     SELECT %d,%d
                      WHERE NOT EXISTS (
                            SELECT 1 FROM sm_admin_role_menu m
                             WHERE m.role_id=%d AND m.menu_id=%d
                        )',
                    $context['super_admin'],
                    $menuId,
                    $context['super_admin'],
                    $menuId,
                ));
            }
            $tenantMenuId = (int) $context['tenant_read'];
            $this->execute(sprintf(
                'INSERT INTO sm_tenant_group_menu (group_id,menu_id)
                 SELECT g.id,%d FROM sm_tenant_group g
                  WHERE g.status=1 AND g.delete_time IS NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM sm_tenant_group_menu m
                         WHERE m.group_id=g.id AND m.menu_id=%d
                    )',
                $tenantMenuId,
                $tenantMenuId,
            ));
            $this->assertCorePermissionBindings($context, true);
            $this->commitScopedTransaction(
                $connection,
                'storage_quota_permissions',
                $ownsTransaction,
            );
        } catch (Throwable $exception) {
            $this->rollbackScopedTransaction(
                $connection,
                'storage_quota_permissions',
                $ownsTransaction,
            );
            throw $exception;
        }
    }

    /** @param array<string,mixed> $data */
    private function insertMenu(string $table, array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $data['create_time'] = $now;
        $data['update_time'] = $now;
        $this->table($table)->insert($data)->saveData();
    }

    /** @param array<string,mixed> $extra */
    private function insertPermission(
        string $table,
        int $parentId,
        string $name,
        string $slug,
        string $method,
        array $extra,
    ): void {
        $this->insertMenu($table, array_merge($extra, [
            'parent_id' => $parentId,
            'name' => $name,
            'code' => null,
            'slug' => $slug,
            'module_key' => null,
            'type' => 3,
            'path' => '',
            'component' => '',
            'method' => $method,
            'is_hidden' => 1,
            'status' => 1,
            'remark' => self::MENU_REMARK,
        ]));
    }

    /** @param array<string,int|null> $context */
    private function removeCorePermissions(array $context): void
    {
        $adminIds = array_values(array_filter([
            $context['admin_page'],
            $context['admin_index'],
            $context['admin_update'],
        ]));
        $tenantIds = array_values(array_filter([$context['tenant_read']]));
        $connection = $this->getAdapter()->getConnection();
        $ownsTransaction = $this->beginScopedTransaction(
            $connection,
            'storage_quota_remove_permissions',
        );
        try {
            if ($adminIds !== []) {
                $ids = implode(',', $adminIds);
                $this->execute("DELETE FROM sm_admin_role_menu WHERE menu_id IN ({$ids})");
                $this->execute("DELETE FROM sm_admin_menu WHERE id IN ({$ids})");
            }
            if ($tenantIds !== []) {
                $ids = implode(',', $tenantIds);
                $this->execute("DELETE FROM sm_tenant_group_menu WHERE menu_id IN ({$ids})");
                $this->execute("DELETE FROM sm_tenant_menu WHERE id IN ({$ids})");
            }
            $this->commitScopedTransaction(
                $connection,
                'storage_quota_remove_permissions',
                $ownsTransaction,
            );
        } catch (Throwable $exception) {
            $this->rollbackScopedTransaction(
                $connection,
                'storage_quota_remove_permissions',
                $ownsTransaction,
            );
            throw $exception;
        }
    }

    private function createReservationTable(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE `sm_im_upload_reservation` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization` int(11) UNSIGNED NOT NULL,
  `upload_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `intent_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_id` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `storage_path` varchar(512) NOT NULL,
  `user_id` varchar(64) NOT NULL,
  `client_family` varchar(16) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `size_bytes` bigint(20) UNSIGNED NOT NULL,
  `mime_type` varchar(255) NOT NULL DEFAULT 'application/octet-stream',
  `extension` varchar(32) NOT NULL,
  `state` varchar(24) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `upload_lease_token` char(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `upload_lease_expires_at` datetime(6) NULL DEFAULT NULL,
  `cleanup_lease_token` char(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `cleanup_lease_expires_at` datetime(6) NULL DEFAULT NULL,
  `cleanup_attempts` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `cleanup_next_at` datetime(6) NULL DEFAULT NULL,
  `cleanup_error` varchar(255) NOT NULL DEFAULT '',
  `confirmed_at` datetime(6) NULL DEFAULT NULL,
  `released_at` datetime(6) NULL DEFAULT NULL,
  `release_reason` varchar(32) NOT NULL DEFAULT '',
  `version` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `create_time` datetime(6) NOT NULL,
  `update_time` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uni_org_upload` (`organization`,`upload_id`),
  UNIQUE KEY `uni_org_idempotency` (`organization`,`idempotency_key`),
  UNIQUE KEY `uni_org_file` (`organization`,`file_id`),
  UNIQUE KEY `uni_org_storage_path` (`organization`,`storage_path`),
  KEY `idx_cleanup` (`state`,`cleanup_next_at`,`cleanup_lease_expires_at`,`id`),
  KEY `idx_expiry` (`state`,`expires_at`,`id`),
  KEY `idx_upload_lease` (`state`,`upload_lease_expires_at`,`id`),
  KEY `idx_object_uploaded` (`state`,`update_time`,`id`),
  KEY `idx_org_state` (`organization`,`state`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Server authoritative IM upload reservations';
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE sm_im_upload_reservation
  ADD CONSTRAINT chk_im_upload_reservation_identity CHECK (
    organization > 0
    AND upload_id = LOWER(upload_id)
    AND upload_id REGEXP '^[0-9a-f]{64}$'
    AND idempotency_key = LOWER(idempotency_key)
    AND idempotency_key REGEXP '^[0-9a-f]{32}$'
    AND intent_hash = LOWER(intent_hash)
    AND intent_hash REGEXP '^[0-9a-f]{64}$'
    AND file_id = LOWER(file_id)
    AND file_id REGEXP '^[0-9a-f]{40}$'
  ),
  ADD CONSTRAINT chk_im_upload_reservation_positive CHECK (
    size_bytes > 0 AND version > 0
  ),
  ADD CONSTRAINT chk_im_upload_reservation_state CHECK (
    state IN ('reserved','uploading','object_uploaded','cleanup_pending','confirmed','released')
  ),
  ADD CONSTRAINT chk_im_upload_reservation_state_facts CHECK (
    (upload_lease_token IS NULL) = (upload_lease_expires_at IS NULL)
    AND (cleanup_lease_token IS NULL) = (cleanup_lease_expires_at IS NULL)
    AND (
      (state = 'reserved'
       AND upload_lease_token IS NULL AND cleanup_lease_token IS NULL
       AND confirmed_at IS NULL AND released_at IS NULL AND release_reason = '')
      OR (state = 'uploading'
       AND upload_lease_token IS NOT NULL AND cleanup_lease_token IS NULL
       AND confirmed_at IS NULL AND released_at IS NULL AND release_reason = '')
      OR (state = 'object_uploaded'
       AND upload_lease_token IS NULL AND cleanup_lease_token IS NULL
       AND confirmed_at IS NULL AND released_at IS NULL AND release_reason = ''
       AND expires_at = '9999-12-31 23:59:59.999999')
      OR (state = 'cleanup_pending'
       AND upload_lease_token IS NULL
       AND confirmed_at IS NULL AND released_at IS NULL AND release_reason = '')
      OR (state = 'confirmed'
       AND upload_lease_token IS NULL AND cleanup_lease_token IS NULL
       AND confirmed_at IS NOT NULL AND released_at IS NULL AND release_reason = ''
       AND expires_at = '9999-12-31 23:59:59.999999')
      OR (state = 'released'
       AND upload_lease_token IS NULL AND cleanup_lease_token IS NULL
       AND confirmed_at IS NULL AND released_at IS NOT NULL AND release_reason <> '')
    )
  );
SQL);
    }

    private function createCleanupCursorTable(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE `sm_im_upload_cleanup_cursor` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `last_reservation_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `update_time` datetime(6) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Upload cleanup scan cursor'
SQL);
        $this->execute(
            'INSERT INTO sm_im_upload_cleanup_cursor (id,last_reservation_id,update_time)
             VALUES (1,0,NOW(6))',
        );
    }

    private function assertCleanupCursorContract(): void
    {
        $this->assertTableOptions(
            self::CLEANUP_CURSOR_TABLE,
            'InnoDB',
            'utf8mb4_general_ci',
            'Upload cleanup scan cursor',
        );
        $this->assertColumnContract(self::CLEANUP_CURSOR_TABLE, [
            'id' => ['tinyint unsigned', false, null, '', null, null],
            'last_reservation_id' => ['bigint unsigned', false, '0', '', null, null],
            'update_time' => ['datetime(6)', false, null, '', null, null],
        ]);
        $this->assertIndexContract(self::CLEANUP_CURSOR_TABLE, [
            'PRIMARY' => [true, ['id']],
        ]);
        $this->assertConstraintContract(
            self::CLEANUP_CURSOR_TABLE,
            ['PRIMARY' => ['PRIMARY KEY', ['id']]],
            [],
        );
        $this->assertNoTriggers(self::CLEANUP_CURSOR_TABLE);
        $rows = $this->fetchAll(
            'SELECT id,last_reservation_id,update_time
               FROM sm_im_upload_cleanup_cursor ORDER BY id',
        );
        if (count($rows) !== 1
            || (int) ($rows[0]['id'] ?? 0) !== 1
            || $this->nonNegativeInteger(
                $rows[0]['last_reservation_id'] ?? null,
                'upload cleanup cursor',
            ) < 0
            || strtotime((string) ($rows[0]['update_time'] ?? '')) === false) {
            throw new RuntimeException('Upload cleanup cursor row is not canonical.');
        }
    }

    private function assertCentralQuotaContract(): void
    {
        $this->assertTableOptions('sm_tenant_quota', 'InnoDB', 'utf8mb4_general_ci', '');
        $this->assertColumnContract('sm_tenant_quota', [
            'id' => ['bigint unsigned', false, null, 'auto_increment', null, null],
            'organization' => ['int unsigned', true, null, '', null, null],
            'quota_key' => ['varchar(64)', true, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'quota_value' => ['bigint unsigned', true, '0', '', null, null],
            'used_value' => ['bigint unsigned', true, '0', '', null, null],
            'source' => ['varchar(24)', true, 'manual', '', 'utf8mb4', 'utf8mb4_general_ci'],
            'start_at' => ['datetime', true, null, '', null, null],
            'end_at' => ['datetime', true, null, '', null, null],
            'status' => ['varchar(16)', true, 'active', '', 'utf8mb4', 'utf8mb4_general_ci'],
            'order_no' => ['varchar(64)', true, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'remark' => ['varchar(255)', true, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'version' => ['int unsigned', true, '1', '', null, null],
            'created_by' => ['int', true, null, '', null, null],
            'updated_by' => ['int', true, null, '', null, null],
            'create_time' => ['datetime', true, null, '', null, null],
            'update_time' => ['datetime', true, null, '', null, null],
            'delete_time' => ['datetime', true, null, '', null, null],
        ]);
        $this->assertIndexContract('sm_tenant_quota', [
            'PRIMARY' => [true, ['id']],
            'uni_organization_quota_key' => [true, ['organization', 'quota_key']],
            'idx_organization_status' => [false, ['organization', 'status']],
        ]);
        if ($this->fetchRow(
            'SELECT organization,quota_key
               FROM sm_tenant_quota
              GROUP BY organization,quota_key
             HAVING COUNT(*)<>1
              LIMIT 1',
        )) {
            throw new RuntimeException('sm_tenant_quota contains duplicate authority rows.');
        }
    }

    private function assertReservationContract(): void
    {
        $this->assertTableOptions(
            self::RESERVATION_TABLE,
            'InnoDB',
            'utf8mb4_general_ci',
            'Server authoritative IM upload reservations',
        );
        $this->assertColumnContract(self::RESERVATION_TABLE, [
            'id' => ['bigint unsigned', false, null, 'auto_increment', null, null],
            'organization' => ['int unsigned', false, null, '', null, null],
            'upload_id' => ['char(64)', false, null, '', 'ascii', 'ascii_bin'],
            'idempotency_key' => ['char(32)', false, null, '', 'ascii', 'ascii_bin'],
            'intent_hash' => ['char(64)', false, null, '', 'ascii', 'ascii_bin'],
            'file_id' => ['char(40)', false, null, '', 'ascii', 'ascii_bin'],
            'storage_path' => ['varchar(512)', false, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'user_id' => ['varchar(64)', false, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'client_family' => ['varchar(16)', false, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'kind' => ['varchar(16)', false, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'filename' => ['varchar(255)', false, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'size_bytes' => ['bigint unsigned', false, null, '', null, null],
            'mime_type' => [
                'varchar(255)',
                false,
                'application/octet-stream',
                '',
                'utf8mb4',
                'utf8mb4_general_ci',
            ],
            'extension' => ['varchar(32)', false, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'state' => ['varchar(24)', false, null, '', 'utf8mb4', 'utf8mb4_general_ci'],
            'expires_at' => ['datetime(6)', false, null, '', null, null],
            'upload_lease_token' => ['char(64)', true, null, '', 'ascii', 'ascii_bin'],
            'upload_lease_expires_at' => ['datetime(6)', true, null, '', null, null],
            'cleanup_lease_token' => ['char(64)', true, null, '', 'ascii', 'ascii_bin'],
            'cleanup_lease_expires_at' => ['datetime(6)', true, null, '', null, null],
            'cleanup_attempts' => ['int unsigned', false, '0', '', null, null],
            'cleanup_next_at' => ['datetime(6)', true, null, '', null, null],
            'cleanup_error' => ['varchar(255)', false, '', '', 'utf8mb4', 'utf8mb4_general_ci'],
            'confirmed_at' => ['datetime(6)', true, null, '', null, null],
            'released_at' => ['datetime(6)', true, null, '', null, null],
            'release_reason' => ['varchar(32)', false, '', '', 'utf8mb4', 'utf8mb4_general_ci'],
            'version' => ['int unsigned', false, '1', '', null, null],
            'create_time' => ['datetime(6)', false, null, '', null, null],
            'update_time' => ['datetime(6)', false, null, '', null, null],
        ]);
        $this->assertIndexContract(self::RESERVATION_TABLE, [
            'PRIMARY' => [true, ['id']],
            'uni_org_upload' => [true, ['organization', 'upload_id']],
            'uni_org_idempotency' => [true, ['organization', 'idempotency_key']],
            'uni_org_file' => [true, ['organization', 'file_id']],
            'uni_org_storage_path' => [true, ['organization', 'storage_path']],
            'idx_cleanup' => [
                false,
                ['state', 'cleanup_next_at', 'cleanup_lease_expires_at', 'id'],
            ],
            'idx_expiry' => [false, ['state', 'expires_at', 'id']],
            'idx_object_uploaded' => [false, ['state', 'update_time', 'id']],
            'idx_org_state' => [false, ['organization', 'state', 'id']],
            'idx_upload_lease' => [false, ['state', 'upload_lease_expires_at', 'id']],
        ]);
        $this->assertConstraintContract(self::RESERVATION_TABLE, [
            'PRIMARY' => ['PRIMARY KEY', ['id']],
            'uni_org_file' => ['UNIQUE', ['organization', 'file_id']],
            'uni_org_idempotency' => ['UNIQUE', ['organization', 'idempotency_key']],
            'uni_org_storage_path' => ['UNIQUE', ['organization', 'storage_path']],
            'uni_org_upload' => ['UNIQUE', ['organization', 'upload_id']],
        ], $this->reservationCheckContract());
        $this->assertNoTriggers(self::RESERVATION_TABLE);
    }

    /**
     * @param array<string,array{0:'PRIMARY KEY'|'UNIQUE',1:list<string>}> $expectedKeys
     * @param array<string,string> $expectedChecks
     */
    private function assertConstraintContract(
        string $table,
        array $expectedKeys,
        array $expectedChecks,
    ): void {
        ksort($expectedChecks);
        $expectedTypes = [];
        foreach ($expectedKeys as $name => [$type]) {
            $expectedTypes[$name] = $type;
        }
        foreach ($expectedChecks as $name => $_clause) {
            $expectedTypes[$name] = 'CHECK';
        }
        ksort($expectedTypes);

        $constraintRows = $this->fetchAll(sprintf(
            'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED
               FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=%s
              ORDER BY CONSTRAINT_NAME',
            $this->quote($table),
        ));
        $actualTypes = [];
        foreach ($constraintRows as $row) {
            $name = (string) ($row['CONSTRAINT_NAME'] ?? '');
            if ($name === '' || isset($actualTypes[$name])
                || (string) ($row['ENFORCED'] ?? '') !== 'YES') {
                throw new RuntimeException(
                    "{$table} contains an invalid or non-enforced table constraint.",
                );
            }
            $actualTypes[$name] = (string) ($row['CONSTRAINT_TYPE'] ?? '');
        }
        ksort($actualTypes);
        if ($actualTypes !== $expectedTypes) {
            throw new RuntimeException(
                "{$table} constraint set does not match the authoritative schema.",
            );
        }

        $keyRows = $this->fetchAll(sprintf(
            'SELECT CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,
                    POSITION_IN_UNIQUE_CONSTRAINT,REFERENCED_TABLE_SCHEMA,
                    REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=%s
              ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION',
            $this->quote($table),
        ));
        $actualKeys = [];
        foreach ($keyRows as $row) {
            $name = (string) ($row['CONSTRAINT_NAME'] ?? '');
            if (!isset($expectedKeys[$name])
                || $row['POSITION_IN_UNIQUE_CONSTRAINT'] !== null
                || $row['REFERENCED_TABLE_SCHEMA'] !== null
                || $row['REFERENCED_TABLE_NAME'] !== null
                || $row['REFERENCED_COLUMN_NAME'] !== null) {
                throw new RuntimeException(
                    "{$table}.{$name} is not an owned non-referencing key constraint.",
                );
            }
            $actualKeys[$name]['columns'][] = (string) ($row['COLUMN_NAME'] ?? '');
            $actualKeys[$name]['ordinals'][] = (int) ($row['ORDINAL_POSITION'] ?? 0);
        }
        ksort($actualKeys);
        $expectedKeyNames = array_keys($expectedKeys);
        sort($expectedKeyNames, SORT_STRING);
        if (array_keys($actualKeys) !== $expectedKeyNames) {
            throw new RuntimeException(
                "{$table} key constraint set does not match the authoritative schema.",
            );
        }
        foreach ($expectedKeys as $name => [, $columns]) {
            if ($actualKeys[$name]['columns'] !== $columns
                || $actualKeys[$name]['ordinals'] !== range(1, count($columns))) {
                throw new RuntimeException(
                    "{$table}.{$name} does not match the authoritative key constraint columns.",
                );
            }
        }

        $checkRows = $this->fetchAll(sprintf(
            'SELECT tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE,tc.ENFORCED
               FROM information_schema.TABLE_CONSTRAINTS tc
               JOIN information_schema.CHECK_CONSTRAINTS cc
                 ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA
                AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME
              WHERE tc.CONSTRAINT_SCHEMA=DATABASE()
                AND tc.TABLE_NAME=%s AND tc.CONSTRAINT_TYPE=\'CHECK\'
              ORDER BY tc.CONSTRAINT_NAME',
            $this->quote($table),
        ));
        if (array_column($checkRows, 'CONSTRAINT_NAME') !== array_keys($expectedChecks)) {
            throw new RuntimeException(
                "{$table} CHECK set does not match the authoritative schema.",
            );
        }
        foreach ($checkRows as $row) {
            $name = (string) ($row['CONSTRAINT_NAME'] ?? '');
            if ((string) ($row['ENFORCED'] ?? '') !== 'YES'
                || $this->normalizeCheck((string) ($row['CHECK_CLAUSE'] ?? ''))
                    !== $this->normalizeCheck($expectedChecks[$name])) {
                throw new RuntimeException(
                    "{$table}.{$name} does not match the authoritative CHECK contract.",
                );
            }
        }
    }

    private function assertNoTriggers(string $table): void
    {
        $triggers = $this->fetchAll(sprintf(
            'SELECT TRIGGER_NAME,EVENT_MANIPULATION,ACTION_TIMING,ACTION_ORIENTATION,
                    ACTION_STATEMENT
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=%s
              ORDER BY TRIGGER_NAME',
            $this->quote($table),
        ));
        if ($triggers !== []) {
            throw new RuntimeException(
                "{$table} must not define triggers in the authoritative schema.",
            );
        }
    }

    /** @return array<string,string> */
    private function reservationCheckContract(): array
    {
        return [
            'chk_im_upload_reservation_identity' => <<<'SQL'
organization > 0
AND upload_id = LOWER(upload_id)
AND upload_id REGEXP '^[0-9a-f]{64}$'
AND idempotency_key = LOWER(idempotency_key)
AND idempotency_key REGEXP '^[0-9a-f]{32}$'
AND intent_hash = LOWER(intent_hash)
AND intent_hash REGEXP '^[0-9a-f]{64}$'
AND file_id = LOWER(file_id)
AND file_id REGEXP '^[0-9a-f]{40}$'
SQL,
            'chk_im_upload_reservation_positive' => <<<'SQL'
size_bytes > 0 AND version > 0
SQL,
            'chk_im_upload_reservation_state' => <<<'SQL'
state IN ('reserved','uploading','object_uploaded','cleanup_pending','confirmed','released')
SQL,
            'chk_im_upload_reservation_state_facts' => <<<'SQL'
(upload_lease_token IS NULL) = (upload_lease_expires_at IS NULL)
AND (cleanup_lease_token IS NULL) = (cleanup_lease_expires_at IS NULL)
AND (
  (state = 'reserved'
   AND upload_lease_token IS NULL AND cleanup_lease_token IS NULL
   AND confirmed_at IS NULL AND released_at IS NULL AND release_reason = '')
  OR (state = 'uploading'
   AND upload_lease_token IS NOT NULL AND cleanup_lease_token IS NULL
   AND confirmed_at IS NULL AND released_at IS NULL AND release_reason = '')
  OR (state = 'object_uploaded'
   AND upload_lease_token IS NULL AND cleanup_lease_token IS NULL
   AND confirmed_at IS NULL AND released_at IS NULL AND release_reason = ''
   AND expires_at = '9999-12-31 23:59:59.999999')
  OR (state = 'cleanup_pending'
   AND upload_lease_token IS NULL
   AND confirmed_at IS NULL AND released_at IS NULL AND release_reason = '')
  OR (state = 'confirmed'
   AND upload_lease_token IS NULL AND cleanup_lease_token IS NULL
   AND confirmed_at IS NOT NULL AND released_at IS NULL AND release_reason = ''
   AND expires_at = '9999-12-31 23:59:59.999999')
  OR (state = 'released'
   AND upload_lease_token IS NULL AND cleanup_lease_token IS NULL
   AND confirmed_at IS NULL AND released_at IS NOT NULL AND release_reason <> '')
)
SQL,
        ];
    }

    private function normalizeCheck(string $clause): string
    {
        $clause = $this->replaceCheckStringLiterals($clause);
        $literalPattern = "\x1fl[0-9a-f]*\x1f";
        $clause = preg_replace_callback(
            "/regexp_like\s*\(\s*[\x60]?([a-z0-9_]+)[\x60]?\s*,\s*($literalPattern)"
            . "\s*(?:,\s*\x1fl63\x1f)?\s*\)/i",
            static fn (array $match): string => $match[1] . ' regexp ' . $match[2],
            $clause,
        ) ?? $clause;

        return $this->serializeCheckNode($this->parseCheckExpression($clause));
    }

    private function replaceCheckStringLiterals(string $expression): string
    {
        if (str_contains($expression, "\x1f")) {
            throw new RuntimeException('CHECK expression contains a reserved normalization byte.');
        }
        $normalized = '';
        $length = strlen($expression);
        for ($offset = 0; $offset < $length;) {
            $openingOffset = null;
            $metadata = false;
            $introducer = null;
            if ($expression[$offset] === '_'
                && preg_match('/\A_[a-z0-9]+/i', substr($expression, $offset), $match) === 1) {
                $candidate = $offset + strlen($match[0]);
                if (($expression[$candidate] ?? '') === "'") {
                    $openingOffset = $candidate;
                    $introducer = strtolower($match[0]);
                } elseif (substr($expression, $candidate, 2) === "\\'") {
                    $openingOffset = $candidate;
                    $metadata = true;
                    $introducer = strtolower($match[0]);
                }
            } elseif ($expression[$offset] === "'") {
                $openingOffset = $offset;
            } elseif (substr($expression, $offset, 2) === "\\'") {
                $openingOffset = $offset;
                $metadata = true;
            }
            if ($openingOffset === null) {
                $normalized .= $expression[$offset++];
                continue;
            }
            // MySQL 8 may rewrite REGEXP literals to _ascii after an otherwise
            // unrelated ALTER TABLE rebuild, while text-state literals remain
            // _utf8mb4. Both are metadata spellings of the owned ASCII payloads.
            if ($introducer !== null
                && !in_array($introducer, ['_ascii', '_utf8mb4'], true)) {
                throw new RuntimeException(
                    "Unsupported character set introducer in CHECK expression: {$introducer}",
                );
            }
            [$literal, $offset] = $metadata
                ? $this->readMetadataCheckLiteral($expression, $openingOffset)
                : $this->readSqlCheckLiteral($expression, $openingOffset);
            $normalized .= "\x1fl" . bin2hex($literal) . "\x1f";
        }

        return $normalized;
    }

    /** @return array{string,int} */
    private function readSqlCheckLiteral(string $expression, int $openingOffset): array
    {
        $literal = '';
        $length = strlen($expression);
        for ($offset = $openingOffset + 1; $offset < $length;) {
            $character = $expression[$offset];
            if ($character === "'") {
                if (($expression[$offset + 1] ?? '') === "'") {
                    $literal .= "'";
                    $offset += 2;
                    continue;
                }

                return [$literal, $offset + 1];
            }
            if ($character !== '\\') {
                $literal .= $character;
                ++$offset;
                continue;
            }
            if ($offset + 1 >= $length) {
                break;
            }
            $escaped = $expression[$offset + 1];
            $literal .= match ($escaped) {
                '0' => "\0",
                'b' => "\x08",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'Z' => "\x1a",
                '%', '_' => '\\' . $escaped,
                default => $escaped,
            };
            $offset += 2;
        }

        throw new RuntimeException('Unterminated SQL literal in CHECK expression.');
    }

    /** @return array{string,int} */
    private function readMetadataCheckLiteral(string $expression, int $openingOffset): array
    {
        if (substr($expression, $openingOffset, 2) !== "\\'") {
            throw new RuntimeException('Malformed MySQL metadata CHECK literal.');
        }
        $literal = '';
        $length = strlen($expression);
        for ($offset = $openingOffset + 2; $offset < $length;) {
            if ($expression[$offset] === "'") {
                throw new RuntimeException('Unescaped quote in MySQL metadata CHECK literal.');
            }
            if ($expression[$offset] !== '\\') {
                $literal .= $expression[$offset++];
                continue;
            }
            $runStart = $offset;
            while ($offset < $length && $expression[$offset] === '\\') {
                ++$offset;
            }
            $backslashes = $offset - $runStart;
            if (($expression[$offset] ?? '') !== "'") {
                if ($backslashes % 4 !== 0) {
                    throw new RuntimeException('Malformed backslash run in MySQL metadata CHECK literal.');
                }
                $literal .= str_repeat('\\', intdiv($backslashes, 4));
                continue;
            }
            $remainder = $backslashes % 4;
            if ($remainder === 1) {
                $literal .= str_repeat('\\', intdiv($backslashes - 1, 4));
                return [$literal, $offset + 1];
            }
            if ($remainder === 3) {
                $literal .= str_repeat('\\', intdiv($backslashes - 3, 4)) . "'";
                ++$offset;
                continue;
            }
            throw new RuntimeException('Malformed quoted byte in MySQL metadata CHECK literal.');
        }

        throw new RuntimeException('Unterminated MySQL metadata CHECK literal.');
    }

    /**
     * @return array{operator:'and'|'or',children:list<array>}|array{atom:string}
     */
    private function parseCheckExpression(string $expression): array
    {
        $expression = $this->stripCheckWrappingParentheses(trim($expression));
        if ($expression === '') {
            throw new RuntimeException('Empty CHECK expression cannot be normalized.');
        }
        foreach (['or', 'and'] as $operator) {
            $parts = $this->splitCheckExpression($expression, $operator);
            if (count($parts) < 2) {
                continue;
            }
            $children = [];
            foreach ($parts as $part) {
                $child = $this->parseCheckExpression($part);
                if (($child['operator'] ?? null) === $operator) {
                    array_push($children, ...$child['children']);
                } else {
                    $children[] = $child;
                }
            }

            return ['operator' => $operator, 'children' => $children];
        }

        return ['atom' => $this->normalizeCheckAtom($expression)];
    }

    private function normalizeCheckAtom(string $atom): string
    {
        $normalized = '';
        $quote = null;
        $length = strlen($atom);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $atom[$offset];
            if ($quote !== null) {
                $normalized .= $character;
                if ($character === '\\' && $offset + 1 < $length) {
                    $normalized .= $atom[++$offset];
                    continue;
                }
                if ($character !== $quote) {
                    continue;
                }
                if ($offset + 1 < $length && $atom[$offset + 1] === $quote) {
                    $normalized .= $atom[++$offset];
                    continue;
                }
                $quote = null;
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $normalized .= $character;
                continue;
            }
            if ($character === '`' || ctype_space($character)) {
                continue;
            }
            $normalized .= strtolower($character);
        }
        if ($quote !== null) {
            throw new RuntimeException('Unbalanced CHECK atom quoting.');
        }

        return $normalized;
    }

    private function stripCheckWrappingParentheses(string $expression): string
    {
        while ($expression !== ''
            && $expression[0] === '('
            && $this->checkClosingParenthesis($expression, 0) === strlen($expression) - 1) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    /** @return list<string> */
    private function splitCheckExpression(string $expression, string $operator): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $length = strlen($expression);
        $operatorLength = strlen($operator);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $expression[$offset];
            if ($quote !== null) {
                if ($character === '\\') {
                    ++$offset;
                    continue;
                }
                if ($character !== $quote) {
                    continue;
                }
                if ($offset + 1 < $length && $expression[$offset + 1] === $quote) {
                    ++$offset;
                    continue;
                }
                $quote = null;
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }
            if ($character === '(') {
                ++$depth;
                continue;
            }
            if ($character === ')') {
                --$depth;
                if ($depth < 0) {
                    throw new RuntimeException('Unbalanced CHECK expression parentheses.');
                }
                continue;
            }
            if ($depth !== 0
                || strncasecmp(
                    substr($expression, $offset, $operatorLength),
                    $operator,
                    $operatorLength,
                ) !== 0) {
                continue;
            }
            $before = $offset === 0 ? '' : $expression[$offset - 1];
            $afterOffset = $offset + $operatorLength;
            $after = $afterOffset >= $length ? '' : $expression[$afterOffset];
            if (($before !== '' && preg_match('/[a-z0-9_]/i', $before) === 1)
                || ($after !== '' && preg_match('/[a-z0-9_]/i', $after) === 1)) {
                continue;
            }
            $part = trim(substr($expression, $start, $offset - $start));
            if ($part === '') {
                throw new RuntimeException('Malformed CHECK boolean expression.');
            }
            $parts[] = $part;
            $offset += $operatorLength - 1;
            $start = $offset + 1;
        }
        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException('Unbalanced CHECK expression quoting or parentheses.');
        }
        if ($parts === []) {
            return [$expression];
        }
        $part = trim(substr($expression, $start));
        if ($part === '') {
            throw new RuntimeException('Malformed CHECK boolean expression.');
        }
        $parts[] = $part;

        return $parts;
    }

    private function checkClosingParenthesis(string $expression, int $openingOffset): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($expression);
        for ($offset = $openingOffset; $offset < $length; ++$offset) {
            $character = $expression[$offset];
            if ($quote !== null) {
                if ($character === '\\') {
                    ++$offset;
                    continue;
                }
                if ($character !== $quote) {
                    continue;
                }
                if ($offset + 1 < $length && $expression[$offset + 1] === $quote) {
                    ++$offset;
                    continue;
                }
                $quote = null;
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }
            if ($character === '(') {
                ++$depth;
            } elseif ($character === ')' && --$depth === 0) {
                return $offset;
            }
        }
        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException('Unbalanced CHECK expression quoting or parentheses.');
        }

        return null;
    }

    /** @param array{operator?:'and'|'or',children?:list<array>,atom?:string} $node */
    private function serializeCheckNode(array $node): string
    {
        if (isset($node['atom'])) {
            return 'atom(' . $node['atom'] . ')';
        }
        if (!isset($node['operator'], $node['children']) || $node['children'] === []) {
            throw new RuntimeException('Invalid CHECK boolean AST.');
        }
        $children = array_map(
            fn (array $child): string => $this->serializeCheckNode($child),
            $node['children'],
        );
        sort($children, SORT_STRING);

        return $node['operator'] . '(' . implode(',', $children) . ')';
    }

    /**
     * @param array<string,array{0:string,1:bool,2:?string,3:string,4:?string,5:?string}> $expected
     */
    private function assertColumnContract(string $table, array $expected): void
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,
                    CHARACTER_SET_NAME,COLLATION_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s
              ORDER BY ORDINAL_POSITION',
            $this->quote($table),
        ));
        if (count($rows) !== count($expected)
            || array_column($rows, 'COLUMN_NAME') !== array_keys($expected)) {
            throw new RuntimeException("{$table} column set does not match the authoritative schema.");
        }
        foreach ($rows as $row) {
            $name = (string) $row['COLUMN_NAME'];
            [$type, $nullable, $default, $extra, $charset, $collation] = $expected[$name];
            $actualDefault = $row['COLUMN_DEFAULT'];
            if ($actualDefault !== null) {
                $actualDefault = (string) $actualDefault;
            }
            if ($this->canonicalType((string) $row['COLUMN_TYPE']) !== $type
                || ((string) $row['IS_NULLABLE'] === 'YES') !== $nullable
                || $actualDefault !== $default
                || strtolower((string) $row['EXTRA']) !== $extra
                || ($row['CHARACTER_SET_NAME'] === null
                    ? null
                    : (string) $row['CHARACTER_SET_NAME']) !== $charset
                || ($row['COLLATION_NAME'] === null
                    ? null
                    : (string) $row['COLLATION_NAME']) !== $collation) {
                throw new RuntimeException(
                    "{$table}.{$name} does not match the authoritative column contract.",
                );
            }
        }
    }

    /**
     * @param array<string,array{0:bool,1:list<string>}> $expected
     */
    private function assertIndexContract(string $table, array $expected): void
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,
                    INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s
              ORDER BY INDEX_NAME,SEQ_IN_INDEX',
            $this->quote($table),
        ));
        $actual = [];
        foreach ($rows as $row) {
            $name = (string) $row['INDEX_NAME'];
            if ((string) $row['INDEX_TYPE'] !== 'BTREE'
                || (string) $row['IS_VISIBLE'] !== 'YES'
                || $row['SUB_PART'] !== null
                || $row['EXPRESSION'] !== null
                || (string) $row['COLLATION'] !== 'A') {
                throw new RuntimeException(
                    "{$table}.{$name} is not a full-width visible ascending BTREE index.",
                );
            }
            $actual[$name]['unique'] = (int) $row['NON_UNIQUE'] === 0;
            $actual[$name]['columns'][] = (string) $row['COLUMN_NAME'];
            $actual[$name]['sequences'][] = (int) $row['SEQ_IN_INDEX'];
        }
        ksort($actual);
        ksort($expected);
        if (array_keys($actual) !== array_keys($expected)) {
            throw new RuntimeException("{$table} index set does not match the authoritative schema.");
        }
        foreach ($expected as $name => [$unique, $columns]) {
            if ($actual[$name]['unique'] !== $unique
                || $actual[$name]['columns'] !== $columns
                || $actual[$name]['sequences'] !== range(1, count($columns))) {
                throw new RuntimeException(
                    "{$table}.{$name} does not match the authoritative index contract.",
                );
            }
        }
    }

    private function assertTableOptions(
        string $table,
        string $engine,
        string $collation,
        string $comment,
    ): void {
        $row = $this->fetchRow(sprintf(
            'SELECT ENGINE,TABLE_COLLATION,TABLE_COMMENT
               FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $this->quote($table),
        ));
        if (!$row
            || (string) $row['ENGINE'] !== $engine
            || (string) $row['TABLE_COLLATION'] !== $collation
            || (string) $row['TABLE_COMMENT'] !== $comment) {
            throw new RuntimeException(
                "{$table} table options do not match the authoritative schema.",
            );
        }
    }

    private function canonicalType(string $type): string
    {
        return strtolower((string) preg_replace(
            '/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/i',
            '$1',
            $type,
        ));
    }

    /**
     * Validate the complete legacy/physical state before performing any writes.
     *
     * @return list<array{operation:string,id?:int,organization?:int,maximum?:int,used:int}>
     */
    private function storageQuotaPlan(bool $lock): array
    {
        $physical = $this->physicalUsageByOrganization();
        /** @var array<int,int>|null $legacyMax */
        $legacyMax = null;
        $known = [];
        $plan = [];
        foreach ($this->fetchAll('SELECT id FROM sm_system_organization ORDER BY id') as $organizationRow) {
            $organization = $this->positiveInteger(
                $organizationRow['id'] ?? null,
                'organization id',
            );
            $known[$organization] = true;
            $used = $physical[$organization] ?? 0;
            $row = $this->fetchRow(sprintf(
                'SELECT * FROM sm_tenant_quota
                  WHERE organization=%d AND quota_key=%s LIMIT 1%s',
                $organization,
                $this->quote(self::QUOTA_KEY),
                $lock ? ' FOR UPDATE' : '',
            ));
            if ($row) {
                $this->assertQuotaRow($row, $used);
                $plan[] = ['operation' => 'update', 'id' => (int) $row['id'], 'used' => $used];
                continue;
            }
            // A safe re-run after down already has complete central rows and
            // must not depend on a module table that may have since adopted the
            // post-split policy-only schema. Legacy projection is consulted only
            // when a central row genuinely needs initial creation.
            $legacyMax ??= $this->legacyStorageMaximums();
            $maximum = $legacyMax[$organization] ?? 0;
            if ($maximum > 0 && $maximum < $used) {
                throw new RuntimeException("Legacy storage maximum is below usage for organization {$organization}.");
            }
            $plan[] = [
                'operation' => 'insert',
                'organization' => $organization,
                'maximum' => $maximum,
                'used' => $used,
            ];
        }
        foreach (array_keys($physical) as $organization) {
            if (!isset($known[$organization])) {
                throw new RuntimeException("Upload assets reference unknown organization {$organization}.");
            }
        }
        return $plan;
    }

    private function applyStorageQuotaPlan(): void
    {
        $connection = $this->getAdapter()->getConnection();
        $ownsTransaction = $this->beginScopedTransaction(
            $connection,
            'storage_quota_central_dml',
        );
        try {
            $plan = $this->storageQuotaPlan(true);
            $dmlCount = 0;
            foreach ($plan as $action) {
                if ($action['operation'] === 'update') {
                    $row = $this->fetchRow(sprintf(
                        'SELECT used_value FROM sm_tenant_quota WHERE id=%d FOR UPDATE',
                        (int) $action['id'],
                    ));
                    if (!$row) {
                        throw new RuntimeException(
                            'Storage quota authority disappeared during migration.',
                        );
                    }
                    if ($this->nonNegativeInteger(
                        $row['used_value'] ?? null,
                        'storage quota used_value',
                    ) === $action['used']) {
                        continue;
                    }
                    $this->execute(sprintf(
                        'UPDATE sm_tenant_quota
                            SET used_value=%d,version=version+1,update_time=NOW()
                          WHERE id=%d',
                        $action['used'],
                        (int) $action['id'],
                    ));
                } else {
                    $this->execute(sprintf(
                        'INSERT INTO sm_tenant_quota
                            (organization,quota_key,quota_value,used_value,source,status,
                             order_no,remark,version,create_time,update_time)
                         VALUES (%d,%s,%d,%d,%s,%s,%s,%s,1,NOW(),NOW())',
                        (int) $action['organization'],
                        $this->quote(self::QUOTA_KEY),
                        (int) $action['maximum'],
                        $action['used'],
                        $this->quote('migration'),
                        $this->quote('active'),
                        $this->quote(self::SEED_ORDER),
                        $this->quote(self::SEED_REMARK),
                    ));
                }
                $dmlCount++;
                if ($dmlCount === 1) {
                    $this->injectFault('after_first_central_dml');
                }
            }
            $this->commitScopedTransaction(
                $connection,
                'storage_quota_central_dml',
                $ownsTransaction,
            );
        } catch (Throwable $exception) {
            $this->rollbackScopedTransaction(
                $connection,
                'storage_quota_central_dml',
                $ownsTransaction,
            );
            throw $exception;
        }
    }

    private function beginScopedTransaction(PDO $connection, string $savepoint): bool
    {
        if (!$connection->inTransaction()) {
            $connection->beginTransaction();

            return true;
        }
        $connection->exec("SAVEPOINT {$savepoint}");

        return false;
    }

    private function commitScopedTransaction(
        PDO $connection,
        string $savepoint,
        bool $ownsTransaction,
    ): void {
        if ($ownsTransaction) {
            $connection->commit();

            return;
        }
        $connection->exec("RELEASE SAVEPOINT {$savepoint}");
    }

    private function rollbackScopedTransaction(
        PDO $connection,
        string $savepoint,
        bool $ownsTransaction,
    ): void {
        if (!$connection->inTransaction()) {
            return;
        }
        if ($ownsTransaction) {
            $connection->rollBack();

            return;
        }
        $connection->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
        $connection->exec("RELEASE SAVEPOINT {$savepoint}");
    }

    /** @return array<int,int> */
    private function physicalUsageByOrganization(): array
    {
        if (!$this->hasTable('im_upload_asset')) {
            return [];
        }
        foreach (['id', 'organization', 'storage_path', 'size_byte'] as $column) {
            if (!$this->table('im_upload_asset')->hasColumn($column)) {
                throw new RuntimeException("im_upload_asset.{$column} is required.");
            }
        }
        if ($this->fetchRow(
            "SELECT id FROM im_upload_asset
              WHERE organization<=0 OR storage_path='' OR size_byte<=0 LIMIT 1",
        )) {
            throw new RuntimeException('Malformed upload asset prevents reconciliation.');
        }
        if ($this->fetchRow(
            'SELECT organization,storage_path FROM im_upload_asset
              GROUP BY organization,storage_path HAVING MIN(size_byte)<>MAX(size_byte) LIMIT 1',
        )) {
            throw new RuntimeException('Aliases disagree on physical object size.');
        }
        foreach ($this->fetchAll(
            'SELECT organization,storage_path,size_byte FROM im_upload_asset ORDER BY id',
        ) as $asset) {
            $this->positiveInteger($asset['organization'] ?? null, 'asset organization');
            $this->positiveInteger($asset['size_byte'] ?? null, 'asset size');
            $this->assertCanonicalStoragePath((string) ($asset['storage_path'] ?? ''));
        }
        $rows = $this->fetchAll(
            'SELECT organization,SUM(size_byte) AS used_value
               FROM (SELECT organization,storage_path,MAX(size_byte) AS size_byte
                       FROM im_upload_asset GROUP BY organization,storage_path) physical
              GROUP BY organization',
        );
        $usage = [];
        foreach ($rows as $row) {
            $organization = $this->positiveInteger(
                $row['organization'] ?? null,
                'asset organization',
            );
            $usage[$organization] = $this->nonNegativeInteger(
                $row['used_value'] ?? null,
                'physical storage usage',
            );
        }
        return $usage;
    }

    private function assertCanonicalStoragePath(string $path): void
    {
        if ($path === ''
            || strlen($path) > 512
            || trim($path, '/') !== $path
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || preg_match(
                '#^(?:[A-Za-z0-9._-]+/)*organizations/[1-9]\d*/im/'
                    . '[0-9]{6}/[a-f0-9]{32,64}\.[A-Za-z0-9]{1,32}$#',
                $path,
            ) !== 1) {
            throw new RuntimeException('Upload asset storage_path is not canonical.');
        }
    }

    /** @return array<int,int> */
    private function legacyStorageMaximums(): array
    {
        if (!$this->hasTable('sm_file_media_quota')) {
            return [];
        }
        foreach (['organization', 'max_storage_bytes', 'delete_time'] as $column) {
            if (!$this->table('sm_file_media_quota')->hasColumn($column)) {
                throw new RuntimeException("sm_file_media_quota.{$column} is required.");
            }
        }
        $maximums = [];
        foreach ($this->fetchAll(
            'SELECT organization,max_storage_bytes,delete_time FROM sm_file_media_quota',
        ) as $row) {
            $organization = $this->positiveInteger(
                $row['organization'] ?? null,
                'legacy quota organization',
            );
            if (isset($maximums[$organization]) || ($row['delete_time'] ?? null) !== null) {
                throw new RuntimeException('Legacy file-media quota data is ambiguous.');
            }
            $maximums[$organization] = $this->nonNegativeInteger(
                $row['max_storage_bytes'] ?? null,
                'legacy storage maximum',
            );
        }
        return $maximums;
    }

    /** @param array<string,mixed> $row */
    private function assertQuotaRow(array $row, int $physicalUsed): void
    {
        $maximum = $this->nonNegativeInteger($row['quota_value'] ?? null, 'quota_value');
        $this->nonNegativeInteger($row['used_value'] ?? null, 'used_value');
        $this->positiveInteger($row['version'] ?? null, 'quota version');
        if (($row['status'] ?? null) !== 'active'
            || ($row['delete_time'] ?? null) !== null) {
            throw new RuntimeException('Existing storage quota is malformed or inactive.');
        }
        $now = time();
        foreach (['start_at' => 'future', 'end_at' => 'past'] as $column => $direction) {
            if (($row[$column] ?? null) === null) {
                continue;
            }
            $timestamp = strtotime((string) $row[$column]);
            if ($timestamp === false
                || ($direction === 'future' && $timestamp > $now)
                || ($direction === 'past' && $timestamp < $now)) {
                throw new RuntimeException('Existing storage quota is outside its active window.');
            }
        }
        if ($maximum > 0 && $maximum < $physicalUsed) {
            throw new RuntimeException('Existing storage quota is below physical usage.');
        }
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new RuntimeException("Malformed {$label}.");
            }
            return $value;
        }
        if (!is_string($value)
            || !preg_match('/^(?:0|[1-9]\d*)$/', $value)
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX)
                && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            throw new RuntimeException("Malformed or unsupported {$label}.");
        }
        return (int) $value;
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        $integer = $this->nonNegativeInteger($value, $label);
        if ($integer <= 0) {
            throw new RuntimeException("Malformed {$label}.");
        }
        return $integer;
    }

    private function injectFault(string $point): void
    {
        if ((string) getenv(self::FAULT_ENV) === $point) {
            throw new RuntimeException("Injected storage quota migration fault: {$point}.");
        }
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
