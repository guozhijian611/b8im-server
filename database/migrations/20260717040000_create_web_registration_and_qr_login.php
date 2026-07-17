<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateWebRegistrationAndQrLogin extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE `sm_tenant_account_policy` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NOT NULL COMMENT '机构编号',
  `register_enabled` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否开放注册',
  `invite_required` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否要求邀请码',
  `tenant_invite_enabled` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否启用机构邀请码',
  `user_invite_enabled` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否启用用户邀请码',
  `email_verify_enabled` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否要求邮箱验证',
  `mobile_verify_enabled` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否要求手机验证',
  `email_provider_config_id` bigint(20) UNSIGNED NULL DEFAULT NULL COMMENT '邮件服务配置编号',
  `sms_provider_config_id` bigint(20) UNSIGNED NULL DEFAULT NULL COMMENT '短信服务配置编号',
  `realname_required` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否要求实名',
  `invite_code_mode` varchar(24) NOT NULL DEFAULT 'tenant_single' COMMENT '邀请码模式',
  `invite_auto_friend` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '邀请后是否自动加好友',
  `invite_bind_customer_service` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '邀请后是否绑定客服',
  `status` varchar(16) NOT NULL DEFAULT 'ENABLED' COMMENT '策略状态',
  `version` bigint(20) UNSIGNED NOT NULL DEFAULT 1 COMMENT '乐观锁版本',
  `create_time` datetime NOT NULL COMMENT '创建时间',
  `update_time` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_tenant_account_policy_organization` (`organization`) USING BTREE,
  KEY `idx_tenant_account_policy_status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='租户账号准入策略' ROW_FORMAT=DYNAMIC;
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE `im_web_qr_login` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NOT NULL COMMENT '机构编号',
  `deployment_id` varchar(64) NOT NULL COMMENT '部署信任域',
  `qr_id` char(32) NOT NULL COMMENT '二维码业务标识',
  `browser_token_hash` char(64) NOT NULL COMMENT '浏览器令牌SHA-256摘要',
  `scan_token_hash` char(64) NOT NULL COMMENT '扫码令牌SHA-256摘要',
  `browser_device_id` varchar(100) NOT NULL COMMENT '浏览器设备ID',
  `browser_origin` varchar(255) NOT NULL COMMENT '发起登录的Web站点',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending,scanned,confirmed,consumed,cancelled,expired',
  `app_im_user_id` bigint(20) UNSIGNED NULL DEFAULT NULL COMMENT '绑定App用户主键',
  `app_user_id` varchar(64) NULL DEFAULT NULL COMMENT '绑定App用户ID',
  `app_device_id` varchar(100) NULL DEFAULT NULL COMMENT '绑定App设备ID',
  `expires_at` datetime NOT NULL COMMENT '二维码失效时间',
  `scanned_at` datetime NULL DEFAULT NULL COMMENT '扫码时间',
  `confirmed_at` datetime NULL DEFAULT NULL COMMENT '确认时间',
  `consumed_at` datetime NULL DEFAULT NULL COMMENT '消费时间',
  `cancelled_at` datetime NULL DEFAULT NULL COMMENT '取消时间',
  `create_time` datetime NOT NULL COMMENT '创建时间',
  `update_time` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_web_qr_login_qr_id` (`qr_id`) USING BTREE,
  UNIQUE KEY `uk_web_qr_login_browser_token` (`browser_token_hash`) USING BTREE,
  UNIQUE KEY `uk_web_qr_login_scan_token` (`scan_token_hash`) USING BTREE,
  KEY `idx_web_qr_login_scope_status` (`organization`, `deployment_id`, `status`) USING BTREE,
  KEY `idx_web_qr_login_expiry` (`status`, `expires_at`) USING BTREE,
  KEY `idx_web_qr_login_app_binding` (`organization`, `app_user_id`, `app_device_id`, `status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Web一次性扫码登录状态' ROW_FORMAT=DYNAMIC;
SQL);

        $now = $this->getAdapter()->getConnection()->quote(date('Y-m-d H:i:s'));
        $this->execute(<<<SQL
INSERT INTO `sm_tenant_account_policy` (
  `organization`, `register_enabled`, `invite_required`, `tenant_invite_enabled`,
  `user_invite_enabled`, `email_verify_enabled`, `mobile_verify_enabled`,
  `realname_required`, `invite_code_mode`, `invite_auto_friend`,
  `invite_bind_customer_service`, `status`, `version`, `create_time`, `update_time`
)
SELECT
  `id`, 1, 0, 0,
  0, 0, 0,
  0, 'tenant_single', 0,
  0, 'ENABLED', 1, {$now}, {$now}
FROM `sm_system_organization`
WHERE `id` > 0 AND `status` = 1 AND `delete_time` IS NULL;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `im_web_qr_login`');
        $this->execute('DROP TABLE IF EXISTS `sm_tenant_account_policy`');
    }
}
