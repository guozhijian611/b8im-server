<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebAccessIssuerInterface
{
    /** @param array<string, mixed> $organization @param array<string, mixed> $user @return array<string, mixed> */
    public function issueAccessForUser(
        array $organization,
        array $user,
        string $deviceId,
        string $clientFamily,
        string $os,
        string $clientIp,
        string $auditScope,
        ?int $now = null,
    ): array;

    public function recordLoginEvent(
        int $organization,
        string $userId,
        ?string $deviceId,
        ?string $loginIp,
        string $clientFamily,
        string $os,
        string $result,
        string $auditScope,
        ?string $failureCode = null,
        ?int $now = null,
    ): void;
}
