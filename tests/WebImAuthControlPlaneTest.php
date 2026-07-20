<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\ImChallengeTokenService;
use plugin\saimulti\service\web\RedisWebImLoginRateLimiter;
use plugin\saimulti\service\web\WebImAuthService;
use plugin\saimulti\service\web\WebImAuthStoreInterface;
use plugin\saimulti\service\web\WebImAvatarServiceInterface;
use plugin\saimulti\service\web\WebImLoginRateLimiterInterface;
use plugin\saimulti\service\web\WebImPolicyGuard;
use plugin\saimulti\service\web\WebImPolicyStoreInterface;
use plugin\saimulti\service\WebTokenService;

final class InMemoryWebImAuthStore implements WebImAuthStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $users;

    /** @var list<array<string, mixed>> */
    public array $audits = [];

    /** @var array<string, array<string, mixed>> */
    public array $devices = [];

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, array<string, mixed>> */
    public array $accessSessions = [];

    /** @param array<int, array<string, mixed>> $users */
    public function __construct(array $users)
    {
        $this->users = $users;
    }

    public function findActiveLoginUser(int $organization, string $account): ?array
    {
        foreach ($this->users as $user) {
            if (
                (int) $user['organization'] === $organization
                && (string) $user['account'] === $account
                && (int) $user['status'] === 1
                && (int) $user['is_system'] === 2
                && ($user['delete_time'] ?? null) === null
            ) {
                return $user;
            }
        }

        return null;
    }

    public function findActiveUser(int $organization, int $id, string $userId): ?array
    {
        $user = $this->users[$id] ?? null;
        if (
            !$user
            || (int) $user['organization'] !== $organization
            || (string) $user['user_id'] !== $userId
            || (int) $user['status'] !== 1
            || (int) $user['is_system'] !== 2
            || ($user['delete_time'] ?? null) !== null
        ) {
            return null;
        }

        return $user;
    }

    public function recordLoginAudit(array $audit): void
    {
        $this->audits[] = $audit;
    }

    public function recordSuccessfulLogin(
        int $organization,
        int $id,
        string $loginAt,
        string $clientFamily,
        array $audit,
        array $accessSession,
    ): void
    {
        if (!isset($this->users[$id]) || (int) $this->users[$id]['organization'] !== $organization) {
            throw new RuntimeException('Test user does not exist.');
        }
        $this->users[$id]['login_time'] = $loginAt;
        $this->audits[] = $audit;
        $this->accessSessions[$organization . ':' . $accessSession['jti']] = $accessSession;
    }

    public function upsertChallenge(array $device, array $session, array $accessSession): void
    {
        $storedAccess = $this->accessSessions[$accessSession['organization'] . ':' . $accessSession['jti']] ?? null;
        if (
            !$storedAccess
            || (int) $storedAccess['im_user_id'] !== (int) $accessSession['im_user_id']
            || $storedAccess['user_id'] !== $accessSession['user_id']
            || $storedAccess['device_id'] !== $accessSession['device_id']
            || (int) $storedAccess['status'] !== 1
            || ($storedAccess['revoked_at'] ?? null) !== null
            || strtotime((string) $storedAccess['expire_at']) <= (int) $accessSession['now']
            || strtotime((string) $storedAccess['expire_at']) < (int) $accessSession['token_exp']
        ) {
            throw new ApiException('Web 登录会话已撤销或过期。', 401);
        }
        $deviceKey = implode(':', [$device['organization'], $device['user_id'], $device['device_id']]);
        $existingDevice = $this->devices[$deviceKey] ?? null;
        if (
            $existingDevice
            && (
                (int) $existingDevice['status'] !== 1
                || $existingDevice['client_family'] !== $device['client_family']
                || $existingDevice['os'] !== $device['os']
            )
        ) {
            throw new ApiException('当前 Web 设备已停用或设备类型不匹配。', 403);
        }
        $this->devices[$deviceKey] = array_merge($existingDevice ?? [], $device);

        foreach ($this->sessions as $storedSession) {
            if (
                (int) $storedSession['organization'] === (int) $session['organization']
                && hash_equals((string) $storedSession['session_id'], (string) $session['session_id'])
                && !hash_equals((string) $storedSession['client_id'], (string) $session['client_id'])
            ) {
                throw new RuntimeException('Generated IM credential session_id collision.');
            }
        }

        $sessionKey = $session['organization'] . ':' . $session['client_id'];
        $existingSession = $this->sessions[$sessionKey] ?? null;
        if ($existingSession && ((int) $existingSession['status'] === 2 || ($existingSession['revoked_at'] ?? null) !== null)) {
            throw new ApiException('已撤销的 client_id 不能重新签发凭证。', 409);
        }
        if (
            $existingSession
            && (int) $existingSession['status'] === 1
            && strtotime((string) $existingSession['expire_at']) > strtotime((string) $session['create_time'])
            && (
                $existingSession['user_id'] !== $session['user_id']
                || $existingSession['device_id'] !== $session['device_id']
            )
        ) {
            throw new ApiException('当前 client_id 已绑定其他有效身份。', 409);
        }
        $this->sessions[$sessionKey] = $session;
    }

    public function updateAvatar(
        int $organization,
        int $id,
        string $userId,
        string $avatarFileId,
        string $updateTime,
    ): void {
        $user = $this->findActiveUser($organization, $id, $userId);
        if (!$user) {
            return;
        }
        $this->users[$id]['avatar'] = $avatarFileId;
        $this->users[$id]['update_time'] = $updateTime;
    }
}

final class EnabledWebImPolicyStore implements WebImPolicyStoreInterface
{
    public array $rows = [
        7 => [
            'organization' => 7,
            'status' => 'ENABLED',
            'allowed_client_families_json' => '["web","app","desktop"]',
        ],
    ];

    public function findPolicy(int $organization): ?array
    {
        return $this->rows[$organization] ?? null;
    }
}

final class RecordingWebImLoginRateLimiter implements WebImLoginRateLimiterInterface
{
    /** @var list<array{int, string, string}> */
    public array $calls = [];

    /** @var list<array{int, string}> */
    public array $resetCalls = [];

    public bool $blocked = false;

    public bool $resetUnavailable = false;

    public function assertAllowed(int $organization, string $account, string $clientIp): void
    {
        $this->calls[] = [$organization, $account, $clientIp];
        if ($this->blocked) {
            throw new ApiException('登录尝试过于频繁，请稍后再试。', RedisWebImLoginRateLimiter::RATE_LIMITED);
        }
    }

    public function resetAccountAttempts(int $organization, string $account): void
    {
        $this->resetCalls[] = [$organization, $account];
        if ($this->resetUnavailable) {
            throw new ApiException('登录服务暂不可用。', RedisWebImLoginRateLimiter::UNAVAILABLE);
        }
    }
}

final class RecordingWebImAvatarService implements WebImAvatarServiceInterface
{
    public string $ownedFileId;

    /** @var list<array{int, string, string}> */
    public array $ownershipChecks = [];

    /** @var list<array{int, string}> */
    public array $projections = [];

    public function __construct(string $ownedFileId)
    {
        $this->ownedFileId = $ownedFileId;
    }

    public function assertOwnedImage(int $organization, string $ownerUserId, string $fileId): string
    {
        $this->ownershipChecks[] = [$organization, $ownerUserId, $fileId];
        if (preg_match('/^[a-f0-9]{40}$/', $fileId) !== 1) {
            throw new ApiException('avatar_file_id 格式无效。', 422);
        }
        if (!hash_equals($this->ownedFileId, $fileId)) {
            throw new ApiException('头像附件不存在或不属于当前用户。', 404);
        }

        return $fileId;
    }

    public function project(int $organization, string $fileId): array
    {
        $this->projections[] = [$organization, $fileId];

        return [
            'avatar_file_id' => $fileId,
            'avatar_url' => 'https://s3.example.test/avatar?X-Amz-Signature=test',
            'avatar_expires_at' => 1_800_000_300,
        ];
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$expectApiCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, 'ApiException code mismatch.');
        return;
    }
    throw new RuntimeException('Expected ApiException was not thrown.');
};

$now = time();
$webSecret = str_repeat('web-control-plane-secret-', 2);
$imSecret = str_repeat('im-challenge-hmac-secret-', 2);
$webTokens = new WebTokenService($webSecret, 'HS256');
$imTokens = new ImChallengeTokenService($imSecret, 300);
$store = new InMemoryWebImAuthStore([
    9 => [
        'id' => 9,
        'organization' => 7,
        'user_id' => 'user_9',
        'im_short_no' => '90009',
        'account' => 'alice',
        'password_hash' => password_hash('correct-password', PASSWORD_DEFAULT),
        'nickname' => 'Alice',
        'signature' => 'hello',
        'avatar' => '',
        'mobile' => '13800000000',
        'gender' => 2,
        'is_system' => 2,
        'system_code' => null,
        'status' => 1,
        'remark' => 'owner remark',
        'login_time' => null,
        'delete_time' => null,
    ],
]);
$loginRateLimiter = new RecordingWebImLoginRateLimiter();
$avatarFileId = str_repeat('a', 40);
$avatars = new RecordingWebImAvatarService($avatarFileId);
$policyStore = new EnabledWebImPolicyStore();
$service = new WebImAuthService(
    $store,
    $webTokens,
    $imTokens,
    static fn (): int => $now,
    $loginRateLimiter,
    $avatars,
    new WebImPolicyGuard($policyStore),
);
$organization = ['id' => 7, 'deployment_id' => 'deployment-1'];

$login = $service->login(
    $organization,
    'alice',
    'correct-password',
    'web-login-device',
    'web',
    'browser',
    '203.0.113.10',
);
$assert(
    array_keys($login) === [
        'organization',
        'deployment_id',
        'cross_org_access_snapshot_id',
        'token',
        'user',
    ],
    'Login contract is not exact.',
);
$assert(!array_key_exists('im_token', $login), 'Login must not pre-issue an IM token.');
$assert($login['organization'] === 7 && $login['deployment_id'] === 'deployment-1', 'Login trust context mismatch.');
$assert($login['cross_org_access_snapshot_id'] === '0', 'Login must expose a decimal access snapshot.');
$assert(
    $login['user']['user_id'] === 'user_9'
    && $login['user']['organization'] === 7
    && $login['user']['is_system'] === false,
    'Web user model mismatch.',
);
$assert(!array_key_exists('password_hash', $login['user']) && !array_key_exists('password', $login['user']), 'Password leaked.');
$assert(count($store->audits) === 1 && $store->audits[0]['login_result'] === 'success', 'Successful login audit missing.');
$assert($store->audits[0]['audit_scope'] === 'password' && $store->audits[0]['current_online_state'] === 2, 'Successful audit contract mismatch.');
$assert(count($store->accessSessions) === 1, 'Web access session was not persisted with the login transaction.');
$assert(
    $loginRateLimiter->resetCalls === [[7, 'alice']],
    'Successful credential verification did not reset the exact account limiter scope.',
);

$expectApiCode(401, static fn () => $service->login(
    $organization,
    'alice',
    'wrong-password',
    'web-login-device',
    'web',
    'browser',
    '203.0.113.10',
));
$assert(count($store->audits) === 2 && $store->audits[1]['login_result'] === 'failed', 'Failed login audit missing.');
$assert($store->audits[1]['failure_code'] === 'INVALID_CREDENTIALS', 'Failed login code mismatch.');
$assert(count($loginRateLimiter->resetCalls) === 1, 'Invalid credentials unexpectedly reset the account limiter.');
$loginRateLimiter->blocked = true;
$expectApiCode(RedisWebImLoginRateLimiter::RATE_LIMITED, static fn () => $service->login(
    $organization,
    'alice',
    'correct-password',
    'web-login-device',
    'web',
    'browser',
    '203.0.113.10',
));
$assert(
    $loginRateLimiter->calls === [
        [7, 'alice', '203.0.113.10'],
        [7, 'alice', '203.0.113.10'],
        [7, 'alice', '203.0.113.10'],
    ],
    'Login limiter did not receive the organization/account/IP dimensions.',
);
$assert(
    count($store->audits) === 3 && $store->audits[2]['failure_code'] === 'LOGIN_RATE_LIMITED',
    'Rate-limited login attempt was not audited.',
);
$assert(count($loginRateLimiter->resetCalls) === 1, 'Rate-limited login unexpectedly reset the account limiter.');
$loginRateLimiter->blocked = false;
$loginRateLimiter->resetUnavailable = true;
$expectApiCode(RedisWebImLoginRateLimiter::UNAVAILABLE, static fn () => $service->login(
    $organization,
    'alice',
    'correct-password',
    'web-login-device',
    'web',
    'browser',
    '203.0.113.10',
));
$assert(
    count($store->audits) === 4 && $store->audits[3]['failure_code'] === 'LOGIN_RATE_LIMITER_UNAVAILABLE',
    'Account limiter reset failure was not audited with the stable failure code.',
);
$assert(count($store->accessSessions) === 1, 'A token session was persisted after limiter reset failed.');
$loginRateLimiter->resetUnavailable = false;

$accessClaims = $webTokens->verifyAccess(
    $login['token']['access_token'],
    7,
    'deployment-1',
    'web',
);
$identity = [
    'id' => $accessClaims['id'],
    'organization' => $accessClaims['organization'],
    'deployment_id' => $accessClaims['deployment_id'],
    'user_id' => $accessClaims['user_id'],
    'account' => $accessClaims['account'],
    'device_id' => $accessClaims['device_id'],
    'client_family' => $accessClaims['client_family'],
    'os' => $accessClaims['os'],
    'token_exp' => $accessClaims['exp'],
    'web_access_jti' => $accessClaims['jti'],
];

$assert(!array_key_exists('jti', $login['token']), 'Web access jti leaked as a response field.');
$assert(!array_key_exists('jti', $login['user']), 'Web access jti leaked in the user view.');
$expectApiCode(401, static fn () => $service->issueImToken(
    $identity,
    'web-refreshed-device',
    'gateway-client-mismatched-device',
    '203.0.113.11',
));

$challenge = $service->issueImToken(
    $identity,
    'web-login-device',
    'gateway-client-1',
    '203.0.113.11',
);
$assert(
    ($challenge['cross_org_access_snapshot_id'] ?? null) === '0',
    'IM session credential must expose a decimal access snapshot.',
);
$claims = (array) JWT::decode($challenge['token'], new Key($imSecret, 'HS256'));
try {
    JWT::decode($challenge['token'], new Key(str_repeat('wrong-hmac-secret-', 3), 'HS256'));
    throw new RuntimeException('IM JWT accepted the wrong HMAC secret.');
} catch (UnexpectedValueException) {
    $assert(true, 'IM JWT wrong-secret verification did not fail as expected.');
}
$requiredClaims = [
    'iss',
    'aud',
    'iat',
    'nbf',
    'exp',
    'deployment_id',
    'organization',
    'user_id',
    'device_id',
    'client_id',
    'session_id',
    'client_family',
    'os',
    'username',
];
$assert(array_keys($claims) === $requiredClaims, 'IM JWT claims are not exact.');
$assert($claims['iss'] === 'deployment-1' && $claims['deployment_id'] === 'deployment-1', 'IM issuer mismatch.');
$assert($claims['aud'] === 'im' && $claims['organization'] === 7, 'IM audience or organization mismatch.');
$assert($claims['user_id'] === 'user_9' && $claims['username'] === 'alice', 'IM user claims mismatch.');
$assert($claims['device_id'] === 'web-login-device', 'IM challenge did not use the bearer-bound device_id.');
$assert($claims['device_id'] === $identity['device_id'], 'IM challenge diverged from the access JWT device_id.');
$assert($claims['client_id'] === 'gateway-client-1', 'IM client_id claim mismatch.');
$assert($claims['client_family'] === 'web' && $claims['os'] === 'browser', 'IM device family claims mismatch.');
$assert($claims['exp'] <= $identity['token_exp'] && $claims['exp'] === $challenge['expire_at'], 'IM token exceeded access session.');

$sessionKey = '7:gateway-client-1';
$deviceKey = '7:user_9:web-login-device';
$assert(isset($store->sessions[$sessionKey]), 'Credential session was not stored.');
$assert($store->sessions[$sessionKey]['session_id'] === $claims['session_id'], 'Stored credential session differs from JWT.');
$assert(strtotime($store->sessions[$sessionKey]['expire_at']) === $claims['exp'], 'Stored expiry differs from JWT.');
$assert($store->devices[$deviceKey]['current_ip'] === '203.0.113.11', 'Device current IP was not stored.');
$assert($store->devices[$deviceKey]['last_login_ip'] === '203.0.113.11', 'Device last IP was not stored.');
$assert($store->devices[$deviceKey]['client_family'] === 'web', 'Device family was not persisted as web.');
$assert($store->devices[$deviceKey]['os'] === 'browser', 'Device OS was not persisted as browser.');

$firstCredentialSessionId = $claims['session_id'];
$secondChallenge = $service->issueImToken(
    $identity,
    'web-login-device',
    'gateway-client-1',
    '203.0.113.12',
);
$secondClaims = (array) JWT::decode($secondChallenge['token'], new Key($imSecret, 'HS256'));
$assert(count($store->sessions) === 1, 'client_id uniqueness was not preserved.');
$assert($secondClaims['session_id'] !== $firstCredentialSessionId, 'A new challenge reused the credential session.');
$assert($store->sessions[$sessionKey]['session_id'] === $secondClaims['session_id'], 'Challenge session was not atomically replaced.');

try {
    $store->upsertChallenge($store->devices[$deviceKey], array_merge($store->sessions[$sessionKey], [
        'client_id' => 'gateway-client-2',
        'session_id' => $secondClaims['session_id'],
    ]), [
        'organization' => 7,
        'jti' => $accessClaims['jti'],
        'im_user_id' => 9,
        'user_id' => 'user_9',
        'device_id' => 'web-login-device',
        'token_exp' => $accessClaims['exp'],
        'now' => $now,
    ]);
    throw new RuntimeException('A duplicate credential session_id was accepted for another client.');
} catch (RuntimeException $exception) {
    $assert(
        str_contains($exception->getMessage(), 'session_id collision'),
        'Credential session collision error mismatch.',
    );
}

$store->sessions[$sessionKey]['status'] = 2;
$store->sessions[$sessionKey]['revoked_at'] = date('Y-m-d H:i:s', $now);
$expectApiCode(409, static fn () => $service->issueImToken(
    $identity,
    'web-login-device',
    'gateway-client-1',
    '203.0.113.13',
));
$assert(
    $store->sessions[$sessionKey]['status'] === 2 && $store->sessions[$sessionKey]['revoked_at'] !== null,
    'Revoked client_id was revived by challenge upsert.',
);

$appLogin = $service->login(
    $organization,
    'alice',
    'correct-password',
    'app-login-device',
    'app',
    'ios',
    '203.0.113.20',
);
$appAccessClaims = $webTokens->verifyAccess(
    $appLogin['token']['access_token'],
    7,
    'deployment-1',
    'app',
);
$appIdentity = [
    'id' => $appAccessClaims['id'],
    'organization' => $appAccessClaims['organization'],
    'deployment_id' => $appAccessClaims['deployment_id'],
    'user_id' => $appAccessClaims['user_id'],
    'account' => $appAccessClaims['account'],
    'device_id' => $appAccessClaims['device_id'],
    'client_family' => $appAccessClaims['client_family'],
    'os' => $appAccessClaims['os'],
    'token_exp' => $appAccessClaims['exp'],
    'web_access_jti' => $appAccessClaims['jti'],
];
$appChallenge = $service->issueImToken(
    $appIdentity,
    'app-login-device',
    'gateway-app-client-1',
    '203.0.113.21',
);
$appImClaims = (array) JWT::decode($appChallenge['token'], new Key($imSecret, 'HS256'));
$assert(
    $appImClaims['client_family'] === 'app'
    && $appImClaims['os'] === 'ios'
    && $appImClaims['aud'] === 'im',
    'App IM challenge runtime claims mismatch.',
);
$appDevice = $store->devices['7:user_9:app-login-device'] ?? null;
$assert(
    is_array($appDevice)
    && $appDevice['client_family'] === 'app'
    && $appDevice['os'] === 'ios',
    'App device runtime was not persisted.',
);
$appSession = $store->sessions['7:gateway-app-client-1'] ?? null;
$assert(
    is_array($appSession) && $appSession['web_access_jti'] === null,
    'App IM credential session must not persist a Web-only access binding.',
);

$policyStore->rows[7]['status'] = 'DISABLED';
$expectApiCode(403, static fn () => $service->login(
    $organization,
    'alice',
    'correct-password',
    'web-login-device',
    'web',
    'browser',
    '203.0.113.10',
));
$policyStore->rows[7]['status'] = 'ENABLED';

$me = $service->me($identity);
$assert($me['account'] === 'alice' && $me['signature'] === 'hello', 'me contract mismatch.');
$assert(
    $me['avatar_file_id'] === '' && $me['avatar_url'] === '' && $me['avatar_expires_at'] === 0,
    'Empty avatar projection contract mismatch.',
);
$expectApiCode(422, static fn () => $service->updateAvatar($identity, 'http://cdn.example.com/avatar.png'));
$expectApiCode(404, static fn () => $service->updateAvatar($identity, str_repeat('b', 40)));
$updated = $service->updateAvatar($identity, $avatarFileId);
$assert(
    $store->users[9]['avatar'] === $avatarFileId
    && !array_key_exists('avatar', $updated)
    && $updated['avatar_file_id'] === $avatarFileId
    && str_contains($updated['avatar_url'], 'X-Amz-Signature=')
    && $updated['avatar_expires_at'] === 1_800_000_300,
    'Avatar file_id persistence or signed projection failed.',
);
$assert(
    $avatars->ownershipChecks[2] === [7, 'user_9', $avatarFileId]
    && $avatars->projections === [[7, $avatarFileId]],
    'Avatar ownership or read-time signing context mismatch.',
);

try {
    new ImChallengeTokenService('short-secret', 300);
    throw new RuntimeException('Weak IM token secret was accepted.');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), '32 bytes'), 'Weak secret error mismatch.');
}
foreach ([
    'please-change-this-im-token-secret-now',
    'change-me-this-im-token-secret-now-now',
    'example-secret-for-im-token-signing',
] as $placeholderSecret) {
    try {
        new ImChallengeTokenService($placeholderSecret, 300);
        throw new RuntimeException('Placeholder IM token secret was accepted.');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'non-placeholder'), 'Placeholder secret error mismatch.');
    }
}

echo sprintf("WebImAuthControlPlaneTest: %d assertions passed\n", $assertions);
