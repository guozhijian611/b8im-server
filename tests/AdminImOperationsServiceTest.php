<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\app\logic\system\SystemOrganizationLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\adminIm\AdminImOperationsService;
use plugin\saimulti\service\adminIm\AdminImRealtimePublisherInterface;
use plugin\saimulti\service\adminIm\AdminImSessionCacheInterface;
use plugin\saimulti\service\adminIm\AdminImStorageInspectorInterface;
use plugin\saimulti\service\adminIm\AdminImStoreInterface;
use plugin\saimulti\service\adminIm\OrganizationImAccessService;
use plugin\saimulti\service\adminIm\SaiAdminStorageInspector;
use plugin\saimulti\service\adminIm\ThinkCacheAdminImSessionCache;
use plugin\saimulti\service\adminIm\ThinkCacheAdminImRealtimePublisher;

final class FakeAdminImSequence
{
    /** @var list<string> */
    public array $events = [];
}

final class FakeAdminImStore implements AdminImStoreInterface
{
    public function __construct(public readonly ?FakeAdminImSequence $sequence = null)
    {
    }

    /** @var array<string, mixed> */
    public array $receivedFilters = [];

    /** @var list<array<string, mixed>> */
    public array $audits = [];

    /** @var list<string> */
    public array $revokedSessionIds = [];

    /** @var list<array<string, mixed>> */
    public array $revokedWebAccess = [];

    public ?array $device = [
        'id' => 9,
        'organization' => 7,
        'user_id' => 'user-7',
        'device_id' => 'browser-7',
        'client_id' => 'client-7',
        'session_id' => 'connection-session-7',
        'status' => 1,
    ];

    public ?array $session = [
        'id' => 15,
        'organization' => 7,
        'user_id' => 'user-7',
        'device_id' => 'browser-7',
        'client_id' => 'client-7',
        'session_id' => 'credential-session-7',
        'web_access_jti' => '0123456789abcdef0123456789abcdef',
        'status' => 1,
    ];

    public function databaseStatus(): array
    {
        return ['status' => 'up'];
    }

    public function schemaStatus(): array
    {
        return ['status' => 'ready', 'missing' => []];
    }

    public function statistics(string $now): array
    {
        return [
            'users' => ['total' => 2, 'active' => 1, 'disabled' => 1, 'banned' => 0],
            'devices' => ['total' => 2, 'online' => 1, 'disabled' => 1],
            'sessions' => ['total' => 2, 'active' => 1, 'revoked' => 1, 'expired' => 0],
            'outbox' => ['total' => 1, 'pending' => 1, 'publishing' => 0, 'published' => 0, 'failed' => 0],
        ];
    }

    public function users(array $filters, int $page, int $limit): array
    {
        $this->receivedFilters = compact('filters', 'page', 'limit');

        return $this->page([]);
    }

    public function devices(array $filters, int $page, int $limit): array
    {
        $this->receivedFilters = compact('filters', 'page', 'limit');

        return $this->page([]);
    }

    public function sessions(array $filters, int $page, int $limit): array
    {
        $this->receivedFilters = compact('filters', 'page', 'limit');

        return $this->page([[
            'id' => 15,
            'organization' => 7,
            'session_id' => 'credential-session-7',
            'status' => 1,
        ]]);
    }

    public function loginAudits(array $filters, int $page, int $limit): array
    {
        $this->receivedFilters = compact('filters', 'page', 'limit');

        return $this->page([]);
    }

    public function transaction(callable $callback): mixed
    {
        $result = $callback();
        if ($this->sequence !== null) {
            $this->sequence->events[] = 'db_commit';
        }

        return $result;
    }

    public function lockDeviceById(int $id): ?array
    {
        return $id === 9 ? $this->device : null;
    }

    public function lockSessionById(int $id): ?array
    {
        return $id === 15 ? $this->session : null;
    }

    public function activeSessionIdsForDevice(int $organization, string $userId, string $deviceId): array
    {
        return ['credential-session-7', 'credential-session-8'];
    }

    public function activeSessionIdsForWebAccess(
        int $organization,
        string $userId,
        string $deviceId,
        string $webAccessJti,
    ): array {
        return ['credential-session-7', 'credential-session-8'];
    }

    public function setDeviceStatus(
        int $id,
        int $organization,
        string $userId,
        string $deviceId,
        int $status,
        string $now,
    ): void {
        $this->device['status'] = $status;
    }

    public function revokeSessions(
        int $organization,
        string $userId,
        string $deviceId,
        array $sessionIds,
        string $now,
    ): void {
        $this->revokedSessionIds = $sessionIds;
    }

    public function revokeSession(int $id, int $organization, string $sessionId, string $now): void
    {
        $this->revokedSessionIds[] = $sessionId;
        $this->session['status'] = 2;
    }

    public function revokeWebAccessForDevice(
        int $organization,
        string $userId,
        string $deviceId,
        string $now,
    ): void {
        $this->revokedWebAccess[] = compact('organization', 'userId', 'deviceId', 'now');
    }

    public function revokeWebAccess(
        int $organization,
        string $userId,
        string $deviceId,
        string $webAccessJti,
        string $now,
    ): void {
        $this->revokedWebAccess[] = [
            'organization' => $organization,
            'userId' => $userId,
            'deviceId' => $deviceId,
            'webAccessJti' => $webAccessJti,
            'now' => $now,
        ];
    }

    public function appendOperationAudit(
        array $actor,
        int $organization,
        string $action,
        array $target,
        string $now,
    ): void {
        $this->audits[] = compact('actor', 'organization', 'action', 'target', 'now');
    }

    private function page(array $data): array
    {
        return ['current_page' => 1, 'data' => $data, 'per_page' => 20, 'total' => count($data)];
    }
}

final class FakeAdminImSessionCache implements AdminImSessionCacheInterface
{
    public function __construct(public readonly ?FakeAdminImSequence $sequence = null)
    {
    }

    /** @var list<array{0: int, 1: string}> */
    public array $invalidated = [];

    public bool $succeeds = true;

    public function status(): array
    {
        return ['status' => 'up', 'max_stale_seconds' => 3];
    }

    public function invalidate(int $organization, string $sessionId): bool
    {
        $this->invalidated[] = [$organization, $sessionId];
        if ($this->sequence !== null) {
            $this->sequence->events[] = 'cache_invalidate';
        }

        return $this->succeeds;
    }

    public function maxStaleSeconds(): int
    {
        return 3;
    }
}

final class FakeAdminImRealtimePublisher implements AdminImRealtimePublisherInterface
{
    /** @var list<array{type: string, payload: array<string, mixed>}> */
    public array $published = [];

    public bool $succeeds = true;

    public function __construct(public readonly ?FakeAdminImSequence $sequence = null)
    {
    }

    public function publish(string $type, array $payload): bool
    {
        if ($this->sequence !== null) {
            $this->sequence->events[] = 'realtime_publish';
        }
        $this->published[] = compact('type', 'payload');

        return $this->succeeds;
    }
}

final class FakeAdminImStorageInspector implements AdminImStorageInspectorInterface
{
    public function inspect(): array
    {
        return [
            'status' => 'ready',
            'mode' => '5',
            'label' => 'S3 存储',
            'configured' => true,
            'missing' => [],
        ];
    }
}

final class FakeRedisHandler
{
    /** @var list<string> */
    public array $deleted = [];

    /** @var list<array{0: string, 1: string}> */
    public array $pushed = [];

    /** @var list<array{0: string, 1: int, 2: string}> */
    public array $expiring = [];

    public bool $deleteSucceeds = true;

    public bool $pushSucceeds = true;

    public function ping(): string
    {
        return '+PONG';
    }

    public function del(string $key): int|false
    {
        $this->deleted[] = $key;

        return $this->deleteSucceeds ? 1 : false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->expiring[] = [$key, $ttl, $value];

        return true;
    }

    public function rPush(string $key, string $value): int|false
    {
        $this->pushed[] = [$key, $value];

        return $this->pushSucceeds ? count($this->pushed) : false;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, '异常码不匹配');

        return;
    }

    throw new RuntimeException('预期异常未抛出');
};

$sequence = new FakeAdminImSequence();
$store = new FakeAdminImStore($sequence);
$cache = new FakeAdminImSessionCache($sequence);
$publisher = new FakeAdminImRealtimePublisher($sequence);
$service = new AdminImOperationsService(
    $store,
    $cache,
    new FakeAdminImStorageInspector(),
    static fn (): string => '2026-07-10 12:00:00',
    $publisher,
);
$actor = ['id' => 1, 'username' => 'admin', 'ip' => '127.0.0.1'];

$overview = $service->overview();
$assert($overview['status'] === 'healthy', '健康总览状态不正确');
$assert($overview['statistics']['sessions']['active'] === 1, '会话统计不正确');
$assert(!str_contains(json_encode($overview), 'secret-value'), '总览泄露存储凭证');

$service->users(['organization' => '7', 'keyword' => 'allen', 'status' => '1', 'page' => '2', 'limit' => '50']);
$assert($store->receivedFilters['filters'] === ['organization' => 7, 'keyword' => 'allen', 'status' => 1], '用户筛选未严格规范化');
$assert($store->receivedFilters['page'] === 2 && $store->receivedFilters['limit'] === 50, '分页未规范化');

$sessions = $service->sessions([]);
$assert(!array_key_exists('session_id', $sessions['data'][0]), '会话列表泄露完整 session_id');
$assert($sessions['data'][0]['session_ref'] === substr(hash('sha256', 'credential-session-7'), 0, 16), '会话安全引用不正确');

$disabled = $service->setDeviceStatus(9, 2, $actor);
$assert($disabled['organization'] === 7, '设备操作未从服务端目标行解析机构');
$assert($store->revokedSessionIds === ['credential-session-7', 'credential-session-8'], '停用设备未撤销关联会话');
$assert(count($store->revokedWebAccess) === 1, '停用设备未撤销该设备的 Web access 会话');
$assert($cache->invalidated === [[7, 'credential-session-7'], [7, 'credential-session-8']], '未定向失效关联会话缓存');
$assert($publisher->published[0]['type'] === 'auth.device_disabled', '设备停用事件类型错误');
$assert($publisher->published[0]['payload']['organization'] === 7, '设备停用事件缺少服务端机构');
$assert($publisher->published[0]['payload']['credential_session_ids'] === ['credential-session-7', 'credential-session-8'], '设备停用事件会话范围错误');
$assert($sequence->events === ['db_commit', 'cache_invalidate', 'cache_invalidate', 'realtime_publish'], '实时事件未在提交与缓存失效后发布');
$assert($store->audits[0]['organization'] === 7, '操作审计未记录目标机构');
$assert(!str_contains(json_encode($store->audits), 'credential-session-7'), '操作审计泄露 session_id');

$cache->succeeds = false;
$revoked = $service->revokeSession(15, $actor);
$assert($revoked['organization'] === 7, '撤销会话未从服务端目标行解析机构');
$assert($revoked['cache_invalidation']['status'] === 'mysql_authoritative_bounded_fallback', 'Redis 失败未转入有界 MySQL 权威回退');
$assert($revoked['cache_invalidation']['max_stale_seconds'] === 3, '缓存失效上界不正确');
$assert($revoked['revoked_session_count'] === 2, '撤销 IM 会话未连带撤销同 Web access 签发的会话');
$assert(count($store->revokedWebAccess) === 2, '撤销 IM 会话未撤销关联 Web access 会话');
$assert($cache->invalidated === [
    [7, 'credential-session-7'],
    [7, 'credential-session-8'],
    [7, 'credential-session-7'],
    [7, 'credential-session-8'],
], '关联 Web access 的全部 IM 会话缓存未失效');
$assert($publisher->published[1]['type'] === 'auth.session_revoked', '会话撤销事件类型错误');
$assert($publisher->published[2]['type'] === 'auth.session_revoked', '关联会话撤销事件未发布');
$assert(
    $publisher->published[1]['payload']['client_id'] === null
    && $publisher->published[2]['payload']['client_id'] === null,
    '多会话撤销事件不得被单个 client_id 误限定',
);
$assert(!str_contains(json_encode($store->audits), '0123456789abcdef'), '操作审计泄露 Web access jti');

$expectCode(422, static fn () => $service->setDeviceStatus(9, 3, $actor));
$expectCode(422, static fn () => $service->users(['limit' => 101]));
$expectCode(422, static fn () => $service->loginAudits(['login_result' => 'unknown']));
$expectCode(404, static fn () => $service->setDeviceStatus(999, 2, $actor));

$storage = new SaiAdminStorageInspector(static fn (): array => [
    ['key' => 'upload_mode', 'value' => '5'],
    ['key' => 's3_key', 'value' => 'access-value'],
    ['key' => 's3_secret', 'value' => 'secret-value'],
    ['key' => 's3_bucket', 'value' => 'bucket-value'],
    ['key' => 's3_domain', 'value' => 'domain-value'],
    ['key' => 's3_region', 'value' => 'region-value'],
    ['key' => 's3_version', 'value' => 'latest'],
    ['key' => 's3_endpoint', 'value' => 'endpoint-value'],
]);
$storageStatus = $storage->inspect();
$encodedStorage = json_encode($storageStatus, JSON_UNESCAPED_UNICODE);
$assert($storageStatus['configured'] === true, 'S3 完整性判定错误');
$assert(!str_contains($encodedStorage, 'access-value') && !str_contains($encodedStorage, 'secret-value'), 'S3 凭证值出现在返回值');

$redis = new FakeRedisHandler();
$redisCache = new ThinkCacheAdminImSessionCache($redis);
$assert($redisCache->status()['status'] === 'up', 'Redis 健康探测错误');
$assert($redisCache->invalidate(7, 'credential-session-7') === true, 'Redis 定向失效失败');
$assert($redis->deleted === ['im:auth:active:7:credential-session-7'], 'Redis 缓存键与 IM 不一致');
$assert($redisCache->maxStaleSeconds() === 3, '会话重验 TTL 默认值不是 3 秒');
$boundedCache = new ThinkCacheAdminImSessionCache($redis, 999);
$assert($boundedCache->maxStaleSeconds() === 30, '会话重验 TTL 未限制在 30 秒内');

$redisPublisher = new ThinkCacheAdminImRealtimePublisher($redis);
$assert($redisPublisher->publish('auth.session_revoked', [
    'organization' => 7,
    'user_id' => 'user-7',
    'device_id' => 'browser-7',
    'credential_session_ids' => ['credential-session-7'],
]) === true, 'Redis 实时事件发布失败');
$assert($redis->pushed[0][0] === 'im:events:realtime', '实时事件队列键与 IM 不一致');
$redisEvent = json_decode($redis->pushed[0][1], true, flags: JSON_THROW_ON_ERROR);
$assert($redisEvent['type'] === 'auth.session_revoked', '实时事件包装类型错误');
$assert(preg_match('/^[a-f0-9]{64}$/', (string) ($redisEvent['event_id'] ?? '')) === 1, '实时事件缺少稳定 event_id');
$assert($redisEvent['organization'] === 7 && $redisEvent['data']['user_id'] === 'user-7', '实时事件未遵循已有 type/organization/data 协议');
$assert(!array_key_exists('token', $redisEvent['data']) && !array_key_exists('secret', $redisEvent['data']), '实时事件泄露密钥字段');

$organizationRedis = new FakeRedisHandler();
$organizationAccess = new OrganizationImAccessService($organizationRedis);
$organizationAccess->afterCommit(
    7,
    false,
    ['credential-session-7'],
    '2026-07-10 12:00:00',
    'organization_disabled',
);
$assert(
    $organizationRedis->expiring === [['im:auth:organization_inactive:7', 60, '1']],
    '机构停用标记没有使用有界 TTL，仍可能永久覆盖 MySQL 状态',
);
$assert(
    in_array('im:auth:active:7:credential-session-7', $organizationRedis->deleted, true),
    '机构停用时未失效已知凭证会话缓存',
);

$organizationRedis->deleteSucceeds = false;
$logic = (new ReflectionClass(SystemOrganizationLogic::class))->newInstanceWithoutConstructor();
$shouldRevoke = new ReflectionMethod(SystemOrganizationLogic::class, 'shouldRevokeImAccess');
$assert($shouldRevoke->invoke(null, true, 2) === true, '显式重试 status=2 未重做 MySQL 会话撤销');
$assert($shouldRevoke->invoke(null, true, 1) === false, '显式启用不应恢复或撤销 bearer');
$assert($shouldRevoke->invoke(null, false, 2) === false, '非状态写入误触发会话撤销');
$normalizeOrganizationIds = new ReflectionMethod(SystemOrganizationLogic::class, 'normalizedOrganizationIds');
$assert(
    $normalizeOrganizationIds->invoke(null, [9, '2', 9, 0, -1, '17']) === [2, 9, 17],
    '批量机构删除未使用唯一升序锁定顺序',
);
$publishAccess = new ReflectionMethod($logic, 'publishOrganizationImAccess');
$expectCode(OrganizationImAccessService::SYNC_UNAVAILABLE, static fn () => $publishAccess->invoke(
    $logic,
    $organizationAccess,
    7,
    true,
    [],
    '2026-07-10 12:01:00',
    'organization_enabled',
));
$organizationRedis->deleteSucceeds = true;
$publishAccess->invoke(
    $logic,
    $organizationAccess,
    7,
    true,
    [],
    '2026-07-10 12:01:01',
    'organization_enabled',
);
$assert(
    count(array_filter(
        $organizationRedis->deleted,
        static fn (string $key): bool => $key === 'im:auth:organization_inactive:7',
    )) === 2,
    '机构重新启用失败后未能显式重试清理 inactive marker',
);

echo "Admin IM operations service tests passed ({$assertions} assertions)\n";
