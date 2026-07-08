<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tool;

use plugin\saimulti\app\logic\tool\OperLogLogic;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 操作日志控制器
 */
class OperLogController extends AdminController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new OperLogLogic();
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('操作日志列表', 'saimulti:operlog:index')]
    public function index(Request $request) : Response
    {
        $where = $request->more([
            ['organization', ''],
            ['create_time', ''],
            ['username', ''],
            ['service_name', ''],
            ['ip', ''],
        ]);
        $query = $this->logic->search($where)->with(['organ']);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 删除数据
     * @param Request $request
     * @return Response
     */
    #[Permission('操作日志删除', 'saimulti:operlog:destroy')]
    public function destroy(Request $request) : Response
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

}
