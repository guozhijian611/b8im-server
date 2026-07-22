<?php

declare(strict_types=1);

$database = getenv('ROUTING_TEST_DB_NAME');
if (!is_string($database) || !str_ends_with($database, '_routing_test')) {
    throw new RuntimeException('ROUTING_TEST_DB_NAME 只允许使用 *_routing_test 临时库。');
}
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('DB_NAME=' . $database);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

use plugin\saimulti\service\OrganizationDiscovery;
use plugin\saimulti\service\WebOrganizationResolver;
use plugin\saimulti\app\middleware\AppClientRequest;
use plugin\saimulti\app\middleware\WebCors;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\exception\SearchProjectionIntegrityException;
use plugin\saimulti\service\routing\CanonicalJson;
use plugin\saimulti\service\routing\RoutingConfigService;
use plugin\saimulti\service\routing\RoutingSnapshotSigner;
use support\think\Db;
use Webman\Http\Request;

$config = config('think-orm');
$connection = (string) ($config['default'] ?? 'mysql');
$config['connections'][$connection]['database'] = $database;
Db::setConfig($config);
$actual = (string) (Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '');
if ($actual !== $database) {
    throw new RuntimeException("线路集成测试库隔离失败: expected={$database} actual={$actual}");
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) throw new RuntimeException($message);
    $assertions++;
};

$seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
$signer = new RoutingSnapshotSigner(rtrim(strtr(base64_encode($seed), '+/', '-_'), '='), 'routing-test-1');
$service = new RoutingConfigService($signer);
$input = [
    'organization' => 1,
    'deployment_id' => 'b8im-local',
    'deployment_name' => '本机开发环境',
    'route_pool_id' => 'org-1-local-dev',
    'route_pool_name' => '本机双端口线路池',
    'mode' => 'primary_backup',
    'client_families' => ['web', 'app', 'desktop'],
    'routes' => [
        [
            'route_id' => 'local-primary', 'name' => '本机主线路', 'priority' => 10, 'weight' => 100,
            'region' => 'local', 'carrier' => 'loopback', 'failure_domain' => 'port-primary',
            'endpoints' => [
                'api_server_url' => 'http://127.0.0.1:18888',
                'im_server_url' => 'ws://127.0.0.1:18787',
                'upload_server_url' => 'http://127.0.0.1:18888',
                'web_server_url' => 'http://127.0.0.1:16988',
            ],
        ],
        [
            'route_id' => 'local-backup', 'name' => '本机备用线路', 'priority' => 20, 'weight' => 100,
            'region' => 'local', 'carrier' => 'loopback', 'failure_domain' => 'port-backup',
            'endpoints' => [
                'api_server_url' => 'http://127.0.0.1:18889',
                'im_server_url' => 'ws://127.0.0.1:18788',
                'upload_server_url' => 'http://127.0.0.1:18889',
                'web_server_url' => 'http://127.0.0.1:16988',
            ],
        ],
    ],
];

$first = $service->publish($input, ['id' => 7]);
$assert($first['route_pool_version'] >= 1, '未生成线路池版本');
$assert(array_keys($first['published']) === ['web', 'app', 'desktop'], '未按三类客户端发布');
$web = $service->read(1, 'web');
$assert($web['server_info']['schema_version'] === 2, 'server_info schema 不是 V2');
$assert(count($web['server_info']['routes']) === 2, '主备线路没有完整发布');
$assert($web['server_info']['policy']['mode'] === 'primary_backup', '线路模式不正确');

$organization = Db::table('sm_system_organization')->where('id', 1)->find();
$assert(
    (new WebOrganizationResolver())->assertOrganizationOrigin($organization, 'http://127.0.0.1:16988') === 'http://127.0.0.1:16988',
    'Web Origin 未按当前机构发布线路校验',
);
$webRequest = new Request(
    "GET /saimulti/web/search/messages HTTP/1.1\r\n"
    . "Host: api.example.test\r\n"
    . "App-Id: 1\r\n"
    . "Origin: http://127.0.0.1:16988\r\n\r\n",
);
$appRequest = new Request(
    "GET /saimulti/app/search/messages HTTP/1.1\r\n"
    . "Host: api.example.test\r\n"
    . "App-Id: 1\r\n\r\n",
);
$integrityFailure = static function (Request $_request): never {
    throw new SearchProjectionIntegrityException('internal-search-integrity-sentinel');
};
$integrityResponses = [
    'web' => (new WebCors())->process($webRequest, $integrityFailure),
    'app' => (new AppClientRequest())->process($appRequest, $integrityFailure),
];
foreach ($integrityResponses as $client => $response) {
    $payload = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    $assert(
        $response->getStatusCode() === 503 && ($payload['code'] ?? null) === 503,
        '搜索投影完整性异常未在 Web/App 中间件映射为 HTTP/body 503',
    );
    $assert(
        !str_contains($response->rawBody(), 'internal-search-integrity-sentinel'),
        '搜索投影完整性内部详情泄漏到 Web/App 响应',
    );
    if ($client === 'web') {
        $assert(
            $response->getHeader('Access-Control-Allow-Origin')
                === 'http://127.0.0.1:16988',
            'Web 搜索 503 错误响应丢失可信 CORS Origin',
        );
    }
}
$ordinaryUnavailable = static function (Request $_request): never {
    throw new ApiException('Search backend is unavailable.', 503);
};
$ordinaryResponses = [
    'web' => (new WebCors())->process($webRequest, $ordinaryUnavailable),
    'app' => (new AppClientRequest())->process($appRequest, $ordinaryUnavailable),
];
foreach ($ordinaryResponses as $client => $response) {
    $payload = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    $assert(
        $response->getStatusCode() === 503 && ($payload['code'] ?? null) === 503,
        '普通 ApiException(503) 未在 Web/App 中间件映射为 HTTP/body 503',
    );
    $assert(
        ($payload['message'] ?? null) === 'Search backend is unavailable.',
        '普通 ApiException(503) 丢失明确用户文案',
    );
    if ($client === 'web') {
        $assert(
            $response->getHeader('Access-Control-Allow-Origin')
                === 'http://127.0.0.1:16988',
            'Web ApiException(503) 错误响应丢失可信 CORS Origin',
        );
    }
}
$payload = [
    'organization' => 1,
    'deployment_id' => 'b8im-local',
    'enterprise_code' => (string) $organization['enterprise_code'],
    'client_family' => 'web',
    'server_info' => $web['server_info'],
];
$signature = strtr($web['routing_signature']['value'], '-_', '+/');
$signature .= str_repeat('=', (4 - strlen($signature) % 4) % 4);
$assert(sodium_crypto_sign_verify_detached(
    base64_decode($signature, true),
    CanonicalJson::encode($payload),
    base64_decode(strtr($signer->publicKey(), '-_', '+/') . str_repeat('=', (4 - strlen($signer->publicKey()) % 4) % 4), true),
), 'Ed25519 线路签名验证失败');

$second = $service->publish($input, ['id' => 7]);
$assert($second['route_pool_version'] === $first['route_pool_version'], '相同线路内容错误生成了新 pool version');
$assert($second['published']['web']['routing_version'] === $first['published']['web']['routing_version'] + 1, '重复发布没有递增 routing_version');
$assert((int) Db::table('sm_server_route_version')->whereIn('route_id', ['local-primary', 'local-backup'])->count() === 2, '相同线路内容没有复用 immutable route version');

$expiredRow = Db::table('sm_organization_route_publish')
    ->where('deployment_id', 'b8im-local')
    ->where('organization', 1)
    ->where('client_family', 'web')
    ->order('routing_version', 'desc')
    ->find();
$expiredSnapshot = json_decode((string) $expiredRow['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
$expiredSnapshot['expires_at'] = '2000-01-01T00:00:00+00:00';
Db::table('sm_organization_route_publish')->where('id', $expiredRow['id'])->update([
    'snapshot_json' => json_encode($expiredSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
]);
$publishCount = (int) Db::table('sm_organization_route_publish')
    ->where('deployment_id', 'b8im-local')
    ->where('organization', 1)
    ->where('client_family', 'web')
    ->count();
$renewed = $service->read(1, 'web');
$assert($renewed['server_info']['routing_version'] === $second['published']['web']['routing_version'] + 1, '过期快照没有递增 routing_version 原子续签');
$assert(strtotime($renewed['server_info']['expires_at']) > time(), '续签快照 expires_at 未恢复到未来时间');
$renewedPayload = [
    'organization' => 1,
    'deployment_id' => 'b8im-local',
    'enterprise_code' => (string) $organization['enterprise_code'],
    'client_family' => 'web',
    'server_info' => $renewed['server_info'],
];
$renewedSignature = strtr($renewed['routing_signature']['value'], '-_', '+/');
$renewedSignature .= str_repeat('=', (4 - strlen($renewedSignature) % 4) % 4);
$assert(sodium_crypto_sign_verify_detached(
    base64_decode($renewedSignature, true),
    CanonicalJson::encode($renewedPayload),
    base64_decode(strtr($signer->publicKey(), '-_', '+/') . str_repeat('=', (4 - strlen($signer->publicKey()) % 4) % 4), true),
), '续签快照 Ed25519 签名验证失败');
$assert((int) Db::table('sm_organization_route_publish')
    ->where('deployment_id', 'b8im-local')
    ->where('organization', 1)
    ->where('client_family', 'web')
    ->count() === $publishCount + 1, '过期快照续签未创建不可变发布记录');
$service->read(1, 'web');
$assert((int) Db::table('sm_organization_route_publish')
    ->where('deployment_id', 'b8im-local')
    ->where('organization', 1)
    ->where('client_family', 'web')
    ->count() === $publishCount + 1, '未过期快照被重复续签');

$contract = (new OrganizationDiscovery(true))->resolve((string) $organization['enterprise_code'], OrganizationDiscovery::MODE_ENTERPRISE_CODE, 'web');
$assert($contract['client_family'] === 'web', 'appInfo 未返回严格 client_family');
$assert($contract['server_info']['routing_version'] === $renewed['server_info']['routing_version'], 'appInfo 没有读取最新续签快照');
$assert($contract['routing_signature']['kid'] === 'routing-test-1', 'appInfo 签名元数据不一致');

echo "RoutingConfigIntegrationTest: {$assertions} assertions passed\n";
