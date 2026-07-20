<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use InvalidArgumentException;

final class SearchConsumerTopology
{
    public const VERSION = 'search-message-index-v1';

    public const SOURCE_EXCHANGE = 'im.message';

    public const WORK_EXCHANGE = 'search.message.index.work';

    public const MAIN_QUEUE = 'search.message.index';

    public const RETRY_EXCHANGE = 'search.message.index.retry';

    public const RETRY_QUEUE = 'search.message.index.retry';

    public const DEAD_EXCHANGE = 'search.message.index.dead';

    public const DEAD_QUEUE = 'search.message.index.dead';

    public const ROUTING_KEYS = [
        'message.created',
        'message.edited',
        'message.recalled',
        'message.deleted_both',
    ];

    public const PRODUCTION_RETRY_DELAYS_MS = [
        1_000,
        2_000,
        4_000,
        8_000,
        16_000,
        32_000,
    ];

    public function __construct(
        public readonly string $sourceExchange,
        public readonly string $workExchange,
        public readonly string $mainQueue,
        public readonly string $retryExchange,
        public readonly string $retryQueue,
        public readonly string $deadExchange,
        public readonly string $deadQueue,
        /** @var list<int> */
        public readonly array $retryDelaysMs,
    ) {
        foreach ([
            'source_exchange' => $sourceExchange,
            'work_exchange' => $workExchange,
            'main_queue' => $mainQueue,
            'retry_exchange' => $retryExchange,
            'retry_queue' => $retryQueue,
            'dead_exchange' => $deadExchange,
            'dead_queue' => $deadQueue,
        ] as $field => $value) {
            if (strlen($value) > 200 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid search consumer ' . $field . '.');
            }
        }
        if ($sourceExchange !== self::SOURCE_EXCHANGE) {
            throw new InvalidArgumentException('The search source exchange must be im.message.');
        }
        if (in_array('im.message.after', [
            $workExchange,
            $mainQueue,
            $retryExchange,
            $retryQueue,
            $deadExchange,
            $deadQueue,
        ], true)) {
            throw new InvalidArgumentException('The realtime im.message.after topology is reserved.');
        }
        if (count(array_unique([
            $sourceExchange,
            $workExchange,
            $retryExchange,
            $deadExchange,
        ])) !== 4) {
            throw new InvalidArgumentException('Search consumer exchanges must be independent.');
        }
        if (count(array_unique([$mainQueue, $retryQueue, $deadQueue])) !== 3) {
            throw new InvalidArgumentException('Search consumer queues must be independent.');
        }
        if ($retryDelaysMs === [] || !array_is_list($retryDelaysMs) || count($retryDelaysMs) > 20) {
            throw new InvalidArgumentException('Search consumer retry tiers are invalid.');
        }
        foreach ($retryDelaysMs as $delayMs) {
            if (!is_int($delayMs) || $delayMs < 100 || $delayMs > 86_400_000) {
                throw new InvalidArgumentException('Search consumer retry tier delay is invalid.');
            }
        }
        foreach ($this->retryTiers() as $tier) {
            if (strlen($tier['exchange']) > 200 || strlen($tier['queue']) > 200) {
                throw new InvalidArgumentException('Search consumer retry tier name is too long.');
            }
        }
    }

    public function isCanonical(): bool
    {
        return $this->sourceExchange === self::SOURCE_EXCHANGE
            && $this->workExchange === self::WORK_EXCHANGE
            && $this->mainQueue === self::MAIN_QUEUE
            && $this->retryExchange === self::RETRY_EXCHANGE
            && $this->retryQueue === self::RETRY_QUEUE
            && $this->deadExchange === self::DEAD_EXCHANGE
            && $this->deadQueue === self::DEAD_QUEUE
            && $this->retryDelaysMs === self::PRODUCTION_RETRY_DELAYS_MS;
    }

    public static function production(): self
    {
        return new self(
            self::SOURCE_EXCHANGE,
            self::WORK_EXCHANGE,
            self::MAIN_QUEUE,
            self::RETRY_EXCHANGE,
            self::RETRY_QUEUE,
            self::DEAD_EXCHANGE,
            self::DEAD_QUEUE,
            self::PRODUCTION_RETRY_DELAYS_MS,
        );
    }

    /** @return list<array{name:string,type:string,durable:bool}> */
    public function exchanges(): array
    {
        $exchanges = array_map(
            static fn (string $name): array => ['name' => $name, 'type' => 'topic', 'durable' => true],
            [$this->sourceExchange, $this->workExchange, $this->deadExchange],
        );
        foreach ($this->retryTiers() as $tier) {
            $exchanges[] = ['name' => $tier['exchange'], 'type' => 'topic', 'durable' => true];
        }

        return $exchanges;
    }

    /** @return list<array{queue:string,durable:bool,arguments:array<string,int|string>}> */
    public function queues(): array
    {
        $queues = [
            [
                'queue' => $this->mainQueue,
                'durable' => true,
                'arguments' => ['x-dead-letter-exchange' => $this->deadExchange],
            ],
        ];
        foreach ($this->retryTiers() as $tier) {
            $queues[] = [
                'queue' => $tier['queue'],
                'durable' => true,
                'arguments' => [
                    'x-message-ttl' => $tier['delay_ms'],
                    'x-dead-letter-exchange' => $this->workExchange,
                ],
            ];
        }
        $queues[] = ['queue' => $this->deadQueue, 'durable' => true, 'arguments' => []];

        return $queues;
    }

    /** @return list<array{queue:string,exchange:string,routing_key:string}> */
    public function bindings(): array
    {
        $bindings = [];
        foreach (self::ROUTING_KEYS as $routingKey) {
            $bindings[] = ['queue' => $this->mainQueue, 'exchange' => $this->sourceExchange, 'routing_key' => $routingKey];
            $bindings[] = ['queue' => $this->mainQueue, 'exchange' => $this->workExchange, 'routing_key' => $routingKey];
        }
        foreach ($this->retryTiers() as $tier) {
            $bindings[] = ['queue' => $tier['queue'], 'exchange' => $tier['exchange'], 'routing_key' => '#'];
        }
        $bindings[] = ['queue' => $this->deadQueue, 'exchange' => $this->deadExchange, 'routing_key' => '#'];

        return $bindings;
    }

    /** @return list<array{tier:int,delay_ms:int,exchange:string,queue:string}> */
    public function retryTiers(): array
    {
        $tiers = [];
        foreach ($this->retryDelaysMs as $index => $delayMs) {
            $tier = $index + 1;
            $suffix = sprintf('.tier-%02d.delay-%d', $tier, $delayMs);
            $tiers[] = [
                'tier' => $tier,
                'delay_ms' => $delayMs,
                'exchange' => $this->retryExchange . $suffix,
                'queue' => $this->retryQueue . $suffix,
            ];
        }

        return $tiers;
    }

    /** @return array{tier:int,delay_ms:int,exchange:string,queue:string} */
    public function retryTier(int $tier): array
    {
        if ($tier < 1 || !isset($this->retryDelaysMs[$tier - 1])) {
            throw new InvalidArgumentException('Search consumer retry tier is invalid.');
        }

        return $this->retryTiers()[$tier - 1];
    }
}
