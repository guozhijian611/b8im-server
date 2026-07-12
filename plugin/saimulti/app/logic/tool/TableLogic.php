<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tool;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use plugin\saimulti\app\model\admin\Menu as AdminMenu;
use plugin\saimulti\app\model\tenant\Menu as TenantMenu;
use plugin\saimulti\app\model\tool\Column;
use plugin\saimulti\app\model\tool\Table;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\utils\code\CodeEngine;
use plugin\saimulti\utils\code\CodeZip;
use plugin\saimulti\utils\Helper;

/**
 * 低代码表逻辑层
 */
class TableLogic extends BaseLogic
{

    protected $columnLogic = null;
    protected $dbLogic = null;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new Table();
        $this->columnLogic = new ColumnLogic();
        $this->dbLogic = new DbLogic();
    }

    /**
     * 默认源
     * @return string
     */
    private function defaultDbSource(): string
    {
        $config = config('think-orm');
        if (empty($config)) {
            $config = config('thinkorm');
        }
        return $config['default'] ?? 'mysql';
    }

    /**
     * 删除表和字段信息
     * @param $ids
     */
    public function destroy($ids): bool
    {
        return $this->transaction(function () use ($ids) {
            Column::destroy(function ($query) use ($ids) {
                $query->where('table_id', 'in', $ids);
            });
            return parent::destroy($ids);
        });
    }

    /**
     * 装载表信息
     * @param $names
     * @param $source
     * @return void
     */
    public function loadTable($names, $source)
    {
        $connections = config('think-orm.connections');
        if (empty($connections)) {
            $connections = config('thinkorm.connections');
        }
        $config = $connections[$source];
        $prefix = $config['prefix'] ?? '';
        $this->transaction(function () use ($names, $prefix, $source) {
            foreach ($names as $item) {
                $class_name = $item['name'];
                if (!empty($prefix)) {
                    $class_name = Helper::str_replace_once($prefix, '', $class_name);
                }
                $class_name = Helper::camel($class_name);
                $tableInfo = [
                    'class_name' => $class_name,
                    'business_name' => Helper::get_business($item['name']),
                    'table_name' => $item['name'],
                    'table_comment' => $item['comment'],
                    'belong_menu_id' => 4000,
                    'menu_name' => $item['comment'],
                    'tpl_category' => 'single',
                    'template' => 'app',
                    'stub' => 'tenant',
                    'generate_path' => 'b8im-tenant-vue',
                    'namespace' => '',
                    'package_name' => '',
                    'source' => $source,
                    'generate_menus' => 'index,save,update,read,destroy',
                    'span' => 24
                ];
                $model = Table::create($tableInfo);
                $columns = $this->dbLogic->getColumnList($item['name'], $source);
                foreach ($columns as &$column) {
                    $column['table_id'] = $model->id;
                    $column['is_cover'] = false;
                }
                $this->columnLogic->saveExtra($columns);
            }
        });
    }

    /**
     * 同步表字段信息
     * @param $id
     * @return void
     */
    public function sync($id): void
    {
        $model = $this->model->findOrEmpty($id);
        // 拉取已有数据表信息
        $queryModel = $this->columnLogic->model->where([['table_id', '=', $id]]);
        $columnLogicData = $this->columnLogic->getAll($queryModel);
        $columnLogicList = [];
        foreach ($columnLogicData as $item) {
            $columnLogicList[$item['column_name']] = $item;
        }
        $this->columnLogic->destroy(function ($query) use ($id) {
            $query->where('table_id', $id);
        });
        $columns = $this->dbLogic->getColumnList($model->table_name, $model->source ?? '');
        foreach ($columns as &$column) {
            $column['table_id'] = $model->id;
            $column['is_cover'] = false;
            if (isset($columnLogicList[$column['column_name']])) {
                // 存在历史信息的情况
                $getcolumnLogicItem = $columnLogicList[$column['column_name']];
                if ($getcolumnLogicItem['column_type'] == $column['column_type']) {
                    $column['is_cover'] = true;
                    foreach ($getcolumnLogicItem as $key => $item) {
                        $array = [
                            'column_comment',
                            'column_type',
                            'is_pk',
                            'is_required',
                            'is_insert',
                            'is_edit',
                            'is_list',
                            'is_query',
                            'is_sort',
                            'query_type',
                            'view_type',
                            'dict_type',
                            'options',
                            'sort',
                            'is_cover'
                        ];
                        if (in_array($key, $array)) {
                            $column[$key] = $item;
                        }
                    }
                }
            }
        }
        $this->columnLogic->saveExtra($columns);
    }

    /**
     * 代码预览
     * @param $id
     * @return array
     */
    public function preview($id): array
    {
        $data = $this->renderData($id);

        $codeEngine = new CodeEngine($data);
        $codeEngine->setStub($data['stub']);
        $controllerContent = $codeEngine->renderContent('php', 'controller.stub');
        $logicContent = $codeEngine->renderContent('php', 'logic.stub');
        $modelContent = $codeEngine->renderContent('php', 'model.stub');
        $validateContent = $codeEngine->renderContent('php', 'validate.stub');
        $sqlContent = $codeEngine->renderContent('sql', 'sql.stub');
        $indexContent = $codeEngine->renderContent('vue', 'index.stub');
        $editContent = $codeEngine->renderContent('vue', 'edit-dialog.stub');
        $viewContent = $codeEngine->renderContent('vue', 'view-dialog.stub');
        $searchContent = $codeEngine->renderContent('vue', 'table-search.stub');
        $apiContent = $codeEngine->renderContent('ts', 'api.stub');

        // 返回生成内容
        return [
            [
                'tab_name' => 'controller.php',
                'name' => 'controller',
                'lang' => 'php',
                'code' => $controllerContent
            ],
            [
                'tab_name' => 'logic.php',
                'name' => 'logic',
                'lang' => 'php',
                'code' => $logicContent
            ],
            [
                'tab_name' => 'model.php',
                'name' => 'model',
                'lang' => 'php',
                'code' => $modelContent
            ],
            [
                'tab_name' => 'validate.php',
                'name' => 'validate',
                'lang' => 'php',
                'code' => $validateContent
            ],
            [
                'tab_name' => 'sql.sql',
                'name' => 'sql',
                'lang' => 'sql',
                'code' => $sqlContent
            ],
            [
                'tab_name' => 'index.vue',
                'name' => 'index',
                'lang' => 'html',
                'code' => $indexContent
            ],
            [
                'tab_name' => 'edit-dialog.vue',
                'name' => 'edit-dialog',
                'lang' => 'html',
                'code' => $editContent
            ],
            [
                'tab_name' => 'view-dialog.vue',
                'name' => 'view-dialog',
                'lang' => 'html',
                'code' => $viewContent
            ],
            [
                'tab_name' => 'table-search.vue',
                'name' => 'table-search',
                'lang' => 'html',
                'code' => $searchContent
            ],
            [
                'tab_name' => 'api.ts',
                'name' => 'api',
                'lang' => 'javascript',
                'code' => $apiContent
            ]
        ];
    }

    /**
     * 生成到模块
     * @param $id
     */
    public function genModule($id)
    {
        $data = $this->renderData($id);

        // 生成文件到模块
        $codeEngine = new CodeEngine($data);
        $codeEngine->setStub($data['stub']);

        $codeEngine->generateBackend('controller', $codeEngine->renderContent('php', 'controller.stub'));
        $codeEngine->generateBackend('logic', $codeEngine->renderContent('php', 'logic.stub'));
        $codeEngine->generateBackend('model', $codeEngine->renderContent('php', 'model.stub'));
        $codeEngine->generateBackend('validate', $codeEngine->renderContent('php', 'validate.stub'));
        $codeEngine->generateFrontend('index', $codeEngine->renderContent('vue', 'index.stub'));
        $codeEngine->generateFrontend('edit-dialog', $codeEngine->renderContent('vue', 'edit-dialog.stub'));
        $codeEngine->generateFrontend('view-dialog', $codeEngine->renderContent('vue', 'view-dialog.stub'));
        $codeEngine->generateFrontend('table-search', $codeEngine->renderContent('vue', 'table-search.stub'));
        $codeEngine->generateFrontend('api', $codeEngine->renderContent('ts', 'api.stub'));

        // 如果有导入功能，生成一个导入模板excel文件
        if (str_contains($data['generate_menus'], 'import')) {
            $header = array_column($data['export_columns'], 'column_comment');
            $this->createExcelTemplate($header, $data['menu_name']);
        }
    }

    /**
     * 处理数据
     * @param $id
     * @return array
     */
    protected function renderData($id): array
    {
        $table = $this->model->findOrEmpty($id);
        if (!in_array($table['template'], ["plugin", "app"])) {
            throw new ApiException('应用类型必须为plugin或者app');
        }
        if (empty($table['namespace'])) {
            throw new ApiException('请先设置应用名称');
        }

        $columns = $this->columnLogic->where('table_id', $id)
            ->order('list_sort', 'asc')
            ->select()
            ->toArray();
        $pk = 'id';
        $export_columns = [];
        foreach ($columns as &$column) {
            if ($column['is_pk'] == 2) {
                $pk = $column['column_name'];
            }
            if ($column['column_name'] == 'delete_time') {
                unset($column['column_name']);
            }
            if (!in_array($column['column_name'], [$pk, 'created_by', 'updated_by', 'create_time', 'update_time'])) {
                $export_columns[] = $column;
            }
            switch ($column['column_type']) {
                case 'tinyint':
                case 'smallint':
                case 'mediumint':
                case 'int':
                case 'bigint':
                    $column['php_type'] = 'int';
                    break;
                case 'float':
                case 'double':
                case 'decimal':
                    $column['php_type'] = 'float';
                    break;
                default:
                    $column['php_type'] = 'string';
                    break;
            }
        }

        // 处理特殊变量
        if ($table['template'] == 'plugin') {
            $namespace_start = "plugin\\" . $table['namespace'] . "\\app\\";
            $namespace_end = $table['package_name'] != "" ? "\\" . $table['package_name'] : "";
            $url_path = 'app/' . $table['namespace'] . ($table['package_name'] != "" ? "/" . $table['package_name'] : "") . '/' . $table['class_name'];
            $route = 'app/';
        } else {
            $namespace_start = "app\\" . $table['namespace'] . "\\";
            $namespace_end = $table['package_name'] != "" ? "\\" . $table['package_name'] : "";
            $url_path = $table['namespace'] . ($table['package_name'] != "" ? "/" . $table['package_name'] : "") . '/' . $table['class_name'];
            $route = '';
        }
        $data = $table->toArray();
        $data['pk'] = $pk;
        $data['namespace_start'] = $namespace_start;
        $data['namespace_end'] = $namespace_end;
        $data['url_path'] = $url_path;
        $data['route'] = $route;
        $data['tables'] = [$data];
        $data['columns'] = $columns;
        $data['db_source'] = $this->defaultDbSource();
        $form_columns = $columns;
        array_multisort(array_column($form_columns, 'form_sort'), SORT_ASC, $form_columns);
        $data['form_columns'] = $form_columns;
        $data['export_columns'] = $export_columns;

        // 统计搜索字段数量，用于控制折叠功能
        $data['query_column_count'] = count(array_filter($columns, function ($col) {
            return $col['is_query'] == 2;
        }));

        return $data;
    }

    /**
     * 生成到模块
     */
    public function generateFile($id)
    {
        $table = $this->model->where('id', $id)->findOrEmpty();
        if ($table->isEmpty()) {
            throw new ApiException('请选择要生成的表');
        }
        $debug = config('app.debug', true);
        if (!$debug) {
            throw new ApiException('非调试模式下，不允许生成文件');
        }
        // 生成菜单
        if ($table['stub'] == 'admin') {
            $this->updateAdminMenu($table);
        } else {
            $this->updateTenantMenu($table);
        }
        // 生成文件
        $this->genModule($id);
    }

    /**
     * 代码生成下载
     */
    public function generate($idsArr): array
    {
        $zip = new CodeZip();
        $tables = $this->model->where('id', 'in', $idsArr)->select()->toArray();
        foreach ($idsArr as $table_id) {
            $data = $this->renderData($table_id);
            $data['tables'] = $tables;
            $codeEngine = new CodeEngine($data);
            $codeEngine->setStub($data['stub']);
            $codeEngine->generateTemp();
        }

        $filename = 'code.zip';
        $download = $zip->compress();

        return compact('filename', 'download');
    }

    /**
     * 部署菜单列表
     * @param $tables
     */
    public function updateTenantMenu($tables)
    {
        /*不存在的情况下进行新建操作*/

        if ($tables['template'] == 'plugin') {
            $url_path = $tables['namespace'] . ($tables['package_name'] != "" ? ":" . $tables['package_name'] : "") . ':' . $tables['business_name'];
            $code = 'app/' . $tables['namespace'] . ($tables['package_name'] != "" ? "/" . $tables['package_name'] : "") . '/' . $tables['business_name'];
        } else {
            $url_path = $tables['namespace'] . ($tables['package_name'] != "" ? ":" . $tables['package_name'] : "") . ':' . $tables['business_name'];
            $code = $tables['namespace'] . ($tables['package_name'] != "" ? "/" . $tables['package_name'] : "") . '/' . $tables['business_name'];
        }
        $component = $tables['namespace'] . ($tables['package_name'] != "" ? "/" . $tables['package_name'] : "") . '/' . $tables['business_name'];

        /*先获取一下已有的路由中是否包含当前ID的路由的核心信息*/
        $model = new TenantMenu();
        $tableMenu = $model->where('generate_id', $tables['id'])->findOrEmpty();
        $fistMenu = [
            'parent_id' => $tables['belong_menu_id'],
            'name' => $tables['menu_name'],
            'code' => $code,
            'type' => 2,
            'path' => "/$code",
            'component' => "/$component/index",
            'icon' => 'ri:home-2-line',
            'sort' => 100,
            'is_iframe' => 2,
            'is_keep_alive' => 2,
            'is_hidden' => 2,
            'is_fixed_tab' => 2,
            'is_full_page' => 2,
            'generate_id' => $tables['id']
        ];
        if (empty($tableMenu)) {
            $temp = TenantMenu::create($fistMenu);
            $fistMenuId = $temp->id;
        } else {
            $fistMenu['id'] = $tableMenu['id'];
            $tableMenu->save($fistMenu);
            $fistMenuId = $tableMenu['id'];
        }
        /*开始进行子权限的判定操作*/
        $childNodes = [
            ['name' => '列表', 'key' => 'index'],
            ['name' => '保存', 'key' => 'save'],
            ['name' => '更新', 'key' => 'update'],
            ['name' => '读取', 'key' => 'read'],
            ['name' => '删除', 'key' => 'destroy'],
            ['name' => '导入', 'key' => 'import'],
            ['name' => '导出', 'key' => 'export'],
        ];

        $chooseNodes = explode(',', $tables['generate_menus']);
        foreach ($childNodes as $node) {

            $nodeData = $model->where('parent_id', $fistMenuId)
                ->where('generate_key', $node['key'])
                ->findOrEmpty();
            $childNodeData = [
                'parent_id' => $fistMenuId,
                'name' => $tables['menu_name'] . $node['name'],
                'slug' => "$url_path:{$node['key']}",
                'type' => '3',
                'sort' => 100,
                'is_iframe' => 2,
                'is_keep_alive' => 2,
                'is_hidden' => 2,
                'is_fixed_tab' => 2,
                'is_full_page' => 2,
                'generate_key' => $node['key']
            ];
            if (!empty($nodeData)) {
                if (!in_array($node['key'], $chooseNodes)) {
                    $nodeData->delete();
                } else {
                    $childNodeData['id'] = $nodeData['id'];
                    $nodeData->save($childNodeData);
                }
            } else {
                $menuModel = new TenantMenu();
                $menuModel->save($childNodeData);
            }
        }
    }


    /**
     * 部署菜单列表
     * @param $tables
     */
    public function updateAdminMenu($tables)
    {
        /*不存在的情况下进行新建操作*/

        if ($tables['template'] == 'plugin') {
            $url_path = $tables['namespace'] . ($tables['package_name'] != "" ? ":" . $tables['package_name'] : "") . ':' . $tables['business_name'];
            $code = 'app/' . $tables['namespace'] . ($tables['package_name'] != "" ? "/" . $tables['package_name'] : "") . '/' . $tables['business_name'];
        } else {
            $url_path = $tables['namespace'] . ($tables['package_name'] != "" ? ":" . $tables['package_name'] : "") . ':' . $tables['business_name'];
            $code = $tables['namespace'] . ($tables['package_name'] != "" ? "/" . $tables['package_name'] : "") . '/' . $tables['business_name'];
        }
        $component = $tables['namespace'] . ($tables['package_name'] != "" ? "/" . $tables['package_name'] : "") . '/' . $tables['business_name'];

        /*先获取一下已有的路由中是否包含当前ID的路由的核心信息*/
        $model = new AdminMenu();
        $tableMenu = $model->where('generate_id', $tables['id'])->findOrEmpty();
        $fistMenu = [
            'parent_id' => $tables['belong_menu_id'],
            'name' => $tables['menu_name'],
            'code' => $code,
            'type' => 2,
            'path' => "/$code",
            'component' => "/$component/index",
            'icon' => 'ri:home-2-line',
            'sort' => 100,
            'is_iframe' => 2,
            'is_keep_alive' => 2,
            'is_hidden' => 2,
            'is_fixed_tab' => 2,
            'is_full_page' => 2,
            'generate_id' => $tables['id']
        ];
        if (empty($tableMenu)) {
            $temp = AdminMenu::create($fistMenu);
            $fistMenuId = $temp->id;
        } else {
            $fistMenu['id'] = $tableMenu['id'];
            $tableMenu->save($fistMenu);
            $fistMenuId = $tableMenu['id'];
        }
        /*开始进行子权限的判定操作*/
        $childNodes = [
            ['name' => '列表', 'key' => 'index'],
            ['name' => '保存', 'key' => 'save'],
            ['name' => '更新', 'key' => 'update'],
            ['name' => '读取', 'key' => 'read'],
            ['name' => '删除', 'key' => 'destroy'],
            ['name' => '导入', 'key' => 'import'],
            ['name' => '导出', 'key' => 'export'],
        ];

        $chooseNodes = explode(',', $tables['generate_menus']);
        foreach ($childNodes as $node) {

            $nodeData = $model->where('parent_id', $fistMenuId)
                ->where('generate_key', $node['key'])
                ->findOrEmpty();
            $childNodeData = [
                'parent_id' => $fistMenuId,
                'name' => $tables['menu_name'] . $node['name'],
                'slug' => "$url_path:{$node['key']}",
                'type' => '3',
                'sort' => 100,
                'is_iframe' => 2,
                'is_keep_alive' => 2,
                'is_hidden' => 2,
                'is_fixed_tab' => 2,
                'is_full_page' => 2,
                'generate_key' => $node['key']
            ];
            if (!empty($nodeData)) {
                if (!in_array($node['key'], $chooseNodes)) {
                    $nodeData->delete();
                } else {
                    $childNodeData['id'] = $nodeData['id'];
                    $nodeData->save($childNodeData);
                }
            } else {
                $menuModel = new AdminMenu();
                $menuModel->save($childNodeData);
            }
        }
    }

    /**
     * 获取数据表字段信息
     * @param $table_id
     * @return mixed
     */
    public function getTableColumns($table_id): mixed
    {
        $query = $this->columnLogic->where('table_id', $table_id);
        return $this->columnLogic->getAll($query);
    }

    /**
     * 编辑数据
     * @param $id
     * @param $data
     * @return mixed
     */
    public function edit($id, $data): mixed
    {
        $id = $data['id'];

        $columns = $data['columns'];
        unset($data['columns']);

        if (!empty($data['belong_menu_id'])) {
            $data['belong_menu_id'] = is_array($data['belong_menu_id']) ? array_pop($data['belong_menu_id']) : $data['belong_menu_id'];
        } else {
            $data['belong_menu_id'] = 0;
        }

        $data['generate_menus'] = implode(',', $data['generate_menus']);

        if (empty($data['options'])) {
            unset($data['options']);
        }

        $data['options'] = json_encode($data['options'], JSON_UNESCAPED_UNICODE);

        // 更新业务表
        $this->model->update($data, ['id' => $id]);

        // 更新业务字段表
        foreach ($columns as $column) {
            if ($column['options']) {
                $column['options'] = json_encode($column['options'], JSON_NUMERIC_CHECK);
            }
            if ($column['query_options']) {
                $column['query_options'] = json_encode($column['query_options'], JSON_NUMERIC_CHECK);
            }

            $this->columnLogic->update($column, ['id' => $column['id']]);
        }

        return true;
    }

    /**
     * 创建下载模板
     */
    public function createExcelTemplate($header, $file): void
    {
        $base_dir = config('plugin.saimulti.saithink.template', base_path() . '/public/template');
        if (!is_dir($base_dir)) {
            mkdir($base_dir, 0777, true);
        }
        $writer = new Writer();
        $writer->openToFile($base_dir . DIRECTORY_SEPARATOR . $file . '_template.xlsx');
        $sheet = $writer->getCurrentSheet();
        foreach ($header as $key => $value) {
            $sheet->setColumnWidth(20, $key + 1);
        }
        $border = new Border(
            new BorderPart("top", "black", "thin"),
            new BorderPart("right", "black", "thin"),
            new BorderPart("bottom", "black", "thin"),
            new BorderPart("left", "black", "thin"),
        );
        $style = new Style();
        $style->setFontBold();
        $style->setCellAlignment("center");
        $style->setBorder($border);

        $rowFromValues = Row::fromValues($header, $style);
        $writer->addRow($rowFromValues);
        $writer->close();
    }

    /**
     * 保存设计
     * @param $table
     * @param $columns
     * @return void
     */
    public function saveDesign($table, $columns): void
    {
        $this->transaction(function () use ($table, $columns) {
            $where = ['id' => $table['id']];
            $this->update($table, $where);

            // 更新业务字段表
            foreach ($columns as &$column) {

                unset($column['option']);
                if ($column['options']) {
                    $column['options'] = json_encode($column['options'], JSON_NUMERIC_CHECK);
                }
                unset($column['query_options']);
                if (isset($column['query_options']) && $column['query_options']) {
                    $column['query_options'] = json_encode($column['query_options'], JSON_NUMERIC_CHECK);
                }


                $column['is_insert'] = 1;
                if ($column['insert']) {
                    $column['is_insert'] = 2;
                }
                unset($column['insert']);

                $column['is_edit'] = 1;
                if ($column['edit']) {
                    $column['is_edit'] = 2;
                }
                unset($column['edit']);

                $this->columnLogic->where('id', $column['id'])->update($column);
            }
        });
    }

    /**
     * 保存搜索设计
     * @param $table_id
     * @param $columns
     * @return void
     */
    public function saveSearchDesign($table_id, $columns): void
    {
        $this->transaction(function () use ($table_id, $columns) {
            // 更新搜索相关字段
            foreach ($columns as $column) {
                $updateData = [
                    'column_comment' => $column['column_comment'],
                    'query_component' => $column['query_component'],
                    'query_dict' => $column['query_dict'] ?? '',
                    'is_query' => $column['is_query'] ? 2 : 1,
                    'query_type' => $column['query_type'] ?? 'eq',
                    'query_span' => $column['query_span'] ?? 6,
                    'query_sort' => $column['query_sort'] ?? 0,
                ];

                // 保存搜索配置到 query_options 字段
                if (isset($column['query_options']) && $column['query_options']) {
                    $updateData['query_options'] = json_encode($column['query_options'], JSON_NUMERIC_CHECK);
                }

                $this->columnLogic->where('id', $column['id'])->update($updateData);
            }
        });
    }

}
