<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Firebase\JWT\JWT;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\OrganizationDiscovery;

final class ImChallengeTokenService
{
    private string $secret;

    private int $ttlSeconds;

    public function __construct(?string $secret = null, ?int $ttlSeconds = null)
    {
        $this->secret = $secret ?? (string) (getenv('IM_TOKEN_SECRET') ?: '');
        $normalizedSecret = strtolower(trim($this->secret));
        if (
            strlen($this->secret) < 32
            || str_contains($normalizedSecret, 'please-change')
            || str_contains($normalizedSecret, 'change-me')
            || str_contains($normalizedSecret, 'example-secret')
        ) {
            throw new \RuntimeException('IM_TOKEN_SECRET must be a non-placeholder secret of at least 32 bytes.');
        }

        $this->ttlSeconds = $ttlSeconds ?? (int) env('IM_TOKEN_TTL_SECONDS', 300);
        if ($this->ttlSeconds < 30 || $this->ttlSeconds > 86400) {
            throw new \RuntimeException('IM_TOKEN_TTL_SECONDS must be between 30 and 86400 seconds.');
        }
    }

    /**
     * @param array{organization: int, deployment_id: string, user_id: string, device_id: string, client_id: string, client_family: string, os: string, username: string} $identity
     * @return array{token: string, expire_at: int, session_id: string, claims: array<string, mixed>}
     */
    public function issue(array $identity, int $accessExpireAt, int $now): array
    {
        $organization = (int) ($identity['organization'] ?? 0);
        if ($organization <= 0) {
            throw new ApiException('IM 凭证机构无效。', 401);
        }

        $deploymentId = OrganizationDiscovery::assertDeploymentId(
            (string) ($identity['deployment_id'] ?? ''),
        );
        $userId = $this->identifier((string) ($identity['user_id'] ?? ''), 'user_id', 64);
        $deviceId = $this->identifier((string) ($identity['device_id'] ?? ''), 'device_id', 100);
        $clientId = $this->identifier((string) ($identity['client_id'] ?? ''), 'client_id', 120);
        [$clientFamily, $os] = $this->clientRuntime(
            (string) ($identity['client_family'] ?? ''),
            (string) ($identity['os'] ?? ''),
        );
        $username = trim((string) ($identity['username'] ?? ''));
        if ($username === '' || mb_strlen($username) > 64) {
            throw new ApiException('IM 凭证用户名无效。', 401);
        }
        if ($now <= 0 || $accessExpireAt <= $now) {
            throw new ApiException('客户端登录会话已过期。', 401);
        }

        $expireAt = min($now + $this->ttlSeconds, $accessExpireAt);
        if ($expireAt <= $now) {
            throw new ApiException('客户端登录会话剩余有效期不足。', 401);
        }

        $credentialSessionId = bin2hex(random_bytes(16));
        $claims = [
            'iss' => $deploymentId,
            'aud' => 'im',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expireAt,
            'deployment_id' => $deploymentId,
            'organization' => $organization,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'client_id' => $clientId,
            'session_id' => $credentialSessionId,
            'client_family' => $clientFamily,
            'os' => $os,
            'username' => $username,
        ];

        return [
            'token' => JWT::encode($claims, $this->secret, 'HS256'),
            'expire_at' => $expireAt,
            'session_id' => $credentialSessionId,
            'claims' => $claims,
        ];
    }

    private function identifier(string $value, string $name, int $maxLength): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/', $value) !== 1
        ) {
            throw new ApiException($name . ' 格式无效。', 422);
        }

        return $value;
    }

    /** @return array{0: string, 1: string} */
    private function clientRuntime(string $clientFamily, string $os): array
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
            throw new ApiException('IM 凭证 client_family 与 os 组合无效。', 422);
        }

        return [$clientFamily, $os];
    }
}
