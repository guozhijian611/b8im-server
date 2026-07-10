<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedTenantImPolicyPermissions extends AbstractMigration
{
    private const PAGE_CODE = 'system/im-policy';

    public function up(): void
    {
        $page = $this->fetchRow(sprintf(
            'SELECT id FROM `sm_tenant_menu` WHERE organization = 0 AND code = %s AND delete_time IS NULL LIMIT 1',
            $this->quote(self::PAGE_CODE),
        ));
        if (!$page) {
            $parent = $this->fetchRow(
                "SELECT id FROM `sm_tenant_menu` WHERE organization = 0 AND code = 'system' AND delete_time IS NULL LIMIT 1",
            );
            $this->table('sm_tenant_menu')->insert([
                'organization' => 0,
                'parent_id' => (int) ($parent['id'] ?? 0),
                'name' => 'IM 运行策略',
                'code' => self::PAGE_CODE,
                'slug' => null,
                'module_key' => null,
                'type' => 2,
                'path' => 'im-policy',
                'component' => '/system/im-policy/index',
                'sort' => 85,
                'is_hidden' => 0,
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ])->saveData();
        }

        $page = $this->fetchRow(sprintf(
            'SELECT id FROM `sm_tenant_menu` WHERE organization = 0 AND code = %s AND delete_time IS NULL LIMIT 1',
            $this->quote(self::PAGE_CODE),
        ));
        $pageId = (int) ($page['id'] ?? 0);
        if ($pageId <= 0) {
            throw new RuntimeException('租户 IM 策略菜单创建失败。');
        }

        foreach ([
            '读取 IM 运行策略' => 'saimulti:tenant:im:policy:read',
            '更新 IM 运行策略' => 'saimulti:tenant:im:policy:update',
        ] as $name => $slug) {
            $exists = $this->fetchRow(sprintf(
                'SELECT id FROM `sm_tenant_menu` WHERE organization = 0 AND slug = %s AND delete_time IS NULL LIMIT 1',
                $this->quote($slug),
            ));
            if ($exists) {
                continue;
            }
            $this->table('sm_tenant_menu')->insert([
                'organization' => 0,
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
        }

        $menuIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->fetchAll(sprintf(
                'SELECT id FROM `sm_tenant_menu` WHERE organization = 0 AND (id = %d OR parent_id = %d)',
                $pageId,
                $pageId,
            )),
        );
        foreach ($menuIds as $menuId) {
            $this->execute(sprintf(
                'INSERT INTO `sm_tenant_group_menu` (`group_id`, `menu_id`)
                 SELECT g.id, %d FROM `sm_tenant_group` g
                  WHERE g.status = 1 AND g.delete_time IS NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM `sm_tenant_group_menu` gm
                         WHERE gm.group_id = g.id AND gm.menu_id = %d
                    )',
                $menuId,
                $menuId,
            ));
        }
    }

    public function down(): void
    {
        $page = $this->fetchRow(sprintf(
            'SELECT id FROM `sm_tenant_menu` WHERE organization = 0 AND code = %s LIMIT 1',
            $this->quote(self::PAGE_CODE),
        ));
        if (!$page) {
            return;
        }
        $pageId = (int) $page['id'];
        $rows = $this->fetchAll(sprintf(
            'SELECT id FROM `sm_tenant_menu` WHERE organization = 0 AND (id = %d OR parent_id = %d)',
            $pageId,
            $pageId,
        ));
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ($ids === []) {
            return;
        }
        $idList = implode(',', $ids);
        $this->execute(sprintf('DELETE FROM `sm_tenant_role_menu` WHERE menu_id IN (%s)', $idList));
        $this->execute(sprintf('DELETE FROM `sm_tenant_group_menu` WHERE menu_id IN (%s)', $idList));
        $this->execute(sprintf('DELETE FROM `sm_tenant_menu` WHERE id IN (%s)', $idList));
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
