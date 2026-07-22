<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use JsonException;
use InvalidArgumentException;
use Throwable;

final class SearchConsumerReadinessReader
{
    public function __construct(
        private readonly SearchConsumerHeartbeatStoreInterface $store,
        private readonly ClockInterface $clock,
        private readonly string $heartbeatKey,
        private readonly int $maximumAgeSeconds,
        private readonly string $expectedDeployment,
        private readonly string $expectedQueue,
        private readonly string $expectedTopology,
    ) {
        if ($heartbeatKey === '' || $maximumAgeSeconds < 1
            || $expectedDeployment === '' || $expectedQueue === '' || $expectedTopology === '') {
            throw new InvalidArgumentException('Invalid search consumer readiness configuration.');
        }
    }

    public static function fromConfig(
        SearchConsumerHeartbeatStoreInterface $store,
        ClockInterface $clock,
        SearchConsumerConfig $config,
    ): self {
        return new self(
            $store,
            $clock,
            $config->heartbeatRedisKey,
            $config->heartbeatTtlSeconds,
            $config->deploymentId,
            $config->topology->mainQueue,
            SearchConsumerTopology::VERSION,
        );
    }

    /**
     * @return array{deployment:string,instance:string,queue:string,topology:string,status:string,updated_at:int}|null
     */
    public function read(): ?array
    {
        try {
            $raw = $this->store->read($this->heartbeatKey);
        } catch (Throwable) {
            return null;
        }
        if ($raw === null) {
            return null;
        }
        try {
            $value = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)
            || count($value) !== 6
            || array_diff(array_keys($value), [
                'deployment',
                'instance',
                'queue',
                'topology',
                'status',
                'updated_at',
            ]) !== []
            || !is_string($value['deployment'] ?? null)
            || !is_string($value['instance'] ?? null)
            || $value['instance'] === ''
            || !is_string($value['queue'] ?? null)
            || !is_string($value['topology'] ?? null)
            || $value['deployment'] !== $this->expectedDeployment
            || $value['queue'] !== $this->expectedQueue
            || $value['topology'] !== $this->expectedTopology
            || ($value['status'] ?? null) !== 'ready'
            || !is_int($value['updated_at'] ?? null)
            || $value['updated_at'] > $this->clock->now()
            || $value['updated_at'] < $this->clock->now() - $this->maximumAgeSeconds) {
            return null;
        }

        return $value;
    }

    public function isReady(): bool
    {
        return $this->read() !== null;
    }
}
