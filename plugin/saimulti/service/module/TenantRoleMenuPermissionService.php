<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use Closure;
use plugin\saimulti\app\cache\TenantAuthCache;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\TenantRoleHierarchyService;
use support\think\Db;

final class TenantRoleMenuPermissionService
{
    private readonly Closure $cacheInvalidator;

    public function __construct(
        private readonly ?TenantAssignableMenuService $menus = null,
        ?callable $cacheInvalidator = null,
        private readonly ?TenantRoleHierarchyService $hierarchy = null,
    ) {
        $this->cacheInvalidator = $cacheInvalidator === null
            ? static fn (int $roleId): bool => TenantAuthCache::clearUserAuthByRoleId($roleId)
            : Closure::fromCallable($cacheInvalidator);
    }

    /** @return list<int> */
    public function assignedIds(int $organization, int $actorUserId, int $roleId): array
    {
        return Db::transaction(function () use ($organization, $actorUserId, $roleId): array {
            $actorMaxLevel = $this->roleHierarchy()->lockActorMaxLevel($organization, $actorUserId);
            $this->roleHierarchy()->lockManageableRole($organization, $roleId, $actorMaxLevel);
            $assignable = array_fill_keys($this->assignableMenus()->ids($organization), true);
            $assigned = Db::table('sm_tenant_role_menu')
                ->where('role_id', $roleId)
                ->column('menu_id');

            return array_values(array_map('intval', array_filter(
                $assigned,
                static fn (mixed $menuId): bool => isset($assignable[(int) $menuId]),
            )));
        });
    }

    public function save(int $organization, int $actorUserId, int $roleId, mixed $menuIds): bool
    {
        $normalized = $this->normalizeMenuIds($menuIds);

        Db::transaction(function () use ($organization, $actorUserId, $roleId, $normalized): void {
            $actorMaxLevel = $this->roleHierarchy()->lockActorMaxLevel($organization, $actorUserId);
            $this->roleHierarchy()->lockManageableRole($organization, $roleId, $actorMaxLevel);
            $this->assertAssignableSubset($organization, $normalized);
            Db::table('sm_tenant_role_menu')->where('role_id', $roleId)->delete();
            if ($normalized !== []) {
                Db::table('sm_tenant_role_menu')->insertAll(array_map(
                    static fn (int $menuId): array => ['role_id' => $roleId, 'menu_id' => $menuId],
                    $normalized,
                ));
            }
        });

        ($this->cacheInvalidator)($roleId);

        return true;
    }

    /** @param list<int> $menuIds */
    private function assertAssignableSubset(int $organization, array $menuIds): void
    {
        $assignable = array_fill_keys($this->assignableMenus()->ids($organization), true);
        $forbidden = array_values(array_filter(
            $menuIds,
            static fn (int $menuId): bool => !isset($assignable[$menuId]),
        ));
        if ($forbidden !== []) {
            throw new ApiException(sprintf(
                '菜单不属于当前 organization 的可分配范围: %s',
                implode(',', $forbidden),
            ), 403);
        }
    }

    /** @return list<int> */
    private function normalizeMenuIds(mixed $menuIds): array
    {
        if (!is_array($menuIds) || !array_is_list($menuIds)) {
            throw new ApiException('menu_ids 必须为数组。', 422);
        }

        $normalized = [];
        foreach ($menuIds as $menuId) {
            if (is_int($menuId) && $menuId > 0) {
                $normalized[] = $menuId;
                continue;
            }
            if (is_string($menuId) && preg_match('/^[1-9][0-9]*$/', $menuId) === 1) {
                $normalized[] = (int) $menuId;
                continue;
            }
            throw new ApiException('menu_ids 只能包含正整数。', 422);
        }

        return array_values(array_unique($normalized));
    }

    private function assignableMenus(): TenantAssignableMenuService
    {
        return $this->menus ?? new TenantAssignableMenuService();
    }

    private function roleHierarchy(): TenantRoleHierarchyService
    {
        return $this->hierarchy ?? new TenantRoleHierarchyService();
    }
}
