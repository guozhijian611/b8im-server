<?php

declare(strict_types=1);

namespace plugin\saimulti\service\routing;

use plugin\saimulti\exception\ApiException;

final class RoutingSnapshotSigner
{
    private string $secretKey;

    public function __construct(
        ?string $encodedPrivateKey = null,
        private readonly string $kid = '',
    ) {
        if (!extension_loaded('sodium')) {
            throw new ApiException('线路签名需要 PHP sodium 扩展。', 50302);
        }
        $encodedPrivateKey ??= (string) env('ROUTING_SIGNING_PRIVATE_KEY', '');
        $decoded = self::decodeBase64Url($encodedPrivateKey);
        if (strlen($decoded) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $decoded = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($decoded));
        }
        if (strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ApiException('ROUTING_SIGNING_PRIVATE_KEY 必须是 Ed25519 seed 或 secret key。', 50302);
        }
        $this->secretKey = $decoded;
    }

    /** @return array{alg: string, kid: string, canonicalization: string, value: string} */
    public function sign(array $payload): array
    {
        $kid = $this->kid !== '' ? $this->kid : (string) env('ROUTING_SIGNING_KID', '');
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $kid)) {
            throw new ApiException('ROUTING_SIGNING_KID 格式无效。', 50302);
        }
        $signature = sodium_crypto_sign_detached(CanonicalJson::encode($payload), $this->secretKey);

        return [
            'alg' => 'Ed25519',
            'kid' => $kid,
            'canonicalization' => 'JCS-RFC8785',
            'value' => self::encodeBase64Url($signature),
        ];
    }

    public function publicKey(): string
    {
        return self::encodeBase64Url(sodium_crypto_sign_publickey_from_secretkey($this->secretKey));
    }

    private static function decodeBase64Url(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }

    private static function encodeBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
