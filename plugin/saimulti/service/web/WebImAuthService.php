<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\OrganizationDiscovery;
use plugin\saimulti\service\WebTokenService;
use plugin\saimulti\service\trace\Telemetry;

final class WebImAuthService implements WebAccessIssuerInterface
{
    private WebImAuthStoreInterface $store;

    private WebTokenService $webTokens;

    private ImChallengeTokenService $imTokens;

    private Closure $clock;

    private WebImLoginRateLimiterInterface $loginRateLimiter;

    private WebImAvatarServiceInterface $avatars;

    private WebImPolicyGuard $policies;

    public function __construct(
        ?WebImAuthStoreInterface $store = null,
        ?WebTokenService $webTokens = null,
        ?ImChallengeTokenService $imTokens = null,
        ?Closure $clock = null,
        ?WebImLoginRateLimiterInterface $loginRateLimiter = null,
        ?WebImAvatarServiceInterface $avatars = null,
        ?WebImPolicyGuard $policies = null,
    ) {
        $this->store = $store ?? new ThinkOrmWebImAuthStore();
        $this->webTokens = $webTokens ?? new WebTokenService();
        $this->imTokens = $imTokens ?? new ImChallengeTokenService();
        $this->clock = $clock ?? static fn (): int => time();
        $this->loginRateLimiter = $loginRateLimiter ?? new RedisWebImLoginRateLimiter();
        $this->avatars = $avatars ?? new WebImAvatarService();
        $this->policies = $policies ?? new WebImPolicyGuard();
    }

    /**
     * @param array<string, mixed> $organization
     * @return array{organization: int, deployment_id: string, token: array<string, mixed>, user: array<string, mixed>}
     */
    public function login(
        array $organization,
        string $account,
        string $password,
        string $deviceId,
        string $clientFamily,
        string $os,
        string $clientIp,
    ): array {
        [$clientFamily, $os] = $this->clientRuntime($clientFamily, $os);
        return Telemetry::inSpan(
            'b8im.auth.client.login',
            'auth.client.login',
            [
                'b8im.auth.scope' => 'client',
                'b8im.organization' => (int) ($organization['id'] ?? 0),
                'b8im.client_family' => $clientFamily,
            ],
            fn (): array => $this->loginInternal(
                $organization,
                $account,
                $password,
                $deviceId,
                $clientFamily,
                $os,
                $clientIp,
            ),
        );
    }

    /** @param array<string, mixed> $organization @return array<string, mixed> */
    private function loginInternal(
        array $organization,
        string $account,
        string $password,
        string $deviceId,
        string $clientFamily,
        string $os,
        string $clientIp,
    ): array {
        $organizationId = (int) ($organization['id'] ?? 0);
        if ($organizationId <= 0) {
            throw new ApiException('当前应用不可用。', 41003);
        }
        $deploymentId = OrganizationDiscovery::assertDeploymentId(
            (string) ($organization['deployment_id'] ?? ''),
        );

        $now = $this->now();
        $loginAt = date('Y-m-d H:i:s', $now);
        $account = trim($account);
        $auditUserId = $this->auditSubject($organizationId, $account);
        $auditDeviceId = null;
        $auditIp = null;
        $failureCode = 'INVALID_LOGIN_INPUT';
        $user = null;

        try {
            $account = $this->account($account);
            $failureCode = 'INVALID_DEVICE_ID';
            $auditDeviceId = $this->identifier($deviceId, 'device_id', 100);
            $auditIp = $this->ip($clientIp);

            $failureCode = 'TENANT_IM_POLICY_FORBIDDEN';
            $this->policies->assertAllowed($organizationId, $clientFamily);

            $failureCode = 'LOGIN_RATE_LIMITED';
            $this->loginRateLimiter->assertAllowed($organizationId, $account, $auditIp);

            $failureCode = 'INVALID_CREDENTIALS';
            $user = $this->store->findActiveLoginUser($organizationId, $account);
            if ($user !== null && trim((string) ($user['user_id'] ?? '')) !== '') {
                $auditUserId = (string) $user['user_id'];
            }
            $passwordHash = (string) ($user['password_hash'] ?? '');
            if (
                $user === null
                || $password === ''
                || strlen($password) > 4096
                || $passwordHash === ''
                || !password_verify($password, $passwordHash)
            ) {
                throw new ApiException('账号或密码错误。', 401);
            }

            $failureCode = 'LOGIN_RATE_LIMITER_UNAVAILABLE';
            $this->loginRateLimiter->resetAccountAttempts($organizationId, $account);

            $failureCode = 'WEB_TOKEN_ISSUE_FAILED';
            return $this->issueAccessForUser(
                $organization,
                $user,
                $auditDeviceId,
                $clientFamily,
                $os,
                $auditIp,
                'password',
                $now,
            );
        } catch (ApiException $exception) {
            if ($exception->getCode() === RedisWebImLoginRateLimiter::RATE_LIMITED) {
                $failureCode = 'LOGIN_RATE_LIMITED';
            } elseif ($exception->getCode() === RedisWebImLoginRateLimiter::UNAVAILABLE) {
                $failureCode = 'LOGIN_RATE_LIMITER_UNAVAILABLE';
            }
            $this->store->recordLoginAudit($this->loginAudit(
                $organizationId,
                $auditUserId,
                $auditDeviceId,
                $auditIp,
                $loginAt,
                $clientFamily,
                $os,
                'failed',
                'password',
                $failureCode,
            ));
            throw $exception;
        }
    }

    public function issueAccessForUser(
        array $organization,
        array $user,
        string $deviceId,
        string $clientFamily,
        string $os,
        string $clientIp,
        string $auditScope,
        ?int $now = null,
    ): array {
        $organizationId = (int) ($organization['id'] ?? 0);
        if ($organizationId <= 0 || (int) ($user['id'] ?? 0) <= 0) {
            throw new ApiException('客户端登录身份无效。', 401);
        }
        $deploymentId = OrganizationDiscovery::assertDeploymentId((string) ($organization['deployment_id'] ?? ''));
        [$clientFamily, $os] = $this->clientRuntime($clientFamily, $os);
        $deviceId = $this->identifier($deviceId, 'device_id', 100);
        $clientIp = $this->ip($clientIp);
        $auditScope = $this->auditScope($auditScope);
        $this->policies->assertAllowed($organizationId, $clientFamily);
        $now ??= $this->now();
        $loginAt = date('Y-m-d H:i:s', $now);
        $issued = $this->webTokens->issueAccessSession(
            $user,
            $organizationId,
            $deploymentId,
            $deviceId,
            $clientFamily,
            $os,
            $now,
        );
        $claims = $issued['claims'];
        $this->store->recordSuccessfulLogin(
            $organizationId,
            (int) $user['id'],
            $loginAt,
            $clientFamily,
            $this->loginAudit(
                $organizationId,
                (string) $user['user_id'],
                $deviceId,
                $clientIp,
                $loginAt,
                $clientFamily,
                $os,
                'success',
                $auditScope,
                null,
            ),
            [
                'organization' => $organizationId,
                'jti' => (string) $claims['jti'],
                'im_user_id' => (int) $user['id'],
                'user_id' => (string) $user['user_id'],
                'device_id' => $deviceId,
                'status' => 1,
                'expire_at' => date('Y-m-d H:i:s', (int) $claims['exp']),
                'revoked_at' => null,
                'create_time' => $loginAt,
                'update_time' => $loginAt,
            ],
        );
        $user['login_time'] = $loginAt;

        return [
            'organization' => $organizationId,
            'deployment_id' => $deploymentId,
            'token' => $issued['token'],
            'user' => $this->userView($organizationId, $user),
        ];
    }

    public function recordLoginEvent(
        int $organization,
        string $userId,
        ?string $deviceId,
        ?string $loginIp,
        string $clientFamily,
        string $os,
        string $result,
        string $auditScope,
        ?string $failureCode = null,
        ?int $now = null,
    ): void {
        if ($organization <= 0) {
            throw new ApiException('登录审计机构无效。', 422);
        }
        $userId = $this->identifier($userId, 'user_id', 64);
        if ($deviceId !== null) {
            $deviceId = $this->identifier($deviceId, 'device_id', 100);
        }
        if ($loginIp !== null) {
            $loginIp = $this->ip($loginIp);
        }
        [$clientFamily, $os] = $this->clientRuntime($clientFamily, $os);
        if (!in_array($result, ['success', 'failed', 'confirmed'], true)) {
            throw new ApiException('登录审计结果无效。', 422);
        }
        $auditScope = $this->auditScope($auditScope);
        $now ??= $this->now();
        $this->store->recordLoginAudit($this->loginAudit(
            $organization,
            $userId,
            $deviceId,
            $loginIp,
            date('Y-m-d H:i:s', $now),
            $clientFamily,
            $os,
            $result,
            $auditScope,
            $failureCode,
        ));
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{token: string, expire_at: int, device_id: string, client_id: string}
     */
    public function issueImToken(
        array $identity,
        string $deviceId,
        string $clientId,
        string $clientIp,
    ): array {
        $clientFamily = (string) ($identity['client_family'] ?? '');
        return Telemetry::inSpan(
            'b8im.auth.im_token.issue',
            'auth.im_token.issue',
            [
                'b8im.auth.scope' => 'im',
                'b8im.organization' => (int) ($identity['organization'] ?? 0),
                'b8im.client_family' => $clientFamily,
            ],
            fn (): array => $this->issueImTokenInternal($identity, $deviceId, $clientId, $clientIp),
        );
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    private function issueImTokenInternal(
        array $identity,
        string $deviceId,
        string $clientId,
        string $clientIp,
    ): array {
        [$organization, $id, $userId, $deploymentId] = $this->identity($identity);
        [$clientFamily, $os] = $this->clientRuntime(
            (string) ($identity['client_family'] ?? ''),
            (string) ($identity['os'] ?? ''),
            401,
        );
        $this->policies->assertAllowed($organization, $clientFamily);
        $requestedDeviceId = $this->identifier($deviceId, 'device_id', 100);
        $deviceId = $this->identifier((string) ($identity['device_id'] ?? ''), 'device_id', 100, 401);
        if (!hash_equals($deviceId, $requestedDeviceId)) {
            throw new ApiException('device_id 与客户端登录会话不一致。', 401);
        }
        $accessJti = trim((string) ($identity['web_access_jti'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/', $accessJti) !== 1) {
            throw new ApiException('客户端登录会话标识无效。', 401);
        }
        $clientId = $this->identifier($clientId, 'client_id', 120);
        $clientIp = $this->ip($clientIp);
        $user = $this->store->findActiveUser($organization, $id, $userId);
        if ($user === null || (int) ($user['is_system'] ?? 1) !== 2) {
            throw new ApiException('客户端用户已停用或不存在。', 401);
        }

        $accessExpireAt = $identity['token_exp'] ?? null;
        if (!is_int($accessExpireAt)) {
            throw new ApiException('客户端登录上下文缺少有效期。', 401);
        }
        $now = $this->now();
        $credential = $this->imTokens->issue([
            'organization' => $organization,
            'deployment_id' => $deploymentId,
            'user_id' => (string) $user['user_id'],
            'device_id' => $deviceId,
            'client_id' => $clientId,
            'client_family' => $clientFamily,
            'os' => $os,
            'username' => (string) $user['account'],
        ], $accessExpireAt, $now);

        $nowText = date('Y-m-d H:i:s', $now);
        $expireAtText = date('Y-m-d H:i:s', $credential['expire_at']);
        $this->store->upsertChallenge([
            'organization' => $organization,
            'user_id' => (string) $user['user_id'],
            'device_id' => $deviceId,
            'client_family' => $clientFamily,
            'os' => $os,
            'current_ip' => $clientIp,
            'last_login_ip' => $clientIp,
            'last_login_at' => $nowText,
            'last_seen_at' => $nowText,
            'current_online_state' => 2,
            'status' => 1,
            'create_time' => $nowText,
            'update_time' => $nowText,
        ], [
            'organization' => $organization,
            'user_id' => (string) $user['user_id'],
            'device_id' => $deviceId,
            'client_id' => $clientId,
            'session_id' => $credential['session_id'],
            // IM 仅对 Web 凭证做持续 access-session 绑定；App challenge
            // 已在本事务中校验 access session，持久化非空值会被 IM 拒绝。
            'web_access_jti' => $clientFamily === 'web' ? $accessJti : null,
            'status' => 1,
            'expire_at' => $expireAtText,
            'revoked_at' => null,
            'create_time' => $nowText,
            'update_time' => $nowText,
        ], [
            'organization' => $organization,
            'jti' => $accessJti,
            'im_user_id' => $id,
            'user_id' => (string) $user['user_id'],
            'device_id' => $deviceId,
            'token_exp' => $accessExpireAt,
            'now' => $now,
        ]);

        return [
            'token' => $credential['token'],
            'expire_at' => $credential['expire_at'],
            'device_id' => $deviceId,
            'client_id' => $clientId,
        ];
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    public function me(array $identity): array
    {
        [$organization, $id, $userId] = $this->identity($identity);
        $user = $this->store->findActiveUser($organization, $id, $userId);
        if ($user === null) {
            throw new ApiException('客户端用户已停用或不存在。', 401);
        }

        return $this->userView($organization, $user);
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    public function updateAvatar(array $identity, string $avatarFileId): array
    {
        [$organization, $id, $userId] = $this->identity($identity);
        $user = $this->store->findActiveUser($organization, $id, $userId);
        if ($user === null || (int) ($user['is_system'] ?? 1) !== 2) {
            throw new ApiException('客户端用户已停用或不存在。', 401);
        }
        $avatarFileId = $this->avatars->assertOwnedImage($organization, $userId, $avatarFileId);
        $this->store->updateAvatar(
            $organization,
            $id,
            $userId,
            $avatarFileId,
            date('Y-m-d H:i:s', $this->now()),
        );
        $updated = $this->store->findActiveUser($organization, $id, $userId);
        if ($updated === null || !hash_equals($avatarFileId, (string) ($updated['avatar'] ?? ''))) {
            throw new \RuntimeException('Client avatar update was not persisted.');
        }

        return $this->userView($organization, $updated);
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{int, int, string, string}
     */
    private function identity(array $identity): array
    {
        $organization = (int) ($identity['organization'] ?? 0);
        $id = (int) ($identity['id'] ?? 0);
        if ($organization <= 0 || $id <= 0) {
            throw new ApiException('客户端登录上下文无效。', 401);
        }
        $userId = $this->identifier((string) ($identity['user_id'] ?? ''), 'user_id', 64, 401);
        $deploymentId = OrganizationDiscovery::assertDeploymentId(
            (string) ($identity['deployment_id'] ?? ''),
        );

        return [$organization, $id, $userId, $deploymentId];
    }

    private function account(string $account): string
    {
        if (
            $account === ''
            || mb_strlen($account) > 64
            || preg_match('/[\x00-\x1F\x7F]/u', $account) === 1
        ) {
            throw new ApiException('账号格式无效。', 422);
        }

        return $account;
    }

    private function identifier(string $value, string $name, int $maxLength, int $code = 422): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/', $value) !== 1
        ) {
            throw new ApiException($name . ' 格式无效。', $code);
        }

        return $value;
    }

    private function ip(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || strlen($ip) > 45) {
            throw new ApiException('客户端 IP 无效。', 422);
        }

        return $ip;
    }

    /** @return array<string, mixed> */
    private function userView(int $organization, array $user): array
    {
        $status = (int) ($user['status'] ?? 0);
        $fileId = trim((string) ($user['avatar'] ?? ''));
        $avatar = $fileId === ''
            ? ['avatar_file_id' => '', 'avatar_url' => '', 'avatar_expires_at' => 0]
            : $this->avatars->project($organization, $fileId);

        return array_merge([
            'id' => (string) ($user['id'] ?? ''),
            'user_id' => (string) ($user['user_id'] ?? ''),
            'account' => (string) ($user['account'] ?? ''),
            'nickname' => (string) ($user['nickname'] ?? ''),
            'signature' => (string) ($user['signature'] ?? ''),
            'mobile' => (string) ($user['mobile'] ?? ''),
            'im_short_no' => (string) ($user['im_short_no'] ?? ''),
            'gender' => (int) ($user['gender'] ?? 0),
            'status' => $status,
            'status_text' => match ($status) {
                2 => '停用',
                3 => '封禁',
                default => '正常',
            },
            'remark' => (string) ($user['remark'] ?? ''),
            'login_time' => (string) ($user['login_time'] ?? ''),
            'relation_status' => 'none',
            'is_system' => (int) ($user['is_system'] ?? 2) === 1,
            'system_code' => (string) ($user['system_code'] ?? ''),
        ], $avatar);
    }

    /** @return array<string, mixed> */
    private function loginAudit(
        int $organization,
        string $userId,
        ?string $deviceId,
        ?string $loginIp,
        string $loginAt,
        string $clientFamily,
        string $os,
        string $result,
        string $auditScope,
        ?string $failureCode,
    ): array {
        return [
            'organization' => $organization,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'client_id' => null,
            'client_family' => $clientFamily,
            'os' => $os,
            'device_name' => null,
            'device_model' => null,
            'os_version' => null,
            'app_version' => null,
            'login_ip' => $loginIp,
            'login_ip_geo' => null,
            'login_at' => $loginAt,
            'logout_at' => null,
            'login_result' => $result,
            'audit_scope' => $auditScope,
            'current_online_state' => 2,
            'failure_code' => $failureCode,
            'create_time' => $loginAt,
        ];
    }

    private function auditScope(string $scope): string
    {
        $scope = trim($scope);
        if (!in_array($scope, ['password', 'register', 'qr_login'], true)) {
            throw new ApiException('登录审计方式无效。', 422);
        }

        return $scope;
    }

    private function auditSubject(int $organization, string $account): string
    {
        return 'attempt_' . substr(hash('sha256', $organization . ':' . mb_strtolower($account)), 0, 40);
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if ($now <= 0) {
            throw new \RuntimeException('Client IM clock returned an invalid timestamp.');
        }

        return $now;
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
