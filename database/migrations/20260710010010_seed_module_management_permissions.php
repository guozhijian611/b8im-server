<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedModuleManagementPermissions extends AbstractMigration
{
    public function up(): void
    {
        $this->seedScope(
            'sm_admin_menu',
            'panel',
            [
                'name' => '模块管理',
                'code' => 'panel/module',
                'path' => 'module',
                'component' => '/admin/panel/module/index',
                'sort' => 80,
            ],
            [
                '目录' => 'saimulti:admin:module:catalog',
                '详情' => 'saimulti:admin:module:read',
                '发现' => 'saimulti:admin:module:discover',
                '安装' => 'saimulti:admin:module:install',
                '升级' => 'saimulti:admin:module:upgrade',
                '启用' => 'saimulti:admin:module:enable',
                '禁用' => 'saimulti:admin:module:disable',
                '卸载' => 'saimulti:admin:module:uninstall',
                '租户授权' => 'saimulti:admin:module:license:grant',
                '撤销授权' => 'saimulti:admin:module:license:revoke',
            ],
        );

        $this->seedScope(
            'sm_tenant_menu',
            'system',
            [
                'name' => '模块中心',
                'code' => 'system/module',
                'path' => 'module',
                'component' => '/system/module/index',
                'sort' => 80,
            ],
            [
                '可用模块' => 'saimulti:tenant:module:index',
                '启用模块' => 'saimulti:tenant:module:enable',
                '禁用模块' => 'saimulti:tenant:module:disable',
                '读取配置' => 'saimulti:tenant:module:config:read',
                '更新配置' => 'saimulti:tenant:module:config:update',
            ],
        );
    }

    public function down(): void
    {
        $this->removeScope('sm_admin_menu', 'sm_admin_role_menu', null, 'panel/module');
        $this->removeScope('sm_tenant_menu', 'sm_tenant_role_menu', 'sm_tenant_group_menu', 'system/module');
    }

    /**
     * @param array{name: string, code: string, path: string, component: string, sort: int} $page
     * @param array<string, string> $permissions
     */
    private function seedScope(string $table, string $parentCode, array $page, array $permissions): void
    {
        $existing = $this->fetchRow(sprintf(
            'SELECT id FROM `%s` WHERE code = %s AND delete_time IS NULL LIMIT 1',
            $table,
            $this->quote($page['code']),
        ));

        if (!$existing) {
            $parent = $this->fetchRow(sprintf(
                'SELECT id FROM `%s` WHERE code = %s AND delete_time IS NULL LIMIT 1',
                $table,
                $this->quote($parentCode),
            ));

            $pageData = [
                'parent_id' => (int) ($parent['id'] ?? 0),
                'name' => $page['name'],
                'code' => $page['code'],
                'slug' => null,
                'module_key' => null,
                'type' => 2,
                'path' => $page['path'],
                'component' => $page['component'],
                'sort' => $page['sort'],
                'is_hidden' => 0,
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ];
            if ($table === 'sm_tenant_menu') {
                $pageData['organization'] = 0;
            }
            $this->table($table)->insert($pageData)->saveData();
        }

        $pageRow = $this->fetchRow(sprintf(
            'SELECT id FROM `%s` WHERE code = %s AND delete_time IS NULL LIMIT 1',
            $table,
            $this->quote($page['code']),
        ));
        $pageId = (int) ($pageRow['id'] ?? 0);

        foreach ($permissions as $name => $slug) {
            $exists = $this->fetchRow(sprintf(
                'SELECT id FROM `%s` WHERE slug = %s AND delete_time IS NULL LIMIT 1',
                $table,
                $this->quote($slug),
            ));
            if ($exists) {
                continue;
            }

            $permissionData = [
                'parent_id' => $pageId,
                'name' => $name,
                'slug' => $slug,
                'module_key' => null,
                'type' => 3,
                'sort' => 0,
                'is_hidden' => 1,
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ];
            if ($table === 'sm_tenant_menu') {
                $permissionData['organization'] = 0;
            }
            $this->table($table)->insert($permissionData)->saveData();
        }
    }

    private function removeScope(string $menuTable, string $roleMenuTable, ?string $groupMenuTable, string $pageCode): void
    {
        $page = $this->fetchRow(sprintf(
            'SELECT id FROM `%s` WHERE code = %s AND module_key IS NULL LIMIT 1',
            $menuTable,
            $this->quote($pageCode),
        ));
        if (!$page) {
            return;
        }

        $pageId = (int) $page['id'];
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->fetchAll(sprintf('SELECT id FROM `%s` WHERE id = %d OR parent_id = %d', $menuTable, $pageId, $pageId)),
        );
        if ($ids === []) {
            return;
        }

        $idList = implode(',', $ids);
        $this->execute(sprintf('DELETE FROM `%s` WHERE menu_id IN (%s)', $roleMenuTable, $idList));
        if ($groupMenuTable !== null) {
            $this->execute(sprintf('DELETE FROM `%s` WHERE menu_id IN (%s)', $groupMenuTable, $idList));
        }
        $this->execute(sprintf('DELETE FROM `%s` WHERE id IN (%s)', $menuTable, $idList));
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
