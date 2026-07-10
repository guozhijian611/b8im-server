<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

use Closure;
use plugin\saimulti\exception\ApiException;
use Throwable;

final class AdminImOperationsService
{
    private AdminImStoreInterface $store;

    private AdminImSessionCacheInterface $sessionCache;

    private AdminImStorageInspectorInterface $storageInspector;

    private AdminImRealtimePublisherInterface $realtimePublisher;

    /** @var Closure(): string */
    private Closure $clock;

    /** @param (Closure(): string)|null $clock */
    public function __construct(
        ?AdminImStoreInterface $store = null,
        ?AdminImSessionCacheInterface $sessionCache = null,
        ?AdminImStorageInspectorInterface $storageInspector = null,
        ?Closure $clock = null,
        ?AdminImRealtimePublisherInterface $realtimePublisher = null,
    ) {
        $this->store = $store ?? new ThinkOrmAdminImStore();
        $this->sessionCache = $sessionCache ?? new ThinkCacheAdminImSessionCache();
        $this->storageInspector = $storageInspector ?? new SaiAdminStorageInspector();
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
        $this->realtimePublisher = $realtimePublisher ?? new ThinkCacheAdminImRealtimePublisher();
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        try {
            $database = $this->store->databaseStatus();
        } catch (Throwable) {
            $database = ['status' => 'down'];
        }

        try {
            $schema = $database['status'] === 'up'
                ? $this->store->schemaStatus()
                : ['status' => 'unavailable', 'missing' => []];
        } catch (Throwable) {
            $schema = ['status' => 'unavailable', 'missing' => []];
        }

        $statistics = $this->emptyStatistics();
        if ($schema['status'] === 'ready') {
            try {
                $statistics = $this->store->statistics(($this->clock)());
            } catch (Throwable) {
                $schema = ['status' => 'unavailable', 'missing' => []];
            }
        }

        $redis = $this->sessionCache->status();
        $storage = $this->storageInspector->inspect();
        $healthy = $database['status'] === 'up'
            && $schema['status'] === 'ready'
            && $redis['status'] === 'up'
            && $storage['status'] === 'ready';

        return [
            'status' => $healthy ? 'healthy' : 'degraded',
            'database' => $database,
            'redis' => $redis,
            'im_schema' => $schema,
            'storage' => $storage,
            'statistics' => $statistics,
            'checked_at' => ($this->clock)(),
        ];
    }

    /** @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int} */
    public function users(array $input): array
    {
        [$filters, $page, $limit] = $this->listInput($input, 'user');

        return $this->store->users($filters, $page, $limit);
    }

    /** @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int} */
    public function devices(array $input): array
    {
        [$filters, $page, $limit] = $this->listInput($input, 'device');

        return $this->store->devices($filters, $page, $limit);
    }

    /** @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int} */
    public function sessions(array $input): array
    {
        [$filters, $page, $limit] = $this->listInput($input, 'session');
        $result = $this->store->sessions($filters, $page, $limit);
        $result['data'] = array_values(array_map(function (array $row): array {
            $sessionId = (string) ($row['session_id'] ?? '');
            unset($row['session_id']);
            $row['session_ref'] = $sessionId === '' ? '' : $this->sessionReference($sessionId);

            return $row;
        }, $result['data']));

        return $result;
    }

    /** @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int} */
    public function loginAudits(array $input): array
    {
        [$filters, $page, $limit] = $this->listInput($input, 'audit');

        return $this->store->loginAudits($filters, $page, $limit);
    }

    /**
     * @param array{id: int, username: string, ip: string} $actor
     * @return array<string, mixed>
     */
    public function setDeviceStatus(int $id, int $status, array $actor): array
    {
        $this->assertId($id, '设备记录');
        if (!in_array($status, [1, 2], true)) {
            throw new ApiException('设备状态必须为正常或停用。', 422);
        }
        $actor = $this->actor($actor);
        $now = ($this->clock)();

        $result = $this->store->transaction(function () use ($id, $status, $actor, $now): array {
            $device = $this->store->lockDeviceById($id);
            if ($device === null) {
                throw new ApiException('设备不存在。', 404);
            }
            $organization = (int) ($device['organization'] ?? 0);
            $userId = trim((string) ($device['user_id'] ?? ''));
            $deviceId = trim((string) ($device['device_id'] ?? ''));
            $clientId = $this->nullableString($device['client_id'] ?? null);
            $connectionSessionId = $this->nullableString($device['session_id'] ?? null);
            if ($organization <= 0 || $userId === '' || $deviceId === '') {
                throw new ApiException('设备的机构或用户范围无效。', 409);
            }

            $sessionIds = $status === 2
                ? $this->store->activeSessionIdsForDevice($organization, $userId, $deviceId)
                : [];
            $this->store->setDeviceStatus($id, $organization, $userId, $deviceId, $status, $now);
            if ($status === 2) {
                $this->store->revokeSessions($organization, $userId, $deviceId, $sessionIds, $now);
                $this->store->revokeWebAccessForDevice($organization, $userId, $deviceId, $now);
            }
            $this->store->appendOperationAudit(
                $actor,
                $organization,
                $status === 1 ? '启用 IM 设备' : '停用 IM 设备',
                [
                    'device_record_id' => $id,
                    'organization' => $organization,
                    'user_id' => $userId,
                    'device_id' => $deviceId,
                    'status' => $status,
                    'revoked_session_count' => count($sessionIds),
                ],
                $now,
            );

            return compact(
                'organization',
                'userId',
                'deviceId',
                'clientId',
                'connectionSessionId',
                'sessionIds',
            );
        });

        $cache = $this->invalidateSessions((int) $result['organization'], $result['sessionIds']);
        $realtimeEvent = $status === 2
            ? $this->publishRealtimeEvent('auth.device_disabled', [
                'organization' => (int) $result['organization'],
                'user_id' => (string) $result['userId'],
                'device_id' => (string) $result['deviceId'],
                'client_id' => $result['clientId'],
                'connection_session_id' => $result['connectionSessionId'],
                'credential_session_ids' => array_values($result['sessionIds']),
                'occurred_at' => $now,
            ])
            : ['status' => 'not_required'];

        return [
            'id' => $id,
            'organization' => (int) $result['organization'],
            'user_id' => (string) $result['userId'],
            'device_id' => (string) $result['deviceId'],
            'status' => $status,
            'revoked_session_count' => count($result['sessionIds']),
            'cache_invalidation' => $cache,
            'realtime_event' => $realtimeEvent,
        ];
    }

    /**
     * @param array{id: int, username: string, ip: string} $actor
     * @return array<string, mixed>
     */
    public function revokeSession(int $id, array $actor): array
    {
        $this->assertId($id, '会话记录');
        $actor = $this->actor($actor);
        $now = ($this->clock)();

        $result = $this->store->transaction(function () use ($id, $actor, $now): array {
            $session = $this->store->lockSessionById($id);
            if ($session === null) {
                throw new ApiException('会话不存在。', 404);
            }
            $organization = (int) ($session['organization'] ?? 0);
            $userId = trim((string) ($session['user_id'] ?? ''));
            $deviceId = trim((string) ($session['device_id'] ?? ''));
            $sessionId = trim((string) ($session['session_id'] ?? ''));
            $webAccessJti = trim((string) ($session['web_access_jti'] ?? ''));
            $clientId = $this->nullableString($session['client_id'] ?? null);
            if ($organization <= 0 || $userId === '' || $deviceId === '' || $sessionId === '') {
                throw new ApiException('会话的机构或身份范围无效。', 409);
            }

            $linkedWebAccess = preg_match('/^[a-f0-9]{32}$/', $webAccessJti) === 1;
            $sessionIds = $linkedWebAccess
                ? $this->store->activeSessionIdsForWebAccess($organization, $userId, $deviceId, $webAccessJti)
                : [$sessionId];
            if (!in_array($sessionId, $sessionIds, true)) {
                $sessionIds[] = $sessionId;
            }
            $sessionIds = array_values(array_unique($sessionIds));
            $this->store->revokeSessions($organization, $userId, $deviceId, $sessionIds, $now);
            if ($linkedWebAccess) {
                $this->store->revokeWebAccess($organization, $userId, $deviceId, $webAccessJti, $now);
            }
            $this->store->appendOperationAudit(
                $actor,
                $organization,
                '撤销 IM 会话',
                [
                    'session_record_id' => $id,
                    'session_ref' => $this->sessionReference($sessionId),
                    'organization' => $organization,
                    'user_id' => $userId,
                    'device_id' => $deviceId,
                    'linked_web_access_revoked' => $linkedWebAccess ? 1 : 0,
                    'revoked_session_count' => count($sessionIds),
                ],
                $now,
            );

            return compact('organization', 'userId', 'deviceId', 'clientId', 'sessionId', 'sessionIds');
        });

        $cache = $this->invalidateSessions((int) $result['organization'], $result['sessionIds']);
        $realtimeStatuses = [];
        foreach ($result['sessionIds'] as $credentialSessionId) {
            $realtimeStatuses[] = $this->publishRealtimeEvent('auth.session_revoked', [
                'organization' => (int) $result['organization'],
                'user_id' => (string) $result['userId'],
                'device_id' => (string) $result['deviceId'],
                'client_id' => count($result['sessionIds']) === 1 ? $result['clientId'] : null,
                'credential_session_ids' => [(string) $credentialSessionId],
                'occurred_at' => $now,
            ])['status'];
        }
        $realtimeEvent = [
            'status' => !in_array('failed_bounded_fallback', $realtimeStatuses, true)
                ? 'published'
                : 'failed_bounded_fallback',
        ];

        return [
            'id' => $id,
            'organization' => (int) $result['organization'],
            'user_id' => (string) $result['userId'],
            'device_id' => (string) $result['deviceId'],
            'status' => 2,
            'revoked_session_count' => count($result['sessionIds']),
            'cache_invalidation' => $cache,
            'realtime_event' => $realtimeEvent,
        ];
    }

    /** @return array<string, array<string, int>> */
    private function emptyStatistics(): array
    {
        return [
            'users' => ['total' => 0, 'active' => 0, 'disabled' => 0, 'banned' => 0],
            'devices' => ['total' => 0, 'online' => 0, 'disabled' => 0],
            'sessions' => ['total' => 0, 'active' => 0, 'revoked' => 0, 'expired' => 0],
            'outbox' => ['total' => 0, 'pending' => 0, 'publishing' => 0, 'published' => 0, 'failed' => 0],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: int, 2: int}
     */
    private function listInput(array $input, string $type): array
    {
        $page = $this->integer($input['page'] ?? 1, '页码');
        $limit = $this->integer($input['limit'] ?? 20, '每页条数');
        if ($page < 1 || $limit < 1 || $limit > 100) {
            throw new ApiException('分页参数无效。', 422);
        }

        $filters = [];
        if (isset($input['organization']) && $input['organization'] !== '') {
            $organization = $this->integer($input['organization'], '机构编号');
            if ($organization <= 0) {
                throw new ApiException('机构编号无效。', 422);
            }
            $filters['organization'] = $organization;
        }

        $keyword = trim((string) ($input['keyword'] ?? ''));
        if (mb_strlen($keyword) > 100) {
            throw new ApiException('搜索词不能超过 100 个字符。', 422);
        }
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
        }

        if (isset($input['status']) && $input['status'] !== '') {
            $status = $this->integer($input['status'], '状态');
            $allowed = $type === 'user' ? [1, 2, 3] : [1, 2];
            if (!in_array($status, $allowed, true)) {
                throw new ApiException('状态筛选值无效。', 422);
            }
            $filters['status'] = $status;
        }

        if ($type === 'device' && isset($input['online_state']) && $input['online_state'] !== '') {
            $onlineState = $this->integer($input['online_state'], '在线状态');
            if (!in_array($onlineState, [1, 2], true)) {
                throw new ApiException('在线状态筛选值无效。', 422);
            }
            $filters['online_state'] = $onlineState;
        }

        if ($type === 'audit') {
            $loginResult = trim((string) ($input['login_result'] ?? ''));
            if ($loginResult !== '') {
                if (!in_array($loginResult, ['success', 'failed', 'kicked', 'logout', 'inactive'], true)) {
                    throw new ApiException('登录结果筛选值无效。', 422);
                }
                $filters['login_result'] = $loginResult;
            }
        }

        return [$filters, $page, $limit];
    }

    /**
     * @param list<string> $sessionIds
     * @return array{status: string, failed: int, max_stale_seconds: int}
     */
    private function invalidateSessions(int $organization, array $sessionIds): array
    {
        if ($sessionIds === []) {
            return ['status' => 'not_required', 'failed' => 0, 'max_stale_seconds' => 0];
        }

        $failed = 0;
        foreach (array_values(array_unique($sessionIds)) as $sessionId) {
            if (!$this->sessionCache->invalidate($organization, $sessionId)) {
                $failed++;
            }
        }

        return [
            'status' => $failed === 0 ? 'invalidated' : 'mysql_authoritative_bounded_fallback',
            'failed' => $failed,
            'max_stale_seconds' => $failed === 0 ? 0 : $this->sessionCache->maxStaleSeconds(),
        ];
    }

    /** @param array<string, mixed> $payload @return array{status: string} */
    private function publishRealtimeEvent(string $type, array $payload): array
    {
        return [
            'status' => $this->realtimePublisher->publish($type, $payload)
                ? 'published'
                : 'failed_bounded_fallback',
        ];
    }

    /** @param array{id?: mixed, username?: mixed, ip?: mixed} $actor */
    private function actor(array $actor): array
    {
        $id = $this->integer($actor['id'] ?? 0, '管理员编号');
        $username = trim((string) ($actor['username'] ?? ''));
        $ip = trim((string) ($actor['ip'] ?? ''));
        if ($id <= 0 || $username === '') {
            throw new ApiException('管理员身份无效。', 401);
        }

        return ['id' => $id, 'username' => $username, 'ip' => $ip];
    }

    private function assertId(int $id, string $label): void
    {
        if ($id <= 0) {
            throw new ApiException($label . '编号无效。', 422);
        }
    }

    private function integer(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?\d+$/', $value) !== 1) {
            throw new ApiException($label . '必须为整数。', 422);
        }

        return (int) $value;
    }

    private function sessionReference(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 16);
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
    }
}
