<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\system;

use plugin\saimulti\app\logic\admin\RoleLogic;
use plugin\saimulti\app\validate\system\RoleValidate;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 角色控制器
 */
class RoleController extends AdminController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new RoleLogic();
        $this->validate = new RoleValidate;
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('角色数据列表', 'saimulti:coreRole:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['name', ''],
            ['code', ''],
            ['status', ''],
        ]);
        $query = $this->logic->search($where);
        $levelArr = array_column($this->adminInfo['roleList'], 'level');
        $maxLevel = max($levelArr);
        $query->where('level', '<', $maxLevel);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 读取数据
     * @param Request $request
     * @return Response
     */
    #[Permission('角色数据读取', 'saimulti:coreRole:read')]
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
    #[Permission('角色数据添加', 'saimulti:coreRole:save')]
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
    #[Permission('角色数据修改', 'saimulti:coreRole:update')]
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
    #[Permission('角色数据删除', 'saimulti:coreRole:destroy')]
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
     * 根据角色获取菜单
     * @param Request $request
     * @return Response
     */
    #[Permission('角色数据列表', 'saimulti:coreRole:index')]
    public function getMenuByRole(Request $request): Response
    {
        $id = $request->get('id');
        $data = $this->logic->getMenuByRole($id);
        return $this->success($data);
    }

    /**
     * 菜单权限
     * @param Request $request
     * @return Response
     */
    #[Permission('角色菜单权限', 'saimulti:coreRole:menu')]
    public function menuPermission(Request $request): Response
    {
        $id = $request->post('id');
        $menu_ids = $request->post('menu_ids');
        $this->logic->saveMenuPermission($id, $menu_ids);
        return $this->success('操作成功');
    }

    /**
     * 可操作角色
     * @param Request $request
     * @return Response
     */
    public function accessRole(Request $request): Response
    {
        $where = ['status' => 1];
        $data = $this->logic->accessRole($where);
        return $this->success($data);
    }

}
