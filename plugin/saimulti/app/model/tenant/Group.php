<?php
// +----------------------------------------------------------------------
// | saimulti [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: your name
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\model\tenant;

use plugin\saimulti\basic\BaseModel;

/**
 * 机构分组表模型
 */
class Group extends BaseModel
{

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据库表名称
     * @var string
     */
    protected $table = 'sm_tenant_group';

    public function getOpenModeAttr($value)
    {
        return json_decode($value, true);
    }

    public function setOpenModeAttr($value)
    {
        return json_encode($value);
    }

    /**
     * 分组名称 搜索
     */
    public function searchGroupNameAttr($query, $value)
    {
        $query->where('group_name', 'like', '%'.$value.'%');
    }

    /**
     * 通过中间表获取菜单
     */
    public function menus()
    {
        return $this->belongsToMany(Menu::class, GroupMenu::class, 'menu_id', 'group_id');
    }
}
