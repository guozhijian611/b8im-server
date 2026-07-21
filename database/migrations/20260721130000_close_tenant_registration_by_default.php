<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CloseTenantRegistrationByDefault extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('sm_tenant_account_policy')) {
            throw new RuntimeException('Tenant account policy table is missing.');
        }
        $table = $this->table('sm_tenant_account_policy');
        foreach (['register_enabled', 'version', 'update_time'] as $column) {
            if (!$table->hasColumn($column)) {
                throw new RuntimeException("Tenant account policy column {$column} is missing.");
            }
        }
        $this->execute(<<<'SQL'
ALTER TABLE `sm_tenant_account_policy`
  MODIFY COLUMN `register_enabled` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否开放注册'
SQL);
        $this->execute(<<<'SQL'
UPDATE `sm_tenant_account_policy`
   SET `register_enabled` = 0,
       `version` = `version` + 1,
       `update_time` = CURRENT_TIMESTAMP
 WHERE `register_enabled` <> 0
SQL);
    }

    public function down(): void
    {
        // Previous per-tenant values are intentionally not reconstructed.
        // A rollback preserves the security invariant; restoring old data requires a pre-migration snapshot.
        $this->up();
    }
}
