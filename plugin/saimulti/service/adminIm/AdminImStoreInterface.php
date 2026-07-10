<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

interface AdminImStoreInterface
{
    /** @return array{status: string} */
    public function databaseStatus(): array;

    /** @return array{status: string, missing: list<string>} */
    public function schemaStatus(): array;

    /** @return array<string, array<string, int>> */
    public function statistics(string $now): array;

    /** @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int} */
    public function users(array $filters, int $page, int $limit): array;

    /** @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int} */
    public function devices(array $filters, int $page, int $limit): array;

    /** @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int} */
    public function sessions(array $filters, int $page, int $limit): array;

    /** @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int} */
    public function loginAudits(array $filters, int $page, int $limit): array;

    public function transaction(callable $callback): mixed;

    /** @return array<string, mixed>|null */
    public function lockDeviceById(int $id): ?array;

    /** @return array<string, mixed>|null */
    public function lockSessionById(int $id): ?array;

    /** @return list<string> */
    public function activeSessionIdsForDevice(int $organization, string $userId, string $deviceId): array;

    /** @return list<string> */
    public function activeSessionIdsForWebAccess(
        int $organization,
        string $userId,
        string $deviceId,
        string $webAccessJti,
    ): array;

    public function setDeviceStatus(
        int $id,
        int $organization,
        string $userId,
        string $deviceId,
        int $status,
        string $now,
    ): void;

    /** @param list<string> $sessionIds */
    public function revokeSessions(
        int $organization,
        string $userId,
        string $deviceId,
        array $sessionIds,
        string $now,
    ): void;

    public function revokeSession(int $id, int $organization, string $sessionId, string $now): void;

    public function revokeWebAccessForDevice(
        int $organization,
        string $userId,
        string $deviceId,
        string $now,
    ): void;

    public function revokeWebAccess(
        int $organization,
        string $userId,
        string $deviceId,
        string $webAccessJti,
        string $now,
    ): void;

    /**
     * @param array{id: int, username: string, ip: string} $actor
     * @param array<string, scalar|null> $target
     */
    public function appendOperationAudit(
        array $actor,
        int $organization,
        string $action,
        array $target,
        string $now,
    ): void;
}
