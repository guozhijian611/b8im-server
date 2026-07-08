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
 * Class SystemGroupMenu
 * @package app\model
 */
class GroupMenu extends Pivot
{
    protected $table = 'sm_tenant_group_menu';
}