<?php
namespace plugin\saimulti\app\controller\admin;

use plugin\saimulti\app\cache\TenantAuthCache;
use plugin\saimulti\app\cache\TenantUserCache;
use plugin\saimulti\app\logic\admin\UserLogic;
use plugin\saimulti\app\model\tenant\Role;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;

/**
 * 租户用户控制器
 */
class SystemUserController extends AdminController
{
    public function __construct()
    {
        $this->logic = new UserLogic();
        parent::__construct();
    }

    /**
     * 用户列表
     * @param Request $request
     * @return Response
     */
    #[Permission('用户信息列表', 'saimulti:admin:user:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['organization', ''],
            ['dept_id', ''],
            ['email', ''],
            ['username', ''],
            ['phone', ''],
            ['status', ''],
            ['create_time', ''],
        ]);
        $query = $this->logic->search($where)->with(['organ']);
        $data = $this->logic->getList($query);
        return $this->success($data);
    }

    /**
     * 读取数据
     * @param Request $request
     * @return Response
     */
    #[Permission('用户信息读取', 'saimulti:admin:user:read')]
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
    #[Permission('用户信息保存', 'saimulti:admin:user:save')]
    public function save(Request $request) : Response
    {
        $data = $request->post();
        // 检查角色是否存在

        $organization = $data['organization'];
        $model = new Role();
        $info = $model->where('organization', $organization)->findOrEmpty();
        if ($info->isEmpty()) {
            return $this->fail('机构角色查找失败，请初始化该机构');
        }
        $data['role_ids'] = [$info->id];
        $result = $this->logic->add($data);
        if ($result) {
            return $this->success('操作成功');
        } else {
            return $this->fail('操作失败');
        }
    }

    /**
     * 更新数据
     * @param Request $request
     * @return Response
     */
    #[Permission('用户信息修改', 'saimulti:admin:user:update')]
    public function update(Request $request): Response
    {
        $data = $request->post();
        $this->validate('update', $data);
        unset($data['password']);
        unset($data['password_confirm']);
        $result = $this->logic->where('id', $data['id'])->update($data);
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
    #[Permission('用户信息删除', 'saimulti:admin:user:destroy')]
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
     * 清理用户缓存
     * @param Request $request
     * @return Response
     */
    #[Permission('用户信息缓存清理', 'saimulti:admin:user:cache')]
    public function clearCache(Request $request) : Response
    {
        $id = $request->post('id', '');
        TenantUserCache::clearUserInfo($id);
        TenantAuthCache::clearUserAuth($id);
        return $this->success('操作成功');
    }

    /**
     * 重置密码
     * @param Request $request
     * @return Response
     */
    #[Permission('用户信息密码重置', 'saimulti:admin:user:reset')]
    public function initUserPassword(Request $request) : Response
    {
        $id = $request->post('id', '');
        $password = $request->post('password', 'sai123456');
        $data = ['password' => password_hash($password, PASSWORD_DEFAULT)];
        $this->logic->where('id', $id)->update($data);
        return $this->success('操作成功');
    }

}