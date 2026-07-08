<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\admin;

use think\model\Pivot;

/**
 * 关联模型
 * Class SystemUserRole
 * @package app\model
 */
class UserRole extends Pivot
{
    protected $table = 'sm_admin_user_role';

    /**
     * 获取角色id
     * @param mixed $user_id
     * @return array
     */
    public static function getRoleIds($user_id): array
    {
        return static::where('user_id', $user_id)->column('role_id');
    }
}
