<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tool;

use plugin\saimulti\app\logic\system\SystemAttachmentLogic;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 上传附件控制器
 */
class AttachmentController extends AdminController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new SystemAttachmentLogic();
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('附件列表', 'saimulti:attachment:index')]
    public function index(Request $request) : Response
    {
        $where = $request->more([
            ['organization', ''],
            ['origin_name', ''],
            ['category_id', ''],
            ['storage_mode', ''],
            ['mime_type', ''],
            ['create_time', ''],
        ]);
        $query = $this->logic->search($where)->with(['organ']);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 更新数据
     * @param Request $request
     * @return Response
     */
    #[Permission('附件数据修改', 'saimulti:attachment:edit')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $result = $this->logic->edit($data['id'], ['origin_name' => $data['origin_name']]);
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
    #[Permission('附件数据删除', 'saimulti:attachment:edit')]
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

    /**
     * 移动分类
     * @param Request $request
     * @return Response
     */
    #[Permission('附件移动分类', 'saimulti:attachment:edit')]
    public function move(Request $request) : Response
    {
        $category_id = $request->post('category_id', '');
        $ids = $request->post('ids', '');
        if (empty($ids) || empty($category_id)) {
            return $this->fail('参数错误，请检查参数');
        }
        $result = $this->logic->move($category_id, $ids);
        if ($result) {
            return $this->success('删除成功');
        } else {
            return $this->fail('删除失败');
        }
    }

}
