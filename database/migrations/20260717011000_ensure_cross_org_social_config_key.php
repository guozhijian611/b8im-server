<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ensure platform cross-org social switch key exists (idempotent repair).
 */
final class EnsureCrossOrgSocialConfigKey extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->execute(sprintf(
            <<<'SQL'
INSERT INTO `sm_system_config_group` (`name`, `code`, `type`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`)
SELECT '社交边界配置', 'social_config', 1, '跨租户好友与单聊等平台级社交边界', 1, 1, '%s', '%s'
WHERE NOT EXISTS (
    SELECT 1 FROM `sm_system_config_group` WHERE `code` = 'social_config' AND `delete_time` IS NULL
)
SQL,
            $now,
            $now,
        ));

        $this->execute(sprintf(
            <<<'SQL'
INSERT INTO `sm_system_config`
  (`group_id`, `key`, `value`, `name`, `input_type`, `config_select_data`, `sort`, `remark`, `created_by`, `updated_by`, `create_time`, `update_time`)
SELECT g.id, 'cross_org_social_enabled', '0', '允许跨租户好友与单聊', 'radio',
       '[{"label":"关闭","value":"0"},{"label":"开启","value":"1"}]', 100,
       '平台总开关。关闭时拒绝跨 organization 好友申请/接受与单聊发送；开启后允许并在跨租户联系人展示公司名称。',
       1, 1, '%s', '%s'
  FROM `sm_system_config_group` g
 WHERE g.code = 'social_config'
   AND g.delete_time IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM `sm_system_config` c
        WHERE c.group_id = g.id
          AND c.`key` = 'cross_org_social_enabled'
          AND c.delete_time IS NULL
   )
 LIMIT 1
SQL,
            $now,
            $now,
        ));
    }

    public function down(): void
    {
        // Keep config; down of seed migration removes it.
    }
}
