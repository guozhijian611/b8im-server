<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Manifest\Manifest;
use B8im\ModuleSdk\Manifest\ManifestLoader;
use Phinx\Db\Adapter\MysqlAdapter;
use plugin\saimulti\service\module\ModuleExpiryHookContract;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};
$base = json_decode(
    (string) file_get_contents(
        dirname(__DIR__) . '/vendor/b8im/module-sdk/examples/announcement/module.json',
    ),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$version64 = '1.2.3+' . str_repeat('a', 58);
$handler300 = 'A' . str_repeat('a', 290) . '::disable';
$base['version'] = $version64;
$base['hooks']['disable'] = [
    'handler' => $handler300,
    'scope' => 'tenant',
    'transactional' => true,
];
for ($index = 0; ; ++$index) {
    $sourceJson = json_encode(
        $base,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    if (strlen($sourceJson) >= 65000) {
        break;
    }
    $base['permissions'][] = [
        'slug' => 'announcement:expiry_padding_' . $index,
        'name' => 'P',
        'scope' => 'tenant',
        'description' => str_repeat('d', 400),
    ];
}
$assert(
    strlen($sourceJson) >= 65000 && strlen($sourceJson) <= 65535,
    'Near-TEXT manifest fixture did not stay inside the legal source capacity.',
);
$sqlType = (new MysqlAdapter([]))->getSqlType('text', MysqlAdapter::TEXT_MEDIUM);
$assert(
    ($sqlType['name'] ?? null) === 'mediumtext'
    && MysqlAdapter::TEXT_MEDIUM > MysqlAdapter::TEXT_REGULAR,
    'Phinx text + TEXT_MEDIUM does not generate a MEDIUMTEXT SQL type.',
);
$manifest = (new ManifestLoader())->fromJson($sourceJson, 'near-text-module.json');
$credential = [
    'license_id' => '18446744073709551615',
    'organization' => 1,
    'module_key' => 'announcement',
    'expired_version' => 2,
];
$frozen = ModuleExpiryHookContract::freeze(
    $manifest,
    LifecycleOperation::DISABLE,
    $credential,
    ModuleExpiryHookContract::KIND_TRANSACTIONAL,
);
$loaded = ModuleExpiryHookContract::load($frozen['json'], $frozen['digest'], $credential);
$assert(
    $frozen['module_version'] === $version64
    && strlen($frozen['module_version']) === 64
    && $frozen['handler'] === $handler300
    && strlen($frozen['handler']) === 300
    && strlen($frozen['json']) > 65535
    && $loaded['module_version'] === $version64
    && $loaded['handler'] === $handler300,
    '64-byte version, 300-byte handler and near-TEXT manifest did not round-trip in the envelope.',
);

foreach ([
    'version' => static function (array $data): array {
        $data['version'] = '1.2.3+' . str_repeat('a', 59);
        return $data;
    },
    'handler' => static function (array $data): array {
        $data['hooks']['disable']['handler'] = 'A' . str_repeat('a', 291) . '::disable';
        return $data;
    },
] as $field => $mutate) {
    try {
        ModuleExpiryHookContract::freeze(
            new Manifest($mutate($base)),
            LifecycleOperation::DISABLE,
            $credential,
            ModuleExpiryHookContract::KIND_TRANSACTIONAL,
        );
        $assert(false, 'Oversized expiry contract ' . $field . ' was accepted.');
    } catch (RuntimeException $exception) {
        $assert(
            str_contains($exception->getMessage(), '冻结契约字段无效'),
            'Oversized expiry contract ' . $field . ' returned the wrong failure.',
        );
    }
}

echo sprintf("ModuleExpiryHookContractTest: %d assertions passed\n", $assertions);
