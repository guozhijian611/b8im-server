<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$suite = $argv[1] ?? 'all';
if (!in_array($suite, ['unit', 'integration', 'all'], true)) {
    fwrite(STDERR, "Usage: php scripts/test-runner.php [unit|integration|all]\n");
    exit(2);
}

$files = glob($root . '/tests/*Test.php') ?: [];
sort($files);
$selected = array_values(array_filter($files, static function (string $file) use ($suite): bool {
    $integration = str_contains(basename($file), 'IntegrationTest.php');
    return $suite === 'all' || ($suite === 'integration' ? $integration : !$integration);
}));

$suffix = substr(hash('sha256', $root . getmypid()), 0, 12);
$environment = [
    'AI_CRUD_TEST_DB_NAME' => "nb8im_{$suffix}_ai_crud_test",
    'MODULE_TEST_DB_NAME' => "nb8im_{$suffix}_module_test",
    'MODULE_ACL_TEST_DB_NAME' => "nb8im_{$suffix}_module_acl_test",
    'ROUTING_TEST_DB_NAME' => "nb8im_{$suffix}_routing_test",
    'TENANT_IM_POLICY_TEST_DB_NAME' => "nb8im_{$suffix}_im_policy_test",
    'IM_USER_MGMT_TEST_DB_NAME' => "nb8im_{$suffix}_im_user_mgmt_test",
    'WEB_IM_ACCESS_TEST_DB_NAME' => "nb8im_web_access_{$suffix}_test",
    'WEB_REGISTER_QR_TEST_DB_NAME' => "nb8im_{$suffix}_web_register_qr_test",
    'ANNOUNCEMENT_TEST_DB_NAME' => "nb8im_{$suffix}_announcement_test",
    'SEARCH_ACL_TEST_DB_NAME' => "nb8im_{$suffix}_search_acl_test",
    'WEB_IM_TEST_DB_NAME' => "nb8im_{$suffix}_web_test",
];
putenv('OTEL_SDK_DISABLED=true');
$_ENV['OTEL_SDK_DISABLED'] = 'true';
$_SERVER['OTEL_SDK_DISABLED'] = 'true';
foreach ($environment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

if ($suite !== 'unit') {
    if (is_file($root . '/.env')) {
        Dotenv\Dotenv::createImmutable($root)->safeLoad();
    }
    $env = static fn (string $key, string $default = ''): string => (string) (getenv($key) ?: ($_ENV[$key] ?? $default));
    $admin = new PDO(sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $env('DB_HOST', '127.0.0.1'),
        (int) $env('DB_PORT', '3306'),
        $env('DB_CHARSET', 'utf8mb4'),
    ), $env('DB_USER', 'root'), $env('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    register_shutdown_function(static function () use ($admin, $environment): void {
        foreach (array_unique(array_values($environment)) as $database) {
            if (preg_match('/^nb8im_[a-z0-9_]+_test$/', $database) !== 1 || $database === 'nb8im') {
                fwrite(STDERR, "Refusing unsafe automatic test cleanup: {$database}\n");
                continue;
            }
            try {
                $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
            } catch (Throwable $throwable) {
                fwrite(STDERR, "Automatic test cleanup failed for {$database}: {$throwable->getMessage()}\n");
            }
        }
    });
    $snapshot = file_get_contents($root . '/db/saimulti.sql');
    if (!is_string($snapshot) || $snapshot === '') {
        throw new RuntimeException('测试数据库快照为空');
    }
    foreach (['MODULE_TEST_DB_NAME', 'MODULE_ACL_TEST_DB_NAME', 'ROUTING_TEST_DB_NAME', 'TENANT_IM_POLICY_TEST_DB_NAME', 'IM_USER_MGMT_TEST_DB_NAME', 'WEB_REGISTER_QR_TEST_DB_NAME'] as $key) {
        $database = $environment[$key];
        $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
        $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $pdo = new PDO(sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $env('DB_HOST', '127.0.0.1'),
            (int) $env('DB_PORT', '3306'),
            $database,
            $env('DB_CHARSET', 'utf8mb4'),
        ), $env('DB_USER', 'root'), $env('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);
        $pdo->exec($snapshot);
        foreach (['DB_NAME', 'PHINX_DB_NAME'] as $dbKey) {
            putenv("{$dbKey}={$database}");
            $_ENV[$dbKey] = $database;
            $_SERVER[$dbKey] = $database;
        }
        $configPath = $root . '/phinx.php';
        $input = new Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(false);
        (new Phinx\Migration\Manager(
            new Phinx\Config\Config(require $configPath, $configPath),
            $input,
            new Symfony\Component\Console\Output\BufferedOutput(),
        ))->migrate('default');
    }
}

$failures = [];
$databaseByTest = [
    'AiCrudCodegenIntegrationTest.php' => 'AI_CRUD_TEST_DB_NAME',
    'AnnouncementIntegrationTest.php' => 'ANNOUNCEMENT_TEST_DB_NAME',
    'ModuleLifecycleIntegrationTest.php' => 'MODULE_TEST_DB_NAME',
    'ModuleTenantAclIntegrationTest.php' => 'MODULE_ACL_TEST_DB_NAME',
    'SearchMessageAclIntegrationTest.php' => 'SEARCH_ACL_TEST_DB_NAME',
    'RoutingConfigIntegrationTest.php' => 'ROUTING_TEST_DB_NAME',
    'TraceCenterMigrationIntegrationTest.php' => 'ROUTING_TEST_DB_NAME',
    'TenantImPolicyIntegrationTest.php' => 'TENANT_IM_POLICY_TEST_DB_NAME',
    'ImUserManagementIntegrationTest.php' => 'IM_USER_MGMT_TEST_DB_NAME',
    'WebImAccessSessionIntegrationTest.php' => 'WEB_IM_ACCESS_TEST_DB_NAME',
    'WebImControlPlaneIntegrationTest.php' => 'WEB_IM_TEST_DB_NAME',
    'WebRegistrationQrLoginIntegrationTest.php' => 'WEB_REGISTER_QR_TEST_DB_NAME',
];
foreach ($selected as $file) {
    $basename = basename($file);
    if (isset($databaseByTest[$basename])) {
        $database = $environment[$databaseByTest[$basename]];
        foreach (['DB_NAME', 'PHINX_DB_NAME'] as $key) {
            putenv("{$key}={$database}");
            $_ENV[$key] = $database;
            $_SERVER[$key] = $database;
        }
    }
    fwrite(STDOUT, sprintf("\n[%s] %s\n", $suite, $basename));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($command, $status);
    if ($status !== 0) {
        $failures[] = $basename;
    }
}

if ($suite !== 'unit') {
    $databases = array_values(array_unique(array_values($environment)));
    $cleanup = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/test-cleanup.php');
    foreach ($databases as $database) {
        $cleanup .= ' ' . escapeshellarg($database);
    }
    passthru($cleanup, $cleanupStatus);
    if ($cleanupStatus !== 0) {
        $failures[] = 'test-cleanup';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\nFailed tests: " . implode(', ', $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("\n%s tests passed: %d files\n", ucfirst($suite), count($selected)));
