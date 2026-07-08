<?php
// +----------------------------------------------------------------------
// | saimulti [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: your name
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tenant;

use plugin\saimulti\app\model\tenant\Group;
use plugin\saimulti\basic\BaseLogic;
use think\facade\Db;

/**
 * 机构分组表逻辑层
 */
class GroupLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new Group();
    }

    /**
     * 根据分组获取菜单
     * @param $id
     * @return array
     */
    public function getMenuByGroup($id): array
    {
        $group = $this->model->findOrEmpty($id);
        $menus = $group->menus ?: [];
        return [
            'id' => $id,
            'menus' => $menus
        ];
    }

    /**
     * 更新分组菜单
     * @param $id
     * @param $menu_ids
     * @return mixed
     */
    public function updateMenuGroup($id, $menu_ids)
    {
        return $this->transaction(function () use ($id, $menu_ids) {
            $role = $this->model->findOrEmpty($id);
            if (!$role->isEmpty()) {
                $role->menus()->detach();
                $data = array_map(function($menu_id) use ($id) {
                    return ['menu_id' => $menu_id, 'group_id' => $id];
                }, $menu_ids);
                Db::name('sm_tenant_group_menu')->limit(100)->insertAll($data);
            }
            return true;
        });
    }
}
