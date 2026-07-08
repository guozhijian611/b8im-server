<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\admin;

use plugin\saimulti\basic\BaseModel;

/**
 * 系统管理员模型
 */
class Admin extends BaseModel
{
    // 完整数据库表名称
    protected $table = 'sm_admin';
    // 主键
    protected $pk = 'id';

    public function getBackendSettingAttr($value)
    {
        return json_decode($value ?? '', true);
    }

    public function setBackendSettingAttr($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function scopeAuth($query, $value)
    {
        if (!empty($value)) {
            $deptIds = [$value['id']];
            $deptLevel = $value['level'] . $value['id'] . ',';
            $dept_ids = Dept::whereLike('level', $deptLevel . '%')->column('id');
            $deptIds = array_merge($deptIds, $dept_ids);
            $query->whereIn('dept_id', $deptIds);
        }
    }

    /**
     * 根据角色id进行搜索
     */
    public function searchRoleIdAttr($query, $value)
    {
        $query->whereRaw('id in (SELECT user_id FROM sm_system_user_role WHERE role_id =?)', [$value]);
    }

    /**
     * 通过中间表关联角色
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, UserRole::class, 'role_id', 'user_id');
    }

    /**
     * 关联部门
     */
    public function depts()
    {
        return $this->belongsTo(Dept::class, 'dept_id', 'id');
    }
}