<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\admin;

use plugin\saimulti\basic\BaseModel;

/**
 * 角色模型
 * Class Role
 */
class Role extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sm_admin_role';

    /**
     * 通过中间表获取菜单
     */
    public function menus()
    {
        return $this->belongsToMany(Menu::class, RoleMenu::class, 'menu_id', 'role_id');
    }
}