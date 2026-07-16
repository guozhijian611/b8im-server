<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Admin system-config form only renders select/input/radio/textarea/upload/wangEditor.
 * Persist cross-org social switch as radio so 总后台 can toggle it.
 */
final class FixCrossOrgSocialInputTypeRadio extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
UPDATE `sm_system_config`
   SET `input_type` = 'radio',
       `config_select_data` = '[{"label":"关闭","value":"0"},{"label":"开启","value":"1"}]',
       `update_time` = CURRENT_TIMESTAMP
 WHERE `key` = 'cross_org_social_enabled'
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
UPDATE `sm_system_config`
   SET `input_type` = 'switch',
       `update_time` = CURRENT_TIMESTAMP
 WHERE `key` = 'cross_org_social_enabled'
SQL);
    }
}
