<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\OrganizationDiscovery;
use plugin\saimulti\service\WebTokenService;
use plugin\saimulti\service\trace\Telemetry;

final class WebImAuthService
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
        string $clientIp,
    ): array {
        return Telemetry::inSpan(
            'b8im.auth.web.login',
            'auth.web.login',
            [
                'b8im.auth.scope' => 'web',
                'b8im.organization' => (int) ($organization['id'] ?? 0),
            ],
            fn (): array => $this->loginInternal(
                $organization,
                $account,
                $password,
                $deviceId,
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
            $this->policies->assertWebAllowed($organizationId);

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

            $failureCode = 'WEB_TOKEN_ISSUE_FAILED';
            $issued = $this->webTokens->issueAccessSession(
                $user,
                $organizationId,
                $deploymentId,
                $auditDeviceId,
                $now,
            );
            $claims = $issued['claims'];
            $this->store->recordSuccessfulLogin(
                $organizationId,
                (int) $user['id'],
                $loginAt,
                $this->loginAudit(
                    $organizationId,
                    (string) $user['user_id'],
                    $auditDeviceId,
                    $auditIp,
                    $loginAt,
                    'success',
                    null,
                ),
                [
                    'organization' => $organizationId,
                    'jti' => (string) $claims['jti'],
                    'im_user_id' => (int) $user['id'],
                    'user_id' => (string) $user['user_id'],
                    'device_id' => $auditDeviceId,
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
                'failed',
                $failureCode,
            ));
            throw $exception;
        }
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
        return Telemetry::inSpan(
            'b8im.auth.im_token.issue',
            'auth.im_token.issue',
            [
                'b8im.auth.scope' => 'im',
                'b8im.organization' => (int) ($identity['organization'] ?? 0),
                'b8im.client_family' => 'web',
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
        $this->policies->assertWebAllowed($organization);
        $requestedDeviceId = $this->identifier($deviceId, 'device_id', 100);
        $deviceId = $this->identifier((string) ($identity['device_id'] ?? ''), 'device_id', 100, 401);
        if (!hash_equals($deviceId, $requestedDeviceId)) {
            throw new ApiException('device_id 与 Web 登录会话不一致。', 401);
        }
        $webAccessJti = trim((string) ($identity['web_access_jti'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/', $webAccessJti) !== 1) {
            throw new ApiException('Web 登录会话标识无效。', 401);
        }
        $clientId = $this->identifier($clientId, 'client_id', 120);
        $clientIp = $this->ip($clientIp);
        $user = $this->store->findActiveUser($organization, $id, $userId);
        if ($user === null || (int) ($user['is_system'] ?? 1) !== 2) {
            throw new ApiException('Web 用户已停用或不存在。', 401);
        }

        $accessExpireAt = $identity['token_exp'] ?? null;
        if (!is_int($accessExpireAt)) {
            throw new ApiException('Web 登录上下文缺少有效期。', 401);
        }
        $now = $this->now();
        $credential = $this->imTokens->issue([
            'organization' => $organization,
            'deployment_id' => $deploymentId,
            'user_id' => (string) $user['user_id'],
            'device_id' => $deviceId,
            'client_id' => $clientId,
            'username' => (string) $user['account'],
        ], $accessExpireAt, $now);

        $nowText = date('Y-m-d H:i:s', $now);
        $expireAtText = date('Y-m-d H:i:s', $credential['expire_at']);
        $this->store->upsertChallenge([
            'organization' => $organization,
            'user_id' => (string) $user['user_id'],
            'device_id' => $deviceId,
            'client_family' => 'web',
            'os' => 'browser',
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
            'web_access_jti' => $webAccessJti,
            'status' => 1,
            'expire_at' => $expireAtText,
            'revoked_at' => null,
            'create_time' => $nowText,
            'update_time' => $nowText,
        ], [
            'organization' => $organization,
            'jti' => $webAccessJti,
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
            throw new ApiException('Web 用户已停用或不存在。', 401);
        }

        return $this->userView($organization, $user);
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    public function updateAvatar(array $identity, string $avatarFileId): array
    {
        [$organization, $id, $userId] = $this->identity($identity);
        $user = $this->store->findActiveUser($organization, $id, $userId);
        if ($user === null || (int) ($user['is_system'] ?? 1) !== 2) {
            throw new ApiException('Web 用户已停用或不存在。', 401);
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
            throw new \RuntimeException('Web avatar update was not persisted.');
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
            throw new ApiException('Web 登录上下文无效。', 401);
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
        string $result,
        ?string $failureCode,
    ): array {
        return [
            'organization' => $organization,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'client_id' => null,
            'client_family' => 'web',
            'os' => 'browser',
            'device_name' => null,
            'device_model' => null,
            'os_version' => null,
            'app_version' => null,
            'login_ip' => $loginIp,
            'login_ip_geo' => null,
            'login_at' => $loginAt,
            'logout_at' => null,
            'login_result' => $result,
            'audit_scope' => 'login',
            'current_online_state' => 2,
            'failure_code' => $failureCode,
            'create_time' => $loginAt,
        ];
    }

    private function auditSubject(int $organization, string $account): string
    {
        return 'attempt_' . substr(hash('sha256', $organization . ':' . mb_strtolower($account)), 0, 40);
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if ($now <= 0) {
            throw new \RuntimeException('Web IM clock returned an invalid timestamp.');
        }

        return $now;
    }
}
