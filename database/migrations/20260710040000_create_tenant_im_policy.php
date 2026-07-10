<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTenantImPolicy extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE `sm_tenant_im_policy` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NOT NULL COMMENT '机构编号',
  `allowed_client_families_json` json NOT NULL COMMENT '允许的客户端形态',
  `allow_multi_device_online` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否允许多设备同时在线',
  `max_online_devices` int(10) UNSIGNED NOT NULL DEFAULT 5 COMMENT '单用户最大在线设备数',
  `same_device_login_policy` varchar(16) NOT NULL DEFAULT 'replace' COMMENT '同设备登录策略',
  `cross_device_login_policy` varchar(16) NOT NULL DEFAULT 'allow' COMMENT '跨设备登录策略',
  `max_message_concurrency` int(10) UNSIGNED NOT NULL DEFAULT 8 COMMENT '单用户消息并发上限',
  `max_message_qps` int(10) UNSIGNED NOT NULL DEFAULT 20 COMMENT '单用户每秒消息上限',
  `default_group_display_member_count` int(10) UNSIGNED NOT NULL DEFAULT 50 COMMENT '群默认展示成员数',
  `message_recall_window_seconds` int(10) UNSIGNED NOT NULL DEFAULT 120 COMMENT '消息撤回窗口秒数',
  `message_edit_window_seconds` int(10) UNSIGNED NOT NULL DEFAULT 120 COMMENT '消息编辑窗口秒数',
  `recall_notice_enabled` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否生成撤回提醒',
  `group_recall_notice_enabled` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否生成群撤回提醒',
  `status` varchar(16) NOT NULL DEFAULT 'ENABLED' COMMENT '策略状态',
  `version` bigint(20) UNSIGNED NOT NULL DEFAULT 1 COMMENT '乐观锁版本',
  `create_time` datetime NOT NULL COMMENT '创建时间',
  `update_time` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_sm_tenant_im_policy_organization` (`organization`) USING BTREE,
  KEY `idx_sm_tenant_im_policy_status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='租户 IM 运行策略' ROW_FORMAT=DYNAMIC;
SQL);

        $now = date('Y-m-d H:i:s');
        $this->execute(sprintf(
            <<<'SQL'
INSERT INTO `sm_tenant_im_policy` (
  `organization`, `allowed_client_families_json`, `allow_multi_device_online`,
  `max_online_devices`, `same_device_login_policy`, `cross_device_login_policy`,
  `max_message_concurrency`, `max_message_qps`, `default_group_display_member_count`,
  `message_recall_window_seconds`, `message_edit_window_seconds`,
  `recall_notice_enabled`, `group_recall_notice_enabled`, `status`, `version`,
  `create_time`, `update_time`
)
SELECT
  `id`, JSON_ARRAY('web', 'app', 'desktop'), 1,
  5, 'replace', 'allow',
  8, 20, 50,
  120, 120,
  1, 1, 'ENABLED', 1,
  %s, %s
FROM `sm_system_organization`
WHERE `id` > 0 AND `delete_time` IS NULL;
SQL,
            $this->getAdapter()->getConnection()->quote($now),
            $this->getAdapter()->getConnection()->quote($now),
        ));
    }

    public function down(): void
    {
        $this->execute('DROP TABLE `sm_tenant_im_policy`');
    }
}
