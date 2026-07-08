<?php
namespace plugin\saimulti\app\logic\system;

use plugin\saimulti\app\model\system\SystemOrganization;
use plugin\saimulti\app\model\tenant\Role;
use plugin\saimulti\app\model\tenant\User;
use plugin\saimulti\app\model\tenant\UserRole;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;

/**
 * 单位信息逻辑层
 */
class SystemOrganizationLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new SystemOrganization();
    }

    public function initTenant($id)
    {
        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            throw new ApiException('未找到该机构信息');
        }
        if ($info->is_init === 1) {
            throw new ApiException('该租户已初始化过，无需再次初始化');
        }
        $this->transaction(function() use ($info) {
            // 1. 创建角色
            $role = Role::create([
                'organization' => $info->id,
                'parent_id' => 0,
                'level' => 100,
                'name' => '超级管理员',
                'code' => 'superAdmin',
                'status' => 1,
                'sort' => 1,
                'remark' => '系统内置角色，不可删除'
            ]);
            // 2. 创建超级管理员
            $user = User::create([
                'organization' => $info->id,
                'username' => 'admin',
                'nickname' => $info->organization_name,
                'user_type' => '100',
                'password' => password_hash('sa123456@', PASSWORD_DEFAULT),
                'status' => 1,
                'dashboard' => 'statistics'
            ]);
            // 3. 创建对应关系
            UserRole::create([
                'user_id' => $user->id,
                'role_id' => $role->id
            ]);
            // 4. 更新初始化状态
            $info->is_init = 1;
            $info->save();
        });
    }

    public function tenant($id): array
    {
        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            throw new ApiException('未找到该应用');
        }
        if ($info->status !== 1) {
            throw new ApiException('当前应用已关闭,暂时无法访问！');
        }
        return $info->toArray();
    }

    public function add($data): bool
    {
        // $region = $data['region'];
        // if (is_array($region)) {
        //     $data['province'] = $region['province'];
        //     $data['city'] = $region['city'];
        //     $data['area'] = $region['area'];
        // }
        return $this->model->save($data);
    }

    public function edit($id, $data): mixed
    {
        // $region = $data['region'];
        // if (is_array($region)) {
        //     $data['province'] = $region['province'];
        //     $data['city'] = $region['city'];
        //     $data['area'] = $region['area'];
        // }
        return $this->model->update($data, ['id' => $id]);
    }

    public function appInfo($id, $mode): array
    {
        if ($mode === 'domain') {
            $info = $this->model->field('id, title, logo, status, domain, group_id')->where('domain', $id)->findOrEmpty();
        } else {
            $info = $this->model->field('id, title, logo, status, domain, group_id')->findOrEmpty($id);
        }
        if ($info->isEmpty()) {
            throw new ApiException('未找到该应用');
        }
        if ($info->status !== 1) {
            throw new ApiException('当前应用已关闭,暂时无法访问！');
        }
        $data = $info->toArray();
        return $data;
    }
}