<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use B8im\Module\Search\Consumer\AccessDecision;
use B8im\Module\Search\Rebuild\AccessDecider;
use B8im\Module\Search\Rebuild\BarrierStatus;
use B8im\Module\Search\Rebuild\BatchResult;
use B8im\Module\Search\Rebuild\Claim;
use B8im\Module\Search\Rebuild\CleanupResult;
use B8im\Module\Search\Rebuild\Config;
use B8im\Module\Search\Rebuild\Projection;
use B8im\Module\Search\Rebuild\Readiness;
use B8im\Module\Search\Rebuild\Store;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\exception\SearchProjectionIntegrityException;
use plugin\saimulti\service\module\SearchDocumentProjectionServiceInterface;
use plugin\saimulti\service\module\SearchMessageFactReader;
use plugin\saimulti\service\module\SearchService;
use plugin\saimulti\service\searchRebuild\SearchRebuildService;
use plugin\saimulti\service\searchRebuild\ServerSearchRebuildProjection;

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
};
$expectApiCode = static function (Closure $callback, int $code, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, $message);
    }
};

final class SearchRebuildFakeReadiness implements Readiness
{
    public int $calls = 0;

    public function __construct(public bool $ready)
    {
    }

    public function isReady(): bool
    {
        $this->calls++;
        return $this->ready;
    }
}

final class SearchRebuildFakeAccess implements AccessDecider
{
    /** @var list<int> */
    public array $organizations = [];

    public function __construct(public AccessDecision $decision)
    {
    }

    public function decide(int $organization): AccessDecision
    {
        $this->organizations[] = $organization;
        return $this->decision;
    }
}

final class SearchRebuildFakeStore implements Store
{
    /** @var list<array{int,int}> */
    public array $enqueues = [];

    public function enqueue(int $organization, int $actorId): array
    {
        $this->enqueues[] = [$organization, $actorId];

        return [
            'job' => [
                'id' => '9007199254740993',
                'organization' => $organization,
                'status' => 'pending',
                'source_event_cut' => '7',
                'barrier_event_cut' => null,
                'finalized_checkpoint_event_seq' => null,
            ],
            'index' => [
                'id' => '9007199254740994',
                'organization' => $organization,
                'status' => 'building',
                'projection_checkpoint' => '7',
                'rebuild_required' => 1,
                'lifecycle_fenced' => 0,
            ],
        ];
    }

    public function claim(string $workerId, int $leaseSeconds): ?Claim
    {
        throw new LogicException('not used');
    }

    public function renew(Claim $claim, int $leaseSeconds): Claim
    {
        throw new LogicException('not used');
    }

    public function processBatch(
        Claim $claim,
        int $batchSize,
        int $leaseSeconds,
        Projection $projection,
    ): BatchResult {
        throw new LogicException('not used');
    }

    public function cleanupBatch(Claim $claim, int $batchSize, int $leaseSeconds): CleanupResult
    {
        throw new LogicException('not used');
    }

    public function captureBarrier(Claim $claim, int $timeoutSeconds): Claim
    {
        throw new LogicException('not used');
    }

    public function barrierStatus(Claim $claim): BarrierStatus
    {
        throw new LogicException('not used');
    }

    public function finalize(Claim $claim): void
    {
        throw new LogicException('not used');
    }

    public function fail(Claim $claim, string $message): void
    {
        throw new LogicException('not used');
    }

    public function retry(Claim $claim, string $message, int $delaySeconds): void
    {
        throw new LogicException('not used');
    }
}

final class SearchRebuildFakeProjectionService implements SearchDocumentProjectionServiceInterface
{
    /** @var list<array{int,string}> */
    public array $writes = [];

    public function upsertMessageDocument(int $homeOrganization, string $messageId): array
    {
        throw new LogicException('rebuild must use outer-transaction projection');
    }

    public function projectMessageDocumentLocked(int $homeOrganization, string $messageId): array
    {
        $this->writes[] = [$homeOrganization, $messageId];
        return [];
    }
}

$configValues = [
    'enabled' => true,
    'poll_interval_seconds' => 0.5,
    'batch_size' => 100,
    'cleanup_batch_size' => 100,
    'lease_seconds' => 30,
    'barrier_timeout_seconds' => 300,
    'retry_base_delay_seconds' => 5,
    'retry_max_delay_seconds' => 60,
    'max_retry_attempts' => 5,
    'deployment_id' => 'rebuild-test-deployment',
    'worker_id' => 'rebuild-test',
    'heartbeat_ttl_seconds' => 60,
];
$config = Config::fromArray($configValues);
$assert($config->barrierTimeoutSeconds === 300, 'Server config 未透传 barrier timeout');
$assert($config->retryDelaySeconds(0) === 5 && $config->retryDelaySeconds(9) === 60, '模块 retry schedule 未生效');
$decimalMethod = (new ReflectionClass(SearchService::class))->getMethod('unsignedDecimal');
$searchService = new SearchService();
$assert(
    $decimalMethod->invoke($searchService, '0', 'boundary') === '0'
    && $decimalMethod->invoke($searchService, '18446744073709551615', 'boundary')
        === '18446744073709551615',
    'SearchService 未通过 Shared CanonicalDecimal 保持 UINT64 边界',
);
foreach (['00', '01', '-1', '18446744073709551616'] as $invalidDecimal) {
    try {
        $decimalMethod->invoke($searchService, $invalidDecimal, 'boundary');
        $assert(false, 'SearchService 接受了非 canonical/越界 UINT64: ' . $invalidDecimal);
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        $cause = $exception instanceof ReflectionException ? $exception->getPrevious() : $exception;
        $assert(
            $cause instanceof SearchProjectionIntegrityException,
            'SearchService UINT64 边界返回了错误异常类型',
        );
    }
}
$formatDocMethod = (new ReflectionClass(SearchService::class))->getMethod('formatDoc');
$maxDocumentRow = [
    'id' => '18446744073709551615',
    'organization' => 23,
    'message_id' => 'uint64-max',
    'conversation_id' => 'uint64-boundary',
    'conversation_type' => 2,
    'sender_organization' => 23,
    'sender_user_id' => 'user-23',
    'message_type' => 1,
    'message_seq' => '18446744073709551615',
    'content' => 'boundary',
    'visibility' => 1,
    'sent_at' => null,
    'create_time' => null,
    'update_time' => null,
];
$formattedMaxDocument = $formatDocMethod->invoke($searchService, $maxDocumentRow);
$assert(
    ($formattedMaxDocument['id'] ?? null) === '18446744073709551615'
    && ($formattedMaxDocument['message_seq'] ?? null) === '18446744073709551615'
    && ($formattedMaxDocument['conversation_type'] ?? null) === 2,
    'Search document DTO 未输出 canonical UINT64 与 strict conversation_type',
);
foreach ([null, 0, 3, ''] as $invalidConversationType) {
    $row = $maxDocumentRow;
    if ($invalidConversationType === null) {
        unset($row['conversation_type']);
    } else {
        $row['conversation_type'] = $invalidConversationType;
    }
    try {
        $formatDocMethod->invoke($searchService, $row);
        $assert(false, 'Search document accepted an invalid conversation_type');
    } catch (SearchProjectionIntegrityException) {
        $assert(true, 'Search document conversation_type failed closed');
    }
}
$bindingMethod = (new ReflectionClass(SearchMessageFactReader::class))->getMethod('assertBinding');
$candidate = [
    'organization' => 23,
    'message_id' => 'fact-message',
    'conversation_id' => 'fact-conversation',
    'conversation_type' => 2,
    'sender_organization' => 23,
    'sender_user_id' => 'fact-sender',
    'message_type' => 1,
    'message_seq' => '9',
    'source_change_seq' => '0',
    'content' => 'fact',
    'sent_at' => '2026-07-21 12:00:00',
];
$authoritativeIndex = [
    'organization' => 23,
    'message_id' => 'fact-message',
    'conversation_id' => 'fact-conversation',
    'message_seq' => '9',
    'sender_organization' => 23,
    'sender_id' => 'fact-sender',
    'client_msg_id' => 'fact-client',
    'index_create_time' => '2026-07-21 12:00:00',
    'index_conversation_type' => 2,
    'authoritative_source_change_seq' => '0',
];
$authoritativeBody = [
    'organization' => 23,
    'message_id' => 'fact-message',
    'conversation_id' => 'fact-conversation',
    'conversation_type' => 2,
    'message_seq' => '9',
    'sender_organization' => 23,
    'sender_id' => 'fact-sender',
    'client_msg_id' => 'fact-client',
    'message_type' => 1,
    'content' => 'fact',
    'status' => 1,
    'create_time' => '2026-07-21 12:00:00',
    'delete_time' => null,
];
$assert(
    $bindingMethod->invoke(
        new SearchMessageFactReader(),
        $candidate,
        $authoritativeIndex,
        $authoritativeBody,
    ) === 2,
    'Fact reader did not return the authoritative conversation_type.',
);
try {
    $bindingMethod->invoke(
        new SearchMessageFactReader(),
        array_replace($candidate, ['conversation_type' => 1]),
        $authoritativeIndex,
        $authoritativeBody,
    );
    $assert(false, 'Fact reader accepted a forged candidate conversation_type.');
} catch (SearchProjectionIntegrityException) {
    $assert(true, 'Fact reader failed closed on a forged candidate conversation_type.');
}
foreach ([
    ['id' => '01'],
    ['id' => '18446744073709551616'],
    ['message_seq' => '-1'],
    ['message_seq' => '18446744073709551616'],
] as $invalidDocumentField) {
    try {
        $formatDocMethod->invoke($searchService, array_replace($maxDocumentRow, $invalidDocumentField));
        $assert(false, 'Search document 接受了非法 UINT64 字段');
    } catch (SearchProjectionIntegrityException) {
        $assert(true, 'Search document 非 canonical/越界 UINT64 fail closed');
    }
}
$invalidConfig = $configValues;
unset($invalidConfig['barrier_timeout_seconds']);
try {
    Config::fromArray($invalidConfig);
    $assert(false, '缺失 barrier timeout 未被拒绝');
} catch (InvalidArgumentException) {
    $passed++;
}

$consumer = new SearchRebuildFakeReadiness(true);
$worker = new SearchRebuildFakeReadiness(true);
$access = new SearchRebuildFakeAccess(AccessDecision::AVAILABLE);
$store = new SearchRebuildFakeStore();
$service = new SearchRebuildService($consumer, $worker, $access, $store);
$result = $service->enqueue(23, 99);
$assert($store->enqueues === [[23, 99]], '搜索重建服务未转交 organization + actor');
$assert($access->organizations === [23], '搜索重建服务未按 organization 授权');
$assert($consumer->calls === 1 && $worker->calls === 1, '搜索重建未检查双 worker readiness');
$assert($result['job']['id'] === '9007199254740993', 'job id 未保持 uint64 字符串');
$assert($result['index']['id'] === '9007199254740994', 'index id 未保持 uint64 字符串');
$assert($result['job']['source_event_cut'] === '7', 'source event cut 未保持字符串');
$assert($result['index']['projection_checkpoint'] === '7', 'checkpoint 未保持字符串');

foreach ([AccessDecision::DENIED->value => 403, AccessDecision::UNAVAILABLE->value => 503] as $decisionValue => $code) {
    $decision = AccessDecision::from($decisionValue);
    $deniedService = new SearchRebuildService(
        new SearchRebuildFakeReadiness(true),
        new SearchRebuildFakeReadiness(true),
        new SearchRebuildFakeAccess($decision),
        new SearchRebuildFakeStore(),
    );
    $expectApiCode(
        static fn () => $deniedService->enqueue(23, 99),
        $code,
        '搜索重建授权失败未 fail closed: ' . $decision->value,
    );
}
$expectApiCode(
    static fn () => (new SearchRebuildService(
        new SearchRebuildFakeReadiness(false),
        new SearchRebuildFakeReadiness(true),
        new SearchRebuildFakeAccess(AccessDecision::AVAILABLE),
        new SearchRebuildFakeStore(),
    ))->enqueue(23, 99),
    503,
    'consumer readiness 缺失未阻断 enqueue',
);
$expectApiCode(
    static fn () => (new SearchRebuildService(
        new SearchRebuildFakeReadiness(true),
        new SearchRebuildFakeReadiness(false),
        new SearchRebuildFakeAccess(AccessDecision::AVAILABLE),
        new SearchRebuildFakeStore(),
    ))->enqueue(23, 99),
    503,
    'rebuild worker readiness 缺失未阻断 enqueue',
);

$projectionService = new SearchRebuildFakeProjectionService();
$projection = new ServerSearchRebuildProjection($projectionService);
$projection->projectMessageDocumentLocked(23, 'message-1');
$assert($projectionService->writes === [[23, 'message-1']], 'Server projection adapter 未调用权威 locked projector');

$factorySource = (string) file_get_contents(
    dirname(__DIR__) . '/plugin/saimulti/service/searchRebuild/SearchRebuildFactory.php',
);
$storeSource = (string) file_get_contents(
    dirname(__DIR__) . '/plugin/saimulti/service/searchRebuild/ThinkOrmSearchRebuildStore.php',
);
$assert(str_contains($factorySource, 'B8im\\Module\\Search\\Rebuild\\Runtime'), '生产 factory 未使用模块 Runtime');
$assert(!str_contains($factorySource, 'FailClosed'), '生产 factory 仍装配复制的 fail-closed barrier');
$assert(str_contains($storeSource, 'finalized_checkpoint_event_seq=?'), 'finalize 未复制 checkpoint snapshot');
$assert(str_contains($storeSource, "rebuild_required=0"), 'rebuild finalize 未清 required fence');
$assert(!str_contains($storeSource, 'rebuild_required=0,lifecycle_fenced=0'), 'rebuild finalize 越权清 lifecycle fence');

echo 'SearchRebuildTest: ' . $passed . " assertions passed\n";
