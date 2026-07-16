<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;

interface WebQrLoginStoreInterface
{
    public function transaction(Closure $callback): mixed;

    /** @return array<string, mixed> */
    public function lockActiveOrganization(int $organization, string $deploymentId): array;

    /** @return array<string, mixed> */
    public function lockActiveUser(int $organization, int $id, string $userId): array;

    /** @return array<string, mixed>|null */
    public function lockImPolicy(int $organization): ?array;

    /** @param array<string, mixed> $row */
    public function insert(array $row): void;

    /** @return array<string, mixed>|null */
    public function find(int $organization, string $deploymentId, string $qrId, bool $lock = false): ?array;

    /** @param array<string, mixed> $changes */
    public function transition(int $id, string $fromStatus, array $changes): bool;
}
