<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\OrganizationDiscovery;

final class WebQrLoginService
{
    private const TERMINAL = ['cancelled', 'expired'];

    private WebQrLoginStoreInterface $store;

    private WebAccessIssuerInterface $access;

    private Closure $clock;

    private Closure $tokenGenerator;

    public function __construct(
        ?WebQrLoginStoreInterface $store = null,
        ?WebAccessIssuerInterface $access = null,
        ?Closure $clock = null,
        ?Closure $tokenGenerator = null,
    ) {
        $this->store = $store ?? new ThinkOrmWebQrLoginStore();
        $this->access = $access ?? new WebImAuthService();
        $this->clock = $clock ?? static fn (): int => time();
        $this->tokenGenerator = $tokenGenerator ?? static fn (): string => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $organization @return array<string, mixed> */
    public function create(array $organization, string $browserDeviceId, string $browserOrigin): array
    {
        [$organizationId, $deploymentId] = $this->organization($organization);
        $browserDeviceId = $this->identifier($browserDeviceId, 'device_id', 100);
        $browserOrigin = trim($browserOrigin);
        if ($browserOrigin === '' || strlen($browserOrigin) > 255) {
            throw new ApiException('Web Origin 格式无效。', 422);
        }
        $qrId = bin2hex(random_bytes(16));
        $browserToken = $this->token();
        $scanToken = $this->token();
        $now = $this->now();
        $ttl = max(30, min(600, (int) env('WEB_QR_LOGIN_TTL_SECONDS', 120)));
        $expiresAt = $now + $ttl;

        $this->store->transaction(function () use (
            $organizationId,
            $deploymentId,
            $qrId,
            $browserToken,
            $scanToken,
            $browserDeviceId,
            $browserOrigin,
            $now,
            $expiresAt,
        ): void {
            $this->store->lockActiveOrganization($organizationId, $deploymentId);
            WebImPolicyGuard::assertRowAllows($this->store->lockImPolicy($organizationId), $organizationId, 'web');
            $nowText = date('Y-m-d H:i:s', $now);
            $this->store->insert([
                'organization' => $organizationId,
                'deployment_id' => $deploymentId,
                'qr_id' => $qrId,
                'browser_token_hash' => $this->digest($browserToken),
                'scan_token_hash' => $this->digest($scanToken),
                'browser_device_id' => $browserDeviceId,
                'browser_origin' => $browserOrigin,
                'status' => 'pending',
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                'create_time' => $nowText,
                'update_time' => $nowText,
            ]);
        });

        $query = http_build_query([
            'qr_id' => $qrId,
            'scan_token' => $scanToken,
            'organization' => $organizationId,
            'deployment_id' => $deploymentId,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'qr_id' => $qrId,
            'browser_token' => $browserToken,
            'qr_content' => 'b8im://web-login?' . $query,
            'expires_at' => $expiresAt,
        ];
    }

    /** @param array<string, mixed> $organization @param array<string, mixed> $identity @return array<string, mixed> */
    public function scan(array $organization, array $identity, string $qrId, string $scanToken, string $clientIp): array
    {
        [$organizationId, $deploymentId] = $this->organization($organization);
        [$imUserId, $userId, $deviceId, $os] = $this->appIdentity($identity, $organizationId, $deploymentId);
        $qrId = $this->qrId($qrId);
        $scanHash = $this->digest($this->presentedToken($scanToken, 'scan_token'));
        $now = $this->now();
        $result = $this->store->transaction(function () use (
            $organizationId,
            $deploymentId,
            $imUserId,
            $userId,
            $deviceId,
            $os,
            $qrId,
            $scanHash,
            $clientIp,
            $now,
        ): array {
            $organizationRow = $this->store->lockActiveOrganization($organizationId, $deploymentId);
            $this->store->lockActiveUser($organizationId, $imUserId, $userId);
            $policy = $this->store->lockImPolicy($organizationId);
            WebImPolicyGuard::assertRowAllows($policy, $organizationId, 'app');
            WebImPolicyGuard::assertRowAllows($policy, $organizationId, 'web');
            $row = $this->lockedQr($organizationId, $deploymentId, $qrId);
            if ($this->expireIfNeeded($row, $now)) {
                return ['expired' => true];
            }
            $this->assertHash($scanHash, (string) $row['scan_token_hash']);
            if (!hash_equals('pending', (string) $row['status'])) {
                throw new ApiException('二维码已绑定或不可扫描。', 409);
            }
            $nowText = date('Y-m-d H:i:s', $now);
            if (!$this->store->transition((int) $row['id'], 'pending', [
                'status' => 'scanned',
                'app_im_user_id' => $imUserId,
                'app_user_id' => $userId,
                'app_device_id' => $deviceId,
                'scanned_at' => $nowText,
                'update_time' => $nowText,
            ])) {
                throw new ApiException('二维码状态已变化。', 409);
            }

            return [
                'qr_id' => $qrId,
                'status' => 'scanned',
                'organization' => $organizationId,
                'organization_name' => (string) ($organizationRow['organization_name'] ?? $organizationRow['title'] ?? ''),
                'web_origin' => (string) $row['browser_origin'],
                'browser_device' => $this->maskedDevice((string) $row['browser_device_id']),
                'expires_at' => strtotime((string) $row['expires_at']) ?: 0,
            ];
        });
        if (($result['expired'] ?? false) === true) {
            throw new ApiException('二维码已过期。', 409);
        }

        return $result;
    }

    /** @param array<string, mixed> $organization @param array<string, mixed> $identity @return array<string, mixed> */
    public function confirm(array $organization, array $identity, string $qrId, string $clientIp): array
    {
        [$organizationId, $deploymentId] = $this->organization($organization);
        [$imUserId, $userId, $deviceId, $os] = $this->appIdentity($identity, $organizationId, $deploymentId);
        $qrId = $this->qrId($qrId);
        $now = $this->now();
        $result = $this->store->transaction(function () use (
            $organizationId,
            $deploymentId,
            $imUserId,
            $userId,
            $deviceId,
            $os,
            $qrId,
            $clientIp,
            $now,
        ): array {
            $this->store->lockActiveOrganization($organizationId, $deploymentId);
            $this->store->lockActiveUser($organizationId, $imUserId, $userId);
            $policy = $this->store->lockImPolicy($organizationId);
            WebImPolicyGuard::assertRowAllows($policy, $organizationId, 'app');
            WebImPolicyGuard::assertRowAllows($policy, $organizationId, 'web');
            $row = $this->lockedQr($organizationId, $deploymentId, $qrId);
            if ($this->expireIfNeeded($row, $now)) {
                return ['expired' => true];
            }
            if (
                !hash_equals('scanned', (string) $row['status'])
                || (int) ($row['app_im_user_id'] ?? 0) !== $imUserId
                || !hash_equals((string) ($row['app_user_id'] ?? ''), $userId)
                || !hash_equals((string) ($row['app_device_id'] ?? ''), $deviceId)
            ) {
                throw new ApiException('只能由扫码绑定的 App 用户和设备确认。', 403);
            }
            $nowText = date('Y-m-d H:i:s', $now);
            if (!$this->store->transition((int) $row['id'], 'scanned', [
                'status' => 'confirmed',
                'confirmed_at' => $nowText,
                'update_time' => $nowText,
            ])) {
                throw new ApiException('二维码状态已变化。', 409);
            }
            $this->access->recordLoginEvent(
                $organizationId,
                $userId,
                $deviceId,
                $clientIp,
                'app',
                $os,
                'confirmed',
                'qr_login',
                null,
                $now,
            );

            return ['qr_id' => $qrId, 'status' => 'confirmed'];
        });
        if (($result['expired'] ?? false) === true) {
            throw new ApiException('二维码已过期。', 409);
        }

        return $result;
    }

    /** @param array<string, mixed> $organization @return array<string, mixed> */
    public function poll(
        array $organization,
        string $qrId,
        string $browserToken,
        string $browserDeviceId,
        string $clientIp,
    ): array {
        [$organizationId, $deploymentId] = $this->organization($organization);
        $qrId = $this->qrId($qrId);
        $browserHash = $this->digest($this->presentedToken($browserToken, 'browser_token'));
        $browserDeviceId = $this->identifier($browserDeviceId, 'device_id', 100);
        $candidate = $this->store->find($organizationId, $deploymentId, $qrId);
        if ($candidate === null) {
            throw new ApiException('二维码不存在或不属于当前机构部署。', 404);
        }
        $now = $this->now();
        $result = $this->store->transaction(function () use (
            $organization,
            $organizationId,
            $deploymentId,
            $qrId,
            $browserHash,
            $browserDeviceId,
            $clientIp,
            $candidate,
            $now,
        ): array {
            $this->store->lockActiveOrganization($organizationId, $deploymentId);
            $policy = $this->store->lockImPolicy($organizationId);
            WebImPolicyGuard::assertRowAllows($policy, $organizationId, 'web');
            $candidateUserId = (int) ($candidate['app_im_user_id'] ?? 0);
            $candidatePublicId = (string) ($candidate['app_user_id'] ?? '');
            $user = null;
            if ($candidateUserId > 0 && $candidatePublicId !== '') {
                $user = $this->store->lockActiveUser($organizationId, $candidateUserId, $candidatePublicId);
            }
            $row = $this->lockedQr($organizationId, $deploymentId, $qrId);
            $this->assertBrowser($row, $browserHash, $browserDeviceId);
            if ($this->expireIfNeeded($row, $now)) {
                return ['expired' => true];
            }
            $status = (string) $row['status'];
            if (in_array($status, self::TERMINAL, true)) {
                throw new ApiException('二维码已关闭。', 409);
            }
            if (in_array($status, ['pending', 'scanned'], true)) {
                return ['status' => $status, 'expires_at' => strtotime((string) $row['expires_at']) ?: 0];
            }
            if ($status === 'consumed') {
                return ['status' => 'consumed'];
            }
            if ($status !== 'confirmed' || $user === null) {
                throw new ApiException('二维码确认身份无效。', 409);
            }
            if (
                (int) $row['app_im_user_id'] !== (int) $user['id']
                || !hash_equals((string) $row['app_user_id'], (string) $user['user_id'])
            ) {
                throw new ApiException('二维码确认身份已变化。', 409);
            }
            $issued = $this->access->issueAccessForUser(
                $organization,
                $user,
                $browserDeviceId,
                'web',
                'browser',
                $clientIp,
                'qr_login',
                $now,
            );
            $nowText = date('Y-m-d H:i:s', $now);
            if (!$this->store->transition((int) $row['id'], 'confirmed', [
                'status' => 'consumed',
                'consumed_at' => $nowText,
                'update_time' => $nowText,
            ])) {
                throw new ApiException('二维码已被消费。', 409);
            }

            return ['status' => 'confirmed'] + $issued;
        });
        if (($result['expired'] ?? false) === true) {
            throw new ApiException('二维码已过期。', 409);
        }

        return $result;
    }

    /** @param array<string, mixed> $organization @return array<string, mixed> */
    public function cancel(array $organization, string $qrId, string $browserToken, string $browserDeviceId): array
    {
        [$organizationId, $deploymentId] = $this->organization($organization);
        $qrId = $this->qrId($qrId);
        $browserHash = $this->digest($this->presentedToken($browserToken, 'browser_token'));
        $browserDeviceId = $this->identifier($browserDeviceId, 'device_id', 100);
        $now = $this->now();
        $result = $this->store->transaction(function () use (
            $organizationId,
            $deploymentId,
            $qrId,
            $browserHash,
            $browserDeviceId,
            $now,
        ): array {
            $this->store->lockActiveOrganization($organizationId, $deploymentId);
            $row = $this->lockedQr($organizationId, $deploymentId, $qrId);
            $this->assertBrowser($row, $browserHash, $browserDeviceId);
            if ($this->expireIfNeeded($row, $now)) {
                return ['expired' => true];
            }
            $status = (string) $row['status'];
            if (!in_array($status, ['pending', 'scanned', 'confirmed'], true)) {
                throw new ApiException('二维码不可取消。', 409);
            }
            $nowText = date('Y-m-d H:i:s', $now);
            if (!$this->store->transition((int) $row['id'], $status, [
                'status' => 'cancelled',
                'cancelled_at' => $nowText,
                'update_time' => $nowText,
            ])) {
                throw new ApiException('二维码状态已变化。', 409);
            }

            return ['status' => 'cancelled'];
        });
        if (($result['expired'] ?? false) === true) {
            throw new ApiException('二维码已过期。', 409);
        }

        return $result;
    }

    /** @param array<string, mixed> $organization @return array{0:int,1:string} */
    private function organization(array $organization): array
    {
        $id = (int) ($organization['id'] ?? 0);
        if ($id <= 0) {
            throw new ApiException('当前应用不可用。', 41003);
        }

        return [$id, OrganizationDiscovery::assertDeploymentId((string) ($organization['deployment_id'] ?? ''))];
    }

    /** @param array<string, mixed> $identity @return array{0:int,1:string,2:string,3:string} */
    private function appIdentity(array $identity, int $organization, string $deploymentId): array
    {
        if (
            (int) ($identity['organization'] ?? 0) !== $organization
            || !hash_equals($deploymentId, (string) ($identity['deployment_id'] ?? ''))
            || !hash_equals('app', (string) ($identity['client_family'] ?? ''))
        ) {
            throw new ApiException('App 登录凭证与二维码机构或部署不一致。', 403);
        }
        $id = (int) ($identity['id'] ?? 0);
        $userId = $this->identifier((string) ($identity['user_id'] ?? ''), 'user_id', 64, 401);
        $deviceId = $this->identifier((string) ($identity['device_id'] ?? ''), 'device_id', 100, 401);
        $os = trim((string) ($identity['os'] ?? ''));
        if ($id <= 0 || !in_array($os, ['android', 'ios', 'other'], true)) {
            throw new ApiException('App 登录身份无效。', 401);
        }

        return [$id, $userId, $deviceId, $os];
    }

    /** @return array<string, mixed> */
    private function lockedQr(int $organization, string $deploymentId, string $qrId): array
    {
        $row = $this->store->find($organization, $deploymentId, $qrId, true);
        if ($row === null) {
            throw new ApiException('二维码不存在或不属于当前机构部署。', 404);
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function expireIfNeeded(array $row, int $now): bool
    {
        if ((strtotime((string) ($row['expires_at'] ?? '')) ?: 0) > $now) {
            return false;
        }
        $status = (string) ($row['status'] ?? '');
        if (in_array($status, ['pending', 'scanned', 'confirmed'], true)) {
            $this->store->transition((int) $row['id'], $status, [
                'status' => 'expired',
                'update_time' => date('Y-m-d H:i:s', $now),
            ]);
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function assertBrowser(array $row, string $browserHash, string $deviceId): void
    {
        $this->assertHash($browserHash, (string) ($row['browser_token_hash'] ?? ''));
        if (!hash_equals((string) ($row['browser_device_id'] ?? ''), $deviceId)) {
            throw new ApiException('二维码不属于当前浏览器设备。', 403);
        }
    }

    private function assertHash(string $presentedHash, string $storedHash): void
    {
        if (strlen($storedHash) !== 64 || !hash_equals($storedHash, $presentedHash)) {
            throw new ApiException('二维码令牌无效。', 403);
        }
    }

    private function qrId(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
            throw new ApiException('qr_id 格式无效。', 422);
        }

        return $value;
    }

    private function token(): string
    {
        return $this->presentedToken((string) ($this->tokenGenerator)(), 'generated_token');
    }

    private function presentedToken(string $value, string $name): string
    {
        $value = trim($value);
        if (strlen($value) < 32 || strlen($value) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new ApiException($name . ' 格式无效。', 422);
        }

        return $value;
    }

    private function digest(string $token): string
    {
        return hash('sha256', $token);
    }

    private function identifier(string $value, string $name, int $maxLength, int $code = 422): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/', $value) !== 1) {
            throw new ApiException($name . ' 格式无效。', $code);
        }

        return $value;
    }

    private function maskedDevice(string $deviceId): string
    {
        return strlen($deviceId) <= 8 ? $deviceId : substr($deviceId, 0, 4) . '…' . substr($deviceId, -4);
    }

    private function now(): int
    {
        $now = (int) ($this->clock)();
        if ($now <= 0) {
            throw new \RuntimeException('Web QR login clock is invalid.');
        }

        return $now;
    }
}
