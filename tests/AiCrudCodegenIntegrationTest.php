<?php

declare(strict_types=1);

$database = trim((string) (getenv('AI_CRUD_TEST_DB_NAME') ?: 'nb8im_ai_crud_test'));
if (preg_match('/^nb8im_[a-z0-9_]+_test$/', $database) !== 1 || $database === 'nb8im') {
    throw new RuntimeException('AiCrudCodegenIntegrationTest 只允许使用安全的 nb8im_*_test 临时库');
}
foreach (['DB_NAME' => $database, 'PHINX_DB_NAME' => $database] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

use app\command\AiCrudCommand;
use app\command\AiCrudRollbackCommand;
use app\command\support\RouteWriter;
use support\think\Db;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

foreach (['DB_NAME' => $database, 'PHINX_DB_NAME' => $database] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$config = config('think-orm');
$connectionName = (string) ($config['default'] ?? 'mysql');
$connection = $config['connections'][$connectionName];
$admin = new PDO(sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    $connection['hostname'],
    (int) $connection['hostport'],
    $connection['charset'],
), (string) $connection['username'], (string) $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$quoted = '`' . $database . '`';
$schemaPath = runtime_path() . '/ai-crud/ai-crud-integration.json';
$routePrefix = '/tenant/ai_crud_probe';
$table = 'sm_tenant_ai_crud_probe_test';
$tableShape = [
    'namespace' => 'codegentest', 'package_name' => '', 'class_name' => 'AiCrudProbe',
    'business_name' => 'ai_crud_probe', 'template' => 'app', 'stub' => 'tenant',
    'generate_path' => 'b8im-tenant-vue',
];
$expectedFiles = AiCrudCommand::resolveExpectedGeneratedFiles($tableShape);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

try {
    $admin->exec('DROP DATABASE IF EXISTS ' . $quoted);
    $admin->exec('CREATE DATABASE ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $pdo = new PDO(sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $connection['hostname'],
        (int) $connection['hostport'],
        $database,
        $connection['charset'],
    ), (string) $connection['username'], (string) $connection['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $snapshot = file_get_contents(dirname(__DIR__) . '/db/saimulti.sql');
    if (!is_string($snapshot) || $snapshot === '') {
        throw new RuntimeException('数据库快照为空');
    }
    $pdo->exec($snapshot);

    $config['connections'][$connectionName]['database'] = $database;
    Db::setConfig($config);
    $actual = (string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '');
    $assert($actual === $database, "ThinkORM 未锁定隔离测试库：{$actual}");

    if (!is_dir(dirname($schemaPath))) {
        mkdir(dirname($schemaPath), 0770, true);
    }
    file_put_contents($schemaPath, json_encode([
        'table' => $table, 'table_comment' => 'AI CRUD 全链测试',
        'stub' => 'tenant', 'template' => 'app', 'namespace' => 'codegentest',
        'business_name' => 'ai_crud_probe', 'class_name' => 'AiCrudProbe',
        'menu_name' => 'AI CRUD 测试', 'belong_menu_id' => 4000,
        'route_prefix' => $routePrefix,
        'columns' => [
            ['name' => 'title', 'type' => 'varchar', 'length' => 120, 'comment' => '标题', 'null' => false, 'is_required' => 2, 'is_list' => 2],
            ['name' => 'status', 'type' => 'tinyint', 'comment' => '状态', 'default' => 1, 'view_type' => 'radio', 'dict_type' => 'data_status'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $application = new Application();
    $application->setAutoExit(false);
    $application->add(new AiCrudCommand());
    $application->add(new AiCrudRollbackCommand());

    $makeOutput = new BufferedOutput();
    $makeStatus = $application->run(new ArrayInput([
        'command' => 'ai-crud:make', '--schema' => $schemaPath, '--force' => true,
    ]), $makeOutput);
    $makeText = $makeOutput->fetch();
    $assert($makeStatus === 0, 'make 全链失败：' . $makeText);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() === 0, '生成表不可查询');
    $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $assert(in_array('organization', $columns, true), '租户生成表缺少 organization');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM saimulti_table WHERE table_name = '{$table}' AND generate_path = 'b8im-tenant-vue'")->fetchColumn() === 1, '登记记录未使用拆仓路径');
    foreach ($expectedFiles as $file) {
        $assert(is_file($file), '生成文件缺失：' . $file);
    }
    $model = file_get_contents($expectedFiles[2]);
    $assert(is_string($model) && str_contains($model, 'TenantModel'), '租户模型未继承 TenantModel');
    $assert((new RouteWriter())->has($routePrefix), '生成路由未写入真实 route.php');

    $duplicateOutput = new BufferedOutput();
    $duplicateStatus = $application->run(new ArrayInput([
        'command' => 'ai-crud:make', '--schema' => $schemaPath, '--force' => true,
    ]), $duplicateOutput);
    $assert($duplicateStatus !== 0, '重复生成未拒绝冲突');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM saimulti_table WHERE table_name = '{$table}'")->fetchColumn() === 1, '重复生成破坏既有登记');
    $assert(is_file($expectedFiles[0]) && (new RouteWriter())->has($routePrefix), '重复生成破坏既有文件或路由');

    $rollbackOutput = new BufferedOutput();
    $rollbackStatus = $application->run(new ArrayInput([
        'command' => 'ai-crud:rollback', '--table' => $table, '--drop-table' => true, '--force' => true,
    ]), $rollbackOutput);
    $rollbackText = $rollbackOutput->fetch();
    $assert($rollbackStatus === 0, 'rollback 全链失败：' . $rollbackText);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$database}' AND table_name = '{$table}'")->fetchColumn() === 0, 'rollback 未删除测试表');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM saimulti_table WHERE table_name = '{$table}'")->fetchColumn() === 0, 'rollback 未删除登记记录');
    $assert(!(new RouteWriter())->has($routePrefix), 'rollback 未删除路由');
    foreach ($expectedFiles as $file) {
        $assert(!is_file($file), 'rollback 未删除生成文件：' . $file);
    }

    if (!is_dir(dirname($expectedFiles[0]))) {
        mkdir(dirname($expectedFiles[0]), 0770, true);
    }
    file_put_contents($expectedFiles[0], 'owned-by-existing-code');
    $fileConflictOutput = new BufferedOutput();
    $fileConflictStatus = $application->run(new ArrayInput([
        'command' => 'ai-crud:make', '--schema' => $schemaPath, '--force' => true,
    ]), $fileConflictOutput);
    $assert($fileConflictStatus !== 0, '已有目标文件时未拒绝生成');
    $assert(file_get_contents($expectedFiles[0]) === 'owned-by-existing-code', '冲突补偿误删或覆盖旧文件');
    unlink($expectedFiles[0]);

    $routeWriter = new RouteWriter();
    $routeWriter->insert($routePrefix, 'app\\codegentest\\controller\\AiCrudProbeController', 'tenant');
    $routeConflictOutput = new BufferedOutput();
    $routeConflictStatus = $application->run(new ArrayInput([
        'command' => 'ai-crud:make', '--schema' => $schemaPath, '--force' => true,
    ]), $routeConflictOutput);
    $assert($routeConflictStatus !== 0, '已有目标路由时未拒绝生成');
    $assert($routeWriter->has($routePrefix), '冲突补偿误删旧路由');
    $routeWriter->remove($routePrefix);

    $registrationCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM saimulti_table')->fetchColumn();
    $columnCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM saimulti_column')->fetchColumn();
    putenv('AI_CRUD_FAULT_AT=load_after_registration');
    $loadFaultOutput = new BufferedOutput();
    $loadFaultStatus = $application->run(new ArrayInput([
        'command' => 'ai-crud:make', '--schema' => $schemaPath, '--force' => true,
    ]), $loadFaultOutput);
    putenv('AI_CRUD_FAULT_AT');
    $assert($loadFaultStatus !== 0, 'load 登记后故障注入未触发失败');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM saimulti_table')->fetchColumn() === $registrationCountBefore, 'load 事务回滚后残留登记');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM saimulti_column')->fetchColumn() === $columnCountBefore, 'load 事务回滚后残留字段');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$database}' AND table_name = '{$table}'")->fetchColumn() === 0, 'load 故障补偿后残留数据表');

    putenv('AI_CRUD_FAULT_AT=route');
    $faultOutput = new BufferedOutput();
    $faultStatus = $application->run(new ArrayInput([
        'command' => 'ai-crud:make', '--schema' => $schemaPath, '--force' => true,
    ]), $faultOutput);
    putenv('AI_CRUD_FAULT_AT');
    $assert($faultStatus !== 0, 'route 故障注入未触发失败');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$database}' AND table_name = '{$table}'")->fetchColumn() === 0, '故障补偿残留数据表');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM saimulti_table WHERE table_name = '{$table}'")->fetchColumn() === 0, '故障补偿残留登记');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM sm_tenant_menu WHERE code = 'codegentest/ai_crud_probe'")->fetchColumn() === 0, '故障补偿残留菜单');
    $assert(!$routeWriter->has($routePrefix), '故障补偿残留路由');
    foreach ($expectedFiles as $file) {
        $assert(!is_file($file), '故障补偿残留生成文件：' . $file);
    }
    $manifests = glob(runtime_path() . '/ai-crud/manifest/' . $table . '_*.json') ?: [];
    usort($manifests, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
    $faultManifest = json_decode((string) file_get_contents($manifests[0]), true);
    $assert(($faultManifest['status'] ?? '') === 'compensated', '故障清单未记录 compensated 状态');
} finally {
    putenv('AI_CRUD_FAULT_AT');
    @unlink($schemaPath);
    (new RouteWriter())->remove($routePrefix);
    foreach ($expectedFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    $testDirectories = array_unique(array_map('dirname', $expectedFiles));
    usort($testDirectories, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
    foreach ($testDirectories as $directory) {
        while (str_contains($directory, '/codegentest') && is_dir($directory) && count(scandir($directory)) === 2) {
            @rmdir($directory);
            $directory = dirname($directory);
        }
    }
    $admin->exec('DROP DATABASE IF EXISTS ' . $quoted);
}

fwrite(STDOUT, "AiCrudCodegenIntegrationTest: {$assertions} assertions passed\n");
