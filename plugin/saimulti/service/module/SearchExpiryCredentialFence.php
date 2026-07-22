<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\Module\Search\Lifecycle\LifecycleFence;
use RuntimeException;
use support\think\Db;

/**
 * Makes an expiry-triggered search fence conditional on the exact durable
 * EXPIRED license version that authorized it. A concurrent renewal that wins
 * before this hook turns the stale hook into an atomic no-op.
 */
final class SearchExpiryCredentialFence implements LifecycleFence
{
    /** @param array{license_id:string,organization:int,module_key:string,expired_version:int} $credential */
    public function __construct(
        private readonly LifecycleFence $inner,
        private readonly array $credential,
    ) {
    }

    public function assertReadyForEnable(?int $organization): void
    {
        $this->inner->assertReadyForEnable($organization);
    }

    public function clearLifecycleFenceForEnable(?int $organization): void
    {
        $this->inner->clearLifecycleFenceForEnable($organization);
    }

    public function fenceForUpgrade(string $fromVersion, string $targetVersion): void
    {
        $this->inner->fenceForUpgrade($fromVersion, $targetVersion);
    }

    public function fenceForDisable(?int $organization): void
    {
        if ($organization === null || $organization !== $this->credential['organization']) {
            throw new RuntimeException('Search expiry fence organization credential is invalid.');
        }
        $rows = Db::query(
            'SELECT status,version FROM sm_tenant_module_license'
            . ' WHERE id=? AND organization=? AND module_key=? AND delete_time IS NULL FOR UPDATE',
            [
                $this->credential['license_id'],
                $organization,
                $this->credential['module_key'],
            ],
        );
        if (count($rows) !== 1
            || (string) ($rows[0]['status'] ?? '') !== 'EXPIRED'
            || (int) ($rows[0]['version'] ?? -1) !== $this->credential['expired_version']) {
            throw new ModuleExpiryHookCredentialSuperseded(
                'Search expiry fence exact credential 已被续期或并发变更取代。',
            );
        }
        $this->inner->fenceForDisable($organization);
    }

    public function fenceForUninstall(bool $preserveData): void
    {
        $this->inner->fenceForUninstall($preserveData);
    }
}
