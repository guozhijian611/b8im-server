<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use support\think\Db;

final class ThinkOrmWebImAccessSessionStore implements WebImAccessSessionStoreInterface
{
    public function findByJti(int $organization, string $jti): ?array
    {
        $rows = Db::query(
            'SELECT organization, jti, im_user_id, user_id, device_id, status, expire_at, revoked_at '
            . 'FROM im_web_access_session WHERE organization = ? AND jti = ? LIMIT 1',
            [$organization, $jti],
        );

        return $rows[0] ?? null;
    }
}
