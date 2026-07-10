<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

/**
 * Resolves the only tenant menus that may be granted inside one organization.
 *
 * Core menus come from the organization's product group. Module menus do not
 * need group mappings: they become assignable only while the system module and
 * the current organization's effective tenant license are both enabled.
 */
final class TenantAssignableMenuService
{
    public function __construct(private readonly ?ModuleAccessService $access = null)
    {
    }

    /** @return list<int> */
    public function ids(int $organization): array
    {
        $groupId = $this->organizationGroupId($organization);

        $coreIds = Db::table('sm_tenant_menu')
            ->alias('m')
            ->join('sm_tenant_group_menu gm', 'gm.menu_id = m.id')
            ->where('gm.group_id', $groupId)
            ->whereNull('m.module_key')
            ->whereIn('m.organization', [0, $organization])
            ->where('m.status', 1)
            ->whereNull('m.delete_time')
            ->column('m.id');

        $moduleKeys = ($this->access ?? ModuleServiceFactory::access())
            ->enabledModuleKeys($organization, 'tenant');
        $moduleIds = [];
        if ($moduleKeys !== []) {
            $moduleIds = Db::table('sm_tenant_menu')
                ->whereIn('module_key', $moduleKeys)
                ->whereIn('organization', [0, $organization])
                ->where('status', 1)
                ->whereNull('delete_time')
                ->column('id');
        }

        $ids = array_values(array_unique(array_map(
            'intval',
            array_merge($coreIds, $moduleIds),
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function organizationGroupId(int $organization): int
    {
        if ($organization <= 0) {
            throw new ApiException('organization 必须为正整数。', 422);
        }

        $row = Db::table('sm_system_organization')
            ->alias('o')
            ->join('sm_tenant_group g', 'g.id = o.group_id')
            ->where('o.id', $organization)
            ->where('o.status', 1)
            ->where('g.status', 1)
            ->whereNull('o.delete_time')
            ->whereNull('g.delete_time')
            ->field('o.group_id')
            ->find();
        $groupId = (int) ($row['group_id'] ?? 0);
        if ($groupId <= 0) {
            throw new ApiException('当前租户未绑定有效的机构分组。', 409);
        }

        return $groupId;
    }
}
