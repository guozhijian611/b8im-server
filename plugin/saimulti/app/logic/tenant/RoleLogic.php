<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\saimulti\app\logic\tenant;

use plugin\saimulti\app\cache\TenantAuthCache;
use plugin\saimulti\app\cache\TenantUserCache;
use plugin\saimulti\app\model\tenant\Role;
use plugin\saimulti\app\model\tenant\Menu;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\TenantContext;
use plugin\saimulti\service\TenantRoleHierarchyService;
use plugin\saimulti\service\module\TenantRoleMenuPermissionService;
use support\think\Db;

/**
 * 角色逻辑层
 */
class RoleLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new Role();
        parent::__construct();
    }

    /**
     * 添加数据
     */
    public function add($data): bool
    {
        [$organization, $actorUserId] = $this->actorContext();

        return Db::transaction(function () use ($organization, $actorUserId, $data): bool {
            $actorMaxLevel = $this->roleHierarchy()->lockActorMaxLevel($organization, $actorUserId);
            $data = $this->normalizeRoleData($data, $actorMaxLevel);

            return $this->model->save($data);
        });
    }

    /**
     * 修改数据
     */
    public function edit($id, $data): bool
    {
        $roleId = $this->roleId($id);
        [$organization, $actorUserId] = $this->actorContext();
        $result = Db::transaction(function () use ($organization, $actorUserId, $roleId, $data): bool {
            $actorMaxLevel = $this->roleHierarchy()->lockActorMaxLevel($organization, $actorUserId);
            $this->roleHierarchy()->lockManageableRole($organization, $roleId, $actorMaxLevel);
            $data = $this->normalizeRoleData($data, $actorMaxLevel);
            $model = Role::where('id', $roleId)
                ->where('organization', $organization)
                ->lock(true)
                ->findOrEmpty();
            if ($model->isEmpty()) {
                throw new ApiException('数据不存在', 404);
            }

            return $model->save($data);
        });
        if ($result) {
            $this->clearRoleCaches([$roleId]);
        }

        return $result;
    }

    /**
     * 删除数据
     */
    public function destroy($ids): bool
    {
        $roleIds = $this->roleIds($ids);
        [$organization, $actorUserId] = $this->actorContext();
        $result = Db::transaction(function () use ($organization, $actorUserId, $roleIds): bool {
            $actorMaxLevel = $this->roleHierarchy()->lockActorMaxLevel($organization, $actorUserId);
            foreach ($roleIds as $roleId) {
                $this->roleHierarchy()->lockManageableRole($organization, $roleId, $actorMaxLevel);
            }

            return Role::destroy($roleIds);
        });
        if ($result) {
            $this->clearRoleCaches($roleIds);
        }

        return $result;
    }

    /**
     * 可操作角色
     * @param array $where
     * @return array
     */
    public function accessRole(array $where = []): array
    {
        $query = $this->search($where);
        // 越权保护
        $levelArr = array_column($this->tenantInfo['roleList'], 'level');
        $maxLevel = max($levelArr);
        $query->where('level', '<', $maxLevel);
        $query->order('sort', 'desc');
        return $this->getAll($query);
    }

    /**
     * 根据角色数组获取菜单
     * @param $ids
     * @return array
     */
    public function getMenuIdsByRoleIds($ids): array
    {
        if (empty($ids))
            return [];
        return $this->model->where('id', 'in', $ids)->with([
            'menus' => function ($query) {
                $query->where('status', 1)->order('sort', 'desc');
            }
        ])->select()->toArray();

    }

    /**
     * 根据角色获取菜单
     * @param $id
     * @return array
     */
    public function getMenuByRole($id): array
    {
        [$organization, $actorUserId] = $this->actorContext();
        $menuIds = (new TenantRoleMenuPermissionService())->assignedIds(
            $organization,
            $actorUserId,
            (int) $id,
        );
        $menus = $menuIds === []
            ? []
            : Menu::whereIn('id', $menuIds)->order('sort', 'desc')->select()->toArray();

        return [
            'id' => (int) $id,
            'menus' => $menus
        ];
    }

    /**
     * 保存菜单权限
     * @param $id
     * @param $menu_ids
     * @return mixed
     */
    public function saveMenuPermission($id, $menu_ids): mixed
    {
        [$organization, $actorUserId] = $this->actorContext();

        return (new TenantRoleMenuPermissionService())->save(
            $organization,
            $actorUserId,
            (int) $id,
            $menu_ids,
        );
    }

    /** @return array{0: int, 1: int} */
    private function actorContext(): array
    {
        $identity = getTenantInfo();
        if (!is_array($identity)
            || ($identity['plat'] ?? null) !== 'tenant'
            || ($identity['aud'] ?? null) !== 'tenant-api') {
            throw new ApiException('租户登录凭证无效。', 401);
        }
        $actorUserId = $this->roleId($identity['id'] ?? null, '租户登录凭证缺少用户标识。');
        $organization = TenantContext::organization();
        if (TenantContext::parseOrganization($identity['organization'] ?? null) !== $organization) {
            throw new ApiException('登录凭证与租户上下文不一致。', TenantContext::MISMATCH);
        }

        return [$organization, $actorUserId];
    }

    private function normalizeRoleData(mixed $data, int $actorMaxLevel): array
    {
        if (!is_array($data)) {
            throw new ApiException('角色数据格式无效。', 422);
        }
        if (hash_equals('superAdmin', trim((string) ($data['code'] ?? '')))) {
            throw new ApiException('superAdmin 是系统保留角色标识。', 403);
        }
        $data['level'] = $this->roleHierarchy()->normalizeNewLevel($data['level'] ?? null, $actorMaxLevel);
        unset($data['id'], $data['organization'], $data['delete_time']);

        return $data;
    }

    private function roleId(mixed $id, string $message = '租户角色编号无效。'): int
    {
        if (is_int($id) && $id > 0) {
            return $id;
        }
        if (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id) === 1) {
            return (int) $id;
        }

        throw new ApiException($message, 422);
    }

    /** @return list<int> */
    private function roleIds(mixed $ids): array
    {
        $ids = is_array($ids) ? $ids : [$ids];
        if (!array_is_list($ids) || $ids === []) {
            throw new ApiException('租户角色编号必须为非空数组。', 422);
        }

        return array_values(array_unique(array_map(fn (mixed $id): int => $this->roleId($id), $ids)));
    }

    /** @param list<int> $roleIds */
    private function clearRoleCaches(array $roleIds): void
    {
        TenantUserCache::clearUserInfoByRoleId($roleIds);
        TenantAuthCache::clearUserAuthByRoleId($roleIds);
    }

    private function roleHierarchy(): TenantRoleHierarchyService
    {
        return new TenantRoleHierarchyService();
    }
}
