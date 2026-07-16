<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use support\think\Db;

final class ThinkOrmTenantAccountPolicyStore implements TenantAccountPolicyStoreInterface
{
    public function find(int $organization, bool $lock = false): ?array
    {
        $query = Db::table('sm_tenant_account_policy')->where('organization', $organization);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    public function createDefault(int $organization, array $defaults): void
    {
        Db::table('sm_tenant_account_policy')->insert(['organization' => $organization] + $defaults);
    }
}
