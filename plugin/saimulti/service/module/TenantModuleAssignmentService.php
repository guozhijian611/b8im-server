<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\State\SystemModuleStatus;
use B8im\ModuleSdk\State\TenantModuleStatus;
use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class TenantModuleAssignmentService
{
    public const MODE_INHERIT = 'inherit';
    public const MODE_ENABLED = 'enabled';
    public const MODE_DISABLED = 'disabled';

    public const SOURCE_PACKAGE = 'PACKAGE';
    public const SOURCE_MANUAL = 'MANUAL';

    public function __construct(private readonly ModuleManager $manager)
    {
    }

    /** @return array<string, mixed> */
    public function organizationCatalog(int $organization): array
    {
        $organizationRow = $this->organizationRow($organization);
        $groupId = (int) ($organizationRow['group_id'] ?? 0);
        $packageKeys = $this->packageModuleKeys($groupId);
        $licenses = $this->licenseRows($organization);
        $items = [];

        foreach ($this->moduleRows() as $module) {
            $moduleKey = (string) $module['module_key'];
            $license = $licenses[$moduleKey] ?? null;
            $source = (string) ($license['assignment_source'] ?? self::SOURCE_PACKAGE);
            $effective = $this->effective($license);
            $items[] = $this->moduleView($module) + [
                'assignment_mode' => $source === self::SOURCE_MANUAL
                    ? ($effective ? self::MODE_ENABLED : self::MODE_DISABLED)
                    : self::MODE_INHERIT,
                'package_enabled' => isset($packageKeys[$moduleKey]),
                'status' => $license['status'] ?? TenantModuleStatus::UNAUTHORIZED->value,
                'effective' => $effective,
                'expire_at' => $license['expire_at'] ?? null,
                'remark' => $license['remark'] ?? '',
                'assignment_source' => $source,
            ];
        }

        return [
            'organization' => $organization,
            'organization_name' => $organizationRow['organization_name'] ?? $organizationRow['title'],
            'group_id' => $groupId,
            'items' => $items,
        ];
    }

    /**
     * @param list<array<string, mixed>> $assignments
     * @param array{type?: string, id?: int|null, ip?: string|null} $actor
     * @return array<string, mixed>
     */
    public function updateOrganization(int $organization, array $assignments, array $actor = []): array
    {
        $organizationRow = $this->organizationRow($organization);
        $groupId = (int) ($organizationRow['group_id'] ?? 0);
        $moduleRows = $this->moduleRows();
        $modules = $this->indexByModuleKey($moduleRows);
        $packageKeys = $this->packageModuleKeys($groupId);
        $current = $this->organizationCatalog($organization);
        $normalized = [];

        foreach ($current['items'] as $item) {
            $normalized[$item['module_key']] = [
                'module_key' => $item['module_key'],
                'mode' => $item['assignment_mode'],
                'expire_at' => $item['expire_at'],
                'remark' => $item['remark'],
            ];
        }
        foreach ($assignments as $assignment) {
            $moduleKey = trim((string) ($assignment['module_key'] ?? ''));
            if (!isset($modules[$moduleKey])) {
                throw new ApiException(sprintf('模块 %s 不存在或系统未启用。', $moduleKey ?: '(empty)'), 422);
            }
            $mode = trim((string) ($assignment['mode'] ?? ''));
            if (!in_array($mode, [self::MODE_INHERIT, self::MODE_ENABLED, self::MODE_DISABLED], true)) {
                throw new ApiException(sprintf('模块 %s 的配置模式无效。', $moduleKey), 422);
            }
            $normalized[$moduleKey] = [
                'module_key' => $moduleKey,
                'mode' => $mode,
                'expire_at' => $mode === self::MODE_ENABLED
                    ? $this->nullableString($assignment['expire_at'] ?? null)
                    : null,
                'remark' => $mode === self::MODE_INHERIT
                    ? sprintf('继承套餐 %d', $groupId)
                    : $this->nullableString($assignment['remark'] ?? null),
            ];
        }

        $desired = [];
        foreach ($normalized as $moduleKey => $assignment) {
            $desired[$moduleKey] = match ($assignment['mode']) {
                self::MODE_ENABLED => true,
                self::MODE_DISABLED => false,
                default => isset($packageKeys[$moduleKey]),
            };
        }
        $this->assertDependencies($desired, $modules);

        foreach ($this->dependencyOrder($modules) as $moduleKey) {
            if (!($desired[$moduleKey] ?? false)) {
                continue;
            }
            $assignment = $normalized[$moduleKey];
            $source = $assignment['mode'] === self::MODE_INHERIT ? self::SOURCE_PACKAGE : self::SOURCE_MANUAL;
            $this->ensureEnabled(
                $organization,
                $moduleKey,
                $source,
                $source === self::SOURCE_PACKAGE && $groupId > 0 ? $groupId : null,
                $source === self::SOURCE_MANUAL ? $assignment['expire_at'] : null,
                $assignment['remark'],
                $actor,
            );
        }

        foreach (array_reverse($this->dependencyOrder($modules)) as $moduleKey) {
            if ($desired[$moduleKey] ?? false) {
                continue;
            }
            $assignment = $normalized[$moduleKey];
            $source = $assignment['mode'] === self::MODE_INHERIT ? self::SOURCE_PACKAGE : self::SOURCE_MANUAL;
            $this->ensureDisabled(
                $organization,
                $moduleKey,
                $source,
                $source === self::SOURCE_PACKAGE && $groupId > 0 ? $groupId : null,
                $assignment['remark'],
                $actor,
            );
        }

        return $this->organizationCatalog($organization);
    }

    /** @return array<string, mixed> */
    public function groupCatalog(int $groupId): array
    {
        $group = $this->groupRow($groupId);
        $selected = $this->packageModuleKeys($groupId);
        $items = [];
        foreach ($this->moduleRows() as $module) {
            $items[] = $this->moduleView($module) + [
                'enabled' => isset($selected[$module['module_key']]),
            ];
        }

        return [
            'group_id' => $groupId,
            'group_name' => $group['group_name'],
            'items' => $items,
        ];
    }

    /**
     * @param list<string> $moduleKeys
     * @param array{type?: string, id?: int|null, ip?: string|null} $actor
     * @return array<string, mixed>
     */
    public function updateGroup(int $groupId, array $moduleKeys, array $actor = []): array
    {
        $this->groupRow($groupId);
        $modules = $this->indexByModuleKey($this->moduleRows());
        $selected = [];
        foreach ($moduleKeys as $moduleKey) {
            $moduleKey = trim((string) $moduleKey);
            if (!isset($modules[$moduleKey])) {
                throw new ApiException(sprintf('模块 %s 不存在或系统未启用。', $moduleKey ?: '(empty)'), 422);
            }
            $selected[$moduleKey] = true;
        }
        $this->assertDependencies($selected, $modules);

        $organizations = Db::table('sm_system_organization')
            ->where('group_id', $groupId)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->column('id');
        foreach ($organizations as $organization) {
            $this->assertPackageChangeCompatible((int) $organization, $selected, $modules);
        }

        Db::transaction(function () use ($groupId, $selected): void {
            Db::table('sm_tenant_group_module')->where('group_id', $groupId)->delete();
            if ($selected === []) {
                return;
            }
            $now = date('Y-m-d H:i:s');
            $rows = [];
            $sort = 10;
            foreach (array_keys($selected) as $moduleKey) {
                $rows[] = [
                    'group_id' => $groupId,
                    'module_key' => $moduleKey,
                    'enabled' => 1,
                    'limits_json' => '{}',
                    'config_json' => '{}',
                    'sort' => $sort,
                    'create_time' => $now,
                    'update_time' => $now,
                ];
                $sort += 10;
            }
            Db::table('sm_tenant_group_module')->insertAll($rows);
        });

        foreach ($organizations as $organization) {
            $this->syncOrganizationFromGroup((int) $organization, $actor);
        }

        return $this->groupCatalog($groupId);
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor */
    public function syncOrganizationFromGroup(int $organization, array $actor = []): void
    {
        $organizationRow = $this->organizationRow($organization);
        $groupId = (int) ($organizationRow['group_id'] ?? 0);
        $selected = $this->packageModuleKeys($groupId);
        $modules = $this->indexByModuleKey($this->moduleRows());
        $licenses = $this->licenseRows($organization);

        $desired = [];
        foreach ($modules as $moduleKey => $_module) {
            $license = $licenses[$moduleKey] ?? null;
            $desired[$moduleKey] = ($license['assignment_source'] ?? self::SOURCE_PACKAGE) === self::SOURCE_MANUAL
                ? $this->effective($license)
                : isset($selected[$moduleKey]);
        }
        $this->assertDependencies($desired, $modules);

        foreach ($this->dependencyOrder($modules) as $moduleKey) {
            $license = $licenses[$moduleKey] ?? null;
            if (($license['assignment_source'] ?? self::SOURCE_PACKAGE) === self::SOURCE_MANUAL) {
                continue;
            }
            if (isset($selected[$moduleKey])) {
                $this->ensureEnabled(
                    $organization,
                    $moduleKey,
                    self::SOURCE_PACKAGE,
                    $groupId,
                    null,
                    sprintf('继承套餐 %d', $groupId),
                    $actor,
                );
            }
        }
        foreach (array_reverse($this->dependencyOrder($modules)) as $moduleKey) {
            $license = $licenses[$moduleKey] ?? null;
            if ($license === null
                || ($license['assignment_source'] ?? self::SOURCE_PACKAGE) === self::SOURCE_MANUAL
                || isset($selected[$moduleKey])) {
                continue;
            }
            $this->ensureDisabled(
                $organization,
                $moduleKey,
                self::SOURCE_PACKAGE,
                $groupId,
                sprintf('套餐 %d 未包含该模块', $groupId),
                $actor,
            );
        }
    }

    /** @param array<string, bool> $selected @param array<string, array<string, mixed>> $modules */
    private function assertPackageChangeCompatible(int $organization, array $selected, array $modules): void
    {
        $licenses = $this->licenseRows($organization);
        $desired = [];
        foreach ($modules as $moduleKey => $_module) {
            $license = $licenses[$moduleKey] ?? null;
            $desired[$moduleKey] = ($license['assignment_source'] ?? self::SOURCE_PACKAGE) === self::SOURCE_MANUAL
                ? $this->effective($license)
                : isset($selected[$moduleKey]);
        }
        $this->assertDependencies($desired, $modules);
    }

    /** @param array<string, bool> $desired @param array<string, array<string, mixed>> $modules */
    private function assertDependencies(array $desired, array $modules): void
    {
        foreach ($desired as $moduleKey => $enabled) {
            if (!$enabled) {
                continue;
            }
            foreach ($this->dependencies($modules[$moduleKey]['depends_on_json'] ?? '[]') as $dependency) {
                $dependencyKey = (string) ($dependency['module_key'] ?? '');
                if ($dependencyKey !== '' && !($desired[$dependencyKey] ?? false)) {
                    throw new ApiException(sprintf(
                        '模块 %s 依赖 %s，请先在同一套餐或机构配置中启用依赖模块。',
                        $moduleKey,
                        $dependencyKey,
                    ), 422);
                }
            }
        }
    }

    /** @param array<string, array<string, mixed>> $modules @return list<string> */
    private function dependencyOrder(array $modules): array
    {
        $order = [];
        $visited = [];
        $visiting = [];
        $visit = function (string $moduleKey) use (&$visit, &$order, &$visited, &$visiting, $modules): void {
            if (isset($visited[$moduleKey])) {
                return;
            }
            if (isset($visiting[$moduleKey])) {
                throw new ApiException(sprintf('模块依赖存在循环：%s。', $moduleKey), 422);
            }
            $visiting[$moduleKey] = true;
            foreach ($this->dependencies($modules[$moduleKey]['depends_on_json'] ?? '[]') as $dependency) {
                $dependencyKey = (string) ($dependency['module_key'] ?? '');
                if (isset($modules[$dependencyKey])) {
                    $visit($dependencyKey);
                }
            }
            unset($visiting[$moduleKey]);
            $visited[$moduleKey] = true;
            $order[] = $moduleKey;
        };
        foreach (array_keys($modules) as $moduleKey) {
            $visit($moduleKey);
        }

        return $order;
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor */
    private function ensureEnabled(
        int $organization,
        string $moduleKey,
        string $source,
        ?int $sourceGroupId,
        ?string $expireAt,
        ?string $remark,
        array $actor,
    ): void {
        $license = $this->licenseRows($organization)[$moduleKey] ?? null;
        if ($this->effective($license)
            && ($license['assignment_source'] ?? self::SOURCE_PACKAGE) === $source
            && $this->sourceGroupId($license) === $sourceGroupId
            && ($license['expire_at'] ?? null) === $expireAt
            && ($license['remark'] ?? null) === $remark) {
            return;
        }
        $current = $this->manager->grantLicense(
            $organization,
            $moduleKey,
            $expireAt,
            $remark,
            $actor,
            $source,
            $sourceGroupId,
        );
        if ($current['status'] !== TenantModuleStatus::ENABLED->value) {
            $this->manager->enableTenant($organization, $moduleKey, $actor);
        }
    }

    /** @param array{type?: string, id?: int|null, ip?: string|null} $actor */
    private function ensureDisabled(
        int $organization,
        string $moduleKey,
        string $source,
        ?int $sourceGroupId,
        ?string $remark,
        array $actor,
    ): void {
        $license = $this->licenseRows($organization)[$moduleKey] ?? null;
        if ($license !== null
            && $license['status'] === TenantModuleStatus::UNAUTHORIZED->value
            && ($license['assignment_source'] ?? self::SOURCE_PACKAGE) === $source
            && $this->sourceGroupId($license) === $sourceGroupId
            && ($license['remark'] ?? null) === $remark) {
            return;
        }
        if ($license === null && $source === self::SOURCE_PACKAGE) {
            return;
        }
        $this->manager->grantLicense(
            $organization,
            $moduleKey,
            null,
            $remark,
            $actor,
            $source,
            $sourceGroupId,
        );
        $this->manager->revokeLicense($organization, $moduleKey, $actor);
    }

    /** @return list<array<string, mixed>> */
    private function moduleRows(): array
    {
        return Db::table('sm_module')
            ->where('status', SystemModuleStatus::ENABLED->value)
            ->whereNull('delete_time')
            ->field([
                'module_key',
                'name',
                'description',
                'category',
                'module_type',
                'version',
                'platforms_json',
                'depends_on_json',
                'capabilities_json',
            ])
            ->order('module_key', 'asc')
            ->select()
            ->toArray();
    }

    /** @param list<array<string, mixed>> $rows @return array<string, array<string, mixed>> */
    private function indexByModuleKey(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['module_key']] = $row;
        }

        return $indexed;
    }

    /** @return array<string, array<string, mixed>> */
    private function licenseRows(int $organization): array
    {
        $rows = Db::table('sm_tenant_module_license')
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->select()
            ->toArray();

        return $this->indexByModuleKey($rows);
    }

    /** @return array<string, bool> */
    private function packageModuleKeys(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }
        $keys = Db::table('sm_tenant_group_module')
            ->where('group_id', $groupId)
            ->where('enabled', 1)
            ->order('sort', 'asc')
            ->column('module_key');

        return array_fill_keys(array_map('strval', $keys), true);
    }

    /** @return array<string, mixed> */
    private function organizationRow(int $organization): array
    {
        $row = Db::table('sm_system_organization')
            ->where('id', $organization)
            ->whereNull('delete_time')
            ->find();
        if (!$row) {
            throw new ApiException('目标机构不存在。', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function groupRow(int $groupId): array
    {
        $row = Db::table('sm_tenant_group')
            ->where('id', $groupId)
            ->whereNull('delete_time')
            ->find();
        if (!$row) {
            throw new ApiException('目标套餐不存在。', 404);
        }

        return $row;
    }

    /** @param array<string, mixed>|null $license */
    private function effective(?array $license): bool
    {
        return $license !== null
            && $license['status'] === TenantModuleStatus::ENABLED->value
            && ($license['expire_at'] === null || strtotime((string) $license['expire_at']) > time());
    }

    /** @param array<string, mixed> $module @return array<string, mixed> */
    private function moduleView(array $module): array
    {
        return [
            'module_key' => $module['module_key'],
            'name' => $module['name'],
            'description' => $module['description'],
            'category' => $module['category'],
            'module_type' => $module['module_type'],
            'version' => $module['version'],
            'platforms' => $this->decodeList((string) $module['platforms_json']),
            'depends_on' => $this->dependencies((string) $module['depends_on_json']),
            'capabilities' => $this->decodeObject((string) $module['capabilities_json']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function dependencies(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /** @return list<mixed> */
    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $license */
    private function sourceGroupId(array $license): ?int
    {
        $value = $license['source_group_id'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
