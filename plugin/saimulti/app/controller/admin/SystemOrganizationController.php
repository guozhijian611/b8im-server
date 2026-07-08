<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\app\logic\system\SystemOrganizationLogic;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 单位信息控制器
 */
class SystemOrganizationController extends AdminController
{
    /**
     * 构造
     */
    public function __construct()
    {
        $this->logic = new SystemOrganizationLogic();
        parent::__construct();
    }

    /**
     * 数据列表
     * @param Request $request
     * @return Response
     */
    #[Permission('机构信息表列表', 'saimulti:admin:organization:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['group_id', ''],
            ['organization_name', ''],
            ['create_time', ''],
        ]);
        $query = $this->logic->search($where);
        $query->with(['groupInfo']);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 读取数据
     * @param Request $request
     * @return Response
     */
    #[Permission('机构信息表读取', 'saimulti:admin:organization:read')]
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
    #[Permission('机构信息表添加', 'saimulti:admin:organization:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $this->validate('save', $data);
        $region = $data['region'];
        if (is_array($region)) {
            $data['province'] = $region[0] ?? '';
            $data['city'] = $region[1] ?? '';
            $data['area'] = $region[2] ?? '';
        }
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
    #[Permission('机构信息表修改', 'saimulti:admin:organization:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        $region = $data['region'];
        if (is_array($region)) {
            $data['province'] = $region[0] ?? '';
            $data['city'] = $region[1] ?? '';
            $data['area'] = $region[2] ?? '';
        }
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
    #[Permission('机构信息表删除', 'saimulti:admin:organization:destroy')]
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
     * 初始化机构
     * @param Request $request
     * @return Response
     */
    #[Permission('机构信息表初始化', 'saimulti:admin:organization:init')]
    public function initTenant(Request $request): Response
    {
        $id = $request->input('id', '');
        $this->logic->initTenant($id);
        return $this->success('操作成功');
    }
}
