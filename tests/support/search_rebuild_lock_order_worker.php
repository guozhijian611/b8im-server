<?php

declare(strict_types=1);

[$script, $dsn, $username, $password, $organization, $readyFile, $releaseFile] = $argv;

$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        'SELECT id FROM sm_search_index WHERE organization=? AND delete_time IS NULL FOR UPDATE',
    );
    $statement->execute([(int) $organization]);
    if ($statement->fetch() === false) {
        throw new RuntimeException('lock-order worker index row is missing');
    }
    file_put_contents($readyFile, (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    $deadline = microtime(true) + 10;
    while (!is_file($releaseFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('lock-order worker release barrier timed out');
        }
        usleep(10_000);
    }
    usleep(300_000);
    $statement = $pdo->prepare(
        "SELECT id FROM sm_search_job WHERE organization=? AND status='running' FOR UPDATE",
    );
    $statement->execute([(int) $organization]);
    if ($statement->fetch() === false) {
        throw new RuntimeException('lock-order worker job row is missing');
    }
    $pdo->commit();
    fwrite(STDOUT, "ok\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
