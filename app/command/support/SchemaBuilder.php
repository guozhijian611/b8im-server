<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | AI 一键 CRUD 工具 - 建表器
// +----------------------------------------------------------------------
namespace app\command\support;

use think\facade\Db;

/**
 * 数据表构建器
 *
 * 根据字段定义拼出带中文注释的 CREATE TABLE 语句，并按端（tenant/admin）
 * 自动补齐标准字段（机构隔离、审计、时间戳）。建表用 IF NOT EXISTS，不碰已有数据。
 */
class SchemaBuilder
{
    /**
     * 用户字段需要的 MySQL 类型映射（简化）
     */
    protected array $typeMap = [
        'int'      => 'int(11)',
        'bigint'   => 'bigint(20)',
        'tinyint'  => 'tinyint(4)',
        'smallint' => 'smallint(6)',
        'string'   => 'varchar(255)',
        'varchar'  => 'varchar(255)',
        'char'     => 'char(50)',
        'text'     => 'text',
        'longtext' => 'longtext',
        'decimal'  => 'decimal(10,2)',
        'float'    => 'float',
        'double'   => 'double',
        'date'     => 'date',
        'datetime' => 'datetime',
        'json'     => 'json',
    ];

    /**
     * 构建 CREATE TABLE 语句
     *
     * @param string $table   表名（含前缀，如 sm_tenant_goods）
     * @param string $comment 表注释
     * @param array  $columns 业务字段定义，每项：
     *   ['name'=>'title','type'=>'varchar','length'=>120,'comment'=>'标题','null'=>true,'default'=>null]
     * @param string $stub    tenant | admin
     */
    public function buildSql(string $table, string $comment, array $columns, string $stub): string
    {
        $this->assertIdentifier($table, '表名');
        if (!in_array($stub, ['tenant', 'admin'], true)) {
            throw new \InvalidArgumentException('stub 必须为 tenant 或 admin');
        }
        $defs = [];
        // 主键
        $defs[] = "  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键'";

        // 租户表加机构字段
        if ($stub === 'tenant') {
            $defs[] = "  `organization` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '机构编号'";
        }

        // 业务字段
        foreach ($columns as $col) {
            $defs[] = '  ' . $this->buildColumnDef($col);
        }

        // 审计字段 + 时间戳（与框架基类模型一致）
        $defs[] = "  `created_by` int(11) NULL DEFAULT NULL COMMENT '创建者'";
        $defs[] = "  `updated_by` int(11) NULL DEFAULT NULL COMMENT '更新者'";
        $defs[] = "  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间'";
        $defs[] = "  `update_time` datetime NULL DEFAULT NULL COMMENT '修改时间'";
        $defs[] = "  `delete_time` datetime NULL DEFAULT NULL COMMENT '删除时间'";

        // 主键与索引
        $defs[] = "  PRIMARY KEY (`id`) USING BTREE";
        if ($stub === 'tenant') {
            $defs[] = "  INDEX `idx_organization`(`organization`) USING BTREE";
        }

        $body = implode(",\n", $defs);
        $comment = addslashes($comment);

        return "CREATE TABLE IF NOT EXISTS `{$table}` (\n{$body}\n) "
            . "ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci "
            . "COMMENT = '{$comment}' ROW_FORMAT = DYNAMIC;";
    }

    /**
     * 构建单个业务字段定义
     */
    protected function buildColumnDef(array $col): string
    {
        $name = $col['name'];
        $this->assertIdentifier((string) $name, '字段名');
        $type = $this->resolveType($col);
        $nullable = ($col['null'] ?? true) ? 'NULL' : 'NOT NULL';

        $default = '';
        if (array_key_exists('default', $col) && $col['default'] !== null) {
            $val = $col['default'];
            $default = is_numeric($val) ? " DEFAULT {$val}" : " DEFAULT '" . addslashes((string) $val) . "'";
        } elseif (($col['null'] ?? true)) {
            $default = ' DEFAULT NULL';
        }

        $comment = addslashes($col['comment'] ?? $name);
        $charset = $this->needsCharset($col) ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci' : '';

        return "`{$name}` {$type}{$charset} {$nullable}{$default} COMMENT '{$comment}'";
    }

    /**
     * 解析字段类型（支持 length 覆盖）
     */
    protected function resolveType(array $col): string
    {
        $type = strtolower($col['type'] ?? 'varchar');
        if (in_array($type, ['varchar', 'string', 'char']) && !empty($col['length'])) {
            if (!preg_match('/^[1-9][0-9]{0,4}$/', (string) $col['length'])) {
                throw new \InvalidArgumentException('字符串字段 length 必须是正整数');
            }
            $base = $type === 'char' ? 'char' : 'varchar';
            return "{$base}({$col['length']})";
        }
        if ($type === 'decimal' && !empty($col['length'])) {
            if (!preg_match('/^[1-9][0-9]?,[0-9]$/', (string) $col['length'])) {
                throw new \InvalidArgumentException('decimal length 必须使用 precision,scale 格式');
            }
            return "decimal({$col['length']})";
        }
        if (!isset($this->typeMap[$type])) {
            throw new \InvalidArgumentException("不支持的字段类型：{$type}");
        }
        return $this->typeMap[$type];
    }

    /**
     * 是否需要字符集声明（字符串类字段才需要）
     */
    protected function needsCharset(array $col): bool
    {
        $type = strtolower($col['type'] ?? 'varchar');
        return in_array($type, ['varchar', 'string', 'char', 'text', 'longtext', 'json']);
    }

    /**
     * 表是否已存在
     */
    public function tableExists(string $table, string $source = ''): bool
    {
        $this->assertIdentifier($table, '表名');
        $conn = empty($source) ? Db::connect() : Db::connect($source);
        $rows = $conn->query("SHOW TABLES LIKE '" . addslashes($table) . "'");
        return !empty($rows);
    }

    /**
     * 执行建表
     */
    public function create(string $sql, string $source = ''): void
    {
        $conn = empty($source) ? Db::connect() : Db::connect($source);
        $conn->execute($sql);
    }

    /**
     * 删除数据表（撤销时显式调用，慎用）
     */
    public function drop(string $table, string $source = ''): void
    {
        $this->assertIdentifier($table, '表名');
        $conn = empty($source) ? Db::connect() : Db::connect($source);
        $conn->execute("DROP TABLE IF EXISTS `{$table}`");
    }

    private function assertIdentifier(string $identifier, string $label): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $identifier)) {
            throw new \InvalidArgumentException("{$label}不合法：{$identifier}");
        }
    }
}
