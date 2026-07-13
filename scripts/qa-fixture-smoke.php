<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/support/bootstrap.php';
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

use plugin\saimulti\service\qa\QaFixtureService;
use plugin\saimulti\service\OrganizationDiscovery;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\web\ThinkOrmWebImAuthStore;
use plugin\saimulti\service\web\WebImPolicyGuard;
use support\think\Db;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$database = (string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '');
$assert($database === 'nb8im', "QA fixture smoke test only accepts nb8im; got {$database}");
$service = new QaFixtureService();
$ordinaryOrganization = Db::table('sm_system_organization')->where('id', 1)->find();
$assert(is_array($ordinaryOrganization), 'Ordinary organization sentinel is missing before the fixture test.');
$first = $service->provision();
$firstLicenseVersion = (int) Db::table('sm_tenant_module_license')
    ->where('organization', (int) $first['organizations'][QaFixtureService::PRIMARY_CODE]['id'])
    ->where('module_key', 'announcement')
    ->value('version');
$second = $service->provision();
$secondLicenseVersion = (int) Db::table('sm_tenant_module_license')
    ->where('organization', (int) $second['organizations'][QaFixtureService::PRIMARY_CODE]['id'])
    ->where('module_key', 'announcement')
    ->value('version');
$assert($first['organizations'] === $second['organizations'], 'Idempotent provision changed organization identities.');
$assert($firstLicenseVersion === $secondLicenseVersion, 'Idempotent provision changed announcement license version.');
$primary = (int) $first['organizations'][QaFixtureService::PRIMARY_CODE]['id'];
$isolated = (int) $first['organizations'][QaFixtureService::ISOLATED_CODE]['id'];
$assert($primary !== $isolated, 'Primary and isolated organizations collapsed.');
$assert(Db::table('im_user')->where('organization', $primary)->whereIn('account', ['qa_im_a', 'qa_im_b'])->count() === 2, 'Primary IM users are missing.');
$assert(Db::table('im_user')->where('organization', $isolated)->where('account', 'qa_im_x')->count() === 1, 'Isolated IM user is missing.');
$announcementLicense = Db::table('sm_tenant_module_license')
    ->where('organization', $primary)
    ->where('module_key', 'announcement')
    ->whereNull('delete_time')
    ->find();
$assert(
    is_array($announcementLicense)
    && $announcementLicense['status'] === 'ENABLED'
    && $announcementLicense['remark'] === QaFixtureService::MARKER
    && $announcementLicense['expire_at'] === null,
    'Primary QA organization announcement license is not enabled.',
);
$assert(
    ModuleServiceFactory::access()->isAvailable(
        $primary,
        'announcement',
        'server',
        'announcement.web.read',
    ),
    'Primary QA organization cannot pass the announcement.web.read license boundary.',
);
$friendships = Db::table('im_friend_relation')
    ->where('organization', $primary)
    ->whereIn('user_id', ['qa-im-user-a', 'qa-im-user-b'])
    ->whereIn('friend_user_id', ['qa-im-user-a', 'qa-im-user-b'])
    ->where('status', 1)
    ->whereNull('delete_time')
    ->select()
    ->toArray();
$assert(count($friendships) === 2, 'Users A and B are not active mutual friends.');
$directions = array_map(static fn (array $row): string => $row['user_id'] . '>' . $row['friend_user_id'], $friendships);
sort($directions);
$assert($directions === ['qa-im-user-a>qa-im-user-b', 'qa-im-user-b>qa-im-user-a'], 'Users A and B friendship directions are incomplete.');
$primaryAppInfo = (new OrganizationDiscovery())->resolve(QaFixtureService::PRIMARY_CODE, OrganizationDiscovery::MODE_ENTERPRISE_CODE, 'web');
$isolatedAppInfo = (new OrganizationDiscovery())->resolve(QaFixtureService::ISOLATED_CODE, OrganizationDiscovery::MODE_ENTERPRISE_CODE, 'web');
$assert((int) $primaryAppInfo['organization'] === $primary, 'Primary AppInfo resolved the wrong organization.');
$assert((int) $isolatedAppInfo['organization'] === $isolated, 'Isolated AppInfo resolved the wrong organization.');
$assert((string) $primaryAppInfo['server_info']['routes'][0]['endpoints']['api_server_url'] !== '', 'Primary AppInfo routing endpoint is empty.');
$assert(Db::table('sm_organization_route_publish')->where('organization', $primary)->count() === 3, 'Primary routing was not published for all clients.');
$assert(Db::table('sm_organization_route_publish')->where('organization', $isolated)->count() === 3, 'Isolated routing was not published for all clients.');
$authStore = new ThinkOrmWebImAuthStore();
$authA = $authStore->findActiveLoginUser($primary, 'qa_im_a');
$assert(is_array($authA) && password_verify(QaFixtureService::IM_PASSWORD, (string) $authA['password_hash']), 'Web IM auth store cannot authenticate User A.');
$assert($authStore->findActiveLoginUser($isolated, 'qa_im_a') === null, 'Cross-organization auth lookup leaked User A.');
(new WebImPolicyGuard())->assertWebAllowed($primary);
(new WebImPolicyGuard())->assertWebAllowed($isolated);
$service->cleanup();
$assert(Db::table('sm_system_organization')->whereIn('enterprise_code', [QaFixtureService::PRIMARY_CODE, QaFixtureService::ISOLATED_CODE])->count() === 0, 'Cleanup left QA organizations behind.');
$assert(Db::table('sm_organization_route_publish')->whereIn('organization', [$primary, $isolated])->count() === 0, 'Cleanup left QA routing publications behind.');
$assert(Db::table('sm_tenant_module_license')->whereIn('organization', [$primary, $isolated])->count() === 0, 'Cleanup left QA module licenses behind.');
$assert(Db::table('sm_server_route_pool')->whereIn('route_pool_id', ['qa-im-primary-local', 'qa-im-isolated-local'])->count() === 0, 'Cleanup left QA route pools behind.');
$assert(Db::table('sm_system_organization')->where('id', 1)->count() === 1, 'Cleanup damaged an ordinary organization.');
$final = $service->provision();
$assert(count($final['im_users']) === 3, 'Final reprovision did not restore all IM users.');
$assert(($final['announcement_license']['status'] ?? null) === 'ENABLED', 'Final reprovision did not restore announcement license.');

fwrite(STDOUT, json_encode(['passed' => true, 'final_fixture' => $final], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
