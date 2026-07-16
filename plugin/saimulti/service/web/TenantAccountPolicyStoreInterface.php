<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface TenantAccountPolicyStoreInterface
{
    /** @return array<string, mixed>|null */
    public function find(int $organization, bool $lock = false): ?array;

    /** @param array<string, mixed> $defaults */
    public function createDefault(int $organization, array $defaults): void;
}
