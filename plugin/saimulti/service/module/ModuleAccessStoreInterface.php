<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

interface ModuleAccessStoreInterface
{
    /** @return array<string, mixed>|null */
    public function tenantSnapshot(int $organization, string $moduleKey): ?array;

    /** @return array<string, mixed>|null */
    public function systemSnapshot(string $moduleKey): ?array;

    /** @return list<array<string, mixed>> */
    public function enabledTenantSnapshots(int $organization): array;

    /** @return list<array<string, mixed>> */
    public function enabledSystemSnapshots(): array;

    /** @return list<int> */
    public function organizationsForModule(string $moduleKey): array;
}
