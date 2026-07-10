<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\app\logic\system\SystemOrganizationLogic;
use plugin\saimulti\service\OrganizationDiscovery;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, '异常码不匹配');
        return;
    }

    throw new RuntimeException('预期异常未抛出');
};

[$mode, $identifier] = OrganizationDiscovery::requestIdentifier('', ' Acme_01 ', '');
$assert($mode === OrganizationDiscovery::MODE_ENTERPRISE_CODE, '企业码模式解析失败');
$assert($identifier === 'acme_01', '企业码未规范化');

[$mode, $identifier] = OrganizationDiscovery::requestIdentifier('domain', '', 'IM.Example.COM.');
$assert($mode === OrganizationDiscovery::MODE_DOMAIN, '域名模式解析失败');
$assert($identifier === 'im.example.com', '域名未规范化');

$expectCode(
    OrganizationDiscovery::INVALID_REQUEST,
    static fn () => OrganizationDiscovery::requestIdentifier('', '', ''),
);

$tenantProfile = SystemOrganizationLogic::tenantProfileData([
    'title' => '租户品牌',
    'user_agreement_content' => '租户协议',
    'enterprise_code' => 'attacker',
    'deployment_id' => 'attacker',
    'api_server_url' => 'https://attacker.example.com',
    'im_server_url' => 'wss://attacker.example.com',
    'organization' => 999,
    'status' => 2,
    'config_version' => 999,
]);
$assert($tenantProfile['title'] === '租户品牌', '租户品牌字段被错误删除');
$assert($tenantProfile['user_agreement_content'] === '租户协议', '租户协议字段被错误删除');
foreach (['enterprise_code', 'deployment_id', 'api_server_url', 'im_server_url', 'organization', 'status', 'config_version'] as $forbiddenField) {
    $assert(!array_key_exists($forbiddenField, $tenantProfile), "租户写入仍包含平台字段 {$forbiddenField}");
}

$logic = (new ReflectionClass(SystemOrganizationLogic::class))->newInstanceWithoutConstructor();
$normalizeWrite = new ReflectionMethod(SystemOrganizationLogic::class, 'normalizeWriteData');
$staleExisting = [
    'enterprise_code' => 'tenant_01',
    'deployment_id' => 'deployment_01',
    'domain' => 'im.example.com',
    'title' => '旧标题',
    'api_server_url' => 'https://api.example.com',
    'im_server_url' => 'wss://im.example.com',
    'upload_server_url' => 'https://upload.example.com',
    'web_server_url' => 'https://web.example.com',
    'status' => 1,
];
$normalizedProfileWrite = $normalizeWrite->invoke($logic, ['title' => '新标题'], $staleExisting);
$assert($normalizedProfileWrite === ['title' => '新标题'], '租户资料更新携带了未请求的旧字段');
foreach ([
    'status',
    'domain',
    'enterprise_code',
    'deployment_id',
    'api_server_url',
    'im_server_url',
    'upload_server_url',
    'web_server_url',
] as $staleField) {
    $assert(
        !array_key_exists($staleField, $normalizedProfileWrite),
        "stale profile write can overwrite platform field {$staleField}",
    );
}
$normalizedExplicitStatus = $normalizeWrite->invoke($logic, ['status' => 2], $staleExisting);
$assert($normalizedExplicitStatus === ['status' => 2], '显式机构状态更新被误删除或扩展');
$expectCode(
    OrganizationDiscovery::INVALID_REQUEST,
    static fn () => OrganizationDiscovery::requestIdentifier('domain', 'acme', 'im.example.com'),
);
$expectCode(
    OrganizationDiscovery::INVALID_REQUEST,
    static fn () => OrganizationDiscovery::normalizeDomain('https://im.example.com/path'),
);

$assert(
    OrganizationDiscovery::assertPublicUrl('http://127.0.0.1:18888', ['https', 'http'], true)
        === 'http://127.0.0.1:18888',
    '开发环境 HTTP 地址被拒绝',
);
$assert(
    OrganizationDiscovery::assertPublicUrl('wss://im.example.com/ws', ['wss', 'ws'], false)
        === 'wss://im.example.com/ws',
    '生产环境 WSS 地址被拒绝',
);
$expectCode(
    OrganizationDiscovery::INVALID_CONFIGURATION,
    static fn () => OrganizationDiscovery::assertPublicUrl('javascript:alert(1)', ['https', 'http'], true),
);
$expectCode(
    OrganizationDiscovery::INVALID_CONFIGURATION,
    static fn () => OrganizationDiscovery::assertPublicUrl('http://api.example.com', ['https', 'http'], false),
);

echo "Organization discovery tests passed\n";
