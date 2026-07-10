<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Manifest\Manifest;
use RuntimeException;
use support\think\Db;

final class ModuleMenuRegistrar
{
    /** @var array<string, int> */
    private array $resolved = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function register(Manifest $manifest): void
    {
        $this->resolved = [];
        $this->resolving = [];
        $definitions = [];
        foreach ($manifest->menus() as $menu) {
            if (in_array($menu['platform'], ['admin', 'tenant'], true)) {
                $definitions[$menu['id']] = $menu;
            }
        }

        $registeredSlugs = [];
        $desired = ['admin' => [], 'tenant' => []];
        foreach ($definitions as $menu) {
            $desired[$menu['platform']][$menu['id']] = true;
            if (!empty($menu['permission'])) {
                $registeredSlugs[$menu['permission']] = true;
            }
        }

        foreach ($manifest->permissions() as $permission) {
            if (isset($registeredSlugs[$permission['slug']])) {
                continue;
            }
            $scope = $permission['scope'] === 'system' ? 'admin' : 'tenant';
            $desired[$scope]['permission:' . $permission['slug']] = true;
        }

        // Remove declarations that disappeared or changed scope before the
        // new desired set is upserted. The caller wraps register() in the same
        // lifecycle transaction, so a later conflict rolls this reconciliation
        // back together with role and group relations.
        $this->reconcileDesired($manifest->moduleKey(), $desired);

        foreach (array_keys($definitions) as $manifestMenuId) {
            $this->registerMenu($manifest, $manifestMenuId, $definitions);
        }

        foreach ($manifest->permissions() as $permission) {
            if (isset($registeredSlugs[$permission['slug']])) {
                continue;
            }
            $scope = $permission['scope'] === 'system' ? 'admin' : 'tenant';
            $parentId = $this->firstPageMenuId($manifest->moduleKey(), $scope);
            $this->upsertMenu(
                moduleKey: $manifest->moduleKey(),
                scope: $scope,
                manifestMenuId: 'permission:' . $permission['slug'],
                data: [
                    'parent_id' => $parentId,
                    'name' => $permission['name'],
                    'code' => null,
                    'slug' => $permission['slug'],
                    'module_key' => $manifest->moduleKey(),
                    'type' => 3,
                    'path' => null,
                    'component' => null,
                    'method' => null,
                    'icon' => null,
                    'sort' => 0,
                    'is_hidden' => 1,
                    'status' => 1,
                    'remark' => $permission['description'] ?? null,
                ],
                permissionSlug: $permission['slug'],
            );
        }

    }

    public function unregister(string $moduleKey): void
    {
        $mappings = Db::table('sm_module_menu_mapping')
            ->where('module_key', $moduleKey)
            ->select()
            ->toArray();

        $this->removeMappings($mappings);
    }

    /**
     * @param array{admin: array<string, true>, tenant: array<string, true>} $desired
     */
    private function reconcileDesired(string $moduleKey, array $desired): void
    {
        $mappings = Db::table('sm_module_menu_mapping')
            ->where('module_key', $moduleKey)
            ->select()
            ->toArray();
        $stale = array_values(array_filter(
            $mappings,
            static fn (array $mapping): bool => !isset(
                $desired[(string) $mapping['scope']][(string) $mapping['manifest_menu_id']],
            ),
        ));
        $this->removeMappings($stale);
    }

    /** @param list<array<string, mixed>> $mappings */
    private function removeMappings(array $mappings): void
    {
        if ($mappings === []) {
            return;
        }

        foreach (['admin', 'tenant'] as $scope) {
            $ids = array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['menu_id'],
                array_filter($mappings, static fn (array $row): bool => $row['scope'] === $scope),
            )));
            if ($ids === []) {
                continue;
            }

            $roleMenuTable = $scope === 'admin' ? 'sm_admin_role_menu' : 'sm_tenant_role_menu';
            Db::table($roleMenuTable)->whereIn('menu_id', $ids)->delete();
            if ($scope === 'tenant') {
                Db::table('sm_tenant_group_menu')->whereIn('menu_id', $ids)->delete();
            }
            Db::table($this->menuTable($scope))->whereIn('id', $ids)->delete();
        }

        Db::table('sm_module_menu_mapping')
            ->whereIn('id', array_map(static fn (array $row): int => (int) $row['id'], $mappings))
            ->delete();
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function registerMenu(Manifest $manifest, string $manifestMenuId, array $definitions): int
    {
        $scope = $definitions[$manifestMenuId]['platform'];
        $resolutionKey = $scope . ':' . $manifestMenuId;
        if (isset($this->resolved[$resolutionKey])) {
            return $this->resolved[$resolutionKey];
        }
        if (isset($this->resolving[$resolutionKey])) {
            throw new RuntimeException(sprintf('manifest 菜单存在循环父子关系: %s', $manifestMenuId));
        }
        $this->resolving[$resolutionKey] = true;

        $menu = $definitions[$manifestMenuId];
        $parent = $menu['parent'] ?? null;
        if ($parent !== null && isset($definitions[$parent])) {
            if ($definitions[$parent]['platform'] !== $scope) {
                throw new RuntimeException(sprintf('菜单 %s 与父菜单平台不一致。', $manifestMenuId));
            }
            $parentId = $this->registerMenu($manifest, $parent, $definitions);
        } else {
            $parentId = $parent === null ? 0 : $this->coreParentId($scope, $parent);
        }

        $route = $this->routeForMenu($manifest, $menu);
        $type = ['directory' => 1, 'menu' => 2, 'button' => 3][$menu['type']];
        $data = [
            'parent_id' => $parentId,
            'name' => $menu['name'],
            'code' => $type === 3 ? null : $menu['id'],
            'slug' => $menu['permission'] ?? null,
            'module_key' => $manifest->moduleKey(),
            'type' => $type,
            'path' => isset($menu['route']) ? ltrim($menu['route'], '/') : null,
            'component' => $this->normalizeComponent($route['component'] ?? null),
            'method' => isset($route['methods']) ? implode(',', $route['methods']) : null,
            'icon' => $menu['icon'] ?? null,
            'sort' => (int) $menu['sort'],
            'is_hidden' => $type === 3 ? 1 : 0,
            'status' => 1,
            'remark' => sprintf('由模块 %s manifest 注册', $manifest->moduleKey()),
        ];

        $id = $this->upsertMenu(
            $manifest->moduleKey(),
            $scope,
            $manifestMenuId,
            $data,
            $menu['permission'] ?? null,
        );

        unset($this->resolving[$resolutionKey]);

        return $this->resolved[$resolutionKey] = $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function upsertMenu(
        string $moduleKey,
        string $scope,
        string $manifestMenuId,
        array $data,
        ?string $permissionSlug,
    ): int {
        $mapping = Db::table('sm_module_menu_mapping')
            ->where('module_key', $moduleKey)
            ->where('scope', $scope)
            ->where('manifest_menu_id', $manifestMenuId)
            ->find();
        $table = $this->menuTable($scope);
        $now = date('Y-m-d H:i:s');

        $menuId = 0;
        if ($mapping) {
            $menuId = (int) $mapping['menu_id'];
            $exists = Db::table($table)->where('id', $menuId)->find();
            if ($exists) {
                Db::table($table)->where('id', $menuId)->update($data + ['update_time' => $now, 'delete_time' => null]);
            } else {
                $menuId = 0;
            }
        }

        $this->assertNoMenuCollision($table, $moduleKey, $scope, $data, $menuId);

        if ($menuId === 0) {
            if ($scope === 'tenant') {
                $data['organization'] = 0;
            }
            $menuId = (int) Db::table($table)->insertGetId($data + [
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        $mappingData = [
            'module_key' => $moduleKey,
            'scope' => $scope,
            'manifest_menu_id' => $manifestMenuId,
            'menu_id' => $menuId,
            'permission_slug' => $permissionSlug,
            'update_time' => $now,
        ];
        if ($mapping) {
            Db::table('sm_module_menu_mapping')->where('id', $mapping['id'])->update($mappingData);
        } else {
            Db::table('sm_module_menu_mapping')->insert($mappingData + ['create_time' => $now]);
        }

        return $menuId;
    }

    /** @param array<string, mixed> $data */
    private function assertNoMenuCollision(
        string $table,
        string $moduleKey,
        string $scope,
        array $data,
        int $currentMenuId,
    ): void {
        foreach (['code', 'slug'] as $column) {
            $value = $data[$column] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $query = Db::table($table)
                ->where($column, $value)
                ->whereNull('delete_time');
            if ($currentMenuId > 0) {
                $query->where('id', '<>', $currentMenuId);
            }
            $collision = $query->field(['id', 'module_key'])->find();
            if (!$collision) {
                continue;
            }

            $owner = empty($collision['module_key']) ? 'core' : (string) $collision['module_key'];
            throw new RuntimeException(sprintf(
                '模块 %s 的 %s %s 与 %s %s 菜单冲突。',
                $moduleKey,
                $column,
                $value,
                $owner,
                $scope,
            ));
        }
    }

    /** @param array<string, mixed> $menu @return array<string, mixed>|null */
    private function routeForMenu(Manifest $manifest, array $menu): ?array
    {
        foreach ($manifest->routes() as $route) {
            if ($route['platform'] === $menu['platform']
                && isset($menu['route'])
                && $route['path'] === $menu['route']) {
                return $route;
            }
        }

        if (!empty($menu['permission'])) {
            foreach ($manifest->routes() as $route) {
                if (($route['permission'] ?? null) === $menu['permission']) {
                    return $route;
                }
            }
        }

        return null;
    }

    private function coreParentId(string $scope, string $parent): int
    {
        $table = $this->menuTable($scope);
        $row = Db::table($table)
            ->whereNull('delete_time')
            ->where(function ($query) use ($parent) {
                $query->where('code', $parent)->whereOr('path', $parent)->whereOr('slug', $parent);
            })
            ->find();

        return (int) ($row['id'] ?? 0);
    }

    private function firstPageMenuId(string $moduleKey, string $scope): int
    {
        $mapping = Db::table('sm_module_menu_mapping')
            ->alias('mm')
            ->join($this->menuTable($scope) . ' m', 'm.id = mm.menu_id')
            ->where('mm.module_key', $moduleKey)
            ->where('mm.scope', $scope)
            ->whereIn('m.type', [1, 2])
            ->order('m.id', 'asc')
            ->field('m.id')
            ->find();

        return (int) ($mapping['id'] ?? 0);
    }

    private function normalizeComponent(?string $component): ?string
    {
        if ($component === null) {
            return null;
        }
        if (str_starts_with($component, '@/views/')) {
            $component = '/' . substr($component, strlen('@/views/'));
        }

        return preg_replace('/\.vue$/', '', $component);
    }

    private function menuTable(string $scope): string
    {
        return $scope === 'admin' ? 'sm_admin_menu' : 'sm_tenant_menu';
    }
}
