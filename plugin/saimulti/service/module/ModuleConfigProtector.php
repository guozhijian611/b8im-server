<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Manifest\Manifest;
use plugin\saimulti\exception\ApiException;

final class ModuleConfigProtector
{
    public function __construct(
        private readonly ModuleConfigValidator $validator,
        private readonly ModuleConfigCipher $cipher,
    ) {
    }

    /**
     * @param array<string, mixed> $stored
     * @return array{values: array<string, mixed>, configured: array<string, bool>}
     */
    public function publicProjection(Manifest $manifest, array $stored): array
    {
        $values = [];
        $configured = [];
        foreach ($this->tenantDefinitions($manifest) as $key => $definition) {
            if ($this->isSensitive($definition)) {
                // The API never returns a secret, including a decrypted value,
                // an encrypted envelope, or a secret default from the manifest.
                $values[$key] = '';
                $configured[$key] = $this->cipher->isEnvelope($stored[$key] ?? null);
                continue;
            }
            if (array_key_exists($key, $stored)) {
                $values[$key] = $stored[$key];
            } elseif (array_key_exists('default', $definition)) {
                $values[$key] = $definition['default'];
            }
        }
        ksort($values);
        ksort($configured);

        return ['values' => $values, 'configured' => $configured];
    }

    /** @return list<array<string, mixed>> */
    public function publicSchema(Manifest $manifest): array
    {
        $schema = [];
        foreach ($this->tenantDefinitions($manifest) as $definition) {
            if ($this->isSensitive($definition)) {
                unset($definition['default']);
            }
            $schema[] = $definition;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    public function sanitizedManifestData(Manifest $manifest): array
    {
        $data = $manifest->toArray();
        $data['config'] = array_map(function (array $definition): array {
            if ($this->isSensitive($definition)) {
                unset($definition['default']);
            }

            return $definition;
        }, $manifest->config());

        return $data;
    }

    /**
     * Returns plaintext only for trusted Server-side consumers. Authentication
     * failure, a copied ciphertext or a missing/weak key always fails closed.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public function internalValues(
        Manifest $manifest,
        array $stored,
        int $organization,
        string $moduleKey,
    ): array {
        return $this->validator->validateTenantConfig(
            $manifest,
            [],
            $this->decodeStoredValues($manifest, $stored, $organization, $moduleKey),
        );
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, true> $explicitReplacements
     * @return array<string, mixed>
     */
    private function decodeStoredValues(
        Manifest $manifest,
        array $stored,
        int $organization,
        string $moduleKey,
        array $explicitReplacements = [],
    ): array {
        $current = [];
        foreach ($this->tenantDefinitions($manifest) as $key => $definition) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }
            if ($this->isSensitive($definition)) {
                if (isset($explicitReplacements[$key]) && !$this->cipher->isEnvelope($stored[$key])) {
                    // Development versions do not decrypt or preserve legacy
                    // plaintext. A tenant may only recover by explicitly
                    // supplying a replacement, which will be encrypted below.
                    continue;
                }
                if (!is_string($stored[$key])) {
                    throw new ApiException('模块敏感配置存储格式无效。', 500);
                }
                $current[$key] = $this->cipher->decryptValue($stored[$key], $organization, $moduleKey, $key);
            } else {
                $current[$key] = $stored[$key];
            }
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public function prepareForPersistence(
        Manifest $manifest,
        array $input,
        array $stored,
        int $organization,
        string $moduleKey,
    ): array {
        $definitions = $this->tenantDefinitions($manifest);
        $effectiveInput = $input;
        $explicitReplacements = [];
        foreach ($input as $key => $value) {
            if (isset($definitions[$key])
                && $this->isSensitive($definitions[$key])
                && $this->isBlankSecret($value)) {
                // An empty password field means "keep the existing secret".
                unset($effectiveInput[$key]);
            } elseif (isset($definitions[$key]) && $this->isSensitive($definitions[$key])) {
                $explicitReplacements[$key] = true;
            }
        }
        $current = $this->decodeStoredValues(
            $manifest,
            $stored,
            $organization,
            $moduleKey,
            $explicitReplacements,
        );

        $validated = $this->validator->validateTenantConfig($manifest, $effectiveInput, $current);
        $persisted = [];
        foreach ($definitions as $key => $definition) {
            if (!array_key_exists($key, $validated)) {
                continue;
            }
            if (!$this->isSensitive($definition)) {
                $persisted[$key] = $validated[$key];
                continue;
            }

            $replace = array_key_exists($key, $effectiveInput);
            if ($replace) {
                $persisted[$key] = $this->cipher->encryptValue(
                    $validated[$key],
                    $organization,
                    $moduleKey,
                    $key,
                );
            } elseif (array_key_exists($key, $stored)) {
                // Preserve the exact authenticated envelope so a blank update
                // never causes a secret rotation or accidental erasure.
                $persisted[$key] = $stored[$key];
            }
        }
        ksort($persisted);

        return $persisted;
    }

    /** @param array<string, mixed> $definition */
    public function isSensitive(array $definition): bool
    {
        return ($definition['sensitive'] ?? false) === true || ($definition['type'] ?? null) === 'secret';
    }

    /** @return array<string, array<string, mixed>> */
    private function tenantDefinitions(Manifest $manifest): array
    {
        $definitions = [];
        foreach ($manifest->config() as $definition) {
            if (($definition['scope'] ?? null) !== 'tenant') {
                continue;
            }
            $key = (string) ($definition['key'] ?? '');
            if ($key === '') {
                throw new ApiException('模块配置 schema 缺少 key。', 500);
            }
            $definitions[$key] = $definition;
        }

        return $definitions;
    }

    private function isBlankSecret(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
