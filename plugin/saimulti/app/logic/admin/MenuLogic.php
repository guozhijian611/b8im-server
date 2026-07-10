<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\admin;

use plugin\saimulti\app\model\admin\Menu;
use plugin\saimulti\app\model\admin\RoleMenu;
use plugin\saimulti\app\model\admin\UserRole;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\utils\Helper;

/**
 * 菜单逻辑层
 */
class MenuLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new Menu();
        parent::__construct();
    }

    /**
     * 数据保存
     */
    public function add($data): mixed
    {
        $data = $this->handleData($data);
        return parent::add($data);
    }

    /**
     * 数据修改
     */
    public function edit($id, $data): mixed
    {
        $data = $this->handleData($data);
        if ($data['parent_id'] == $id) {
            throw new ApiException('不能设置父级为自身');
        }
        return parent::edit($id, $data);
    }

    /**
     * 数据删除
     */
    public function destroy($ids): bool
    {
        $num = $this->model->where('parent_id', 'in', $ids)->count();
        if ($num > 0) {
            throw new ApiException('该菜单下存在子菜单，请先删除子菜单');
        } else {
            return parent::destroy($ids);
        }
    }

    /**
     * 数据处理
     */
    protected function handleData($data)
    {
        if (empty($data['parent_id']) || $data['parent_id'] == 0) {
            $data['level'] = '0';
            $data['parent_id'] = 0;
            $data['type'] = $data['type'] === 3 ? 1 : $data['type'];
        } else {
            $parentMenu = $this->model->findOrEmpty($data['parent_id']);
            $data['level'] = $parentMenu['level'] . ',' . $parentMenu['id'];
        }
        return $data;
    }

    /**
     * 数据树形化
     * @param array $where
     * @param int $group_id
     * @return array
     */
    public function tree($where = [], $group_id = 0): array
    {
        if ($group_id > 0) {
            $query = $this->search($where)->alias('a');
            $this->applyModuleFilter($query, 'a.module_key');
            if (request()->input('tree', 'false') === 'true') {
                $query->field('a.id, a.id as value, a.name as label, a.type, a.parent_id');
            }
            $data = $query->join('sm_tenant_group_menu b', 'a.id=b.menu_id')
                ->where('b.group_id', $group_id)
                ->order('a.sort', 'desc')
                ->select()->toArray();
        } else {
            $query = $this->search($where);
            $this->applyModuleFilter($query);
            if (request()->input('tree', 'false') === 'true') {
                $query->field('id, id as value, name as label, type, parent_id');
            }
            $query->order('sort', 'desc');
            $data = $this->getAll($query);
        }
        return Helper::makeTree($data);
    }

    /**
     * 获取全部菜单
     */
    public function getAllMenus(): array
    {
        $query = $this->search(['status' => 1, 'type' => [1, 2, 4]])->order('sort', 'desc');
        $this->applyModuleFilter($query);
        $data = $this->getAll($query);
        return Helper::makeArtdMenus($data);
    }

    /**
     * 获取全部操作code
     */
    public function getAllCode(): array
    {
        $query = $this->search(['type' => 3]);
        $this->applyModuleFilter($query);
        return $query->column('code');
    }

    /**
     * 根据管理员id获取权限
     * @param $id
     * @return array
     */
    public function getAuthByAdminId($id): array
    {
        $roleIds = UserRole::where('user_id', $id)->column('role_id');
        $menuId = RoleMenu::whereIn('role_id', $roleIds)->column('menu_id');

        return Menu::distinct(true)
            ->where('type', 3)
            ->where('status', 1)
            ->where('id', 'in', array_unique($menuId))
            ->where(function ($query) {
                $this->moduleWhere($query);
            })
            ->column('code');
    }

    /**
     * 获取全部权限
     * @return array
     */
    public function getAllAuth(): array
    {
        return Menu::where('type', 'in', [2, 3])
            ->where('status', 1)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where(function ($query) {
                $this->moduleWhere($query);
            })
            ->column('slug');
    }

    /**
     * 根据角色获取权限
     * @param $roleIds
     * @return array
     */
    public function getAuthByRole($roleIds): array
    {
        $menuId = RoleMenu::whereIn('role_id', $roleIds)->column('menu_id');

        return Menu::distinct(true)
            ->where('type', 'in', [2, 3])
            ->where('status', 1)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where('id', 'in', array_unique($menuId))
            ->where(function ($query) {
                $this->moduleWhere($query);
            })
            ->column('slug');
    }

    /**
     * 根据角色获取菜单
     * @param $roleIds
     * @return array
     */
    public function getMenuByRole($roleIds): array
    {
        $menuId = RoleMenu::whereIn('role_id', $roleIds)->column('menu_id');

        $data = Menu::distinct(true)
            ->where('status', 1)
            ->where('type', 'in', [1, 2, 4])
            ->where('id', 'in', array_unique($menuId))
            ->where(function ($query) {
                $this->moduleWhere($query);
            })
            ->order('sort', 'desc')
            ->select()
            ->toArray();
        return Helper::makeArtdMenus($data);
    }

    private function applyModuleFilter($query, string $field = 'module_key'): void
    {
        $query->where(function ($nested) use ($field) {
            $this->moduleWhere($nested, $field);
        });
    }

    private function moduleWhere($query, string $field = 'module_key'): void
    {
        $enabled = ModuleServiceFactory::access()->enabledSystemModuleKeys('admin');
        $query->whereNull($field);
        if ($enabled !== []) {
            $query->whereIn($field, $enabled, 'OR');
        }
    }

}
