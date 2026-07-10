<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class TenantRoleHierarchyService
{
    /**
     * 调用方必须已进入本次角色变更或权限读写的数据库事务。
     */
    public function lockActorMaxLevel(int $organization, int $actorUserId): int
    {
        if ($organization <= 0 || $actorUserId <= 0) {
            throw new ApiException('租户角色操作人参数无效。', 422);
        }

        $actor = Db::query(
            'SELECT id
               FROM sm_tenant_user
              WHERE id = ?
                AND organization = ?
                AND status = 1
                AND delete_time IS NULL
              LIMIT 1
              FOR UPDATE',
            [$actorUserId, $organization],
        )[0] ?? null;
        if ($actor === null) {
            throw new ApiException('当前账户已停用或不属于当前 organization。', 403);
        }

        $roles = Db::query(
            'SELECT r.id, r.level
               FROM sm_tenant_user_role ur
               INNER JOIN sm_tenant_role r ON r.id = ur.role_id
              WHERE ur.user_id = ?
                AND r.organization = ?
                AND r.status = 1
                AND r.delete_time IS NULL
              ORDER BY r.id ASC
              FOR UPDATE',
            [$actorUserId, $organization],
        );
        $levels = array_values(array_filter(array_map(
            static fn (array $role): int => (int) ($role['level'] ?? 0),
            $roles,
        ), static fn (int $level): bool => $level > 0));
        if ($levels === []) {
            throw new ApiException('当前账户缺少有效角色职级。', 403);
        }

        return max($levels);
    }

    /** @return array<string, mixed> */
    public function lockManageableRole(int $organization, int $roleId, int $actorMaxLevel): array
    {
        if ($organization <= 0 || $roleId <= 0 || $actorMaxLevel <= 0) {
            throw new ApiException('租户角色参数无效。', 422);
        }

        $role = Db::query(
            'SELECT id, organization, code, level, status
               FROM sm_tenant_role
              WHERE id = ?
                AND organization = ?
                AND delete_time IS NULL
              LIMIT 1
              FOR UPDATE',
            [$roleId, $organization],
        )[0] ?? null;
        if ($role === null) {
            throw new ApiException('目标角色不属于当前 organization。', 404);
        }
        if (hash_equals('superAdmin', (string) ($role['code'] ?? ''))) {
            throw new ApiException('超级管理员角色不允许修改或删除。', 403);
        }
        if ((int) ($role['level'] ?? 0) >= $actorMaxLevel) {
            throw new ApiException('不能操作与当前账户同级或更高职级的角色。', 403);
        }

        return $role;
    }

    public function normalizeNewLevel(mixed $level, int $actorMaxLevel): int
    {
        if (is_int($level) && $level > 0) {
            $normalized = $level;
        } elseif (is_string($level) && preg_match('/^[1-9][0-9]*$/', $level) === 1) {
            $normalized = (int) $level;
        } else {
            throw new ApiException('角色职级必须为正整数。', 422);
        }

        if ($actorMaxLevel <= 0 || $normalized >= $actorMaxLevel) {
            throw new ApiException('新角色职级必须低于当前账户职级。', 403);
        }

        return $normalized;
    }
}
