<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use plugin\saimulti\exception\ApiException;
use Throwable;

final class WebTokenService
{
    private string $secret;

    private string $algorithm;

    public function __construct(?string $secret = null, ?string $algorithm = null)
    {
        $config = (array) config('plugin.tinywan.jwt.app.jwt', []);
        $this->secret = $secret ?? (string) ($config['access_secret_key'] ?? '');
        $this->algorithm = $algorithm ?? (string) ($config['algorithms'] ?? 'HS256');
        if (strlen($this->secret) < 32) {
            throw new \RuntimeException('Web JWT access secret must contain at least 32 bytes.');
        }
        if (!in_array($this->algorithm, ['HS256', 'HS384', 'HS512'], true)) {
            throw new \RuntimeException('Web JWT currently requires an HMAC algorithm.');
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    public function issueAccess(
        array $user,
        int $organization,
        string $deploymentId,
        string $deviceId,
        string $clientFamily,
        string $os,
    ): array
    {
        return $this->issueAccessSession(
            $user,
            $organization,
            $deploymentId,
            $deviceId,
            $clientFamily,
            $os,
        )['token'];
    }

    /**
     * Returns claims only to the server-side login transaction. Callers must
     * never include them (especially jti) in an API response or log record.
     *
     * @param array<string, mixed> $user
     * @return array{token: array{token_type: string, expires_in: int, access_token: string, refresh_token: string}, claims: array<string, mixed>}
     */
    public function issueAccessSession(
        array $user,
        int $organization,
        string $deploymentId,
        string $deviceId,
        string $clientFamily,
        string $os,
        ?int $now = null,
    ): array {
        if ($organization <= 0 || (int) ($user['id'] ?? 0) <= 0) {
            throw new ApiException('客户端登录身份无效。', 401);
        }
        $userId = $this->requiredIdentifier((string) ($user['user_id'] ?? ''), 'user_id', 64);
        $deviceId = $this->requiredIdentifier($deviceId, 'device_id', 100);
        [$clientFamily, $os] = $this->clientRuntime($clientFamily, $os);
        $deploymentId = OrganizationDiscovery::assertDeploymentId($deploymentId);
        $ttl = max(300, min(604800, (int) env('WEB_ACCESS_TOKEN_TTL_SECONDS', 7200)));
        $now ??= time();
        if ($now <= 0) {
            throw new \RuntimeException('Web token clock is invalid.');
        }
        $payload = [
            'iss' => $deploymentId,
            'aud' => $clientFamily . '-api',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
            'deployment_id' => $deploymentId,
            'organization' => $organization,
            'id' => (int) $user['id'],
            'user_id' => $userId,
            'account' => (string) ($user['account'] ?? ''),
            'device_id' => $deviceId,
            'client_family' => $clientFamily,
            'os' => $os,
        ];

        return [
            'token' => [
                'token_type' => 'Bearer',
                'expires_in' => $ttl,
                'access_token' => JWT::encode($payload, $this->secret, $this->algorithm),
                'refresh_token' => '',
            ],
            'claims' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    public function verifyAccess(
        string $token,
        int $expectedOrganization,
        string $expectedDeploymentId,
        string $expectedClientFamily,
        ?int $now = null,
    ): array {
        [$expectedClientFamily] = $this->clientRuntime(
            $expectedClientFamily,
            $expectedClientFamily === 'web' ? 'browser' : 'other',
        );
        try {
            JWT::$leeway = 60;
            $payload = (array) JWT::decode($token, new Key($this->secret, $this->algorithm));
        } catch (Throwable) {
            throw new ApiException('客户端登录凭证无效或已过期。', 401);
        }

        $now ??= time();
        $issuer = $this->requiredStringClaim($payload, 'iss');
        $deploymentId = $this->requiredStringClaim($payload, 'deployment_id');
        $organization = $this->requiredIntegerClaim($payload, 'organization');
        $issuedAt = $this->requiredIntegerClaim($payload, 'iat');
        $notBefore = $this->requiredIntegerClaim($payload, 'nbf');
        $expireAt = $this->requiredIntegerClaim($payload, 'exp');
        $this->requiredStringClaim($payload, 'jti');
        $this->requiredStringClaim($payload, 'user_id');
        $this->requiredStringClaim($payload, 'device_id');
        $clientFamily = $this->requiredStringClaim($payload, 'client_family');
        $os = $this->requiredStringClaim($payload, 'os');
        $this->clientRuntime($clientFamily, $os, 401);
        if (
            $expectedOrganization <= 0
            || $organization !== $expectedOrganization
            || !hash_equals($expectedDeploymentId, $issuer)
            || !hash_equals($expectedDeploymentId, $deploymentId)
            || !hash_equals($expectedClientFamily, $clientFamily)
            || !$this->hasAudience($payload['aud'] ?? null, $expectedClientFamily . '-api')
            || $issuedAt <= 0
            || $notBefore > $now + 60
            || $expireAt <= $now
            || $expireAt <= $notBefore
        ) {
            throw new ApiException('客户端登录凭证与当前部署、机构或客户端形态不一致。', 401);
        }
        if ($this->requiredIntegerClaim($payload, 'id') <= 0) {
            throw new ApiException('客户端登录凭证身份无效。', 401);
        }

        return $payload;
    }

    public function extractBearer(mixed $authorization): string
    {
        if (!is_string($authorization) || preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/', $authorization, $match) !== 1) {
            throw new ApiException('请求未携带有效的客户端登录凭证。', 401);
        }

        return $match[1];
    }

    private function requiredIdentifier(string $value, string $name, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/', $value) !== 1) {
            throw new ApiException($name . ' 格式无效。', 422);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredStringClaim(array $payload, string $claim): string
    {
        if (!isset($payload[$claim]) || !is_string($payload[$claim]) || trim($payload[$claim]) === '') {
            throw new ApiException('客户端登录凭证缺少必要声明。', 401);
        }

        return trim($payload[$claim]);
    }

    /** @param array<string, mixed> $payload */
    private function requiredIntegerClaim(array $payload, string $claim): int
    {
        if (!isset($payload[$claim]) || !is_int($payload[$claim])) {
            throw new ApiException('客户端登录凭证缺少必要声明。', 401);
        }

        return $payload[$claim];
    }

    private function hasAudience(mixed $claim, string $expected): bool
    {
        if (is_string($claim)) {
            return hash_equals($expected, $claim);
        }
        if (!is_array($claim)) {
            return false;
        }

        return in_array($expected, $claim, true);
    }

    /** @return array{0: string, 1: string} */
    private function clientRuntime(string $clientFamily, string $os, int $errorCode = 422): array
    {
        $clientFamily = trim($clientFamily);
        $os = trim($os);
        $valid = match ($clientFamily) {
            'web' => $os === 'browser',
            'app' => in_array($os, ['android', 'ios', 'other'], true),
            'desktop' => in_array($os, ['windows', 'macos', 'linux', 'other'], true),
            default => false,
        };
        if (!$valid) {
            throw new ApiException('client_family 与 os 组合无效。', $errorCode);
        }

        return [$clientFamily, $os];
    }
}
