<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tenant;

use plugin\saimulti\basic\TenantModel;

/**
 * 部门模型
 * Class SystemDept
 * @package app\model
 */
class Dept extends TenantModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_tenant_dept';

    /**
     * 权限范围
     */
    public function scopeAuth($query, $value)
    {
        if (!empty($value)) {
            $deptIds = [$value['id']];
            $deptLevel = $value['level'] . $value['id'] . ',';
            $ids = static::whereLike('level', $deptLevel . '%')->column('id');
            $deptIds = array_merge($deptIds, $ids);
            $query->whereIn('id', $deptIds);
        }
    }

    public function leader()
    {
        return $this->belongsToMany(User::class, DeptLeader::class, 'user_id', 'dept_id');
    }

}