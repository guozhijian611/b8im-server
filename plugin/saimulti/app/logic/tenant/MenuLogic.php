<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tenant;

use plugin\saimulti\app\model\tenant\Menu;
use plugin\saimulti\app\model\tenant\RoleMenu;
use plugin\saimulti\app\model\tenant\UserRole;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\TenantContext;
use plugin\saimulti\service\module\TenantAssignableMenuService;
use plugin\saimulti\utils\Helper;
use support\think\Db;

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
     * @return array
     */
    public function tree($where = []): array
    {
        $ids = $this->assignableIds();
        if ($ids === []) {
            return [];
        }

        $query = $this->search($where)->whereIn('id', $ids);
        if (request()->input('tree', 'false') === 'true') {
            $query->field('id, id as value, name as label, type, parent_id');
        }
        $query->order('sort', 'desc');
        $data = $this->getAll($query);

        return Helper::makeTree($data);
    }

    /**
     * 获取全部菜单
     */
    public function getAllMenus(): array
    {
        $ids = $this->assignableIds();
        if ($ids === []) {
            return [];
        }

        $data = $this->model->whereIn('id', $ids)
            ->whereIn('type', [1, 2, 4])
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->select()->toArray();

        return Helper::makeArtdMenus($data);
    }

    /**
     * 获取全部操作code
     */
    public function getAllCode(): array
    {
        $ids = $this->assignableIds();

        return $ids === [] ? [] : $this->search(['type' => 3])->whereIn('id', $ids)->column('code');
    }

    /**
     * 根据管理员id获取权限
     * @param $id
     * @return array
     */
    public function getAuthByAdminId($id): array
    {
        $roleIds = UserRole::where('user_id', $id)->column('role_id');
        $menuId = $this->assignedMenuIds($roleIds);
        if ($menuId === []) {
            return [];
        }

        return Menu::distinct(true)
            ->where('type', 3)
            ->where('status', 1)
            ->whereIn('id', $menuId)
            ->column('code');
    }

    /**
     * 获取全部权限
     * @return array
     */
    public function getAllAuth(): array
    {
        $ids = $this->assignableIds();
        if ($ids === []) {
            return [];
        }

        return Menu::whereIn('id', $ids)
            ->where('type', 'in', [2, 3])
            ->where('status', 1)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->column('slug');
    }

    /**
     * 根据角色获取权限
     * @param $roleIds
     * @return array
     */
    public function getAuthByRole($roleIds): array
    {
        $menuId = $this->assignedMenuIds((array) $roleIds);
        if ($menuId === []) {
            return [];
        }

        // 模块页面菜单本身可以承载列表/API slug。角色勾选页面菜单后，
        // 该 slug 必须与按钮 slug 一起进入鉴权缓存，否则会出现菜单可见、
        // 列表接口却被 CheckTenantAuth 拒绝的断链。
        return Menu::distinct(true)
            ->where('type', 'in', [2, 3])
            ->where('status', 1)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->whereIn('id', $menuId)
            ->column('slug');
    }

    /**
     * 根据角色获取菜单
     * @param $roleIds
     * @return array
     */
    public function getMenuByRole($roleIds): array
    {
        $menuId = $this->assignedMenuIds((array) $roleIds);
        if ($menuId === []) {
            return [];
        }

        $data = Menu::distinct(true)
            ->where('status', 1)
            ->where('type', 'in', [1, 2, 4])
            ->whereIn('id', $menuId)
            ->order('sort', 'desc')
            ->select()
            ->toArray();
        return Helper::makeArtdMenus($data);
    }

    /** @return list<int> */
    private function assignableIds(): array
    {
        return (new TenantAssignableMenuService())->ids(TenantContext::organization());
    }

    /** @param list<int|string> $roleIds @return list<int> */
    private function assignedMenuIds(array $roleIds): array
    {
        $organization = TenantContext::organization();
        $normalizedRoleIds = array_values(array_unique(array_filter(
            array_map('intval', $roleIds),
            static fn (int $roleId): bool => $roleId > 0,
        )));
        if ($normalizedRoleIds === []) {
            return [];
        }

        $currentRoleIds = Db::table('sm_tenant_role')
            ->where('organization', $organization)
            ->whereIn('id', $normalizedRoleIds)
            ->whereNull('delete_time')
            ->column('id');
        if ($currentRoleIds === []) {
            return [];
        }

        $assignable = array_fill_keys($this->assignableIds(), true);

        return array_values(array_unique(array_map('intval', array_filter(
            RoleMenu::whereIn('role_id', $currentRoleIds)->column('menu_id'),
            static fn (mixed $menuId): bool => isset($assignable[(int) $menuId]),
        ))));
    }

}
