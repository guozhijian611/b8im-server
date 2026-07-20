<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCrossOrgAccessSnapshotConfig extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->execute(sprintf(
            <<<'SQL'
INSERT INTO `sm_system_config`
  (`group_id`, `key`, `value`, `name`, `input_type`, `sort`, `remark`,
   `created_by`, `updated_by`, `create_time`, `update_time`)
SELECT g.id, 'cross_org_access_snapshot_id', '0',
       '跨租户社交访问快照序号', 'hidden', 99,
       '系统管理的十进制单调序号；跨租户社交开关实际变化时原子递增，禁止人工修改。',
       1, 1, '%s', '%s'
  FROM `sm_system_config_group` g
 WHERE g.code = 'social_config'
   AND g.delete_time IS NULL
   AND NOT EXISTS (
       SELECT 1
         FROM `sm_system_config` c
        WHERE c.group_id = g.id
          AND c.`key` = 'cross_org_access_snapshot_id'
          AND c.delete_time IS NULL
   )
 LIMIT 1
SQL,
            $now,
            $now,
        ));
        $this->execute(<<<'SQL'
DELETE duplicate_config
  FROM `sm_system_config` duplicate_config
  INNER JOIN `sm_system_config` retained_config
          ON retained_config.group_id = duplicate_config.group_id
         AND retained_config.`key` = duplicate_config.`key`
         AND retained_config.id < duplicate_config.id
         AND retained_config.delete_time IS NULL
 WHERE duplicate_config.delete_time IS NULL
   AND duplicate_config.`key` IN (
       'cross_org_social_enabled',
       'cross_org_access_snapshot_id'
   )
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE `sm_system_config`
  ADD COLUMN `cross_org_managed_key_guard` varchar(64)
    GENERATED ALWAYS AS (
      CASE
        WHEN `delete_time` IS NULL
         AND `key` IN ('cross_org_social_enabled', 'cross_org_access_snapshot_id')
        THEN CONCAT(COALESCE(`group_id`, 0), ':', `key`)
        ELSE NULL
      END
    ) STORED,
  ADD UNIQUE KEY `uk_sm_config_cross_org_managed_key`
    (`cross_org_managed_key_guard`)
SQL);
    }

    public function down(): void
    {
        if ($this->hasTable('im_message_outbox')) {
            $this->execute(<<<'SQL'
DELETE FROM `im_message_outbox`
 WHERE `event_type` = 'conversation.access_changed'
SQL);
        }
        $this->execute(<<<'SQL'
ALTER TABLE `sm_system_config`
  DROP INDEX `uk_sm_config_cross_org_managed_key`,
  DROP COLUMN `cross_org_managed_key_guard`
SQL);
        $this->execute(<<<'SQL'
DELETE c
  FROM `sm_system_config` c
  INNER JOIN `sm_system_config_group` g ON g.id = c.group_id
 WHERE g.code = 'social_config'
   AND c.`key` = 'cross_org_access_snapshot_id'
SQL);
    }
}
