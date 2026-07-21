<?php

declare(strict_types=1);

$boolean = static function (string $name, bool $default): bool {
    $value = env($name, $default);
    if (is_bool($value)) {
        return $value;
    }
    if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
        return strtolower($value) === 'true';
    }
    throw new InvalidArgumentException($name . ' must be true or false.');
};
$integer = static function (string $name, int $default): int {
    $value = env($name, (string) $default);
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
        return (int) $value;
    }
    throw new InvalidArgumentException($name . ' must be a non-negative integer.');
};
$number = static function (string $name, float $default): float {
    $value = env($name, (string) $default);
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1) {
        return (float) $value;
    }
    throw new InvalidArgumentException($name . ' must be a non-negative number.');
};

return [
    'enabled' => $boolean('SEARCH_REBUILD_ENABLED', false),
    'poll_interval_seconds' => $number('SEARCH_REBUILD_POLL_INTERVAL_SECONDS', 0.5),
    'batch_size' => $integer('SEARCH_REBUILD_BATCH_SIZE', 100),
    'cleanup_batch_size' => $integer('SEARCH_REBUILD_CLEANUP_BATCH_SIZE', 100),
    'lease_seconds' => $integer('SEARCH_REBUILD_LEASE_SECONDS', 60),
    'barrier_timeout_seconds' => $integer('SEARCH_REBUILD_BARRIER_TIMEOUT_SECONDS', 300),
    'retry_base_delay_seconds' => $integer('SEARCH_REBUILD_RETRY_BASE_DELAY_SECONDS', 5),
    'retry_max_delay_seconds' => $integer('SEARCH_REBUILD_RETRY_MAX_DELAY_SECONDS', 300),
    'max_retry_attempts' => $integer('SEARCH_REBUILD_MAX_RETRY_ATTEMPTS', 12),
    'deployment_id' => (string) env('DEPLOYMENT_ID', 'b8im-local'),
    'worker_id' => (string) env('SEARCH_REBUILD_WORKER_ID', ''),
    'heartbeat_ttl_seconds' => $integer('SEARCH_REBUILD_HEARTBEAT_TTL_SECONDS', 120),
];
