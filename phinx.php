<?php

require_once __DIR__ . '/vendor/autoload.php';

if (is_file(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return $value === false || $value === '' ? $default : $value;
};

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'default',
        'default' => [
            'adapter' => $env('DB_TYPE', 'mysql'),
            'host' => $env('DB_HOST', '127.0.0.1'),
            'name' => $env('DB_NAME', 'nb8im'),
            'user' => $env('DB_USER', 'root'),
            'pass' => $env('DB_PASSWORD', ''),
            'port' => (int) $env('DB_PORT', 3306),
            'charset' => $env('DB_CHARSET', 'utf8mb4'),
            'collation' => $env('DB_COLLATION', 'utf8mb4_general_ci'),
        ],
    ],
    'version_order' => 'creation',
];
