<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\basic\TenantController;
use plugin\saimulti\app\logic\admin\SystemCategoryLogic;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 附件分类控制器
 */
class SystemCategoryController extends TenantController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new SystemCategoryLogic();
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('附件分类列表', 'saimulti:tenant:attachment:index')]
    public function index(Request $request) : Response
    {
        $where = $request->more([
            ['category_name', ''],
        ]);
        $data = $this->logic->tree($where);
        return $this->success($data);
    }

}
