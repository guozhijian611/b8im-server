<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\State\SystemModuleStatus;
use B8im\ModuleSdk\State\TenantModuleStatus;
use plugin\saimulti\exception\ApiException;
use Throwable;
use OpenTelemetry\API\Trace\Span;
use plugin\saimulti\service\trace\Telemetry;

final class ModuleAccessService
{
    public const ACCESS_DENIED = 403;

    public function __construct(
        private readonly ModuleAccessStoreInterface $store,
        private readonly ModuleAccessCacheInterface $cache,
    ) {
    }

    public function isAvailable(
        int $organization,
        string $moduleKey,
        string $platform = 'server',
        ?string $capability = null,
    ): bool {
        $this->assertIdentifiers($organization, $moduleKey);
        $cacheKey = $this->cacheKey($organization, $moduleKey);

        try {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null && $this->validCacheShape($cached)) {
                // Cached denials are safe to honor directly. Cached grants are
                // only hints: every allow must be revalidated against MySQL so
                // a failed post-commit Redis refresh cannot extend revoked,
                // disabled, expired, or deleted authorization.
                if (!$this->snapshotAllows($cached, $platform, $capability)) {
                    return false;
                }
            }
        } catch (Throwable) {
            // Redis 不可用时继续回源 MySQL。
        }

        try {
            $snapshot = $this->store->tenantSnapshot($organization, $moduleKey);
            $cacheValue = $this->toCacheValue($snapshot);
        } catch (Throwable) {
            // 回源也失败时必须失败关闭。
            return false;
        }

        try {
            $this->cache->set($cacheKey, $cacheValue);
        } catch (Throwable) {
            // MySQL 已给出当次真实结果，缓存写入失败不改变当次判断。
        }

        return $this->snapshotAllows($cacheValue, $platform, $capability);
    }

    public function assertAvailable(
        int $organization,
        string $moduleKey,
        string $platform = 'server',
        ?string $capability = null,
    ): void {
        Telemetry::inSpan(
            'b8im.module.license',
            'module.license.tenant',
            [
                'b8im.organization' => $organization,
                'b8im.module_key' => $moduleKey,
                'b8im.platform' => $platform,
                'b8im.capability' => $capability ?? 'any',
            ],
            function () use ($organization, $moduleKey, $platform, $capability): void {
                $available = $this->isAvailable($organization, $moduleKey, $platform, $capability);
                Span::getCurrent()->setAttribute('b8im.module.available', $available);
                if (!$available) {
                    throw new ApiException('模块未启用、未授权、已过期或当前平台不支持', self::ACCESS_DENIED);
                }
            },
        );
    }

    public function isSystemAvailable(string $moduleKey, string $platform = 'server', ?string $capability = null): bool
    {
        try {
            $snapshot = $this->store->systemSnapshot($moduleKey);
        } catch (Throwable) {
            return false;
        }

        if ($snapshot === null || $snapshot['module_status'] !== SystemModuleStatus::ENABLED->value) {
            return false;
        }

        return $this->platformAllows($snapshot, $platform, $capability);
    }

    public function assertSystemAvailable(string $moduleKey, string $platform = 'server', ?string $capability = null): void
    {
        Telemetry::inSpan(
            'b8im.module.license',
            'module.license.system',
            [
                'b8im.module_key' => $moduleKey,
                'b8im.platform' => $platform,
                'b8im.capability' => $capability ?? 'any',
            ],
            function () use ($moduleKey, $platform, $capability): void {
                $available = $this->isSystemAvailable($moduleKey, $platform, $capability);
                Span::getCurrent()->setAttribute('b8im.module.available', $available);
                if (!$available) {
                    throw new ApiException('系统模块未启用或当前平台不支持', self::ACCESS_DENIED);
                }
            },
        );
    }

    public function isTenantLicensed(int $organization, string $moduleKey): bool
    {
        try {
            $snapshot = $this->store->tenantSnapshot($organization, $moduleKey);
        } catch (Throwable) {
            return false;
        }

        if ($snapshot === null || $snapshot['module_status'] !== SystemModuleStatus::ENABLED->value) {
            return false;
        }

        if (!in_array($snapshot['license_status'], [
            TenantModuleStatus::AUTHORIZED->value,
            TenantModuleStatus::ENABLED->value,
            TenantModuleStatus::DISABLED->value,
        ], true)) {
            return false;
        }

        return !$this->expired($snapshot['expire_at'] ?? null);
    }

    public function assertTenantLicensed(int $organization, string $moduleKey): void
    {
        Telemetry::inSpan(
            'b8im.module.license',
            'module.license.entitlement',
            ['b8im.organization' => $organization, 'b8im.module_key' => $moduleKey],
            function () use ($organization, $moduleKey): void {
                $licensed = $this->isTenantLicensed($organization, $moduleKey);
                Span::getCurrent()->setAttribute('b8im.module.licensed', $licensed);
                if (!$licensed) {
                    throw new ApiException('当前租户未获得有效模块授权', self::ACCESS_DENIED);
                }
            },
        );
    }

    /** @return list<string> */
    public function enabledModuleKeys(int $organization, string $platform): array
    {
        try {
            $snapshots = $this->store->enabledTenantSnapshots($organization);
        } catch (Throwable) {
            return [];
        }

        $keys = [];
        foreach ($snapshots as $snapshot) {
            if ($this->snapshotAllows($this->toCacheValue($snapshot), $platform, null)) {
                $keys[] = (string) $snapshot['module_key'];
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> */
    public function enabledSystemModuleKeys(string $platform): array
    {
        try {
            $snapshots = $this->store->enabledSystemSnapshots();
        } catch (Throwable) {
            return [];
        }

        $keys = [];
        foreach ($snapshots as $snapshot) {
            if (($snapshot['module_status'] ?? null) === SystemModuleStatus::ENABLED->value
                && $this->platformAllows($snapshot, $platform, null)) {
                $keys[] = (string) $snapshot['module_key'];
            }
        }

        return array_values(array_unique($keys));
    }

    public function invalidate(int $organization, string $moduleKey): void
    {
        $cacheKey = $this->cacheKey($organization, $moduleKey);

        try {
            // The mutation has already committed when this method is called.
            // Publish the authoritative post-commit snapshot instead of only
            // deleting the key: a request that read the old DB state before
            // commit may otherwise refill that old snapshot after the DEL.
            $snapshot = $this->store->tenantSnapshot($organization, $moduleKey);
            $this->cache->set($cacheKey, $this->toCacheValue($snapshot));
        } catch (Throwable $exception) {
            // Never intentionally retain a known-stale value. Redis-backed
            // caches also reject lower version tuples, while bounded TTL is
            // the final recovery path if this cleanup itself cannot complete.
            try {
                $this->cache->delete($cacheKey);
            } catch (Throwable) {
            }
            throw $exception;
        }
    }

    public function invalidateModule(string $moduleKey): void
    {
        foreach ($this->store->organizationsForModule($moduleKey) as $organization) {
            $this->invalidate($organization, $moduleKey);
        }
    }

    private function cacheKey(int $organization, string $moduleKey): string
    {
        return sprintf('module_license:%d:%s', $organization, $moduleKey);
    }

    /** @param array<string, mixed>|null $snapshot @return array<string, mixed> */
    private function toCacheValue(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [
                'enabled' => false,
                'effective_until' => null,
                'version' => 0,
                'module_version' => '',
                'module_lock_version' => 0,
                'platforms' => [],
                'capabilities' => [],
            ];
        }

        return [
            'enabled' => $snapshot['module_status'] === SystemModuleStatus::ENABLED->value
                && $snapshot['license_status'] === TenantModuleStatus::ENABLED->value,
            'effective_until' => empty($snapshot['expire_at']) ? null : strtotime((string) $snapshot['expire_at']),
            'version' => (int) ($snapshot['license_version'] ?? 0),
            'module_version' => (string) ($snapshot['module_version'] ?? ''),
            'module_lock_version' => (int) ($snapshot['module_lock_version'] ?? 0),
            'platforms' => $snapshot['platforms'] ?? [],
            'capabilities' => $snapshot['capabilities'] ?? [],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotAllows(array $snapshot, string $platform, ?string $capability): bool
    {
        if ($snapshot['enabled'] !== true) {
            return false;
        }

        $effectiveUntil = $snapshot['effective_until'];
        if ($effectiveUntil !== null && (int) $effectiveUntil <= time()) {
            return false;
        }

        return $this->platformAllows($snapshot, $platform, $capability);
    }

    /** @param array<string, mixed> $snapshot */
    private function platformAllows(array $snapshot, string $platform, ?string $capability): bool
    {
        if (!in_array($platform, $snapshot['platforms'] ?? [], true)) {
            return false;
        }

        if ($capability === null) {
            return true;
        }

        return in_array($capability, $snapshot['capabilities'][$platform] ?? [], true);
    }

    /** @param array<string, mixed> $cached */
    private function validCacheShape(array $cached): bool
    {
        return array_key_exists('enabled', $cached)
            && array_key_exists('effective_until', $cached)
            && array_key_exists('version', $cached)
            && array_key_exists('module_version', $cached)
            && array_key_exists('module_lock_version', $cached)
            && isset($cached['platforms'], $cached['capabilities'])
            && is_bool($cached['enabled'])
            && ($cached['effective_until'] === null || is_int($cached['effective_until']))
            && is_int($cached['version'])
            && is_string($cached['module_version'])
            && is_int($cached['module_lock_version'])
            && is_array($cached['platforms'])
            && is_array($cached['capabilities']);
    }

    private function expired(mixed $expireAt): bool
    {
        return $expireAt !== null && $expireAt !== '' && strtotime((string) $expireAt) <= time();
    }

    private function assertIdentifiers(int $organization, string $moduleKey): void
    {
        if ($organization <= 0 || !preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $moduleKey)) {
            throw new \InvalidArgumentException('Invalid organization or module_key.');
        }
    }
}
