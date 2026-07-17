<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTenantGroupModuleAssignments extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sm_tenant_group_module')) {
            $this->table('sm_tenant_group_module', [
                'id' => false,
                'primary_key' => ['id'],
                'comment' => '套餐默认模块能力表',
                'collation' => 'utf8mb4_general_ci',
            ])
                ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'comment' => '编号'])
                ->addColumn('group_id', 'integer', ['signed' => false, 'null' => false, 'comment' => '套餐编号'])
                ->addColumn('module_key', 'string', ['limit' => 64, 'null' => false, 'comment' => '模块标识'])
                ->addColumn('enabled', 'boolean', ['null' => false, 'default' => true, 'comment' => '是否作为套餐默认能力'])
                ->addColumn('limits_json', 'text', ['null' => false, 'comment' => '套餐模块限制 JSON'])
                ->addColumn('config_json', 'text', ['null' => false, 'comment' => '套餐模块默认配置 JSON'])
                ->addColumn('sort', 'integer', ['signed' => false, 'null' => false, 'default' => 100, 'comment' => '排序'])
                ->addColumn('create_time', 'datetime', ['null' => true])
                ->addColumn('update_time', 'datetime', ['null' => true])
                ->addIndex(['group_id', 'module_key'], ['unique' => true, 'name' => 'uk_group_module'])
                ->addIndex(['module_key', 'enabled'], ['name' => 'idx_module_enabled'])
                ->create();
        }

        if ($this->hasTable('sm_tenant_module_license')) {
            $license = $this->table('sm_tenant_module_license');
            if (!$license->hasColumn('assignment_source')) {
                $license
                    ->addColumn('assignment_source', 'string', [
                        'limit' => 16,
                        'null' => false,
                        'default' => 'MANUAL',
                        'after' => 'remark',
                        'comment' => '授权来源：PACKAGE/MANUAL',
                    ])
                    ->addColumn('source_group_id', 'integer', [
                        'signed' => false,
                        'null' => true,
                        'after' => 'assignment_source',
                        'comment' => '套餐来源编号，无套餐或单独配置时为空',
                    ])
                    ->addIndex(['assignment_source', 'source_group_id'], ['name' => 'idx_assignment_source'])
                    ->update();
            }
        }

        if ($this->hasTable('sm_admin_menu')) {
            $this->execute("UPDATE `sm_admin_menu` SET `name` = '套餐管理' WHERE `code` = 'panel/group' AND `delete_time` IS NULL");
        }
    }

    public function down(): void
    {
        if ($this->hasTable('sm_admin_menu')) {
            $this->execute("UPDATE `sm_admin_menu` SET `name` = '机构分组' WHERE `code` = 'panel/group' AND `delete_time` IS NULL");
        }

        if ($this->hasTable('sm_tenant_module_license')) {
            $license = $this->table('sm_tenant_module_license');
            if ($license->hasColumn('assignment_source')) {
                $license
                    ->removeIndexByName('idx_assignment_source')
                    ->removeColumn('source_group_id')
                    ->removeColumn('assignment_source')
                    ->update();
            }
        }

        if ($this->hasTable('sm_tenant_group_module')) {
            $this->table('sm_tenant_group_module')->drop()->save();
        }
    }
}
