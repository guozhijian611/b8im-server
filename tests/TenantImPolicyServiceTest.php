<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\tenantPolicy\TenantImPolicyPublisherInterface;
use plugin\saimulti\service\tenantPolicy\TenantImPolicyService;
use plugin\saimulti\service\tenantPolicy\TenantImPolicyStoreInterface;
use plugin\saimulti\service\tenantPolicy\ThinkCacheTenantImPolicyPublisher;

final class PolicyTestSequence
{
    /** @var list<string> */
    public array $events = [];
}

final class FakeTenantImPolicyStore implements TenantImPolicyStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @var array<int, bool> */
    public array $organizations = [1 => true, 2 => true];

    public int $updateCalls = 0;

    public function __construct(private readonly PolicyTestSequence $sequence)
    {
    }

    public function transaction(callable $callback): mixed
    {
        $result = $callback();
        $this->sequence->events[] = 'db_commit';

        return $result;
    }

    public function organizationExists(int $organization): bool
    {
        return $this->organizations[$organization] ?? false;
    }

    public function find(int $organization, bool $forUpdate = false): ?array
    {
        return $this->rows[$organization] ?? null;
    }

    public function createDefault(int $organization, array $policy): void
    {
        $this->rows[$organization] = ['id' => count($this->rows) + 1, 'organization' => $organization] + $policy;
    }

    public function update(int $organization, int $expectedVersion, array $policy): bool
    {
        $this->updateCalls++;
        if (!isset($this->rows[$organization]) || (int) $this->rows[$organization]['version'] !== $expectedVersion) {
            return false;
        }
        $this->rows[$organization] = array_merge($this->rows[$organization], $policy);

        return true;
    }
}

final class FakeTenantImPolicyPublisher implements TenantImPolicyPublisherInterface
{
    /** @var list<array{organization: int, version: int, actor: array<string, mixed>}> */
    public array $published = [];

    public bool $fails = false;

    public int $attempts = 0;

    public function __construct(private readonly PolicyTestSequence $sequence)
    {
    }

    public function invalidateAndPublish(int $organization, int $version, array $actor): void
    {
        $this->attempts++;
        $this->sequence->events[] = 'cache_delete_and_event_publish';
        if ($this->fails) {
            throw new RuntimeException('redis unavailable');
        }
        $this->published[] = compact('organization', 'version', 'actor');
    }
}

final class FakePolicyRedisHandler
{
    /** @var list<string> */
    public array $deleted = [];

    /** @var list<array{key: string, value: string}> */
    public array $pushed = [];

    public function del(string $key): int
    {
        $this->deleted[] = $key;

        return 1;
    }

    public function rPush(string $key, string $value): int
    {
        $this->pushed[] = compact('key', 'value');

        return count($this->pushed);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, sprintf('expected code %d, got %d', $code, $exception->getCode()));
        return;
    }
    throw new RuntimeException(sprintf('expected ApiException %d', $code));
};

$sequence = new PolicyTestSequence();
$store = new FakeTenantImPolicyStore($sequence);
$publisher = new FakeTenantImPolicyPublisher($sequence);
$service = new TenantImPolicyService($store, $publisher);

$service->ensureDefault(1);
$policy = $service->read(1);
$assert($policy['organization'] === 1, '默认策略未绑定 organization');
$assert($policy['allowed_client_families'] === ['web', 'app', 'desktop'], '默认客户端形态错误');
$assert($policy['status'] === 'ENABLED' && $policy['version'] === 1, '默认状态或版本错误');

$updated = $service->update(1, [
    'version' => 1,
    'max_online_devices' => 3,
    'max_message_qps' => 9,
], ['type' => 'tenant', 'id' => 11]);
$assert($updated['version'] === 2 && $updated['max_online_devices'] === 3, '策略未使用乐观锁更新');
$assert($sequence->events === ['db_commit', 'db_commit', 'cache_delete_and_event_publish'], '缓存失效/事件未在事务提交后执行');
$assert($publisher->published[0]['organization'] === 1 && $publisher->published[0]['version'] === 2, '变更事件版本错误');

$expectCode(409, static fn () => $service->update(1, ['version' => 1, 'max_message_qps' => 10], []));
$expectCode(422, static fn () => $service->update(1, ['version' => 2, 'organization' => 99], []));
$expectCode(422, static fn () => $service->update(1, [
    'version' => 2,
    'allow_multi_device_online' => false,
    'cross_device_login_policy' => 'allow',
], []));
$expectCode(422, static fn () => $service->update(1, [
    'version' => 2,
    'allowed_client_families' => ['web', 'platform'],
], []));
$expectCode(404, static fn () => $service->ensureDefault(99));

$retrySequence = new PolicyTestSequence();
$retryStore = new FakeTenantImPolicyStore($retrySequence);
$retryPublisher = new FakeTenantImPolicyPublisher($retrySequence);
$retryService = new TenantImPolicyService($retryStore, $retryPublisher);
$retryService->ensureDefault(2);
$retryPublisher->fails = true;
$expectCode(TenantImPolicyService::SYNC_UNAVAILABLE, static fn () => $retryService->update(2, [
    'version' => 1,
    'max_message_qps' => 7,
], ['type' => 'tenant', 'id' => 22]));
$assert(
    (int) $retryStore->rows[2]['version'] === 2
    && (int) $retryStore->rows[2]['max_message_qps'] === 7,
    '发布失败时数据库已提交的策略状态不正确',
);
$assert($retryStore->updateCalls === 1, '首次策略更新没有且仅有一次写入');

$retryPublisher->fails = false;
$retried = $retryService->update(2, [
    'version' => 1,
    'max_message_qps' => 7,
], ['type' => 'tenant', 'id' => 22]);
$assert($retried['version'] === 2, '相同请求重试没有复用已提交的策略版本');
$assert($retryStore->updateCalls === 1, '发布重试不应重复写入或递增策略版本');
$assert(
    $retryPublisher->attempts === 2
    && $retryPublisher->published === [[
        'organization' => 2,
        'version' => 2,
        'actor' => ['type' => 'tenant', 'id' => 22],
    ]],
    '相同请求重试没有重新发布已提交版本',
);
$expectCode(409, static fn () => $retryService->update(2, [
    'version' => 1,
    'max_message_qps' => 8,
], []));

$redis = new FakePolicyRedisHandler();
(new ThinkCacheTenantImPolicyPublisher($redis))->invalidateAndPublish(
    7,
    12,
    ['type' => 'admin', 'id' => 3],
);
$envelope = json_decode($redis->pushed[0]['value'], true, flags: JSON_THROW_ON_ERROR);
$assert($redis->deleted === ['tenant_im_policy:7'], '变更后未删除与 IM 共用的策略缓存 key');
$assert(
    $redis->pushed[0]['key'] === 'im:events:realtime'
    && $envelope['type'] === 'tenant.policy.changed'
    && $envelope['organization'] === 7
    && $envelope['data']['version'] === 12,
    '策略变更事件 envelope 与 IM consumer 契约不一致',
);

fwrite(STDOUT, sprintf("Tenant IM policy service: %d assertions passed.\n", $assertions));
