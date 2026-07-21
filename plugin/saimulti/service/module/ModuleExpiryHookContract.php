<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Manifest\Manifest;
use JsonException;
use RuntimeException;
use Throwable;

final class ModuleExpiryHookContract
{
    private const MAX_MODULE_VERSION_BYTES = 64;

    private const MAX_HANDLER_BYTES = 300;

    private const MAX_CONTRACT_BYTES = 16777215;

    public const KIND_TRANSACTIONAL = 'transactional';
    public const KIND_EXTERNAL = 'external';

    /** @param array<string,mixed> $credential */
    public static function stableKey(array $credential): string
    {
        return hash('sha256', self::encode([
            'contract' => 'b8im.module-expiry-hook.v1',
            'credential' => self::credential($credential),
        ]));
    }

    /**
     * @param array<string,mixed> $credential
     * @return array{json:string,digest:string,hook_kind:string,module_version:string,handler:string,scope:string,transactional:bool}
     */
    public static function freeze(
        Manifest $manifest,
        LifecycleOperation $operation,
        array $credential,
        string $hookKind,
    ): array {
        $definition = $manifest->hooks()[$operation->value] ?? null;
        if (!is_array($definition)) {
            throw new RuntimeException(sprintf(
                '模块 %s 缺少 %s hook。',
                $manifest->moduleKey(),
                $operation->value,
            ));
        }

        $handler = (string) ($definition['handler'] ?? '');
        $scope = (string) ($definition['scope'] ?? '');
        $transactional = (bool) ($definition['transactional'] ?? false);
        $moduleVersion = $manifest->version();
        if (!in_array($hookKind, [self::KIND_TRANSACTIONAL, self::KIND_EXTERNAL], true)
            || strlen($moduleVersion) < 1
            || strlen($moduleVersion) > self::MAX_MODULE_VERSION_BYTES
            || preg_match(
                '/^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)'
                . '(?:-[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?'
                . '(?:\\+[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?$/D',
                $moduleVersion,
            ) !== 1
            || strlen($handler) < 3
            || strlen($handler) > self::MAX_HANDLER_BYTES
            || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*::[a-z][a-zA-Z0-9_]*$/D', $handler) !== 1
            || !in_array($scope, ['tenant', 'system', 'both'], true)) {
            throw new RuntimeException('授权到期 hook 冻结契约字段无效。');
        }

        $json = self::encode([
            'contract' => 'b8im.module-expiry-hook.request.v2',
            'credential' => self::credential($credential),
            'handler' => $handler,
            'hook_kind' => $hookKind,
            'manifest' => $manifest->toArray(),
            'module_key' => $manifest->moduleKey(),
            'module_version' => $moduleVersion,
            'operation' => $operation->value,
            'scope' => $scope,
            'transactional' => $transactional,
        ]);
        if (strlen($json) > self::MAX_CONTRACT_BYTES) {
            throw new RuntimeException('授权到期 hook 冻结契约超过 MEDIUMTEXT 字节上限。');
        }

        return [
            'json' => $json,
            'digest' => hash('sha256', $json),
            'hook_kind' => $hookKind,
            'module_version' => $moduleVersion,
            'handler' => $handler,
            'scope' => $scope,
            'transactional' => $transactional,
        ];
    }

    /**
     * @param array<string,mixed> $credential
     * @return array{manifest:Manifest,operation:LifecycleOperation,hook_kind:string,module_version:string,handler:string,scope:string,transactional:bool}
     */
    public static function load(string $json, string $digest, array $credential): array
    {
        try {
            if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1
                || !hash_equals($digest, hash('sha256', $json))) {
                throw new RuntimeException('冻结契约 digest 不一致。');
            }
            $contract = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $keys = is_array($contract) ? array_keys($contract) : [];
            sort($keys, SORT_STRING);
            if (!is_array($contract)
                || $keys !== [
                    'contract',
                    'credential',
                    'handler',
                    'hook_kind',
                    'manifest',
                    'module_key',
                    'module_version',
                    'operation',
                    'scope',
                    'transactional',
                ]
                || ($contract['contract'] ?? null) !== 'b8im.module-expiry-hook.request.v2'
                || ($contract['credential'] ?? null) !== self::canonicalize(self::credential($credential))
                || !is_array($contract['manifest'] ?? null)) {
                throw new RuntimeException('冻结契约不是 canonical immutable contract。');
            }

            $operation = LifecycleOperation::tryFrom((string) ($contract['operation'] ?? ''));
            $manifest = new Manifest($contract['manifest']);
            $definition = $operation === null ? null : ($manifest->hooks()[$operation->value] ?? null);
            $hookKind = (string) ($contract['hook_kind'] ?? '');
            $handler = (string) ($contract['handler'] ?? '');
            $scope = (string) ($contract['scope'] ?? '');
            $transactional = $contract['transactional'] ?? null;
            if ($operation !== LifecycleOperation::DISABLE
                || !is_array($definition)
                || !in_array($hookKind, [self::KIND_TRANSACTIONAL, self::KIND_EXTERNAL], true)
                || $manifest->moduleKey() !== ($contract['module_key'] ?? null)
                || $manifest->version() !== ($contract['module_version'] ?? null)
                || ($definition['handler'] ?? null) !== $handler
                || ($definition['scope'] ?? null) !== $scope
                || !is_bool($transactional)
                || (bool) ($definition['transactional'] ?? false) !== $transactional) {
                throw new RuntimeException('冻结契约与 manifest hook 定义不一致。');
            }

            return [
                'manifest' => $manifest,
                'operation' => $operation,
                'hook_kind' => $hookKind,
                'module_version' => $manifest->version(),
                'handler' => $handler,
                'scope' => $scope,
                'transactional' => $transactional,
            ];
        } catch (Throwable $exception) {
            throw new ModuleExpiryHookContractUnavailable(
                '授权到期 immutable hook contract 无法执行：' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /** @param array<string,mixed> $credential @return array<string,int|string> */
    public static function credential(array $credential): array
    {
        return [
            'license_id' => self::uint64($credential['license_id'] ?? null, 'license_id'),
            'organization' => self::positiveInt($credential['organization'] ?? null, 'organization'),
            'module_key' => self::moduleKey($credential['module_key'] ?? null),
            'expired_version' => self::positiveInt($credential['expired_version'] ?? null, 'expired_version'),
        ];
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException(sprintf('授权到期 hook %s 必须是正整数。', $field));
        }
        return $value;
    }

    private static function uint64(mixed $value, string $field): string
    {
        $canonical = is_int($value) ? (string) $value : (is_string($value) ? $value : '');
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $canonical) !== 1
            || (strlen($canonical) === 20 && strcmp($canonical, '18446744073709551615') > 0)) {
            throw new RuntimeException(sprintf('授权到期 hook %s 必须是 canonical UINT64。', $field));
        }
        return $canonical;
    }

    private static function moduleKey(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $value) !== 1) {
            throw new RuntimeException('授权到期 hook module_key 无效。');
        }
        return $value;
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        try {
            return json_encode(
                self::canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('授权到期 hook credential 序列化失败。', previous: $exception);
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = self::canonicalize($nested);
        }
        return $value;
    }
}
