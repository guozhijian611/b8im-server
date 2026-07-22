<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use B8im\Module\Search\Consumer\AccessDecider;
use B8im\Module\Search\Consumer\AccessDecision;
use B8im\Module\Search\Consumer\MessageEventHandler;
use B8im\Module\Search\Consumer\ProjectionWriter;
use B8im\ImShared\Protocol\Dto\SearchProjectionEvent;
use B8im\ModuleSdk\State\SystemModuleStatus;
use B8im\ModuleSdk\State\TenantModuleStatus;
use plugin\saimulti\process\SearchConsumerProcess;
use plugin\saimulti\service\module\ModuleAccessCacheInterface;
use plugin\saimulti\service\module\ModuleAccessService;
use plugin\saimulti\service\module\ModuleAccessStoreInterface;
use plugin\saimulti\service\module\SearchDocumentProjectionServiceInterface;
use plugin\saimulti\service\searchConsumer\ClockInterface;
use plugin\saimulti\service\searchConsumer\PhpAmqpLibSearchConsumerTransport;
use plugin\saimulti\service\searchConsumer\RedisSearchConsumerHeartbeatStore;
use plugin\saimulti\service\searchConsumer\SearchAmqpChannelInterface;
use plugin\saimulti\service\searchConsumer\SearchAmqpConnectionFactoryInterface;
use plugin\saimulti\service\searchConsumer\SearchAmqpConnectionInterface;
use plugin\saimulti\service\searchConsumer\SearchConsumerConfig;
use plugin\saimulti\service\searchConsumer\SearchConsumerDelivery;
use plugin\saimulti\service\searchConsumer\SearchConsumerGateInterface;
use plugin\saimulti\service\searchConsumer\SearchConsumerHeartbeatKey;
use plugin\saimulti\service\searchConsumer\SearchConsumerHeartbeatStoreInterface;
use plugin\saimulti\service\searchConsumer\SearchConsumerReadinessReader;
use plugin\saimulti\service\searchConsumer\SearchConsumerRuntime;
use plugin\saimulti\service\searchConsumer\SearchConsumerTopology;
use plugin\saimulti\service\searchConsumer\SearchConsumerTransportInterface;
use plugin\saimulti\service\searchConsumer\ServerSearchAccessDecider;
use plugin\saimulti\service\searchConsumer\ServerSearchProjectionWriter;

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
};
$assertEvents = static function (array $events, array $expected, string $message) use ($assert): void {
    $offset = 0;
    foreach ($expected as $event) {
        $position = array_search($event, array_slice($events, $offset), true);
        if ($position === false) {
            $assert(false, $message);
            return;
        }
        $offset += $position + 1;
    }
    $assert(true, $message);
};

final class SearchConsumerFakeClock implements ClockInterface
{
    public int $monotonicTimeMs;

    public function __construct(public int $timestamp = 1_000)
    {
        $this->monotonicTimeMs = $timestamp * 1000;
    }

    public function now(): int
    {
        return $this->timestamp;
    }

    public function monotonicMilliseconds(): int
    {
        return $this->monotonicTimeMs;
    }
}

final class SearchConsumerFakeHeartbeat implements SearchConsumerHeartbeatStoreInterface
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var list<string> */
    public array $events = [];

    public bool $failWrite = false;

    public function claimOrRenew(
        string $key,
        ?string $expectedValue,
        string $newValue,
        int $ttlSeconds,
    ): bool
    {
        $this->events[] = 'heartbeat:' . $ttlSeconds;
        if ($this->failWrite) {
            throw new RuntimeException('heartbeat unavailable');
        }
        $current = $this->values[$key] ?? null;
        if (($expectedValue === null && $current !== null)
            || ($expectedValue !== null && $current !== $expectedValue)) {
            return false;
        }
        $this->values[$key] = $newValue;

        return true;
    }

    public function read(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function deleteIfEquals(string $key, string $expectedValue): bool
    {
        $this->events[] = 'heartbeat-cas-delete';
        if (($this->values[$key] ?? null) !== $expectedValue) {
            return false;
        }
        unset($this->values[$key]);

        return true;
    }
}

final class SearchConsumerFakeGate implements SearchConsumerGateInterface
{
    public int $checks = 0;

    public function __construct(public bool $allowed = true)
    {
    }

    public function canFetch(): bool
    {
        ++$this->checks;

        return $this->allowed;
    }
}

final class SearchConsumerFakeTransport implements SearchConsumerTransportInterface
{
    /** @var list<SearchConsumerDelivery> */
    public array $deliveries = [];

    /** @var list<string> */
    public array $events = [];

    /** @var list<array{body:string,routing_key:string,headers:array<string,mixed>,message_id:string,tier:int,delay_ms:int}> */
    public array $published = [];

    public ?SearchConsumerTopology $topology = null;

    public int $openCount = 0;

    public int $nextCount = 0;

    public bool $failOpen = false;

    public bool $failPublish = false;

    public ?Closure $onNext = null;

    public ?Closure $onPublish = null;

    public function open(SearchConsumerTopology $topology, int $prefetch): void
    {
        $this->openCount++;
        $this->events[] = 'open:' . $prefetch;
        if ($this->failOpen) {
            throw new RuntimeException('rabbit unavailable');
        }
        $this->topology = $topology;
    }

    public function next(): ?SearchConsumerDelivery
    {
        $this->nextCount++;
        $this->events[] = 'next';
        if ($this->onNext !== null) {
            ($this->onNext)();
        }

        return array_shift($this->deliveries);
    }

    public function ack(SearchConsumerDelivery $delivery): void
    {
        $this->events[] = 'ack:' . $delivery->token;
    }

    public function reject(SearchConsumerDelivery $delivery): void
    {
        $this->events[] = 'reject:' . $delivery->token;
    }

    public function nackRequeue(SearchConsumerDelivery $delivery): void
    {
        $this->events[] = 'nack-requeue:' . $delivery->token;
    }

    public function publishRetry(
        string $body,
        string $routingKey,
        array $headers,
        string $messageId,
        int $retryTier,
    ): void
    {
        $this->events[] = 'publish-confirm:' . $deliveryToken = ($headers[SearchConsumerRuntime::RETRY_COUNT_HEADER] ?? 'missing');
        $this->published[] = [
            'body' => $body,
            'routing_key' => $routingKey,
            'headers' => $headers,
            'message_id' => $messageId,
            'tier' => $retryTier,
            'delay_ms' => $this->topology?->retryTier($retryTier)['delay_ms'] ?? -1,
        ];
        if ($this->onPublish !== null) {
            ($this->onPublish)();
        }
        if ($this->failPublish) {
            throw new RuntimeException('publisher confirm failed');
        }
    }

    public function close(): void
    {
        $this->events[] = 'close';
        $this->topology = null;
    }
}

final class SearchConsumerFakeAccess implements AccessDecider
{
    /** @var list<int> */
    public array $organizations = [];

    public function __construct(public AccessDecision $decision = AccessDecision::AVAILABLE)
    {
    }

    public function decide(int $organization): AccessDecision
    {
        $this->organizations[] = $organization;

        return $this->decision;
    }
}

final class SearchConsumerFakeWriter implements ProjectionWriter
{
    /** @var list<array{int,string}> */
    public array $writes = [];

    public bool $fail = false;

    /** @var list<array{int,string}> */
    public array $denials = [];

    public ?Closure $onWrite = null;

    public function write(SearchProjectionEvent $event): void
    {
        $this->writes[] = [$event->organization, $event->messageId];
        if ($this->onWrite !== null) {
            ($this->onWrite)();
        }
        if ($this->fail) {
            throw new RuntimeException('database transient failure');
        }
    }

    public function deny(SearchProjectionEvent $event): void
    {
        $this->denials[] = [$event->organization, $event->messageId];
        if ($this->fail) {
            throw new RuntimeException('database transient failure');
        }
    }
}

final class SearchConsumerAccessStore implements ModuleAccessStoreInterface
{
    /** @var array<string, mixed> */
    public array $snapshot;

    /** @var list<array{int,string}> */
    public array $reads = [];

    public bool $fail = false;

    public function __construct()
    {
        $this->snapshot = [
            'module_key' => 'search',
            'module_status' => SystemModuleStatus::ENABLED->value,
            'module_version' => '0.4.0',
            'module_lock_version' => 1,
            'platforms' => ['server'],
            'capabilities' => ['server' => ['search.index.write']],
            'organization' => 9,
            'license_status' => TenantModuleStatus::ENABLED->value,
            'expire_at' => null,
            'license_version' => 1,
        ];
    }

    public function tenantSnapshot(int $organization, string $moduleKey): ?array
    {
        $this->reads[] = [$organization, $moduleKey];
        if ($this->fail) {
            throw new RuntimeException('mysql unavailable');
        }

        return $this->snapshot;
    }

    public function systemSnapshot(string $moduleKey): ?array
    {
        return $this->snapshot;
    }

    public function enabledTenantSnapshots(int $organization): array
    {
        return [$this->snapshot];
    }

    public function enabledSystemSnapshots(): array
    {
        return [$this->snapshot];
    }

    public function organizationsForModule(string $moduleKey): array
    {
        return [9];
    }
}

final class SearchConsumerAccessCache implements ModuleAccessCacheInterface
{
    /** @var array<string, array<string,mixed>> */
    public array $values = [];

    public function get(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, array $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}

final class SearchConsumerProjectionService implements SearchDocumentProjectionServiceInterface
{
    /** @var list<array{int,string}> */
    public array $writes = [];

    public function upsertMessageDocument(int $homeOrganization, string $messageId): array
    {
        return $this->projectMessageDocumentLocked($homeOrganization, $messageId);
    }

    public function projectMessageDocumentLocked(int $homeOrganization, string $messageId): array
    {
        $this->writes[] = [$homeOrganization, $messageId];

        return [];
    }
}

final class SearchConsumerRawRedis
{
    /** @var array<string, string> */
    public array $values = [];

    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    public function eval(string $script, array $arguments, int $keyCount): int
    {
        if ($keyCount !== 1 || !in_array(count($arguments), [2, 4], true)) {
            throw new RuntimeException('unexpected Redis CAS arguments');
        }
        if (count($arguments) === 4) {
            [$key, $expected, $newValue] = $arguments;
            $current = $this->values[$key] ?? null;
            if (($expected === '' && $current !== null)
                || ($expected !== '' && $current !== $expected)) {
                return 0;
            }
            $this->values[$key] = $newValue;
            return 1;
        }
        [$key, $expected] = $arguments;
        if (($this->values[$key] ?? null) !== $expected) {
            return 0;
        }
        unset($this->values[$key]);
        return 1;
    }
}

final class SearchConsumerFakeAmqpChannel implements SearchAmqpChannelInterface
{
    /** @var list<array<string, mixed>|string> */
    public array $events = [];

    /** @var list<SearchConsumerDelivery> */
    public array $deliveries = [];

    public string $confirmOutcome = 'ack';

    private ?Closure $publisherAck = null;

    private ?Closure $publisherNack = null;

    private ?Closure $returned = null;

    public bool $open = true;

    public function declareExchange(string $name, string $type, bool $durable): void
    {
        $this->events[] = ['declare-exchange' => $name, 'type' => $type, 'durable' => $durable];
    }

    public function declareQueue(string $name, bool $durable, array $arguments): void
    {
        $this->events[] = ['declare-queue' => $name, 'durable' => $durable, 'arguments' => $arguments];
    }

    public function bindQueue(string $queue, string $exchange, string $routingKey): void
    {
        $this->events[] = ['bind' => $queue, 'exchange' => $exchange, 'routing_key' => $routingKey];
    }

    public function qos(int $prefetch): void
    {
        $this->events[] = 'qos:' . $prefetch;
    }

    public function enablePublisherConfirms(): void
    {
        $this->events[] = 'confirm-select';
    }

    public function onPublisherAck(Closure $handler): void
    {
        $this->publisherAck = $handler;
    }

    public function onPublisherNack(Closure $handler): void
    {
        $this->publisherNack = $handler;
    }

    public function onReturned(Closure $handler): void
    {
        $this->returned = $handler;
    }

    public function get(string $queue): ?SearchConsumerDelivery
    {
        $this->events[] = 'get:' . $queue;
        return array_shift($this->deliveries);
    }

    public function publish(
        string $body,
        array $headers,
        string $messageId,
        string $exchange,
        string $routingKey,
        bool $mandatory,
        bool $persistent,
    ): void {
        $this->events[] = [
            'publish' => $body,
            'headers' => $headers,
            'message_id' => $messageId,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
            'mandatory' => $mandatory,
            'persistent' => $persistent,
        ];
    }

    public function waitForPublisherConfirms(float $timeoutSeconds): void
    {
        $this->events[] = 'wait-confirm:' . $timeoutSeconds;
        if ($this->confirmOutcome === 'timeout') {
            throw new RuntimeException('publisher confirm timeout');
        }
        if ($this->confirmOutcome === 'nack') {
            $this->events[] = 'publisher-nack';
            ($this->publisherNack)();
            return;
        }
        if ($this->confirmOutcome === 'return') {
            $this->events[] = 'publisher-return';
            ($this->returned)();
        }
        $this->events[] = 'publisher-ack';
        ($this->publisherAck)();
    }

    public function ack(mixed $deliveryToken): void
    {
        $this->events[] = 'ack:' . $deliveryToken;
    }

    public function reject(mixed $deliveryToken, bool $requeue): void
    {
        $this->events[] = 'reject:' . $deliveryToken . ':' . (int) $requeue;
    }

    public function nack(mixed $deliveryToken, bool $requeue): void
    {
        $this->events[] = 'nack:' . $deliveryToken . ':' . (int) $requeue;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function close(): void
    {
        $this->events[] = 'channel-close';
        $this->open = false;
    }
}

final class SearchConsumerFakeAmqpConnection implements SearchAmqpConnectionInterface
{
    public bool $connected = true;

    public function __construct(public readonly SearchConsumerFakeAmqpChannel $fakeChannel)
    {
    }

    public function channel(): SearchAmqpChannelInterface
    {
        return $this->fakeChannel;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }
}

final class SearchConsumerFakeAmqpConnectionFactory implements SearchAmqpConnectionFactoryInterface
{
    public function __construct(public readonly SearchConsumerFakeAmqpChannel $fakeChannel)
    {
    }

    public function connect(SearchConsumerConfig $config): SearchAmqpConnectionInterface
    {
        return new SearchConsumerFakeAmqpConnection($this->fakeChannel);
    }
}

$configValues = static fn (): array => [
    'enabled' => true,
    'host' => '127.0.0.1',
    'port' => 5672,
    'user' => 'guest',
    'password' => 'guest',
    'vhost' => '/',
    'connection_timeout_seconds' => 2.0,
    'read_write_timeout_seconds' => 2.0,
    'confirm_timeout_seconds' => 2.0,
    'prefetch' => 1,
    'poll_interval_seconds' => 0.25,
    'max_messages_per_tick' => 3,
    'max_tick_duration_ms' => 200,
    'deployment_id' => 'test-deployment',
    'instance_id' => 'test-instance',
    'heartbeat_key' => 'test:search:heartbeat',
    'heartbeat_ttl_seconds' => 15,
];
$eventBody = static fn (string $messageId = 'message-1'): string => json_encode([
    'event_contract' => SearchProjectionEvent::CONTRACT,
    'event_id' => str_repeat('a', 64),
    'organization' => 9,
    'event_type' => 'message.created',
    'source_event_seq' => '1',
    'message_id' => $messageId,
], JSON_THROW_ON_ERROR);
$delivery = static function (
    int $token,
    string $body,
    array $headers = [],
    string $routingKey = 'message.created',
    ?string $brokerMessageId = null,
): SearchConsumerDelivery {
    $payload = json_decode($body, true);
    if (is_array($payload) && !array_is_list($payload)) {
        $identityHeaders = array_intersect_key($payload, array_flip(SearchProjectionEvent::FIELDS));
        $headers = array_replace($identityHeaders, $headers);
        $brokerMessageId ??= is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null;
    }

    return new SearchConsumerDelivery(
        $token,
        $body,
        $routingKey,
        $headers,
        $brokerMessageId,
    );
};
$build = static function (
    ?SearchConsumerFakeTransport $transport = null,
    ?SearchConsumerFakeHeartbeat $heartbeat = null,
    ?SearchConsumerFakeClock $clock = null,
    ?SearchConsumerFakeAccess $access = null,
    ?SearchConsumerFakeWriter $writer = null,
    ?array $values = null,
    ?SearchConsumerFakeGate $gate = null,
) use ($configValues): array {
    $config = SearchConsumerConfig::fromArray($values ?? $configValues());
    $transport ??= new SearchConsumerFakeTransport();
    $heartbeat ??= new SearchConsumerFakeHeartbeat();
    $clock ??= new SearchConsumerFakeClock();
    $access ??= new SearchConsumerFakeAccess();
    $writer ??= new SearchConsumerFakeWriter();
    $runtime = new SearchConsumerRuntime(
        $config,
        $transport,
        $heartbeat,
        $clock,
        new MessageEventHandler($access, $writer),
        $gate,
    );

    return [$runtime, $config, $transport, $heartbeat, $clock, $access, $writer];
};

$config = SearchConsumerConfig::fromArray($configValues());
$secondConfig = SearchConsumerConfig::fromArray($configValues());
$assert($config->instanceId !== $secondConfig->instanceId, '滚动 worker 使用了可碰撞的静态 instance identity');
$assert($config->topology->sourceExchange === 'im.message', '搜索消费者 source 不是 im.message');
$assert($config->topology->mainQueue === 'search.message.index', '搜索消费者 main queue 配置错误');
$assert(count($config->topology->exchanges()) === 9, '搜索消费者没有 source/work/dead + 六个 retry tier exchange');
$assert(count(array_filter($config->topology->exchanges(), static fn (array $exchange): bool => $exchange['durable'])) === 9, '搜索 exchange 非 durable');
$assert(count(array_filter($config->topology->queues(), static fn (array $queue): bool => $queue['durable'])) === 8, '搜索 queue 非 durable');
$assert($config->topology->queues()[0]['arguments'] === ['x-dead-letter-exchange' => SearchConsumerTopology::DEAD_EXCHANGE], 'main queue DLX 错误');
$assert($config->topology->queues()[1]['arguments'] === ['x-message-ttl' => 1000, 'x-dead-letter-exchange' => SearchConsumerTopology::WORK_EXCHANGE], 'retry tier queue 没有固定 TTL 或 DLX 回专用 work exchange');
$assert(!array_key_exists('x-dead-letter-routing-key', $config->topology->queues()[1]['arguments']), 'retry queue 错误覆盖原 routing key');
$sourceBindings = array_values(array_filter($config->topology->bindings(), static fn (array $binding): bool => $binding['exchange'] === 'im.message'));
$assert(array_column($sourceBindings, 'routing_key') === SearchConsumerTopology::ROUTING_KEYS, 'source exchange 绑定事件不精确');
$assert(!in_array('message.deleted_self', array_column($sourceBindings, 'routing_key'), true), '搜索消费者错误绑定 deleted_self');
$assert(!in_array('im.message.after', array_column($config->topology->bindings(), 'exchange'), true), '搜索消费者错误消费 realtime queue');
$assert($config->maxRetries === 6, 'maxRetries 未从固定 production schedule 派生');
$assert($config->retryDelayMs(0) === 1000 && $config->retryDelayMs(5) === 32000, '固定指数退避 schedule 错误');
$retryTiers = $config->topology->retryTiers();
$assert(array_column($retryTiers, 'delay_ms') === SearchConsumerTopology::PRODUCTION_RETRY_DELAYS_MS, 'production retry schedule 未精确固化');
$assert($config->topology->isCanonical(), 'production topology 未精确包含固定 retry schedule');
$assert(count(array_unique(array_column($retryTiers, 'queue'))) === 6, 'retry tier 未使用独立 durable queue');
$retryQueueArguments = array_column(array_slice($config->topology->queues(), 1, 6), 'arguments');
$assert(array_column($retryQueueArguments, 'x-message-ttl') === SearchConsumerTopology::PRODUCTION_RETRY_DELAYS_MS, 'retry tier queue 未使用固定 x-message-ttl');
$customScheduleTopology = new SearchConsumerTopology(
    SearchConsumerTopology::SOURCE_EXCHANGE,
    SearchConsumerTopology::WORK_EXCHANGE,
    SearchConsumerTopology::MAIN_QUEUE,
    SearchConsumerTopology::RETRY_EXCHANGE,
    SearchConsumerTopology::RETRY_QUEUE,
    SearchConsumerTopology::DEAD_EXCHANGE,
    SearchConsumerTopology::DEAD_QUEUE,
    [400, 100, 200, 300],
);
$assert(!$customScheduleTopology->isCanonical(), 'custom retry schedule 被误判为 production canonical topology');

$searchConfigSource = (string) file_get_contents(dirname(__DIR__) . '/plugin/saimulti/config/search.php');
$environmentExample = (string) file_get_contents(dirname(__DIR__) . '/.env.example');
foreach ([
    'SEARCH_CONSUMER_MAX_RETRIES',
    'SEARCH_CONSUMER_RETRY_BASE_DELAY_MS',
    'SEARCH_CONSUMER_RETRY_MAX_DELAY_MS',
    'SEARCH_CONSUMER_HEARTBEAT_INTERVAL_SECONDS',
    'SEARCH_CONSUMER_SOURCE_EXCHANGE',
    'SEARCH_CONSUMER_WORK_EXCHANGE',
    'SEARCH_CONSUMER_QUEUE',
    'SEARCH_CONSUMER_RETRY_EXCHANGE',
    'SEARCH_CONSUMER_RETRY_QUEUE',
    'SEARCH_CONSUMER_DEAD_EXCHANGE',
    'SEARCH_CONSUMER_DEAD_QUEUE',
] as $removedEnvironmentKey) {
    $assert(!str_contains($searchConfigSource, $removedEnvironmentKey), $removedEnvironmentKey . ' 仍暴露在 search.php');
    $assert(!str_contains($environmentExample, $removedEnvironmentKey), $removedEnvironmentKey . ' 仍暴露在 .env.example');
}

$protocolChannel = new SearchConsumerFakeAmqpChannel();
$protocolTransport = new PhpAmqpLibSearchConsumerTransport(
    $config,
    new SearchConsumerFakeAmqpConnectionFactory($protocolChannel),
);
$protocolTransport->open($config->topology, 1);
$assert(in_array('confirm-select', $protocolChannel->events, true), 'transport open 未启用 publisher confirm_select');
$protocolMessageId = str_repeat('b', 64);
$protocolTransport->publishRetry(
    'protocol-body',
    'message.created',
    ['traceparent' => 'trace'],
    $protocolMessageId,
    2,
);
$protocolPublish = array_values(array_filter($protocolChannel->events, static fn (mixed $event): bool => is_array($event) && array_key_exists('publish', $event)))[0];
$assert($protocolPublish['mandatory'] === true, 'retry publish 未使用 mandatory=true');
$assert($protocolPublish['persistent'] === true, 'retry publish 未使用 persistent delivery mode');
$assert($protocolPublish['exchange'] === $retryTiers[1]['exchange'], 'retry publish 未选择对应 tier exchange');
$assert($protocolPublish['message_id'] === $protocolMessageId, 'retry publish 未保留 broker message_id');
$assert(!array_key_exists('expiration', $protocolPublish), 'retry publish 仍使用 per-message expiration');
$assertEvents($protocolChannel->events, ['wait-confirm:2', 'publisher-ack'], 'retry publish 未等待 broker confirm');
foreach (['nack', 'return', 'timeout'] as $confirmOutcome) {
    $protocolChannel->confirmOutcome = $confirmOutcome;
    try {
        $protocolTransport->publishRetry(
            'failure-' . $confirmOutcome,
            'message.created',
            [],
            $protocolMessageId,
            1,
        );
        throw new RuntimeException($confirmOutcome . ' 未令 publisher 抛错');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() !== $confirmOutcome . ' 未令 publisher 抛错', $confirmOutcome . ' 未令 publisher 抛错');
    }
}

$runtimeChannel = new SearchConsumerFakeAmqpChannel();
$runtimeTransport = new PhpAmqpLibSearchConsumerTransport(
    $config,
    new SearchConsumerFakeAmqpConnectionFactory($runtimeChannel),
);
$runtimeChannel->deliveries[] = $delivery(70, $eventBody('protocol-confirm-before-ack'));
$runtime = new SearchConsumerRuntime(
    $config,
    $runtimeTransport,
    new SearchConsumerFakeHeartbeat(),
    new SearchConsumerFakeClock(),
    new MessageEventHandler(new SearchConsumerFakeAccess(AccessDecision::UNAVAILABLE), new SearchConsumerFakeWriter()),
);
$runtime->start();
$runtime->tick();
$assertEvents($runtimeChannel->events, ['publisher-ack', 'ack:70'], 'runtime 在 retry publisher confirm 前 ACK 原消息');

$runtimeFailureChannel = new SearchConsumerFakeAmqpChannel();
$runtimeFailureChannel->confirmOutcome = 'return';
$runtimeFailureTransport = new PhpAmqpLibSearchConsumerTransport(
    $config,
    new SearchConsumerFakeAmqpConnectionFactory($runtimeFailureChannel),
);
$runtimeFailureChannel->deliveries[] = $delivery(71, $eventBody('protocol-return-no-ack'));
$runtimeFailure = new SearchConsumerRuntime(
    $config,
    $runtimeFailureTransport,
    new SearchConsumerFakeHeartbeat(),
    new SearchConsumerFakeClock(),
    new MessageEventHandler(new SearchConsumerFakeAccess(AccessDecision::UNAVAILABLE), new SearchConsumerFakeWriter()),
);
$runtimeFailure->start();
$runtimeFailure->tick();
$assertEvents($runtimeFailureChannel->events, ['publisher-return', 'nack:71:1'], 'mandatory return 后 runtime 未 NACK 原消息');
$assert(!in_array('ack:71', $runtimeFailureChannel->events, true), 'mandatory return 后 runtime 错误 ACK 原消息');

$invalidConfig = $configValues();
$invalidConfig['poll_interval_seconds'] = 5.0;
$invalidConfig['max_tick_duration_ms'] = 5000;
$invalidConfig['heartbeat_ttl_seconds'] = 14;
try {
    SearchConsumerConfig::fromArray($invalidConfig);
    throw new RuntimeException('非法 heartbeat TTL 未被拒绝');
} catch (InvalidArgumentException) {
    $passed++;
}
$invalidConfig = $configValues();
$invalidConfig['confirm_timeout_seconds'] = 10.0;
$invalidConfig['heartbeat_ttl_seconds'] = 29;
try {
    SearchConsumerConfig::fromArray($invalidConfig);
    throw new RuntimeException('heartbeat TTL 未覆盖 publisher confirm timeout 三倍窗口');
} catch (InvalidArgumentException) {
    $passed++;
}
foreach (['source_exchange', 'max_retries', 'heartbeat_interval_seconds'] as $removedConfigKey) {
    $invalidConfig = $configValues();
    $invalidConfig[$removedConfigKey] = $removedConfigKey === 'source_exchange' ? 'custom.search.source' : 1;
    try {
        SearchConsumerConfig::fromArray($invalidConfig);
        throw new RuntimeException('已删除配置键仍被 fromArray 接受: ' . $removedConfigKey);
    } catch (InvalidArgumentException) {
        $passed++;
    }
}

[$runtime, , $transport, , , $access, $writer] = $build();
$transport->deliveries[] = $delivery(1, $eventBody('message-available'));
$runtime->start();
$runtime->tick();
$assert($writer->writes === [[9, 'message-available']], 'AVAILABLE 未以 organization + message_id 写投影');
$assert($access->organizations === [9], '访问判断未使用事件 organization');
$assertEvents($transport->events, ['next', 'ack:1'], 'AVAILABLE manual ACK 顺序错误');
$runtime->stop();

$deniedAccess = new SearchConsumerFakeAccess(AccessDecision::DENIED);
[$runtime, , $transport, , , , $writer] = $build(access: $deniedAccess);
$transport->deliveries[] = $delivery(2, $eventBody('message-denied'));
$runtime->start();
$runtime->tick();
$assert($writer->writes === [], 'DENIED 仍写搜索投影');
$assert($writer->denials === [[9, 'message-denied']], 'DENIED 未持久化 rebuild-required fence');
$assertEvents($transport->events, ['next', 'ack:2'], 'DENIED fence 成功后未 ACK');

$unavailableAccess = new SearchConsumerFakeAccess(AccessDecision::UNAVAILABLE);
[$runtime, , $transport] = $build(access: $unavailableAccess);
$body = $eventBody('message-unavailable');
$transport->deliveries[] = $delivery(3, $body, ['traceparent' => 'trace-value']);
$runtime->start();
$runtime->tick();
$assertEvents($transport->events, ['next', 'publish-confirm:1', 'ack:3'], 'UNAVAILABLE 未在 confirm 后 ACK');
$assert($transport->published[0]['body'] === $body && $transport->published[0]['routing_key'] === 'message.created', 'retry 未保留原 body/routing key');
$expectedRetryHeaders = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
$expectedRetryHeaders['traceparent'] = 'trace-value';
$expectedRetryHeaders[SearchConsumerRuntime::RETRY_COUNT_HEADER] = 1;
$assert($transport->published[0]['headers'] === $expectedRetryHeaders, 'retry header 未严格自增或未保留已有 header');
$assert($transport->published[0]['message_id'] === str_repeat('a', 64), 'retry 未保留 broker message_id');
$assert($transport->published[0]['tier'] === 1 && $transport->published[0]['delay_ms'] === 1000, '首次 retry tier/延迟错误');

$failingWriter = new SearchConsumerFakeWriter();
$failingWriter->fail = true;
[$runtime, , $transport] = $build(writer: $failingWriter);
$transport->deliveries[] = $delivery(4, $eventBody('message-write-failure'));
$runtime->start();
$runtime->tick();
$assertEvents($transport->events, ['next', 'publish-confirm:1', 'ack:4'], 'writer 暂态失败未进入 confirm retry');

$transport = new SearchConsumerFakeTransport();
$transport->failPublish = true;
[$runtime, , $transport] = $build(transport: $transport, access: new SearchConsumerFakeAccess(AccessDecision::UNAVAILABLE));
$transport->deliveries[] = $delivery(5, $eventBody('message-confirm-failure'));
$runtime->start();
$runtime->tick();
$assertEvents($transport->events, ['next', 'publish-confirm:1', 'nack-requeue:5'], 'publish/confirm 失败未 NACK requeue');
$assert(!in_array('ack:5', $transport->events, true), 'publish/confirm 失败错误 ACK 原消息');

[$runtime, , $transport] = $build();
$transport->deliveries[] = $delivery(6, '{not-json');
$runtime->start();
$runtime->tick();
$assertEvents($transport->events, ['next', 'reject:6'], 'poison JSON 未 reject(no requeue)');

$strictPayload = json_decode($eventBody('strict-extra-field'), true, flags: JSON_THROW_ON_ERROR);
$strictPayload['content'] = 'must not cross the authoritative event contract';
[$runtime, , $transport] = $build();
$transport->deliveries[] = $delivery(63, json_encode($strictPayload, JSON_THROW_ON_ERROR));
$runtime->start();
$runtime->tick();
$assert(in_array('reject:63', $transport->events, true), '额外 body 字段未按 poison 进入 DLQ');

[$runtime, , $transport] = $build();
$transport->deliveries[] = $delivery(
    64,
    $eventBody('strict-header-mismatch'),
    ['event_id' => str_repeat('b', 64)],
);
$runtime->start();
$runtime->tick();
$assert(in_array('reject:64', $transport->events, true), 'header/body identity 不一致未进入 DLQ');

[$runtime, , $transport] = $build();
$transport->deliveries[] = $delivery(
    65,
    $eventBody('strict-property-mismatch'),
    brokerMessageId: str_repeat('b', 64),
);
$runtime->start();
$runtime->tick();
$assert(in_array('reject:65', $transport->events, true), 'broker message_id/event_id 不一致未进入 DLQ');

$deniedFailWriter = new SearchConsumerFakeWriter();
$deniedFailWriter->fail = true;
[$runtime, , $transport] = $build(
    access: new SearchConsumerFakeAccess(AccessDecision::DENIED),
    writer: $deniedFailWriter,
);
$transport->deliveries[] = $delivery(66, $eventBody('denied-fence-failure'));
$runtime->start();
$runtime->tick();
$assertEvents($transport->events, ['next', 'publish-confirm:1', 'ack:66'], 'DENIED fence 失败未进入 retry');

$poisonFenceHeartbeat = new SearchConsumerFakeHeartbeat();
[$poisonOwnerRuntime, $poisonOwnerConfig, $poisonOwnerTransport] = $build(heartbeat: $poisonFenceHeartbeat);
[$poisonStandbyRuntime] = $build(heartbeat: $poisonFenceHeartbeat);
$poisonOwnerRuntime->start();
$poisonStandbyRuntime->start();
$poisonOwnerTransport->onNext = static function () use (
    $poisonFenceHeartbeat,
    $poisonOwnerConfig,
    $poisonStandbyRuntime,
): void {
    unset($poisonFenceHeartbeat->values[$poisonOwnerConfig->heartbeatRedisKey]);
    $poisonStandbyRuntime->tick();
};
$poisonOwnerTransport->deliveries[] = $delivery(7, '{stale-owner-poison');
$poisonOwnerRuntime->tick();
$assert(in_array('nack-requeue:7', $poisonOwnerTransport->events, true), 'poison settlement 前失主未 NACK requeue');
$assert(!in_array('reject:7', $poisonOwnerTransport->events, true), 'poison settlement 前失主仍 reject 到 DLQ');

foreach (['1', 0, 7, true] as $index => $invalidHeader) {
    [$runtime, , $transport] = $build();
    $transport->deliveries[] = $delivery(
        10 + $index,
        $eventBody('message-bad-header-' . $index),
        [SearchConsumerRuntime::RETRY_COUNT_HEADER => $invalidHeader],
    );
    $runtime->start();
    $runtime->tick();
    $assert(in_array('reject:' . (10 + $index), $transport->events, true), '非法 retry header 未按 poison reject');
}

[$runtime, , $transport] = $build(access: new SearchConsumerFakeAccess(AccessDecision::UNAVAILABLE));
$transport->deliveries[] = $delivery(
    20,
    $eventBody('message-exhausted'),
    [SearchConsumerRuntime::RETRY_COUNT_HEADER => 6],
);
$runtime->start();
$runtime->tick();
$assertEvents($transport->events, ['next', 'reject:20'], 'retry 达 max 未进入 DLQ');
$assert($transport->published === [], 'retry 耗尽仍重新发布');

[$runtime, , $transport] = $build(access: new SearchConsumerFakeAccess(AccessDecision::UNAVAILABLE));
$transport->deliveries[] = $delivery(
    21,
    $eventBody('message-third-retry'),
    [SearchConsumerRuntime::RETRY_COUNT_HEADER => 2],
);
$runtime->start();
$runtime->tick();
$assert($transport->published[0]['headers'][SearchConsumerRuntime::RETRY_COUNT_HEADER] === 3, 'retry count 未严格自增');
$assert($transport->published[0]['tier'] === 3 && $transport->published[0]['delay_ms'] === 4000, '指数退避第三次 tier/延迟错误');

[$runtime, , $transport] = $build();
$transport->deliveries[] = $delivery(22, $eventBody('message-reentry'));
$transport->onNext = static fn () => $runtime->tick();
$runtime->start();
$runtime->tick();
$assert($transport->nextCount === 2, 'tick 重入绕过了正常单次 drain 控制');

[$runtime, , $transport] = $build();
foreach (range(30, 34) as $token) {
    $transport->deliveries[] = $delivery($token, $eventBody('message-burst-' . $token));
}
$runtime->start();
$runtime->tick();
$assert($transport->nextCount === 3, '单 tick 超过 max_messages_per_tick');
$assert(in_array('ack:32', $transport->events, true) && !in_array('ack:33', $transport->events, true), 'burst batch 边界错误');
$runtime->tick();
$assert(in_array('ack:33', $transport->events, true) && in_array('ack:34', $transport->events, true), '后续 tick 未继续 drain backlog');

$wallClock = new SearchConsumerFakeClock();
$wallTransport = new SearchConsumerFakeTransport();
[$runtime, , $wallTransport] = $build(transport: $wallTransport, clock: $wallClock);
foreach (range(40, 42) as $token) {
    $wallTransport->deliveries[] = $delivery($token, $eventBody('message-wall-' . $token));
}
$wallTransport->onNext = static function () use ($wallClock): void {
    $wallClock->timestamp -= 1_000;
    $wallClock->monotonicTimeMs += 110;
};
$runtime->start();
$runtime->tick();
$assert($wallTransport->nextCount === 2, '单 tick 超过 wall-clock deadline');
$assert(in_array('ack:41', $wallTransport->events, true) && !in_array('ack:42', $wallTransport->events, true), 'wall-clock deadline 未保留剩余 backlog');

$ackClock = new SearchConsumerFakeClock();
$ackHeartbeat = new SearchConsumerFakeHeartbeat();
$ackTransport = new SearchConsumerFakeTransport();
$ackWriter = new SearchConsumerFakeWriter();
$ackWriter->onWrite = static function () use ($ackClock, $ackHeartbeat): void {
    $ackClock->timestamp -= 3_600;
    $ackClock->monotonicTimeMs += 5_000;
    $ackHeartbeat->failWrite = true;
};
[$runtime, , $ackTransport] = $build(
    transport: $ackTransport,
    heartbeat: $ackHeartbeat,
    clock: $ackClock,
    writer: $ackWriter,
);
$ackTransport->deliveries[] = $delivery(45, $eventBody('message-heartbeat-before-ack'));
$runtime->start();
$runtime->tick();
$assert(in_array('nack-requeue:45', $ackTransport->events, true), 'handler 后 heartbeat 失败未 NACK requeue');
$assert(!in_array('ack:45', $ackTransport->events, true), 'handler 后 heartbeat 失败仍 ACK');

$confirmClock = new SearchConsumerFakeClock();
$confirmHeartbeat = new SearchConsumerFakeHeartbeat();
$confirmTransport = new SearchConsumerFakeTransport();
$confirmTransport->onPublish = static function () use ($confirmClock, $confirmHeartbeat): void {
    $confirmClock->timestamp -= 3_600;
    $confirmClock->monotonicTimeMs += 5_000;
    $confirmHeartbeat->failWrite = true;
};
[$runtime, , $confirmTransport] = $build(
    transport: $confirmTransport,
    heartbeat: $confirmHeartbeat,
    clock: $confirmClock,
    access: new SearchConsumerFakeAccess(AccessDecision::UNAVAILABLE),
);
$confirmTransport->deliveries[] = $delivery(46, $eventBody('message-heartbeat-after-confirm'));
$runtime->start();
$runtime->tick();
$assertEvents($confirmTransport->events, ['publish-confirm:1', 'nack-requeue:46'], 'confirm 后 heartbeat 失败顺序错误');
$assert(!in_array('ack:46', $confirmTransport->events, true), 'confirm 后 heartbeat 失败仍 ACK 原消息');
$assert($confirmTransport->nextCount === 1, 'confirm/heartbeat 失败后同 tick 立即重取');

$deadlineClock = new SearchConsumerFakeClock();
$deadlineHeartbeat = new SearchConsumerFakeHeartbeat();
$deadlineTransport = new SearchConsumerFakeTransport();
[$runtime, , $deadlineTransport] = $build(
    transport: $deadlineTransport,
    heartbeat: $deadlineHeartbeat,
    clock: $deadlineClock,
);
$deadlineTransport->deliveries[] = $delivery(50, $eventBody('message-heartbeat-first'));
$deadlineTransport->deliveries[] = $delivery(51, $eventBody('message-heartbeat-paused'));
$deadlineTransport->onNext = static function () use ($deadlineClock, $deadlineHeartbeat, $deadlineTransport): void {
    if ($deadlineTransport->nextCount === 1) {
        $deadlineClock->timestamp -= 3_600;
        $deadlineClock->monotonicTimeMs += 5_000;
        $deadlineHeartbeat->failWrite = true;
    }
};
$runtime->start();
$runtime->tick();
$assert($deadlineTransport->nextCount === 1, 'heartbeat deadline 写失败后仍 fetch 下一条');
$assert(in_array('nack-requeue:50', $deadlineTransport->events, true), 'heartbeat deadline 未重排当前未 ACK 消息');
$assert(!in_array('ack:50', $deadlineTransport->events, true) && !in_array('ack:51', $deadlineTransport->events, true), 'heartbeat deadline 未暂停 burst 消费');

$clock = new SearchConsumerFakeClock();
$heartbeat = new SearchConsumerFakeHeartbeat();
[$runtime, $heartbeatConfig, $transport] = $build(heartbeat: $heartbeat, clock: $clock);
$runtime->start();
$clock->timestamp -= 3_600;
$clock->monotonicTimeMs += 5_000;
$heartbeat->failWrite = true;
$transport->deliveries[] = $delivery(23, $eventBody('message-paused'));
$runtime->tick();
$assert($transport->nextCount === 0 && !in_array('ack:23', $transport->events, true), 'heartbeat 写失败仍消费或 ACK 新消息');
$heartbeat->failWrite = false;
unset($heartbeat->values[$heartbeatConfig->heartbeatRedisKey]);
$runtime->tick();
$assert(in_array('ack:23', $transport->events, true), 'heartbeat TTL 消失后失主实例未以 NX 重新 claim 并继续消费');

$gatedHeartbeat = new SearchConsumerFakeHeartbeat();
$gatedClock = new SearchConsumerFakeClock();
$gatedTransport = new SearchConsumerFakeTransport();
$closedGate = new SearchConsumerFakeGate(false);
[$gatedRuntime, $gatedConfig] = $build(
    transport: $gatedTransport,
    heartbeat: $gatedHeartbeat,
    clock: $gatedClock,
    gate: $closedGate,
);
$gatedRuntime->start();
$gatedClock->timestamp += $gatedConfig->heartbeatTtlSeconds + 1;
$gatedClock->monotonicTimeMs += ($gatedConfig->heartbeatTtlSeconds + 1) * 1000;
$gatedRuntime->tick();
$firstGatedHeartbeat = json_decode(
    (string) ($gatedHeartbeat->values[$gatedConfig->heartbeatRedisKey] ?? ''),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$gatedClock->timestamp += $gatedConfig->heartbeatTtlSeconds + 1;
$gatedClock->monotonicTimeMs += ($gatedConfig->heartbeatTtlSeconds + 1) * 1000;
$gatedRuntime->tick();
$secondGatedHeartbeat = json_decode(
    (string) ($gatedHeartbeat->values[$gatedConfig->heartbeatRedisKey] ?? ''),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$assert(
    $gatedTransport->nextCount === 0
    && $closedGate->checks === 2
    && ($firstGatedHeartbeat['updated_at'] ?? null) === 1_016
    && ($secondGatedHeartbeat['updated_at'] ?? null) === 1_032,
    'lifecycle gate 关闭超过 heartbeat TTL 时 consumer 未续约 readiness 或错误 fetch',
);
$closedGate->allowed = true;
$gatedTransport->deliveries[] = $delivery(72, $eventBody('gated-enable-resume'));
$gatedRuntime->tick();
$assert(in_array('ack:72', $gatedTransport->events, true), 'gate 重开后 heartbeat owner 未恢复 fetch');

$heartbeatKey = $heartbeatConfig->heartbeatRedisKey;
$newWorkerValue = '{"instance":"new-worker"}';
$heartbeat->values[$heartbeatKey] = $newWorkerValue;
$runtime->stop();
$assert(($heartbeat->values[$heartbeatKey] ?? null) === $newWorkerValue, 'stop CAS 删除了新 worker heartbeat');
$assert(in_array('close', $transport->events, true), 'stop 未关闭 AMQP transport');

$rollingHeartbeat = new SearchConsumerFakeHeartbeat();
$oldValues = $configValues();
$oldValues['deployment_id'] = 'deployment-old';
$currentValues = $configValues();
$currentValues['deployment_id'] = 'deployment-current';
$oldClock = new SearchConsumerFakeClock();
$currentClock = new SearchConsumerFakeClock();
[$oldRuntime, $oldConfig, $oldTransport] = $build(
    heartbeat: $rollingHeartbeat,
    clock: $oldClock,
    values: $oldValues,
);
[$currentRuntime, $currentConfig] = $build(
    heartbeat: $rollingHeartbeat,
    clock: $currentClock,
    values: $currentValues,
);
$oldRuntime->start();
$currentRuntime->start();
$assert($oldConfig->heartbeatRedisKey !== $currentConfig->heartbeatRedisKey, '滚动 deployment 共用了 heartbeat Redis key');
$assert($oldConfig->heartbeatRedisKey === SearchConsumerHeartbeatKey::forDeployment('test:search:heartbeat', 'deployment-old'), 'deployment heartbeat key 未由稳定 hash 派生');
$currentValueBeforeOldActivity = $rollingHeartbeat->values[$currentConfig->heartbeatRedisKey] ?? null;
$oldClock->monotonicTimeMs += 5_000;
$oldRuntime->tick();
$oldRuntime->stop();
$assert(($rollingHeartbeat->values[$currentConfig->heartbeatRedisKey] ?? null) === $currentValueBeforeOldActivity, '旧 deployment refresh/stop 影响当前 deployment heartbeat');
$currentReader = SearchConsumerReadinessReader::fromConfig($rollingHeartbeat, $currentClock, $currentConfig);
$assert($currentReader->isReady(), '旧 deployment 停止导致当前 deployment readiness 失败');
$currentPayload = json_decode((string) $currentValueBeforeOldActivity, true, flags: JSON_THROW_ON_ERROR);
$assert(array_keys($currentPayload) === ['deployment', 'instance', 'queue', 'topology', 'status', 'updated_at'], 'heartbeat payload 字段不严格');

$ownerHeartbeat = new SearchConsumerFakeHeartbeat();
$ownerClock = new SearchConsumerFakeClock();
$standbyClock = new SearchConsumerFakeClock();
[$ownerRuntime, $ownerConfig, $ownerTransport] = $build(
    heartbeat: $ownerHeartbeat,
    clock: $ownerClock,
);
[$standbyRuntime, $standbyConfig, $standbyTransport] = $build(
    heartbeat: $ownerHeartbeat,
    clock: $standbyClock,
);
$ownerRuntime->start();
$standbyRuntime->start();
$assert($ownerConfig->heartbeatRedisKey === $standbyConfig->heartbeatRedisKey, '同 deployment 未共享派生 heartbeat key');
$standbyTransport->deliveries[] = $delivery(60, $eventBody('standby-must-not-fetch'));
$standbyRuntime->tick();
$assert($standbyTransport->nextCount === 0 && !in_array('ack:60', $standbyTransport->events, true), 'standby 在未持有 heartbeat ownership 时 fetch/ACK');
unset($ownerHeartbeat->values[$ownerConfig->heartbeatRedisKey]);
$standbyRuntime->tick();
$assert(in_array('ack:60', $standbyTransport->events, true), 'owner TTL 消失后 standby 未接管消费');
$ownerClock->monotonicTimeMs += 5_000;
$ownerRuntime->tick();
$assert($ownerTransport->nextCount === 0, '旧 owner CAS renew 失败后仍 fetch');

$handoffHeartbeat = new SearchConsumerFakeHeartbeat();
$handoffOwnerClock = new SearchConsumerFakeClock();
$handoffStandbyClock = new SearchConsumerFakeClock();
$handoffWriter = new SearchConsumerFakeWriter();
[$handoffOwnerRuntime, $handoffOwnerConfig, $handoffOwnerTransport] = $build(
    heartbeat: $handoffHeartbeat,
    clock: $handoffOwnerClock,
    writer: $handoffWriter,
);
[$handoffStandbyRuntime, , $handoffStandbyTransport] = $build(
    heartbeat: $handoffHeartbeat,
    clock: $handoffStandbyClock,
);
$handoffOwnerRuntime->start();
$handoffStandbyRuntime->start();
$handoffWriter->onWrite = static function () use (
    $handoffHeartbeat,
    $handoffOwnerConfig,
    $handoffOwnerClock,
    $handoffStandbyRuntime,
): void {
    unset($handoffHeartbeat->values[$handoffOwnerConfig->heartbeatRedisKey]);
    $handoffStandbyRuntime->tick();
};
$handoffOwnerTransport->deliveries[] = $delivery(61, $eventBody('stale-owner-handler'));
$handoffOwnerRuntime->tick();
$assert(in_array('nack-requeue:61', $handoffOwnerTransport->events, true), 'handler 执行中 ownership 转移后旧 owner 未 NACK 当前消息');
$assert(!in_array('ack:61', $handoffOwnerTransport->events, true), 'handler 执行中 ownership 转移后旧 owner 错误 ACK');
$handoffWriter->onWrite = null;
unset($handoffHeartbeat->values[$handoffOwnerConfig->heartbeatRedisKey]);
$handoffOwnerTransport->deliveries[] = $delivery(62, $eventBody('lost-owner-reclaim'));
$handoffOwnerRuntime->tick();
$assert(in_array('ack:62', $handoffOwnerTransport->events, true), '失主 token 未清理，key TTL 消失后原实例无法重新 NX claim');

$readinessHeartbeat = new SearchConsumerFakeHeartbeat();
$readinessClock = new SearchConsumerFakeClock();
$readinessHeartbeat->values[$heartbeatKey] = json_encode([
    'deployment' => 'test-deployment',
    'instance' => 'test-instance',
    'queue' => SearchConsumerTopology::MAIN_QUEUE,
    'topology' => SearchConsumerTopology::VERSION,
    'status' => 'ready',
    'updated_at' => 1_000,
], JSON_THROW_ON_ERROR);
$reader = SearchConsumerReadinessReader::fromConfig($readinessHeartbeat, $readinessClock, $config);
$assert($reader->isReady(), '有效 consumer heartbeat 未被 readiness reader 接受');
$validReadiness = $readinessHeartbeat->values[$heartbeatKey];
$wrongReadiness = json_decode($validReadiness, true, flags: JSON_THROW_ON_ERROR);
$wrongReadiness['legacy'] = true;
$readinessHeartbeat->values[$heartbeatKey] = json_encode($wrongReadiness, JSON_THROW_ON_ERROR);
$assert(!$reader->isReady(), '含额外字段的 heartbeat 被 readiness reader 接受');
unset($wrongReadiness['legacy']);
$wrongReadiness['deployment'] = 'old-deployment';
$readinessHeartbeat->values[$heartbeatKey] = json_encode($wrongReadiness, JSON_THROW_ON_ERROR);
$assert(!$reader->isReady(), '旧 deployment heartbeat 被 readiness reader 接受');
$wrongReadiness['deployment'] = 'test-deployment';
$wrongReadiness['queue'] = 'old.search.queue';
$readinessHeartbeat->values[$heartbeatKey] = json_encode($wrongReadiness, JSON_THROW_ON_ERROR);
$assert(!$reader->isReady(), '错误 queue heartbeat 被 readiness reader 接受');
$wrongReadiness['queue'] = SearchConsumerTopology::MAIN_QUEUE;
$wrongReadiness['topology'] = 'search-message-index-v0';
$readinessHeartbeat->values[$heartbeatKey] = json_encode($wrongReadiness, JSON_THROW_ON_ERROR);
$assert(!$reader->isReady(), '旧 topology heartbeat 被 readiness reader 接受');
$readinessHeartbeat->values[$heartbeatKey] = $validReadiness;
$readinessClock->timestamp = 1_016;
$assert(!$reader->isReady(), '过期 consumer heartbeat 被 readiness reader 接受');
$readinessHeartbeat->values[$heartbeatKey] = '{invalid';
$assert(!$reader->isReady(), '损坏 heartbeat 被 readiness reader 接受');

$accessStore = new SearchConsumerAccessStore();
$accessCache = new SearchConsumerAccessCache();
$serverAccess = new ServerSearchAccessDecider(new ModuleAccessService($accessStore, $accessCache));
$assert($serverAccess->decide(9) === AccessDecision::AVAILABLE, 'Server search access adapter 未固定 search/server/search.index.write');
$assert($accessStore->reads === [[9, 'search']], 'Server search access adapter 未直读 search 模块授权');
$accessStore->fail = true;
$assert($serverAccess->decide(9) === AccessDecision::UNAVAILABLE, 'Server search access adapter 丢失 UNAVAILABLE');

$projectionService = new SearchConsumerProjectionService();
$serverWriter = new ServerSearchProjectionWriter($projectionService);
$assert($serverWriter instanceof ProjectionWriter, 'Server projection writer 未实现模块原生契约');

$rawRedis = new SearchConsumerRawRedis();
$redisHeartbeat = new RedisSearchConsumerHeartbeatStore($rawRedis);
$assert($redisHeartbeat->claimOrRenew('consumer:heartbeat', null, 'worker-a', 15), 'Redis heartbeat NX claim 失败');
$assert(!$redisHeartbeat->claimOrRenew('consumer:heartbeat', null, 'worker-b', 15), 'Redis heartbeat NX claim 覆盖现 owner');
$assert($redisHeartbeat->claimOrRenew('consumer:heartbeat', 'worker-a', 'worker-a-renewed', 15), 'Redis heartbeat CAS renew 失败');
$assert($redisHeartbeat->read('consumer:heartbeat') === 'worker-a-renewed', 'Redis heartbeat claim/renew/read 契约错误');
$assert(!$redisHeartbeat->deleteIfEquals('consumer:heartbeat', 'worker-b'), 'Redis heartbeat CAS 删除了新 worker value');
$assert($redisHeartbeat->deleteIfEquals('consumer:heartbeat', 'worker-a-renewed'), 'Redis heartbeat CAS 未删除本实例 value');

[$processRuntime, $processRuntimeConfig, $processTransport, $processHeartbeat] = $build();
$timerCallback = null;
$timerDeleted = [];
$process = new SearchConsumerProcess(
    static fn (): array => $configValues(),
    static fn (SearchConsumerConfig $config): SearchConsumerRuntime => $processRuntime,
    static function (float $interval, callable $callback) use (&$timerCallback, $assert): int {
        $assert($interval === 0.25, 'process Timer interval 配置错误');
        $timerCallback = $callback;
        return 77;
    },
    static function (int $timerId) use (&$timerDeleted): bool {
        $timerDeleted[] = $timerId;
        return true;
    },
);
$process->onWorkerStart();
$assert(is_callable($timerCallback), 'process 未注册 bounded Timer');
$timerCallback();
$process->onWorkerStop();
$assert($timerDeleted === [77], 'onWorkerStop 未取消 Timer');
$assert(in_array('close', $processTransport->events, true), 'onWorkerStop 未关闭 runtime transport');
$assert(!isset($processHeartbeat->values[$processRuntimeConfig->heartbeatRedisKey]), '同实例 stop 未 CAS 清理 heartbeat');

$factoryCalled = false;
$disabledValues = $configValues();
$disabledValues['enabled'] = false;
$disabledProcess = new SearchConsumerProcess(
    static fn (): array => $disabledValues,
    static function () use (&$factoryCalled): SearchConsumerRuntime {
        $factoryCalled = true;
        throw new RuntimeException('disabled process must not build runtime');
    },
);
$disabledProcess->onWorkerStart();
$assert(!$factoryCalled, 'SEARCH_CONSUMER_ENABLED=false 仍启动 runtime');

$processConfig = require dirname(__DIR__) . '/plugin/saimulti/config/process.php';
$assert(($processConfig['search-consumer']['count'] ?? null) === 1, 'SearchConsumerProcess count 不等于 1');
$assert(($processConfig['search-consumer']['handler'] ?? null) === SearchConsumerProcess::class, 'SearchConsumerProcess 未独立注册');
$assert(($processConfig['task']['handler'] ?? null) !== SearchConsumerProcess::class, 'SearchConsumerProcess 被错误塞入 Task');

echo sprintf("SearchConsumerTest: %d assertions passed\n", $passed);
