<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\imUser;

use Closure;
use JsonException;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\adminIm\ThinkCacheAdminImRealtimePublisher;
use plugin\saimulti\service\adminIm\ThinkCacheAdminImSessionCache;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use support\think\Db;

final class ImUserManagementService
{
    private const QUOTA_KEY = 'im_user_seats';

    /** @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int} */
    public function index(array $input, ?int $fixedOrganization = null): array
    {
        $page = $this->positiveInteger($input['page'] ?? 1, '页码');
        $limit = $this->positiveInteger($input['limit'] ?? 20, '每页条数');
        if ($limit > 100) {
            throw new ApiException('每页最多查询 100 条数据。', 422);
        }

        $where = ['u.is_system = 2', 'u.delete_time IS NULL'];
        $params = [];
        $organization = $fixedOrganization;
        if ($organization === null && ($input['organization'] ?? '') !== '') {
            $organization = $this->positiveInteger($input['organization'], '机构编号');
        }
        if ($organization !== null) {
            $where[] = 'u.organization = ?';
            $params[] = $organization;
        }

        $keyword = trim((string) ($input['keyword'] ?? ''));
        if (mb_strlen($keyword) > 100) {
            throw new ApiException('搜索词不能超过 100 个字符。', 422);
        }
        if ($keyword !== '') {
            $where[] = '(u.account LIKE ? OR u.nickname LIKE ? OR u.user_id LIKE ? OR u.im_short_no LIKE ? OR u.mobile LIKE ? OR u.email LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if (($input['status'] ?? '') !== '') {
            $status = $this->status($input['status']);
            $where[] = 'u.status = ?';
            $params[] = $status;
        }

        $from = ' FROM im_user u '
            . 'LEFT JOIN im_user_profile p ON p.organization = u.organization AND p.user_id = u.user_id AND p.delete_time IS NULL '
            . 'LEFT JOIN sm_system_organization o ON o.id = u.organization AND o.delete_time IS NULL '
            . 'WHERE ' . implode(' AND ', $where);
        $totalRow = Db::query('SELECT COUNT(*) AS total' . $from, $params)[0] ?? [];
        $offset = ($page - 1) * $limit;
        $rows = Db::query(
            'SELECT u.id, u.organization, o.organization_name, u.user_id, u.im_short_no, u.account, '
            . 'u.nickname, u.avatar, u.mobile, u.email, u.gender, u.status, u.remark, '
            . 'u.login_time, u.create_time, u.update_time, COALESCE(p.signature, \'\') AS signature'
            . $from . sprintf(' ORDER BY u.id DESC LIMIT %d OFFSET %d', $limit, $offset),
            $params,
        );

        return [
            'current_page' => $page,
            'data' => array_values($rows),
            'per_page' => $limit,
            'total' => (int) ($totalRow['total'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    public function read(int $id, ?int $fixedOrganization = null): array
    {
        $params = [$id];
        $scope = '';
        if ($fixedOrganization !== null) {
            $scope = ' AND u.organization = ?';
            $params[] = $fixedOrganization;
        }
        $rows = Db::query(
            'SELECT u.id, u.organization, o.organization_name, u.user_id, u.im_short_no, u.account, '
            . 'u.nickname, u.avatar, u.mobile, u.email, u.gender, u.status, u.remark, '
            . 'u.login_time, u.create_time, u.update_time, COALESCE(p.signature, \'\') AS signature '
            . 'FROM im_user u '
            . 'LEFT JOIN im_user_profile p ON p.organization = u.organization AND p.user_id = u.user_id AND p.delete_time IS NULL '
            . 'LEFT JOIN sm_system_organization o ON o.id = u.organization AND o.delete_time IS NULL '
            . 'WHERE u.id = ? AND u.is_system = 2 AND u.delete_time IS NULL' . $scope . ' LIMIT 1',
            $params,
        );
        if ($rows === []) {
            throw new ApiException('IM 用户不存在。', 404);
        }

        return $rows[0];
    }

    /** @param array{type:string,id:int,username:string,ip:string} $actor */
    public function create(int $organization, array $input, array $actor): array
    {
        $organization = $this->positiveInteger($organization, '机构编号');
        $data = $this->userWriteData($input);
        $status = $this->status($input['status'] ?? 1);
        $password = (string) ($input['password'] ?? '');
        $signature = trim((string) ($input['signature'] ?? ''));
        $now = date('Y-m-d H:i:s');

        $id = Db::transaction(function () use ($organization, $data, $status, $password, $signature, $actor, $now): int {
            $this->lockActiveOrganization($organization);
            $this->assertUnique($organization, $data['account'], $data['im_short_no'], null);
            if ($status === 1) {
                $this->assertSeatAvailable($organization);
            }
            $user = $this->provisionUser($organization, $data, $password, $signature, $status, $now);
            $id = (int) $user['id'];
            $userId = (string) $user['user_id'];
            $this->syncUsedSeats($organization, $now);
            $this->audit($actor, $organization, '创建 IM 用户', [
                'target' => ['id' => $id, 'user_id' => $userId],
                'after' => $this->safeUserData($data + ['status' => $status]),
            ], $now);

            return $id;
        });

        return $this->read($id, $organization);
    }

    /**
     * Public registration shares the canonical user/profile/privacy/security/quota
     * provisioning path. Both callbacks run inside the same transaction after
     * organization locking; the first locks/checks account policy, the second
     * persists the access session before the user can become visible.
     *
     * @param Closure(int):void $beforeProvision
     * @param Closure(array<string,mixed>,string):array<string,mixed> $afterProvision
     * @return array<string,mixed>
     */
    public function register(
        int $organization,
        array $input,
        Closure $beforeProvision,
        Closure $afterProvision,
    ): array {
        $organization = $this->positiveInteger($organization, '机构编号');
        $data = $this->userWriteData($input);
        $password = (string) ($input['password'] ?? '');
        $signature = trim((string) ($input['signature'] ?? ''));
        $now = date('Y-m-d H:i:s');

        return Db::transaction(function () use (
            $organization,
            $data,
            $password,
            $signature,
            $beforeProvision,
            $afterProvision,
            $now,
        ): array {
            $this->lockActiveOrganization($organization);
            $beforeProvision($organization);
            $this->assertUnique($organization, $data['account'], null, null);
            $this->assertSeatAvailable($organization);
            $user = $this->provisionUser($organization, $data, $password, $signature, 1, $now);
            $this->syncUsedSeats($organization, $now);

            return $afterProvision($user, $now);
        });
    }

    /** @param array{type:string,id:int,username:string,ip:string} $actor */
    public function update(int $id, ?int $fixedOrganization, array $input, array $actor): array
    {
        $data = $this->userWriteData($input);
        $signature = trim((string) ($input['signature'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $organization = Db::transaction(function () use ($id, $fixedOrganization, $data, $signature, $actor, $now): int {
            $before = $this->lockUser($id, $fixedOrganization);
            $organization = (int) $before['organization'];
            $this->assertUnique($organization, $data['account'], $data['im_short_no'], $id);
            Db::table('im_user')->where('id', $id)->update($data + ['update_time' => $now]);
            Db::table('im_user_profile')
                ->where('organization', $organization)
                ->where('user_id', (string) $before['user_id'])
                ->update(['signature' => $signature === '' ? null : $signature, 'update_time' => $now]);
            $this->audit($actor, $organization, '编辑 IM 用户', [
                'target' => ['id' => $id, 'user_id' => (string) $before['user_id']],
                'before' => $this->safeUserData($before),
                'after' => $this->safeUserData($data),
            ], $now);

            return $organization;
        });

        return $this->read($id, $organization);
    }

    /** @param array{type:string,id:int,username:string,ip:string} $actor */
    public function setStatus(int $id, ?int $fixedOrganization, int $status, array $actor): array
    {
        $status = $this->status($status);
        $now = date('Y-m-d H:i:s');
        $result = Db::transaction(function () use ($id, $fixedOrganization, $status, $actor, $now): array {
            $before = $this->lockUser($id, $fixedOrganization);
            $organization = (int) $before['organization'];
            if ((int) $before['status'] !== 1 && $status === 1) {
                $this->assertSeatAvailable($organization);
            }
            Db::table('im_user')->where('id', $id)->update(['status' => $status, 'update_time' => $now]);
            $access = $status === 1
                ? ['sessions' => [], 'devices' => []]
                : $this->revokeUserAccess($organization, (string) $before['user_id'], $now);
            $this->syncUsedSeats($organization, $now);
            $this->audit($actor, $organization, '变更 IM 用户状态', [
                'target' => ['id' => $id, 'user_id' => (string) $before['user_id']],
                'before' => ['status' => (int) $before['status']],
                'after' => ['status' => $status],
                'revoked_session_count' => count($access['sessions']),
            ], $now);

            return ['organization' => $organization, 'user_id' => (string) $before['user_id']] + $access;
        });
        $this->invalidateAccess($result, $now);

        return $this->read($id, (int) $result['organization']);
    }

    /** @param array{type:string,id:int,username:string,ip:string} $actor */
    public function resetPassword(int $id, ?int $fixedOrganization, string $password, array $actor): array
    {
        $now = date('Y-m-d H:i:s');
        $result = Db::transaction(function () use ($id, $fixedOrganization, $password, $actor, $now): array {
            $user = $this->lockUser($id, $fixedOrganization);
            $organization = (int) $user['organization'];
            Db::table('im_user')->where('id', $id)->update([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'update_time' => $now,
            ]);
            $access = $this->revokeUserAccess($organization, (string) $user['user_id'], $now);
            $this->audit($actor, $organization, '重置 IM 用户密码', [
                'target' => ['id' => $id, 'user_id' => (string) $user['user_id']],
                'revoked_session_count' => count($access['sessions']),
            ], $now);

            return ['organization' => $organization, 'user_id' => (string) $user['user_id']] + $access;
        });
        $this->invalidateAccess($result, $now);

        return ['id' => $id, 'revoked_session_count' => count($result['sessions'])];
    }

    /** @return array{organization:int,quota_key:string,quota_value:int,used_value:int,remaining_value:int,configured:bool} */
    public function quota(int $organization): array
    {
        $organization = $this->positiveInteger($organization, '机构编号');
        $row = Db::table('sm_tenant_quota')
            ->where('organization', $organization)
            ->where('quota_key', self::QUOTA_KEY)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('start_at')->whereOr('start_at', '<=', date('Y-m-d H:i:s'));
            })
            ->where(function ($query) {
                $query->whereNull('end_at')->whereOr('end_at', '>', date('Y-m-d H:i:s'));
            })
            ->whereNull('delete_time')
            ->find();
        $used = $this->activeUserCount($organization);
        $value = $row ? (int) $row['quota_value'] : 0;

        return [
            'organization' => $organization,
            'quota_key' => self::QUOTA_KEY,
            'quota_value' => $value,
            'used_value' => $used,
            'remaining_value' => max(0, $value - $used),
            'configured' => $row !== null,
        ];
    }

    /** @param array{type:string,id:int,username:string,ip:string} $actor */
    public function updateQuota(int $organization, int $quotaValue, array $actor): array
    {
        $organization = $this->positiveInteger($organization, '机构编号');
        if ($quotaValue < 0) {
            throw new ApiException('席位数不能小于 0。', 422);
        }
        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($organization, $quotaValue, $actor, $now): void {
            $this->lockActiveOrganization($organization);
            $used = $this->activeUserCount($organization, true);
            if ($quotaValue < $used) {
                throw new ApiException(sprintf('席位数不能低于当前已启用用户数 %d。', $used), 422);
            }
            $row = Db::table('sm_tenant_quota')
                ->where('organization', $organization)
                ->where('quota_key', self::QUOTA_KEY)
                ->lock(true)
                ->find();
            if ($row) {
                Db::table('sm_tenant_quota')->where('id', (int) $row['id'])->update([
                    'quota_value' => $quotaValue,
                    'used_value' => $used,
                    'source' => 'manual',
                    'status' => 'active',
                    'start_at' => null,
                    'end_at' => null,
                    'delete_time' => null,
                    'version' => (int) $row['version'] + 1,
                    'updated_by' => $actor['id'],
                    'update_time' => $now,
                ]);
            } else {
                Db::table('sm_tenant_quota')->insert([
                    'organization' => $organization,
                    'quota_key' => self::QUOTA_KEY,
                    'quota_value' => $quotaValue,
                    'used_value' => $used,
                    'source' => 'manual',
                    'status' => 'active',
                    'version' => 1,
                    'created_by' => $actor['id'],
                    'updated_by' => $actor['id'],
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
            $this->audit($actor, $organization, '配置 IM 用户席位', [
                'before' => ['quota_value' => $row ? (int) $row['quota_value'] : null],
                'after' => ['quota_value' => $quotaValue, 'used_value' => $used],
            ], $now);
        });

        return $this->quota($organization);
    }

    /** @return array<string,mixed> */
    private function userWriteData(array $input): array
    {
        $nullable = static fn (mixed $value): ?string => trim((string) $value) === '' ? null : trim((string) $value);

        return [
            'account' => trim((string) ($input['account'] ?? '')),
            'im_short_no' => $nullable($input['im_short_no'] ?? null),
            'nickname' => trim((string) ($input['nickname'] ?? '')),
            'avatar' => $nullable($input['avatar'] ?? null),
            'mobile' => $nullable($input['mobile'] ?? null),
            'email' => $nullable($input['email'] ?? null),
            'gender' => (int) ($input['gender'] ?? 0),
            'remark' => $nullable($input['remark'] ?? null),
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function provisionUser(
        int $organization,
        array $data,
        string $password,
        string $signature,
        int $status,
        string $now,
    ): array {
        $userId = Uuid::uuid4()->toString();
        $id = (int) Db::table('im_user')->insertGetId($data + [
            'organization' => $organization,
            'user_id' => $userId,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_system' => 2,
            'status' => $status,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::table('im_user_profile')->insert([
            'organization' => $organization,
            'user_id' => $userId,
            'signature' => $signature === '' ? null : $signature,
            'status' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::table('im_user_privacy_setting')->insert([
            'organization' => $organization,
            'user_id' => $userId,
            'allow_add_by_mobile' => 1,
            'allow_add_by_short_no' => 1,
            'allow_add_by_username' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::table('im_user_security_policy')->insert([
            'organization' => $organization,
            'user_id' => $userId,
            'login_ip_policy' => 'disabled',
            'status' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->read($id, $organization);
    }

    private function lockActiveOrganization(int $organization): void
    {
        $row = Db::table('sm_system_organization')->where('id', $organization)->lock(true)->find();
        if (!$row || (int) $row['status'] !== 1 || $row['delete_time'] !== null) {
            throw new ApiException('所属机构不存在或已停用。', 422);
        }
    }

    /** @return array<string,mixed> */
    private function lockUser(int $id, ?int $fixedOrganization): array
    {
        $candidateQuery = Db::table('im_user')->where('id', $id)->where('is_system', 2)->whereNull('delete_time');
        if ($fixedOrganization !== null) {
            $candidateQuery->where('organization', $fixedOrganization);
        }
        $candidate = $candidateQuery->find();
        if (!$candidate) {
            throw new ApiException('IM 用户不存在或无权操作。', 404);
        }
        $organization = (int) $candidate['organization'];
        $this->lockActiveOrganization($organization);
        $row = Db::table('im_user')
            ->where('id', $id)
            ->where('organization', $organization)
            ->where('is_system', 2)
            ->whereNull('delete_time')
            ->lock(true)
            ->find();
        if (!$row) {
            throw new ApiException('IM 用户不存在或无权操作。', 404);
        }

        return $row;
    }

    private function assertUnique(int $organization, string $account, ?string $shortNo, ?int $ignoreId): void
    {
        $accountQuery = Db::table('im_user')->where('organization', $organization)->where('account', $account);
        if ($ignoreId !== null) {
            $accountQuery->where('id', '<>', $ignoreId);
        }
        if ($accountQuery->lock(true)->find()) {
            throw new ApiException('当前机构已存在相同登录账号。', 422);
        }
        if ($shortNo !== null) {
            $shortQuery = Db::table('im_user')->where('im_short_no', $shortNo);
            if ($ignoreId !== null) {
                $shortQuery->where('id', '<>', $ignoreId);
            }
            if ($shortQuery->lock(true)->find()) {
                throw new ApiException('IM 短号已被使用。', 422);
            }
        }
    }

    private function assertSeatAvailable(int $organization): void
    {
        $quota = Db::table('sm_tenant_quota')
            ->where('organization', $organization)
            ->where('quota_key', self::QUOTA_KEY)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('start_at')->whereOr('start_at', '<=', date('Y-m-d H:i:s'));
            })
            ->where(function ($query) {
                $query->whereNull('end_at')->whereOr('end_at', '>', date('Y-m-d H:i:s'));
            })
            ->whereNull('delete_time')
            ->lock(true)
            ->find();
        if (!$quota) {
            throw new ApiException('该机构尚未配置 IM 用户席位。', 422);
        }
        $used = $this->activeUserCount($organization, true);
        if ($used >= (int) $quota['quota_value']) {
            throw new ApiException(sprintf('IM 用户席位已用满（%d/%d）。', $used, (int) $quota['quota_value']), 422);
        }
    }

    private function activeUserCount(int $organization, bool $lock = false): int
    {
        if ($lock) {
            $rows = Db::query(
                'SELECT id FROM im_user WHERE organization = ? AND is_system = 2 AND status = 1 AND delete_time IS NULL FOR UPDATE',
                [$organization],
            );
            return count($rows);
        }

        return (int) Db::table('im_user')
            ->where('organization', $organization)
            ->where('is_system', 2)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->count();
    }

    private function syncUsedSeats(int $organization, string $now): void
    {
        Db::table('sm_tenant_quota')
            ->where('organization', $organization)
            ->where('quota_key', self::QUOTA_KEY)
            ->whereNull('delete_time')
            ->update(['used_value' => $this->activeUserCount($organization, true), 'update_time' => $now]);
    }

    /** @return array{sessions:list<array<string,mixed>>,devices:list<array<string,mixed>>} */
    private function revokeUserAccess(int $organization, string $userId, string $now): array
    {
        $sessions = Db::query(
            'SELECT session_id, device_id, client_id FROM im_auth_session '
            . 'WHERE organization = ? AND user_id = ? AND status = 1 FOR UPDATE',
            [$organization, $userId],
        );
        $devices = Db::query(
            'SELECT device_id, client_id, session_id FROM im_user_device '
            . 'WHERE organization = ? AND user_id = ? AND delete_time IS NULL FOR UPDATE',
            [$organization, $userId],
        );
        Db::table('im_auth_session')->where('organization', $organization)->where('user_id', $userId)->where('status', 1)->update([
            'status' => 2, 'revoked_at' => $now, 'update_time' => $now,
        ]);
        Db::table('im_web_access_session')->where('organization', $organization)->where('user_id', $userId)->where('status', 1)->update([
            'status' => 2, 'revoked_at' => $now, 'update_time' => $now,
        ]);
        Db::table('im_user_device')->where('organization', $organization)->where('user_id', $userId)->whereNull('delete_time')->update([
            'current_online_state' => 2,
            'client_id' => null,
            'session_id' => null,
            'current_ip' => null,
            'current_ip_geo' => null,
            'update_time' => $now,
        ]);

        return ['sessions' => array_values($sessions), 'devices' => array_values($devices)];
    }

    /** @param array{organization:int,user_id:string,sessions:list<array<string,mixed>>,devices:list<array<string,mixed>>} $access */
    private function invalidateAccess(array $access, string $now): void
    {
        $cache = new ThinkCacheAdminImSessionCache();
        $publisher = new ThinkCacheAdminImRealtimePublisher();
        foreach ($access['sessions'] as $session) {
            $sessionId = trim((string) ($session['session_id'] ?? ''));
            if ($sessionId === '') {
                continue;
            }
            $cache->invalidate((int) $access['organization'], $sessionId);
            $publisher->publish('auth.session_revoked', [
                'organization' => (int) $access['organization'],
                'user_id' => (string) $access['user_id'],
                'device_id' => (string) ($session['device_id'] ?? ''),
                'client_id' => $session['client_id'] ?? null,
                'credential_session_ids' => [$sessionId],
                'occurred_at' => $now,
            ]);
        }
    }

    /** @param array{type:string,id:int,username:string,ip:string} $actor */
    private function audit(array $actor, int $organization, string $action, array $payload, string $now): void
    {
        try {
            $requestData = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('IM 用户审计数据序列化失败。', previous: $exception);
        }
        Db::table('sm_tool_oper_log')->insert([
            'organization' => $organization,
            'username' => $actor['username'],
            'app' => 'saimulti',
            'method' => 'SYSTEM',
            'router' => '/saimulti/' . $actor['type'] . '/im/user',
            'service_name' => $action,
            'ip' => $actor['ip'],
            'ip_location' => '',
            'request_data' => $requestData,
            'remark' => 'IM 用户管理操作',
            'created_by' => $actor['id'],
            'updated_by' => $actor['id'],
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /** @return array<string,mixed> */
    private function safeUserData(array $data): array
    {
        unset($data['password_hash'], $data['password'], $data['password_confirm']);
        return $data;
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $parsed = (int) $value;
        } else {
            throw new ApiException($label . '必须是正整数。', 422);
        }
        if ($parsed <= 0) {
            throw new ApiException($label . '必须是正整数。', 422);
        }
        return $parsed;
    }

    private function status(mixed $value): int
    {
        $status = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);
        if (!in_array($status, [1, 2, 3], true)) {
            throw new ApiException('用户状态值无效。', 422);
        }
        return $status;
    }
}
