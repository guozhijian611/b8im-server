<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tenant;

use think\model\Pivot;

/**
 * 关联模型
 * Class SystemDeptLeader
 * @package app\model
 */
class DeptLeader extends Pivot
{
    protected $table = 'sm_tenant_dept_leader';
}