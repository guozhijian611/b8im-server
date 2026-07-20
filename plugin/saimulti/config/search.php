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
    'enabled' => $boolean('SEARCH_CONSUMER_ENABLED', false),
    'host' => (string) env('SEARCH_CONSUMER_RABBIT_HOST', '127.0.0.1'),
    'port' => $integer('SEARCH_CONSUMER_RABBIT_PORT', 5672),
    'user' => (string) env('SEARCH_CONSUMER_RABBIT_USER', 'guest'),
    'password' => (string) env('SEARCH_CONSUMER_RABBIT_PASSWORD', 'guest'),
    'vhost' => (string) env('SEARCH_CONSUMER_RABBIT_VHOST', '/'),
    'connection_timeout_seconds' => $number('SEARCH_CONSUMER_RABBIT_CONNECTION_TIMEOUT_SECONDS', 2.0),
    'read_write_timeout_seconds' => $number('SEARCH_CONSUMER_RABBIT_READ_WRITE_TIMEOUT_SECONDS', 2.0),
    'confirm_timeout_seconds' => $number('SEARCH_CONSUMER_RABBIT_CONFIRM_TIMEOUT_SECONDS', 2.0),
    'prefetch' => $integer('SEARCH_CONSUMER_PREFETCH', 1),
    'poll_interval_seconds' => $number('SEARCH_CONSUMER_POLL_INTERVAL_SECONDS', 0.25),
    'max_messages_per_tick' => $integer('SEARCH_CONSUMER_MAX_MESSAGES_PER_TICK', 50),
    'max_tick_duration_ms' => $integer('SEARCH_CONSUMER_MAX_TICK_DURATION_MS', 200),
    'deployment_id' => (string) env('DEPLOYMENT_ID', 'b8im-local'),
    'instance_id' => (string) env('SEARCH_CONSUMER_INSTANCE_ID', ''),
    'heartbeat_key' => (string) env('SEARCH_CONSUMER_HEARTBEAT_KEY', 'b8im:search:consumer:readiness'),
    'heartbeat_ttl_seconds' => $integer('SEARCH_CONSUMER_HEARTBEAT_TTL_SECONDS', 15),
];
