<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImAuthStoreInterface
{
    /** @return array<string, mixed>|null */
    public function findActiveLoginUser(int $organization, string $account): ?array;

    /** @return array<string, mixed>|null */
    public function findActiveUser(int $organization, int $id, string $userId): ?array;

    /** @param array<string, mixed> $audit */
    public function recordLoginAudit(array $audit): void;

    /** @param array<string, mixed> $audit @param array<string, mixed> $accessSession */
    public function recordSuccessfulLogin(
        int $organization,
        int $id,
        string $loginAt,
        array $audit,
        array $accessSession,
    ): void;

    /**
     * Device and credential session writes must commit atomically.
     *
     * @param array<string, mixed> $device
     * @param array<string, mixed> $session
     * @param array<string, mixed> $accessSession
     */
    public function upsertChallenge(array $device, array $session, array $accessSession): void;

    public function updateAvatar(
        int $organization,
        int $id,
        string $userId,
        string $avatarFileId,
        string $updateTime,
    ): void;
}
