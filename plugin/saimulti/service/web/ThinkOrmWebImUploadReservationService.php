<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\quota\StorageQuotaAuthority;
use support\think\Db;
use Throwable;

final class ThinkOrmWebImUploadReservationService implements WebImUploadReservationServiceInterface
{
    private const TABLE = 'sm_im_upload_reservation';
    private const CLEANUP_CURSOR_TABLE = 'sm_im_upload_cleanup_cursor';
    private const UPLOAD_LEASE_SECONDS = 120;
    private const PREPARE_TTL_SECONDS = 900;
    private const TERMINAL_EXPIRES_AT = '9999-12-31 23:59:59.999999';
    private const MAX_DB_UNSIGNED_INT = 4_294_967_295;
    private const CLEANUP_CLAIM_ERROR = 'cleanup claim authority unavailable';
    private const CLEANUP_ELIGIBLE_PREDICATE =
        "((state='cleanup_pending' AND (cleanup_next_at IS NULL OR cleanup_next_at<=NOW())
            AND (cleanup_lease_expires_at IS NULL OR cleanup_lease_expires_at<=NOW()))
         OR (state='reserved' AND expires_at<=NOW())
         OR (state='uploading' AND upload_lease_expires_at<=NOW())
         OR (state='object_uploaded' AND update_time<=DATE_SUB(NOW(), INTERVAL 30 MINUTE)))";

    public function __construct(WebImUploadPolicyInterface $policy)
    {
    }

    public function prepare(array $reservation): array
    {
        return Db::transaction(function () use ($reservation): array {
            $quota = (new StorageQuotaAuthority())->lock(
                (int) $reservation['organization'],
            );
            $existing = Db::table(self::TABLE)
                ->where('organization', (int) $reservation['organization'])
                ->where('idempotency_key', (string) $reservation['idempotency_key'])
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                $this->assertInvariant($existing, (int) $reservation['organization']);
                if (!$this->sameIntent($existing, $reservation)
                    || !$this->reusableIdempotencyReservation($existing)) {
                    throw new ApiException('幂等键已用于其他上传意图或已结束上传。', 409);
                }
                return $this->refreshNonTerminalExpiry($existing);
            }
            $size = (int) $reservation['size_bytes'];
            if ((int) $quota['quota_value'] > 0
                && (int) $quota['occupancy_value'] > (int) $quota['quota_value'] - $size) {
                throw new ApiException('机构存储配额不足。', 422);
            }
            Db::table(self::TABLE)->insert($reservation);
            $created = Db::table(self::TABLE)
                ->where('organization', (int) $reservation['organization'])
                ->where('upload_id', (string) $reservation['upload_id'])
                ->find();
            if (!is_array($created)) {
                throw new \RuntimeException('Upload reservation insert was not observable.');
            }
            $this->assertInvariant($created, (int) $reservation['organization']);
            return $created;
        });
    }

    public function findPrepare(array $intent): ?array
    {
        $row = Db::table(self::TABLE)
            ->where('organization', (int) $intent['organization'])
            ->where('idempotency_key', (string) $intent['idempotency_key'])
            ->find();
        if (!is_array($row)) {
            return null;
        }
        $this->assertInvariant($row, (int) $intent['organization']);
        if (!$this->sameIntent($row, $intent)
            || !$this->reusableIdempotencyReservation($row)) {
            throw new ApiException('幂等键已用于其他上传意图或已结束上传。', 409);
        }

        return $row;
    }

    public function refreshPrepare(array $intent): array
    {
        return Db::transaction(function () use ($intent): array {
            (new StorageQuotaAuthority())->lock((int) $intent['organization']);
            $row = Db::table(self::TABLE)
                ->where('organization', (int) $intent['organization'])
                ->where('idempotency_key', (string) $intent['idempotency_key'])
                ->lock(true)
                ->find();
            if (is_array($row)) {
                $this->assertInvariant($row, (int) $intent['organization']);
            }
            if (!is_array($row)
                || !$this->sameIntent($row, $intent)
                || !$this->reusableIdempotencyReservation($row)) {
                throw new ApiException('幂等键已用于其他上传意图或已结束上传。', 409);
            }

            return $this->refreshNonTerminalExpiry($row);
        });
    }

    public function claim(array $identity, string $uploadId): array
    {
        $initial = $this->ownedReservation($identity, $uploadId, false);
        if (in_array((string) $initial['state'], ['confirmed', 'object_uploaded'], true)) {
            return $initial;
        }
        if ((string) $initial['state'] !== 'reserved') {
            throw new ApiException('上传已被处理或正在处理中。', 409);
        }

        return Db::transaction(function () use ($identity, $uploadId): array {
            (new StorageQuotaAuthority())->lock((int) $identity['organization']);
            $row = $this->lockReservation($identity, $uploadId);
            if ((string) $row['state'] === 'confirmed') {
                return $row;
            }
            if ((string) $row['state'] === 'object_uploaded') {
                return $row;
            }
            if ((string) $row['state'] !== 'reserved') {
                throw new ApiException('上传已被处理或正在处理中。', 409);
            }
            if (strtotime((string) $row['expires_at']) <= time()) {
                throw new ApiException('上传预留已过期。', 409);
            }
            $token = bin2hex(random_bytes(32));
            Db::table(self::TABLE)->where('id', (int) $row['id'])->update([
                'state' => 'uploading',
                'upload_lease_token' => $token,
                'upload_lease_expires_at' => date(
                    'Y-m-d H:i:s',
                    time() + self::UPLOAD_LEASE_SECONDS,
                ),
                'version' => (int) $row['version'] + 1,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            $row['state'] = 'uploading';
            $row['upload_lease_token'] = $token;
            return $row;
        });
    }

    public function renewUploadLease(int $reservationId, string $leaseToken): bool
    {
        $updated = Db::table(self::TABLE)
            ->where('id', $reservationId)
            ->where('state', 'uploading')
            ->where('upload_lease_token', $leaseToken)
            ->where('upload_lease_expires_at', '>', date('Y-m-d H:i:s'))
            ->update([
                'upload_lease_expires_at' => Db::raw(
                    'DATE_ADD(NOW(6), INTERVAL ' . self::UPLOAD_LEASE_SECONDS . ' SECOND)',
                ),
                'update_time' => Db::raw('NOW(6)'),
            ]);

        return (int) $updated === 1;
    }

    public function releaseBeforeObject(int $reservationId, string $leaseToken, string $reason): void
    {
        $organization = $this->reservationOrganization($reservationId);
        if ($organization === null) {
            return;
        }
        Db::transaction(function () use ($reservationId, $leaseToken, $reason, $organization): void {
            (new StorageQuotaAuthority())->lock($organization);
            Db::table(self::TABLE)
                ->where('id', $reservationId)
                ->where('organization', $organization)
                ->where('state', 'uploading')
                ->where('upload_lease_token', $leaseToken)
                ->update([
                    'state' => 'released',
                    'released_at' => date('Y-m-d H:i:s'),
                    'release_reason' => substr($reason, 0, 32),
                    'upload_lease_token' => null,
                    'upload_lease_expires_at' => null,
                    'version' => Db::raw('version+1'),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
        });
    }

    public function markObjectUploaded(int $reservationId, string $leaseToken): void
    {
        $updated = Db::table(self::TABLE)
            ->where('id', $reservationId)
            ->where('state', 'uploading')
            ->where('upload_lease_token', $leaseToken)
            ->where('upload_lease_expires_at', '>', date('Y-m-d H:i:s'))
            ->update([
                'state' => 'object_uploaded',
                'expires_at' => self::TERMINAL_EXPIRES_AT,
                'upload_lease_token' => null,
                'upload_lease_expires_at' => null,
                'version' => Db::raw('version+1'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        if ((int) $updated !== 1) {
            throw new \RuntimeException('Upload reservation lease was lost after object upload.');
        }
    }

    public function confirm(array $identity, string $uploadId): array
    {
        return Db::transaction(function () use ($identity, $uploadId): array {
            $quota = (new StorageQuotaAuthority())->lock(
                (int) $identity['organization'],
            );
            $row = $this->lockReservation($identity, $uploadId);
            if ((string) $row['state'] === 'confirmed') {
                return $this->confirmedMetadata($row);
            }
            if ((string) $row['state'] !== 'object_uploaded') {
                throw new ApiException('上传对象尚未完成可信校验。', 409);
            }
            $asset = [
                'organization' => (int) $row['organization'],
                'file_id' => (string) $row['file_id'],
                'user_id' => (string) $row['user_id'],
                'kind' => (string) $row['kind'],
                'name' => (string) $row['filename'],
                'url' => '',
                'storage_path' => (string) $row['storage_path'],
                'size_byte' => (int) $row['size_bytes'],
                'mime_type' => (string) $row['mime_type'],
                'extension' => (string) $row['extension'],
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ];
            $existingAsset = Db::table('im_upload_asset')
                ->where('organization', (int) $row['organization'])
                ->where('file_id', (string) $row['file_id'])
                ->lock(true)
                ->find();
            if (is_array($existingAsset)) {
                if (!$this->sameConfirmedAsset($existingAsset, $asset)) {
                    throw new ApiException('可信附件编号与既有事实冲突。', 409);
                }
            } else {
                Db::table('im_upload_asset')->insert($asset);
            }
            $newPhysicalPath = !isset(
                $quota['physical_paths'][(string) $row['storage_path']],
            );
            if ($newPhysicalPath) {
                $quotaRow = $quota['row'];
                $updated = Db::table('sm_tenant_quota')
                    ->where('id', (int) $quotaRow['id'])
                    ->where('version', (int) $quotaRow['version'])
                    ->update([
                        'used_value' => (int) $quota['used_value'] + (int) $row['size_bytes'],
                        'version' => (int) $quotaRow['version'] + 1,
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                if ((int) $updated !== 1) {
                    throw new ApiException('机构存储配额版本冲突。', 409);
                }
            }
            Db::table(self::TABLE)->where('id', (int) $row['id'])->update([
                'state' => 'confirmed',
                'confirmed_at' => date('Y-m-d H:i:s'),
                'expires_at' => self::TERMINAL_EXPIRES_AT,
                'cleanup_lease_token' => null,
                'cleanup_lease_expires_at' => null,
                'version' => (int) $row['version'] + 1,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            return $this->confirmedMetadata($row);
        });
    }

    public function registerObjectCleanup(int $reservationId, string $reason): void
    {
        Db::transaction(function () use ($reservationId, $reason): void {
            $row = Db::table(self::TABLE)
                ->where('id', $reservationId)
                ->lock(true)
                ->find();
            if (!is_array($row) || (string) $row['state'] === 'confirmed') {
                return;
            }
            Db::table(self::TABLE)->where('id', $reservationId)->update([
                'state' => 'cleanup_pending',
                'cleanup_next_at' => date('Y-m-d H:i:s'),
                'cleanup_error' => mb_substr($reason, 0, 255),
                'cleanup_lease_token' => null,
                'cleanup_lease_expires_at' => null,
                'upload_lease_token' => null,
                'upload_lease_expires_at' => null,
                'released_at' => null,
                'release_reason' => '',
                'version' => (int) $row['version'] + 1,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    public function release(array $identity, string $uploadId): array
    {
        return Db::transaction(function () use ($identity, $uploadId): array {
            (new StorageQuotaAuthority())->lock((int) $identity['organization']);
            $row = $this->lockReservation($identity, $uploadId);
            $state = (string) $row['state'];
            if ($state === 'confirmed') {
                return ['released' => false, 'state' => 'confirmed'];
            }
            if ($state !== 'reserved') {
                return ['released' => $state === 'released', 'state' => $state];
            }
            Db::table(self::TABLE)->where('id', (int) $row['id'])->update([
                'state' => 'released',
                'released_at' => date('Y-m-d H:i:s'),
                'release_reason' => 'client_release',
                'version' => (int) $row['version'] + 1,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            return ['released' => true, 'state' => 'released'];
        });
    }

    public function claimCleanupBatch(int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $claimed = [];
        $errors = [];
        $ids = $this->claimCleanupCandidateIds($limit);
        foreach ($ids as $idValue) {
            $id = (int) $idValue;
            try {
                $row = $this->claimCleanupCandidate($id);
                if (is_array($row)) {
                    $claimed[] = $row;
                }
            } catch (Throwable $exception) {
                error_log(sprintf(
                    'Upload cleanup claim failed [reservation=%d class=%s code=%d]',
                    $id,
                    $exception::class,
                    $exception->getCode(),
                ));
                $quarantined = false;
                try {
                    $quarantined = $this->quarantineCleanupCandidate($id);
                } catch (Throwable $quarantineException) {
                    error_log(sprintf(
                        'Upload cleanup quarantine failed [reservation=%d class=%s code=%d]',
                        $id,
                        $quarantineException::class,
                        $quarantineException->getCode(),
                    ));
                }
                $errors[] = [
                    'reservation_id' => $id,
                    'phase' => $quarantined ? 'claim' : 'claim_quarantine',
                    'code' => $exception instanceof ApiException
                        && $exception->getCode() >= 400
                        && $exception->getCode() <= 599
                            ? $exception->getCode()
                            : 503,
                    'message' => $quarantined
                        ? '上传孤儿清理候选权威事实不可用，已延后重试。'
                        : '上传孤儿清理候选权威事实不可用且延后重试失败。',
                ];
            }
        }

        return ['scanned' => count($ids), 'rows' => $claimed, 'errors' => $errors];
    }

    /** @return list<int> */
    private function claimCleanupCandidateIds(int $limit): array
    {
        return Db::transaction(function () use ($limit): array {
            $cursorRows = Db::query(
                'SELECT id,last_reservation_id
                   FROM ' . self::CLEANUP_CURSOR_TABLE . '
                  ORDER BY id FOR UPDATE',
            );
            if (count($cursorRows) !== 1 || (int) ($cursorRows[0]['id'] ?? 0) !== 1) {
                throw new ApiException('上传孤儿清理游标事实不可用。', 503);
            }
            $cursor = $this->cleanupDbInteger(
                $cursorRows[0]['last_reservation_id'] ?? null,
                false,
            );
            $idValues = Db::table(self::TABLE)
                ->where('id', '>', $cursor)
                ->whereRaw(self::CLEANUP_ELIGIBLE_PREDICATE)
                ->order('id', 'asc')
                ->limit($limit)
                ->column('id');
            if ($idValues === [] && $cursor > 0) {
                $idValues = Db::table(self::TABLE)
                    ->whereRaw(self::CLEANUP_ELIGIBLE_PREDICATE)
                    ->order('id', 'asc')
                    ->limit($limit)
                    ->column('id');
            }
            $ids = [];
            $previous = 0;
            foreach ($idValues as $idValue) {
                $id = $this->cleanupDbInteger($idValue, true);
                if ($id <= $previous) {
                    throw new ApiException('上传孤儿清理候选顺序事实非法。', 503);
                }
                $ids[] = $id;
                $previous = $id;
            }
            if ($ids !== []) {
                $updated = Db::table(self::CLEANUP_CURSOR_TABLE)
                    ->where('id', 1)
                    ->update([
                        'last_reservation_id' => $ids[array_key_last($ids)],
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                if ((int) $updated !== 1) {
                    throw new ApiException('上传孤儿清理游标推进失败。', 503);
                }
            }

            return $ids;
        });
    }

    private function cleanupDbInteger(mixed $value, bool $positive): int
    {
        $pattern = $positive ? '/^[1-9]\d*$/' : '/^(?:0|[1-9]\d*)$/';
        if (is_int($value)) {
            if ($value >= ($positive ? 1 : 0)) {
                return $value;
            }
        } elseif (is_string($value) && preg_match($pattern, $value) === 1) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($maximum)
                || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0)) {
                return (int) $value;
            }
        }
        throw new ApiException('上传孤儿清理整数事实非法。', 503);
    }

    /** @return array<string,mixed>|null */
    private function claimCleanupCandidate(int $id): ?array
    {
        $identity = Db::table(self::TABLE)
            ->field('organization')
            ->where('id', $id)
            ->find();
        if (!is_array($identity)) {
            return null;
        }
        $organization = (int) $identity['organization'];
        if ($organization <= 0) {
            throw new ApiException('上传孤儿清理机构事实非法。', 503);
        }

        return Db::transaction(function () use ($id, $organization): ?array {
            $quota = (new StorageQuotaAuthority())->lockForHeldCleanup($organization);
            $row = Db::table(self::TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->lock(true)
                ->find();
            if (!is_array($row) || !$this->cleanupEligible($row)
                || isset($quota['physical_paths'][(string) $row['storage_path']])) {
                return null;
            }
            $version = $this->cleanupDbInteger($row['version'] ?? null, true);
            $attempts = $this->cleanupDbInteger(
                $row['cleanup_attempts'] ?? null,
                false,
            );
            if ($version >= self::MAX_DB_UNSIGNED_INT
                || $attempts >= self::MAX_DB_UNSIGNED_INT) {
                throw new ApiException('上传孤儿清理计数事实已耗尽。', 503);
            }
            $token = bin2hex(random_bytes(32));
            $claimedVersion = $version + 1;
            Db::table(self::TABLE)->where('id', $id)->update([
                'state' => 'cleanup_pending',
                'upload_lease_token' => null,
                'upload_lease_expires_at' => null,
                'cleanup_lease_token' => $token,
                'cleanup_lease_expires_at' => date('Y-m-d H:i:s', time() + 300),
                'cleanup_attempts' => $attempts + 1,
                'version' => $claimedVersion,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            $row['state'] = 'cleanup_pending';
            $row['cleanup_lease_token'] = $token;
            $row['cleanup_claimed_version'] = $claimedVersion;
            $row['version'] = $claimedVersion;
            return $row;
        });
    }

    private function quarantineCleanupCandidate(int $id): bool
    {
        return Db::transaction(function () use ($id): bool {
            $row = Db::table(self::TABLE)
                ->where('id', $id)
                ->lock(true)
                ->find();
            if (!is_array($row)
                || !in_array((string) ($row['state'] ?? ''), StorageQuotaAuthority::HELD_STATES, true)
                || !$this->cleanupEligible($row)) {
                return false;
            }
            $attempts = (int) ($row['cleanup_attempts'] ?? -1);
            $version = (int) ($row['version'] ?? 0);
            if ($attempts < 0 || $attempts >= self::MAX_DB_UNSIGNED_INT
                || $version <= 0 || $version >= self::MAX_DB_UNSIGNED_INT) {
                return false;
            }
            $nextAttempts = $attempts + 1;
            return (int) Db::table(self::TABLE)
                ->where('id', $id)
                ->where('state', (string) $row['state'])
                ->where('version', $version)
                ->update([
                    'state' => 'cleanup_pending',
                    'upload_lease_token' => null,
                    'upload_lease_expires_at' => null,
                    'cleanup_lease_token' => null,
                    'cleanup_lease_expires_at' => null,
                    'cleanup_attempts' => $nextAttempts,
                    'cleanup_next_at' => date(
                        'Y-m-d H:i:s',
                        time() + $this->cleanupRetryDelay($nextAttempts),
                    ),
                    'cleanup_error' => self::CLEANUP_CLAIM_ERROR,
                    'version' => $version + 1,
                    'update_time' => date('Y-m-d H:i:s'),
                ]) === 1;
        });
    }

    public function authorizeCleanupDelete(
        int $id,
        string $leaseToken,
        int $claimedVersion,
        int $organization,
        string $storagePath,
    ): bool {
        if ($id <= 0 || $claimedVersion <= 0 || $organization <= 0
            || preg_match('/^[a-f0-9]{64}$/', $leaseToken) !== 1
            || $storagePath === '') {
            return false;
        }

        return Db::transaction(function () use (
            $id,
            $leaseToken,
            $claimedVersion,
            $organization,
            $storagePath,
        ): bool {
            $quota = (new StorageQuotaAuthority())->lockForHeldCleanup($organization);
            $row = Db::table(self::TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->lock(true)
                ->find();
            if (!is_array($row)
                || (string) ($row['state'] ?? '') !== 'cleanup_pending'
                || !hash_equals((string) ($row['cleanup_lease_token'] ?? ''), $leaseToken)
                || (int) ($row['version'] ?? 0) !== $claimedVersion
                || !hash_equals((string) ($row['storage_path'] ?? ''), $storagePath)
                || strtotime((string) ($row['cleanup_lease_expires_at'] ?? '')) <= time()
                || isset($quota['physical_paths'][$storagePath])) {
                return false;
            }
            $updated = Db::table(self::TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->where('state', 'cleanup_pending')
                ->where('cleanup_lease_token', $leaseToken)
                ->where('version', $claimedVersion)
                ->update([
                    // cleanup_pending remains a held-path fence while the network
                    // delete runs; the extended lease prevents another worker
                    // taking ownership during that bounded operation.
                    'cleanup_lease_expires_at' => date('Y-m-d H:i:s', time() + 900),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);

            return (int) $updated === 1;
        });
    }

    /** @param array<string,mixed> $row */
    private function cleanupEligible(array $row): bool
    {
        $now = time();
        $state = (string) ($row['state'] ?? '');
        if ($state === 'cleanup_pending') {
            return $this->dueAt($row['cleanup_next_at'] ?? null, $now, true)
                && $this->dueAt($row['cleanup_lease_expires_at'] ?? null, $now, true);
        }
        if ($state === 'reserved') {
            return $this->dueAt($row['expires_at'] ?? null, $now, false);
        }
        if ($state === 'uploading') {
            return $this->dueAt($row['upload_lease_expires_at'] ?? null, $now, false);
        }
        if ($state === 'object_uploaded') {
            $updatedAt = strtotime((string) ($row['update_time'] ?? ''));
            return $updatedAt !== false && $updatedAt <= ($now - 1800);
        }
        return false;
    }

    private function dueAt(mixed $value, int $now, bool $nullIsDue): bool
    {
        if ($value === null) {
            return $nullIsDue;
        }
        $timestamp = strtotime((string) $value);
        return $timestamp !== false && $timestamp <= $now;
    }

    public function cleanupSucceeded(int $id, string $leaseToken, int $claimedVersion): bool
    {
        $organization = $this->reservationOrganization($id);
        if ($organization === null) {
            return false;
        }
        return Db::transaction(function () use (
            $id,
            $leaseToken,
            $claimedVersion,
            $organization,
        ): bool {
            (new StorageQuotaAuthority())->lockForHeldCleanup($organization);
            return (int) Db::table(self::TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->where('state', 'cleanup_pending')
                ->where('cleanup_lease_token', $leaseToken)
                ->where('version', $claimedVersion)
                ->update([
                    'state' => 'released',
                    'released_at' => date('Y-m-d H:i:s'),
                    'release_reason' => 'cleanup',
                    'cleanup_lease_token' => null,
                    'cleanup_lease_expires_at' => null,
                    'cleanup_error' => '',
                    'version' => $claimedVersion + 1,
                    'update_time' => date('Y-m-d H:i:s'),
                ]) === 1;
        });
    }

    public function cleanupFailed(
        int $id,
        string $leaseToken,
        int $claimedVersion,
        string $error,
    ): bool
    {
        $row = Db::table(self::TABLE)
            ->where('id', $id)
            ->where('state', 'cleanup_pending')
            ->where('cleanup_lease_token', $leaseToken)
            ->where('version', $claimedVersion)
            ->find();
        if (!is_array($row)) {
            return false;
        }
        $attempts = max(1, (int) ($row['cleanup_attempts'] ?? 1));
        $delay = $this->cleanupRetryDelay($attempts);
        return (int) Db::table(self::TABLE)
            ->where('id', $id)
            ->where('state', 'cleanup_pending')
            ->where('cleanup_lease_token', $leaseToken)
            ->where('version', $claimedVersion)
            ->update([
                'cleanup_lease_token' => null,
                'cleanup_lease_expires_at' => null,
                'cleanup_next_at' => date('Y-m-d H:i:s', time() + $delay),
                'cleanup_error' => mb_substr($error, 0, 255),
                'version' => $claimedVersion + 1,
                'update_time' => date('Y-m-d H:i:s'),
            ]) === 1;
    }

    private function cleanupRetryDelay(int $attempts): int
    {
        return min(3600, 30 * (2 ** min(7, max(0, $attempts - 1))));
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function lockReservation(array $identity, string $uploadId): array
    {
        return $this->ownedReservation($identity, $uploadId, true);
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function ownedReservation(array $identity, string $uploadId, bool $lock): array
    {
        $query = Db::table(self::TABLE)
            ->where('organization', (int) $identity['organization'])
            ->where('upload_id', $uploadId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row) || (string) $row['user_id'] !== (string) $identity['user_id']
            || (string) $row['client_family'] !== (string) $identity['client_family']) {
            throw new ApiException('上传预留不存在。', 404);
        }
        $this->assertInvariant($row, (int) $identity['organization']);
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function assertInvariant(array $row, int $organization): string
    {
        return (new WebImUploadReservationInvariant())->assert($row, $organization);
    }

    private function reservationOrganization(int $id): ?int
    {
        if ($id <= 0) {
            return null;
        }
        $row = Db::table(self::TABLE)->field('organization')->where('id', $id)->find();
        $organization = is_array($row) ? (int) ($row['organization'] ?? 0) : 0;

        return $organization > 0 ? $organization : null;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $intent */
    private function sameIntent(array $existing, array $intent): bool
    {
        return hash_equals((string) $existing['intent_hash'], (string) $intent['intent_hash'])
            && (string) $existing['user_id'] === (string) $intent['user_id']
            && (string) $existing['client_family'] === (string) $intent['client_family'];
    }

    /** @param array<string,mixed> $row */
    private function reusableIdempotencyReservation(array $row): bool
    {
        $state = (string) ($row['state'] ?? '');
        if ($state === 'confirmed' || $state === 'object_uploaded') {
            return true;
        }
        if ($state === 'reserved') {
            $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
            return $expiresAt !== false && $expiresAt > time();
        }
        if ($state === 'uploading') {
            $leaseExpiresAt = strtotime((string) ($row['upload_lease_expires_at'] ?? ''));
            return $leaseExpiresAt !== false && $leaseExpiresAt > time();
        }
        return false;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function refreshNonTerminalExpiry(array $row): array
    {
        if (!in_array((string) ($row['state'] ?? ''), ['reserved', 'uploading'], true)) {
            return $row;
        }
        $expiresAt = date('Y-m-d H:i:s', time() + self::PREPARE_TTL_SECONDS);
        $updated = Db::table(self::TABLE)
            ->where('id', (int) $row['id'])
            ->where('version', (int) $row['version'])
            ->whereIn('state', ['reserved', 'uploading'])
            ->update([
                'expires_at' => $expiresAt,
                'version' => (int) $row['version'] + 1,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        if ((int) $updated !== 1) {
            throw new ApiException('上传预留版本冲突。', 409);
        }
        $row['expires_at'] = $expiresAt;
        $row['version'] = (int) $row['version'] + 1;

        return $row;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $expected */
    private function sameConfirmedAsset(array $existing, array $expected): bool
    {
        foreach ([
            'organization',
            'file_id',
            'user_id',
            'kind',
            'name',
            'url',
            'storage_path',
            'size_byte',
            'mime_type',
            'extension',
            'status',
        ] as $field) {
            if ((string) ($existing[$field] ?? '') !== (string) ($expected[$field] ?? '')) {
                return false;
            }
        }

        return ($existing['delete_time'] ?? null) === null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function confirmedMetadata(array $row): array
    {
        return [
            'file_id' => (string) $row['file_id'],
            'kind' => (string) $row['kind'],
            'name' => (string) $row['filename'],
            'size' => (int) $row['size_bytes'],
            'mime_type' => (string) $row['mime_type'],
            'extension' => (string) $row['extension'],
        ];
    }
}
