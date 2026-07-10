<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

use JsonException;
use RuntimeException;
use support\think\Db;

final class ThinkOrmAdminImStore implements AdminImStoreInterface
{
    private const REQUIRED_TABLES = [
        'im_user',
        'im_user_device',
        'im_web_access_session',
        'im_auth_session',
        'im_user_login_audit',
        'im_message_outbox',
    ];

    public function databaseStatus(): array
    {
        Db::query('SELECT 1 AS healthy');

        return ['status' => 'up'];
    }

    public function schemaStatus(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::REQUIRED_TABLES), '?'));
        $rows = Db::query(
            'SELECT TABLE_NAME AS table_name FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
            self::REQUIRED_TABLES,
        );
        $present = array_map(static fn (array $row): string => (string) $row['table_name'], $rows);
        $missing = array_values(array_diff(self::REQUIRED_TABLES, $present));

        return ['status' => $missing === [] ? 'ready' : 'missing', 'missing' => $missing];
    }

    public function statistics(string $now): array
    {
        $users = $this->aggregate(
            'SELECT COUNT(*) AS total, '
            . 'COALESCE(SUM(status = 1), 0) AS active, '
            . 'COALESCE(SUM(status = 2), 0) AS disabled, '
            . 'COALESCE(SUM(status = 3), 0) AS banned '
            . 'FROM im_user WHERE delete_time IS NULL',
        );
        $devices = $this->aggregate(
            'SELECT COUNT(*) AS total, '
            . 'COALESCE(SUM(current_online_state = 1 AND status = 1), 0) AS online, '
            . 'COALESCE(SUM(status = 2), 0) AS disabled '
            . 'FROM im_user_device WHERE delete_time IS NULL',
        );
        $sessions = $this->aggregate(
            'SELECT COUNT(*) AS total, '
            . 'COALESCE(SUM(status = 1 AND expire_at > ?), 0) AS active, '
            . 'COALESCE(SUM(status = 2), 0) AS revoked, '
            . 'COALESCE(SUM(status = 1 AND expire_at <= ?), 0) AS expired '
            . 'FROM im_auth_session',
            [$now, $now],
        );
        $outbox = $this->aggregate(
            'SELECT COUNT(*) AS total, '
            . 'COALESCE(SUM(status = 1), 0) AS pending, '
            . 'COALESCE(SUM(status = 2), 0) AS publishing, '
            . 'COALESCE(SUM(status = 3), 0) AS published, '
            . 'COALESCE(SUM(status = 4), 0) AS failed '
            . 'FROM im_message_outbox',
        );

        return compact('users', 'devices', 'sessions', 'outbox');
    }

    public function users(array $filters, int $page, int $limit): array
    {
        $where = ['u.delete_time IS NULL'];
        $params = [];
        $this->organizationFilter($where, $params, 'u', $filters);
        $this->statusFilter($where, $params, 'u.status', $filters);
        $keyword = (string) ($filters['keyword'] ?? '');
        if ($keyword !== '') {
            $where[] = '(u.user_id LIKE ? OR u.account LIKE ? OR u.nickname LIKE ? OR u.mobile LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $from = 'FROM im_user u '
            . 'LEFT JOIN sm_system_organization o ON o.id = u.organization AND o.delete_time IS NULL '
            . 'WHERE ' . implode(' AND ', $where);
        $fields = 'SELECT u.id, u.organization, o.organization_name, u.user_id, u.im_short_no, '
            . 'u.account, u.nickname, u.avatar, u.mobile, u.email, u.gender, u.is_system, '
            . 'u.system_code, u.status, u.remark, u.login_time, u.create_time, u.update_time ';

        return $this->page($fields, $from, $params, 'u.id DESC', $page, $limit);
    }

    public function devices(array $filters, int $page, int $limit): array
    {
        $where = ['d.delete_time IS NULL'];
        $params = [];
        $this->organizationFilter($where, $params, 'd', $filters);
        $this->statusFilter($where, $params, 'd.status', $filters);
        if (isset($filters['online_state'])) {
            $where[] = 'd.current_online_state = ?';
            $params[] = (int) $filters['online_state'];
        }
        $keyword = (string) ($filters['keyword'] ?? '');
        if ($keyword !== '') {
            $where[] = '(d.user_id LIKE ? OR u.account LIKE ? OR u.nickname LIKE ? OR d.device_id LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $from = 'FROM im_user_device d '
            . 'LEFT JOIN im_user u ON u.organization = d.organization AND u.user_id = d.user_id AND u.delete_time IS NULL '
            . 'LEFT JOIN sm_system_organization o ON o.id = d.organization AND o.delete_time IS NULL '
            . 'WHERE ' . implode(' AND ', $where);
        $fields = 'SELECT d.id, d.organization, o.organization_name, d.user_id, u.account, u.nickname, '
            . 'd.device_id, d.client_family, d.os, d.client_id, d.device_name, d.device_model, '
            . 'd.os_version, d.app_version, d.current_ip, d.current_ip_geo, d.last_login_ip, '
            . 'd.last_login_ip_geo, d.last_login_at, d.last_seen_at, d.current_online_state, '
            . 'd.status, d.create_time, d.update_time ';

        return $this->page($fields, $from, $params, 'd.id DESC', $page, $limit);
    }

    public function sessions(array $filters, int $page, int $limit): array
    {
        $where = ['1 = 1'];
        $params = [];
        $this->organizationFilter($where, $params, 's', $filters);
        $this->statusFilter($where, $params, 's.status', $filters);
        $keyword = (string) ($filters['keyword'] ?? '');
        if ($keyword !== '') {
            $where[] = '(s.user_id LIKE ? OR u.account LIKE ? OR u.nickname LIKE ? OR s.device_id LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $from = 'FROM im_auth_session s '
            . 'LEFT JOIN im_user u ON u.organization = s.organization AND u.user_id = s.user_id AND u.delete_time IS NULL '
            . 'LEFT JOIN sm_system_organization o ON o.id = s.organization AND o.delete_time IS NULL '
            . 'WHERE ' . implode(' AND ', $where);
        $fields = 'SELECT s.id, s.organization, o.organization_name, s.user_id, u.account, u.nickname, '
            . 's.device_id, s.client_id, s.session_id, s.status, s.expire_at, s.revoked_at, '
            . 's.create_time, s.update_time ';

        return $this->page($fields, $from, $params, 's.id DESC', $page, $limit);
    }

    public function loginAudits(array $filters, int $page, int $limit): array
    {
        $where = ['1 = 1'];
        $params = [];
        $this->organizationFilter($where, $params, 'a', $filters);
        $keyword = (string) ($filters['keyword'] ?? '');
        if ($keyword !== '') {
            $where[] = '(a.user_id LIKE ? OR u.account LIKE ? OR u.nickname LIKE ? OR a.device_id LIKE ? OR a.login_ip LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $loginResult = (string) ($filters['login_result'] ?? '');
        if ($loginResult !== '') {
            $where[] = 'a.login_result = ?';
            $params[] = $loginResult;
        }

        $from = 'FROM im_user_login_audit a '
            . 'LEFT JOIN im_user u ON u.organization = a.organization AND u.user_id = a.user_id AND u.delete_time IS NULL '
            . 'LEFT JOIN sm_system_organization o ON o.id = a.organization AND o.delete_time IS NULL '
            . 'WHERE ' . implode(' AND ', $where);
        $fields = 'SELECT a.id, a.organization, o.organization_name, a.user_id, u.account, u.nickname, '
            . 'a.device_id, a.client_id, a.client_family, a.os, a.device_name, a.device_model, '
            . 'a.os_version, a.app_version, a.login_ip, a.login_ip_geo, a.login_at, a.logout_at, '
            . 'a.login_result, a.audit_scope, a.current_online_state, a.failure_code, a.create_time ';

        return $this->page($fields, $from, $params, 'a.id DESC', $page, $limit);
    }

    public function transaction(callable $callback): mixed
    {
        return Db::transaction($callback);
    }

    public function lockDeviceById(int $id): ?array
    {
        $rows = Db::query(
            'SELECT id, organization, user_id, device_id, client_id, session_id, status FROM im_user_device '
            . 'WHERE id = ? AND delete_time IS NULL LIMIT 1 FOR UPDATE',
            [$id],
        );

        return $rows[0] ?? null;
    }

    public function lockSessionById(int $id): ?array
    {
        $rows = Db::query(
            'SELECT id, organization, user_id, device_id, client_id, session_id, web_access_jti, status, expire_at '
            . 'FROM im_auth_session WHERE id = ? LIMIT 1 FOR UPDATE',
            [$id],
        );

        return $rows[0] ?? null;
    }

    public function activeSessionIdsForDevice(int $organization, string $userId, string $deviceId): array
    {
        $rows = Db::query(
            'SELECT session_id FROM im_auth_session '
            . 'WHERE organization = ? AND user_id = ? AND device_id = ? AND status = 1 FOR UPDATE',
            [$organization, $userId, $deviceId],
        );

        return array_values(array_map(
            static fn (array $row): string => (string) $row['session_id'],
            $rows,
        ));
    }

    public function activeSessionIdsForWebAccess(
        int $organization,
        string $userId,
        string $deviceId,
        string $webAccessJti,
    ): array {
        $rows = Db::query(
            'SELECT session_id FROM im_auth_session '
            . 'WHERE organization = ? AND user_id = ? AND device_id = ? '
            . 'AND web_access_jti = ? AND status = 1 FOR UPDATE',
            [$organization, $userId, $deviceId, $webAccessJti],
        );

        return array_values(array_map(
            static fn (array $row): string => (string) $row['session_id'],
            $rows,
        ));
    }

    public function setDeviceStatus(
        int $id,
        int $organization,
        string $userId,
        string $deviceId,
        int $status,
        string $now,
    ): void {
        $changes = ['status' => $status, 'update_time' => $now];
        if ($status === 2) {
            $changes += [
                'current_online_state' => 2,
                'client_id' => null,
                'session_id' => null,
                'current_ip' => null,
                'current_ip_geo' => null,
            ];
        }
        $affected = Db::table('im_user_device')
            ->where('id', $id)
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->whereNull('delete_time')
            ->update($changes);
        if ($affected < 0) {
            throw new RuntimeException('IM 设备状态更新失败。');
        }
    }

    public function revokeSessions(
        int $organization,
        string $userId,
        string $deviceId,
        array $sessionIds,
        string $now,
    ): void {
        if ($sessionIds === []) {
            return;
        }

        Db::table('im_auth_session')
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->whereIn('session_id', $sessionIds)
            ->where('status', 1)
            ->update([
                'status' => 2,
                'revoked_at' => $now,
                'update_time' => $now,
            ]);
    }

    public function revokeSession(int $id, int $organization, string $sessionId, string $now): void
    {
        Db::table('im_auth_session')
            ->where('id', $id)
            ->where('organization', $organization)
            ->where('session_id', $sessionId)
            ->where('status', 1)
            ->update([
                'status' => 2,
                'revoked_at' => $now,
                'update_time' => $now,
            ]);
    }

    public function revokeWebAccessForDevice(
        int $organization,
        string $userId,
        string $deviceId,
        string $now,
    ): void {
        Db::table('im_web_access_session')
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('status', 1)
            ->whereNull('revoked_at')
            ->update([
                'status' => 2,
                'revoked_at' => $now,
                'update_time' => $now,
            ]);
    }

    public function revokeWebAccess(
        int $organization,
        string $userId,
        string $deviceId,
        string $webAccessJti,
        string $now,
    ): void {
        Db::table('im_web_access_session')
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('jti', $webAccessJti)
            ->where('status', 1)
            ->whereNull('revoked_at')
            ->update([
                'status' => 2,
                'revoked_at' => $now,
                'update_time' => $now,
            ]);
    }

    public function appendOperationAudit(
        array $actor,
        int $organization,
        string $action,
        array $target,
        string $now,
    ): void {
        try {
            $requestData = json_encode(
                $target,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('IM 运维审计数据序列化失败。', previous: $exception);
        }

        Db::table('sm_tool_oper_log')->insert([
            'organization' => $organization,
            'username' => $actor['username'],
            'app' => 'saimulti',
            'method' => 'SYSTEM',
            'router' => '/saimulti/admin/im/operations',
            'service_name' => $action,
            'ip' => $actor['ip'],
            'ip_location' => '',
            'request_data' => $requestData,
            'remark' => '平台管理员 IM 运维操作',
            'created_by' => $actor['id'],
            'updated_by' => $actor['id'],
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /** @return array<string, int> */
    private function aggregate(string $sql, array $params = []): array
    {
        $row = Db::query($sql, $params)[0] ?? [];
        $result = [];
        foreach ($row as $key => $value) {
            $result[(string) $key] = (int) $value;
        }

        return $result;
    }

    /**
     * @param list<mixed> $params
     * @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int}
     */
    private function page(
        string $fields,
        string $from,
        array $params,
        string $order,
        int $page,
        int $limit,
    ): array {
        $totalRow = Db::query('SELECT COUNT(*) AS aggregate ' . $from, $params)[0] ?? [];
        $offset = ($page - 1) * $limit;
        $data = Db::query(
            $fields . $from . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );

        return [
            'current_page' => $page,
            'data' => array_values($data),
            'per_page' => $limit,
            'total' => (int) ($totalRow['aggregate'] ?? 0),
        ];
    }

    /** @param list<string> $where @param list<mixed> $params */
    private function organizationFilter(array &$where, array &$params, string $alias, array $filters): void
    {
        if (isset($filters['organization'])) {
            $where[] = $alias . '.organization = ?';
            $params[] = (int) $filters['organization'];
        }
    }

    /** @param list<string> $where @param list<mixed> $params */
    private function statusFilter(array &$where, array &$params, string $column, array $filters): void
    {
        if (isset($filters['status'])) {
            $where[] = $column . ' = ?';
            $params[] = (int) $filters['status'];
        }
    }
}
