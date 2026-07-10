<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddOrganizationDiscoveryContract extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `sm_system_organization`
  ADD COLUMN `enterprise_code` varchar(64) NULL COMMENT '公开企业码' AFTER `domain`,
  ADD COLUMN `deployment_id` varchar(64) NULL COMMENT '目标部署信任域' AFTER `enterprise_code`,
  ADD COLUMN `config_version` bigint(20) UNSIGNED NOT NULL DEFAULT 1 COMMENT '公开配置版本' AFTER `deployment_id`,
  ADD COLUMN `favicon` varchar(512) NOT NULL DEFAULT '' COMMENT '站点图标' AFTER `logo`,
  ADD COLUMN `icp` varchar(128) NOT NULL DEFAULT '' COMMENT 'ICP 备案号' AFTER `favicon`,
  ADD COLUMN `public_security_record_no` varchar(128) NOT NULL DEFAULT '' COMMENT '公安备案号' AFTER `icp`,
  ADD COLUMN `public_security_record_url` varchar(512) NOT NULL DEFAULT '' COMMENT '公安备案链接' AFTER `public_security_record_no`,
  ADD COLUMN `copyright` varchar(255) NOT NULL DEFAULT '' COMMENT '版权信息' AFTER `public_security_record_url`,
  ADD COLUMN `android_download_url` varchar(512) NOT NULL DEFAULT '' COMMENT 'Android 下载地址' AFTER `copyright`,
  ADD COLUMN `ios_download_url` varchar(512) NOT NULL DEFAULT '' COMMENT 'iOS 下载地址' AFTER `android_download_url`,
  ADD COLUMN `api_server_url` varchar(512) NOT NULL DEFAULT '' COMMENT 'API 服务地址' AFTER `ios_download_url`,
  ADD COLUMN `im_server_url` varchar(512) NOT NULL DEFAULT '' COMMENT 'IM 服务地址' AFTER `api_server_url`,
  ADD COLUMN `upload_server_url` varchar(512) NOT NULL DEFAULT '' COMMENT '上传服务地址' AFTER `im_server_url`,
  ADD COLUMN `web_server_url` varchar(512) NOT NULL DEFAULT '' COMMENT 'Web 服务地址' AFTER `upload_server_url`,
  ADD COLUMN `user_agreement_title` varchar(128) NOT NULL DEFAULT '用户协议' COMMENT '用户协议标题' AFTER `web_server_url`,
  ADD COLUMN `user_agreement_content` longtext NULL COMMENT '用户协议内容' AFTER `user_agreement_title`,
  ADD COLUMN `privacy_policy_title` varchar(128) NOT NULL DEFAULT '隐私政策' COMMENT '隐私政策标题' AFTER `user_agreement_content`,
  ADD COLUMN `privacy_policy_content` longtext NULL COMMENT '隐私政策内容' AFTER `privacy_policy_title`;
SQL);

        $this->execute(<<<'SQL'
UPDATE `sm_system_organization`
SET
  `domain` = NULLIF(LOWER(TRIM(`domain`)), ''),
  `enterprise_code` = CONCAT('org_', `id`),
  `deployment_id` = 'b8im-local',
  `config_version` = 1;
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE `sm_system_organization`
  MODIFY COLUMN `enterprise_code` varchar(64) NOT NULL COMMENT '公开企业码',
  MODIFY COLUMN `deployment_id` varchar(64) NOT NULL COMMENT '目标部署信任域',
  ADD UNIQUE KEY `uk_sm_org_enterprise_code` (`enterprise_code`),
  ADD UNIQUE KEY `uk_sm_org_domain` (`domain`);
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `sm_system_organization`
  DROP INDEX `uk_sm_org_enterprise_code`,
  DROP INDEX `uk_sm_org_domain`,
  DROP COLUMN `privacy_policy_content`,
  DROP COLUMN `privacy_policy_title`,
  DROP COLUMN `user_agreement_content`,
  DROP COLUMN `user_agreement_title`,
  DROP COLUMN `web_server_url`,
  DROP COLUMN `upload_server_url`,
  DROP COLUMN `im_server_url`,
  DROP COLUMN `api_server_url`,
  DROP COLUMN `ios_download_url`,
  DROP COLUMN `android_download_url`,
  DROP COLUMN `copyright`,
  DROP COLUMN `public_security_record_url`,
  DROP COLUMN `public_security_record_no`,
  DROP COLUMN `icp`,
  DROP COLUMN `favicon`,
  DROP COLUMN `config_version`,
  DROP COLUMN `deployment_id`,
  DROP COLUMN `enterprise_code`;
SQL);
    }
}
