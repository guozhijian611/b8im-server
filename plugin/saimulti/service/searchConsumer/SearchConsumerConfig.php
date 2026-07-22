<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use InvalidArgumentException;

final class SearchConsumerConfig
{
    private const INPUT_KEYS = [
        'enabled',
        'host',
        'port',
        'user',
        'password',
        'vhost',
        'connection_timeout_seconds',
        'read_write_timeout_seconds',
        'confirm_timeout_seconds',
        'prefetch',
        'poll_interval_seconds',
        'max_messages_per_tick',
        'max_tick_duration_ms',
        'deployment_id',
        'instance_id',
        'heartbeat_key',
        'heartbeat_ttl_seconds',
    ];

    private function __construct(
        public readonly bool $enabled,
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $password,
        public readonly string $vhost,
        public readonly float $connectionTimeoutSeconds,
        public readonly float $readWriteTimeoutSeconds,
        public readonly float $confirmTimeoutSeconds,
        public readonly int $prefetch,
        public readonly float $pollIntervalSeconds,
        public readonly int $maxMessagesPerTick,
        public readonly int $maxTickDurationMs,
        public readonly int $maxRetries,
        public readonly string $deploymentId,
        public readonly string $instanceId,
        public readonly string $heartbeatRedisKey,
        public readonly int $heartbeatTtlSeconds,
        public readonly SearchConsumerTopology $topology,
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $unknownKeys = array_diff(array_keys($values), self::INPUT_KEYS);
        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('Unknown search consumer configuration key: ' . reset($unknownKeys) . '.');
        }
        $enabled = self::boolean($values, 'enabled');
        $host = self::nonEmptyString($values, 'host', 255);
        $port = self::integer($values, 'port', 1, 65535);
        $user = self::nonEmptyString($values, 'user', 255);
        $password = self::string($values, 'password', 1024);
        $vhost = self::nonEmptyString($values, 'vhost', 255);
        $connectionTimeout = self::number($values, 'connection_timeout_seconds', 0.1, 10.0);
        $readWriteTimeout = self::number($values, 'read_write_timeout_seconds', 0.1, 10.0);
        $confirmTimeout = self::number($values, 'confirm_timeout_seconds', 0.1, 10.0);
        $prefetch = self::integer($values, 'prefetch', 1, 100);
        $pollInterval = self::number($values, 'poll_interval_seconds', 0.05, 5.0);
        $maxMessagesPerTick = self::integer($values, 'max_messages_per_tick', 1, 1000);
        $maxTickDurationMs = self::integer($values, 'max_tick_duration_ms', 10, 5000);
        $deploymentId = self::identifier($values, 'deployment_id', 128);
        $instancePrefix = self::string($values, 'instance_id', 180);
        if ($instancePrefix === '') {
            $hostName = gethostname();
            $instancePrefix = $deploymentId . ':'
                . (is_string($hostName) && $hostName !== '' ? $hostName : 'unknown-host');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,179}$/D', $instancePrefix) !== 1) {
            throw new InvalidArgumentException('Invalid search consumer instance_id.');
        }
        $instanceId = sprintf('%s:%d:%s', $instancePrefix, getmypid(), bin2hex(random_bytes(8)));
        $heartbeatBaseKey = self::identifier($values, 'heartbeat_key', 200);
        $heartbeatRedisKey = SearchConsumerHeartbeatKey::forDeployment(
            $heartbeatBaseKey,
            $deploymentId,
        );
        $heartbeatTtl = self::integer($values, 'heartbeat_ttl_seconds', 3, 3600);
        $minimumHeartbeatTtl = max(
            3,
            (int) ceil(max(
                $connectionTimeout,
                $readWriteTimeout,
                $confirmTimeout,
                $pollInterval,
                $maxTickDurationMs / 1000,
            ) * 3),
        );
        if ($heartbeatTtl < $minimumHeartbeatTtl) {
            throw new InvalidArgumentException('Search consumer heartbeat TTL is too short for the runtime I/O bounds.');
        }

        $topology = SearchConsumerTopology::production();
        if (!$topology->isCanonical()) {
            throw new InvalidArgumentException('Search consumer production topology must use canonical names.');
        }

        return new self(
            $enabled,
            $host,
            $port,
            $user,
            $password,
            $vhost,
            $connectionTimeout,
            $readWriteTimeout,
            $confirmTimeout,
            $prefetch,
            $pollInterval,
            $maxMessagesPerTick,
            $maxTickDurationMs,
            count(SearchConsumerTopology::PRODUCTION_RETRY_DELAYS_MS),
            $deploymentId,
            $instanceId,
            $heartbeatRedisKey,
            $heartbeatTtl,
            $topology,
        );
    }

    public function retryDelayMs(int $currentRetryCount): int
    {
        if ($currentRetryCount < 0 || $currentRetryCount >= $this->maxRetries) {
            throw new InvalidArgumentException('Invalid current retry count.');
        }
        return $this->topology->retryTier($currentRetryCount + 1)['delay_ms'];
    }

    /** @param array<string, mixed> $values */
    private static function boolean(array $values, string $key): bool
    {
        if (!array_key_exists($key, $values) || !is_bool($values[$key])) {
            throw new InvalidArgumentException('Invalid search consumer ' . $key . '.');
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function string(array $values, string $key, int $maxLength): string
    {
        if (!array_key_exists($key, $values) || !is_string($values[$key]) || strlen($values[$key]) > $maxLength) {
            throw new InvalidArgumentException('Invalid search consumer ' . $key . '.');
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function nonEmptyString(array $values, string $key, int $maxLength): string
    {
        $value = self::string($values, $key, $maxLength);
        if ($value === '') {
            throw new InvalidArgumentException('Invalid search consumer ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function identifier(array $values, string $key, int $maxLength): string
    {
        $value = self::nonEmptyString($values, $key, $maxLength);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid search consumer ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $key, int $min, int $max): int
    {
        if (!array_key_exists($key, $values) || !is_int($values[$key]) || $values[$key] < $min || $values[$key] > $max) {
            throw new InvalidArgumentException('Invalid search consumer ' . $key . '.');
        }

        return $values[$key];
    }

    /** @param array<string, mixed> $values */
    private static function number(array $values, string $key, float $min, float $max): float
    {
        if (!array_key_exists($key, $values) || !is_int($values[$key]) && !is_float($values[$key])) {
            throw new InvalidArgumentException('Invalid search consumer ' . $key . '.');
        }
        $value = (float) $values[$key];
        if (!is_finite($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Invalid search consumer ' . $key . '.');
        }

        return $value;
    }
}
