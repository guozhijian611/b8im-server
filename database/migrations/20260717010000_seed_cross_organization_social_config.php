<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Platform switch: cross-organization friends + single chat (default off).
 */
final class SeedCrossOrganizationSocialConfig extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->execute(sprintf(
            <<<'SQL'
INSERT INTO `sm_system_config_group` (`name`, `code`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`)
SELECT '社交边界配置', 'social_config', '跨租户好友与单聊等平台级社交边界', 1, 1, '%s', '%s'
WHERE NOT EXISTS (
    SELECT 1 FROM `sm_system_config_group` WHERE `code` = 'social_config' AND `delete_time` IS NULL
)
SQL,
            $now,
            $now,
        ));

        $groupId = (int) $this->fetchRow(
            "SELECT id FROM sm_system_config_group WHERE code = 'social_config' AND delete_time IS NULL LIMIT 1",
        )['id'];

        $exists = $this->fetchRow(
            "SELECT id FROM sm_system_config WHERE group_id = {$groupId} AND `key` = 'cross_org_social_enabled' AND delete_time IS NULL LIMIT 1",
        );
        if ($exists === null) {
            $this->execute(sprintf(
                <<<'SQL'
INSERT INTO `sm_system_config`
  (`group_id`, `key`, `value`, `name`, `input_type`, `config_select_data`, `sort`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`)
VALUES
  (%d, 'cross_org_social_enabled', '0', '允许跨租户好友与单聊', 'radio', '[{"label":"关闭","value":"0"},{"label":"开启","value":"1"}]', 100, '平台总开关。关闭时拒绝跨 organization 好友申请/接受与单聊发送；开启后允许并在跨租户联系人展示公司名称。', 1, 1, '%s', '%s')
SQL,
                $groupId,
                $now,
                $now,
            ));
        }
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM `sm_system_config` WHERE `key` = 'cross_org_social_enabled'",
        );
        $this->execute(
            "DELETE FROM `sm_system_config_group` WHERE `code` = 'social_config'",
        );
    }
}
