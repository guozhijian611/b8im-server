<?php

declare(strict_types=1);

const EXPECTED_AREA_CODE_ROWS = 665552;
const INSERT_BATCH_SIZE = 1000;

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "[area-code] required environment variable is missing: {$name}\n");
        exit(1);
    }
    return $value;
}

function executeInsertBatch(PDO $pdo, array &$values): void
{
    if ($values === []) {
        return;
    }
    $pdo->exec('INSERT INTO `sm_area_code` VALUES ' . implode(',', $values));
    $values = [];
}

$archivePath = getenv('AREA_CODE_SQL_ARCHIVE') ?: '/app/db/area_code.sql.gz';
$dbHost = requiredEnv('DB_HOST');
$dbName = requiredEnv('DB_NAME');
$dbUser = requiredEnv('DB_USER');
$dbPassword = requiredEnv('DB_PASSWORD');
$dbPort = getenv('DB_PORT') ?: '3306';

if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "[area-code] DB_NAME contains unsupported characters\n");
    exit(1);
}
if (!is_file($archivePath) || !is_readable($archivePath)) {
    fwrite(STDERR, "[area-code] SQL archive is missing: {$archivePath}\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);

$tableExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sm_area_code'"
)->fetchColumn();
$currentRows = $tableExists === 1 ? (int) $pdo->query('SELECT COUNT(*) FROM sm_area_code')->fetchColumn() : 0;
if ($currentRows === EXPECTED_AREA_CODE_ROWS) {
    echo '[area-code] sm_area_code already complete (' . EXPECTED_AREA_CODE_ROWS . " rows)\n";
    exit(0);
}

echo "[area-code] rebuilding sm_area_code (current={$currentRows} expected=" . EXPECTED_AREA_CODE_ROWS . ")\n";
$stream = gzopen($archivePath, 'rb');
if ($stream === false) {
    throw new RuntimeException("Unable to open SQL archive: {$archivePath}");
}

$pdo->exec('SET unique_checks=0');
$pdo->exec('SET foreign_key_checks=0');
try {
    $statement = '';
    $insertValues = [];
    while (($line = gzgets($stream)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        if (str_starts_with($trimmed, 'INSERT INTO `sm_area_code` VALUES ')) {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
            $value = substr($trimmed, strlen('INSERT INTO `sm_area_code` VALUES '));
            $insertValues[] = rtrim($value, ";\r\n");
            if (count($insertValues) >= INSERT_BATCH_SIZE) {
                executeInsertBatch($pdo, $insertValues);
            }
            continue;
        }
        executeInsertBatch($pdo, $insertValues);
        $statement .= ($statement === '' ? '' : "\n") . $trimmed;
        if (str_ends_with($trimmed, ';')) {
            $pdo->exec($statement);
            $statement = '';
        }
    }
    executeInsertBatch($pdo, $insertValues);
    if ($statement !== '') {
        throw new RuntimeException('SQL archive ended with an incomplete statement');
    }
    if (!gzeof($stream)) {
        throw new RuntimeException('Failed while reading SQL archive');
    }
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $throwable;
} finally {
    gzclose($stream);
    $pdo->exec('SET foreign_key_checks=1');
    $pdo->exec('SET unique_checks=1');
}

$importedRows = (int) $pdo->query('SELECT COUNT(*) FROM sm_area_code')->fetchColumn();
if ($importedRows !== EXPECTED_AREA_CODE_ROWS) {
    fwrite(STDERR, "[area-code] row verification failed (actual={$importedRows} expected=" . EXPECTED_AREA_CODE_ROWS . ")\n");
    exit(1);
}
echo "[area-code] sm_area_code initialized ({$importedRows} rows)\n";
