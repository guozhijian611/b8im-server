<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\app\logic\tenant\NoticeLogic;
use plugin\saimulti\app\validate\system\SystemNoticeValidate;
use plugin\saimulti\basic\TenantController;
use support\Request;
use support\Response;

/**
 * 系统公告控制器
 */
class SystemNoticeController extends TenantController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new NoticeLogic();
        $this->validate = new SystemNoticeValidate;
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
            ['title', ''],
            ['type', ''],
            ['create_time', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

}
