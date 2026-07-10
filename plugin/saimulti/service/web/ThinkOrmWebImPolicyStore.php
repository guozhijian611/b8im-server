<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use support\think\Db;

final class ThinkOrmWebImPolicyStore implements WebImPolicyStoreInterface
{
    public function findPolicy(int $organization): ?array
    {
        $rows = Db::query(
            'SELECT organization, status, allowed_client_families_json '
            . 'FROM sm_tenant_im_policy WHERE organization = ? LIMIT 1',
            [$organization],
        );

        return $rows[0] ?? null;
    }
}
