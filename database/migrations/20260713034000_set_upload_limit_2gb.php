<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SetUploadLimit2gb extends AbstractMigration
{
    private const MAX_UPLOAD_BYTES = '2147483648';

    public function up(): void
    {
        $this->execute(sprintf(
            "UPDATE `sm_system_config`
                SET `value` = '%s',
                    `remark` = '单位Byte，单文件绝对上限2GiB；大文件使用S3直传分片',
                    `update_time` = CURRENT_TIMESTAMP
              WHERE `key` = 'upload_size'",
            self::MAX_UPLOAD_BYTES,
        ));
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE `sm_system_config`
                SET `value` = '5242880',
                    `remark` = '单位Byte,1MB=1024*1024Byte',
                    `update_time` = CURRENT_TIMESTAMP
              WHERE `key` = 'upload_size'",
        );
    }
}
