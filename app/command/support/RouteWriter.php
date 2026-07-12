<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | AI 一键 CRUD 工具 - 路由读写
// +----------------------------------------------------------------------
namespace app\command\support;

/**
 * 路由文件读写
 *
 * 负责把 saiMultiRoute(...) 行插入到 config/route.php 对应的中间件分组内，
 * 以及撤销时移除它。写入前自动备份，找不到锚点时降级为返回提示，绝不破坏文件。
 */
class RouteWriter
{
    /**
     * route.php 路径
     */
    protected string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: (base_path() . '/plugin/saimulti/config/route.php');
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /**
     * 生成一行标准 saiMultiRoute 路由
     *
     * @param string $prefix     如 /tenant/goods
     * @param string $controller 完整类名（不带前导反斜杠）
     */
    public static function buildLine(string $prefix, string $controller): string
    {
        if (!preg_match('#^/[a-z][a-zA-Z0-9_/-]*$#', $prefix) || str_contains($prefix, '//') || str_contains($prefix, '..')) {
            throw new \InvalidArgumentException("路由前缀不合法：{$prefix}");
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $controller)) {
            throw new \InvalidArgumentException("控制器类名不合法：{$controller}");
        }
        $controller = '\\' . ltrim($controller, '\\');
        return "\tsaiMultiRoute('{$prefix}', {$controller}::class);";
    }

    /**
     * 该路由是否已存在（幂等判断）
     */
    public function has(string $prefix): bool
    {
        if (!$this->exists()) {
            return false;
        }
        $content = file_get_contents($this->file);
        // 匹配 saiMultiRoute('/tenant/goods'  ... 不论控制器写法
        return (bool) preg_match("#saiMultiRoute\(\s*['\"]" . preg_quote($prefix, '#') . "['\"]#", $content);
    }

    /**
     * 在指定端的中间件分组内插入路由行
     *
     * @param string $stub  tenant | admin
     * @return array{ok:bool, message:string, line:string}
     */
    public function insert(string $prefix, string $controller, string $stub): array
    {
        if (!in_array($stub, ['tenant', 'admin'], true)) {
            throw new \InvalidArgumentException('stub 必须为 tenant 或 admin');
        }
        $line = self::buildLine($prefix, $controller);

        if (!$this->exists()) {
            return ['ok' => false, 'message' => 'route.php 不存在，请手动添加：' . trim($line), 'line' => $line];
        }
        if ($this->has($prefix)) {
            return ['ok' => true, 'message' => '路由已存在，跳过', 'line' => $line];
        }

        $content = file_get_contents($this->file);
        $anchorMiddleware = $stub === 'admin' ? 'CheckAdminLogin' : 'CheckTenantLogin';

        // 定位中间件分组闭合处：})->middleware([\n ... CheckXxxLogin
        // 思路：找到包含 CheckXxxLogin 的 middleware 块，在它前面的 "})->middleware([" 处倒推，
        // 把路由行插到该分组结束的 "})" 之前。
        $lines = explode("\n", $content);
        $insertAt = $this->findGroupEndLine($lines, $anchorMiddleware);

        if ($insertAt === null) {
            return [
                'ok' => false,
                'message' => "未能在 route.php 中定位 {$stub} 中间件分组，请手动添加：" . trim($line),
                'line' => $line,
            ];
        }

        $this->backup();
        array_splice($lines, $insertAt, 0, [$line]);
        file_put_contents($this->file, implode("\n", $lines));

        return ['ok' => true, 'message' => '路由已写入', 'line' => $line];
    }

    /**
     * 移除指定前缀的路由行（撤销用）
     *
     * @return array{ok:bool, message:string}
     */
    public function remove(string $prefix): array
    {
        if (!$this->exists()) {
            return ['ok' => false, 'message' => 'route.php 不存在'];
        }
        if (!$this->has($prefix)) {
            return ['ok' => true, 'message' => '路由不存在，跳过'];
        }

        $content = file_get_contents($this->file);
        $lines = explode("\n", $content);
        $kept = [];
        $removed = 0;
        foreach ($lines as $l) {
            if (preg_match("#saiMultiRoute\(\s*['\"]" . preg_quote($prefix, '#') . "['\"]#", $l)) {
                $removed++;
                continue;
            }
            $kept[] = $l;
        }

        if ($removed > 0) {
            $this->backup();
            file_put_contents($this->file, implode("\n", $kept));
        }
        return ['ok' => true, 'message' => "已移除 {$removed} 行路由"];
    }

    /**
     * 找到包含目标中间件的分组的结束行号（即 "})->middleware([" 那一行的下标）
     * 返回应插入的行下标（在该结束行之前插入）
     */
    protected function findGroupEndLine(array $lines, string $anchorMiddleware): ?int
    {
        // 先找到 middleware 块里出现 anchorMiddleware 的行
        $mwLine = null;
        foreach ($lines as $i => $l) {
            if (str_contains($l, $anchorMiddleware . '::class')) {
                $mwLine = $i;
                break;
            }
        }
        if ($mwLine === null) {
            return null;
        }
        // 从该行向上回溯，找到 "})->middleware([" —— 分组体结束、中间件声明开始处
        for ($i = $mwLine; $i >= 0; $i--) {
            if (str_contains($lines[$i], '})->middleware(')) {
                // 路由行插到这一行之前
                return $i;
            }
        }
        return null;
    }

    /**
     * 备份 route.php
     */
    public function backup(): void
    {
        if ($this->exists()) {
            $directory = runtime_path() . '/ai-crud/route-backups';
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException('无法创建路由备份目录');
            }
            $name = basename($this->file) . '.' . date('YmdHis') . '.' . bin2hex(random_bytes(4)) . '.bak';
            if (!copy($this->file, $directory . '/' . $name)) {
                throw new \RuntimeException('路由文件备份失败');
            }
        }
    }
}
