<?php
// +----------------------------------------------------------------------
// | saimulti [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: your name
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\app\logic\tenant\GroupLogic;
use plugin\saimulti\app\validate\admin\SystemGroupValidate;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 机构分组表控制器
 */
class SystemGroupController extends AdminController
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->logic = new GroupLogic();
        $this->validate = new SystemGroupValidate;
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('机构分组表列表', 'saimulti:admin:group:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['group_name', ''],
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
    #[Permission('机构分组表读取', 'saimulti:admin:group:read')]
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
    #[Permission('机构分组表添加', 'saimulti:admin:group:save')]
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
    #[Permission('机构分组表修改', 'saimulti:admin:group:update')]
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
    #[Permission('机构分组表删除', 'saimulti:admin:group:destroy')]
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
     * 根据分组获取菜单
     * @param Request $request
     * @return Response
     */
    #[Permission('机构分组表菜单', 'saimulti:admin:group:menu')]
    public function getMenuByGroup(Request $request): Response
    {
        $id = $request->input('id');
        $data = $this->logic->getMenuByGroup($id);
        return $this->success($data);
    }

    /**
     * 更新分组菜单
     * @param Request $request
     * @return Response
     */
    #[Permission('机构分组表菜单更新', 'saimulti:admin:group:menu')]
    public function updateMenuGroup(Request $request): Response
    {
        $id = $request->input('id');
        $menu_ids = $request->post('menu_ids');
        $this->logic->updateMenuGroup($id, $menu_ids);
        return $this->success('操作成功');
    }

    #[Permission('套餐模块能力读取', 'saimulti:admin:group:update')]
    public function moduleCapabilities(Request $request): Response
    {
        return $this->success(
            ModuleServiceFactory::tenantAssignments()->groupCatalog(
                (int) $request->input('id', 0),
            ),
        );
    }

    #[Permission('套餐模块能力更新', 'saimulti:admin:group:update')]
    public function updateModuleCapabilities(Request $request): Response
    {
        $moduleKeys = $request->post('module_keys', []);
        if (!is_array($moduleKeys)) {
            return $this->fail('module_keys 必须为数组。');
        }

        return $this->success(
            ModuleServiceFactory::tenantAssignments()->updateGroup(
                (int) $request->input('id', 0),
                array_values($moduleKeys),
                ['type' => 'admin', 'id' => $this->adminId, 'ip' => $request->getRealIp()],
            ),
        );
    }

}
