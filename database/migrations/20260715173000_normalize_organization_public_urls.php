<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class NormalizeOrganizationPublicUrls extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
UPDATE `sm_system_organization`
SET
  `logo` = CASE WHEN COALESCE(`logo`, '') <> '' AND LOWER(`logo`) NOT LIKE 'https://%' THEN '' ELSE `logo` END,
  `favicon` = CASE WHEN COALESCE(`favicon`, '') <> '' AND LOWER(`favicon`) NOT LIKE 'https://%' THEN '' ELSE `favicon` END,
  `public_security_record_url` = CASE WHEN COALESCE(`public_security_record_url`, '') <> '' AND LOWER(`public_security_record_url`) NOT LIKE 'https://%' THEN '' ELSE `public_security_record_url` END,
  `android_download_url` = CASE WHEN COALESCE(`android_download_url`, '') <> '' AND LOWER(`android_download_url`) NOT LIKE 'https://%' THEN '' ELSE `android_download_url` END,
  `ios_download_url` = CASE WHEN COALESCE(`ios_download_url`, '') <> '' AND LOWER(`ios_download_url`) NOT LIKE 'https://%' THEN '' ELSE `ios_download_url` END,
  `config_version` = `config_version` + 1,
  `update_time` = NOW()
WHERE
  (COALESCE(`logo`, '') <> '' AND LOWER(`logo`) NOT LIKE 'https://%')
  OR (COALESCE(`favicon`, '') <> '' AND LOWER(`favicon`) NOT LIKE 'https://%')
  OR (COALESCE(`public_security_record_url`, '') <> '' AND LOWER(`public_security_record_url`) NOT LIKE 'https://%')
  OR (COALESCE(`android_download_url`, '') <> '' AND LOWER(`android_download_url`) NOT LIKE 'https://%')
  OR (COALESCE(`ios_download_url`, '') <> '' AND LOWER(`ios_download_url`) NOT LIKE 'https://%')
SQL);
    }

    public function down(): void
    {
        // Reintroducing insecure public URLs would violate the discovery contract.
    }
}
