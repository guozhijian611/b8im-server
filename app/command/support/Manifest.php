<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | AI 一键 CRUD 工具 - 生成清单
// +----------------------------------------------------------------------
namespace app\command\support;

/**
 * 生成清单管理
 *
 * 每次 ai-crud:make 把生成的全部产物写入一个 JSON 清单文件，
 * ai-crud:rollback 据此精确反向删除，避免"猜路径"误删无关文件。
 */
class Manifest
{
    /**
     * 清单存放目录
     */
    public static function dir(): string
    {
        $dir = runtime_path() . DIRECTORY_SEPARATOR . 'ai-crud' . DIRECTORY_SEPARATOR . 'manifest';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * 写入一份清单，返回文件路径
     *
     * @param array $data 形如：
     *  [
     *    'table_name'   => 'sm_tenant_goods',
     *    'table_id'     => 12,            // saimulti_table 主键
     *    'stub'         => 'tenant',      // tenant | admin
     *    'namespace'    => 'saimulti',
     *    'package_name' => '',
     *    'business_name'=> 'goods',
     *    'class_name'   => 'Goods',
     *    'files'        => ['/abs/path/Controller.php', ...],
     *    'route_line'   => "saiMultiRoute('/tenant/goods', \\plugin\\...::class);",
     *    'created_table'=> true,          // 数据表是否由本工具创建
     *  ]
     */
    public static function write(array $data): string
    {
        self::assertTableName((string) ($data['table_name'] ?? ''));
        $data['created_at'] = date('Y-m-d H:i:s');
        $name = ($data['table_name'] ?? 'unknown') . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.json';
        $path = self::dir() . DIRECTORY_SEPARATOR . $name;
        self::persist($path, $data);
        return $path;
    }

    public static function persist(string $path, array $data): void
    {
        self::assertTableName((string) ($data['table_name'] ?? ''));
        $directory = realpath(self::dir());
        $targetDirectory = realpath(dirname($path));
        if ($directory === false || $targetDirectory !== $directory) {
            throw new \RuntimeException('清单路径不在受控目录内');
        }
        $temporary = $path . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('清单持久化失败');
        }
    }

    /**
     * 按表名查找最新的一份清单
     */
    public static function latestByTable(string $tableName): ?array
    {
        self::assertTableName($tableName);
        $files = glob(self::dir() . DIRECTORY_SEPARATOR . $tableName . '_*.json');
        if (empty($files)) {
            return null;
        }
        rsort($files);
        foreach ($files as $path) {
            $manifest = self::read($path);
            if (!$manifest) {
                continue;
            }
            if (!empty($manifest['created_table'])
                || !empty($manifest['registration_created'])
                || !empty($manifest['menus_created'])
                || !empty($manifest['route_created'])
                || !empty($manifest['files'])) {
                return $manifest;
            }
        }
        return null;
    }

    /**
     * 读取指定清单文件
     */
    public static function read(string $path): ?array
    {
        $realDir = realpath(self::dir());
        $realPath = realpath($path);
        if ($realDir === false || $realPath === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $json = json_decode(file_get_contents($realPath), true);
        if (!is_array($json)) {
            return null;
        }
        try {
            self::assertTableName((string) ($json['table_name'] ?? ''));
        } catch (\InvalidArgumentException) {
            return null;
        }
        $json['__path'] = $realPath;
        return $json;
    }

    /**
     * 删除清单文件本身（撤销完成后调用）
     */
    public static function remove(string $path): void
    {
        $realDir = realpath(self::dir());
        $realPath = realpath($path);
        if ($realDir !== false && $realPath !== false && str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
            unlink($realPath);
        }
    }

    private static function assertTableName(string $tableName): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $tableName)) {
            throw new \InvalidArgumentException('清单表名不合法');
        }
    }
}
