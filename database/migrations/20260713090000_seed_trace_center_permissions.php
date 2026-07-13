<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedTraceCenterPermissions extends AbstractMigration
{
    private const PAGE_CODE = 'TraceCenter';

    /** @var array<string, string> */
    private const PERMISSIONS = [
        '链路服务列表' => 'saimulti:system:trace:services',
        '链路查询' => 'saimulti:system:trace:search',
        '链路详情' => 'saimulti:system:trace:read',
    ];

    public function up(): void
    {
        $page = $this->fetchRow(sprintf(
            'SELECT id FROM `sm_admin_menu` WHERE code = %s AND delete_time IS NULL LIMIT 1',
            $this->quote(self::PAGE_CODE),
        ));
        if (!$page) {
            $parent = $this->fetchRow(
                "SELECT id FROM `sm_admin_menu` WHERE code = 'system' AND delete_time IS NULL LIMIT 1",
            );
            if (!$parent) {
                throw new RuntimeException('系统管理父菜单不存在。');
            }
            $this->table('sm_admin_menu')->insert([
                'parent_id' => (int) $parent['id'],
                'name' => '链路中心',
                'code' => self::PAGE_CODE,
                'slug' => null,
                'module_key' => null,
                'type' => 2,
                'path' => '/system/trace',
                'component' => '/system/trace/index',
                'icon' => 'ri:route-line',
                'sort' => 80,
                'is_hidden' => 0,
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ])->saveData();
        }

        $page = $this->fetchRow(sprintf(
            'SELECT id FROM `sm_admin_menu` WHERE code = %s AND delete_time IS NULL LIMIT 1',
            $this->quote(self::PAGE_CODE),
        ));
        $pageId = (int) ($page['id'] ?? 0);
        if ($pageId <= 0) {
            throw new RuntimeException('链路中心菜单创建失败。');
        }

        $menuIds = [$pageId];
        foreach (self::PERMISSIONS as $name => $slug) {
            $permission = $this->fetchRow(sprintf(
                'SELECT id FROM `sm_admin_menu` WHERE slug = %s AND delete_time IS NULL LIMIT 1',
                $this->quote($slug),
            ));
            if (!$permission) {
                $this->table('sm_admin_menu')->insert([
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
                ])->saveData();
                $permission = $this->fetchRow(sprintf(
                    'SELECT id FROM `sm_admin_menu` WHERE slug = %s AND delete_time IS NULL LIMIT 1',
                    $this->quote($slug),
                ));
            }
            $menuIds[] = (int) ($permission['id'] ?? 0);
        }

        $superRole = $this->fetchRow(
            "SELECT id FROM `sm_admin_role` WHERE code = 'superAdmin' AND delete_time IS NULL LIMIT 1",
        );
        if (!$superRole) {
            throw new RuntimeException('系统超级管理员角色不存在。');
        }
        foreach (array_values(array_unique(array_filter($menuIds))) as $menuId) {
            $exists = $this->fetchRow(sprintf(
                'SELECT id FROM `sm_admin_role_menu` WHERE role_id = %d AND menu_id = %d LIMIT 1',
                (int) $superRole['id'],
                $menuId,
            ));
            if (!$exists) {
                $this->table('sm_admin_role_menu')->insert([
                    'role_id' => (int) $superRole['id'],
                    'menu_id' => $menuId,
                ])->saveData();
            }
        }
    }

    public function down(): void
    {
        $page = $this->fetchRow(sprintf(
            'SELECT id FROM `sm_admin_menu` WHERE code = %s AND module_key IS NULL LIMIT 1',
            $this->quote(self::PAGE_CODE),
        ));
        if (!$page) {
            return;
        }
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->fetchAll(sprintf(
                'SELECT id FROM `sm_admin_menu` WHERE id = %d OR parent_id = %d',
                (int) $page['id'],
                (int) $page['id'],
            )),
        );
        if ($ids === []) {
            return;
        }
        $idList = implode(',', $ids);
        $this->execute(sprintf('DELETE FROM `sm_admin_role_menu` WHERE menu_id IN (%s)', $idList));
        $this->execute(sprintf('DELETE FROM `sm_admin_menu` WHERE id IN (%s)', $idList));
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
