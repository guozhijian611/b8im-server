<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tenant;

use plugin\saimulti\basic\TenantModel;

/**
 * 角色模型
 * Class SystemRole
 * @package app\model
 */
class Role extends TenantModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_tenant_role';

    /**
     * 通过中间表获取菜单
     */
    public function menus()
    {
        return $this->belongsToMany(Menu::class, RoleMenu::class, 'menu_id', 'role_id');
    }

}