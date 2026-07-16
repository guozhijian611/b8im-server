<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class ThinkOrmWebQrLoginStore implements WebQrLoginStoreInterface
{
    public function transaction(Closure $callback): mixed
    {
        return Db::transaction($callback);
    }

    public function lockActiveOrganization(int $organization, string $deploymentId): array
    {
        $row = Db::table('sm_system_organization')
            ->where('id', $organization)
            ->where('deployment_id', $deploymentId)
            ->lock(true)
            ->find();
        if (
            !is_array($row)
            || (int) ($row['status'] ?? 0) !== 1
            || ($row['delete_time'] ?? null) !== null
        ) {
            throw new ApiException('当前应用已停用、跨部署或不存在。', 403);
        }

        return $row;
    }

    public function lockActiveUser(int $organization, int $id, string $userId): array
    {
        $row = Db::table('im_user')
            ->where('organization', $organization)
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('status', 1)
            ->where('is_system', 2)
            ->whereNull('delete_time')
            ->lock(true)
            ->find();
        if (!is_array($row)) {
            throw new ApiException('App 用户已停用或不存在。', 401);
        }

        return $row;
    }

    public function lockImPolicy(int $organization): ?array
    {
        $row = Db::table('sm_tenant_im_policy')
            ->where('organization', $organization)
            ->lock(true)
            ->find();

        return is_array($row) ? $row : null;
    }

    public function insert(array $row): void
    {
        if ((int) Db::table('im_web_qr_login')->insert($row) !== 1) {
            throw new \RuntimeException('Web QR login was not persisted.');
        }
    }

    public function find(int $organization, string $deploymentId, string $qrId, bool $lock = false): ?array
    {
        $query = Db::table('im_web_qr_login')
            ->where('organization', $organization)
            ->where('deployment_id', $deploymentId)
            ->where('qr_id', $qrId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    public function transition(int $id, string $fromStatus, array $changes): bool
    {
        return Db::table('im_web_qr_login')
            ->where('id', $id)
            ->where('status', $fromStatus)
            ->update($changes) === 1;
    }
}
