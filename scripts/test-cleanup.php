<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$readEnv = static fn (string $key, string $default): string => (string) (getenv($key) ?: ($_ENV[$key] ?? $default));
$host = $readEnv('DB_HOST', '127.0.0.1');
$port = (int) $readEnv('DB_PORT', '3306');
$user = $readEnv('DB_USER', 'root');
$password = $readEnv('DB_PASSWORD', '');
$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$requested = array_values(array_filter(array_slice($argv, 1), static fn (string $arg): bool => $arg !== '--'));
if ($requested === []) {
    $requested = array_map(
        static fn (array $row): string => (string) array_values($row)[0],
        $pdo->query("SHOW DATABASES LIKE 'nb8im\\_%\\_test'")->fetchAll(PDO::FETCH_ASSOC),
    );
}

$dropped = 0;
foreach (array_unique($requested) as $database) {
    if ($database === 'nb8im' || preg_match('/^nb8im_[a-z0-9_]+_test$/', $database) !== 1) {
        throw new RuntimeException("拒绝清理非安全测试库：{$database}");
    }
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    fwrite(STDOUT, "Dropped test database: {$database}\n");
    $dropped++;
}

fwrite(STDOUT, "Test cleanup complete: {$dropped} database(s)\n");
