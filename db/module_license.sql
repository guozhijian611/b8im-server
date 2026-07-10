/*
 b8im 已安装模块与租户启用体系
 DDL 迁移文件

 执行目标库: nb8im
 依赖: sm_system_organization 已存在

 说明：
   - sm_module               : 系统已安装模块注册表（全局唯一）
   - sm_tenant_module_license: 租户运行时模块启用状态（唯一执行边界）

 授权流程：
   1. 安装模块 → 写 sm_module（可通过安装命令自动完成）
   2. 管理员后台对目标租户启用模块 → 写 sm_tenant_module_license
   3. IM 链路 / 控制面 API 实时查 sm_tenant_module_license 做鉴权
      （IM 进程带 Redis 长缓存，后台变更租户能力边界后主动刷新）

 对于源码交付客户：
   他们自己是平台管理员，同样走第 2 步对自己的租户启用模块，无需加密 license key。
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 平台已安装模块注册表
-- 每个商业模块安装后在此登记，卸载时软删除。
-- module_key 由模块包自己声明（如 customer_service / rtc / i18n）。
-- ----------------------------
DROP TABLE IF EXISTS `sm_module`;
CREATE TABLE `sm_module` (
  `id`          int(11) UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '编号',
  `module_key`  varchar(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '模块唯一标识（如 customer_service）',
  `name`        varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '模块名称',
  `version`     varchar(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '已安装版本',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NULL DEFAULT NULL COMMENT '描述',
  `status`      tinyint(1)   NULL DEFAULT 1   COMMENT '状态：1 启用  2 停用',
  `sort`        smallint(4)  NULL DEFAULT 100 COMMENT '排序',
  `installed_at` datetime    NULL DEFAULT NULL COMMENT '安装时间',
  `create_time` datetime     NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime     NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime     NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_module_key` (`module_key`) USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci
  COMMENT = '已安装模块注册表'
  ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- 租户模块运行时启用表
-- 这是唯一运行时执行边界，IM 和控制面 API 均以此为准。
-- 前端展示/入口控制不作为权限依据。
-- expire_at NULL = 永不过期（源码交付客户通常如此设置）。
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_module_license`;
CREATE TABLE `sm_tenant_module_license` (
  `id`           int(11) UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '编号',
  `organization` int(11) UNSIGNED  NOT NULL COMMENT '机构编号',
  `module_key`   varchar(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '模块标识',
  `status`       tinyint(1)   NULL DEFAULT 1    COMMENT '状态：1 启用  2 停用',
  `expire_at`    datetime     NULL DEFAULT NULL  COMMENT '启用到期时间（NULL = 永不过期）',
  `granted_by`   int(11)      NULL DEFAULT NULL  COMMENT '启用操作人 admin user_id',
  `remark`       varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NULL DEFAULT NULL COMMENT '备注',
  `create_time`  datetime     NULL DEFAULT NULL COMMENT '创建时间',
  `update_time`  datetime     NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time`  datetime     NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_org_module` (`organization`, `module_key`) USING BTREE,
  INDEX `idx_organization` (`organization`) USING BTREE,
  INDEX `idx_module_key`   (`module_key`)   USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci
  COMMENT = '租户模块运行时启用表'
  ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- 开发环境种子数据
-- 注册客服模块 + 给 organization=1（京北商城）开通授权
-- ----------------------------
INSERT INTO `sm_module` (`module_key`, `name`, `version`, `description`, `status`, `sort`, `installed_at`, `create_time`, `update_time`)
VALUES ('customer_service', '客服模块', '1.0.0', '提供客服入口、会话分配、坐席工作台、访客接入和客服消息能力', 1, 10, NOW(), NOW(), NOW());

INSERT INTO `sm_tenant_module_license` (`organization`, `module_key`, `status`, `expire_at`, `granted_by`, `remark`, `create_time`, `update_time`)
VALUES (1, 'customer_service', 1, NULL, 1, '开发环境初始启用', NOW(), NOW());

SET FOREIGN_KEY_CHECKS = 1;
