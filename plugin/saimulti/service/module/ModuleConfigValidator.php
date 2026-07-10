<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Manifest\Manifest;
use plugin\saimulti\exception\ApiException;

final class ModuleConfigValidator
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public function validateTenantConfig(Manifest $manifest, array $input, array $current = []): array
    {
        $definitions = [];
        $values = [];
        foreach ($manifest->config() as $definition) {
            if ($definition['scope'] !== 'tenant') {
                continue;
            }
            $definitions[$definition['key']] = $definition;
            // Sensitive defaults would put a reusable secret in the manifest
            // and can leak through schema projections. They are deliberately
            // ignored: a secret must be supplied explicitly and encrypted.
            if (array_key_exists('default', $definition) && !$this->isSensitive($definition)) {
                $values[$definition['key']] = $definition['default'];
            }
        }

        foreach ($current as $key => $value) {
            if (isset($definitions[$key])) {
                $values[$key] = $value;
            }
        }
        foreach ($input as $key => $value) {
            if (!isset($definitions[$key])) {
                throw new ApiException(sprintf('未声明的模块配置项: %s', $key), 422);
            }
            $this->assertValue($definitions[$key], $value);
            $values[$key] = $value;
        }

        foreach ($definitions as $key => $definition) {
            if (($definition['required'] ?? false)
                && (!array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '')) {
                throw new ApiException(sprintf('模块配置项 %s 为必填项。', $key), 422);
            }
            if (array_key_exists($key, $values)) {
                $this->assertValue($definition, $values[$key]);
            }
        }

        ksort($values);

        return $values;
    }

    /** @return array<string, mixed> */
    public function defaults(Manifest $manifest): array
    {
        return $this->validateTenantConfig($manifest, []);
    }

    /** @param array<string, mixed> $definition */
    private function assertValue(array $definition, mixed $value): void
    {
        $type = $definition['type'];
        $valid = match ($type) {
            'string', 'secret' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'select' => $this->allowedOption($definition, $value),
            'multiselect' => is_array($value)
                && array_is_list($value)
                && array_reduce($value, fn (bool $ok, mixed $item): bool => $ok && $this->allowedOption($definition, $item), true),
            'json' => is_array($value),
            'url' => is_string($value)
                && filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true),
            default => false,
        };

        if (!$valid) {
            throw new ApiException(sprintf('模块配置项 %s 类型或值不合法。', $definition['key']), 422);
        }
    }

    /** @param array<string, mixed> $definition */
    private function allowedOption(array $definition, mixed $value): bool
    {
        foreach ($definition['options'] ?? [] as $option) {
            if (($option['value'] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $definition */
    private function isSensitive(array $definition): bool
    {
        return ($definition['sensitive'] ?? false) === true || ($definition['type'] ?? null) === 'secret';
    }
}
