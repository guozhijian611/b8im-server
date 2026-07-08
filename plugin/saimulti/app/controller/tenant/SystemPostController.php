<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\tenant;

use plugin\saimulti\app\logic\tenant\PostLogic;
use plugin\saimulti\app\validate\system\SystemPostValidate;
use plugin\saimulti\basic\TenantController;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 岗位信息控制器
 */
class SystemPostController extends TenantController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new PostLogic();
        $this->validate = new SystemPostValidate;
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('岗位数据列表', 'saimulti:tenant:post:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['name', ''],
            ['code', ''],
            ['status', ''],
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
    #[Permission('岗位数据读取', 'saimulti:tenant:post:read')]
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
     * 保存数据
     * @param Request $request
     * @return Response
     */
    #[Permission('岗位数据添加', 'saimulti:tenant:post:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        $result = $this->logic->add($data);
        if ($result) {
            return $this->success('添加成功');
        } else {
            return $this->fail('添加失败');
        }
    }

    /**
     * 更新数据
     * @param Request $request
     * @return Response
     */
    #[Permission('岗位数据修改', 'saimulti:tenant:post:update')]
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
    #[Permission('岗位数据删除', 'saimulti:tenant:post:destroy')]
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

}
