<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface TenantAccountPolicyStoreInterface
{
    public function transaction(callable $callback): mixed;

    /** @return array<string, mixed>|null */
    public function find(int $organization, bool $lock = false): ?array;

    /** @param array<string, mixed> $defaults */
    public function createDefault(int $organization, array $defaults): void;

    /** @param array<string, mixed> $values */
    public function updateRegisterEnabled(int $organization, int $expectedVersion, array $values): bool;
}
