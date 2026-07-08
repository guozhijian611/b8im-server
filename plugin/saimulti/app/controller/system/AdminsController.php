<?php
namespace plugin\saimulti\app\controller\system;

use plugin\saimulti\app\cache\AdminUserCache;
use plugin\saimulti\app\cache\AdminAuthCache;
use plugin\saimulti\app\logic\admin\AdminLogic;
use plugin\saimulti\app\logic\admin\MenuLogic;
use plugin\saimulti\app\logic\tool\LoginLogLogic;
use plugin\saimulti\app\logic\tool\OperLogLogic;
use plugin\saimulti\app\model\admin\UserRole;
use plugin\saimulti\basic\AdminController;
use plugin\saimulti\utils\Arr;
use plugin\saimulti\service\Permission;
use support\Request;
use support\Response;


/**
 * 管理员控制器
 */
class AdminsController extends AdminController
{
    public function __construct()
    {
        $this->logic = new AdminLogic();
        parent::__construct();
    }

    /**
     * 用户信息
     */
    public function userInfo(): Response
    {
        $info['user'] = $this->adminInfo;
        $info = [];
        $info['id'] = $this->adminInfo['id'];
        $info['username'] = $this->adminInfo['username'];
        $info['dashboard'] = $this->adminInfo['dashboard'];
        $info['avatar'] = $this->adminInfo['avatar'];
        $info['email'] = $this->adminInfo['email'];
        $info['phone'] = $this->adminInfo['phone'];
        $info['gender'] = $this->adminInfo['gender'] ?? '';
        $info['signed'] = $this->adminInfo['signed'];
        $info['nickname'] = $this->adminInfo['nickname'];
        $info['department'] = $this->adminInfo['deptList'];
        if ($this->adminInfo['id'] === 1) {
            $info['buttons'] = ['*'];
            $info['roles'] = ['super_admin'];
        } else {
            $info['buttons'] = AdminAuthCache::getUserAuth($this->adminInfo['id']);
            $info['roles'] = Arr::getArrayColumn($this->adminInfo['roleList'], 'code');
        }
        return $this->success($info);
    }

    /**
     * 菜单数据
     * @return Response
     */
    public function menu(): Response
    {
        $logic = new MenuLogic();
        $data = [];
        if ($this->adminInfo['id'] == 1) {
            $data = $logic->getAllMenus();
        } else {
            $roleIds = UserRole::getRoleIds($this->adminInfo['id']);
            $data = $logic->getMenuByRole($roleIds);
        }
        return $this->success($data);
    }

    /**
     * 更新资料
     * @param Request $request
     * @return Response
     */
    public function updateInfo(Request $request) : Response
    {
        $data = $request->post();
        unset($data['deptList']);
        unset($data['postList']);
        unset($data['roleList']);
        $result = $this->logic->updateInfo($this->adminId, $data);
        if ($result) {
            AdminUserCache::clearUserInfo($this->adminId);
            return $this->success('操作成功');
        } else {
            return $this->fail('操作失败');
        }
    }

    /**
     * 修改密码
     * @param Request $request
     * @return Response
     */
    public function modifyPassword(Request $request) : Response
    {
        $oldPassword = $request->input('oldPassword');
        $newPassword = $request->input('newPassword');
        $this->logic->modifyPassword($this->adminId, $oldPassword, $newPassword);
        AdminUserCache::clearUserInfo($this->adminId);
        return $this->success('修改成功');
    }

    /**
     * 获取登录日志
     * @return Response
     */
    public function loginList() : Response
    {
        $logic = new LoginLogLogic();
        $query = $logic->search(['organization' => $this->organization, 'username' => $this->adminName]);
        $data = $logic->getList($query);
        return $this->success($data);
    }

    /**
     * 获取操作日志
     * @return Response
     */
    public function operList() : Response
    {
        $logic = new OperLogLogic();
        $query = $logic->search(['organization' => $this->organization, 'username' => $this->adminName])->hidden(['request_data', 'delete_time']);
        $data = $logic->getList($query);
        return $this->success($data);
    }

    /**
     * 用户列表
     * @param Request $request
     * @return Response
     */
    #[Permission('用户数据列表', 'saimulti:admin:index')]
    public function index(Request $request): Response
    {
        $where = $request->more([
            ['dept_id', ''],
            ['email', ''],
            ['username', ''],
            ['phone', ''],
            ['status', ''],
            ['create_time', ''],
        ]);
        $data = $this->logic->indexList($where);
        return $this->success($data);
    }

    /**
     * 读取数据
     * @param Request $request
     * @return Response
     */
    #[Permission('用户数据读取', 'saimulti:admin:read')]
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
    #[Permission('用户数据保存', 'saimulti:admin:save')]
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
    #[Permission('用户数据更新', 'saimulti:admin:update')]
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
    #[Permission('用户数据删除', 'saimulti:admin:destroy')]
    public function destroy(Request $request): Response
    {
        $ids = $request->input('ids', '');
        if (!empty($ids)) {
            $this->logic->destroy($ids);
            return $this->success('操作成功');
        } else {
            return $this->fail('参数错误，请检查');
        }
    }

    /**
     * 清理用户缓存
     * @param Request $request
     * @return Response
     */
    #[Permission('清理用户缓存', 'saimulti:admin:cache')]
    public function clearCache(Request $request) : Response
    {
        $id = $request->post('id', '');
        AdminUserCache::clearUserInfo($id);
        AdminAuthCache::clearUserAuth($id);
        return $this->success('操作成功');
    }

    /**
     * 重置密码
     * @param Request $request
     * @return Response
     */
    #[Permission('修改用户密码', 'saimulti:admin:password')]
    public function initUserPassword(Request $request) : Response
    {
        $id = $request->post('id', '');
        $password = $request->post('password', 'sai123456');
        if ($id == 1) {
            return $this->fail('超级管理员不允许重置密码');
        }
        $data = ['password' => password_hash($password, PASSWORD_DEFAULT)];
        $this->logic->update($data, ['id' => $id]);
        AdminUserCache::clearUserInfo($id);
        return $this->success('操作成功');
    }

    /**
     * 设置首页
     * @param Request $request
     * @return Response
     */
    #[Permission('设置用户首页', 'saimulti:admin:home')]
    public function setHomePage(Request $request) : Response
    {
        $id = $request->post('id', '');
        $dashboard = $request->post('dashboard', '');
        $data = ['dashboard' => $dashboard];
        $this->logic->update($data, ['id' => $id]);
        AdminUserCache::clearUserInfo($id);
        return $this->success('操作成功');
    }

}
