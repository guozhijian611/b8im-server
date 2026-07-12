<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support/bootstrap.php';

use plugin\saimulti\service\routing\RoutingConfigService;
use support\think\Db;

$database = (string) env('DB_NAME', '');
if (!(bool) config('app.debug', false) || ($database !== 'nb8im' && !str_ends_with($database, '_routing_test'))) {
    throw new RuntimeException('本机线路发布脚本只允许在 APP_DEBUG 的 nb8im 或 *_routing_test 数据库执行。');
}

$organizations = Db::table('sm_system_organization')
    ->where('status', 1)
    ->whereNull('delete_time')
    ->order('id', 'asc')
    ->select()
    ->toArray();
if ($organizations === []) {
    throw new RuntimeException('没有可发布线路的启用机构。');
}

$service = new RoutingConfigService();
$summaries = [];
$publicKey = '';
foreach ($organizations as $organization) {
    $id = (int) $organization['id'];
    $routes = [
        [
            'route_id' => 'local-primary', 'name' => '本机主线路', 'priority' => 10, 'weight' => 100,
            'region' => 'local', 'carrier' => 'loopback', 'failure_domain' => 'port-primary',
            'endpoints' => [
                'api_server_url' => env('ROUTING_LOCAL_API_PRIMARY', 'http://127.0.0.1:18888'),
                'im_server_url' => env('ROUTING_LOCAL_IM_PRIMARY', 'ws://127.0.0.1:18787'),
                'upload_server_url' => env('ROUTING_LOCAL_UPLOAD_PRIMARY', 'http://127.0.0.1:18888'),
                'web_server_url' => env('ROUTING_LOCAL_WEB_URL', 'http://127.0.0.1:16988'),
            ],
        ],
    ];
    $result = $service->publish([
        'organization' => $id,
        'deployment_id' => (string) $organization['deployment_id'],
        'deployment_name' => '本机开发环境',
        'route_pool_id' => 'org-' . $id . '-local-dev',
        'route_pool_name' => '机构 ' . $id . ' 本机单线路池',
        'mode' => 'single',
        'client_families' => ['web', 'app', 'desktop'],
        'routes' => $routes,
    ], ['type' => 'local-script', 'id' => 0]);
    $publicKey = (string) $result['routing_public_key'];
    $summaries[] = [
        'organization' => $id,
        'route_pool_version' => $result['route_pool_version'],
        'routing_versions' => array_map(
            static fn (array $published): int => (int) $published['routing_version'],
            $result['published'],
        ),
    ];
}

echo json_encode([
    'kid' => (string) env('ROUTING_SIGNING_KID', ''),
    'public_key' => $publicKey,
    'published' => $summaries,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
