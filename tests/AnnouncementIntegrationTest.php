<?php

declare(strict_types=1);

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\AnnouncementService;
use plugin\saimulti\service\module\DistributedLockInterface;
use plugin\saimulti\service\module\ManifestCatalog;
use plugin\saimulti\service\module\ModuleAccessCacheInterface;
use plugin\saimulti\service\module\ModuleAccessService;
use plugin\saimulti\service\module\ModuleAuditWriter;
use plugin\saimulti\service\module\ModuleConfigValidator;
use plugin\saimulti\service\module\ModuleDependencyGuard;
use plugin\saimulti\service\module\ModuleLifecycleHookRunner;
use plugin\saimulti\service\module\ModuleManager;
use plugin\saimulti\service\module\ModuleMenuRegistrar;
use plugin\saimulti\service\module\ModuleMigrationRunner;
use plugin\saimulti\service\module\ThinkOrmModuleAccessStore;
use support\think\Db;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$database = trim((string) (getenv('ANNOUNCEMENT_TEST_DB_NAME') ?: 'nb8im_announcement_test'));
if (preg_match('/^[A-Za-z0-9_]+_announcement_test$/', $database) !== 1) {
    throw new RuntimeException('AnnouncementIntegrationTest 只允许使用 *_announcement_test 临时库。');
}

foreach (['DB_NAME' => $database, 'PHINX_DB_NAME' => $database] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

// webman bootstrap 会加载本机 .env，bootstrap 后必须再次锁定临时库。
foreach (['DB_NAME' => $database, 'PHINX_DB_NAME' => $database] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

$thinkOrmConfig = config('think-orm');
$connectionName = (string) ($thinkOrmConfig['default'] ?? 'mysql');
if (!isset($thinkOrmConfig['connections'][$connectionName])) {
    throw new RuntimeException('ThinkORM 默认连接不存在。');
}
$connection = $thinkOrmConfig['connections'][$connectionName];
$host = (string) $connection['hostname'];
$port = (int) $connection['hostport'];
$charset = (string) $connection['charset'];
$username = (string) $connection['username'];
$password = (string) $connection['password'];
$adminPdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$quotedDatabase = '`' . $database . '`';
$pdo = null;

final class AnnouncementIntegrationCache implements ModuleAccessCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $values = [];

    public function get(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, array $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}

final class AnnouncementIntegrationLock implements DistributedLockInterface
{
    /** @var array<string, string> */
    private array $locks = [];

    public function acquire(string $key, string $token, int $ttlSeconds): bool
    {
        if (isset($this->locks[$key])) {
            return false;
        }
        $this->locks[$key] = $token;

        return true;
    }

    public function release(string $key, string $token): void
    {
        if (($this->locks[$key] ?? null) === $token) {
            unset($this->locks[$key]);
        }
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$expectApiCode = static function (int $code, callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, $message . ' code 不正确。');
        return;
    }

    throw new RuntimeException($message . ' 未抛出预期异常。');
};

try {
    $adminPdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    $adminPdo->exec(
        'CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    );
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ],
    );

    $snapshotPath = dirname(__DIR__) . '/db/saimulti.sql';
    $snapshot = file_get_contents($snapshotPath);
    if (!is_string($snapshot) || trim($snapshot) === '') {
        throw new RuntimeException('Saimulti 数据库快照不存在或为空。');
    }
    $pdo->exec($snapshot);

    $thinkOrmConfig['connections'][$connectionName]['database'] = $database;
    Db::setConfig($thinkOrmConfig);

    $pdoDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $thinkOrmDatabase = (string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '');
    $assert(
        $pdoDatabase === $database
        && $thinkOrmDatabase === $database
        && str_ends_with($pdoDatabase, '_announcement_test')
        && str_ends_with($thinkOrmDatabase, '_announcement_test'),
        sprintf(
            '测试库隔离断言失败: expected=%s, pdo=%s, thinkorm=%s',
            $database,
            $pdoDatabase,
            $thinkOrmDatabase,
        ),
    );

    $configPath = dirname(__DIR__) . '/phinx.php';
    $configValues = require $configPath;
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    (new Manager(new Config($configValues, $configPath), $input, new BufferedOutput()))->migrate('default');
    $assert(
        (int) Db::table('phinxlog')->where('version', 20260710010000)->count() === 1,
        'Server 模块生命周期迁移未在临时库执行。',
    );

    $now = date('Y-m-d H:i:s');
    Db::table('sm_system_organization')->insert([
        'id' => 2,
        'group_id' => 1,
        'domain' => 'org-two.announcement.test',
        'enterprise_code' => 'announcement_org_2',
        'deployment_id' => 'b8im-announcement-test',
        'config_version' => 1,
        'title' => '公告隔离机构',
        'organization_name' => '公告隔离机构',
        'status' => 1,
        'is_init' => 1,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $passwordHash = password_hash('announcement-test', PASSWORD_BCRYPT);
    $orgOneUserId = (int) Db::table('sm_tenant_user')->insertGetId([
        'organization' => 1,
        'username' => 'ann_org1',
        'password' => $passwordHash,
        'user_type' => '200',
        'nickname' => '公告用户一',
        'status' => 1,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $orgTwoUserId = (int) Db::table('sm_tenant_user')->insertGetId([
        'organization' => 2,
        'username' => 'ann_org2',
        'password' => $passwordHash,
        'user_type' => '200',
        'nickname' => '公告用户二',
        'status' => 1,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $orgOneIdentity = ['organization' => 1, 'user_id' => 'announcement-user-' . $orgOneUserId];
    $orgTwoIdentity = ['organization' => 2, 'user_id' => 'announcement-user-' . $orgTwoUserId];
    $assert(
        (int) Db::table('sm_tenant_user')->where('organization', 1)->where('id', $orgOneUserId)->count() === 1
        && (int) Db::table('sm_tenant_user')->where('organization', 2)->where('id', $orgTwoUserId)->count() === 1,
        '两个机构的认证用户 fixture 未建立。',
    );

    $access = new ModuleAccessService(
        new ThinkOrmModuleAccessStore(),
        new AnnouncementIntegrationCache(),
    );
    $manager = new ModuleManager(
        new ManifestCatalog(),
        new ModuleMigrationRunner(),
        new ModuleLifecycleHookRunner(),
        new ModuleMenuRegistrar(),
        new ModuleDependencyGuard(),
        $access,
        new ModuleAuditWriter(),
        new ModuleConfigValidator(),
        new AnnouncementIntegrationLock(),
    );
    $adminActor = ['type' => 'admin', 'id' => 1, 'ip' => '127.0.0.1'];
    $orgOneActor = ['type' => 'tenant', 'id' => $orgOneUserId, 'ip' => '127.0.0.1'];
    $orgTwoActor = ['type' => 'tenant', 'id' => $orgTwoUserId, 'ip' => '127.0.0.1'];

    $manager->discover('announcement', $adminActor);
    $installed = $manager->install('announcement', $adminActor);
    $assert($installed['system']['status'] === 'INSTALLED', '公告模块未安装。');
    $manager->enableSystem('announcement', $adminActor);
    foreach ([[1, $orgOneActor], [2, $orgTwoActor]] as [$organization, $tenantActor]) {
        $manager->grantLicense($organization, 'announcement', null, '公告隔离集成验收', $adminActor);
        $manager->enableTenant($organization, 'announcement', $tenantActor);
    }
    $orgOneConfig = $manager->updateTenantConfig(1, 'announcement', [
        'display_mode' => 'both',
        'require_read_ack' => true,
    ], $orgOneActor);
    $orgTwoConfig = $manager->updateTenantConfig(2, 'announcement', [
        'display_mode' => 'popup',
        'require_read_ack' => false,
    ], $orgTwoActor);
    $assert(
        $orgOneConfig['values'] === ['display_mode' => 'both', 'require_read_ack' => true]
        && $orgTwoConfig['values'] === ['display_mode' => 'popup', 'require_read_ack' => false],
        '租户 display_mode/require_read_ack 模块配置未按 organization 持久化。',
    );
    $assert(
        $access->isAvailable(1, 'announcement', 'server', 'announcement.web.read')
        && $access->isAvailable(2, 'announcement', 'server', 'announcement.web.read'),
        '公告模块完整授权链路未放行两个机构。',
    );

    $service = new AnnouncementService();
    $payload = static fn (string $title, int $status = AnnouncementService::STATUS_PUBLISHED): array => [
        'title' => $title,
        'summary' => $title . '摘要',
        'content' => $title . '正文',
        'display_mode' => 'list',
        'priority' => 10,
        'status' => $status,
        'start_time' => null,
        'end_time' => null,
    ];

    $platformAnnouncement = $service->create(0, $payload('平台公告'), 1);
    $orgOneDraft = $service->create(
        1,
        array_replace($payload('机构一公告', AnnouncementService::STATUS_DRAFT), ['display_mode' => 'popup']),
        $orgOneUserId,
    );
    $assert($orgOneDraft['published_at'] === null, '草稿公告被提前写入 published_at。');
    $orgOneUpdated = $service->update(1, (int) $orgOneDraft['id'], [
        'summary' => '机构一公告已编辑摘要',
        'content' => '机构一公告已编辑正文',
    ], $orgOneUserId);
    $assert(
        $orgOneUpdated['summary'] === '机构一公告已编辑摘要'
        && (int) $orgOneUpdated['status'] === AnnouncementService::STATUS_DRAFT,
        '公告更新未保留草稿状态或内容。',
    );
    $orgOneAnnouncement = $service->update(
        1,
        (int) $orgOneDraft['id'],
        ['status' => AnnouncementService::STATUS_PUBLISHED],
        $orgOneUserId,
    );
    $assert(
        !empty($orgOneAnnouncement['published_at'])
        && (int) $orgOneAnnouncement['status'] === AnnouncementService::STATUS_PUBLISHED,
        '草稿发布未生成 published_at。',
    );
    $orgTwoAnnouncement = $service->create(2, $payload('机构二公告'), $orgTwoUserId);
    $draftAnnouncement = $service->create(
        1,
        $payload('不可见草稿', AnnouncementService::STATUS_DRAFT),
        $orgOneUserId,
    );
    $offlineAnnouncement = $service->create(1, $payload('不可见下线公告'), $orgOneUserId);
    $service->update(
        1,
        (int) $offlineAnnouncement['id'],
        ['status' => AnnouncementService::STATUS_OFFLINE],
        $orgOneUserId,
    );
    $clock = new DateTimeImmutable('now');
    $futureAnnouncement = $service->create(1, array_replace($payload('不可见未生效公告'), [
        'start_time' => $clock->modify('+1 day')->format('Y-m-d H:i:s'),
        'end_time' => $clock->modify('+2 days')->format('Y-m-d H:i:s'),
    ]), $orgOneUserId);
    $expiredAnnouncement = $service->create(1, array_replace($payload('不可见已过期公告'), [
        'start_time' => $clock->modify('-2 days')->format('Y-m-d H:i:s'),
        'end_time' => $clock->modify('-1 day')->format('Y-m-d H:i:s'),
    ]), $orgOneUserId);
    $deletedAnnouncement = $service->create(1, $payload('不可见已删除公告'), $orgOneUserId);
    $assert(
        $service->delete(1, [(int) $deletedAnnouncement['id']], $orgOneUserId) === 1
        && Db::table('sm_announcement')->where('id', $deletedAnnouncement['id'])->whereNotNull('delete_time')->count() === 1,
        '公告软删除未正确写入 delete_time。',
    );
    $expectApiCode(
        404,
        static fn () => $service->managementRead(1, (int) $orgTwoAnnouncement['id']),
        '机构一管理端读取机构二公告',
    );

    $orgOneList = $service->publishedList(
        $orgOneIdentity['organization'],
        $orgOneIdentity['user_id'],
        1,
        100,
    );
    $orgOneTitles = array_column($orgOneList['list'], 'title');
    $assert(
        in_array('平台公告', $orgOneTitles, true)
        && in_array('机构一公告', $orgOneTitles, true)
        && !in_array('机构二公告', $orgOneTitles, true),
        '平台/租户公告可见性未按 organization 隔离。',
    );
    foreach (['不可见草稿', '不可见下线公告', '不可见未生效公告', '不可见已过期公告', '不可见已删除公告'] as $invisibleTitle) {
        $assert(!in_array($invisibleTitle, $orgOneTitles, true), $invisibleTitle . ' 被发布列表错误暴露。');
    }
    $assert(
        $orgOneList['config'] === ['display_mode' => 'both', 'require_read_ack' => true]
        && array_reduce(
            $orgOneList['list'],
            static fn (bool $valid, array $item): bool => $valid
                && isset($item['display_mode'])
                && $item['is_read'] === false,
            true,
        ),
        '发布列表未返回 display_mode/config/is_read 固定契约。',
    );

    $orgTwoList = $service->publishedList(
        $orgTwoIdentity['organization'],
        $orgTwoIdentity['user_id'],
        1,
        100,
    );
    $orgTwoTitles = array_column($orgTwoList['list'], 'title');
    $assert(
        in_array('平台公告', $orgTwoTitles, true)
        && in_array('机构二公告', $orgTwoTitles, true)
        && !in_array('机构一公告', $orgTwoTitles, true)
        && $orgTwoList['config'] === ['display_mode' => 'popup', 'require_read_ack' => false],
        '机构二公告可见性或模块配置被串租。',
    );

    $orgOneRead = $service->publishedRead(
        $orgOneIdentity['organization'],
        $orgOneIdentity['user_id'],
        (int) $orgOneAnnouncement['id'],
    );
    $assert(
        $orgOneRead['display_mode'] === 'popup'
        && $orgOneRead['config'] === ['display_mode' => 'both', 'require_read_ack' => true]
        && $orgOneRead['is_read'] === false
        && $orgOneRead['read_ack_required'] === true,
        '发布详情未返回 display_mode/config/is_read 固定契约。',
    );
    $expectApiCode(
        404,
        static fn () => $service->publishedRead(
            $orgOneIdentity['organization'],
            $orgOneIdentity['user_id'],
            (int) $orgTwoAnnouncement['id'],
        ),
        '机构一读取机构二已发布公告',
    );
    $expectApiCode(
        404,
        static fn () => $service->acknowledge(
            $orgOneIdentity['organization'],
            $orgOneIdentity['user_id'],
            (int) $draftAnnouncement['id'],
        ),
        '用户确认不可见草稿',
    );
    $expectApiCode(
        404,
        static fn () => $service->acknowledge(
            $orgOneIdentity['organization'],
            $orgOneIdentity['user_id'],
            (int) $orgTwoAnnouncement['id'],
        ),
        '用户跨 organization 确认公告',
    );

    $firstAck = $service->acknowledge(
        $orgOneIdentity['organization'],
        $orgOneIdentity['user_id'],
        (int) $orgOneAnnouncement['id'],
    );
    $firstReadTime = (string) $firstAck['read_time'];
    $secondAck = $service->acknowledge(
        $orgOneIdentity['organization'],
        $orgOneIdentity['user_id'],
        (int) $orgOneAnnouncement['id'],
    );
    $assert(
        $firstAck['required'] === true
        && $firstAck['recorded'] === true
        && $secondAck['read_time'] === $firstReadTime
        && (int) Db::table('sm_announcement_read')
            ->where('organization', $orgOneIdentity['organization'])
            ->where('announcement_id', $orgOneAnnouncement['id'])
            ->where('user_id', $orgOneIdentity['user_id'])
            ->count() === 1,
        '认证 organization+user 的公告已读确认未幂等。',
    );
    $readAfterAck = $service->publishedRead(
        $orgOneIdentity['organization'],
        $orgOneIdentity['user_id'],
        (int) $orgOneAnnouncement['id'],
    );
    $assert($readAfterAck['is_read'] === true, '公告已读状态未投影到详情。');
    $orgTwoNoAck = $service->acknowledge(
        $orgTwoIdentity['organization'],
        $orgTwoIdentity['user_id'],
        (int) $orgTwoAnnouncement['id'],
    );
    $assert(
        $orgTwoNoAck === [
            'required' => false,
            'recorded' => false,
            'announcement_id' => (int) $orgTwoAnnouncement['id'],
            'read_time' => null,
        ]
        && (int) Db::table('sm_announcement_read')
            ->where('organization', $orgTwoIdentity['organization'])
            ->where('announcement_id', $orgTwoAnnouncement['id'])
            ->count() === 0,
        'require_read_ack=false 的机构仍写入了已读记录。',
    );

    $businessRowCount = (int) Db::table('sm_announcement')->count();
    $readRowCount = (int) Db::table('sm_announcement_read')->count();
    $manager->disableSystem('announcement', $adminActor);
    $expectApiCode(
        ModuleAccessService::ACCESS_DENIED,
        static fn () => $access->assertAvailable(1, 'announcement', 'server', 'announcement.web.read'),
        '系统禁用后的公告访问',
    );
    $uninstalled = $manager->uninstall('announcement', true, $adminActor);
    $assert($uninstalled['system']['status'] === 'UNINSTALLED', '公告模块未进入 UNINSTALLED。');
    $assert(
        (int) Db::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $database)
            ->whereIn('TABLE_NAME', ['sm_announcement', 'sm_announcement_read'])
            ->count() === 2
        && (int) Db::table('sm_announcement')->count() === $businessRowCount
        && (int) Db::table('sm_announcement_read')->count() === $readRowCount,
        'preserve_data=true 卸载未保留公告表或业务数据。',
    );
    $assert(
        (int) Db::table('sm_tenant_module_config')->where('module_key', 'announcement')->count() === 0
        && (int) Db::table('sm_tenant_module_license')->where('module_key', 'announcement')->count() === 0,
        '卸载后租户授权/配置控制数据未清理。',
    );
    $expectApiCode(
        ModuleAccessService::ACCESS_DENIED,
        static fn () => $access->assertAvailable(1, 'announcement', 'server', 'announcement.web.read'),
        '模块卸载后的公告访问',
    );

    $manager->discover('announcement', $adminActor);
    $manager->install('announcement', $adminActor);
    $manager->enableSystem('announcement', $adminActor);
    $manager->grantLicense(1, 'announcement', null, '卸载保留数据后重装', $adminActor);
    $manager->enableTenant(1, 'announcement', $orgOneActor);
    $manager->updateTenantConfig(1, 'announcement', [
        'display_mode' => 'both',
        'require_read_ack' => true,
    ], $orgOneActor);
    $assert(
        $access->isAvailable(1, 'announcement', 'server', 'announcement.web.read'),
        '公告模块重装后未恢复授权能力。',
    );
    $reinstalledTitles = array_column(
        $service->publishedList(
            $orgOneIdentity['organization'],
            $orgOneIdentity['user_id'],
            1,
            100,
        )['list'],
        'title',
    );
    $assert(
        in_array('平台公告', $reinstalledTitles, true)
        && in_array('机构一公告', $reinstalledTitles, true)
        && (int) Db::table('sm_announcement')->count() === $businessRowCount,
        '公告模块重装后未恢复保留的业务数据。',
    );

    echo sprintf("AnnouncementIntegrationTest: %d assertions passed on %s\n", $assertions, $database);
} finally {
    $pdo = null;
    $adminPdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    $remaining = (int) $adminPdo->query(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $adminPdo->quote($database),
    )->fetchColumn();
    if ($remaining !== 0) {
        throw new RuntimeException('公告集成测试临时库未删除。');
    }
}
