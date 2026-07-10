<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\tenantPolicy;

use support\think\Db;

final class ThinkOrmTenantImPolicyStore implements TenantImPolicyStoreInterface
{
    public function transaction(callable $callback): mixed
    {
        return Db::transaction($callback);
    }

    public function organizationExists(int $organization): bool
    {
        return Db::table('sm_system_organization')
            ->where('id', $organization)
            ->whereNull('delete_time')
            ->count() === 1;
    }

    public function find(int $organization, bool $forUpdate = false): ?array
    {
        $query = Db::table('sm_tenant_im_policy')->where('organization', $organization);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    public function createDefault(int $organization, array $policy): void
    {
        Db::table('sm_tenant_im_policy')->insert(array_merge($policy, [
            'organization' => $organization,
        ]));
    }

    public function update(int $organization, int $expectedVersion, array $policy): bool
    {
        return Db::table('sm_tenant_im_policy')
            ->where('organization', $organization)
            ->where('version', $expectedVersion)
            ->update($policy) === 1;
    }
}
