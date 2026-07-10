<?php

declare(strict_types=1);

$databaseOverride = getenv('TENANT_IM_POLICY_TEST_DB_NAME');
if (!is_string($databaseOverride) || !str_ends_with($databaseOverride, '_im_policy_test')) {
    throw new RuntimeException('TENANT_IM_POLICY_TEST_DB_NAME 只允许使用 *_im_policy_test 临时库。');
}
$_ENV['DB_NAME'] = $databaseOverride;
$_SERVER['DB_NAME'] = $databaseOverride;
putenv('DB_NAME=' . $databaseOverride);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

$_ENV['DB_NAME'] = $databaseOverride;
$_SERVER['DB_NAME'] = $databaseOverride;
putenv('DB_NAME=' . $databaseOverride);

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use plugin\saimulti\app\logic\system\SystemOrganizationLogic;
use plugin\saimulti\service\tenantPolicy\TenantImPolicyPublisherInterface;
use plugin\saimulti\service\tenantPolicy\TenantImPolicyService;
use plugin\saimulti\service\tenantPolicy\ThinkOrmTenantImPolicyStore;
use support\think\Db;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$thinkOrmConfig = config('think-orm');
$connectionName = (string) ($thinkOrmConfig['default'] ?? 'mysql');
$thinkOrmConfig['connections'][$connectionName]['database'] = $databaseOverride;
Db::setConfig($thinkOrmConfig);
$connectionConfig = $thinkOrmConfig['connections'][$connectionName];
$pdo = new PDO(sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $connectionConfig['hostname'],
    (int) $connectionConfig['hostport'],
    $databaseOverride,
    $connectionConfig['charset'],
), (string) $connectionConfig['username'], (string) $connectionConfig['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdoDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$thinkDatabase = (string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '');
if ($pdoDatabase !== $databaseOverride || $thinkDatabase !== $databaseOverride) {
    throw new RuntimeException(sprintf(
        '策略集成测试库隔离失败: expected=%s pdo=%s thinkorm=%s',
        $databaseOverride,
        $pdoDatabase,
        $thinkDatabase,
    ));
}

if (getenv('TENANT_IM_POLICY_TEST_MIGRATE') === '1') {
    $configPath = dirname(__DIR__) . '/phinx.php';
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    (new Manager(
        new Config(require $configPath, $configPath),
        $input,
        new BufferedOutput(),
    ))->migrate('default');
}

final class IntegrationPolicyPublisher implements TenantImPolicyPublisherInterface
{
    /** @var list<array{organization: int, version: int}> */
    public array $events = [];

    public function invalidateAndPublish(int $organization, int $version, array $actor): void
    {
        $this->events[] = compact('organization', 'version');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$backfilled = Db::table('sm_tenant_im_policy')->where('organization', 1)->find();
$assert(is_array($backfilled), '迁移未为已有机构回填默认策略');
$assert((int) $backfilled['version'] === 1 && $backfilled['status'] === 'ENABLED', '回填策略并非 canonical 状态');

$suffix = bin2hex(random_bytes(5));
$logic = new SystemOrganizationLogic();
$created = $logic->add([
    'group_id' => 1,
    'domain' => 'policy-' . $suffix . '.example.invalid',
    'enterprise_code' => 'policy_' . $suffix,
    'deployment_id' => 'policy-test',
    'title' => '策略测试机构',
    'organization_name' => '策略测试机构',
    'api_server_url' => 'https://api.example.invalid',
    'im_server_url' => 'wss://im.example.invalid',
    'upload_server_url' => 'https://upload.example.invalid',
    'web_server_url' => 'https://web.example.invalid',
    'status' => 1,
]);
$assert($created, '新机构创建失败');
$organization = (int) Db::table('sm_system_organization')->where('enterprise_code', 'policy_' . $suffix)->value('id');
$assert($organization > 1, '新机构 organization 无效');
$assert(
    Db::table('sm_tenant_im_policy')->where('organization', $organization)->count() === 1,
    '新机构未在同一事务初始化默认 IM 策略',
);

$publisher = new IntegrationPolicyPublisher();
$service = new TenantImPolicyService(new ThinkOrmTenantImPolicyStore(), $publisher);
$updated = $service->update($organization, [
    'version' => 1,
    'allowed_client_families' => ['web'],
    'max_message_qps' => 7,
], ['type' => 'integration', 'id' => 1]);
$assert($updated['version'] === 2 && $updated['allowed_client_families'] === ['web'], '真实库策略更新失败');
$stored = Db::table('sm_tenant_im_policy')->where('organization', $organization)->find();
$assert((int) $stored['max_message_qps'] === 7 && (int) $stored['version'] === 2, '真实库策略未持久化');
$assert($publisher->events === [['organization' => $organization, 'version' => 2]], '提交后变更事件缺失');

fwrite(STDOUT, sprintf("Tenant IM policy integration (%s): %d assertions passed.\n", $databaseOverride, $assertions));
