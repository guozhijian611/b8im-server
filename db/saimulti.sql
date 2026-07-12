/*
 Navicat Premium Dump SQL

 Source Server         : 本地
 Source Server Type    : MySQL
 Source Server Version : 50744 (5.7.44-log)
 Source Host           : localhost:3306
 Source Schema         : saimulti

 Target Server Type    : MySQL
 Target Server Version : 50744 (5.7.44-log)
 File Encoding         : 65001

 Date: 26/04/2026 00:31:22
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Phinx baseline represented by this full schema snapshot
-- ----------------------------
DROP TABLE IF EXISTS `phinxlog`;
CREATE TABLE `phinxlog` (
  `version` bigint NOT NULL,
  `migration_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `start_time` timestamp NULL DEFAULT NULL,
  `end_time` timestamp NULL DEFAULT NULL,
  `breakpoint` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`version`)
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

INSERT INTO `phinxlog` VALUES (
  20260710020000,
  'AddOrganizationDiscoveryContract',
  '2026-07-10 00:00:00',
  '2026-07-10 00:00:00',
  0
);

-- ----------------------------
-- Table structure for saimulti_column
-- ----------------------------
DROP TABLE IF EXISTS `saimulti_column`;
CREATE TABLE `saimulti_column`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `table_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '所属表ID',
  `column_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字段名称',
  `column_comment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字段注释',
  `column_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字段类型',
  `column_width` int(11) NULL DEFAULT 0 COMMENT '列表宽度',
  `default_value` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '默认值',
  `is_pk` smallint(6) NULL DEFAULT 1 COMMENT '1 非主键 2 主键',
  `is_required` smallint(6) NULL DEFAULT 1 COMMENT '1 非必填 2 必填',
  `is_insert` smallint(6) NULL DEFAULT 1 COMMENT '1 非插入字段 2 插入字段',
  `is_edit` smallint(6) NULL DEFAULT 1 COMMENT '1 非编辑字段 2 编辑字段',
  `is_list` smallint(6) NULL DEFAULT 1 COMMENT '1 非列表显示字段 2 列表显示字段',
  `is_query` smallint(6) NULL DEFAULT 1 COMMENT '1 非查询字段 2 查询字段',
  `is_sort` smallint(6) NULL DEFAULT 1 COMMENT '1 非排序 2 排序',
  `query_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'eq' COMMENT '查询方式',
  `view_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'text' COMMENT '页面控件',
  `dict_type` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字典类型',
  `options` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '字段其他设置',
  `list_sort` smallint(6) UNSIGNED NULL DEFAULT 0 COMMENT '列表排序',
  `span` smallint(6) NULL DEFAULT NULL COMMENT '布局',
  `form_sort` smallint(6) UNSIGNED NULL DEFAULT 0 COMMENT '字段排序',
  `query_component` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'input' COMMENT '查询控件',
  `query_dict` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '查询字典',
  `query_span` int(11) NULL DEFAULT 6 COMMENT '搜索栅格宽度',
  `query_sort` int(11) NULL DEFAULT 0 COMMENT '搜索排序',
  `query_options` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '查询属性配置',
  `table_field` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '列表名称',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 113 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '代码生成业务字段表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of saimulti_column
-- ----------------------------
INSERT INTO `saimulti_column` VALUES (1, 1, 'id', '编号', 'int', 180, NULL, 2, 2, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 0, NULL, 1300, NULL, '', 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (2, 1, 'organization', '机构编号', 'int', 180, '0', 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 1, NULL, 1400, NULL, '', 6, 1, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (3, 1, 'category_id', '文章分类', 'int', 180, NULL, 1, 2, 2, 1, 1, 2, 1, 'eq', 'treeSelect', NULL, '{\"check_strictly\":false,\"field_label\":\"category_name\",\"field_value\":\"id\",\"url\":\"\\/cms\\/\\/ArticleCategory\\/index\"}', 2, NULL, 100, 'treeSelect', '', 6, 2, '{\"check_strictly\":false,\"field_label\":\"category_name\",\"field_value\":\"id\",\"url\":\"\\/cms\\/article\\/ArticleCategory\\/index\"}', NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (4, 1, 'title', '文章标题', 'varchar', 180, '', 1, 2, 2, 2, 2, 2, 1, 'like', 'input', NULL, NULL, 3, NULL, 200, 'input', '', 6, 3, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (5, 1, 'author', '文章作者', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 4, NULL, 300, NULL, '', 6, 4, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (6, 1, 'image', '文章图片', 'varchar', 120, '', 1, 1, 2, 2, 2, 1, 1, 'eq', 'uploadImage', NULL, '{\"multiple\":false,\"limit\":3}', 5, NULL, 400, NULL, '', 6, 5, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (7, 1, 'describe', '文章简介', 'varchar', 180, NULL, 1, 2, 2, 2, 2, 1, 1, 'eq', 'textarea', NULL, NULL, 6, NULL, 500, NULL, '', 6, 6, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (8, 1, 'content', '文章内容', 'text', 180, NULL, 1, 2, 2, 2, 1, 1, 1, 'eq', 'editor', NULL, '{\"height\":400}', 7, NULL, 600, NULL, '', 6, 7, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (9, 1, 'views', '浏览次数', 'int', 180, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'inputNumber', NULL, '{\"min\":1,\"max\":100,\"step\":1}', 8, NULL, 700, NULL, '', 6, 8, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (10, 1, 'sort', '排序', 'int', 180, '100', 1, 1, 2, 2, 2, 1, 1, 'eq', 'inputNumber', NULL, '{\"min\":1,\"max\":100,\"step\":1}', 9, NULL, 800, NULL, '', 6, 9, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (11, 1, 'status', '状态', 'tinyint', 180, '1', 1, 1, 2, 2, 2, 2, 1, 'eq', 'radio', 'data_status', NULL, 10, NULL, 900, 'saSelect', 'data_status', 6, 10, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (12, 1, 'is_link', '是否外链', 'tinyint', 180, '2', 1, 1, 2, 2, 1, 1, 1, 'eq', 'radio', 'yes_or_no', NULL, 11, NULL, 1000, NULL, '', 6, 11, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (13, 1, 'link_url', '链接地址', 'varchar', 180, NULL, 1, 1, 2, 2, 1, 1, 1, 'eq', 'input', NULL, NULL, 12, NULL, 1100, NULL, '', 6, 12, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (14, 1, 'is_hot', '是否热门', 'tinyint', 180, '2', 1, 1, 2, 2, 1, 1, 1, 'eq', 'radio', 'yes_or_no', NULL, 13, NULL, 1200, NULL, '', 6, 13, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (15, 1, 'create_time', '创建时间', 'datetime', 180, NULL, 1, 1, 1, 2, 2, 1, 2, 'between', 'date', NULL, '{\"mode\":\"date\",\"showTime\":true}', 14, NULL, 1500, NULL, '', 6, 14, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (16, 1, 'update_time', '修改时间', 'datetime', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"date\",\"showTime\":true}', 15, NULL, 1600, NULL, '', 6, 15, NULL, NULL, NULL, NULL, NULL, '2026-04-15 17:09:07', '2026-04-15 17:09:07', NULL);
INSERT INTO `saimulti_column` VALUES (17, 2, 'id', '编号', 'int', 180, NULL, 2, 2, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 0, NULL, 0, NULL, '', 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (18, 2, 'organization', '机构编号', 'int', 180, '0', 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 1, NULL, 1, NULL, '', 6, 1, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (19, 2, 'parent_id', '上级菜单', 'int', 180, '0', 1, 2, 2, 2, 2, 1, 1, 'eq', 'treeSelect', NULL, '{\"check_strictly\":false,\"field_label\":\"label\",\"field_value\":\"value\",\"url\":\"\"}', 2, NULL, 2, NULL, '', 6, 2, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (20, 2, 'category_name', '分类标题', 'varchar', 180, NULL, 1, 2, 2, 2, 2, 2, 1, 'like', 'input', NULL, NULL, 3, NULL, 3, 'input', '', 6, 3, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (21, 2, 'describe', '分类简介', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'textarea', NULL, NULL, 4, NULL, 4, NULL, '', 6, 4, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (22, 2, 'image', '分类图片', 'varchar', 120, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'uploadImage', NULL, '{\"multiple\":false,\"limit\":1}', 5, NULL, 5, NULL, '', 6, 5, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (23, 2, 'sort', '排序', 'int', 180, '100', 1, 1, 2, 2, 2, 1, 1, 'eq', 'inputNumber', NULL, '{\"min\":1,\"max\":100,\"step\":1}', 6, NULL, 6, NULL, '', 6, 6, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (24, 2, 'status', '状态', 'tinyint', 180, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'radio', 'data_status', NULL, 7, NULL, 7, NULL, '', 6, 7, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (25, 2, 'create_time', '创建时间', 'datetime', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 8, NULL, 8, NULL, '', 6, 8, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (26, 2, 'update_time', '修改时间', 'datetime', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 9, NULL, 9, NULL, '', 6, 9, NULL, NULL, NULL, NULL, NULL, '2026-04-16 13:06:00', '2026-04-16 13:06:00', NULL);
INSERT INTO `saimulti_column` VALUES (27, 3, 'id', '编号', 'int', 180, NULL, 2, 2, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 0, NULL, 600, NULL, '', 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-16 15:37:35', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (28, 3, 'group_name', '分组名称', 'varchar', 180, NULL, 1, 2, 2, 2, 2, 2, 1, 'like', 'input', NULL, NULL, 1, NULL, 100, 'input', '', 6, 1, NULL, NULL, NULL, NULL, NULL, '2026-04-16 15:37:35', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (29, 3, 'remark', '描述', 'varchar', 180, NULL, 1, 1, 2, 1, 1, 1, 1, 'eq', 'textarea', NULL, NULL, 2, NULL, 200, NULL, '', 6, 2, NULL, NULL, NULL, NULL, NULL, '2026-04-16 15:37:35', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (30, 3, 'content', '内容详情', 'text', 180, NULL, 1, 1, 2, 2, 1, 1, 1, 'eq', 'editor', NULL, '{\"height\":400}', 3, NULL, 500, NULL, '', 6, 3, NULL, NULL, NULL, NULL, NULL, '2026-04-16 15:37:35', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (31, 3, 'sort', '排序', 'smallint', 180, '100', 1, 1, 2, 2, 2, 1, 1, 'eq', 'inputNumber', NULL, '{\"min\":1,\"max\":100,\"step\":1}', 4, NULL, 400, NULL, '', 6, 4, NULL, NULL, NULL, NULL, NULL, '2026-04-16 15:37:35', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (32, 3, 'status', '状态', 'tinyint', 180, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'radio', 'data_status', NULL, 5, NULL, 300, NULL, '', 6, 5, NULL, NULL, NULL, NULL, NULL, '2026-04-16 15:37:35', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (33, 3, 'create_time', '创建时间', 'datetime', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 6, NULL, 700, NULL, '', 6, 6, NULL, NULL, NULL, NULL, NULL, '2026-04-16 15:37:35', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (34, 3, 'update_time', '修改时间', 'datetime', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 7, NULL, 800, NULL, '', 6, 7, NULL, NULL, NULL, NULL, NULL, '2026-04-16 15:37:35', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (35, 4, 'id', '主键', 'int', 180, NULL, 2, 2, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (36, 4, 'organization', '机构编号', 'int', 180, '0', 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 1, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (37, 4, 'parent_id', '父ID', 'int', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 2, NULL, 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (38, 4, 'level', '组级集合', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 3, NULL, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (39, 4, 'name', '菜单名称', 'varchar', 180, NULL, 1, 2, 2, 2, 2, 2, 1, 'like', 'input', NULL, NULL, 4, NULL, 4, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (40, 4, 'code', '菜单标识代码', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 5, NULL, 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (41, 4, 'icon', '菜单图标', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 6, NULL, 6, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (42, 4, 'route', '路由地址', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 7, NULL, 7, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (43, 4, 'component', '组件路径', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 8, NULL, 8, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (44, 4, 'redirect', '跳转地址', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 9, NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (45, 4, 'is_hidden', '是否隐藏', 'smallint', 180, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'radio', 'yes_or_no', NULL, 10, NULL, 10, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (46, 4, 'type', '菜单类型,', 'char', 180, '', 1, 2, 2, 2, 2, 2, 1, 'eq', 'input', NULL, NULL, 11, NULL, 11, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (47, 4, 'generate_id', '生成id', 'int', 180, '0', 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 12, NULL, 12, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (48, 4, 'generate_key', '生成key', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 13, NULL, 13, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (49, 4, 'status', '状态', 'smallint', 180, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'radio', 'data_status', NULL, 14, NULL, 14, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (50, 4, 'sort', '排序', 'smallint', 180, '0', 1, 1, 2, 2, 2, 1, 1, 'eq', 'inputNumber', NULL, NULL, 15, NULL, 15, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (51, 4, 'remark', '备注', 'varchar', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 16, NULL, 16, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (52, 4, 'create_time', '创建时间', 'datetime', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 17, NULL, 17, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (53, 4, 'update_time', '修改时间', 'datetime', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 18, NULL, 18, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (54, 5, 'id', '用户ID', 'int', 180, NULL, 2, 2, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (55, 5, 'organization', '机构编号', 'int', 180, '1', 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 1, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (56, 5, 'username', '用户名', 'varchar', 180, NULL, 1, 2, 2, 2, 2, 2, 1, 'like', 'input', NULL, NULL, 2, NULL, 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (57, 5, 'password', '密码', 'varchar', 180, NULL, 1, 2, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 3, NULL, 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (58, 5, 'user_type', '用户类型:', 'varchar', 180, '200', 1, 2, 2, 2, 2, 2, 1, 'eq', 'input', NULL, NULL, 4, NULL, 4, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (59, 5, 'nickname', '用户昵称', 'varchar', 180, NULL, 1, 2, 2, 2, 2, 2, 1, 'like', 'input', NULL, NULL, 5, NULL, 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (60, 5, 'phone', '手机', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 6, NULL, 6, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (61, 5, 'email', '用户邮箱', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 7, NULL, 7, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (62, 5, 'avatar', '用户头像', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 8, NULL, 8, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (63, 5, 'signed', '个人签名', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 9, NULL, 9, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (64, 5, 'dashboard', '后台首页类型', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 10, NULL, 10, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (65, 5, 'dept_id', '部门ID', 'int', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 11, NULL, 11, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (66, 5, 'status', '状态', 'smallint', 180, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'radio', 'data_status', NULL, 12, NULL, 12, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (67, 5, 'login_ip', '最后登陆IP', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 13, NULL, 13, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (68, 5, 'login_time', '最后登陆时间', 'datetime', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 14, NULL, 14, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (69, 5, 'backend_setting', '后台设置数据', 'varchar', 180, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 15, NULL, 15, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (70, 5, 'remark', '备注', 'varchar', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 16, NULL, 16, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (71, 5, 'create_time', '创建时间', 'datetime', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 17, NULL, 17, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (72, 5, 'update_time', '修改时间', 'datetime', 180, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 18, NULL, 18, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (73, 6, 'id', '机构编号', 'int', 0, NULL, 2, 2, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 0, NULL, 1500, 'input', '', 6, 500, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (74, 6, 'group_id', '所属分组', 'int', 0, NULL, 1, 2, 2, 2, 2, 2, 1, 'eq', 'input', 'attachment_type', NULL, 1, NULL, 300, 'select', '', 6, 100, '{\"field_label\":\"group_name\",\"field_value\":\"id\",\"url\":\"\\/app\\/saimulti\\/admin\\/admin\\/TenantGroup\\/index?saiType=all\"}', NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (75, 6, 'domain', '域名', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 2, NULL, 400, 'input', '', 6, 600, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (76, 6, 'title', '站点标题', 'varchar', 0, NULL, 1, 2, 2, 2, 2, 1, 1, 'like', 'input', NULL, NULL, 3, NULL, 100, 'input', '', 6, 200, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (77, 6, 'logo', '站点Logo', 'varchar', 0, NULL, 1, 2, 2, 2, 2, 1, 1, 'eq', 'uploadImage', NULL, '{\"multiple\":false,\"limit\":1}', 4, NULL, 200, 'input', '', 6, 700, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (78, 6, 'organization_name', '机构名称', 'varchar', 0, NULL, 1, 2, 2, 2, 2, 2, 1, 'like', 'input', NULL, NULL, 5, NULL, 500, 'input', '', 6, 300, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (79, 6, 'province', '省', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 6, 8, 600, 'input', '', 6, 800, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (80, 6, 'city', '市', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 7, 8, 700, 'input', '', 6, 900, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (81, 6, 'area', '区', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 8, 8, 800, 'input', '', 6, 1000, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (82, 6, 'address', '地址', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 9, NULL, 900, 'input', '', 6, 1100, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (83, 6, 'contact_name', '联系人', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'like', 'input', NULL, NULL, 10, NULL, 1000, 'input', '', 6, 400, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (84, 6, 'contact_phone', '联系电话', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 11, NULL, 1100, 'input', '', 6, 1200, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (85, 6, 'contact_email', '联系邮箱', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 12, NULL, 1200, 'input', '', 6, 1300, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (86, 6, 'status', '状态', 'smallint', 0, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'radio', 'data_status', NULL, 13, NULL, 1300, 'input', '', 6, 1400, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (87, 6, 'remark', '备注', 'varchar', 0, NULL, 1, 1, 2, 1, 1, 1, 1, 'eq', 'textarea', NULL, NULL, 14, NULL, 1600, 'input', '', 6, 1500, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (88, 6, 'is_init', '初始化', 'tinyint', 0, '0', 1, 1, 1, 2, 2, 1, 1, 'eq', 'radio', 'yes_or_no', NULL, 15, NULL, 1400, 'input', '', 6, 1600, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (89, 6, 'create_time', '创建时间', 'datetime', 0, NULL, 1, 1, 1, 1, 1, 2, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 16, NULL, 1700, 'date', '', 8, 1700, '{\"mode\":\"date\"}', NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (90, 6, 'update_time', '修改时间', 'datetime', 0, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 17, NULL, 1800, 'input', '', 6, 1800, NULL, NULL, NULL, NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_column` VALUES (91, 7, 'id', '编号', 'int', 0, NULL, 2, 2, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 0, NULL, 0, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (92, 7, 'organization', '机构编号', 'int', 0, '0', 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 1, NULL, 1, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (93, 7, 'parent_id', '父级ID', 'int', 0, '0', 1, 2, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 2, NULL, 2, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (94, 7, 'category_name', '分类标题', 'varchar', 0, NULL, 1, 2, 2, 2, 2, 2, 1, 'like', 'input', NULL, NULL, 3, NULL, 3, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (95, 7, 'describe', '分类简介', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 4, NULL, 4, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (96, 7, 'image', '分类图片', 'varchar', 120, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'uploadImage', NULL, '{\"multiple\":false,\"limit\":3}', 5, NULL, 5, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (97, 7, 'sort', '排序', 'int', 0, '100', 1, 1, 2, 2, 2, 1, 1, 'eq', 'inputNumber', NULL, NULL, 6, NULL, 6, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (98, 7, 'status', '状态', 'tinyint', 0, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'radio', 'data_status', NULL, 7, NULL, 7, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (99, 7, 'create_time', '创建时间', 'datetime', 0, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 8, NULL, 8, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (100, 7, 'update_time', '修改时间', 'datetime', 0, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 9, NULL, 9, 'input', NULL, 6, 0, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_column` VALUES (101, 8, 'id', '编号', 'int', 0, NULL, 2, 2, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 0, NULL, 800, 'input', '', 6, 300, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (102, 8, 'organization', '机构编号', 'int', 0, '0', 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 1, NULL, 900, 'input', '', 6, 400, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (103, 8, 'banner_type', '类型', 'int', 0, NULL, 1, 2, 2, 2, 2, 1, 1, 'eq', 'saSelect', 'admin_dashboard', NULL, 2, NULL, 100, 'input', '', 6, 100, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (104, 8, 'image', '图片地址', 'varchar', 120, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'uploadImage', NULL, '{\"multiple\":false,\"limit\":1}', 3, NULL, 300, 'input', '', 6, 500, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (105, 8, 'is_href', '是否链接', 'tinyint', 0, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'radio', 'yes_or_no', NULL, 4, NULL, 400, 'input', '', 6, 600, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (106, 8, 'url', '链接地址', 'varchar', 0, NULL, 1, 1, 2, 2, 2, 1, 1, 'eq', 'input', NULL, NULL, 5, NULL, 500, 'input', '', 6, 700, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (107, 8, 'title', '标题', 'varchar', 0, NULL, 1, 2, 2, 2, 2, 2, 1, 'like', 'input', NULL, NULL, 6, NULL, 200, 'input', '', 6, 200, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (108, 8, 'status', '状态', 'tinyint', 0, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'radio', 'data_status', NULL, 7, NULL, 600, 'input', '', 6, 800, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (109, 8, 'sort', '排序', 'int', 0, '1', 1, 1, 2, 2, 2, 1, 1, 'eq', 'inputNumber', NULL, '{\"min\":1,\"max\":100,\"step\":1}', 8, NULL, 700, 'input', '', 6, 900, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (110, 8, 'remark', '描述', 'varchar', 0, NULL, 1, 1, 1, 1, 1, 1, 1, 'eq', 'input', NULL, NULL, 9, NULL, 1000, 'input', '', 6, 1000, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (111, 8, 'create_time', '创建时间', 'datetime', 0, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 10, NULL, 1100, 'input', '', 6, 1100, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);
INSERT INTO `saimulti_column` VALUES (112, 8, 'update_time', '修改时间', 'datetime', 0, NULL, 1, 1, 1, 1, 1, 1, 1, 'between', 'date', NULL, '{\"mode\":\"datetime\"}', 11, NULL, 1200, 'input', '', 6, 1200, NULL, NULL, NULL, NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 15:36:38', NULL);

-- ----------------------------
-- Table structure for saimulti_table
-- ----------------------------
DROP TABLE IF EXISTS `saimulti_table`;
CREATE TABLE `saimulti_table`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `table_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '表名称',
  `table_comment` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '表注释',
  `stub` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'stub类型',
  `template` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '模板名称',
  `namespace` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '命名空间',
  `package_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '控制器包名',
  `business_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '业务名称',
  `class_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '类名称',
  `menu_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '生成菜单名',
  `belong_menu_id` int(11) NULL DEFAULT NULL COMMENT '所属菜单',
  `tpl_category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '生成类型',
  `generate_type` smallint(6) NULL DEFAULT 1 COMMENT '1 压缩包下载 2 生成到模块',
  `generate_model` smallint(6) NULL DEFAULT 1 COMMENT '1 软删除 2 非软删除',
  `generate_path` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'b8im-tenant-vue' COMMENT '前端根目录',
  `generate_menus` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '生成菜单列表',
  `component_type` smallint(6) NULL DEFAULT 1 COMMENT '组件方式',
  `form_width` int(11) NULL DEFAULT 600 COMMENT '宽度',
  `is_full` smallint(6) NULL DEFAULT 1 COMMENT '是否全屏',
  `span` smallint(6) NULL DEFAULT NULL COMMENT '布局',
  `options` varchar(1500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '其他业务选项',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '数据源',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 9 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '低代码数据表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of saimulti_table
-- ----------------------------
INSERT INTO `saimulti_table` VALUES (1, 'sm_article', '文章表', 'tenant', 'app', 'pms', '', 'article', 'Article', '文章管理', 4000, 'single', 1, 1, 'b8im-tenant-vue', 'index,save,update,read,destroy', 1, 800, 1, 24, '{\"relations\":[]}', NULL, 'mysql', NULL, NULL, '2026-04-15 17:09:07', '2026-04-24 17:18:45', NULL);
INSERT INTO `saimulti_table` VALUES (2, 'sm_article_category', '文章分类表', 'tenant', 'app', 'pms', '', 'category', 'ArticleCategory', '文章分类表', 4000, 'tree', 1, 1, 'b8im-tenant-vue', 'index,save,update,read,destroy', 1, 600, 1, 24, '{\"relations\":[],\"tree_id\":\"id\",\"tree_name\":\"category_name\",\"tree_parent_id\":\"parent_id\"}', NULL, 'mysql', NULL, NULL, '2026-04-16 13:06:00', '2026-04-24 16:56:59', NULL);
INSERT INTO `saimulti_table` VALUES (3, 'sm_tenant_group', '机构分组', 'admin', 'plugin', 'saimulti', 'admin', 'group', 'TenantGroup', '机构分组表', 4000, 'single', 1, 1, 'b8im-admin-vue', 'index,save,update,read,destroy', 2, 800, 1, 24, '{\"relations\":[]}', NULL, 'mysql', NULL, NULL, '2026-04-16 15:37:35', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_table` VALUES (4, 'sm_tenant_menu', '菜单信息表', 'admin', 'plugin', 'saimulti', 'admin', 'menu', 'TenantMenu', '菜单信息表', 4000, 'tree', 1, 1, 'b8im-admin-vue', 'index,save,update,read,destroy', 1, 600, 1, 24, '{\"relations\":[],\"tree_id\":\"id\",\"tree_parent_id\":\"parent_id\",\"tree_name\":\"name\"}', NULL, 'mysql', NULL, NULL, '2026-04-16 16:04:57', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_table` VALUES (5, 'sm_tenant_user', '用户信息表', 'admin', 'plugin', 'saimulti', 'admin', 'user', 'SmTenantUser', '用户信息表', 4000, 'single', 1, 1, 'b8im-admin-vue', 'index,save,update,read,destroy', 1, 600, 1, 24, '{\"relations\":[]}', NULL, 'mysql', NULL, NULL, '2026-04-16 16:05:19', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_table` VALUES (6, 'sm_system_organization', '机构信息表', 'admin', 'plugin', 'saimulti', 'admin', 'organization', 'SystemOrganization', '机构信息表', 4000, 'single', 1, 1, 'b8im-admin-vue', 'index,save,update,read,destroy', 1, 800, 1, 24, '{\"relations\":[]}', NULL, 'mysql', NULL, NULL, '2026-04-16 16:32:46', '2026-04-24 15:12:44', '2026-04-24 15:12:43');
INSERT INTO `saimulti_table` VALUES (7, 'sm_article_category', '文章分类表', 'tenant', 'app', 'cms', '', 'category', 'ArticleCategory', '文章分类表', 4000, 'single', 1, 1, 'b8im-tenant-vue', 'index,save,update,read,destroy', 1, 600, 1, 24, '{\"relations\":[]}', NULL, 'mysql', NULL, NULL, '2026-04-24 15:13:31', '2026-04-24 15:36:32', '2026-04-24 15:36:31');
INSERT INTO `saimulti_table` VALUES (8, 'sm_article_banner', '文章轮播图', 'tenant', 'app', 'pms', '', 'banner', 'ArticleBanner', '文章轮播', 4000, 'single', 1, 1, 'b8im-tenant-vue', 'index,save,update,read,destroy', 1, 600, 1, 24, '{\"relations\":[],\"tree_id\":\"id\",\"tree_name\":\"category_name\",\"tree_parent_id\":\"parent_id\"}', NULL, 'mysql', NULL, NULL, '2026-04-24 15:36:38', '2026-04-24 17:14:09', NULL);

-- ----------------------------
-- Table structure for sm_admin
-- ----------------------------
DROP TABLE IF EXISTS `sm_admin`;
CREATE TABLE `sm_admin`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `username` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '用户名',
  `password` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '密码',
  `user_type` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '200' COMMENT '用户类型:(100系统用户)',
  `nickname` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '用户昵称',
  `gender` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '性别',
  `phone` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '手机',
  `email` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '用户邮箱',
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '用户头像',
  `signed` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '个人签名',
  `dashboard` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '后台首页类型',
  `dept_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '部门ID',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `login_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '最后登陆IP',
  `login_time` datetime NULL DEFAULT NULL COMMENT '最后登陆时间',
  `backend_setting` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '后台设置数据',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `username`(`username`) USING BTREE,
  INDEX `dept_id`(`dept_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '系统管理员' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_admin
-- ----------------------------
INSERT INTO `sm_admin` VALUES (1, 'admin', '$2y$10$O2pTdk7777rOgc0pZIi7huiOrhkXudrKOr9WlxoPtyRn/FPl6Ka4i', '100', '祭道之上', '1', '13888888888', 'admin@admin.com', 'http://127.0.0.1:8888/storage/20260414/7971881d7e10a122e0f51ea188571dbe29d82229.jpg', 'Today is very good！', 'statistics', NULL, 1, '127.0.0.1', '2026-04-25 23:30:06', '{\"mode\":\"light\",\"tag\":true,\"menuCollapse\":false,\"menuWidth\":230,\"layout\":\"classic\",\"skin\":\"mine\",\"i18n\":false,\"language\":\"zh_CN\",\"animation\":\"ma-slide-down\",\"color\":\"#7166F0\",\"waterMark\":false,\"waterContent\":\"saas\",\"roundOpen\":true}', NULL, NULL, '2026-04-25 23:30:07', NULL);
INSERT INTO `sm_admin` VALUES (2, 'martin', '$2y$10$TQmRQg36YppTpKibhwSupeMjht13TOIsvo4k5TR4D76O0nlYdxHx.', '200', '马丁', '', '15888888888', '158', 'http://127.0.0.1:8888/storage/20260414/7971881d7e10a122e0f51ea188571dbe29d82229.jpg', NULL, 'work', 1, 1, '127.0.0.1', '2026-04-21 15:16:22', NULL, '888', '2026-04-14 16:20:33', '2026-04-21 15:16:22', NULL);

-- ----------------------------
-- Table structure for sm_admin_dept
-- ----------------------------
DROP TABLE IF EXISTS `sm_admin_dept`;
CREATE TABLE `sm_admin_dept`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `parent_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '父ID',
  `level` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '组级集合',
  `name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '部门名称',
  `code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '部门编码',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `sort` smallint(5) UNSIGNED NULL DEFAULT 0 COMMENT '排序',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `parent_id`(`parent_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '部门信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_admin_dept
-- ----------------------------
INSERT INTO `sm_admin_dept` VALUES (1, 0, '0', '总经办', 'manage', 1, 100, '', '2026-04-14 14:11:42', '2026-04-14 15:34:32', NULL);
INSERT INTO `sm_admin_dept` VALUES (2, 1, '0,1', '技术部', 'develop', 1, 100, '', '2026-04-14 21:59:27', '2026-04-14 21:59:27', NULL);
INSERT INTO `sm_admin_dept` VALUES (3, 1, '0,1', '销售部', 'sales', 1, 100, '', '2026-04-14 21:59:41', '2026-04-14 21:59:41', NULL);
INSERT INTO `sm_admin_dept` VALUES (4, 1, '0,1', '财务部', 'finance', 1, 100, '', '2026-04-14 21:59:52', '2026-04-14 21:59:52', NULL);

-- ----------------------------
-- Table structure for sm_admin_menu
-- ----------------------------
DROP TABLE IF EXISTS `sm_admin_menu`;
CREATE TABLE `sm_admin_menu`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `parent_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '父ID',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '菜单名称',
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '菜单标识',
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '权限标识',
  `type` tinyint(1) NULL DEFAULT NULL COMMENT '菜单类型',
  `path` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '路由地址',
  `component` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '组件路径',
  `method` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '请求方式',
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '菜单图标',
  `sort` smallint(5) UNSIGNED NULL DEFAULT 0 COMMENT '排序',
  `link_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '跳转地址',
  `is_iframe` tinyint(1) NULL DEFAULT NULL COMMENT '是否iframe',
  `is_keep_alive` tinyint(1) NULL DEFAULT NULL COMMENT '是否缓存',
  `is_hidden` tinyint(1) NULL DEFAULT 1 COMMENT '是否隐藏',
  `is_fixed_tab` tinyint(1) NULL DEFAULT NULL COMMENT '是否固定标签页',
  `is_full_page` tinyint(1) NULL DEFAULT NULL COMMENT '是否全屏',
  `generate_id` int(11) NULL DEFAULT 0 COMMENT '生成id',
  `generate_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '生成key',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_parent`(`parent_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4017 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '管理端菜单表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_admin_menu
-- ----------------------------
INSERT INTO `sm_admin_menu` VALUES (1, 0, '仪表盘', 'Dashboard', NULL, 1, '/dashboard', NULL, NULL, 'ri:pie-chart-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2025-02-08 15:10:27', '2025-02-08 15:10:27', NULL);
INSERT INTO `sm_admin_menu` VALUES (2, 1, '工作台', 'Console', NULL, 2, 'console', '/dashboard/console', NULL, 'ri:home-smile-2-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2025-02-08 15:10:27', '2025-02-08 15:10:27', NULL);
INSERT INTO `sm_admin_menu` VALUES (3, 1, '个人中心', 'UserCenter', NULL, 2, 'user-center', '/dashboard/user-center/index', NULL, 'ri:user-2-line', 100, NULL, 2, 2, 1, 2, 2, 0, NULL, 1, NULL, '2025-02-08 15:10:27', '2025-02-08 15:10:27', NULL);
INSERT INTO `sm_admin_menu` VALUES (1000, 0, '租户管理', 'panel', '', 1, 'panel', '', NULL, 'ri:apps-2-ai-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:10:27', '2026-04-14 10:34:53', NULL);
INSERT INTO `sm_admin_menu` VALUES (1001, 1000, '机构管理', 'panel/organizationList', '', 2, 'organization', '/admin/panel/organization/index', NULL, 'ri:building-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:12:36', '2026-04-17 22:59:50', NULL);
INSERT INTO `sm_admin_menu` VALUES (1002, 1000, '机构账号', 'panel/user', '', 2, 'user', '/admin/panel/user/index', NULL, 'ri:file-user-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:13:16', '2026-04-17 23:00:00', NULL);
INSERT INTO `sm_admin_menu` VALUES (1003, 1000, '机构分组', 'panel/group', '', 2, 'group', '/admin/panel/group/index', NULL, 'ri:group-3-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:14:51', '2026-04-16 16:13:32', NULL);
INSERT INTO `sm_admin_menu` VALUES (1004, 1000, '菜单管理', 'panel/menu', '', 2, 'menu', '/admin/panel/menu/index', NULL, 'ri:menu-fold-2-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:15:28', '2026-04-16 16:13:40', NULL);
INSERT INTO `sm_admin_menu` VALUES (1101, 1001, '列表', NULL, 'saimulti:admin:organization:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:11:30', '2026-04-17 22:58:13', NULL);
INSERT INTO `sm_admin_menu` VALUES (1102, 1001, '添加', NULL, 'saimulti:admin:organization:save', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:15:26', '2026-04-17 22:58:24', NULL);
INSERT INTO `sm_admin_menu` VALUES (1103, 1001, '编辑', NULL, 'saimulti:admin:organization:update', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:25:58', '2026-04-17 22:58:34', NULL);
INSERT INTO `sm_admin_menu` VALUES (1104, 1001, '删除', NULL, 'saimulti:admin:organization:destroy', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:26:36', '2026-04-17 22:58:51', NULL);
INSERT INTO `sm_admin_menu` VALUES (1105, 1001, '读取', NULL, 'saimulti:admin:organization:read', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:27:00', '2026-04-17 22:58:59', NULL);
INSERT INTO `sm_admin_menu` VALUES (1106, 1001, '初始化', NULL, 'saimulti:admin:organization:init', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-09 15:11:54', '2026-04-17 22:59:13', NULL);
INSERT INTO `sm_admin_menu` VALUES (1201, 1002, '列表', NULL, 'saimulti:admin:user:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:49:29', '2026-04-17 23:01:02', NULL);
INSERT INTO `sm_admin_menu` VALUES (1202, 1002, '添加', NULL, 'saimulti:admin:user:save', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:49:29', '2026-04-17 23:01:10', NULL);
INSERT INTO `sm_admin_menu` VALUES (1203, 1002, '编辑', NULL, 'saimulti:admin:user:update', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:49:29', '2026-04-17 23:01:19', NULL);
INSERT INTO `sm_admin_menu` VALUES (1204, 1002, '删除', NULL, 'saimulti:admin:user:destroy', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:49:29', '2026-04-17 23:01:29', NULL);
INSERT INTO `sm_admin_menu` VALUES (1205, 1002, '读取', NULL, 'saimulti:admin:user:read', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-10 09:49:29', '2026-04-17 23:01:39', NULL);
INSERT INTO `sm_admin_menu` VALUES (1206, 1002, '清理缓存', NULL, 'saimulti:admin:user:cache', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-09 15:14:58', '2026-04-17 23:02:14', NULL);
INSERT INTO `sm_admin_menu` VALUES (1207, 1002, '重置密码', NULL, 'saimulti:admin:user:reset', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-17 23:02:33', '2026-04-17 23:02:33', NULL);
INSERT INTO `sm_admin_menu` VALUES (1301, 1003, '分组列表', NULL, 'saimulti:admin:group:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (1302, 1003, '分组添加', NULL, 'saimulti:admin:group:save', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (1303, 1003, '分组编辑', NULL, 'saimulti:admin:group:update', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (1304, 1003, '分组删除', NULL, 'saimulti:admin:group:destroy', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (1305, 1003, '分组读取', NULL, 'saimulti:admin:group:read', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (1306, 1003, '菜单分配', '', 'saimulti:admin:group:menu', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-17 23:07:15', '2026-04-17 23:07:15', NULL);
INSERT INTO `sm_admin_menu` VALUES (1401, 1004, '菜单列表', NULL, 'saimulti:admin:menu:index', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (1402, 1004, '菜单添加', NULL, 'saimulti:admin:menu:save', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (1403, 1004, '菜单编辑', NULL, 'saimulti:admin:menu:update', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (1404, 1004, '菜单删除', NULL, 'saimulti:admin:menu:destroy', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (1405, 1004, '菜单读取', NULL, 'saimulti:admin:menu:read', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 10:20:04', '2025-02-10 10:20:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (2000, 0, '系统管理', 'system', '', 1, 'system', '', NULL, 'ri:dashboard-horizontal-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:16:12', '2026-04-14 10:36:32', NULL);
INSERT INTO `sm_admin_menu` VALUES (2001, 2000, '菜单管理', 'system/menu', '', 2, 'menu', '/admin/system/menu/index', NULL, 'ri:menu-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:17:54', '2026-04-17 23:13:02', NULL);
INSERT INTO `sm_admin_menu` VALUES (2002, 2000, '角色管理', 'system/role', '', 2, 'role', '/admin/system/role/index', NULL, 'ri:admin-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:19:42', '2026-04-17 22:44:03', NULL);
INSERT INTO `sm_admin_menu` VALUES (2003, 2000, '部门管理', 'system/dept', '', 2, 'dept', '/admin/system/dept/index', NULL, 'ri:node-tree', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-08 13:41:39', '2026-04-17 22:44:08', NULL);
INSERT INTO `sm_admin_menu` VALUES (2004, 2000, '账号管理', 'system/user', '', 2, 'user', '/admin/system/user/index', NULL, 'ri:user-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:20:19', '2026-04-17 22:44:12', NULL);
INSERT INTO `sm_admin_menu` VALUES (2005, 2000, '数据字典', 'system/dict', '', 2, 'dict', '/admin/system/dict/index', NULL, 'ri:database-2-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:20:53', '2026-04-17 22:44:15', NULL);
INSERT INTO `sm_admin_menu` VALUES (2006, 2000, '系统设置', 'system/config', '', 2, 'config', '/admin/system/config/index', NULL, 'ri:settings-4-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-07 09:25:47', '2026-04-17 22:44:20', NULL);
INSERT INTO `sm_admin_menu` VALUES (2101, 2001, '菜单列表', NULL, 'saimulti:coreMenu:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 10:51:51', '2025-06-08 12:39:03', NULL);
INSERT INTO `sm_admin_menu` VALUES (2102, 2001, '菜单添加', NULL, 'saimulti:coreMenu:save', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 10:51:51', '2025-06-08 12:39:07', NULL);
INSERT INTO `sm_admin_menu` VALUES (2103, 2001, '菜单编辑', NULL, 'saimulti:coreMenu:update', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 10:51:51', '2025-06-08 12:39:11', NULL);
INSERT INTO `sm_admin_menu` VALUES (2104, 2001, '菜单删除', NULL, 'saimulti:coreMenu:destroy', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 10:51:51', '2025-06-08 12:39:15', NULL);
INSERT INTO `sm_admin_menu` VALUES (2105, 2001, '菜单读取', NULL, 'saimulti:coreMenu:read', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 10:51:51', '2025-06-08 12:39:18', NULL);
INSERT INTO `sm_admin_menu` VALUES (2201, 2002, '角色列表', NULL, 'saimulti:coreRole:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:11:34', '2025-06-08 12:51:34', NULL);
INSERT INTO `sm_admin_menu` VALUES (2202, 2002, '角色添加', NULL, 'saimulti:coreRole:save', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:11:34', '2025-06-08 12:51:38', NULL);
INSERT INTO `sm_admin_menu` VALUES (2203, 2002, '角色编辑', NULL, 'saimulti:coreRole:edit', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:11:34', '2025-06-08 12:51:42', NULL);
INSERT INTO `sm_admin_menu` VALUES (2204, 2002, '角色删除', NULL, 'saimulti:coreRole:destroy', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:11:34', '2025-06-08 12:51:46', NULL);
INSERT INTO `sm_admin_menu` VALUES (2205, 2002, '角色读取', NULL, 'saimulti:coreRole:read', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:11:34', '2025-06-08 12:51:49', NULL);
INSERT INTO `sm_admin_menu` VALUES (2206, 2002, '菜单权限', NULL, 'saimulti:coreRole:menu', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 12:56:54', '2025-06-08 12:56:54', NULL);
INSERT INTO `sm_admin_menu` VALUES (2301, 2003, '部门列表', NULL, 'saimulti:coreDept:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 13:47:13', '2025-06-08 13:47:13', NULL);
INSERT INTO `sm_admin_menu` VALUES (2302, 2003, '部门添加', NULL, 'saimulti:coreDept:save', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 13:47:24', '2025-06-08 13:47:24', NULL);
INSERT INTO `sm_admin_menu` VALUES (2303, 2003, '部门修改', NULL, 'saimulti:coreDept:update', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 13:47:39', '2025-06-08 13:47:39', NULL);
INSERT INTO `sm_admin_menu` VALUES (2304, 2003, '部门删除', NULL, 'saimulti:coreDept:destroy', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 13:47:50', '2025-06-08 13:47:50', NULL);
INSERT INTO `sm_admin_menu` VALUES (2305, 2003, '部门读取', NULL, 'saimulti:coreDept:read', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 13:48:04', '2025-06-08 13:48:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (2401, 2004, '账号列表', NULL, 'saimulti:admin:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:14:37', '2025-06-08 14:08:28', NULL);
INSERT INTO `sm_admin_menu` VALUES (2402, 2004, '账号添加', NULL, 'saimulti:admin:save', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:14:37', '2025-06-08 14:08:32', NULL);
INSERT INTO `sm_admin_menu` VALUES (2403, 2004, '账号编辑', NULL, 'saimulti:admin:update', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:14:37', '2025-06-08 14:08:35', NULL);
INSERT INTO `sm_admin_menu` VALUES (2404, 2004, '账号删除', NULL, 'saimulti:admin:destroy', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:14:37', '2025-06-08 14:08:39', NULL);
INSERT INTO `sm_admin_menu` VALUES (2405, 2004, '账号读取', NULL, 'saimulti:admin:read', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:14:37', '2025-06-08 14:08:42', NULL);
INSERT INTO `sm_admin_menu` VALUES (2406, 2004, '密码重置', NULL, 'saimulti:admin:reset', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:14:37', '2025-06-08 14:08:47', NULL);
INSERT INTO `sm_admin_menu` VALUES (2407, 2004, '清理缓存', NULL, 'saimulti:admin:cache', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 14:11:06', '2025-06-08 14:11:06', NULL);
INSERT INTO `sm_admin_menu` VALUES (2408, 2004, '设置首页', NULL, 'saimulti:admin:home', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 14:11:44', '2025-06-08 14:11:44', NULL);
INSERT INTO `sm_admin_menu` VALUES (2501, 2005, '字典列表', NULL, 'saimulti:dict:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 11:26:44', '2025-02-10 11:26:44', NULL);
INSERT INTO `sm_admin_menu` VALUES (2502, 2005, '字典添加', NULL, 'saimulti:dict:save', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 11:26:44', '2025-02-10 11:26:44', NULL);
INSERT INTO `sm_admin_menu` VALUES (2503, 2005, '字典编辑', NULL, 'saimulti:dict:update', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 11:26:44', '2025-02-10 11:26:44', NULL);
INSERT INTO `sm_admin_menu` VALUES (2504, 2005, '字典删除', NULL, 'saimulti:dict:destroy', 3, NULL, NULL, NULL, NULL, 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, NULL, '2025-02-10 11:26:44', '2025-02-10 11:26:44', NULL);
INSERT INTO `sm_admin_menu` VALUES (2601, 2006, '设置列表', NULL, 'saimulti:config:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 12:28:17', '2025-06-08 12:28:55', NULL);
INSERT INTO `sm_admin_menu` VALUES (2602, 2006, '设置添加', NULL, 'saimulti:config:save', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 12:29:08', '2025-06-08 12:29:08', NULL);
INSERT INTO `sm_admin_menu` VALUES (2603, 2006, '设置编辑', NULL, 'saimulti:config:update', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 12:29:43', '2025-06-08 12:29:43', NULL);
INSERT INTO `sm_admin_menu` VALUES (2604, 2006, '设置删除', NULL, 'saimulti:config:destroy', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 12:30:04', '2025-06-08 12:30:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (3000, 0, '系统维护', 'tool', '', 1, 'tool', '', NULL, 'ri:tools-fill', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-07 10:06:49', '2026-04-14 10:36:51', NULL);
INSERT INTO `sm_admin_menu` VALUES (3001, 3000, '数据表维护', 'tool/database', '', 2, 'database', '/tool/database/index', NULL, 'ri:database-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-07 10:58:38', '2026-04-17 22:45:23', NULL);
INSERT INTO `sm_admin_menu` VALUES (3002, 3000, '附件管理', 'tool/attachment', '', 2, 'attachment', '/tool/attachment/index', NULL, 'ri:file-cloud-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:21:47', '2026-04-17 22:45:32', NULL);
INSERT INTO `sm_admin_menu` VALUES (3003, 3000, '定时任务', 'tool/crontab', '', 2, 'crontab', '/tool/crontab/index', NULL, 'ri:calendar-schedule-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:33:29', '2026-04-17 22:45:40', NULL);
INSERT INTO `sm_admin_menu` VALUES (3004, 3000, '登录日志', 'tool/loginLog', '', 2, 'loginLog', '/tool/login-log/index', NULL, 'ri:login-circle-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:22:38', '2026-04-17 22:45:46', NULL);
INSERT INTO `sm_admin_menu` VALUES (3005, 3000, '操作日志', 'tool/operLog', '', 2, 'operLog', '/tool/oper-log/index', NULL, 'ri:shield-keyhole-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:31:29', '2026-04-17 22:45:53', NULL);
INSERT INTO `sm_admin_menu` VALUES (3006, 3000, '邮件记录', 'tool/email', '', 2, 'email', '/tool/email-log/index', NULL, 'ri:mail-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-07 10:41:21', '2026-04-17 22:45:59', NULL);
INSERT INTO `sm_admin_menu` VALUES (3101, 3001, '列表', '', 'saimulti:database:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-17 23:33:23', '2026-04-17 23:33:23', NULL);
INSERT INTO `sm_admin_menu` VALUES (3102, 3001, '维护', '', 'saimulti:database:edit', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-17 23:35:22', '2026-04-17 23:35:22', NULL);
INSERT INTO `sm_admin_menu` VALUES (3103, 3001, '回收站数据', '', 'saimulti:recycle:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-17 23:34:05', '2026-04-17 23:34:05', NULL);
INSERT INTO `sm_admin_menu` VALUES (3104, 3001, '回收站管理', '', 'saimulti:recycle:edit', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-17 23:34:30', '2026-04-17 23:34:30', NULL);
INSERT INTO `sm_admin_menu` VALUES (3201, 3002, '附件列表', NULL, 'saimulti:attachment:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 15:32:13', '2025-06-08 11:14:49', NULL);
INSERT INTO `sm_admin_menu` VALUES (3202, 3002, '附件管理', NULL, 'saimulti:attachment:edit', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 15:32:13', '2025-06-08 11:14:43', NULL);
INSERT INTO `sm_admin_menu` VALUES (3301, 3003, '任务列表', NULL, 'saimulti:crontab:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 16:01:42', '2025-06-08 11:54:31', NULL);
INSERT INTO `sm_admin_menu` VALUES (3302, 3003, '任务管理', NULL, 'saimulti:crontab:edit', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 16:01:42', '2025-06-08 11:54:39', NULL);
INSERT INTO `sm_admin_menu` VALUES (3303, 3003, '任务删除', NULL, 'saimulti:crontab:destroy', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 16:01:42', '2025-06-08 11:54:53', NULL);
INSERT INTO `sm_admin_menu` VALUES (3304, 3003, '任务执行', NULL, 'saimulti:crontab:run', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 16:01:42', '2025-06-08 11:55:07', NULL);
INSERT INTO `sm_admin_menu` VALUES (3401, 3004, '日志列表', NULL, 'saimulti:loginlog:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 15:49:56', '2025-06-08 11:27:57', NULL);
INSERT INTO `sm_admin_menu` VALUES (3402, 3004, '日志删除', NULL, 'saimulti:loginlog:destroy', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 15:49:56', '2025-06-08 11:28:04', NULL);
INSERT INTO `sm_admin_menu` VALUES (3501, 3005, '操作列表', NULL, 'saimulti:operlog:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 15:53:43', '2025-06-08 11:28:10', NULL);
INSERT INTO `sm_admin_menu` VALUES (3502, 3005, '操作删除', NULL, 'saimulti:operlog:destroy', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-02-10 15:54:07', '2025-06-08 11:28:17', NULL);
INSERT INTO `sm_admin_menu` VALUES (3601, 3006, '邮件列表', NULL, 'saimulti:mail:index', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 12:20:05', '2025-06-08 12:20:18', NULL);
INSERT INTO `sm_admin_menu` VALUES (3602, 3006, '邮件删除', NULL, 'saimulti:mail:destroy', 3, '', '', NULL, '', 100, NULL, NULL, NULL, 2, NULL, NULL, 0, NULL, 1, '', '2025-06-08 12:20:53', '2025-06-08 12:20:53', NULL);
INSERT INTO `sm_admin_menu` VALUES (4000, 0, '开发中心', 'develop', '', 1, 'develop', '', NULL, 'ri:code-block', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:34:36', '2026-04-14 10:37:03', NULL);
INSERT INTO `sm_admin_menu` VALUES (4001, 4000, '代码生成', 'tool/develop', '', 2, 'develop', '/tool/develop/index', NULL, 'ri:code-s-slash-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-02-08 15:35:42', '2026-04-17 22:46:13', NULL);
INSERT INTO `sm_admin_menu` VALUES (4002, 4001, '代码生成列表', '', 'saimulti:tool:develop:index', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-17 22:52:57', '2026-04-17 23:56:07', NULL);
INSERT INTO `sm_admin_menu` VALUES (4003, 4001, '预览代码', '', 'saimulti:tool:develop:preview', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-17 23:56:31', '2026-04-17 23:56:31', NULL);
INSERT INTO `sm_admin_menu` VALUES (4004, 4001, '管理接口', '', 'saimulti:tool:develop', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-17 23:56:46', '2026-04-17 23:56:46', NULL);
INSERT INTO `sm_admin_menu` VALUES (4012, 0, '附加权限', 'Permission', '', 1, 'permission', '', NULL, 'ri:apps-2-ai-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:04:01', '2026-04-18 00:04:01', NULL);
INSERT INTO `sm_admin_menu` VALUES (4013, 4012, '上传图片', '', 'saimulti:system:uploadImage', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:04:34', '2026-04-18 00:04:34', NULL);
INSERT INTO `sm_admin_menu` VALUES (4014, 4012, '上传文件', '', 'saimulti:system:uploadFile', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:04:48', '2026-04-18 00:04:48', NULL);
INSERT INTO `sm_admin_menu` VALUES (4015, 4012, '切片上传', '', 'saimulti:system:chunkUpload', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:05:01', '2026-04-18 00:05:01', NULL);
INSERT INTO `sm_admin_menu` VALUES (4016, 4012, '附件信息', '', 'saimulti:system:resource', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:05:14', '2026-04-18 00:05:14', NULL);

-- ----------------------------
-- Table structure for sm_admin_role
-- ----------------------------
DROP TABLE IF EXISTS `sm_admin_role`;
CREATE TABLE `sm_admin_role`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '角色名称',
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '角色代码',
  `level` int(11) NULL DEFAULT NULL COMMENT '角色职权',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `sort` smallint(5) UNSIGNED NULL DEFAULT 0 COMMENT '排序',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '管理端角色表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_admin_role
-- ----------------------------
INSERT INTO `sm_admin_role` VALUES (1, '超级管理员', 'superAdmin', 100, 1, 1, '系统内置角色，不可删除', '2025-02-07 17:13:01', '2025-02-07 17:13:01', NULL);
INSERT INTO `sm_admin_role` VALUES (2, '管理员', 'admin', 90, 1, 100, '', '2026-04-14 12:24:50', '2026-04-14 12:24:50', NULL);
INSERT INTO `sm_admin_role` VALUES (3, '33', 'xxxx', 1, 1, 100, '', '2026-04-14 22:44:30', '2026-04-14 22:45:01', '2026-04-14 22:45:00');

-- ----------------------------
-- Table structure for sm_admin_role_menu
-- ----------------------------
DROP TABLE IF EXISTS `sm_admin_role_menu`;
CREATE TABLE `sm_admin_role_menu`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `role_id` int(11) UNSIGNED NOT NULL COMMENT '角色主键',
  `menu_id` int(11) UNSIGNED NOT NULL COMMENT '菜单主键',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_role_id`(`role_id`) USING BTREE,
  INDEX `idx_menu_id`(`menu_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 33 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '管理端角色菜单关联表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_admin_role_menu
-- ----------------------------
INSERT INTO `sm_admin_role_menu` VALUES (1, 2, 1);
INSERT INTO `sm_admin_role_menu` VALUES (2, 2, 2);
INSERT INTO `sm_admin_role_menu` VALUES (3, 2, 3);
INSERT INTO `sm_admin_role_menu` VALUES (4, 2, 1000);
INSERT INTO `sm_admin_role_menu` VALUES (5, 2, 1001);
INSERT INTO `sm_admin_role_menu` VALUES (6, 2, 1101);
INSERT INTO `sm_admin_role_menu` VALUES (7, 2, 1102);
INSERT INTO `sm_admin_role_menu` VALUES (8, 2, 1103);
INSERT INTO `sm_admin_role_menu` VALUES (9, 2, 1104);
INSERT INTO `sm_admin_role_menu` VALUES (10, 2, 1105);
INSERT INTO `sm_admin_role_menu` VALUES (11, 2, 1106);
INSERT INTO `sm_admin_role_menu` VALUES (12, 2, 1002);
INSERT INTO `sm_admin_role_menu` VALUES (13, 2, 1201);
INSERT INTO `sm_admin_role_menu` VALUES (14, 2, 1202);
INSERT INTO `sm_admin_role_menu` VALUES (15, 2, 1203);
INSERT INTO `sm_admin_role_menu` VALUES (16, 2, 1204);
INSERT INTO `sm_admin_role_menu` VALUES (17, 2, 1205);
INSERT INTO `sm_admin_role_menu` VALUES (18, 2, 1206);
INSERT INTO `sm_admin_role_menu` VALUES (19, 2, 1207);
INSERT INTO `sm_admin_role_menu` VALUES (20, 2, 1003);
INSERT INTO `sm_admin_role_menu` VALUES (21, 2, 1301);
INSERT INTO `sm_admin_role_menu` VALUES (22, 2, 1302);
INSERT INTO `sm_admin_role_menu` VALUES (23, 2, 1303);
INSERT INTO `sm_admin_role_menu` VALUES (24, 2, 1304);
INSERT INTO `sm_admin_role_menu` VALUES (25, 2, 1305);
INSERT INTO `sm_admin_role_menu` VALUES (26, 2, 1306);
INSERT INTO `sm_admin_role_menu` VALUES (27, 2, 1004);
INSERT INTO `sm_admin_role_menu` VALUES (28, 2, 1401);
INSERT INTO `sm_admin_role_menu` VALUES (29, 2, 1402);
INSERT INTO `sm_admin_role_menu` VALUES (30, 2, 1403);
INSERT INTO `sm_admin_role_menu` VALUES (31, 2, 1404);
INSERT INTO `sm_admin_role_menu` VALUES (32, 2, 1405);

-- ----------------------------
-- Table structure for sm_admin_user_role
-- ----------------------------
DROP TABLE IF EXISTS `sm_admin_user_role`;
CREATE TABLE `sm_admin_user_role`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `user_id` int(11) UNSIGNED NOT NULL COMMENT '用户主键',
  `role_id` int(11) UNSIGNED NOT NULL COMMENT '角色主键',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_user_id`(`user_id`) USING BTREE,
  INDEX `idx_role_id`(`role_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '管理端用户角色关联表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_admin_user_role
-- ----------------------------
INSERT INTO `sm_admin_user_role` VALUES (1, 1, 1);
INSERT INTO `sm_admin_user_role` VALUES (4, 2, 2);

-- ----------------------------
-- Table structure for sm_article
-- ----------------------------
DROP TABLE IF EXISTS `sm_article`;
CREATE TABLE `sm_article`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `organization` int(11) UNSIGNED NULL DEFAULT 0 COMMENT '机构编号',
  `category_id` int(10) NOT NULL COMMENT '分类id',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '文章标题',
  `author` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '文章作者',
  `image` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '' COMMENT '文章图片',
  `describe` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '文章简介',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '文章内容',
  `views` int(11) NULL DEFAULT 0 COMMENT '浏览次数',
  `sort` int(10) UNSIGNED NULL DEFAULT 100 COMMENT '排序',
  `status` tinyint(1) UNSIGNED NULL DEFAULT 1 COMMENT '状态',
  `is_link` tinyint(1) NULL DEFAULT 2 COMMENT '是否外链',
  `link_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '链接地址',
  `is_hot` tinyint(1) UNSIGNED NULL DEFAULT 2 COMMENT '是否热门',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_category_id`(`category_id`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '文章表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_article
-- ----------------------------
INSERT INTO `sm_article` VALUES (1, 1, 2, '号外', '', 'http://127.0.0.1:8888/storage/20260424/6a6bd5a3bfe69bb6b97fdce9977296c66aef9903.png', '号外', '<p>号外</p>', 1, 100, 1, 2, '', 2, '2026-04-24 17:23:25', '2026-04-24 17:23:25', NULL);

-- ----------------------------
-- Table structure for sm_article_banner
-- ----------------------------
DROP TABLE IF EXISTS `sm_article_banner`;
CREATE TABLE `sm_article_banner`  (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '编号',
  `organization` int(11) UNSIGNED NULL DEFAULT 0 COMMENT '机构编号',
  `banner_type` int(11) NULL DEFAULT NULL COMMENT '类型',
  `image` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '图片地址',
  `is_href` tinyint(1) NULL DEFAULT 1 COMMENT '是否链接',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '链接地址',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '标题',
  `status` tinyint(1) NULL DEFAULT 1 COMMENT '状态',
  `sort` int(11) NULL DEFAULT 0 COMMENT '排序',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '描述',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '文章轮播图' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_article_banner
-- ----------------------------
INSERT INTO `sm_article_banner` VALUES (1, 1, 0, 'http://127.0.0.1:8888/storage/20260424/f068744d25370a046b99c4335578cc729aa8d098.png', 1, '', '旅游', 1, 1, NULL, '2026-04-24 17:10:37', '2026-04-24 17:10:37', NULL);

-- ----------------------------
-- Table structure for sm_article_category
-- ----------------------------
DROP TABLE IF EXISTS `sm_article_category`;
CREATE TABLE `sm_article_category`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `organization` int(11) UNSIGNED NULL DEFAULT 0 COMMENT '机构编号',
  `parent_id` int(11) NOT NULL DEFAULT 0 COMMENT '父级ID',
  `category_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '分类标题',
  `describe` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '分类简介',
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '分类图片',
  `sort` int(10) UNSIGNED NULL DEFAULT 100 COMMENT '排序',
  `status` tinyint(1) UNSIGNED NULL DEFAULT 1 COMMENT '状态',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '文章分类表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_article_category
-- ----------------------------
INSERT INTO `sm_article_category` VALUES (1, 1, 0, '新闻中心', '', '', 100, 1, '2026-04-24 16:58:34', '2026-04-24 16:58:34', NULL);
INSERT INTO `sm_article_category` VALUES (2, 1, 1, '国内新闻', '', '', 100, 1, '2026-04-24 16:58:48', '2026-04-24 16:58:48', NULL);
INSERT INTO `sm_article_category` VALUES (3, 1, 1, '国际新闻', '', '', 100, 1, '2026-04-24 16:59:01', '2026-04-24 16:59:01', NULL);

-- ----------------------------
-- Table structure for sm_system_attachment
-- ----------------------------
DROP TABLE IF EXISTS `sm_system_attachment`;
CREATE TABLE `sm_system_attachment`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NULL DEFAULT 0 COMMENT '单位编号',
  `category_id` int(11) NULL DEFAULT 0 COMMENT '附件分类',
  `storage_mode` smallint(6) NULL DEFAULT 1 COMMENT '存储模式 (1 本地 2 阿里云 3 七牛云 4 腾讯云)',
  `origin_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '原文件名',
  `object_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '新文件名',
  `hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '文件hash',
  `mime_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '资源类型',
  `storage_path` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '存储目录',
  `suffix` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '文件后缀',
  `size_byte` bigint(20) NULL DEFAULT NULL COMMENT '字节数',
  `size_info` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '文件大小',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'url地址',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `storage_path`(`storage_path`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 12 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '附件信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_system_attachment
-- ----------------------------
INSERT INTO `sm_system_attachment` VALUES (1, 0, 1, 1, '46062256.jpg', '7971881d7e10a122e0f51ea188571dbe29d82229.jpg', '7971881d7e10a122e0f51ea188571dbe29d82229', 'image/jpeg', 'public/storage/20260414/7971881d7e10a122e0f51ea188571dbe29d82229.jpg', 'jpg', 10142, '9.9 KB', 'http://127.0.0.1:8888/storage/20260414/7971881d7e10a122e0f51ea188571dbe29d82229.jpg', NULL, '2026-04-14 16:16:22', '2026-04-14 16:16:22', NULL);
INSERT INTO `sm_system_attachment` VALUES (2, 0, 3, 1, '46062256.jpg', '7971881d7e10a122e0f51ea188571dbe29d82229.jpg', '7971881d7e10a122e0f51ea188571dbe29d82229', 'image/jpeg', 'public/storage/20260414/7971881d7e10a122e0f51ea188571dbe29d82229.jpg', 'jpg', 10142, '9.9 KB', 'http://127.0.0.1:8888/storage/20260414/7971881d7e10a122e0f51ea188571dbe29d82229.jpg', NULL, '2026-04-14 16:17:30', '2026-04-14 16:17:30', NULL);
INSERT INTO `sm_system_attachment` VALUES (3, 0, 1, 1, '京北商城.png', '1a461f995803887b2441eff5bd55c24f30841eed.png', '1a461f995803887b2441eff5bd55c24f30841eed', 'image/png', 'public/storage/20260417/1a461f995803887b2441eff5bd55c24f30841eed.png', 'png', 43877, '42.85 KB', 'http://127.0.0.1:8888/storage/20260417/1a461f995803887b2441eff5bd55c24f30841eed.png', NULL, '2026-04-17 16:00:31', '2026-04-17 16:00:31', NULL);
INSERT INTO `sm_system_attachment` VALUES (4, 1, 1, 1, 'ref_image.png', 'f068744d25370a046b99c4335578cc729aa8d098.png', 'f068744d25370a046b99c4335578cc729aa8d098', 'image/png', 'public/storage/20260422/f068744d25370a046b99c4335578cc729aa8d098.png', 'png', 927395, '905.66 KB', 'http://127.0.0.1:8888/storage/20260422/f068744d25370a046b99c4335578cc729aa8d098.png', NULL, '2026-04-22 21:51:10', '2026-04-22 21:51:10', NULL);
INSERT INTO `sm_system_attachment` VALUES (5, 1, 1, 1, 'sai_logo.png', '6a6bd5a3bfe69bb6b97fdce9977296c66aef9903.png', '6a6bd5a3bfe69bb6b97fdce9977296c66aef9903', 'image/png', 'public/storage/20260422/6a6bd5a3bfe69bb6b97fdce9977296c66aef9903.png', 'png', 70788, '69.13 KB', 'http://127.0.0.1:8888/storage/20260422/6a6bd5a3bfe69bb6b97fdce9977296c66aef9903.png', NULL, '2026-04-22 21:51:39', '2026-04-22 21:51:39', NULL);
INSERT INTO `sm_system_attachment` VALUES (6, 1, 2, 1, 'SAI_Gen_1775402853165.png', '340423f8dd1296170692a0d5fa31465d65948376.png', '340423f8dd1296170692a0d5fa31465d65948376', 'image/png', 'public/storage/20260422/340423f8dd1296170692a0d5fa31465d65948376.png', 'png', 155615, '151.97 KB', 'http://127.0.0.1:8888/storage/20260422/340423f8dd1296170692a0d5fa31465d65948376.png', NULL, '2026-04-22 21:57:43', '2026-04-22 21:57:43', NULL);
INSERT INTO `sm_system_attachment` VALUES (8, 1, 1, 1, 'ref_image.png', 'f068744d25370a046b99c4335578cc729aa8d098.png', 'f068744d25370a046b99c4335578cc729aa8d098', 'image/png', 'public/storage/20260424/f068744d25370a046b99c4335578cc729aa8d098.png', 'png', 927395, '905.66 KB', 'http://127.0.0.1:8888/storage/20260424/f068744d25370a046b99c4335578cc729aa8d098.png', NULL, '2026-04-24 15:07:17', '2026-04-24 15:07:17', NULL);
INSERT INTO `sm_system_attachment` VALUES (9, 1, 1, 1, 'ref_image.png', 'f068744d25370a046b99c4335578cc729aa8d098.png', 'f068744d25370a046b99c4335578cc729aa8d098', 'image/png', 'public/storage/20260424/f068744d25370a046b99c4335578cc729aa8d098.png', 'png', 927395, '905.66 KB', 'http://127.0.0.1:8888/storage/20260424/f068744d25370a046b99c4335578cc729aa8d098.png', NULL, '2026-04-24 16:59:09', '2026-04-24 16:59:09', NULL);
INSERT INTO `sm_system_attachment` VALUES (10, 1, 1, 1, 'ref_image.png', 'f068744d25370a046b99c4335578cc729aa8d098.png', 'f068744d25370a046b99c4335578cc729aa8d098', 'image/png', 'public/storage/20260424/f068744d25370a046b99c4335578cc729aa8d098.png', 'png', 927395, '905.66 KB', 'http://127.0.0.1:8888/storage/20260424/f068744d25370a046b99c4335578cc729aa8d098.png', NULL, '2026-04-24 17:10:35', '2026-04-24 17:10:35', NULL);
INSERT INTO `sm_system_attachment` VALUES (11, 1, 1, 1, 'sai_logo.png', '6a6bd5a3bfe69bb6b97fdce9977296c66aef9903.png', '6a6bd5a3bfe69bb6b97fdce9977296c66aef9903', 'image/png', 'public/storage/20260424/6a6bd5a3bfe69bb6b97fdce9977296c66aef9903.png', 'png', 70788, '69.13 KB', 'http://127.0.0.1:8888/storage/20260424/6a6bd5a3bfe69bb6b97fdce9977296c66aef9903.png', NULL, '2026-04-24 17:23:20', '2026-04-24 17:23:20', NULL);

-- ----------------------------
-- Table structure for sm_system_category
-- ----------------------------
DROP TABLE IF EXISTS `sm_system_category`;
CREATE TABLE `sm_system_category`  (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `parent_id` int(11) NOT NULL DEFAULT 0 COMMENT '父id',
  `level` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '组集关系',
  `category_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '分类名称',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint(1) NULL DEFAULT 1 COMMENT '状态',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `pid`(`parent_id`) USING BTREE,
  INDEX `sort`(`sort`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '附件分类表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_system_category
-- ----------------------------
INSERT INTO `sm_system_category` VALUES (1, 0, '0,', '全部分类', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);
INSERT INTO `sm_system_category` VALUES (2, 1, '0,1,', '图片分类', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-04-15 07:24:51', NULL);
INSERT INTO `sm_system_category` VALUES (3, 1, '0,1,', '文件分类', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);
INSERT INTO `sm_system_category` VALUES (4, 1, '0,1,', '系统图片', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);
INSERT INTO `sm_system_category` VALUES (5, 1, '0,1,', '其他分类', 100, 1, NULL, 1, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL);

-- ----------------------------
-- Table structure for sm_system_config
-- ----------------------------
DROP TABLE IF EXISTS `sm_system_config`;
CREATE TABLE `sm_system_config`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `group_id` int(11) NULL DEFAULT NULL COMMENT '组id',
  `key` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '配置键名',
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '配置值',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '配置名称',
  `input_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '数据输入类型',
  `config_select_data` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '配置选项数据',
  `sort` smallint(5) UNSIGNED NULL DEFAULT 0 COMMENT '排序',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建人',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新人',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`, `key`) USING BTREE,
  INDEX `group_id`(`group_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 50 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '参数配置信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_system_config
-- ----------------------------
INSERT INTO `sm_system_config` VALUES (1, 1, 'site_copyright', 'Copyright © 2024 saithink', '版权信息', 'textarea', NULL, 96, '', NULL, NULL, NULL, '2026-04-18 00:05:35', NULL);
INSERT INTO `sm_system_config` VALUES (2, 1, 'site_desc', '基于vue3 + webman 的极速开发框架', '网站描述', 'textarea', NULL, 97, NULL, NULL, NULL, NULL, '2026-04-18 00:05:35', NULL);
INSERT INTO `sm_system_config` VALUES (3, 1, 'site_keywords', 'Saas管理系统', '网站关键字', 'input', '[]', 98, '', NULL, NULL, NULL, '2026-04-18 00:06:48', NULL);
INSERT INTO `sm_system_config` VALUES (4, 1, 'site_name', 'SaiSass', '网站名称', 'input', NULL, 99, NULL, NULL, NULL, NULL, '2026-04-18 00:05:35', NULL);
INSERT INTO `sm_system_config` VALUES (5, 1, 'site_record_number', '', '网站备案号', 'input', '[]', 95, '', NULL, NULL, NULL, '2026-04-18 00:07:49', NULL);
INSERT INTO `sm_system_config` VALUES (6, 2, 'upload_allow_file', 'txt,doc,docx,xls,xlsx,ppt,pptx,rar,zip,7z,gz,pdf,wps,md', '文件类型', 'input', NULL, 0, NULL, NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (7, 2, 'upload_allow_image', 'jpg,jpeg,png,gif,svg,bmp', '图片类型', 'input', NULL, 0, NULL, NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (8, 2, 'upload_mode', '1', '上传模式', 'select', '[{\"label\":\"本地上传\",\"value\":\"1\"},{\"label\":\"阿里云OSS\",\"value\":\"2\"},{\"label\":\"七牛云\",\"value\":\"3\"},{\"label\":\"腾讯云COS\",\"value\":\"4\"},{\"label\":\"亚马逊S3\",\"value\":\"5\"}]', 99, NULL, NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (10, 2, 'upload_size', '2147483648', '上传大小', 'input', NULL, 88, '单位Byte，单文件绝对上限2GiB；大文件使用S3直传分片', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (11, 2, 'local_root', 'public/storage/', '本地存储路径', 'input', NULL, 0, '本地存储文件路径', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (12, 2, 'local_domain', 'http://127.0.0.1:8888', '本地存储域名', 'input', NULL, 0, 'http://127.0.0.1:8787', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (13, 2, 'local_uri', '/storage/', '本地访问路径', 'input', NULL, 0, '访问是通过domain + uri', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (14, 2, 'qiniu_accessKey', '', '七牛key', 'input', NULL, 0, '七牛云存储secretId', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (15, 2, 'qiniu_secretKey', '', '七牛secret', 'input', NULL, 0, '七牛云存储secretKey', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (16, 2, 'qiniu_bucket', '', '七牛bucket', 'input', NULL, 0, '七牛云存储bucket', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (17, 2, 'qiniu_dirname', '', '七牛dirname', 'input', NULL, 0, '七牛云存储dirname', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (18, 2, 'qiniu_domain', '', '七牛domain', 'input', NULL, 0, '七牛云存储domain', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (19, 2, 'cos_secretId', '', '腾讯Id', 'input', NULL, 0, '腾讯云存储secretId', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (20, 2, 'cos_secretKey', '', '腾讯key', 'input', NULL, 0, '腾讯云secretKey', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (21, 2, 'cos_bucket', '', '腾讯bucket', 'input', NULL, 0, '腾讯云存储bucket', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (22, 2, 'cos_dirname', '', '腾讯dirname', 'input', NULL, 0, '腾讯云存储dirname', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (23, 2, 'cos_domain', '', '腾讯domain', 'input', NULL, 0, '腾讯云存储domain', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (24, 2, 'cos_region', '', '腾讯region', 'input', NULL, 0, '腾讯云存储region', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (25, 2, 'oss_accessKeyId', '', '阿里Id', 'input', NULL, 0, '阿里云存储accessKeyId', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (26, 2, 'oss_accessKeySecret', '', '阿里Secret', 'input', NULL, 0, '阿里云存储accessKeySecret', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (27, 2, 'oss_bucket', '', '阿里bucket', 'input', NULL, 0, '阿里云存储bucket', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (28, 2, 'oss_dirname', '', '阿里dirname', 'input', NULL, 0, '阿里云存储dirname', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (29, 2, 'oss_domain', '', '阿里domain', 'input', NULL, 0, '阿里云存储domain', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (30, 2, 'oss_endpoint', '', '阿里endpoint', 'input', NULL, 0, '阿里云存储endpoint', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (31, 2, 's3_key', '', 'key', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (32, 2, 's3_secret', '', 'secret', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (33, 2, 's3_bucket', '', 'bucket', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (34, 2, 's3_dirname', '', 'dirname', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (35, 2, 's3_domain', '', 'domain', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (36, 2, 's3_region', '', 'region', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (37, 2, 's3_version', '', 'version', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (38, 2, 's3_use_path_style_endpoint', '', 'path_style_endpoint', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (39, 2, 's3_endpoint', '', 'endpoint', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (40, 2, 's3_acl', '', 'acl', 'input', '', 0, '', NULL, NULL, NULL, '2026-04-14 21:59:03', NULL);
INSERT INTO `sm_system_config` VALUES (41, 3, 'Host', 'smtp.qq.com', 'SMTP服务器', 'input', '', 100, '', NULL, NULL, NULL, '2025-06-07 14:59:37', NULL);
INSERT INTO `sm_system_config` VALUES (42, 3, 'Port', '465', 'SMTP端口', 'input', '', 100, '', NULL, NULL, NULL, '2025-06-07 14:59:37', NULL);
INSERT INTO `sm_system_config` VALUES (43, 3, 'Username', '', 'SMTP用户名', 'input', '', 100, '', NULL, NULL, NULL, '2025-06-07 14:59:37', NULL);
INSERT INTO `sm_system_config` VALUES (44, 3, 'Password', '', 'SMTP密码', 'input', '', 100, '', NULL, NULL, NULL, '2025-06-07 14:59:37', NULL);
INSERT INTO `sm_system_config` VALUES (45, 3, 'SMTPSecure', 'ssl', 'SMTP验证方式', 'radio', '[{\"label\":\"ssl\",\"value\":\"ssl\"},{\"label\":\"tsl\",\"value\":\"tsl\"}]', 100, '', NULL, NULL, NULL, '2026-04-18 00:07:07', NULL);
INSERT INTO `sm_system_config` VALUES (46, 3, 'From', '', '默认发件人', 'input', '', 100, '默认发件的邮箱地址', NULL, NULL, NULL, '2025-06-07 14:59:37', NULL);
INSERT INTO `sm_system_config` VALUES (47, 3, 'FromName', '', '默认发件名称', 'input', '', 100, '', NULL, NULL, NULL, '2025-06-07 14:59:37', NULL);
INSERT INTO `sm_system_config` VALUES (48, 3, 'CharSet', 'UTF-8', '编码', 'input', '', 100, '', NULL, NULL, NULL, '2025-06-07 14:59:37', NULL);
INSERT INTO `sm_system_config` VALUES (49, 3, 'SMTPDebug', '0', '调试模式', 'radio', '[\r\n    {\"label\":\"关闭\",\"value\":\"0\"},\r\n    {\"label\":\"client\",\"value\":\"1\"},\r\n    {\"label\":\"server\",\"value\":\"2\"}\r\n]', 100, '', NULL, NULL, NULL, '2025-06-07 14:59:37', NULL);

-- ----------------------------
-- Table structure for sm_system_config_group
-- ----------------------------
DROP TABLE IF EXISTS `sm_system_config_group`;
CREATE TABLE `sm_system_config_group`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字典名称',
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字典标示',
  `type` tinyint(1) NULL DEFAULT 1 COMMENT '类型',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建人',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新人',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '参数配置分组表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_system_config_group
-- ----------------------------
INSERT INTO `sm_system_config_group` VALUES (1, '站点配置', 'site_config', 1, '18', 1, 11, '2021-11-23 10:49:29', '2025-04-17 17:20:45', NULL);
INSERT INTO `sm_system_config_group` VALUES (2, '上传配置', 'upload_config', 1, NULL, 1, 1, '2021-11-23 10:49:29', '2021-11-23 10:49:29', NULL);
INSERT INTO `sm_system_config_group` VALUES (3, '邮件服务', 'email_config', 2, NULL, 1, 1, '2021-11-23 10:49:29', '2025-04-17 17:10:04', NULL);

-- ----------------------------
-- Table structure for sm_system_dict_data
-- ----------------------------
DROP TABLE IF EXISTS `sm_system_dict_data`;
CREATE TABLE `sm_system_dict_data`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `type_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '字典类型ID',
  `label` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字典标签',
  `value` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字典值',
  `color` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '颜色',
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字典标示',
  `sort` smallint(5) UNSIGNED NULL DEFAULT 0 COMMENT '排序',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `type_id`(`type_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 34 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '字典数据表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_system_dict_data
-- ----------------------------
INSERT INTO `sm_system_dict_data` VALUES (1, 2, '本地存储', '1', '#5d87ff', 'upload_mode', 99, 1, '', '2021-06-27 13:33:43', '2025-06-08 11:11:55', NULL);
INSERT INTO `sm_system_dict_data` VALUES (2, 2, '阿里云OSS', '2', '#f9901f', 'upload_mode', 98, 1, NULL, '2021-06-27 13:33:55', '2021-06-27 13:33:55', NULL);
INSERT INTO `sm_system_dict_data` VALUES (3, 2, '七牛云', '3', '#00ced1', 'upload_mode', 97, 1, NULL, '2021-06-27 13:34:07', '2023-12-13 16:50:26', NULL);
INSERT INTO `sm_system_dict_data` VALUES (4, 2, '腾讯云COS', '4', '#1d84ff', 'upload_mode', 96, 1, NULL, '2021-06-27 13:34:19', '2023-12-13 16:47:34', NULL);
INSERT INTO `sm_system_dict_data` VALUES (5, 2, 'S3存储', '5', '#ed53b4', 'upload_mode', 95, 1, '', '2025-06-08 11:11:35', '2026-04-14 16:47:45', NULL);
INSERT INTO `sm_system_dict_data` VALUES (7, 3, '正常', '1', '#13deb9', 'data_status', 0, 1, '1为正常', '2021-06-27 13:36:51', '2025-06-06 17:22:14', NULL);
INSERT INTO `sm_system_dict_data` VALUES (8, 3, '停用', '2', '#ff4d4f', 'data_status', 0, 1, '2为停用', '2021-06-27 13:37:10', '2025-06-06 17:22:29', NULL);
INSERT INTO `sm_system_dict_data` VALUES (9, 4, '统计页面', 'statistics', '#00ced1', 'admin_dashboard', 0, 1, '管理员用', '2021-08-09 12:53:53', '2023-11-16 11:39:17', NULL);
INSERT INTO `sm_system_dict_data` VALUES (10, 4, '工作台', 'work', '#ff8c00', 'admin_dashboard', 0, 1, '员工使用', '2021-08-09 12:54:18', '2021-08-09 12:54:18', NULL);
INSERT INTO `sm_system_dict_data` VALUES (11, 5, '男', '1', '#5d87ff', 'sex', 0, 1, NULL, '2021-08-09 12:55:00', '2021-08-09 12:55:00', NULL);
INSERT INTO `sm_system_dict_data` VALUES (12, 5, '女', '2', '#ff4500', 'sex', 0, 1, NULL, '2021-08-09 12:55:08', '2021-08-09 12:55:08', NULL);
INSERT INTO `sm_system_dict_data` VALUES (13, 5, '未知', '3', '#b48df3', 'sex', 0, 1, NULL, '2021-08-09 12:55:16', '2021-08-09 12:55:16', NULL);
INSERT INTO `sm_system_dict_data` VALUES (14, 7, '通知', '1', '#5d87ff', 'backend_notice_type', 2, 1, NULL, '2021-11-11 17:29:27', '2021-11-11 17:30:51', NULL);
INSERT INTO `sm_system_dict_data` VALUES (15, 7, '公告', '2', '#ff4500', 'backend_notice_type', 1, 1, NULL, '2021-11-11 17:31:42', '2021-11-11 17:31:42', NULL);
INSERT INTO `sm_system_dict_data` VALUES (16, 8, '统计页面', 'statistics', '#00ced1', 'tenant_dashboard', 0, 1, '管理员用', '2021-08-09 12:53:53', '2023-11-16 11:39:17', NULL);
INSERT INTO `sm_system_dict_data` VALUES (17, 8, '工作台', 'work', '#ff8c00', 'tenant_dashboard', 0, 1, '员工使用', '2021-08-09 12:54:18', '2021-08-09 12:54:18', NULL);
INSERT INTO `sm_system_dict_data` VALUES (18, 12, '图片', 'image', '#60c041', 'attachment_type', 10, 1, NULL, '2022-03-17 14:49:59', '2022-03-17 14:49:59', NULL);
INSERT INTO `sm_system_dict_data` VALUES (19, 12, '文档', 'text', '#1d84ff', 'attachment_type', 9, 1, NULL, '2022-03-17 14:50:20', '2022-03-17 14:50:49', NULL);
INSERT INTO `sm_system_dict_data` VALUES (20, 12, '音频', 'audio', '#00ced1', 'attachment_type', 8, 1, NULL, '2022-03-17 14:50:37', '2022-03-17 14:50:52', NULL);
INSERT INTO `sm_system_dict_data` VALUES (21, 12, '视频', 'video', '#ff4500', 'attachment_type', 7, 1, NULL, '2022-03-17 14:50:45', '2022-03-17 14:50:57', NULL);
INSERT INTO `sm_system_dict_data` VALUES (22, 12, '应用程序', 'application', '#ff8c00', 'attachment_type', 6, 1, NULL, '2022-03-17 14:50:52', '2022-03-17 14:50:59', NULL);
INSERT INTO `sm_system_dict_data` VALUES (23, 13, '目录', '1', '#909399', 'menu_type', 100, 1, '', '2024-07-31 10:34:12', '2024-07-31 10:34:12', NULL);
INSERT INTO `sm_system_dict_data` VALUES (24, 13, '菜单', '2', '#1e90ff', 'menu_type', 100, 1, '', '2024-07-31 10:34:20', '2024-07-31 10:34:20', NULL);
INSERT INTO `sm_system_dict_data` VALUES (25, 13, '按钮', '3', '#ff4500', 'menu_type', 100, 1, '', '2024-07-31 10:34:27', '2024-07-31 10:34:27', NULL);
INSERT INTO `sm_system_dict_data` VALUES (26, 13, '外链', '4', '#00ced1', 'menu_type', 100, 1, '', '2024-07-31 10:34:51', '2024-07-31 10:34:51', NULL);
INSERT INTO `sm_system_dict_data` VALUES (27, 14, '是', '1', '#60c041', 'yes_or_no', 100, 1, '', '2024-07-31 10:35:17', '2024-07-31 10:35:17', NULL);
INSERT INTO `sm_system_dict_data` VALUES (28, 14, '否', '2', '#ff4500', 'yes_or_no', 100, 1, '', '2024-07-31 10:35:22', '2024-07-31 10:35:22', NULL);
INSERT INTO `sm_system_dict_data` VALUES (29, 15, '管理员', '100', '#60c041', 'user_type', 100, 1, '', '2024-08-10 08:44:56', '2026-04-15 08:04:45', NULL);
INSERT INTO `sm_system_dict_data` VALUES (30, 15, '普通账户', '200', '#1d84ff', 'user_type', 100, 1, '', '2024-08-10 08:45:21', '2026-04-15 08:04:45', NULL);
INSERT INTO `sm_system_dict_data` VALUES (31, 16, 'URL任务GET', '1', '#5d87ff', 'crontab_task_type', 100, 1, '', '2026-04-15 08:22:11', '2026-04-15 08:22:11', NULL);
INSERT INTO `sm_system_dict_data` VALUES (32, 16, 'URL任务POST', '2', '#b48df3', 'crontab_task_type', 100, 1, '', '2026-04-15 08:22:31', '2026-04-15 08:23:01', NULL);
INSERT INTO `sm_system_dict_data` VALUES (33, 16, '类任务', '3', '#38c0fc', 'crontab_task_type', 100, 1, '', '2026-04-15 08:22:54', '2026-04-15 08:22:54', NULL);

-- ----------------------------
-- Table structure for sm_system_dict_type
-- ----------------------------
DROP TABLE IF EXISTS `sm_system_dict_type`;
CREATE TABLE `sm_system_dict_type`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字典名称',
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '字典标示',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 17 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '字典类型表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_system_dict_type
-- ----------------------------
INSERT INTO `sm_system_dict_type` VALUES (2, '存储模式', 'upload_mode', 1, '上传文件存储模式', '2021-06-27 13:33:29', '2025-06-07 17:19:06', NULL);
INSERT INTO `sm_system_dict_type` VALUES (3, '数据状态', 'data_status', 1, '通用数据状态', '2021-06-27 13:33:29', '2025-06-07 17:09:04', NULL);
INSERT INTO `sm_system_dict_type` VALUES (4, '后台首页', 'admin_dashboard', 1, '', '2021-06-27 13:33:29', '2025-06-09 15:19:19', NULL);
INSERT INTO `sm_system_dict_type` VALUES (5, '性别', 'gender', 1, NULL, '2021-06-27 13:33:29', '2023-11-16 11:39:12', NULL);
INSERT INTO `sm_system_dict_type` VALUES (7, '后台公告类型', 'backend_notice_type', 1, NULL, '2021-06-27 13:33:29', '2021-11-11 17:29:14', NULL);
INSERT INTO `sm_system_dict_type` VALUES (8, '管理端首页', 'tenant_dashboard', 1, '', '2021-06-27 13:33:29', '2025-06-09 15:19:19', NULL);
INSERT INTO `sm_system_dict_type` VALUES (12, '附件类型', 'attachment_type', 1, NULL, '2021-06-27 13:33:29', '2022-03-17 14:49:23', NULL);
INSERT INTO `sm_system_dict_type` VALUES (13, '菜单类型', 'menu_type', 1, '', '2024-07-31 10:33:37', '2024-07-31 10:33:37', NULL);
INSERT INTO `sm_system_dict_type` VALUES (14, '是否', 'yes_or_no', 1, '', '2024-07-31 10:35:07', '2024-07-31 10:35:07', NULL);
INSERT INTO `sm_system_dict_type` VALUES (15, '账户类型', 'user_type', 1, '', '2024-08-10 08:44:31', '2026-04-15 08:04:45', NULL);
INSERT INTO `sm_system_dict_type` VALUES (16, '任务类型', 'crontab_task_type', 1, '', '2026-04-15 08:19:42', '2026-04-15 08:19:42', NULL);

-- ----------------------------
-- Table structure for sm_system_organization
-- ----------------------------
DROP TABLE IF EXISTS `sm_system_organization`;
CREATE TABLE `sm_system_organization`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '机构编号',
  `group_id` int(11) NULL DEFAULT NULL COMMENT '机构分组',
  `domain` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '域名',
  `enterprise_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '公开企业码',
  `deployment_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '目标部署信任域',
  `config_version` bigint(20) UNSIGNED NOT NULL DEFAULT 1 COMMENT '公开配置版本',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '标题',
  `logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '图标',
  `favicon` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '站点图标',
  `icp` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'ICP 备案号',
  `public_security_record_no` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '公安备案号',
  `public_security_record_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '公安备案链接',
  `copyright` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '版权信息',
  `android_download_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'Android 下载地址',
  `ios_download_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'iOS 下载地址',
  `api_server_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'API 服务地址',
  `im_server_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'IM 服务地址',
  `upload_server_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '上传服务地址',
  `web_server_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'Web 服务地址',
  `user_agreement_title` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '用户协议' COMMENT '用户协议标题',
  `user_agreement_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '用户协议内容',
  `privacy_policy_title` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '隐私政策' COMMENT '隐私政策标题',
  `privacy_policy_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '隐私政策内容',
  `organization_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '机构名称',
  `province` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '省',
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '市',
  `area` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '区',
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '地址',
  `contact_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '联系人',
  `contact_phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '联系电话',
  `contact_email` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '联系邮箱',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `is_init` tinyint(1) NULL DEFAULT 2 COMMENT '初始化',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_sm_org_enterprise_code`(`enterprise_code`) USING BTREE,
  UNIQUE INDEX `uk_sm_org_domain`(`domain`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '机构信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_system_organization
-- ----------------------------
INSERT INTO `sm_system_organization` (
  `id`, `group_id`, `domain`, `enterprise_code`, `deployment_id`, `config_version`,
  `title`, `logo`, `favicon`, `icp`, `public_security_record_no`, `public_security_record_url`, `copyright`,
  `android_download_url`, `ios_download_url`, `api_server_url`, `im_server_url`, `upload_server_url`, `web_server_url`,
  `user_agreement_title`, `user_agreement_content`, `privacy_policy_title`, `privacy_policy_content`,
  `organization_name`, `province`, `city`, `area`, `address`, `contact_name`, `contact_phone`, `contact_email`,
  `status`, `remark`, `is_init`, `create_time`, `update_time`, `delete_time`
) VALUES (
  1, 1, NULL, 'org_1', 'b8im-local', 1,
  '京北商城', 'http://127.0.0.1:8888/storage/20260417/1a461f995803887b2441eff5bd55c24f30841eed.png', '', '', '', '', 'Copyright © B8IM',
  '', '', 'http://127.0.0.1:8888', 'ws://127.0.0.1:7272', 'http://127.0.0.1:8888', 'http://127.0.0.1:5173',
  '用户协议', '', '隐私政策', '',
  '京北商城', '130000000000', '130400000000', '130404000000', '河马大道158号', '张三', '15888888888', '15888888888@qq.com',
  1, '', 1, '2026-04-17 16:01:24', '2026-04-25 23:59:37', NULL
);

-- ----------------------------
-- Table structure for sm_tenant_config
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_config`;
CREATE TABLE `sm_tenant_config`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `organization` int(11) NULL DEFAULT NULL COMMENT '机构编号',
  `group_id` int(11) NULL DEFAULT NULL COMMENT '组id',
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '配置值',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `group_id`(`group_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '租户配置信息' ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of sm_tenant_config
-- ----------------------------
INSERT INTO `sm_tenant_config` VALUES (1, 1, 3, '{\"Host\":\"smtp.qq.com\",\"Port\":\"465\",\"Username\":\"root\",\"Password\":\"123456\",\"SMTPSecure\":\"ssl\",\"From\":\"hehe\",\"FromName\":\"\\u5f20\\u4e09\",\"CharSet\":\"UTF-8\",\"SMTPDebug\":\"0\"}', '2026-04-23 23:08:11', '2026-04-23 23:08:41', NULL);

-- ----------------------------
-- Table structure for sm_tenant_dept
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_dept`;
CREATE TABLE `sm_tenant_dept`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NULL DEFAULT 1 COMMENT '机构编号',
  `parent_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '父ID',
  `level` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '组级集合',
  `name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '部门名称',
  `leader` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '负责人',
  `phone` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '联系电话',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `sort` smallint(5) UNSIGNED NULL DEFAULT 0 COMMENT '排序',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `parent_id`(`parent_id`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '部门信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_dept
-- ----------------------------
INSERT INTO `sm_tenant_dept` VALUES (1, 1, 0, '0', '总经办', NULL, NULL, 1, 100, '', '2026-04-22 17:14:42', '2026-04-22 17:14:42', NULL);
INSERT INTO `sm_tenant_dept` VALUES (2, 1, 1, '0,1', '技术部', NULL, NULL, 1, 100, '', '2026-04-22 17:14:59', '2026-04-22 17:14:59', NULL);
INSERT INTO `sm_tenant_dept` VALUES (3, 1, 1, '0,1', '销售部', NULL, NULL, 1, 100, '', '2026-04-22 17:16:02', '2026-04-22 17:16:02', NULL);

-- ----------------------------
-- Table structure for sm_tenant_dept_leader
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_dept_leader`;
CREATE TABLE `sm_tenant_dept_leader`  (
  `leader_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `dept_id` int(11) UNSIGNED NOT NULL COMMENT '部门主键',
  `user_id` int(11) UNSIGNED NOT NULL COMMENT '角色主键',
  PRIMARY KEY (`leader_id`) USING BTREE,
  INDEX `idx_dept_id`(`dept_id`) USING BTREE,
  INDEX `idx_user_id`(`user_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '部门领导关联表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_dept_leader
-- ----------------------------

-- ----------------------------
-- Table structure for sm_tenant_group
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_group`;
CREATE TABLE `sm_tenant_group`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `group_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '分组名称',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '描述',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '内容详情',
  `sort` smallint(4) NULL DEFAULT 100 COMMENT '排序',
  `status` tinyint(1) NULL DEFAULT 1 COMMENT '状态',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '机构分组表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_group
-- ----------------------------
INSERT INTO `sm_tenant_group` VALUES (1, '基础版', '', '<p><img src=\"http://127.0.0.1:8888/storage/20260414/7971881d7e10a122e0f51ea188571dbe29d82229.jpg\" alt=\"\" data-href=\"\" style=\"\"/></p>', 100, 1, '2026-04-16 15:55:33', '2026-04-16 16:01:48', NULL);
INSERT INTO `sm_tenant_group` VALUES (2, '高级版', '', '<p><br></p>', 100, 1, '2026-04-16 16:03:50', '2026-04-16 16:03:50', NULL);
INSERT INTO `sm_tenant_group` VALUES (3, 'Plus版', '', '<p><br></p>', 100, 1, '2026-04-16 16:04:05', '2026-04-16 16:04:05', NULL);

-- ----------------------------
-- Table structure for sm_tenant_group_menu
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_group_menu`;
CREATE TABLE `sm_tenant_group_menu`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `group_id` int(11) UNSIGNED NOT NULL COMMENT '分组主键',
  `menu_id` int(11) UNSIGNED NOT NULL COMMENT '菜单主键',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_menu_id`(`menu_id`) USING BTREE,
  INDEX `idx_group_id`(`group_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 93 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '机构分组与菜单关联表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_group_menu
-- ----------------------------
INSERT INTO `sm_tenant_group_menu` VALUES (20, 1, 3000);
INSERT INTO `sm_tenant_group_menu` VALUES (21, 1, 3001);
INSERT INTO `sm_tenant_group_menu` VALUES (22, 1, 3002);
INSERT INTO `sm_tenant_group_menu` VALUES (23, 1, 3003);
INSERT INTO `sm_tenant_group_menu` VALUES (24, 1, 3004);
INSERT INTO `sm_tenant_group_menu` VALUES (25, 1, 3005);
INSERT INTO `sm_tenant_group_menu` VALUES (26, 1, 3007);
INSERT INTO `sm_tenant_group_menu` VALUES (27, 1, 3011);
INSERT INTO `sm_tenant_group_menu` VALUES (28, 1, 3012);
INSERT INTO `sm_tenant_group_menu` VALUES (29, 1, 3013);
INSERT INTO `sm_tenant_group_menu` VALUES (30, 1, 3014);
INSERT INTO `sm_tenant_group_menu` VALUES (31, 1, 3015);
INSERT INTO `sm_tenant_group_menu` VALUES (32, 1, 3017);
INSERT INTO `sm_tenant_group_menu` VALUES (33, 1, 3021);
INSERT INTO `sm_tenant_group_menu` VALUES (34, 1, 3022);
INSERT INTO `sm_tenant_group_menu` VALUES (35, 1, 3023);
INSERT INTO `sm_tenant_group_menu` VALUES (36, 1, 3024);
INSERT INTO `sm_tenant_group_menu` VALUES (37, 1, 3025);
INSERT INTO `sm_tenant_group_menu` VALUES (38, 1, 3027);
INSERT INTO `sm_tenant_group_menu` VALUES (39, 1, 1000);
INSERT INTO `sm_tenant_group_menu` VALUES (40, 1, 1100);
INSERT INTO `sm_tenant_group_menu` VALUES (41, 1, 1101);
INSERT INTO `sm_tenant_group_menu` VALUES (42, 1, 1103);
INSERT INTO `sm_tenant_group_menu` VALUES (43, 1, 1104);
INSERT INTO `sm_tenant_group_menu` VALUES (44, 1, 1105);
INSERT INTO `sm_tenant_group_menu` VALUES (45, 1, 1106);
INSERT INTO `sm_tenant_group_menu` VALUES (46, 1, 1111);
INSERT INTO `sm_tenant_group_menu` VALUES (47, 1, 1112);
INSERT INTO `sm_tenant_group_menu` VALUES (48, 1, 1113);
INSERT INTO `sm_tenant_group_menu` VALUES (49, 1, 1114);
INSERT INTO `sm_tenant_group_menu` VALUES (50, 1, 1300);
INSERT INTO `sm_tenant_group_menu` VALUES (51, 1, 1301);
INSERT INTO `sm_tenant_group_menu` VALUES (52, 1, 1303);
INSERT INTO `sm_tenant_group_menu` VALUES (53, 1, 1304);
INSERT INTO `sm_tenant_group_menu` VALUES (54, 1, 1305);
INSERT INTO `sm_tenant_group_menu` VALUES (55, 1, 1306);
INSERT INTO `sm_tenant_group_menu` VALUES (56, 1, 1307);
INSERT INTO `sm_tenant_group_menu` VALUES (57, 1, 1308);
INSERT INTO `sm_tenant_group_menu` VALUES (58, 1, 1309);
INSERT INTO `sm_tenant_group_menu` VALUES (59, 1, 1400);
INSERT INTO `sm_tenant_group_menu` VALUES (60, 1, 1401);
INSERT INTO `sm_tenant_group_menu` VALUES (61, 1, 1403);
INSERT INTO `sm_tenant_group_menu` VALUES (62, 1, 1404);
INSERT INTO `sm_tenant_group_menu` VALUES (63, 1, 1405);
INSERT INTO `sm_tenant_group_menu` VALUES (64, 1, 1406);
INSERT INTO `sm_tenant_group_menu` VALUES (65, 1, 1407);
INSERT INTO `sm_tenant_group_menu` VALUES (66, 1, 1500);
INSERT INTO `sm_tenant_group_menu` VALUES (67, 1, 1501);
INSERT INTO `sm_tenant_group_menu` VALUES (68, 1, 1503);
INSERT INTO `sm_tenant_group_menu` VALUES (69, 1, 1504);
INSERT INTO `sm_tenant_group_menu` VALUES (70, 1, 1505);
INSERT INTO `sm_tenant_group_menu` VALUES (71, 1, 1506);
INSERT INTO `sm_tenant_group_menu` VALUES (72, 1, 1507);
INSERT INTO `sm_tenant_group_menu` VALUES (73, 1, 2000);
INSERT INTO `sm_tenant_group_menu` VALUES (74, 1, 2100);
INSERT INTO `sm_tenant_group_menu` VALUES (75, 1, 2101);
INSERT INTO `sm_tenant_group_menu` VALUES (76, 1, 2103);
INSERT INTO `sm_tenant_group_menu` VALUES (77, 1, 2104);
INSERT INTO `sm_tenant_group_menu` VALUES (78, 1, 2105);
INSERT INTO `sm_tenant_group_menu` VALUES (79, 1, 2106);
INSERT INTO `sm_tenant_group_menu` VALUES (80, 1, 2200);
INSERT INTO `sm_tenant_group_menu` VALUES (81, 1, 2201);
INSERT INTO `sm_tenant_group_menu` VALUES (82, 1, 2202);
INSERT INTO `sm_tenant_group_menu` VALUES (83, 1, 2250);
INSERT INTO `sm_tenant_group_menu` VALUES (84, 1, 2251);
INSERT INTO `sm_tenant_group_menu` VALUES (85, 1, 2252);
INSERT INTO `sm_tenant_group_menu` VALUES (86, 1, 2253);
INSERT INTO `sm_tenant_group_menu` VALUES (87, 1, 2300);
INSERT INTO `sm_tenant_group_menu` VALUES (88, 1, 2301);
INSERT INTO `sm_tenant_group_menu` VALUES (89, 1, 2302);
INSERT INTO `sm_tenant_group_menu` VALUES (90, 1, 2400);
INSERT INTO `sm_tenant_group_menu` VALUES (91, 1, 2401);
INSERT INTO `sm_tenant_group_menu` VALUES (92, 1, 2402);

-- ----------------------------
-- Table structure for sm_tenant_menu
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_menu`;
CREATE TABLE `sm_tenant_menu`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NULL DEFAULT 0 COMMENT '机构编号',
  `parent_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '父ID',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '菜单名称',
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '菜单标识代码',
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '权限标识',
  `type` tinyint(1) NULL DEFAULT NULL COMMENT '菜单类型',
  `path` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '路由地址',
  `component` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '组件路径',
  `method` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '菜单图标',
  `sort` smallint(5) UNSIGNED NULL DEFAULT 0 COMMENT '排序',
  `link_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '跳转地址',
  `is_iframe` tinyint(1) NULL DEFAULT NULL COMMENT '是否iframe',
  `is_keep_alive` tinyint(1) NULL DEFAULT NULL COMMENT '是否缓存',
  `is_hidden` tinyint(1) NULL DEFAULT 1 COMMENT '是否隐藏',
  `is_fixed_tab` tinyint(1) NULL DEFAULT NULL COMMENT '是否固定标签页',
  `is_full_page` tinyint(1) NULL DEFAULT NULL COMMENT '是否全屏',
  `generate_id` int(11) NULL DEFAULT 0 COMMENT '生成id',
  `generate_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '生成key',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE,
  INDEX `idx_parent`(`parent_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3028 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '菜单信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_menu
-- ----------------------------
INSERT INTO `sm_tenant_menu` VALUES (1, 0, 0, '仪表盘', 'Dashboard', '', 1, '/dashboard', NULL, NULL, 'ri:pie-chart-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2025-02-08 15:10:27', '2025-02-08 15:10:27', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2, 0, 1, '工作台', 'Console', '', 2, 'console', '/dashboard/console', NULL, 'ri:home-smile-2-line', 100, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2025-02-08 15:10:27', '2025-02-08 15:10:27', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3, 0, 1, '个人中心', 'UserCenter', '', 2, 'user-center', '/dashboard/user-center/index', NULL, 'ri:user-2-line', 100, '', 2, 1, 2, 2, 2, 0, NULL, 1, NULL, '2025-02-08 15:10:27', '2025-02-08 15:10:27', NULL);
INSERT INTO `sm_tenant_menu` VALUES (100, 0, 0, '附加权限', 'Permission', '', 1, 'permission', '', NULL, 'ri:apps-2-ai-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:04:01', '2026-04-18 00:04:01', NULL);
INSERT INTO `sm_tenant_menu` VALUES (101, 0, 100, '上传图片', '', 'saimulti:tenant:uploadImage', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:04:34', '2026-04-18 00:04:34', NULL);
INSERT INTO `sm_tenant_menu` VALUES (102, 0, 100, '上传文件', '', 'saimulti:tenant:uploadFile', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:04:48', '2026-04-18 00:04:48', NULL);
INSERT INTO `sm_tenant_menu` VALUES (103, 0, 100, '切片上传', '', 'saimulti:tenant:chunkUpload', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:05:01', '2026-04-18 00:05:01', NULL);
INSERT INTO `sm_tenant_menu` VALUES (104, 0, 100, '附件信息', '', 'saimulti:tenant:resource', 3, '', '', NULL, '', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2026-04-18 00:05:14', '2026-04-18 00:05:14', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1000, 0, 0, '权限管理', 'permission', '', 1, 'permission', '', NULL, 'ri:dashboard-horizontal-line', 90, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-22 11:44:12', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1100, 0, 1000, '用户管理', 'system/user', '', 2, 'user', '/system/user/index', NULL, 'ri:user-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-22 11:44:36', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1101, 0, 1100, '用户列表', NULL, 'saimulti:tenant:user:index', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1103, 0, 1100, '用户保存', NULL, 'saimulti:tenant:user:save', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1104, 0, 1100, '用户更新', NULL, 'saimulti:tenant:user:update', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1105, 0, 1100, '用户删除', NULL, 'saimulti:tenant:user:destroy', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1106, 0, 1100, '用户读取', NULL, 'saimulti:tenant:user:read', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1112, 0, 1100, '重置密码', NULL, 'saimulti:tenant:user:password', 3, '', '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2025-06-09 14:59:30', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1113, 0, 1100, '更新缓存', NULL, 'saimulti:tenant:user:cache', 3, NULL, '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1114, 0, 1100, '设置首页', NULL, 'saimulti:tenant:user:home', 3, NULL, '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1300, 0, 1000, '部门管理', 'system/dept', '', 2, 'dept', '/system/dept/index', NULL, 'ri:node-tree', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-22 11:44:50', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1301, 0, 1300, '部门列表', NULL, 'saimulti:tenant:dept:index', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1303, 0, 1300, '部门保存', NULL, 'saimulti:tenant:dept:save', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1304, 0, 1300, '部门更新', NULL, 'saimulti:tenant:dept:update', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1305, 0, 1300, '部门删除', NULL, 'saimulti:tenant:dept:destroy', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1306, 0, 1300, '部门读取', NULL, 'saimulti:tenant:dept:read', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1400, 0, 1000, '角色管理', 'system/role', '', 2, 'role', '/system/role/index', NULL, 'ri:admin-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-22 11:45:02', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1401, 0, 1400, '角色列表', NULL, 'saimulti:tenant:role:index', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1403, 0, 1400, '角色保存', NULL, 'saimulti:tenant:role:save', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1404, 0, 1400, '角色更新', NULL, 'saimulti:tenant:role:update', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1405, 0, 1400, '角色删除', NULL, 'saimulti:tenant:role:destroy', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1406, 0, 1400, '角色读取', NULL, 'saimulti:tenant:role:read', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1407, 0, 1400, '菜单权限', NULL, 'saimulti:tenant:role:menu', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1500, 0, 1000, '岗位管理', 'system/post', '', 2, 'post', '/system/post/index', NULL, 'ri:user-location-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-22 11:45:41', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1501, 0, 1500, '岗位列表', NULL, 'saimulti:tenant:post:index', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1503, 0, 1500, '岗位保存', NULL, 'saimulti:tenant:post:save', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1504, 0, 1500, '岗位更新', NULL, 'saimulti:tenant:post:update', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1505, 0, 1500, '岗位删除', NULL, 'saimulti:tenant:post:destroy', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (1506, 0, 1500, '岗位读取', NULL, 'saimulti:tenant:post:read', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2000, 0, 0, '系统管理', 'system', '', 1, 'system', '', NULL, 'ri:settings-4-line', 80, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-22 17:28:21', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2200, 0, 2000, '系统配置', '/saimulti/tenant/config', '', 2, 'tenant/config', '/system/setting/index', NULL, 'ri:settings-6-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-24 14:58:16', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2201, 0, 2200, '基础配置', NULL, 'saimulti:tenant:config:index', 3, '', '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-09 15:03:52', '2025-06-09 15:03:52', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2202, 0, 2200, '配置保存', NULL, 'saimulti:tenant:config:save', 3, '', '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '', NULL, '2025-06-09 15:04:05', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2203, 0, 2200, '分组配置', NULL, 'saimulti:tenant:group:index', 3, '', '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-09 15:03:52', '2025-06-09 15:03:52', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2204, 0, 2200, '保存配置', NULL, 'saimulti:tenant:group:save', 3, '', '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '', NULL, '2025-06-09 15:04:05', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2250, 0, 2000, '附件管理', 'tenant/attachment', '', 2, 'tenant/attachment', '/system/attachment/index', NULL, 'ri:file-cloud-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', NULL, '2026-04-22 22:02:15', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2251, 0, 2250, '列表', NULL, 'saimulti:tenant:attachment:index', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, NULL, NULL, NULL);
INSERT INTO `sm_tenant_menu` VALUES (2252, 0, 2250, '编辑', NULL, 'saimulti:tenant:attachment:edit', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, NULL, NULL, NULL);
INSERT INTO `sm_tenant_menu` VALUES (2253, 0, 2250, '删除', NULL, 'saimulti:tenant:attachment:destroy', 3, '', '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '', NULL, '2025-06-09 14:12:01', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2300, 0, 2000, '登录日志', 'system/loginList', '', 2, 'tenant/loginLog', '/system/login-log/index', NULL, 'ri:login-circle-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-22 21:41:28', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2301, 0, 2300, '登录日志列表', NULL, 'saimulti:tenant:login:index', 3, '', '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-09 15:04:53', '2025-06-09 15:04:53', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2302, 0, 2300, '登录日志删除', NULL, 'saimulti:tenant:login:destroy', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2400, 0, 2000, '操作日志', 'system/operLog', '', 2, 'tenant/operLog', '/system/oper-log/index', NULL, 'ri:shield-keyhole-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-22 17:29:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2401, 0, 2400, '操作日志列表', NULL, 'saimulti:tenant:oper:index', 3, '', '', NULL, '', 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, '', '2025-06-09 15:05:13', '2025-06-09 15:05:13', NULL);
INSERT INTO `sm_tenant_menu` VALUES (2402, 0, 2400, '操作日志删除', NULL, 'saimulti:tenant:oper:destroy', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3000, 0, 0, '数据管理', 'data', '', 1, 'data', '', NULL, 'ri:database-line', 100, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-24 16:07:34', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3001, 0, 3000, '文章管理', 'cms/article', '', 2, 'cms/article', '/data/cms/article/index', NULL, 'ri:article-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-24 16:07:14', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3002, 0, 3001, '文章管理列表', NULL, 'data:cms:article:index', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3003, 0, 3001, '文章管理保存', NULL, 'data:cms:article:save', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3004, 0, 3001, '文章管理更新', NULL, 'data:cms:article:update', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3005, 0, 3001, '文章管理读取', NULL, 'data:cms:article:read', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3007, 0, 3001, '文章管理删除', NULL, 'data:cms:article:destroy', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3011, 0, 3000, '文章轮播', 'cms/banner', '', 2, 'cms/banner', '/data/cms/banner/index', NULL, 'ri:folder-image-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-24 16:07:04', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3012, 0, 3011, '文章轮播列表', NULL, 'data:cms:banner:index', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3013, 0, 3011, '文章轮播保存', NULL, 'data:cms:banner:save', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3014, 0, 3011, '文章轮播更新', NULL, 'data:cms:banner:update', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3015, 0, 3011, '文章轮播读取', NULL, 'data:cms:banner:read', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3017, 0, 3011, '文章轮播删除', NULL, 'data:cms:banner:destroy', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3021, 0, 3000, '文章分类', 'cms/category', '', 2, 'cms/category', '/data/cms/category/index', NULL, 'ri:apps-2-line', 0, '', 2, 2, 2, 2, 2, 0, NULL, 1, '', '2024-08-08 08:08:08', '2026-04-24 16:06:51', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3022, 0, 3021, '文章分类列表', NULL, 'data:cms:category:index', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3023, 0, 3021, '文章分类保存', NULL, 'data:cms:category:save', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3024, 0, 3021, '文章分类更新', NULL, 'data:cms:category:update', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3025, 0, 3021, '文章分类读取', NULL, 'data:cms:category:read', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);
INSERT INTO `sm_tenant_menu` VALUES (3027, 0, 3021, '文章分类删除', NULL, 'data:cms:category:destroy', 3, NULL, NULL, NULL, NULL, 0, NULL, 2, 2, 2, 2, 2, 0, NULL, 1, NULL, '2024-08-08 08:08:08', '2024-08-08 08:08:08', NULL);

-- ----------------------------
-- Table structure for sm_tenant_notice
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_notice`;
CREATE TABLE `sm_tenant_notice`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NULL DEFAULT 1 COMMENT '机构编号',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '标题',
  `type` smallint(6) NULL DEFAULT NULL COMMENT '公告类型(1通知 2公告)',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '公告内容',
  `click_num` int(11) NULL DEFAULT 0 COMMENT '浏览次数',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '系统公告表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_notice
-- ----------------------------

-- ----------------------------
-- Table structure for sm_tenant_post
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_post`;
CREATE TABLE `sm_tenant_post`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NULL DEFAULT 1 COMMENT '机构编号',
  `parent_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '父ID',
  `level` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '组级集合',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '岗位名称',
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '岗位代码',
  `sort` smallint(5) UNSIGNED NULL DEFAULT 0 COMMENT '排序',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '岗位信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_post
-- ----------------------------
INSERT INTO `sm_tenant_post` VALUES (1, 1, NULL, NULL, '司机', 'driver', 100, 1, '老司机', '2026-04-22 15:28:43', '2026-04-22 15:30:49', NULL);

-- ----------------------------
-- Table structure for sm_tenant_role
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_role`;
CREATE TABLE `sm_tenant_role`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NULL DEFAULT 1 COMMENT '机构编号',
  `name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '角色名称',
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '角色代码',
  `level` int(11) NULL DEFAULT NULL COMMENT '角色职权',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `sort` smallint(5) UNSIGNED NULL DEFAULT 0 COMMENT '排序',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '角色信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_role
-- ----------------------------
INSERT INTO `sm_tenant_role` VALUES (1, 1, '超级管理员', 'superAdmin', 100, 1, 1, '系统内置角色，不可删除', '2026-04-17 16:35:30', '2026-04-17 16:35:30', NULL);
INSERT INTO `sm_tenant_role` VALUES (2, 1, '管理员', 'admin', 99, 1, 100, '', '2026-04-22 16:44:02', '2026-04-22 16:45:41', NULL);

-- ----------------------------
-- Table structure for sm_tenant_role_menu
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_role_menu`;
CREATE TABLE `sm_tenant_role_menu`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `role_id` int(11) UNSIGNED NOT NULL COMMENT '角色主键',
  `menu_id` int(11) UNSIGNED NOT NULL COMMENT '菜单主键',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_role_id`(`role_id`) USING BTREE,
  INDEX `idx_menu_id`(`menu_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '角色与菜单关联表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_role_menu
-- ----------------------------

-- ----------------------------
-- Table structure for sm_tenant_user
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_user`;
CREATE TABLE `sm_tenant_user`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `organization` int(11) UNSIGNED NULL DEFAULT 1 COMMENT '机构编号',
  `username` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '用户名',
  `password` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '密码',
  `user_type` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '200' COMMENT '用户类型:(100系统用户,200常规用户)',
  `nickname` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '用户昵称',
  `gender` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '性别',
  `phone` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '手机',
  `email` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '用户邮箱',
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '用户头像',
  `signed` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '个人签名',
  `dashboard` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '后台首页类型',
  `dept_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '部门ID',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `login_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '最后登陆IP',
  `login_time` datetime NULL DEFAULT NULL COMMENT '最后登陆时间',
  `backend_setting` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '后台设置数据',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uni_user`(`organization`, `username`) USING BTREE,
  INDEX `idx_dept`(`dept_id`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '用户信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_user
-- ----------------------------
INSERT INTO `sm_tenant_user` VALUES (1, 1, 'admin', '$2y$10$O2pTdk7777rOgc0pZIi7huiOrhkXudrKOr9WlxoPtyRn/FPl6Ka4i', '100', 'xiaobai', NULL, '15888888888', 'xiaobai@gmail.com', 'http://127.0.0.1:8888/storage/20260414/7971881d7e10a122e0f51ea188571dbe29d82229.jpg', NULL, 'statistics', NULL, 1, '127.0.0.1', '2026-04-25 23:59:59', NULL, '', '2026-04-17 16:35:30', '2026-04-25 23:59:59', NULL);

-- ----------------------------
-- Table structure for sm_tenant_user_post
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_user_post`;
CREATE TABLE `sm_tenant_user_post`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(11) UNSIGNED NOT NULL COMMENT '用户主键',
  `post_id` int(11) UNSIGNED NOT NULL COMMENT '岗位主键',
  PRIMARY KEY (`id`, `user_id`) USING BTREE,
  INDEX `idx_user_id`(`user_id`) USING BTREE,
  INDEX `idx_post_id`(`post_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '用户与岗位关联表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_user_post
-- ----------------------------

-- ----------------------------
-- Table structure for sm_tenant_user_role
-- ----------------------------
DROP TABLE IF EXISTS `sm_tenant_user_role`;
CREATE TABLE `sm_tenant_user_role`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `user_id` int(11) UNSIGNED NOT NULL COMMENT '用户主键',
  `role_id` int(11) UNSIGNED NOT NULL COMMENT '角色主键',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_user_id`(`user_id`) USING BTREE,
  INDEX `idx_role_id`(`role_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '用户与角色关联表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tenant_user_role
-- ----------------------------
INSERT INTO `sm_tenant_user_role` VALUES (1, 1, 1);

-- ----------------------------
-- Table structure for sm_tool_crontab
-- ----------------------------
DROP TABLE IF EXISTS `sm_tool_crontab`;
CREATE TABLE `sm_tool_crontab`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务名称',
  `type` smallint(6) NULL DEFAULT 4 COMMENT '任务类型 (1 command, 2 class, 3 url, 4 eval)',
  `target` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '调用任务字符串',
  `parameter` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '调用任务参数',
  `task_style` tinyint(1) UNSIGNED NULL DEFAULT 1 COMMENT '执行类型',
  `rule` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务执行表达式',
  `singleton` smallint(6) NULL DEFAULT 1 COMMENT '是否单次执行 (1 是 2 不是)',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '状态 (1正常 2停用)',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时任务信息表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tool_crontab
-- ----------------------------
INSERT INTO `sm_tool_crontab` VALUES (1, '访问官网', 1, 'https://saithink.top', '', 1, '0 15 3 * * *', 2, 1, '', 1, 1, '2024-01-20 14:21:11', '2025-06-08 11:52:47', NULL);
INSERT INTO `sm_tool_crontab` VALUES (2, '登录gitee', 2, 'https://gitee.com/check_user_login', '{\"user_login\": \"saiadmin\"}', 1, '0 0 10 * * *', 2, 1, NULL, 1, 1, '2024-01-20 14:31:51', '2024-01-20 15:21:33', NULL);
INSERT INTO `sm_tool_crontab` VALUES (3, '定时执行任务', 3, '\\plugin\\saimulti\\process\\Task', '{\"type\":\"1\"}', 1, '0 0 12 * * *', 2, 1, '', 1, 1, '2024-01-20 14:38:03', '2024-08-10 11:20:43', NULL);

-- ----------------------------
-- Table structure for sm_tool_crontab_log
-- ----------------------------
DROP TABLE IF EXISTS `sm_tool_crontab_log`;
CREATE TABLE `sm_tool_crontab_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `crontab_id` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '任务ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务名称',
  `target` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务调用目标字符串',
  `parameter` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务调用参数',
  `exception_info` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '异常信息',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '执行状态 (1成功 2失败)',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时任务执行日志表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tool_crontab_log
-- ----------------------------
INSERT INTO `sm_tool_crontab_log` VALUES (1, 2, '登录gitee', 'https://gitee.com/check_user_login', '{\"user_login\": \"saiadmin\"}', '{\"result\":1,\"failed_count\":1}', 1, '2026-04-16 10:00:03', '2026-04-16 10:00:03', NULL);
INSERT INTO `sm_tool_crontab_log` VALUES (2, 1, '访问官网', 'https://saithink.top', '', NULL, 1, '2026-04-17 23:47:55', '2026-04-17 23:49:40', '2026-04-17 23:49:40');
INSERT INTO `sm_tool_crontab_log` VALUES (3, 3, '定时执行任务', '\\plugin\\saimulti\\process\\Task', '{\"type\":\"1\"}', '类:\\plugin\\saimulti\\process\\Task,方法:run,未找到', 2, '2026-04-21 12:00:00', '2026-04-21 12:00:00', NULL);
INSERT INTO `sm_tool_crontab_log` VALUES (4, 2, '登录gitee', 'https://gitee.com/check_user_login', '{\"user_login\": \"saiadmin\"}', '{\"result\":1,\"failed_count\":1}', 1, '2026-04-22 10:00:02', '2026-04-22 10:00:02', NULL);
INSERT INTO `sm_tool_crontab_log` VALUES (5, 3, '定时执行任务', '\\plugin\\saimulti\\process\\Task', '{\"type\":\"1\"}', '类:\\plugin\\saimulti\\process\\Task,方法:run,未找到', 2, '2026-04-22 12:00:01', '2026-04-22 12:00:01', NULL);

-- ----------------------------
-- Table structure for sm_tool_login_log
-- ----------------------------
DROP TABLE IF EXISTS `sm_tool_login_log`;
CREATE TABLE `sm_tool_login_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NULL DEFAULT 1 COMMENT '机构编号',
  `username` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '用户名',
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '登录IP地址',
  `ip_location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'IP所属地',
  `os` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '操作系统',
  `browser` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '浏览器',
  `status` smallint(6) NULL DEFAULT 1 COMMENT '登录状态 (1成功 2失败)',
  `message` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '提示消息',
  `login_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '登录时间',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '更新时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `username`(`username`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '登录日志表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tool_login_log
-- ----------------------------

-- ----------------------------
-- Table structure for sm_tool_mail
-- ----------------------------
DROP TABLE IF EXISTS `sm_tool_mail`;
CREATE TABLE `sm_tool_mail`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '编号',
  `gateway` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '网关',
  `from` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '发送人',
  `email` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '接收人',
  `code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '验证码',
  `content` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '邮箱内容',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '发送状态',
  `response` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '返回结果',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '邮件记录' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tool_mail
-- ----------------------------

-- ----------------------------
-- Table structure for sm_tool_oper_log
-- ----------------------------
DROP TABLE IF EXISTS `sm_tool_oper_log`;
CREATE TABLE `sm_tool_oper_log`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `organization` int(11) UNSIGNED NULL DEFAULT 1 COMMENT '机构编号',
  `username` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '用户名',
  `app` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '应用名称',
  `method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '请求方式',
  `router` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '请求路由',
  `service_name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '业务名称',
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '请求IP地址',
  `ip_location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'IP所属地',
  `request_data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '请求数据',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '备注',
  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者',
  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '更新时间',
  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `username`(`username`) USING BTREE,
  INDEX `idx_organization`(`organization`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '操作日志表' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of sm_tool_oper_log
-- ----------------------------

SET FOREIGN_KEY_CHECKS = 1;
