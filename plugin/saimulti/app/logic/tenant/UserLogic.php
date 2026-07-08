<?php
namespace plugin\saimulti\app\logic\tenant;

use plugin\saimulti\app\cache\TenantUserCache;
use plugin\saimulti\app\cache\TenantAuthCache;
use plugin\saimulti\app\model\tenant\User;
use plugin\saimulti\app\model\tenant\Dept;
use plugin\saimulti\app\model\tenant\Role;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use Tinywan\Jwt\JwtToken;
use Webman\Event\Event;

/**
 * 租户管理员逻辑层
 */
class UserLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new User();
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
        $query->auth($this->tenantInfo['deptList']);
        return $this->getList($query);
    }

    /**
     * 读取数据
     * @param $id
     * @return array
     */
    public function read($id): array
    {
        $user = $this->model->findOrEmpty($id);
        $data = $user->hidden(['password'])->toArray();
        $data['roleList'] = $user->roles->toArray() ?: [];
        $data['postList'] = $user->posts->toArray() ?: [];
        $data['deptList'] = $user->depts;
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
        $organization = request()->header('App-Id');
        $tenantInfo = $this->model->where('username', $username)->where('organization', $organization)->findOrEmpty();
        $status = 1;
        $message = '登录成功';
        if ($tenantInfo->isEmpty()) {
            $message = '账号或密码错误，请重新输入!';
            throw new ApiException($message);
        }
        if ($tenantInfo->status === 2) {
            $status = 0;
            $message = '您已被禁止登录!';
        }
        if (!password_verify($password, $tenantInfo->password)) {
            $status = 0;
            $message = '账号或密码错误，请重新输入!';
        }
        if ($status === 0) {
            // 登录事件
            Event::emit('tenant.login', compact('username','status', 'message'));
            throw new ApiException($message);
        }

        $tenantInfo->login_time = date('Y-m-d H:i:s');
        $tenantInfo->login_ip = request()->getRealIp();
        $tenantInfo->save();

        $access_exp = config('plugin.saimulti.saithink.jwt.tenant_expire', 3600 * 4);
        $token = JwtToken::generateToken([
            'access_exp' => $access_exp,
            'id' => $tenantInfo->id,
            'username' => $tenantInfo->username,
            'user_type' => $tenantInfo->user_type,
            'plat' => 'tenant',
            'type' => $type
        ]);
        // 登录事件
        Event::emit('tenant.login', compact('username','status', 'message'));
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
            $post_ids = $data['post_ids'] ?? [];
            if ($this->tenantInfo['user_type'] != 100) {
                // 部门保护
                if (!$this->deptProtect($this->tenantInfo['deptList'], $data['dept_id'])) {
                    throw new ApiException('没有权限操作该部门数据');
                }
                // 越权保护
                if (!$this->roleProtect($this->tenantInfo['roleList'], $role_ids)) {
                    throw new ApiException('没有权限操作该角色数据');
                }
            }
            $user = User::create($data);
            $user->roles()->detach();
            $user->roles()->saveAll($role_ids);
            $user->posts()->detach();
            if (!empty($post_ids)) {
                $user->posts()->save($post_ids);
            }
            return $user->getKey();
        });
    }

    public function edit($id, $data): mixed
    {
        unset($data['password']);
        return $this->transaction(function () use ($data, $id) {
            $role_ids = $data['role_ids'] ?? [];
            $post_ids = $data['post_ids'] ?? [];

            // 仅可修改当前部门和子部门的用户
            $query = $this->model->where('id', $id);
            $query->auth($this->tenantInfo['deptList']);
            $user = $query->findOrEmpty();
            if ($user->isEmpty()) {
                throw new ApiException('没有权限操作该数据');
            }
            if ($this->tenantInfo['user_type'] != 100) {
                // 部门保护
                if (!$this->deptProtect($this->tenantInfo['deptList'], $data['dept_id'])) {
                    throw new ApiException('没有权限操作该部门数据');
                }
                // 越权保护
                if (!$this->roleProtect($this->tenantInfo['roleList'], $role_ids)) {
                    throw new ApiException('没有权限操作该角色数据');
                }
            }
            $result = parent::edit($id, $data);
            if ($result && !$user->isEmpty()) {
                $user->roles()->detach();
                $user->posts()->detach();
                $user->roles()->saveAll($role_ids);
                if (!empty($post_ids)) {
                    $user->posts()->save($post_ids);
                }
            }
            TenantUserCache::clearUserInfo($id);
            TenantAuthCache::clearUserAuth($id);
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
        $model = $this->model->findOrEmpty($ids);
        if ($model->isEmpty()) {
            throw new ApiException('数据不存在');
        }
        if ($model->user_type == 100) {
            throw new ApiException('超级管理员禁止删除');
        }
        TenantUserCache::clearUserInfo($ids);
        TenantAuthCache::clearUserAuth($ids);
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
    public function modifyPassword($adminId, $oldPassword, $newPassword)
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
            $query = User::where('id', $id);
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
