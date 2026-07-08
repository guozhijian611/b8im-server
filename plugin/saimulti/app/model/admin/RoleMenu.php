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
 * Class RoleMenu
 * @package app\model
 */
class RoleMenu extends Pivot
{
    protected $table = 'sm_admin_role_menu';
}