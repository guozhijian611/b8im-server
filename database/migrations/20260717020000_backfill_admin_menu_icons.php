<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 回填平台菜单缺失 icon，并规范用户相关菜单可见性。
 * 前端已离线注册 Remix Icon（ri:*），无 icon 时侧栏表现为空白。
 */
final class BackfillAdminMenuIcons extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sm_admin_menu')) {
            return;
        }

        $iconMap = [
            // path => icon
            'module' => 'ri:puzzle-line',
            'im-operations' => 'ri:message-3-line',
            'routing' => 'ri:route-line',
            'user' => 'ri:user-line',
            'panel' => 'ri:apps-2-ai-line',
            'organization' => 'ri:building-line',
            'group' => 'ri:group-3-line',
            'menu' => 'ri:menu-line',
            'system' => 'ri:dashboard-horizontal-line',
            'role' => 'ri:admin-line',
            'dept' => 'ri:node-tree',
            'dict' => 'ri:database-2-line',
            'config' => 'ri:settings-4-line',
            'announcement' => 'ri:notification-3-line',
            'user-center' => 'ri:user-2-line',
        ];

        foreach ($iconMap as $path => $icon) {
            $this->execute(sprintf(
                "UPDATE sm_admin_menu
                 SET icon = '%s', update_time = NOW()
                 WHERE type IN (1, 2)
                   AND path = '%s'
                   AND (icon IS NULL OR icon = '')
                   AND delete_time IS NULL",
                addslashes($icon),
                addslashes($path)
            ));
        }

        // 机构账号：名称更易发现（仍保留 path=user）
        $this->execute(
            "UPDATE sm_admin_menu
             SET name = '机构用户',
                 icon = COALESCE(NULLIF(icon, ''), 'ri:file-user-line'),
                 update_time = NOW()
             WHERE path = 'user'
               AND component LIKE '%/admin/panel/user/%'
               AND delete_time IS NULL"
        );

        // 平台账号管理
        $this->execute(
            "UPDATE sm_admin_menu
             SET name = '平台账号',
                 icon = COALESCE(NULLIF(icon, ''), 'ri:user-settings-line'),
                 update_time = NOW()
             WHERE path = 'user'
               AND component LIKE '%/admin/system/user/%'
               AND delete_time IS NULL"
        );

        // 开关类配置在后台用 radio 渲染更稳妥（避免无 switch 控件时无法操作）
        if ($this->hasTable('sm_system_config')) {
            $this->execute(
                "UPDATE sm_system_config
                 SET input_type = 'radio',
                     update_time = NOW()
                 WHERE input_type = 'switch'
                   AND delete_time IS NULL"
            );
        }
    }

    public function down(): void
    {
        // 开发版：不回滚名称与 icon 回填
    }
}
