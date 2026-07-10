<?php

declare(strict_types=1);

use B8im\ModuleSdk\State\SystemModuleStatus;
use B8im\ModuleSdk\State\TenantModuleStatus;
use Phinx\Migration\AbstractMigration;

final class CreateModuleLifecycle extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sm_tenant_module_license')) {
            $this->table('sm_tenant_module_license')->drop()->save();
        }
        if ($this->hasTable('sm_module')) {
            $this->table('sm_module')->drop()->save();
        }

        $this->table('sm_module', [
            'id' => false,
            'primary_key' => ['id'],
            'comment' => '系统模块注册与生命周期表',
            'collation' => 'utf8mb4_general_ci',
        ])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'comment' => '编号'])
            ->addColumn('module_key', 'string', ['limit' => 64, 'null' => false, 'comment' => 'snake_case 模块唯一标识'])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false, 'comment' => '模块名称'])
            ->addColumn('description', 'text', ['null' => true, 'comment' => '模块描述'])
            ->addColumn('category', 'string', ['limit' => 32, 'null' => false, 'comment' => '模块分类'])
            ->addColumn('module_type', 'string', ['limit' => 32, 'null' => false, 'comment' => '模块类型'])
            ->addColumn('is_builtin', 'boolean', ['null' => false, 'default' => false, 'comment' => '是否内置'])
            ->addColumn('license_required', 'boolean', ['null' => false, 'default' => true, 'comment' => '是否需要租户授权'])
            ->addColumn('version', 'string', ['limit' => 64, 'null' => false, 'comment' => '当前注册版本'])
            ->addColumn('available_version', 'string', ['limit' => 64, 'null' => false, 'comment' => '当前发现的 manifest 版本'])
            ->addColumn('min_system_version', 'string', ['limit' => 64, 'null' => false, 'comment' => '最低系统版本'])
            ->addColumn('platforms_json', 'text', ['null' => false, 'comment' => '支持平台 JSON'])
            ->addColumn('depends_on_json', 'text', ['null' => false, 'comment' => '依赖 JSON'])
            ->addColumn('conflicts_with_json', 'text', ['null' => false, 'comment' => '冲突 JSON'])
            ->addColumn('capabilities_json', 'text', ['null' => false, 'comment' => '能力 JSON'])
            ->addColumn('manifest_json', 'text', ['null' => false, 'comment' => '已校验 manifest 快照'])
            ->addColumn('manifest_path', 'string', ['limit' => 512, 'null' => false, 'comment' => '受控 manifest 绝对路径'])
            ->addColumn('status', 'string', [
                'limit' => 32,
                'null' => false,
                'default' => SystemModuleStatus::DISCOVERED->value,
                'comment' => 'SDK SystemModuleStatus',
            ])
            ->addColumn('failure_message', 'text', ['null' => true, 'comment' => '最后失败原因'])
            ->addColumn('lock_version', 'integer', ['signed' => false, 'null' => false, 'default' => 1, 'comment' => '乐观锁版本'])
            ->addColumn('installed_at', 'datetime', ['null' => true])
            ->addColumn('enabled_at', 'datetime', ['null' => true])
            ->addColumn('disabled_at', 'datetime', ['null' => true])
            ->addColumn('uninstalled_at', 'datetime', ['null' => true])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('updated_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('create_time', 'datetime', ['null' => true])
            ->addColumn('update_time', 'datetime', ['null' => true])
            ->addColumn('delete_time', 'datetime', ['null' => true])
            ->addIndex(['module_key'], ['unique' => true, 'name' => 'uk_module_key'])
            ->addIndex(['status'], ['name' => 'idx_module_status'])
            ->create();

        $this->table('sm_tenant_module_license', [
            'id' => false,
            'primary_key' => ['id'],
            'comment' => '租户模块授权与启停表',
            'collation' => 'utf8mb4_general_ci',
        ])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true, 'comment' => '编号'])
            ->addColumn('organization', 'integer', ['signed' => false, 'null' => false, 'comment' => '机构编号'])
            ->addColumn('module_key', 'string', ['limit' => 64, 'null' => false, 'comment' => '模块标识'])
            ->addColumn('status', 'string', [
                'limit' => 32,
                'null' => false,
                'default' => TenantModuleStatus::UNAUTHORIZED->value,
                'comment' => 'SDK TenantModuleStatus',
            ])
            ->addColumn('expire_at', 'datetime', ['null' => true, 'comment' => '授权到期时间，NULL 为永久'])
            ->addColumn('version', 'integer', ['signed' => false, 'null' => false, 'default' => 1, 'comment' => '乐观锁与缓存版本'])
            ->addColumn('granted_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('revoked_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('authorized_at', 'datetime', ['null' => true])
            ->addColumn('enabled_at', 'datetime', ['null' => true])
            ->addColumn('disabled_at', 'datetime', ['null' => true])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('remark', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('create_time', 'datetime', ['null' => true])
            ->addColumn('update_time', 'datetime', ['null' => true])
            ->addColumn('delete_time', 'datetime', ['null' => true])
            ->addIndex(['organization', 'module_key'], ['unique' => true, 'name' => 'uk_org_module'])
            ->addIndex(['module_key', 'status'], ['name' => 'idx_module_status'])
            ->addIndex(['status', 'expire_at'], ['name' => 'idx_status_expire'])
            ->create();

        $this->table('sm_tenant_module_config', [
            'id' => false,
            'primary_key' => ['id'],
            'comment' => '租户模块配置表',
            'collation' => 'utf8mb4_general_ci',
        ])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true])
            ->addColumn('organization', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('module_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('config_json', 'text', ['null' => false, 'comment' => '已校验租户配置 JSON'])
            ->addColumn('version', 'integer', ['signed' => false, 'null' => false, 'default' => 1])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('updated_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('create_time', 'datetime', ['null' => true])
            ->addColumn('update_time', 'datetime', ['null' => true])
            ->addColumn('delete_time', 'datetime', ['null' => true])
            ->addIndex(['organization', 'module_key'], ['unique' => true, 'name' => 'uk_org_module'])
            ->create();

        $this->table('sm_module_lifecycle_audit', [
            'id' => false,
            'primary_key' => ['id'],
            'comment' => '模块生命周期与授权审计表',
            'collation' => 'utf8mb4_general_ci',
        ])
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('module_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('organization', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('operation', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('from_status', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('to_status', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('from_version', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('target_version', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('success', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('error_message', 'text', ['null' => true])
            ->addColumn('context_json', 'text', ['null' => true])
            ->addColumn('operator_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('operator_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('source_ip', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('create_time', 'datetime', ['null' => false])
            ->addIndex(['module_key', 'create_time'], ['name' => 'idx_module_time'])
            ->addIndex(['organization', 'create_time'], ['name' => 'idx_org_time'])
            ->create();

        $this->table('sm_module_menu_mapping', [
            'id' => false,
            'primary_key' => ['id'],
            'comment' => '模块 manifest 菜单与 SaiAdmin Multi 菜单映射',
            'collation' => 'utf8mb4_general_ci',
        ])
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true])
            ->addColumn('module_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('scope', 'string', ['limit' => 16, 'null' => false, 'comment' => 'admin/tenant'])
            ->addColumn('manifest_menu_id', 'string', ['limit' => 180, 'null' => false])
            ->addColumn('menu_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('permission_slug', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('create_time', 'datetime', ['null' => true])
            ->addColumn('update_time', 'datetime', ['null' => true])
            ->addIndex(['module_key', 'scope', 'manifest_menu_id'], ['unique' => true, 'name' => 'uk_module_scope_manifest'])
            ->addIndex(['scope', 'menu_id'], ['name' => 'idx_scope_menu'])
            ->create();

        foreach (['sm_admin_menu', 'sm_tenant_menu'] as $menuTable) {
            if ($this->hasTable($menuTable)) {
                $menu = $this->table($menuTable);
                if (!$menu->hasColumn('module_key')) {
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
                $this->table($menuTable)
                    ->changeColumn('name', 'string', ['limit' => 120, 'null' => true, 'comment' => '菜单名称'])
                    ->changeColumn('slug', 'string', ['limit' => 160, 'null' => true, 'comment' => '权限标识'])
                    ->changeColumn('path', 'string', ['limit' => 300, 'null' => true, 'comment' => '路由地址'])
                    ->changeColumn('component', 'string', ['limit' => 300, 'null' => true, 'comment' => '组件路径'])
                    ->changeColumn('icon', 'string', ['limit' => 100, 'null' => true, 'comment' => '菜单图标'])
                    ->update();
            }
        }
    }

    public function down(): void
    {
        foreach (['sm_admin_menu', 'sm_tenant_menu'] as $menuTable) {
            if ($this->hasTable($menuTable) && $this->table($menuTable)->hasColumn('module_key')) {
                $this->table($menuTable)
                    ->removeColumn('module_key')
                    ->changeColumn('name', 'string', ['limit' => 50, 'null' => true, 'comment' => '菜单名称'])
                    ->changeColumn('slug', 'string', ['limit' => 100, 'null' => true, 'comment' => '权限标识'])
                    ->changeColumn('path', 'string', ['limit' => 200, 'null' => true, 'comment' => '路由地址'])
                    ->changeColumn('component', 'string', ['limit' => 255, 'null' => true, 'comment' => '组件路径'])
                    ->changeColumn('icon', 'string', ['limit' => 50, 'null' => true, 'comment' => '菜单图标'])
                    ->update();
            }
        }

        foreach ([
            'sm_module_menu_mapping',
            'sm_module_lifecycle_audit',
            'sm_tenant_module_config',
            'sm_tenant_module_license',
            'sm_module',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }

    }
}
