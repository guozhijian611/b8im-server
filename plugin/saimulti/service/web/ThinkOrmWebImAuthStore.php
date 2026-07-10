<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class ThinkOrmWebImAuthStore implements WebImAuthStoreInterface
{
    /** @return array<string, mixed>|null */
    public function findActiveLoginUser(int $organization, string $account): ?array
    {
        $rows = Db::query(
            'SELECT u.*, COALESCE(p.signature, \'\') AS signature
               FROM im_user u
          LEFT JOIN im_user_profile p
                 ON p.organization = u.organization
                AND p.user_id = u.user_id
                AND p.status = 1
                AND p.delete_time IS NULL
              WHERE u.organization = ?
                AND u.account = ?
                AND u.status = 1
                AND u.is_system = 2
                AND u.delete_time IS NULL
              LIMIT 1',
            [$organization, $account],
        );

        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function findActiveUser(int $organization, int $id, string $userId): ?array
    {
        $rows = Db::query(
            'SELECT u.*, COALESCE(p.signature, \'\') AS signature
               FROM im_user u
          LEFT JOIN im_user_profile p
                 ON p.organization = u.organization
                AND p.user_id = u.user_id
                AND p.status = 1
                AND p.delete_time IS NULL
              WHERE u.organization = ?
                AND u.id = ?
                AND u.user_id = ?
                AND u.status = 1
                AND u.is_system = 2
                AND u.delete_time IS NULL
              LIMIT 1',
            [$organization, $id, $userId],
        );

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $audit */
    public function recordLoginAudit(array $audit): void
    {
        Db::table('im_user_login_audit')->insert($audit);
    }

    /** @param array<string, mixed> $audit @param array<string, mixed> $accessSession */
    public function recordSuccessfulLogin(
        int $organization,
        int $id,
        string $loginAt,
        array $audit,
        array $accessSession,
    ): void
    {
        Db::transaction(function () use ($organization, $id, $loginAt, $audit, $accessSession): void {
            // Global lock order for organization access transitions is:
            // organization -> user/policy -> auth/access sessions. This makes
            // login serialize before or after organization disable, never in
            // the revoke-then-insert gap.
            $organizationRow = Db::table('sm_system_organization')
                ->where('id', $organization)
                ->lock(true)
                ->find();
            if (
                !$organizationRow
                || (int) ($organizationRow['status'] ?? 0) !== 1
                || ($organizationRow['delete_time'] ?? null) !== null
            ) {
                throw new ApiException('当前应用已停用或不存在。', 403);
            }

            $user = Db::table('im_user')
                ->where('organization', $organization)
                ->where('id', $id)
                ->where('status', 1)
                ->where('is_system', 2)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if (!$user) {
                throw new ApiException('当前 Web 用户已停用或不存在。', 401);
            }

            $policy = Db::table('sm_tenant_im_policy')
                ->where('organization', $organization)
                ->lock(true)
                ->find();
            WebImPolicyGuard::assertRowAllowsWeb(is_array($policy) ? $policy : null, $organization);

            $affected = Db::table('im_user')
                ->where('organization', $organization)
                ->where('id', $id)
                ->where('status', 1)
                ->where('is_system', 2)
                ->whereNull('delete_time')
                ->update([
                    'login_time' => $loginAt,
                    'update_time' => $loginAt,
                ]);
            if ($affected !== 1) {
                // MySQL reports zero affected rows when both timestamps already equal
                // the requested second. The row remains locked, so only that exact
                // idempotent case may proceed.
                $current = Db::table('im_user')
                    ->where('organization', $organization)
                    ->where('id', $id)
                    ->where('status', 1)
                    ->where('is_system', 2)
                    ->whereNull('delete_time')
                    ->lock(true)
                    ->find();
                if (
                    !$current
                    || !hash_equals($loginAt, (string) ($current['login_time'] ?? ''))
                    || !hash_equals($loginAt, (string) ($current['update_time'] ?? ''))
                ) {
                    throw new \RuntimeException('Web login state update was not persisted.');
                }
            }

            $inserted = Db::table('im_web_access_session')->insert($accessSession);
            if ((int) $inserted !== 1) {
                throw new \RuntimeException('Web access session was not persisted.');
            }
            Db::table('im_user_login_audit')->insert($audit);
        });
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $session
     * @param array<string, mixed> $accessSession
     */
    public function upsertChallenge(array $device, array $session, array $accessSession): void
    {
        Db::transaction(function () use ($device, $session, $accessSession): void {
            $this->upsertDevice($device);
            $this->upsertAuthSession($session);
            $this->assertActiveAccessSession($accessSession);
        });
    }

    public function updateAvatar(
        int $organization,
        int $id,
        string $userId,
        string $avatarFileId,
        string $updateTime,
    ): void {
        Db::table('im_user')
            ->where('organization', $organization)
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('status', 1)
            ->where('is_system', 2)
            ->whereNull('delete_time')
            ->update([
                'avatar' => $avatarFileId,
                'update_time' => $updateTime,
            ]);
    }

    /** @param array<string, mixed> $device */
    private function upsertDevice(array $device): void
    {
        Db::execute(
            'INSERT INTO im_user_device
                (organization, user_id, device_id, client_family, os, current_ip, last_login_ip,
                 last_login_at, last_seen_at, current_online_state, status, create_time, update_time)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [
                $device['organization'],
                $device['user_id'],
                $device['device_id'],
                $device['client_family'],
                $device['os'],
                $device['current_ip'],
                $device['last_login_ip'],
                $device['last_login_at'],
                $device['last_seen_at'],
                $device['current_online_state'],
                $device['status'],
                $device['create_time'],
                $device['update_time'],
            ],
        );

        $row = Db::table('im_user_device')
            ->where('organization', $device['organization'])
            ->where('user_id', $device['user_id'])
            ->where('device_id', $device['device_id'])
            ->lock(true)
            ->find();
        if (!$row) {
            throw new \RuntimeException('IM device upsert did not produce a row.');
        }
        if (
            (int) ($row['status'] ?? 0) !== 1
            || ($row['delete_time'] ?? null) !== null
            || !hash_equals('web', (string) ($row['client_family'] ?? ''))
            || !hash_equals('browser', (string) ($row['os'] ?? ''))
        ) {
            throw new ApiException('当前 Web 设备已停用或设备类型不匹配。', 403);
        }

        Db::table('im_user_device')
            ->where('id', (int) $row['id'])
            ->update([
                'current_ip' => $device['current_ip'],
                'last_login_ip' => $device['last_login_ip'],
                'last_login_at' => $device['last_login_at'],
                'last_seen_at' => $device['last_seen_at'],
                'update_time' => $device['update_time'],
            ]);
    }

    /** @param array<string, mixed> $session */
    private function upsertAuthSession(array $session): void
    {
        $collision = Db::table('im_auth_session')
            ->where('organization', $session['organization'])
            ->where('session_id', $session['session_id'])
            ->where('client_id', '<>', $session['client_id'])
            ->lock(true)
            ->find();
        if ($collision) {
            throw new \RuntimeException('Generated IM credential session_id collision.');
        }

        Db::execute(
            'INSERT INTO im_auth_session
                (organization, user_id, device_id, client_id, session_id, web_access_jti, status, expire_at,
                 revoked_at, create_time, update_time)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [
                $session['organization'],
                $session['user_id'],
                $session['device_id'],
                $session['client_id'],
                $session['session_id'],
                $session['web_access_jti'],
                $session['status'],
                $session['expire_at'],
                $session['revoked_at'],
                $session['create_time'],
                $session['update_time'],
            ],
        );

        $row = Db::table('im_auth_session')
            ->where('organization', $session['organization'])
            ->where('client_id', $session['client_id'])
            ->lock(true)
            ->find();
        if (!$row) {
            throw new \RuntimeException('IM auth session upsert did not produce a client row.');
        }

        if ((int) ($row['status'] ?? 0) === 2 || ($row['revoked_at'] ?? null) !== null) {
            throw new ApiException('已撤销的 client_id 不能重新签发凭证。', 409);
        }

        $sameIdentity =
            hash_equals((string) $row['user_id'], (string) $session['user_id'])
            && hash_equals((string) $row['device_id'], (string) $session['device_id']);
        $active = (int) ($row['status'] ?? 0) === 1
            && strtotime((string) ($row['expire_at'] ?? '')) > strtotime((string) $session['create_time']);
        if ($active && !$sameIdentity) {
            throw new ApiException('当前 client_id 已绑定其他有效身份。', 409);
        }

        Db::table('im_auth_session')
            ->where('id', (int) $row['id'])
            ->update([
                'organization' => $session['organization'],
                'user_id' => $session['user_id'],
                'device_id' => $session['device_id'],
                'client_id' => $session['client_id'],
                'session_id' => $session['session_id'],
                'web_access_jti' => $session['web_access_jti'],
                'status' => 1,
                'expire_at' => $session['expire_at'],
                'revoked_at' => null,
                'update_time' => $session['update_time'],
            ]);
    }

    /** @param array<string, mixed> $accessSession */
    private function assertActiveAccessSession(array $accessSession): void
    {
        $row = Db::table('im_web_access_session')
            ->where('organization', $accessSession['organization'])
            ->where('jti', $accessSession['jti'])
            ->lock(true)
            ->find();
        $expireAt = $row ? (strtotime((string) ($row['expire_at'] ?? '')) ?: 0) : 0;
        if (
            !$row
            || (int) ($row['im_user_id'] ?? 0) !== (int) $accessSession['im_user_id']
            || !hash_equals((string) $accessSession['user_id'], (string) ($row['user_id'] ?? ''))
            || !hash_equals((string) $accessSession['device_id'], (string) ($row['device_id'] ?? ''))
            || (int) ($row['status'] ?? 0) !== 1
            || ($row['revoked_at'] ?? null) !== null
            || $expireAt <= (int) $accessSession['now']
            || (int) $accessSession['token_exp'] > $expireAt
        ) {
            throw new ApiException('Web 登录会话已撤销或过期。', 401);
        }
    }
}
