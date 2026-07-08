<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\app\logic\tool\LoginLogLogic;
use plugin\saimulti\app\logic\tool\OperLogLogic;
use plugin\saimulti\basic\TenantController;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 日志控制器
 */
class SystemLogController extends TenantController
{

    /**
     * 登录日志列表
     * @param Request $request
     * @return Response
     */
    #[Permission('登录日志列表', 'saimulti:tenant:login:index')]
    public function getLoginLogPageList(Request $request) : Response
    {
        $where = $request->more([
            ['organization', ''],
            ['login_time', ''],
            ['username', ''],
            ['status', ''],
            ['ip', ''],
        ]);
        $logic = new LoginLogLogic();
        $query = $logic->search($where)->with(['organ']);
        $data = $logic->getList($query);
        return $this->success($data);
    }

    /**
     * 删除登录日志
     * @param Request $request
     * @return Response
     */
    #[Permission('删除登录日志', 'saimulti:tenant:login:destroy')]
    public function deleteLoginLog(Request $request) : Response
    {
        $ids = $request->input('ids', '');
        $logic = new LoginLogLogic();
        if (!empty($ids)) {
            $logic->destroy($ids);
            return $this->success('删除成功');
        } else {
            return $this->fail('参数错误，请检查');
        }
    }

    /**
     * 操作日志列表
     * @param Request $request
     * @return Response
     */
    #[Permission('操作日志列表', 'saimulti:tenant:oper:index')]
    public function getOperLogPageList(Request $request) : Response
    {
        $where = $request->more([
            ['organization', ''],
            ['create_time', ''],
            ['username', ''],
            ['service_name', ''],
            ['ip', ''],
        ]);
        $logic = new OperLogLogic();
        $query = $logic->search($where)->with(['organ']);
        $data = $logic->getList($query);
        return $this->success($data);
    }

    /**
     * 删除操作日志
     * @param Request $request
     * @return Response
     */
    #[Permission('删除操作日志', 'saimulti:tenant:oper:destroy')]
    public function deleteOperLog(Request $request) : Response
    {
        $ids = $request->input('ids', '');
        $logic = new OperLogLogic();
        if (!empty($ids)) {
            $logic->destroy($ids);
            return $this->success('删除成功');
        } else {
            return $this->fail('参数错误，请检查');
        }
    }

}
