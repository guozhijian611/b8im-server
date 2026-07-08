<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tool;

use plugin\saimulti\basic\AdminController;
use plugin\saimulti\app\logic\tool\MailLogic;
use plugin\saimulti\app\validate\tool\SystemMailValidate;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 邮件记录控制器
 */
class MailController extends AdminController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new MailLogic();
        $this->validate = new SystemMailValidate;
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('邮件记录列表', 'saimulti:mail:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['gateway', ''],
            ['from', ''],
            ['code', ''],
            ['email', ''],
            ['status', ''],
            ['create_time', ''],
        ]);
        $query = $this->logic->search($where);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 删除数据
     * @param Request $request
     * @return Response
     */
    #[Permission('邮件记录删除', 'saimulti:mail:destroy')]
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