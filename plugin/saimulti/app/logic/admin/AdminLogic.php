<?php
namespace plugin\saimulti\app\logic\admin;

use plugin\saimulti\app\cache\AdminUserCache;
use plugin\saimulti\app\cache\AdminAuthCache;
use plugin\saimulti\app\model\admin\Admin;
use plugin\saimulti\app\model\admin\Dept;
use plugin\saimulti\app\model\admin\Role;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use Tinywan\Jwt\JwtToken;
use Webman\Event\Event;

/**
 * 管理员逻辑层
 */
class AdminLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new Admin();
        parent::__construct();
    }

    /**
     * 分页数据列表
     * @param mixed $where
     * @return array
     */
    public function indexList($where): array
    {
        $query = $this->search($where);
        $query->with(['depts'])->hidden(['password']);
        $query->auth($this->adminInfo['deptList']);
        return $this->getList($query);
    }

    /**
     * 读取数据
     * @param $id
     * @return array
     */
    public function read($id): array
    {
        $admin = $this->model->findOrEmpty($id);
        $data = $admin->hidden(['password'])->toArray();
        $data['roleList'] = $admin->roles->toArray() ?: [];
        return $data;
    }

    /**
     * 用户登录
     * @param string $username
     * @param string $password
     * @param string $type
     * @return array
     */
    public function login(string $username, string $password, string $type): array
    {
        $adminInfo = $this->model->where('username', $username)->findOrEmpty();
        $status = 1;
        $message = '登录成功';
        if ($adminInfo->isEmpty()) {
            $message = '账号或密码错误，请重新输入!';
            throw new ApiException($message);
        }
        if ($adminInfo->status === 2) {
            $status = 0;
            $message = '您已被禁止登录!';
        }
        if (!password_verify($password, $adminInfo->password)) {
            $status = 0;
            $message = '账号或密码错误，请重新输入!';
        }
        if ($status === 0) {
            // 登录事件
            Event::emit('admin.login', compact('username','status','message'));
            throw new ApiException($message);
        }

        $adminInfo->login_time = date('Y-m-d H:i:s');
        $adminInfo->login_ip = request()->getRealIp();
        $adminInfo->save();

        $access_exp = config('plugin.saimulti.saithink.jwt.admin_expire', 3600 * 2);
        $token = JwtToken::generateToken([
            'access_exp' => $access_exp,
            'id' => $adminInfo->id,
            'username' => $adminInfo->username,
            'plat' => 'admin',
            'type' => $type
        ]);
        // 登录事件
        $admin_id = $adminInfo->id;
        // 登录事件
        Event::emit('admin.login', compact('username','status', 'message', 'admin_id'));
        return $token;
    }

    /**
     * 数据添加
     * @param $data
     * @return mixed
     */
    public function add($data): mixed
    {
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        return $this->transaction(function () use ($data) {
            $role_ids = $data['role_ids'] ?? [];
            if ($this->adminInfo['id'] > 1) {
                // 部门保护
                if (!$this->deptProtect($this->adminInfo['deptList'], $data['dept_id'])) {
                    throw new ApiException('没有权限操作该部门数据');
                }
                // 越权保护
                if (!$this->roleProtect($this->adminInfo['roleList'], $role_ids)) {
                    throw new ApiException('没有权限操作该角色数据');
                }
            }
            $user = Admin::create($data);
            $user->roles()->detach();
            $user->roles()->saveAll($role_ids);
            return $user->getKey();
        });
    }

    /**
     * 数据修改
     * @param $id
     * @param $data
     * @return mixed
     */
    public function edit($id, $data): mixed
    {
        unset($data['password']);
        return $this->transaction(function () use ($data, $id) {
            $role_ids = $data['role_ids'] ?? [];
            // 仅可修改当前部门和子部门的用户
            $query = $this->model->where('id', $id);
            $query->auth($this->adminInfo['deptList']);
            $user = $query->findOrEmpty();
            if ($user->isEmpty()) {
                throw new ApiException('没有权限操作该数据');
            }
            if ($this->adminInfo['id'] > 1) {
                // 部门保护
                if (!$this->deptProtect($this->adminInfo['deptList'], $data['dept_id'])) {
                    throw new ApiException('没有权限操作该部门数据');
                }
                // 越权保护
                if (!$this->roleProtect($this->adminInfo['roleList'], $role_ids)) {
                    throw new ApiException('没有权限操作该角色数据');
                }
            }
            $result = parent::edit($id, $data);
            if ($result && !$user->isEmpty()) {
                $user->roles()->detach();
                $user->roles()->saveAll($role_ids);
            }
            AdminUserCache::clearUserInfo($id);
            AdminAuthCache::clearUserAuth($id);
            return $result;
        });
    }

    /**
     * 删除数据
     * @param $ids
     */
    public function destroy($ids): bool
    {
        if (is_array($ids)) {
            if (count($ids) > 1) {
                throw new ApiException('禁止批量删除操作');
            }
            $ids = $ids[0];
        }
        if ($ids == 1) {
            throw new ApiException('超级管理员禁止删除');
        }
        AdminUserCache::clearUserInfo($ids);
        AdminAuthCache::clearUserAuth($ids);
        return parent::destroy($ids);
    }

    /**
     * 更新资料
     * @param mixed $id
     * @param mixed $data
     * @return bool
     */
    public function updateInfo($id, $data): bool
    {
        $this->model->update($data, ['id' => $id], ['nickname', 'gender', 'phone', 'email', 'avatar', 'signed']);
        return true;
    }

    /**
     * 修改密码
     * @param $adminId
     * @param $oldPassword
     * @param $newPassword
     * @return bool
     */
    public function modifyPassword($adminId, $oldPassword, $newPassword): bool
    {
        $model = $this->model->findOrEmpty($adminId);
        if (password_verify($oldPassword, $model->password)) {
            $model->password = password_hash($newPassword, PASSWORD_DEFAULT);
            return $model->save();
        } else {
            throw new ApiException('原密码错误');
        }
    }

    /**
     * 修改数据
     */
    public function authEdit($id, $data)
    {
        if ($this->adminInfo['id'] > 1) {
            // 判断用户是否可以操作
            $query = Admin::where('id', $id);
            $query->auth($this->adminInfo['deptList']);
            $user = $query->findOrEmpty();
            if ($user->isEmpty()) {
                throw new ApiException('没有权限操作该数据');
            }
        }
        parent::edit($id, $data);
    }

    /**
     * 部门保护
     * @param $dept
     * @param $dept_id
     * @return bool
     */
    public function deptProtect($dept, $dept_id): bool
    {
        // 部门保护
        $deptIds = [$dept['id']];
        $deptLevel = $dept['level'] . $dept['id'] . ',';
        $dept_ids = Dept::whereLike('level', $deptLevel . '%')->column('id');
        $deptIds = array_merge($deptIds, $dept_ids);
        if (!in_array($dept_id, $deptIds)) {
            return false;
        }
        return true;
    }

    /**
     * 越权保护
     * @param $roleList
     * @param $role_ids
     * @return bool
     */
    public function roleProtect($roleList, $role_ids): bool
    {
        // 越权保护
        $levelArr = array_column($roleList, 'level');
        $maxLevel = max($levelArr);
        $currentLevel = Role::whereIn('id', $role_ids)->max('level');
        if ($currentLevel >= $maxLevel) {
            return false;
        }
        return true;
    }

}