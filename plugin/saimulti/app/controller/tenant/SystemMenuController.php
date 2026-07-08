<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\app\logic\tenant\MenuLogic;
use plugin\saimulti\basic\TenantController;
use support\Request;
use support\Response;

/**
 * 菜单控制器
 */
class SystemMenuController extends TenantController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new MenuLogic();
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    public function index(Request $request) : Response
    {
        $where = $request->more([
            ['name', ''],
            ['code', ''],
            ['is_hidden', ''],
            ['status', ''],
        ]);
        $data = $this->logic->tree($where, $this->organInfo['group_id']);
        return $this->success($data);
    }

}