<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\tenantPolicy;

interface TenantImPolicyStoreInterface
{
    public function transaction(callable $callback): mixed;

    public function organizationExists(int $organization): bool;

    /** @return array<string, mixed>|null */
    public function find(int $organization, bool $forUpdate = false): ?array;

    /** @param array<string, mixed> $policy */
    public function createDefault(int $organization, array $policy): void;

    /** @param array<string, mixed> $policy */
    public function update(int $organization, int $expectedVersion, array $policy): bool;
}
