<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\app\event\SystemAdmin;
use plugin\saimulti\app\event\SystemTenant;
use plugin\saimulti\service\AuditLogRedactor;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$params = [
    'password_confirm' => 'top-level-password-confirm',
    'passwordConfirm' => 'camel-password-confirm',
    'PASSWORD' => 'upper-password',
    'content' => 'sensitive-rich-text',
    'profile' => [
        'nickname' => '普通用户',
        'config' => [
            'config_secret' => 'nested-config-secret',
            'API_KEY' => 'nested-api-key',
            'api_key_name' => 'nested-api-key-name',
            'privateKey' => 'nested-private-key',
            'private_key_format' => 'nested-private-key-format',
            'client_secret_version' => 'nested-client-secret-version',
            'qiniu_secretKey' => 'direct-qiniu-secret-key',
            'cos_secretKey' => 'direct-cos-secret-key',
            'oss_accessKeySecret' => 'direct-oss-access-key-secret',
            's3_secret' => 'direct-s3-secret',
            'Authorization' => 'Bearer nested-authorization',
            'Cookie' => 'session=nested-cookie',
            'accessToken' => 'nested-access-token',
            'public_key' => 'ordinary-public-key',
            'token_type' => 'Bearer',
            'cookie_policy' => 'strict',
            'secretary' => 'ordinary-secretary',
            'tokenizer' => 'ordinary-tokenizer',
        ],
        'settings' => [
            ['key' => 'qiniu_secretKey', 'value' => 'entry-qiniu-secret', 'label' => '七牛密钥'],
            ['key' => 'oss_accessKeySecret', 'value' => ['nested' => 'entry-oss-secret']],
            ['key' => 's3_secret', 'value' => 'entry-s3-secret'],
            ['key' => 'qiniu_accessKey', 'value' => 'entry-qiniu-access-key'],
            ['key' => 'cos_secretId', 'value' => 'entry-cos-secret-id'],
            ['key' => 'oss_accessKeyId', 'value' => 'entry-oss-access-key-id'],
            ['key' => 's3_key', 'value' => 'entry-s3-key'],
            ['key' => 'public_key', 'value' => 'entry-public-key'],
            ['key' => 's3_region', 'value' => 'cn-east-1'],
        ],
    ],
    'raw_json' => '{"password":"text-must-not-be-guessed"}',
    'invalid_utf8_note' => "\xB1\x31",
];

$redactor = new AuditLogRedactor();
$encoded = $redactor->encode($params);
$assert(is_string($encoded), 'AuditLogRedactor 编码失败时未返回字符串');
$filtered = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

foreach ([SystemAdmin::class, SystemTenant::class] as $eventClass) {
    $method = new ReflectionMethod($eventClass, 'filterParams');
    $method->setAccessible(true);
    $eventEncoded = $method->invoke(new $eventClass(), $params);
    $assert($eventEncoded === $encoded, "{$eventClass} 未委托统一审计脱敏服务");

    foreach (['password_confirm', 'passwordConfirm', 'PASSWORD', 'content'] as $key) {
        $assert($filtered[$key] === '******', "{$eventClass} 未隐藏敏感键 {$key}");
    }
    foreach ([
        'config_secret', 'API_KEY', 'api_key_name', 'privateKey', 'private_key_format',
        'client_secret_version', 'qiniu_secretKey', 'cos_secretKey',
        'oss_accessKeySecret', 's3_secret', 'Authorization', 'Cookie', 'accessToken',
    ] as $key) {
        $assert(
            $filtered['profile']['config'][$key] === '******',
            "{$eventClass} 未隐藏嵌套敏感键 {$key}",
        );
    }
    $assert($filtered['profile']['nickname'] === '普通用户', "{$eventClass} 丢失普通字段");
    $assert(
        $filtered['profile']['config']['public_key'] === 'ordinary-public-key'
        && $filtered['profile']['config']['token_type'] === 'Bearer'
        && $filtered['profile']['config']['cookie_policy'] === 'strict'
        && $filtered['profile']['config']['secretary'] === 'ordinary-secretary'
        && $filtered['profile']['config']['tokenizer'] === 'ordinary-tokenizer',
        "{$eventClass} 误隐藏普通 config 字段",
    );
    $assert(
        $filtered['profile']['settings'][0]['key'] === 'qiniu_secretKey'
        && $filtered['profile']['settings'][0]['value'] === '******'
        && $filtered['profile']['settings'][0]['label'] === '七牛密钥'
        && $filtered['profile']['settings'][1]['value'] === '******'
        && $filtered['profile']['settings'][2]['value'] === '******'
        && $filtered['profile']['settings'][3]['value'] === '******'
        && $filtered['profile']['settings'][4]['value'] === '******'
        && $filtered['profile']['settings'][5]['value'] === '******'
        && $filtered['profile']['settings'][6]['value'] === '******',
        "{$eventClass} 未隐藏 key/value 配置项中的敏感值",
    );
    $assert(
        $filtered['profile']['settings'][7]['value'] === 'entry-public-key'
        && $filtered['profile']['settings'][8]['value'] === 'cn-east-1',
        "{$eventClass} 误隐藏普通 key/value 配置项",
    );
    $assert(
        $filtered['raw_json'] === '{"password":"text-must-not-be-guessed"}',
        "{$eventClass} 猜测解析了字符串 JSON",
    );
    $assert(
        $filtered['invalid_utf8_note'] === "\u{FFFD}1",
        "{$eventClass} 未安全替换非法 UTF-8",
    );
}

$resource = fopen('php://memory', 'r+');
if ($resource === false) {
    throw new RuntimeException('无法创建编码失败测试资源');
}
try {
    $fallback = $redactor->encode(['ordinary' => 'must-not-leak', 'resource' => $resource]);
    $assert($fallback === AuditLogRedactor::ENCODING_ERROR, '编码失败未返回固定安全 marker');
    $assert(!str_contains($fallback, 'must-not-leak'), '编码失败 marker 泄漏原始值');
} finally {
    fclose($resource);
}

echo "OperationAuditRedactionTest passed\n";
