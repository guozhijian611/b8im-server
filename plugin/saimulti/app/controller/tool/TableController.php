<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tool;

use support\Request;
use support\Response;
use plugin\saimulti\service\Permission;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\app\logic\tool\TableLogic;
use plugin\saimulti\app\logic\tool\DbLogic;
use plugin\saimulti\app\validate\tool\TableValidate;
use plugin\saimulti\exception\ApiException;

/**
 * 低代码控制器
 */
class TableController extends AdminController
{

    /**
     * 数据源逻辑层
     */
    public $dbLogic;

    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new TableLogic();
        $this->dbLogic = new DbLogic();
        $this->validate = new TableValidate;
        parent::__construct();
    }


    /**
     * 读取系统数据源
     * @return Response
     */
    public function source(): Response
    {
        $data = config('think-orm.connections');
        if (empty($data)) {
            $data = config('thinkorm.connections');
        }
        $list = [];
        foreach ($data as $k => $v) {
            $list[] = $k;
        }
        return $this->success($list);
    }

    /**
     * 数据源数据表列表
     * @param Request $request
     * @return Response
     */
    #[Permission('数据表列表', 'saimulti:tool:develop')]
    public function sourceTable(Request $request): Response
    {
        $where = $request->more([
            ['name', ''],
            ['source', ''],
        ]);
        $data = $this->dbLogic->getList($where);
        return $this->success($data);
    }

    /**
     * 装载数据表
     * @param Request $request
     * @return Response
     */
    #[Permission('装载数据表', 'saimulti:tool:develop')]
    public function loadTable(Request $request): Response
    {
        $names = $request->input('names', []);
        $source = $request->input('source', '');
        $this->logic->loadTable($names, $source);
        return $this->success('操作成功');
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
   #[Permission('代码生成列表', 'saimulti:tool:develop:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['table_name', ''],
            ['source', ''],
            ['namespace', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 读取数据
     * @param Request $request
     * @return Response
     */
    #[Permission('代码生成读取', 'saimulti:tool:develop')]
    public function read(Request $request): Response
    {
        $id = $request->input('id', '');
        $model = $this->logic->read($id);
        if ($model) {
            $data = is_array($model) ? $model : $model->toArray();
            return $this->success($data);
        } else {
            return $this->fail('未查找到信息');
        }
    }

    /**
     * 修改数据
     * @param Request $request
     * @return Response
     */
    #[Permission('代码生成修改', 'saimulti:tool:develop')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        $result = $this->logic->edit($data['id'], $data);
        if ($result) {
            return $this->success('修改成功');
        } else {
            return $this->fail('修改失败');
        }
    }

    /**
     * 删除数据
     * @param Request $request
     * @return Response
     */
    #[Permission('代码生成删除', 'saimulti:tool:develop')]
    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids', '');
        if (empty($ids)) {
            return $this->fail('请选择要删除的数据');
        }
        $result = $this->logic->destroy($ids);
        if ($result) {
            return $this->success('删除成功');
        } else {
            return $this->fail('删除失败');
        }
    }

    /**
     * 同步数据表字段信息
     * @param Request $request
     * @return Response
     */
    #[Permission('代码生成同步', 'saimulti:tool:develop')]
    public function sync(Request $request): Response
    {
        $id = $request->input('id');
        $this->logic->sync($id);
        return $this->success('操作成功');
    }

    /**
     * 代码预览
     */
    #[Permission('代码生成预览', 'saimulti:tool:develop:preview')]
    public function preview(Request $request): Response
    {
        $id = $request->input('id');
        $data = $this->logic->preview($id);
        return $this->success($data);
    }

    /**
     * 代码生成
     */
    #[Permission('代码生成', 'saimulti:tool:develop')]
    public function generate(Request $request): Response
    {
        $ids = $request->input('ids', '');
        $data = $this->logic->generate($ids);
        return response()->download($data['download'], $data['filename']);
    }

    /**
     * 生成到模块
     */
    #[Permission('代码生成到模块', 'saimulti:tool:develop')]
    public function generateFile(Request $request): Response
    {
        $id = $request->input('id', '');
        $this->logic->generateFile($id);
        return $this->success('操作成功');
    }

    /**
     * 获取数据表字段信息
     * @param Request $request
     * @return Response
     */
    #[Permission('数据表字段信息', 'saimulti:tool:develop')]
    public function getTableColumns(Request $request): Response
    {
        $table_id = $request->input('table_id', '');
        $data = $this->logic->getTableColumns($table_id);
        return $this->success($data);
    }

    /**
     * 保存表单设计
     * @param Request $request
     * @return Response
     */
    #[Permission('保存表单设计', 'saimulti:tool:develop')]
    public function saveDesign(Request $request): Response
    {
        $table = $request->input('table');
        $columns = $request->input('columns', []);
        $data = [
            'id' => $table['id'],
            'form_width' => $table['form_width'],
            'is_full' => $table['is_full'] === true ? 2 : 1,
            'component_type' => $table['component_type'],
            'span' => $table['form_span'],
        ];
        $this->logic->saveDesign($data, $columns);
        return $this->success('操作成功');
    }

    /**
     * 保存搜索设计
     * @param Request $request
     * @return Response
     */
    #[Permission('保存搜索设计', 'saimulti:tool:develop')]
    public function saveSearchDesign(Request $request): Response
    {
        $table_id = $request->input('table_id');
        $columns = $request->input('columns', []);
        $this->logic->saveSearchDesign($table_id, $columns);
        return $this->success('操作成功');
    }

}