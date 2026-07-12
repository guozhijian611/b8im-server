<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | AI 一键 CRUD 生成命令
// +----------------------------------------------------------------------
namespace app\command;

use app\command\support\Manifest;
use app\command\support\RouteWriter;
use app\command\support\SchemaBuilder;
use plugin\saimulti\app\logic\tool\ColumnLogic;
use plugin\saimulti\app\logic\tool\DbLogic;
use plugin\saimulti\app\logic\tool\TableLogic;
use plugin\saimulti\app\model\tool\Table;
use plugin\saimulti\app\model\tool\Column;
use plugin\saimulti\app\model\admin\Menu as AdminMenu;
use plugin\saimulti\app\model\tenant\Menu as TenantMenu;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * AI 一键 CRUD：建表 → 装载配置 → 生成代码/菜单 → 写路由
 *
 * 用法：
 *   php webman ai-crud:make --schema=goods.json
 *   php webman ai-crud:make --schema=goods.json --dry-run
 *   php webman ai-crud:make --schema=goods.json --force
 *
 * schema JSON 由 saimulti-codegen 技能驱动 AI 产出，结构见技能文档。
 */
#[AsCommand(name: 'ai-crud:make', description: 'AI 一键生成 Saimulti CRUD（建表/装载/生成/路由）')]
class AiCrudCommand extends Command
{
    public const FRONTEND_PATHS = [
        'admin' => 'b8im-admin-vue',
        'tenant' => 'b8im-tenant-vue',
    ];

    protected static $defaultName = 'ai-crud:make';
    protected static $defaultDescription = 'AI 一键生成 Saimulti CRUD（建表/装载/生成/路由）';

    protected function configure(): void
    {
        $this->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'schema JSON 文件路径');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, '仅预览，不实际执行');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, '跳过确认直接执行');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schemaPath = $input->getOption('schema');
        if (!$schemaPath || !is_file($schemaPath)) {
            $output->writeln("<error>未找到 schema 文件：{$schemaPath}</error>");
            return Command::FAILURE;
        }
        $schema = json_decode(file_get_contents($schemaPath), true);
        if (!is_array($schema)) {
            $output->writeln('<error>schema 不是合法 JSON</error>');
            return Command::FAILURE;
        }

        $error = $this->validateSchema($schema);
        if ($error) {
            $output->writeln("<error>schema 校验失败：{$error}</error>");
            return Command::FAILURE;
        }

        $steps = ['table', 'load', 'generate', 'route'];
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        // 预览
        $this->preview($schema, $steps, $output);
        if ($dryRun) {
            $output->writeln('<info>[dry-run] 仅预览，未执行任何操作。</info>');
            return Command::SUCCESS;
        }
        if (!$force && !$this->confirm($input, $output, '确认执行以上操作？')) {
            $output->writeln('已取消。');
            return Command::SUCCESS;
        }

        [$actualRoutePrefix] = $this->routeParts($schema);
        $manifest = [
            'table_name'    => $schema['table'],
            'stub'          => $schema['stub'],
            'template'      => $schema['template'],
            'namespace'     => $schema['namespace'],
            'package_name'  => $schema['package_name'] ?? '',
            'business_name' => $schema['business_name'],
            'class_name'    => $schema['class_name'],
            'route_prefix'  => $actualRoutePrefix,
            'files'         => [],
            'route_line'    => '',
            'created_table' => false,
            'table_id'      => null,
            'source'        => $schema['source'] ?? '',
            'registration_created' => false,
            'menus_created' => false,
            'route_created' => false,
            'status' => 'pending',
        ];
        $manifestPath = Manifest::write($manifest);
        $preflightPassed = false;

        try {
            $this->assertNoConflicts($schema, $steps);
            $preflightPassed = true;
            // 1) 建表
            if (in_array('table', $steps)) {
                $created = $this->stepTable($schema, $output);
                $manifest['created_table'] = $created;
                Manifest::persist($manifestPath, $manifest);
            }

            // 2) 装载到 saimulti_table / saimulti_column 并应用 AI 配置
            if (in_array('load', $steps)) {
                $manifest['table_id'] = $this->stepLoad($schema, $output);
                $manifest['registration_created'] = true;
                Manifest::persist($manifestPath, $manifest);
            }

            // 3) 生成代码 + 菜单权限
            if (in_array('generate', $steps)) {
                if (empty($manifest['table_id'])) {
                    $manifest['table_id'] = $this->findTableId($schema);
                }
                $manifest['files'] = $this->stepGenerate($manifest['table_id'], $output);
                $manifest['menus_created'] = true;
                Manifest::persist($manifestPath, $manifest);
            }

            // 4) 写路由
            if (in_array('route', $steps)) {
                if (getenv('AI_CRUD_FAULT_AT') === 'route') {
                    throw new \RuntimeException('AI_CRUD_FAULT_AT=route');
                }
                $manifest['route_line'] = $this->stepRoute($schema, $output);
                $manifest['route_created'] = true;
                Manifest::persist($manifestPath, $manifest);
            }
        } catch (\Throwable $e) {
            if ($preflightPassed) {
                $manifest['files'] = array_values(array_filter(
                    self::resolveExpectedGeneratedFiles($this->tableShape($schema)),
                    'is_file',
                ));
                if (!empty($manifest['table_id'])) {
                    $menu = $schema['stub'] === 'admin' ? new AdminMenu() : new TenantMenu();
                    $manifest['menus_created'] = $menu->where('generate_id', $manifest['table_id'])->count() > 0;
                }
                [$routePrefix] = $this->routeParts($schema);
                $manifest['route_created'] = (new RouteWriter())->has($routePrefix);
            }
            try {
                $this->compensate($manifest);
                $manifest['status'] = 'compensated';
            } catch (\Throwable $compensationError) {
                $manifest['status'] = 'failed';
                $manifest['compensation_error'] = $compensationError->getMessage();
            }
            $manifest['error'] = $e->getMessage();
            Manifest::persist($manifestPath, $manifest);
            $output->writeln('<error>执行出错：' . $e->getMessage() . '</error>');
            if ($manifest['status'] === 'failed') {
                $output->writeln("<error>补偿失败，保留清单：{$manifestPath}</error>");
            }
            return Command::FAILURE;
        }

        $manifest['status'] = 'completed';
        Manifest::persist($manifestPath, $manifest);
        $output->writeln("<info>✓ 完成。清单已保存：{$manifestPath}</info>");
        $output->writeln("<comment>撤销：php webman ai-crud:rollback --table={$schema['table']}</comment>");
        return Command::SUCCESS;
    }

    // __HELPERS__
    /**
     * 校验 schema 必填项
     */
    protected function validateSchema(array $schema): string
    {
        foreach (['table', 'table_comment', 'stub', 'template', 'namespace', 'business_name', 'class_name', 'columns'] as $key) {
            if (!isset($schema[$key]) || $schema[$key] === '') {
                return "缺少字段：{$key}";
            }
        }
        if (!in_array($schema['stub'], ['tenant', 'admin'])) {
            return "stub 必须为 tenant 或 admin";
        }
        if (!in_array($schema['template'], ['app', 'plugin'])) {
            return "template 必须为 app 或 plugin";
        }
        if (!is_array($schema['columns']) || empty($schema['columns'])) {
            return "columns 不能为空";
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', (string) $schema['table'])) {
            return 'table 只能使用 2-64 位小写字母、数字和下划线，且必须以字母开头';
        }
        foreach (['namespace', 'business_name'] as $key) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', (string) $schema[$key])) {
                return "{$key} 只能使用小写字母、数字和下划线，且必须以字母开头";
            }
        }
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', (string) $schema['class_name'])) {
            return 'class_name 必须是大驼峰 PHP 类名';
        }
        if (!empty($schema['package_name']) && !preg_match('/^[a-z][a-z0-9_]*$/', (string) $schema['package_name'])) {
            return 'package_name 只能使用小写字母、数字和下划线，且必须以字母开头';
        }
        $reserved = ['id', 'organization', 'created_by', 'updated_by', 'create_time', 'update_time', 'delete_time'];
        $names = [];
        foreach ($schema['columns'] as $index => $column) {
            if (!is_array($column)) {
                return "columns[{$index}] 必须是对象";
            }
            foreach (['name', 'type', 'comment'] as $key) {
                if (!isset($column[$key]) || $column[$key] === '') {
                    return "columns[{$index}] 缺少字段：{$key}";
                }
            }
            $name = (string) $column['name'];
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                return "字段名 {$name} 不合法";
            }
            if (in_array($name, $reserved, true)) {
                return "字段 {$name} 由生成器维护，不能在 columns 中声明";
            }
            if (isset($names[$name])) {
                return "字段 {$name} 重复";
            }
            $names[$name] = true;
        }
        return '';
    }

    public static function frontendPath(string $stub): string
    {
        if (!isset(self::FRONTEND_PATHS[$stub])) {
            throw new \InvalidArgumentException("不支持的生成端：{$stub}");
        }
        return self::FRONTEND_PATHS[$stub];
    }

    protected function assertNoConflicts(array $schema, array $steps): void
    {
        $builder = new SchemaBuilder();
        if ($builder->tableExists($schema['table'], $schema['source'] ?? '')) {
            throw new \RuntimeException("目标表已存在，拒绝生成：{$schema['table']}");
        }
        if ((new Table())->where('table_name', $schema['table'])->count() > 0) {
            throw new \RuntimeException("目标登记已存在，拒绝生成：{$schema['table']}");
        }
        foreach (self::resolveExpectedGeneratedFiles($this->tableShape($schema)) as $file) {
            if (is_file($file)) {
                throw new \RuntimeException("目标文件已存在，拒绝覆盖：{$file}");
            }
        }
        [$prefix] = $this->routeParts($schema);
        if ((new RouteWriter())->has($prefix)) {
            throw new \RuntimeException("目标路由已存在，拒绝生成：{$prefix}");
        }
        $menu = $schema['stub'] === 'admin' ? new AdminMenu() : new TenantMenu();
        $code = $schema['template'] === 'plugin' ? 'app/' : '';
        $code .= $schema['namespace'] . (!empty($schema['package_name']) ? '/' . $schema['package_name'] : '') . '/' . $schema['business_name'];
        if ($menu->where('code', $code)->count() > 0) {
            throw new \RuntimeException("目标菜单已存在，拒绝生成：{$code}");
        }
    }

    protected function compensate(array &$manifest): void
    {
        if (!empty($manifest['route_created'])) {
            (new RouteWriter())->remove((string) $manifest['route_prefix']);
            $manifest['route_created'] = false;
        }
        foreach ($manifest['files'] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new \RuntimeException("补偿删除文件失败：{$file}");
            }
        }
        $manifest['files'] = [];
        if (!empty($manifest['menus_created']) && !empty($manifest['table_id'])) {
            $menu = $manifest['stub'] === 'admin' ? new AdminMenu() : new TenantMenu();
            $parent = $menu->where('generate_id', $manifest['table_id'])->findOrEmpty();
            if (!$parent->isEmpty()) {
                $class = get_class($menu);
                $children = $menu->where('parent_id', $parent['id'])->column('id');
                if ($children !== []) $class::destroy($children, true);
                $class::destroy($parent['id'], true);
            }
            $manifest['menus_created'] = false;
        }
        if (!empty($manifest['registration_created']) && !empty($manifest['table_id'])) {
            $ids = Column::where('table_id', $manifest['table_id'])->column('id');
            if ($ids !== []) Column::destroy($ids, true);
            Table::destroy($manifest['table_id'], true);
            $manifest['registration_created'] = false;
            $manifest['table_id'] = null;
        }
        if (!empty($manifest['created_table'])) {
            (new SchemaBuilder())->drop($manifest['table_name'], $manifest['source'] ?? '');
            $manifest['created_table'] = false;
        }
    }

    protected function tableShape(array $schema): array
    {
        return [
            'namespace' => $schema['namespace'], 'package_name' => $schema['package_name'] ?? '',
            'class_name' => $schema['class_name'], 'business_name' => $schema['business_name'],
            'template' => $schema['template'], 'stub' => $schema['stub'],
            'generate_path' => self::frontendPath($schema['stub']),
        ];
    }

    /**
     * 预览将执行的操作
     */
    protected function preview(array $schema, array $steps, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<info>=== AI 一键 CRUD 预览 ===</info>');
        $output->writeln("  表名      : {$schema['table']}（{$schema['table_comment']}）");
        $output->writeln("  端        : {$schema['stub']}  模板: {$schema['template']}  命名空间: {$schema['namespace']}");
        $output->writeln("  业务名    : {$schema['business_name']}  类名: {$schema['class_name']}");
        $output->writeln("  执行步骤  : " . implode(' → ', $steps));
        $output->writeln("  字段数    : " . count($schema['columns']));
        if (in_array('table', $steps)) {
            $sql = (new SchemaBuilder())->buildSql(
                $schema['table'], $schema['table_comment'], $schema['columns'], $schema['stub']
            );
            $output->writeln('');
            $output->writeln('<comment>--- 建表 SQL ---</comment>');
            $output->writeln($sql);
        }
        if (in_array('route', $steps)) {
            [$prefix, $controller] = $this->routeParts($schema);
            $output->writeln('');
            $output->writeln('<comment>--- 路由行 ---</comment>');
            $output->writeln('  ' . trim(RouteWriter::buildLine($prefix, $controller)));
        }
        $output->writeln('');
    }

    /**
     * 交互确认
     */
    protected function confirm(InputInterface $input, OutputInterface $output, string $question): bool
    {
        $helper = $this->getHelper('question');
        $q = new ConfirmationQuestion($question . ' [y/N] ', false);
        return (bool) $helper->ask($input, $output, $q);
    }

    // __STEPS__
    /**
     * 步骤1：建表。返回是否由本次创建。
     */
    protected function stepTable(array $schema, OutputInterface $output): bool
    {
        $builder = new SchemaBuilder();
        $source = $schema['source'] ?? '';
        if ($builder->tableExists($schema['table'], $source)) {
            throw new \RuntimeException("表已存在，拒绝覆盖：{$schema['table']}");
        }
        $sql = $builder->buildSql($schema['table'], $schema['table_comment'], $schema['columns'], $schema['stub']);
        $builder->create($sql, $source);
        $output->writeln("<info>· 建表完成：{$schema['table']}</info>");
        return true;
    }

    /**
     * 步骤2：装载表到 saimulti_table/saimulti_column，并应用 AI 字段配置。
     * 返回 saimulti_table 主键。
     */
    protected function stepLoad(array $schema, OutputInterface $output): int
    {
        $source = $schema['source'] ?? '';
        return \support\think\Db::transaction(function () use ($schema, $output, $source): int {
            $tableModel = new Table();

            $existing = $tableModel->where('table_name', $schema['table'])
                ->where('namespace', $schema['namespace'])
                ->where('stub', $schema['stub'])
                ->findOrEmpty();
            if (!$existing->isEmpty()) {
                throw new \RuntimeException("登记已存在，拒绝覆盖：{$schema['table']}");
            }

            $tableInfo = [
                'class_name'      => $schema['class_name'],
                'business_name'   => $schema['business_name'],
                'table_name'      => $schema['table'],
                'table_comment'   => $schema['table_comment'],
                'belong_menu_id'  => $schema['belong_menu_id'] ?? 4000,
                'menu_name'       => $schema['menu_name'] ?? $schema['table_comment'],
                'tpl_category'    => $schema['tpl_category'] ?? 'single',
                'template'        => $schema['template'],
                'stub'            => $schema['stub'],
                'generate_path'   => self::frontendPath($schema['stub']),
                'namespace'       => $schema['namespace'],
                'package_name'    => $schema['package_name'] ?? '',
                'source'          => $source,
                'generate_menus'  => $schema['generate_menus'] ?? 'index,save,update,read,destroy',
                'span'            => 24,
                'options'         => json_encode($schema['options'] ?? ['relations' => []], JSON_UNESCAPED_UNICODE),
            ];
            $model = Table::create($tableInfo);
            $tableId = $model->id;
            if (getenv('AI_CRUD_FAULT_AT') === 'load_after_registration') {
                throw new \RuntimeException('AI_CRUD_FAULT_AT=load_after_registration');
            }

            $dbLogic = new DbLogic();
            $columns = $dbLogic->getColumnList($schema['table'], $source);
            $aiCols = [];
            foreach ($schema['columns'] as $columnConfig) {
                $aiCols[$columnConfig['name']] = $columnConfig;
            }
            foreach ($columns as &$column) {
                $column['table_id'] = $tableId;
                $column['is_cover'] = false;
                $name = $column['column_name'];
                if (isset($aiCols[$name])) {
                    $column = $this->applyAiColumn($column, $aiCols[$name]);
                }
            }
            unset($column);
            (new ColumnLogic())->saveExtra($columns);

            $output->writeln("<info>· 装载完成：saimulti_table#{$tableId}，字段 " . count($columns) . " 个</info>");
            return (int) $tableId;
        });
    }

    /**
     * 把 AI 的字段配置叠加到 DB 推断出的列上。
     * is_cover=true 会让 saveExtra 跳过框架默认推断，直接用我们给的值。
     */
    protected function applyAiColumn(array $column, array $ai): array
    {
        $column['is_cover'] = true;
        $map = [
            'view_type'    => 'view_type',
            'dict_type'    => 'dict_type',
            'query_type'   => 'query_type',
            'is_query'     => 'is_query',
            'is_required'  => 'is_required',
            'is_list'      => 'is_list',
            'is_insert'    => 'is_insert',
            'is_edit'      => 'is_edit',
            'is_sort'      => 'is_sort',
            'column_width' => 'column_width',
            'options'      => 'options',
            'comment'      => 'column_comment',
        ];
        foreach ($map as $aiKey => $colKey) {
            if (array_key_exists($aiKey, $ai)) {
                $column[$colKey] = $ai[$aiKey];
            }
        }
        foreach (['is_insert', 'is_edit', 'is_list'] as $k) {
            if (!isset($column[$k])) {
                $column[$k] = 2;
            }
        }
        return $column;
    }

    /**
     * 步骤3：生成代码到模块 + 写菜单权限。返回生成的文件绝对路径列表。
     */
    protected function stepGenerate(int $tableId, OutputInterface $output): array
    {
        if (empty($tableId)) {
            throw new \RuntimeException('未找到对应的 saimulti_table 记录，无法生成');
        }
        (new TableLogic())->generateFile($tableId);
        $files = $this->snapshotOutputDirs($tableId);
        $output->writeln("<info>· 代码已生成，菜单权限已写入</info>");
        foreach ($files as $f) {
            $output->writeln("    + " . $this->shortPath($f));
        }
        return $files;
    }

    /**
     * 步骤4：写路由。返回写入的路由行。
     */
    protected function stepRoute(array $schema, OutputInterface $output): string
    {
        [$prefix, $controller] = $this->routeParts($schema);
        $writer = new RouteWriter();
        $result = $writer->insert($prefix, $controller, $schema['stub']);
        $tag = $result['ok'] ? 'info' : 'error';
        $output->writeln("<{$tag}>· 路由：{$result['message']}</{$tag}>");
        if (!$result['ok']) {
            throw new \RuntimeException($result['message']);
        }
        return trim($result['line']);
    }

    /**
     * 计算路由前缀与控制器完整类名
     */
    protected function routeParts(array $schema): array
    {
        if (!empty($schema['route_prefix'])) {
            $prefix = $schema['route_prefix'];
        } else {
            $pkg = !empty($schema['package_name']) ? '/' . $schema['package_name'] : '';
            $base = $schema['stub'] === 'admin' ? '/admin' : '/tenant';
            $prefix = $base . $pkg . '/' . $schema['business_name'];
        }

        $sub = !empty($schema['package_name']) ? '\\' . $schema['package_name'] : '';
        if ($schema['template'] === 'plugin') {
            $controller = 'plugin\\' . $schema['namespace'] . '\\app\\controller' . $sub . '\\' . $schema['class_name'] . 'Controller';
        } else {
            $controller = 'app\\' . $schema['namespace'] . '\\controller' . $sub . '\\' . $schema['class_name'] . 'Controller';
        }
        return [$prefix, $controller];
    }

    /**
     * 根据 schema 查 saimulti_table 主键
     */
    protected function findTableId(array $schema): int
    {
        $row = (new Table())->where('table_name', $schema['table'])
            ->where('namespace', $schema['namespace'])
            ->where('stub', $schema['stub'])
            ->order('id', 'desc')
            ->findOrEmpty();
        return $row->isEmpty() ? 0 : (int) $row['id'];
    }

    /**
     * 推算生成产物的文件路径
     */
    protected function snapshotOutputDirs(int $tableId): array
    {
        $row = (new Table())->findOrEmpty($tableId);
        if ($row->isEmpty()) {
            return [];
        }
        return self::resolveGeneratedFiles($row->toArray());
    }

    /**
     * 给定一条 saimulti_table 记录，返回它对应的生成文件路径（存在的才返回）
     */
    public static function resolveGeneratedFiles(array $t): array
    {
        return array_values(array_filter(self::resolveExpectedGeneratedFiles($t), 'is_file'));
    }

    /**
     * 返回该登记记录唯一允许生成/回滚的文件集合，不要求文件已经存在。
     */
    public static function resolveExpectedGeneratedFiles(array $t): array
    {
        foreach (['namespace', 'business_name'] as $key) {
            if (!isset($t[$key]) || !preg_match('/^[a-z][a-z0-9_]*$/', (string) $t[$key])) {
                throw new \RuntimeException("生成记录 {$key} 不合法");
            }
        }
        if (!isset($t['class_name']) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', (string) $t['class_name'])) {
            throw new \RuntimeException('生成记录 class_name 不合法');
        }
        if (!empty($t['package_name']) && !preg_match('/^[a-z][a-z0-9_]*$/', (string) $t['package_name'])) {
            throw new \RuntimeException('生成记录 package_name 不合法');
        }
        if (!in_array($t['template'] ?? null, ['app', 'plugin'], true)) {
            throw new \RuntimeException('生成记录 template 不合法');
        }
        $namespace    = $t['namespace'];
        $package      = $t['package_name'] ?? '';
        $class        = $t['class_name'];
        $business     = $t['business_name'];
        $template     = $t['template'];
        $generatePath = self::frontendPath((string) $t['stub']);
        if (isset($t['generate_path']) && $t['generate_path'] !== $generatePath) {
            throw new \RuntimeException("generate_path 必须为 {$generatePath}");
        }

        if ($template === 'app') {
            $phpRoot = base_path() . '/app/' . $namespace;
        } else {
            $phpRoot = base_path() . '/plugin/' . $namespace . '/app';
        }
        $sub = $package !== '' ? '/' . $package : '';

        $files = [
            "{$phpRoot}/controller{$sub}/{$class}Controller.php",
            "{$phpRoot}/logic{$sub}/{$class}Logic.php",
            "{$phpRoot}/model{$sub}/{$class}.php",
            "{$phpRoot}/validate{$sub}/{$class}Validate.php",
        ];

        $feRoot = dirname(base_path()) . '/' . $generatePath . '/src';
        // 与 CodeEngine::generateFrontend 对齐：根在 src/views/<namespace>，api 在其下的 api/<package>/
        $nsRoot = "{$feRoot}/views/{$namespace}";
        $viewBase = "{$nsRoot}{$sub}/{$business}";
        $files[] = "{$viewBase}/index.vue";
        $files[] = "{$viewBase}/modules/edit-dialog.vue";
        $files[] = "{$viewBase}/modules/table-search.vue";
        $files[] = "{$viewBase}/modules/view-dialog.vue";
        $files[] = "{$nsRoot}/api{$sub}/{$business}.ts";

        return $files;
    }

    protected function shortPath(string $path): string
    {
        $root = dirname(base_path());
        return str_replace($root . '/', '', $path);
    }
}
