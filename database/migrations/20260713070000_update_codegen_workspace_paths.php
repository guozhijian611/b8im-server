<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpdateCodegenWorkspacePaths extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE `saimulti_table` SET `generate_path` = CASE `stub` "
            . "WHEN 'admin' THEN 'b8im-admin-vue' ELSE 'b8im-tenant-vue' END "
            . "WHERE `stub` IN ('admin', 'tenant')",
        );
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE `saimulti_table` SET `generate_path` = CASE `stub` "
            . "WHEN 'admin' THEN 'admin-vue' ELSE 'tenant-vue' END "
            . "WHERE `stub` IN ('admin', 'tenant')",
        );
    }
}
