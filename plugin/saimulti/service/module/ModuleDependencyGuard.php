<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Manifest\Manifest;
use B8im\ModuleSdk\State\SystemModuleStatus;
use B8im\ModuleSdk\State\TenantModuleStatus;
use Composer\Semver\Semver;
use RuntimeException;
use support\think\Db;

final class ModuleDependencyGuard
{
    public function assertInstallable(Manifest $manifest): void
    {
        $this->assertSystemVersion($manifest);
        $this->assertDependencies($manifest, false);
        $this->assertNoConflicts($manifest, false);
    }

    public function assertEnableable(Manifest $manifest): void
    {
        $this->assertSystemVersion($manifest);
        $this->assertDependencies($manifest, true);
        $this->assertNoConflicts($manifest, true);
    }

    public function assertTenantEnableable(int $organization, Manifest $manifest): void
    {
        $this->assertOrganization($organization);
        $this->assertEnableable($manifest);

        foreach ($manifest->dependsOn() as $dependency) {
            $license = Db::table('sm_tenant_module_license')
                ->where('organization', $organization)
                ->where('module_key', $dependency['module_key'])
                ->whereNull('delete_time')
                ->find();
            $expired = $license
                && $license['expire_at'] !== null
                && strtotime((string) $license['expire_at']) <= time();
            if (!$license
                || $license['status'] !== TenantModuleStatus::ENABLED->value
                || $expired) {
                throw new RuntimeException(sprintf(
                    '租户 %d 启用模块 %s 前，必须先授权并启用依赖模块 %s。',
                    $organization,
                    $manifest->moduleKey(),
                    $dependency['module_key'],
                ));
            }
        }
    }

    public function assertNoEnabledDependents(string $moduleKey): void
    {
        foreach ($this->activeModules() as $module) {
            if ($module['module_key'] === $moduleKey || $module['status'] !== SystemModuleStatus::ENABLED->value) {
                continue;
            }
            foreach ($this->decodeRelations($module['depends_on_json']) as $dependency) {
                if ($dependency['module_key'] === $moduleKey) {
                    throw new RuntimeException(sprintf(
                        '模块 %s 仍被已启用模块 %s 依赖。',
                        $moduleKey,
                        $module['module_key'],
                    ));
                }
            }
        }
    }

    public function assertNoInstalledDependents(string $moduleKey): void
    {
        foreach ($this->activeModules() as $module) {
            if ($module['module_key'] === $moduleKey) {
                continue;
            }
            foreach ($this->decodeRelations($module['depends_on_json']) as $dependency) {
                if ($dependency['module_key'] === $moduleKey) {
                    throw new RuntimeException(sprintf(
                        '模块 %s 仍被已安装模块 %s 依赖。',
                        $moduleKey,
                        $module['module_key'],
                    ));
                }
            }
        }
    }

    public function assertNoTenantEnabledDependents(int $organization, string $moduleKey): void
    {
        $this->assertOrganization($organization);
        $now = date('Y-m-d H:i:s');
        $modules = Db::table('sm_module')
            ->alias('m')
            ->join('sm_tenant_module_license l', 'l.module_key = m.module_key')
            ->where('m.status', SystemModuleStatus::ENABLED->value)
            ->where('l.organization', $organization)
            ->where('l.status', TenantModuleStatus::ENABLED->value)
            ->where(function ($query) use ($now) {
                $query->whereNull('l.expire_at')->whereOr('l.expire_at', '>', $now);
            })
            ->whereNull('m.delete_time')
            ->whereNull('l.delete_time')
            ->field(['m.module_key', 'm.depends_on_json'])
            ->select()
            ->toArray();

        foreach ($modules as $module) {
            if ($module['module_key'] === $moduleKey) {
                continue;
            }
            foreach ($this->decodeRelations($module['depends_on_json']) as $dependency) {
                if ($dependency['module_key'] === $moduleKey) {
                    throw new RuntimeException(sprintf(
                        '租户 %d 的模块 %s 仍被已启用模块 %s 依赖。',
                        $organization,
                        $moduleKey,
                        $module['module_key'],
                    ));
                }
            }
        }
    }

    private function assertSystemVersion(Manifest $manifest): void
    {
        $systemVersion = (string) config('plugin.saimulti.module.system_version', '0.1.0');
        if (!Semver::satisfies($systemVersion, '>=' . $manifest->minSystemVersion())) {
            throw new RuntimeException(sprintf(
                '模块 %s 需要系统版本 >= %s，当前为 %s。',
                $manifest->moduleKey(),
                $manifest->minSystemVersion(),
                $systemVersion,
            ));
        }
    }

    private function assertOrganization(int $organization): void
    {
        if ($organization <= 0) {
            throw new RuntimeException('organization 必须为正整数。');
        }
    }

    private function assertDependencies(Manifest $manifest, bool $mustBeEnabled): void
    {
        foreach ($manifest->dependsOn() as $dependency) {
            $row = Db::table('sm_module')
                ->where('module_key', $dependency['module_key'])
                ->whereNull('delete_time')
                ->find();
            $allowedStatuses = $mustBeEnabled
                ? [SystemModuleStatus::ENABLED->value]
                : [
                    SystemModuleStatus::INSTALLED->value,
                    SystemModuleStatus::ENABLED->value,
                    SystemModuleStatus::DISABLED->value,
                ];
            if (!$row || !in_array($row['status'], $allowedStatuses, true)) {
                throw new RuntimeException(sprintf(
                    '模块 %s 依赖 %s，但依赖模块未%s。',
                    $manifest->moduleKey(),
                    $dependency['module_key'],
                    $mustBeEnabled ? '启用' : '安装',
                ));
            }
            if (!Semver::satisfies((string) $row['version'], $dependency['constraint'])) {
                throw new RuntimeException(sprintf(
                    '依赖模块 %s 版本 %s 不满足 %s。',
                    $dependency['module_key'],
                    $row['version'],
                    $dependency['constraint'],
                ));
            }
        }
    }

    private function assertNoConflicts(Manifest $manifest, bool $enabledOnly): void
    {
        foreach ($manifest->conflictsWith() as $conflict) {
            $row = Db::table('sm_module')
                ->where('module_key', $conflict['module_key'])
                ->whereNull('delete_time')
                ->find();
            if (!$row) {
                continue;
            }

            $active = $enabledOnly
                ? $row['status'] === SystemModuleStatus::ENABLED->value
                : in_array($row['status'], [
                    SystemModuleStatus::INSTALLED->value,
                    SystemModuleStatus::ENABLED->value,
                    SystemModuleStatus::DISABLED->value,
                    SystemModuleStatus::UPGRADING->value,
                ], true);
            if ($active && Semver::satisfies((string) $row['version'], $conflict['constraint'])) {
                throw new RuntimeException(sprintf(
                    '模块 %s 与 %s %s 冲突。',
                    $manifest->moduleKey(),
                    $row['module_key'],
                    $row['version'],
                ));
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function activeModules(): array
    {
        return Db::table('sm_module')
            ->whereIn('status', [
                SystemModuleStatus::INSTALLED->value,
                SystemModuleStatus::ENABLED->value,
                SystemModuleStatus::DISABLED->value,
                SystemModuleStatus::UPGRADING->value,
            ])
            ->whereNull('delete_time')
            ->select()
            ->toArray();
    }

    /** @return list<array{module_key: string, constraint: string}> */
    private function decodeRelations(?string $json): array
    {
        $value = json_decode((string) $json, true);

        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
