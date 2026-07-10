<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedAdminImOperationsPermissions extends AbstractMigration
{
    private const PAGE_CODE = 'panel/im-operations';

    /** @var array<string, string> */
    private const PERMISSIONS = [
        'IM 运行总览' => 'saimulti:admin:im:overview',
        'IM 用户列表' => 'saimulti:admin:im:user:index',
        'IM 设备列表' => 'saimulti:admin:im:device:index',
        'IM 会话列表' => 'saimulti:admin:im:session:index',
        'IM 登录审计' => 'saimulti:admin:im:audit:index',
        'IM 设备状态变更' => 'saimulti:admin:im:device:status',
        'IM 会话撤销' => 'saimulti:admin:im:session:revoke',
        '读取租户 IM 策略' => 'saimulti:admin:im:policy:read',
        '更新租户 IM 策略' => 'saimulti:admin:im:policy:update',
    ];

    public function up(): void
    {
        $page = $this->fetchRow(sprintf(
            'SELECT id FROM `sm_admin_menu` WHERE code = %s AND delete_time IS NULL LIMIT 1',
            $this->quote(self::PAGE_CODE),
        ));
        if (!$page) {
            $parent = $this->fetchRow(
                "SELECT id FROM `sm_admin_menu` WHERE code = 'panel' AND delete_time IS NULL LIMIT 1",
            );
            $this->table('sm_admin_menu')->insert([
                'parent_id' => (int) ($parent['id'] ?? 0),
                'name' => 'IM 运行管理',
                'code' => self::PAGE_CODE,
                'slug' => null,
                'module_key' => null,
                'type' => 2,
                'path' => 'im-operations',
                'component' => '/admin/im/operations/index',
                'sort' => 90,
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
            throw new RuntimeException('IM 运行管理菜单创建失败。');
        }

        foreach (self::PERMISSIONS as $name => $slug) {
            $exists = $this->fetchRow(sprintf(
                'SELECT id FROM `sm_admin_menu` WHERE slug = %s AND delete_time IS NULL LIMIT 1',
                $this->quote($slug),
            ));
            if ($exists) {
                continue;
            }

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

        $pageId = (int) $page['id'];
        $rows = $this->fetchAll(sprintf(
            'SELECT id FROM `sm_admin_menu` WHERE id = %d OR parent_id = %d',
            $pageId,
            $pageId,
        ));
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
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
