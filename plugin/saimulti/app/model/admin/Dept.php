<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\admin;

use plugin\saimulti\basic\BaseModel;

/**
 * 菜单模型
 */
class Dept extends BaseModel
{
    // 完整数据库表名称
    protected $table  = 'sm_admin_dept';
    // 主键
    protected $pk = 'id';

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

}