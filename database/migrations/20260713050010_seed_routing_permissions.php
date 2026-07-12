<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedRoutingPermissions extends AbstractMigration
{
    public function up(): void
    {
        $this->seed('sm_admin_menu', 'panel', 'panel/routing', '接入线路策略', '/admin/routing/index', [
            '读取线路策略' => 'saimulti:admin:routing:read',
            '发布线路策略' => 'saimulti:admin:routing:publish',
        ]);
        $this->seed('sm_tenant_menu', 'system', 'system/routing', '接入线路策略', '/system/routing/index', [
            '读取线路策略' => 'saimulti:tenant:routing:read',
        ]);
    }

    public function down(): void
    {
        $this->remove('sm_admin_menu', 'sm_admin_role_menu', 'panel/routing');
        $this->remove('sm_tenant_menu', 'sm_tenant_role_menu', 'system/routing');
    }

    /** @param array<string, string> $permissions */
    private function seed(string $table, string $parentCode, string $code, string $name, string $component, array $permissions): void
    {
        $parent = $this->fetchRow(sprintf('SELECT id FROM `%s` WHERE code = %s AND delete_time IS NULL LIMIT 1', $table, $this->quote($parentCode)));
        $page = $this->fetchRow(sprintf('SELECT id FROM `%s` WHERE code = %s AND delete_time IS NULL LIMIT 1', $table, $this->quote($code)));
        if (!$page) {
            $data = [
                'parent_id' => (int) ($parent['id'] ?? 0), 'name' => $name, 'code' => $code,
                'slug' => null, 'module_key' => null, 'type' => 2, 'path' => 'routing',
                'component' => $component, 'sort' => 95, 'is_hidden' => 0, 'status' => 1,
                'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
            ];
            if ($table === 'sm_tenant_menu') {
                $data['organization'] = 0;
            }
            $this->table($table)->insert($data)->saveData();
            $page = $this->fetchRow(sprintf('SELECT id FROM `%s` WHERE code = %s AND delete_time IS NULL LIMIT 1', $table, $this->quote($code)));
        }
        foreach ($permissions as $permissionName => $slug) {
            if ($this->fetchRow(sprintf('SELECT id FROM `%s` WHERE slug = %s AND delete_time IS NULL LIMIT 1', $table, $this->quote($slug)))) {
                continue;
            }
            $data = [
                'parent_id' => (int) $page['id'], 'name' => $permissionName, 'slug' => $slug,
                'module_key' => null, 'type' => 3, 'sort' => 0, 'is_hidden' => 1, 'status' => 1,
                'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
            ];
            if ($table === 'sm_tenant_menu') {
                $data['organization'] = 0;
            }
            $this->table($table)->insert($data)->saveData();
        }
    }

    private function remove(string $menuTable, string $roleMenuTable, string $code): void
    {
        $page = $this->fetchRow(sprintf('SELECT id FROM `%s` WHERE code = %s LIMIT 1', $menuTable, $this->quote($code)));
        if (!$page) return;
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $this->fetchAll(sprintf('SELECT id FROM `%s` WHERE id = %d OR parent_id = %d', $menuTable, $page['id'], $page['id'])));
        if ($ids === []) return;
        $list = implode(',', $ids);
        $this->execute(sprintf('DELETE FROM `%s` WHERE menu_id IN (%s)', $roleMenuTable, $list));
        $this->execute(sprintf('DELETE FROM `%s` WHERE id IN (%s)', $menuTable, $list));
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
