<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImUploadReservationServiceInterface
{
    /** @param array<string,mixed> $reservation @return array<string,mixed> */
    public function prepare(array $reservation): array;

    /** @param array<string,mixed> $intent @return array<string,mixed>|null */
    public function findPrepare(array $intent): ?array;

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    public function refreshPrepare(array $intent): array;

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    public function claim(array $identity, string $uploadId): array;

    public function releaseBeforeObject(int $reservationId, string $leaseToken, string $reason): void;

    public function renewUploadLease(int $reservationId, string $leaseToken): bool;

    public function markObjectUploaded(int $reservationId, string $leaseToken): void;

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    public function confirm(array $identity, string $uploadId): array;

    public function registerObjectCleanup(int $reservationId, string $reason): void;

    /** @param array<string,mixed> $identity @return array{released:bool,state:string} */
    public function release(array $identity, string $uploadId): array;

    /**
     * @return array{
     *   scanned:int,
     *   rows:list<array<string,mixed>>,
     *   errors:list<array{reservation_id:int,phase:string,code:int,message:string}>
     * }
     */
    public function claimCleanupBatch(int $limit): array;

    public function authorizeCleanupDelete(
        int $id,
        string $leaseToken,
        int $claimedVersion,
        int $organization,
        string $storagePath,
    ): bool;

    public function cleanupSucceeded(int $id, string $leaseToken, int $claimedVersion): bool;

    public function cleanupFailed(
        int $id,
        string $leaseToken,
        int $claimedVersion,
        string $error,
    ): bool;
}
