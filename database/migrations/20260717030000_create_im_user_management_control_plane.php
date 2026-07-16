<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateImUserManagementControlPlane extends AbstractMigration
{
    /** @var array<string,string> */
    private const ADMIN_PERMISSIONS = [
        'IM 用户列表' => 'saimulti:admin:im:user:index',
        '读取 IM 用户' => 'saimulti:admin:im:user:read',
        '创建 IM 用户' => 'saimulti:admin:im:user:save',
        '更新 IM 用户' => 'saimulti:admin:im:user:update',
        '变更 IM 用户状态' => 'saimulti:admin:im:user:status',
        '重置 IM 用户密码' => 'saimulti:admin:im:user:reset',
        '读取 IM 用户席位' => 'saimulti:admin:im:user:quota:read',
        '配置 IM 用户席位' => 'saimulti:admin:im:user:quota:update',
    ];

    /** @var array<string,string> */
    private const TENANT_PERMISSIONS = [
        'IM 用户列表' => 'saimulti:tenant:im:user:index',
        '读取 IM 用户' => 'saimulti:tenant:im:user:read',
        '创建 IM 用户' => 'saimulti:tenant:im:user:save',
        '更新 IM 用户' => 'saimulti:tenant:im:user:update',
        '变更 IM 用户状态' => 'saimulti:tenant:im:user:status',
        '重置 IM 用户密码' => 'saimulti:tenant:im:user:reset',
        '读取 IM 用户席位' => 'saimulti:tenant:im:user:quota:read',
    ];

    public function up(): void
    {
        $this->createQuotaTable();
        $this->seedCurrentSeatUsage();
        $this->seedAdminMenus();
        $this->seedTenantMenus();
    }

    public function down(): void
    {
        $this->removeMenuScope('sm_admin_menu', 'sm_admin_role_menu', null, 'im');
        $this->removeMenuScope('sm_tenant_menu', 'sm_tenant_role_menu', 'sm_tenant_group_menu', 'im');
        if ($this->hasTable('sm_tenant_quota')) {
            $this->table('sm_tenant_quota')->drop()->save();
        }
    }

    private function createQuotaTable(): void
    {
        if ($this->hasTable('sm_tenant_quota')) {
            return;
        }
        $this->table('sm_tenant_quota', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('organization', 'integer', ['signed' => false])
            ->addColumn('quota_key', 'string', ['limit' => 64])
            ->addColumn('quota_value', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('used_value', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('source', 'string', ['limit' => 24, 'default' => 'manual'])
            ->addColumn('start_at', 'datetime', ['null' => true])
            ->addColumn('end_at', 'datetime', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'active'])
            ->addColumn('order_no', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('remark', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('updated_by', 'integer', ['null' => true])
            ->addColumn('create_time', 'datetime', ['null' => true])
            ->addColumn('update_time', 'datetime', ['null' => true])
            ->addColumn('delete_time', 'datetime', ['null' => true])
            ->addIndex(['organization', 'quota_key'], ['unique' => true, 'name' => 'uni_organization_quota_key'])
            ->addIndex(['organization', 'status'], ['name' => 'idx_organization_status'])
            ->create();
    }

    private function seedCurrentSeatUsage(): void
    {
        if (!$this->hasTable('im_user')) {
            $this->execute(<<<'SQL'
INSERT INTO sm_tenant_quota
    (organization, quota_key, quota_value, used_value, source, status, order_no, remark, version, create_time, update_time)
SELECT o.id, 'im_user_seats', 0, 0, 'migration', 'active', 'initial-import',
       'IM 运行时表尚未安装，席位初始化为 0', 1, NOW(), NOW()
  FROM sm_system_organization o
 WHERE o.delete_time IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM sm_tenant_quota q
        WHERE q.organization = o.id AND q.quota_key = 'im_user_seats'
   )
SQL);
            return;
        }
        $this->execute(<<<'SQL'
INSERT INTO sm_tenant_quota
    (organization, quota_key, quota_value, used_value, source, status, order_no, remark, version, create_time, update_time)
SELECT o.id, 'im_user_seats', COALESCE(u.used_value, 0), COALESCE(u.used_value, 0),
       'migration', 'active', 'initial-import', '按迁移时已启用 IM 用户数初始化', 1, NOW(), NOW()
  FROM sm_system_organization o
  LEFT JOIN (
      SELECT organization, COUNT(*) AS used_value
        FROM im_user
       WHERE is_system = 2 AND status = 1 AND delete_time IS NULL
       GROUP BY organization
  ) u ON u.organization = o.id
 WHERE o.delete_time IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM sm_tenant_quota q
        WHERE q.organization = o.id AND q.quota_key = 'im_user_seats'
   )
SQL);
    }

    private function seedAdminMenus(): void
    {
        $rootId = $this->upsertMenu('sm_admin_menu', null, 0, [
            'name' => 'IM 管理', 'code' => 'im', 'type' => 1, 'path' => 'im', 'component' => '',
            'icon' => 'ri:chat-settings-line', 'sort' => 95,
        ]);
        $userPageId = $this->upsertMenu('sm_admin_menu', null, $rootId, [
            'name' => 'IM 用户', 'code' => 'im/user', 'type' => 2, 'path' => 'user',
            'component' => '/admin/im/user/index', 'icon' => 'ri:user-3-line', 'sort' => 100,
        ]);
        $this->seedPermissions('sm_admin_menu', null, $userPageId, self::ADMIN_PERMISSIONS);

        $operations = $this->fetchRow(
            "SELECT id FROM sm_admin_menu WHERE code IN ('panel/im-operations', 'im/operations') AND delete_time IS NULL LIMIT 1",
        );
        if ($operations) {
            $this->execute(sprintf(
                "UPDATE sm_admin_menu SET parent_id=%d,name='IM 运维中心',code='im/operations',path='operations',icon='ri:settings-3-line',update_time=NOW() WHERE id=%d",
                $rootId,
                (int) $operations['id'],
            ));
        }

        $ids = $this->menuBranchIds('sm_admin_menu', $rootId);
        $role = $this->fetchRow("SELECT id FROM sm_admin_role WHERE code = 'superAdmin' AND status = 1 AND delete_time IS NULL LIMIT 1");
        if ($role) {
            foreach ($ids as $menuId) {
                $this->execute(sprintf(
                    'INSERT INTO sm_admin_role_menu (role_id,menu_id) SELECT %d,%d WHERE NOT EXISTS (SELECT 1 FROM sm_admin_role_menu WHERE role_id=%d AND menu_id=%d)',
                    (int) $role['id'], $menuId, (int) $role['id'], $menuId,
                ));
            }
        }
    }

    private function seedTenantMenus(): void
    {
        $rootId = $this->upsertMenu('sm_tenant_menu', 0, 0, [
            'name' => 'IM 管理', 'code' => 'im', 'type' => 1, 'path' => 'im', 'component' => '',
            'icon' => 'ri:chat-settings-line', 'sort' => 95,
        ]);
        $userPageId = $this->upsertMenu('sm_tenant_menu', 0, $rootId, [
            'name' => '用户管理', 'code' => 'im/user', 'type' => 2, 'path' => 'user',
            'component' => '/im/user/index', 'icon' => 'ri:user-3-line', 'sort' => 100,
        ]);
        $this->seedPermissions('sm_tenant_menu', 0, $userPageId, self::TENANT_PERMISSIONS);

        $policyPageId = $this->upsertMenu('sm_tenant_menu', 0, $rootId, [
            'name' => '运行策略', 'code' => 'im/policy', 'type' => 2, 'path' => 'policy',
            'component' => '/system/im-policy/index', 'icon' => 'ri:shield-settings-line', 'sort' => 90,
        ], ['system/im-policy']);
        $this->seedPermissions('sm_tenant_menu', 0, $policyPageId, [
            '读取 IM 运行策略' => 'saimulti:tenant:im:policy:read',
            '更新 IM 运行策略' => 'saimulti:tenant:im:policy:update',
        ]);

        foreach ($this->menuBranchIds('sm_tenant_menu', $rootId) as $menuId) {
            $this->execute(sprintf(
                'INSERT INTO sm_tenant_group_menu (group_id,menu_id) '
                . 'SELECT g.id,%d FROM sm_tenant_group g WHERE g.status=1 AND g.delete_time IS NULL '
                . 'AND NOT EXISTS (SELECT 1 FROM sm_tenant_group_menu gm WHERE gm.group_id=g.id AND gm.menu_id=%d)',
                $menuId,
                $menuId,
            ));
        }
    }

    /** @param array{name:string,code:string,type:int,path:string,component:string,icon:string,sort:int} $data @param list<string> $legacyCodes */
    private function upsertMenu(string $table, ?int $organization, int $parentId, array $data, array $legacyCodes = []): int
    {
        $codes = array_merge([$data['code']], $legacyCodes);
        $quoted = implode(',', array_map(fn (string $code): string => $this->quote($code), $codes));
        $scope = $organization === null ? '' : sprintf('organization=%d AND ', $organization);
        $row = $this->fetchRow(sprintf(
            'SELECT id FROM `%s` WHERE %scode IN (%s) AND delete_time IS NULL LIMIT 1',
            $table,
            $scope,
            $quoted,
        ));
        $payload = [
            'parent_id' => $parentId,
            'name' => $data['name'],
            'code' => $data['code'],
            'slug' => null,
            'module_key' => null,
            'type' => $data['type'],
            'path' => $data['path'],
            'component' => $data['component'],
            'icon' => $data['icon'],
            'sort' => $data['sort'],
            'is_hidden' => 2,
            'status' => 1,
            'update_time' => date('Y-m-d H:i:s'),
        ];
        if ($organization !== null) {
            $payload['organization'] = $organization;
        }
        if ($row) {
            $this->updateMenuRow($table, (int) $row['id'], $payload);
            return (int) $row['id'];
        }
        $payload['create_time'] = date('Y-m-d H:i:s');
        $this->table($table)->insert($payload)->saveData();
        $created = $this->fetchRow(sprintf(
            'SELECT id FROM `%s` WHERE %scode=%s AND delete_time IS NULL LIMIT 1',
            $table,
            $scope,
            $this->quote($data['code']),
        ));
        if (!$created) {
            throw new RuntimeException('IM 菜单创建失败。');
        }
        return (int) $created['id'];
    }

    /** @param array<string,string> $permissions */
    private function seedPermissions(string $table, ?int $organization, int $parentId, array $permissions): void
    {
        $scope = $organization === null ? '' : sprintf('organization=%d AND ', $organization);
        foreach ($permissions as $name => $slug) {
            $row = $this->fetchRow(sprintf(
                'SELECT id FROM `%s` WHERE %sslug=%s AND delete_time IS NULL LIMIT 1',
                $table,
                $scope,
                $this->quote($slug),
            ));
            $payload = [
                'parent_id' => $parentId, 'name' => $name, 'slug' => $slug, 'module_key' => null,
                'type' => 3, 'sort' => 0, 'is_hidden' => 1, 'status' => 1,
                'update_time' => date('Y-m-d H:i:s'),
            ];
            if ($organization !== null) {
                $payload['organization'] = $organization;
            }
            if ($row) {
                $this->updateMenuRow($table, (int) $row['id'], $payload);
            } else {
                $payload['create_time'] = date('Y-m-d H:i:s');
                $this->table($table)->insert($payload)->saveData();
            }
        }
    }

    /** @return list<int> */
    private function menuBranchIds(string $table, int $rootId): array
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT id FROM `%s` WHERE delete_time IS NULL AND (id=%d OR parent_id=%d OR parent_id IN (SELECT id FROM (SELECT id FROM `%s` WHERE parent_id=%d AND delete_time IS NULL) children))',
            $table,
            $rootId,
            $rootId,
            $table,
            $rootId,
        ));
        return array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
    }

    private function removeMenuScope(string $menuTable, string $roleMenuTable, ?string $groupMenuTable, string $rootCode): void
    {
        $root = $this->fetchRow(sprintf('SELECT id FROM `%s` WHERE code=%s LIMIT 1', $menuTable, $this->quote($rootCode)));
        if (!$root) {
            return;
        }
        $ids = $this->menuBranchIds($menuTable, (int) $root['id']);
        if ($ids === []) {
            return;
        }
        $list = implode(',', $ids);
        $this->execute(sprintf('DELETE FROM `%s` WHERE menu_id IN (%s)', $roleMenuTable, $list));
        if ($groupMenuTable !== null) {
            $this->execute(sprintf('DELETE FROM `%s` WHERE menu_id IN (%s)', $groupMenuTable, $list));
        }
        $this->execute(sprintf('DELETE FROM `%s` WHERE id IN (%s)', $menuTable, $list));
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }

    /** @param array<string,mixed> $payload */
    private function updateMenuRow(string $table, int $id, array $payload): void
    {
        $sets = [];
        foreach ($payload as $column => $value) {
            if ($value === null) {
                $sets[] = sprintf('`%s`=NULL', $column);
            } elseif (is_int($value)) {
                $sets[] = sprintf('`%s`=%d', $column, $value);
            } else {
                $sets[] = sprintf('`%s`=%s', $column, $this->quote((string) $value));
            }
        }
        $this->execute(sprintf('UPDATE `%s` SET %s WHERE id=%d', $table, implode(',', $sets), $id));
    }
}
