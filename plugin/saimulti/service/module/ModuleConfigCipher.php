<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use JsonException;
use plugin\saimulti\exception\ApiException;

/**
 * Authenticated encryption for tenant-owned module configuration values.
 *
 * The AAD deliberately binds a ciphertext to one organization, module and
 * field. Moving an encrypted value between any of those boundaries therefore
 * fails authentication instead of silently exposing another tenant's secret.
 */
final class ModuleConfigCipher
{
    public const PREFIX = 'b8imcfg:v1:';

    private const CIPHER = 'aes-256-gcm';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    private const DERIVATION_CONTEXT = 'b8im/module-config/aes-256-gcm/v1';

    public function __construct(private readonly string $configuredKey)
    {
    }

    public function encryptValue(mixed $value, int $organization, string $moduleKey, string $field): string
    {
        try {
            $plaintext = json_encode(['value' => $value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ApiException('模块敏感配置无法安全序列化。', 422);
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->derivedKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad($organization, $moduleKey, $field),
            self::TAG_BYTES,
        );
        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
            throw new ApiException('模块敏感配置加密失败。', 500);
        }

        return self::PREFIX . $this->base64UrlEncode($nonce . $tag . $ciphertext);
    }

    public function decryptValue(string $envelope, int $organization, string $moduleKey, string $field): mixed
    {
        $payload = $this->decodeEnvelope($envelope);
        $nonce = substr($payload, 0, self::NONCE_BYTES);
        $tag = substr($payload, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($payload, self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->derivedKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad($organization, $moduleKey, $field),
        );
        if (!is_string($plaintext)) {
            throw new ApiException('模块敏感配置密文校验失败。', 500);
        }

        try {
            $decoded = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('模块敏感配置密文校验失败。', 500);
        }
        if (!is_array($decoded) || !array_key_exists('value', $decoded)) {
            throw new ApiException('模块敏感配置密文校验失败。', 500);
        }

        return $decoded['value'];
    }

    public function isEnvelope(mixed $value): bool
    {
        if (!is_string($value) || !str_starts_with($value, self::PREFIX)) {
            return false;
        }

        try {
            $this->decodeEnvelope($value);
            return true;
        } catch (ApiException) {
            return false;
        }
    }

    private function derivedKey(): string
    {
        $source = $this->keyMaterial();
        $derived = hash_hkdf('sha256', $source, 32, self::DERIVATION_CONTEXT);
        if (!is_string($derived) || strlen($derived) !== 32) {
            throw new ApiException('模块敏感配置密钥派生失败。', 500);
        }

        return $derived;
    }

    private function keyMaterial(): string
    {
        $configured = $this->configuredKey;
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if (!is_string($decoded)) {
                throw new ApiException('MODULE_CONFIG_ENCRYPTION_KEY 的 base64 格式无效。', 503);
            }
            $configured = $decoded;
        }
        if (strlen($configured) < 32) {
            throw new ApiException('MODULE_CONFIG_ENCRYPTION_KEY 必须至少包含 32 字节随机密钥材料。', 503);
        }

        return $configured;
    }

    private function aad(int $organization, string $moduleKey, string $field): string
    {
        if ($organization <= 0 || trim($moduleKey) === '' || trim($field) === '') {
            throw new ApiException('模块敏感配置加密上下文无效。', 500);
        }

        return self::PREFIX
            . "\norganization=" . $organization
            . "\nmodule_key=" . $moduleKey
            . "\nfield=" . $field;
    }

    private function decodeEnvelope(string $envelope): string
    {
        if (!str_starts_with($envelope, self::PREFIX)) {
            throw new ApiException('模块敏感配置密文格式无效。', 500);
        }
        $encoded = substr($envelope, strlen(self::PREFIX));
        if ($encoded === '') {
            throw new ApiException('模块敏感配置密文格式无效。', 500);
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $payload = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($payload) || strlen($payload) <= self::NONCE_BYTES + self::TAG_BYTES) {
            throw new ApiException('模块敏感配置密文格式无效。', 500);
        }

        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
