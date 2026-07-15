<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Repair schema drift: CreateModuleLifecycle should have added module_key to
 * admin/tenant menus, but sm_tenant_menu on the test database is still missing
 * it and tenant menu listing fails after login.
 */
final class EnsureMenuModuleKey extends AbstractMigration
{
    public function up(): void
    {
        foreach (['sm_admin_menu', 'sm_tenant_menu'] as $menuTable) {
            if (!$this->hasTable($menuTable)) {
                continue;
            }
            $menu = $this->table($menuTable);
            if ($menu->hasColumn('module_key')) {
                continue;
            }
            $menu
                ->addColumn('module_key', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'after' => 'slug',
                    'comment' => '所属模块，NULL 为核心菜单',
                ])
                ->addIndex(['module_key'], ['name' => 'idx_module_key'])
                ->update();
        }
    }

    public function down(): void
    {
        // Keep columns; removing them would re-break module menu filtering.
    }
}
