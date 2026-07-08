<?php
// +----------------------------------------------------------------------
// | saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\cache;

use plugin\saimulti\app\model\admin\Admin;
use support\think\Cache;

/**
 * 用户信息缓存
 */
class AdminUserCache
{
    /**
     * 读取缓存配置
     * @return array
     */
    public static function cacheConfig(): array
    {
        return config('plugin.saimulti.saithink.admin_cache', [
            'prefix' => 'saimulti:admin_cache:info_',
            'expire' => 60 * 60 * 4,
            'dept' => 'saimulti:admin_cache:dept_',
            'role' => 'saimulti:admin_cache:role_',
        ]);
    }

    /**
     * 通过id获取缓存管理员信息
     */
    public static function getUserInfo($uid): array
    {
        if (empty($uid)) {
            return [];
        }
        $cache = static::cacheConfig();
        // 直接从缓存获取
        $adminInfo = Cache::get($cache['prefix'] . $uid);

        if ($adminInfo) {
            return $adminInfo;
        }

        // 获取缓存信息并返回
        $adminInfo = static::setUserInfo($uid);
        if ($adminInfo) {
            return $adminInfo;
        }

        return [];
    }

    /**
     * 设置管理员信息
     */
    public static function setUserInfo($uid): array
    {
        $admin = Admin::findOrEmpty($uid);
        $data = $admin->hidden(['password'])->toArray();
        $data['roleList'] = $admin->roles->toArray() ?: [];
        $data['deptList'] = $admin->depts ? $admin->depts->toArray() : [];
        $cache = static::cacheConfig();

        $tags = [];
        if (!empty($data['deptList'])) {
            $tags[] = $cache['dept'] . $data['deptList']['id'];
        }
        if (!empty($data['roleList'])) {
            foreach ($data['roleList'] as $role) {
                $tags[] = $cache['role'] . $role['id'];
            }
        }
        Cache::tag($tags)->set($cache['prefix'] . $uid, $data, $cache['expire']);
        return $data;
    }

    /**
     * 清理管理员信息缓存
     */
    public static function clearUserInfo($uid): bool
    {
        $cache = static::cacheConfig();
        return Cache::delete($cache['prefix'] . $uid);
    }

    /**
     * 清理部门下所有用户缓存
     */
    public static function clearUserInfoByDeptId($dept_id): bool
    {
        $cache = static::cacheConfig();
        if (is_array($dept_id)) {
            $tags = [];
            foreach ($dept_id as $id) {
                $tags[] = $cache['dept'] . $id;
            }
        } else {
            $tags = $cache['dept'] . $dept_id;
        }
        return Cache::tag($tags)->clear();
    }

    /**
     * 清理角色下所有用户缓存
     */
    public static function clearUserInfoByRoleId($role_id): bool
    {
        $cache = static::cacheConfig();
        if (is_array($role_id)) {
            $tags = [];
            foreach ($role_id as $id) {
                $tags[] = $cache['role'] . $id;
            }
        } else {
            $tags = $cache['role'] . $role_id;
        }
        return Cache::tag($tags)->clear();
    }

}
