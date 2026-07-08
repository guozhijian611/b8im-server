<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\system;

use plugin\saimulti\app\logic\admin\MenuLogic;
use plugin\saimulti\app\validate\admin\SystemMenuValidate;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 菜单控制器
 */
class MenuController extends AdminController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new MenuLogic();
        $this->validate = new SystemMenuValidate;
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('菜单数据列表', 'saimulti:coreMenu:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['name', ''],
            ['path', ''],
            ['menu', ''],
            ['status', ''],
        ]);
        $data = $this->logic->tree($where);
        return $this->success($data);
    }

    /**
     * 读取数据
     * @param Request $request
     * @return Response
     */
    #[Permission('菜单数据读取', 'saimulti:coreMenu:read')]
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
    #[Permission('菜单数据添加', 'saimulti:coreMenu:save')]
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
    #[Permission('菜单数据修改', 'saimulti:coreMenu:update')]
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
    #[Permission('菜单数据删除', 'saimulti:coreMenu:destroy')]
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
     * 可操作菜单
     * @param Request $request
     * @return Response
     */
    public function accessMenu(Request $request): Response
    {
        $where = [];
        if ($this->adminId > 1) {
            $data = $this->logic->auth();
        } else {
            $data = $this->logic->tree($where);
        }
        return $this->success($data);
    }

}