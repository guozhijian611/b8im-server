<?php
namespace plugin\saimulti\app\logic\admin;

use plugin\saimulti\app\cache\TenantUserCache;
use plugin\saimulti\app\cache\TenantAuthCache;
use plugin\saimulti\app\model\tenant\User;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;

/**
 * 租户端用户逻辑层
 */
class UserLogic extends BaseLogic
{

    protected string $orderField = 'id';

    protected string $orderType = 'DESC';

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
     * 数据添加
     * @param $data
     * @return mixed
     */
    public function add($data): mixed
    {
        return $this->transaction(function () use ($data) {
            $role_ids = $data['role_ids'] ?? [];
            $post_ids = $data['post_ids'] ?? [];
            $user = User::create($data);
            $user->roles()->detach();
            $user->roles()->saveAll($role_ids);
            $user->posts()->detach();
            if (!empty($post_ids)) {
                $user->posts()->saveAll($post_ids);
            }
            return $user->getKey();
        });
    }

    public function edit($id, $data): mixed
    {
        return $this->transaction(function () use ($data, $id) {
            $role_ids = $data['role_ids'] ?? [];
            $post_ids = $data['post_ids'] ?? [];


            $user = $this->model->where('id', $id)->findOrEmpty();
            if ($user->isEmpty()) {
                throw new ApiException('没有权限操作该数据');
            }
            $result = parent::edit($id, $data);
            if ($result && !$user->isEmpty()) {
                $user->roles()->detach();
                $user->posts()->detach();
                $user->roles()->saveAll($role_ids);
                if (!empty($post_ids)) {
                    $user->posts()->saveAll($post_ids);
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
        TenantUserCache::clearUserInfo($ids);
        TenantAuthCache::clearUserAuth($ids);
        return parent::destroy($ids);
    }

}