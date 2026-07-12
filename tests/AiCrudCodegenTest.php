<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

use app\command\AiCrudCommand;
use app\command\AiCrudRollbackCommand;
use app\command\support\Manifest;
use app\command\support\RouteWriter;
use app\command\support\SchemaBuilder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$builder = new SchemaBuilder();
$sql = $builder->buildSql('sm_tenant_codegen_test', '代码生成测试', [[
    'name' => 'title', 'type' => 'varchar', 'length' => 120, 'comment' => '标题', 'null' => false,
]], 'tenant');
$assert(str_contains($sql, '`organization` int(11) UNSIGNED'), 'tenant schema 未生成 organization');
$assert(str_contains($sql, 'INDEX `idx_organization`'), 'tenant schema 未生成 organization 索引');

foreach (['bad-name', 'sm_test`; DROP DATABASE nb8im; --'] as $unsafeTable) {
    try {
        $builder->buildSql($unsafeTable, '非法', [['name' => 'title', 'type' => 'varchar', 'comment' => '标题']], 'tenant');
        throw new RuntimeException('非法表名未被拒绝');
    } catch (InvalidArgumentException) {
        $assert(true, '非法表名已拒绝');
    }
}

$assert(AiCrudCommand::frontendPath('tenant') === 'b8im-tenant-vue', '租户端拆仓路径错误');
$assert(AiCrudCommand::frontendPath('admin') === 'b8im-admin-vue', '管理端拆仓路径错误');
$expected = AiCrudCommand::resolveExpectedGeneratedFiles([
    'namespace' => 'codegentest', 'package_name' => '', 'class_name' => 'CodegenProbe',
    'business_name' => 'probe', 'template' => 'app', 'stub' => 'tenant',
    'generate_path' => 'b8im-tenant-vue',
]);
$assert(count($expected) === 9, '生成文件集合数量错误');
$assert(str_contains(implode("\n", $expected), '/b8im-tenant-vue/src/views/codegentest/'), '生成文件未指向租户端兄弟仓库');

try {
    AiCrudCommand::resolveExpectedGeneratedFiles([
        'namespace' => 'codegentest', 'package_name' => '', 'class_name' => 'CodegenProbe',
        'business_name' => 'probe', 'template' => 'app', 'stub' => 'tenant', 'generate_path' => 'tenant-vue',
    ]);
    throw new RuntimeException('旧 generate_path 未被拒绝');
} catch (RuntimeException) {
    $assert(true, '旧 generate_path 已拒绝');
}

try {
    AiCrudCommand::resolveExpectedGeneratedFiles([
        'namespace' => '../../tmp', 'package_name' => '', 'class_name' => 'CodegenProbe',
        'business_name' => 'probe', 'template' => 'app', 'stub' => 'tenant',
        'generate_path' => 'b8im-tenant-vue',
    ]);
    throw new RuntimeException('路径穿越 namespace 未被拒绝');
} catch (RuntimeException) {
    $assert(true, '路径穿越 namespace 已拒绝');
}

$routeFile = tempnam(sys_get_temp_dir(), 'b8im-route-');
file_put_contents($routeFile, "<?php\nRoute::group('/saimulti', function () {\n})->middleware([\n    CheckTenantLogin::class,\n]);\n");
$writer = new RouteWriter($routeFile);
$insert = $writer->insert('/tenant/probe', 'app\\codegentest\\controller\\CodegenProbeController', 'tenant');
$assert($insert['ok'] && $writer->has('/tenant/probe'), '路由安全插入失败');
$assert($writer->remove('/tenant/probe')['ok'] && !$writer->has('/tenant/probe'), '路由安全移除失败');
@unlink($routeFile);
@unlink($routeFile . '.bak');

$outsideManifest = tempnam(sys_get_temp_dir(), 'b8im-manifest-');
file_put_contents($outsideManifest, json_encode(['table_name' => 'sm_tenant_probe']));
$assert(Manifest::read($outsideManifest) === null, '允许读取 runtime 清单目录外的文件');
@unlink($outsideManifest);

$innocent = tempnam(sys_get_temp_dir(), 'b8im-innocent-');
$rollback = new AiCrudRollbackCommand();
$removeFiles = new ReflectionMethod($rollback, 'removeFiles');
$removeFiles->setAccessible(true);
try {
    $removeFiles->invoke($rollback, [
        'files' => [$innocent], 'namespace' => 'codegentest', 'package_name' => '',
        'class_name' => 'CodegenProbe', 'business_name' => 'probe', 'template' => 'app', 'stub' => 'tenant',
    ], new BufferedOutput());
    throw new RuntimeException('伪造清单路径未被拒绝');
} catch (ReflectionException $exception) {
    throw $exception;
} catch (Throwable $exception) {
    $assert(str_contains($exception->getMessage(), '拒绝删除'), '伪造清单抛出了非预期异常');
}
$assert(is_file($innocent), 'rollback 删除了清单之外的文件');
@unlink($innocent);

$schemaPath = runtime_path() . '/ai-crud/codegen-dry-run.json';
if (!is_dir(dirname($schemaPath))) {
    mkdir(dirname($schemaPath), 0770, true);
}
$schema = [
    'table' => 'sm_tenant_codegen_dry_test', 'table_comment' => '生成器预览测试',
    'stub' => 'tenant', 'template' => 'app', 'namespace' => 'codegentest',
    'business_name' => 'dry_probe', 'class_name' => 'DryProbe',
    'columns' => [['name' => 'title', 'type' => 'varchar', 'comment' => '标题']],
];
file_put_contents($schemaPath, json_encode($schema, JSON_UNESCAPED_UNICODE));
$application = new Application();
$application->setAutoExit(false);
$application->add(new AiCrudCommand());
$output = new BufferedOutput();
$status = $application->run(new ArrayInput([
    'command' => 'ai-crud:make', '--schema' => $schemaPath, '--dry-run' => true,
]), $output);
@unlink($schemaPath);
$dryOutput = $output->fetch();
$assert($status === 0, 'dry-run 执行失败：' . $dryOutput);
$assert(str_contains($dryOutput, '[dry-run]') && str_contains($dryOutput, '`organization`'), 'dry-run 未输出租户 SQL 预览');

fwrite(STDOUT, "AiCrudCodegenTest: {$assertions} assertions passed\n");
