<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | AI 一键 CRUD 撤销命令
// +----------------------------------------------------------------------
namespace app\command;

use app\command\support\Manifest;
use app\command\support\RouteWriter;
use app\command\support\SchemaBuilder;
use plugin\saimulti\app\model\admin\Menu as AdminMenu;
use plugin\saimulti\app\model\tenant\Menu as TenantMenu;
use plugin\saimulti\app\model\tool\Column;
use plugin\saimulti\app\model\tool\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * 撤销 ai-crud:make 的产物
 *
 * 用法：
 *   php webman ai-crud:rollback --table=sm_tenant_goods
 *   php webman ai-crud:rollback --manifest=runtime/ai-crud/manifest/xxx.json
 *   php webman ai-crud:rollback --table=sm_tenant_goods --drop-table   # 同时删数据表（危险）
 *   php webman ai-crud:rollback --table=sm_tenant_goods --force        # 跳过确认
 *
 * 逆转四类产物：生成的文件、菜单权限、路由行、登记表记录。
 * 数据表本身默认不删，需显式 --drop-table。
 */
#[AsCommand(name: 'ai-crud:rollback', description: '撤销 AI 一键 CRUD 的生成产物')]
class AiCrudRollbackCommand extends Command
{
    protected static $defaultName = 'ai-crud:rollback';
    protected static $defaultDescription = '撤销 AI 一键 CRUD 的生成产物';

    protected function configure(): void
    {
        $this->addOption('table', 't', InputOption::VALUE_REQUIRED, '表名（按最新清单撤销）');
        $this->addOption('manifest', 'm', InputOption::VALUE_REQUIRED, '指定清单文件路径');
        $this->addOption('drop-table', null, InputOption::VALUE_NONE, '同时删除数据表（危险，默认不删）');
        $this->addOption('keep-record', null, InputOption::VALUE_NONE, '保留 saimulti_table/column 登记记录');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, '跳过确认直接执行');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manifest = $this->loadManifest($input, $output);
        if ($manifest === null) {
            return Command::FAILURE;
        }

        $dropTable = (bool) $input->getOption('drop-table');
        $keepRecord = (bool) $input->getOption('keep-record');
        $force = (bool) $input->getOption('force');

        if ($dropTable && empty($manifest['created_table'])) {
            $output->writeln('<error>该清单未标记数据表由 ai-crud 创建，拒绝 --drop-table</error>');
            return Command::FAILURE;
        }

        $this->preview($manifest, $dropTable, $output);

        if (!$force && !$this->confirm($input, $output, '确认撤销以上产物？')) {
            $output->writeln('已取消。');
            return Command::SUCCESS;
        }
        if ($dropTable && !$force && !$this->confirm($input, $output, "⚠ 将永久删除数据表 {$manifest['table_name']} 及其所有数据，确认？")) {
            $output->writeln('已取消删表（其余产物未处理，可去掉 --drop-table 重试）。');
            return Command::SUCCESS;
        }

        try {
            $this->removeFiles($manifest, $output);
            $this->removeMenus($manifest, $output);
            $this->removeRoute($manifest, $output);
            $this->removeRecords($manifest, $keepRecord, $output);
            if ($dropTable) {
                (new SchemaBuilder())->drop($manifest['table_name'], $manifest['source'] ?? '');
                $output->writeln("<info>· 已删除数据表：{$manifest['table_name']}</info>");
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>撤销出错：' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (!empty($manifest['__path'])) {
            Manifest::remove($manifest['__path']);
        }
        $output->writeln('<info>✓ 撤销完成。</info>');
        return Command::SUCCESS;
    }

    /**
     * 加载清单：优先 --manifest，否则按 --table 取最新；都没有时尝试从登记表重建
     */
    protected function loadManifest(InputInterface $input, OutputInterface $output): ?array
    {
        $manifestPath = $input->getOption('manifest');
        $table = $input->getOption('table');

        if ($manifestPath) {
            $m = Manifest::read($manifestPath);
            if (!$m) {
                $output->writeln("<error>清单读取失败：{$manifestPath}</error>");
                return null;
            }
            return $m;
        }

        if (!$table) {
            $output->writeln('<error>请提供 --table 或 --manifest</error>');
            return null;
        }

        $m = Manifest::latestByTable($table);
        if (!$m) {
            $output->writeln("<error>未找到 {$table} 的 owned 清单，拒绝猜测并删除产物</error>");
            return null;
        }
        return $m;
    }

    protected function preview(array $m, bool $dropTable, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<info>=== 撤销预览 ===</info>');
        $output->writeln("  表名      : {$m['table_name']}");
        $files = !empty($m['files']) ? $m['files'] : AiCrudCommand::resolveGeneratedFiles($this->tableArr($m));
        $output->writeln('  待删文件  : ' . count($files) . ' 个');
        foreach ($files as $f) {
            $output->writeln('    - ' . $this->shortPath($f));
        }
        if (!empty($m['route_prefix']) || !empty($m['route_line'])) {
            $output->writeln('  待删路由  : ' . ($m['route_prefix'] ?: $m['route_line']));
        }
        $output->writeln('  待删菜单  : saimulti_table#' . ($m['table_id'] ?? '?') . ' 关联的菜单与按钮权限');
        $output->writeln('  登记记录  : saimulti_table / saimulti_column');
        $output->writeln('  数据表    : ' . ($dropTable ? '⚠ 将删除' : '保留（如需删除加 --drop-table）'));
        $output->writeln('');
    }

    /**
     * 删除生成的文件
     *
     * 只删本次生成的 9 个文件；目录清理仅限本模块"独占"的前端业务目录
     * （views/<namespace>[/<package>]/<business>/ 及其 modules 子目录）。
     * controller/logic/model/validate、前端 api/ 等目录可能被同 namespace 下
     * 其他模块共享，一律不动——空目录留着无害，误删共享目录才是真风险。
     */
    protected function removeFiles(array $m, OutputInterface $output): void
    {
        $expected = AiCrudCommand::resolveExpectedGeneratedFiles($this->tableArr($m));
        $files = $m['files'] ?? [];
        $allowed = array_fill_keys(array_map([$this, 'normalizePath'], $expected), true);
        $count = 0;
        foreach ($files as $f) {
            if (!is_string($f) || !isset($allowed[$this->normalizePath($f)])) {
                throw new \RuntimeException('清单包含非本模块生成路径，拒绝删除：' . (is_scalar($f) ? $f : gettype($f)));
            }
            if (is_file($f)) {
                if (!unlink($f)) {
                    throw new \RuntimeException("生成文件删除失败：{$f}");
                }
                $count++;
            }
        }
        // 仅清理本模块独占的业务目录（带 business_name，不会与其他模块共享）
        $this->cleanupBusinessDir($m);
        $output->writeln("<info>· 已删除文件 {$count} 个</info>");
    }

    /**
     * 清理前端业务目录：views/<namespace>[/<package>]/<business>/{modules,自身}
     * 只在确实为空时删除，绝不向上波及 namespace/package/api 等共享目录。
     */
    protected function cleanupBusinessDir(array $m): void
    {
        $business = $m['business_name'] ?? '';
        if ($business === '') {
            return;
        }
        $generatePath = AiCrudCommand::frontendPath((string) ($m['stub'] ?? 'tenant'));
        $namespace = $m['namespace'] ?? '';
        $sub = !empty($m['package_name']) ? '/' . $m['package_name'] : '';
        $viewBase = dirname(base_path()) . "/{$generatePath}/src/views/{$namespace}{$sub}/{$business}";

        // 先删 modules 子目录（空才删），再删业务目录本身（空才删）
        foreach (["{$viewBase}/modules", $viewBase] as $dir) {
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                @rmdir($dir);
            }
        }
    }

    /**
     * 删除菜单与按钮权限（按 generate_id 精确定位）
     */
    protected function removeMenus(array $m, OutputInterface $output): void
    {
        if (empty($m['menus_created'])) {
            $output->writeln('<comment>· 清单未拥有菜单，跳过</comment>');
            return;
        }
        $tableId = $m['table_id'] ?? null;
        if (empty($tableId)) {
            $output->writeln('<comment>· 无 table_id，跳过菜单删除</comment>');
            return;
        }
        $menuModel = $m['stub'] === 'admin' ? new AdminMenu() : new TenantMenu();

        $parent = $menuModel->where('generate_id', $tableId)->findOrEmpty();
        if ($parent->isEmpty()) {
            $output->writeln('<comment>· 未找到关联菜单，跳过</comment>');
            return;
        }
        $parentId = $parent['id'];
        // 物理删除（destroy 第二参 force=true），避免软删残留导致重建时 generate_id 冲突
        $childIds = $menuModel->where('parent_id', $parentId)->column('id');
        $childCount = count($childIds);
        if ($childCount > 0) {
            $menuClass = get_class($menuModel);
            $menuClass::destroy($childIds, true);
        }
        $menuClass = get_class($menuModel);
        $menuClass::destroy($parentId, true);
        $output->writeln("<info>· 已删除菜单 1 个 + 按钮权限 {$childCount} 个</info>");
    }

    /**
     * 移除路由行
     */
    protected function removeRoute(array $m, OutputInterface $output): void
    {
        if (empty($m['route_created'])) {
            $output->writeln('<comment>· 清单未拥有路由，跳过</comment>');
            return;
        }
        $prefix = $m['route_prefix'] ?? '';
        if (empty($prefix) && !empty($m['route_line'])) {
            // 从路由行原文里抠出前缀
            if (preg_match("#saiMultiRoute\(\s*['\"]([^'\"]+)['\"]#", $m['route_line'], $mm)) {
                $prefix = $mm[1];
            }
        }
        if (empty($prefix)) {
            $output->writeln('<comment>· 无路由信息，跳过</comment>');
            return;
        }
        $result = (new RouteWriter())->remove($prefix);
        $output->writeln("<info>· 路由：{$result['message']}</info>");
    }

    /**
     * 删除登记表记录
     */
    protected function removeRecords(array $m, bool $keep, OutputInterface $output): void
    {
        if ($keep) {
            $output->writeln('<comment>· 保留登记记录（--keep-record）</comment>');
            return;
        }
        if (empty($m['registration_created'])) {
            $output->writeln('<comment>· 清单未拥有登记记录，跳过</comment>');
            return;
        }
        $tableId = $m['table_id'] ?? null;
        if (empty($tableId)) {
            return;
        }
        // 物理删除（destroy force=true），避免软删残留导致重新生成时重复
        $colIds = Column::where('table_id', $tableId)->column('id');
        if (!empty($colIds)) {
            Column::destroy($colIds, true);
        }
        Table::destroy($tableId, true);
        $output->writeln('<info>· 已删除登记记录（saimulti_table/column）</info>');
    }

    protected function tableArr(array $m): array
    {
        return [
            'namespace'     => $m['namespace'] ?? '',
            'package_name'  => $m['package_name'] ?? '',
            'class_name'    => $m['class_name'] ?? '',
            'business_name' => $m['business_name'] ?? '',
            'template'      => $m['template'] ?? 'app',
            'stub'          => $m['stub'] ?? 'tenant',
            'generate_path' => AiCrudCommand::frontendPath((string) ($m['stub'] ?? 'tenant')),
        ];
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return '/' . implode('/', $parts);
    }


    protected function confirm(InputInterface $input, OutputInterface $output, string $question): bool
    {
        $helper = $this->getHelper('question');
        $q = new ConfirmationQuestion($question . ' [y/N] ', false);
        return (bool) $helper->ask($input, $output, $q);
    }

    protected function shortPath(string $path): string
    {
        $root = dirname(base_path());
        return str_replace($root . '/', '', $path);
    }
}
