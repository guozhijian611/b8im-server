<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\State\SystemModuleStatus;
use support\think\Db;

final class ThinkOrmModuleAccessStore implements ModuleAccessStoreInterface
{
    public function tenantSnapshot(int $organization, string $moduleKey): ?array
    {
        $module = $this->systemSnapshot($moduleKey);
        if ($module === null) {
            return null;
        }

        $license = Db::table('sm_tenant_module_license')
            ->where('organization', $organization)
            ->where('module_key', $moduleKey)
            ->whereNull('delete_time')
            ->find();

        if (!$license) {
            return $module + [
                'organization' => $organization,
                'license_status' => null,
                'expire_at' => null,
                'license_version' => 0,
            ];
        }

        return $module + [
            'organization' => $organization,
            'license_status' => $license['status'],
            'expire_at' => $license['expire_at'],
            'license_version' => (int) $license['version'],
        ];
    }

    public function systemSnapshot(string $moduleKey): ?array
    {
        $module = Db::table('sm_module')
            ->where('module_key', $moduleKey)
            ->whereNull('delete_time')
            ->find();

        if (!$module) {
            return null;
        }

        return [
            'module_key' => $module['module_key'],
            'module_status' => $module['status'],
            'module_version' => $module['version'],
            'module_lock_version' => (int) $module['lock_version'],
            'platforms' => $this->decodeList($module['platforms_json']),
            'capabilities' => $this->decodeMap($module['capabilities_json']),
        ];
    }

    public function enabledTenantSnapshots(int $organization): array
    {
        $rows = Db::table('sm_module')
            ->alias('m')
            ->join('sm_tenant_module_license l', 'l.module_key = m.module_key')
            ->where('m.status', SystemModuleStatus::ENABLED->value)
            ->where('l.organization', $organization)
            ->whereNull('m.delete_time')
            ->whereNull('l.delete_time')
            ->field([
                'm.module_key',
                'm.status AS module_status',
                'm.version AS module_version',
                'm.lock_version AS module_lock_version',
                'm.platforms_json',
                'm.capabilities_json',
                'l.status AS license_status',
                'l.expire_at',
                'l.version AS license_version',
            ])
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['organization'] = $organization;
            $row['platforms'] = $this->decodeList($row['platforms_json']);
            $row['capabilities'] = $this->decodeMap($row['capabilities_json']);
            unset($row['platforms_json'], $row['capabilities_json']);
        }

        return $rows;
    }

    public function enabledSystemSnapshots(): array
    {
        $rows = Db::table('sm_module')
            ->where('status', SystemModuleStatus::ENABLED->value)
            ->whereNull('delete_time')
            ->field([
                'module_key',
                'status AS module_status',
                'version AS module_version',
                'lock_version AS module_lock_version',
                'platforms_json',
                'capabilities_json',
            ])
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['platforms'] = $this->decodeList($row['platforms_json']);
            $row['capabilities'] = $this->decodeMap($row['capabilities_json']);
            unset($row['platforms_json'], $row['capabilities_json']);
        }

        return $rows;
    }

    public function organizationsForModule(string $moduleKey): array
    {
        return array_map(
            'intval',
            Db::table('sm_tenant_module_license')
                ->where('module_key', $moduleKey)
                ->whereNull('delete_time')
                ->distinct(true)
                ->column('organization'),
        );
    }

    /** @return list<string> */
    private function decodeList(?string $json): array
    {
        $value = json_decode((string) $json, true);

        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @return array<string, list<string>> */
    private function decodeMap(?string $json): array
    {
        $value = json_decode((string) $json, true);

        return is_array($value) ? $value : [];
    }
}
