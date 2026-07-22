<?php

declare(strict_types=1);

namespace plugin\saimulti\service\quota;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\WebImUploadReservationInvariant;
use support\think\Db;
use Throwable;

final class StorageQuotaAuthority
{
    public const QUOTA_KEY = 'storage_bytes';
    public const HELD_STATES = ['reserved', 'uploading', 'object_uploaded', 'cleanup_pending'];
    private const RESERVATION_STATES = [
        'reserved',
        'uploading',
        'object_uploaded',
        'cleanup_pending',
        'confirmed',
        'released',
    ];

    /**
     * The caller must already be inside a transaction. The central row is always
     * locked before physical facts and reservations, which is the global lock order.
     *
     * @return array{
     *   row:array<string,mixed>,
     *   quota_value:int,
     *   used_value:int,
     *   held_value:int,
     *   occupancy_value:int,
     *   used_file_count:int,
     *   held_file_count:int,
     *   physical_paths:array<string,int>,
     *   held_paths:array<string,int>
     * }
     */
    public function lock(int $organization): array
    {
        return $this->lockSnapshot($organization, false);
    }

    /**
     * Cleanup-only authority view. It preserves every central, physical,
     * reservation and confirmed-path check, but permits an existing quota
     * shortfall while the caller exclusively removes an unconfirmed held row.
     *
     * @return array{
     *   row:array<string,mixed>,
     *   quota_value:int,
     *   used_value:int,
     *   held_value:int,
     *   occupancy_value:int,
     *   used_file_count:int,
     *   held_file_count:int,
     *   physical_paths:array<string,int>,
     *   held_paths:array<string,int>
     * }
     */
    public function lockForHeldCleanup(int $organization): array
    {
        return $this->lockSnapshot($organization, true);
    }

    /** @return array<string,mixed> */
    private function lockSnapshot(int $organization, bool $allowOverCapacity): array
    {
        if ($organization <= 0) {
            throw new ApiException('机构编号无效。', 422);
        }
        try {
            $row = Db::table('sm_tenant_quota')
                ->where('organization', $organization)
                ->where('quota_key', self::QUOTA_KEY)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new ApiException('机构存储配额不可用。', 503);
            }
            $quota = $this->dbUnsigned($row['quota_value'] ?? null, 'quota_value');
            $used = $this->dbUnsigned($row['used_value'] ?? null, 'used_value');
            $version = $this->dbPositive($row['version'] ?? null, 'version');
            if ((int) ($row['organization'] ?? 0) !== $organization
                || (string) ($row['quota_key'] ?? '') !== self::QUOTA_KEY
                || (string) ($row['status'] ?? '') !== 'active'
                || ($row['delete_time'] ?? null) !== null
                || !$this->activeWindow($row['start_at'] ?? null, $row['end_at'] ?? null)
                || $version < 1) {
                throw new ApiException('机构存储配额不可用。', 503);
            }

            $assets = Db::table('im_upload_asset')
                ->where('organization', $organization)
                ->lock(true)
                ->select()
                ->toArray();
            $paths = [];
            $assetsByFileId = [];
            foreach ($assets as $asset) {
                if ((int) ($asset['organization'] ?? 0) !== $organization) {
                    throw new ApiException('物理附件事实机构不一致。', 503);
                }
                $path = (string) ($asset['storage_path'] ?? '');
                $this->assertCanonicalPath($organization, $path, false);
                $size = $this->dbPositive($asset['size_byte'] ?? null, 'asset.size_byte');
                if (isset($paths[$path]) && $paths[$path] !== $size) {
                    throw new ApiException('同一物理附件路径存在冲突尺寸。', 503);
                }
                $paths[$path] = $size;
                $fileId = (string) ($asset['file_id'] ?? '');
                if ($fileId !== '') {
                    $assetsByFileId[$fileId] = $asset;
                }
            }
            $physical = 0;
            foreach ($paths as $size) {
                $physical = $this->safeAdd($physical, $size, 'physical usage');
            }

            $reservationRows = Db::table('sm_im_upload_reservation')
                ->where('organization', $organization)
                ->lock(true)
                ->select()
                ->toArray();
            $held = 0;
            $heldPaths = [];
            $heldCount = 0;
            foreach ($reservationRows as $reservation) {
                $state = $this->assertReservationFacts(
                    $organization,
                    $reservation,
                    $assetsByFileId,
                );
                if (!in_array($state, self::HELD_STATES, true)) {
                    continue;
                }
                $heldCount++;
                $heldPath = (string) ($reservation['storage_path'] ?? '');
                if (isset($paths[$heldPath]) || isset($heldPaths[$heldPath])) {
                    throw new ApiException(
                        '物理附件与上传预留存在冲突路径事实。',
                        503,
                    );
                }
                $heldSize = $this->dbPositive(
                    $reservation['size_bytes'] ?? null,
                    'reservation.size_bytes',
                );
                $heldPaths[$heldPath] = $heldSize;
                $held = $this->safeAdd($held, $heldSize, 'held usage');
            }
            $occupancy = $this->safeAdd($physical, $held, 'storage occupancy');
            if ($used !== $physical
                || (!$allowOverCapacity && $quota > 0 && $quota < $occupancy)) {
                throw new ApiException('机构存储配额与权威物理用量不一致。', 503);
            }

            return [
                'row' => $row,
                'quota_value' => $quota,
                'used_value' => $physical,
                'held_value' => $held,
                'occupancy_value' => $occupancy,
                'used_file_count' => count($paths),
                'held_file_count' => $heldCount,
                'physical_paths' => $paths,
                'held_paths' => $heldPaths,
            ];
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Storage quota authority unavailable [%s:%d]',
                $exception::class,
                $exception->getCode(),
            ));
            throw new ApiException('机构存储配额权威事实不可用。', 503);
        }
    }

    /** @return array<string,mixed> */
    public function read(int $organization): array
    {
        return Db::transaction(fn (): array => $this->lock($organization));
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function format(array $snapshot): array
    {
        $row = $snapshot['row'];
        $quota = (int) $snapshot['quota_value'];
        $occupancy = (int) $snapshot['occupancy_value'];

        return [
            'organization' => (int) $row['organization'],
            'quota_key' => self::QUOTA_KEY,
            'quota_value' => (string) $quota,
            'used_value' => (string) $snapshot['used_value'],
            'held_value' => (string) $snapshot['held_value'],
            'occupancy_value' => (string) $occupancy,
            'remaining_value' => $quota === 0 ? null : (string) ($quota - $occupancy),
            'unlimited' => $quota === 0,
            'used_file_count' => (int) $snapshot['used_file_count'],
            'held_file_count' => (int) $snapshot['held_file_count'],
            'usage_ratio' => $quota === 0
                ? null
                : $this->usageRatio($occupancy, $quota),
            'version' => $this->dbPositive($row['version'] ?? null, 'version'),
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    public function requestUnsignedDecimal(mixed $value, string $label): int
    {
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9]\d*)$/', $value) !== 1
            || !$this->fitsPhpInt($value)) {
            throw new ApiException("{$label}必须是不超过服务端整数上限的十进制字符串。", 422);
        }

        return (int) $value;
    }

    public function requestPositiveInteger(mixed $value, string $label): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new ApiException("{$label}必须是正整数。", 422);
        }

        return $value;
    }

    private function dbUnsigned(mixed $value, string $label): int
    {
        if (is_int($value)) {
            if ($value >= 0) {
                return $value;
            }
        } elseif (is_string($value)
            && preg_match('/^(?:0|[1-9]\d*)$/', $value) === 1
            && $this->fitsPhpInt($value)) {
            return (int) $value;
        }
        throw new ApiException("权威 {$label} 非法或超出服务端整数范围。", 503);
    }

    private function dbPositive(mixed $value, string $label): int
    {
        $integer = $this->dbUnsigned($value, $label);
        if ($integer <= 0) {
            throw new ApiException("权威 {$label} 必须为正整数。", 503);
        }

        return $integer;
    }

    private function fitsPhpInt(string $value): bool
    {
        $max = (string) PHP_INT_MAX;
        return strlen($value) < strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0);
    }

    private function safeAdd(int $left, int $right, string $label): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw new ApiException("权威 {$label} 超出服务端整数范围。", 503);
        }

        return $left + $right;
    }

    private function usageRatio(int $occupancy, int $quota): float
    {
        if ($occupancy < 0 || $quota <= 0 || $occupancy > $quota) {
            throw new ApiException('机构存储配额比例事实非法。', 503);
        }
        if ($occupancy === 0) {
            return 0.0;
        }
        if ($occupancy === $quota) {
            return 1.0;
        }

        $remainder = $occupancy;
        $scaled = 0;
        for ($place = 0; $place < 6; ++$place) {
            $accumulator = 0;
            $digit = 0;
            $wrapAt = $quota - $remainder;
            for ($multiple = 0; $multiple < 10; ++$multiple) {
                if ($accumulator >= $wrapAt) {
                    $accumulator -= $wrapAt;
                    ++$digit;
                } else {
                    $accumulator += $remainder;
                }
            }
            $scaled = ($scaled * 10) + $digit;
            $remainder = $accumulator;
        }
        $half = intdiv($quota, 2) + ($quota % 2);
        if ($remainder >= $half) {
            ++$scaled;
        }

        return $scaled / 1_000_000;
    }

    private function activeWindow(mixed $startAt, mixed $endAt): bool
    {
        $start = $startAt === null ? null : strtotime((string) $startAt);
        $end = $endAt === null ? null : strtotime((string) $endAt);
        return !($startAt !== null && $start === false)
            && !($endAt !== null && $end === false)
            && ($start === null || $end === null || $start <= $end)
            && ($start === null || $start <= time())
            && ($end === null || $end >= time());
    }

    private function assertCanonicalPath(
        int $organization,
        string $path,
        bool $mustBelongToOrganization,
    ): void
    {
        (new CanonicalPhysicalStoragePath())->assert(
            $path,
            $mustBelongToOrganization ? $organization : null,
        );
    }

    /**
     * @param array<string,mixed> $reservation
     * @param array<string,array<string,mixed>> $assetsByFileId
     */
    private function assertReservationFacts(
        int $organization,
        array $reservation,
        array $assetsByFileId,
    ): string {
        $state = (new WebImUploadReservationInvariant())->assert(
            $reservation,
            $organization,
        );
        if ((int) ($reservation['organization'] ?? 0) !== $organization
            || !in_array($state, self::RESERVATION_STATES, true)
            || preg_match('/^[a-f0-9]{64}$/', (string) ($reservation['upload_id'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{32}$/', (string) ($reservation['idempotency_key'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($reservation['intent_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{40}$/', (string) ($reservation['file_id'] ?? '')) !== 1
            || trim((string) ($reservation['user_id'] ?? '')) === ''
            || !in_array((string) ($reservation['client_family'] ?? ''), ['web', 'app'], true)
            || !in_array((string) ($reservation['kind'] ?? ''), ['image', 'file', 'voice', 'video'], true)
            || trim((string) ($reservation['filename'] ?? '')) === ''
            || trim((string) ($reservation['mime_type'] ?? '')) === ''
            || preg_match('/^[A-Za-z0-9]{1,32}$/', (string) ($reservation['extension'] ?? '')) !== 1) {
            throw new ApiException('上传预留权威关键事实非法。', 503);
        }
        $this->dbPositive($reservation['id'] ?? null, 'reservation.id');
        $this->dbPositive($reservation['size_bytes'] ?? null, 'reservation.size_bytes');
        $this->dbPositive($reservation['version'] ?? null, 'reservation.version');
        $this->assertCanonicalPath(
            $organization,
            (string) $reservation['storage_path'],
            true,
        );
        foreach (['expires_at', 'create_time', 'update_time'] as $field) {
            $this->assertDate($reservation[$field] ?? null, "reservation.{$field}");
        }

        $uploadLease = $this->assertLeasePair(
            $reservation['upload_lease_token'] ?? null,
            $reservation['upload_lease_expires_at'] ?? null,
            'upload',
        );
        $cleanupLease = $this->assertLeasePair(
            $reservation['cleanup_lease_token'] ?? null,
            $reservation['cleanup_lease_expires_at'] ?? null,
            'cleanup',
        );
        $confirmed = $this->optionalDate($reservation['confirmed_at'] ?? null, 'confirmed_at');
        $released = $this->optionalDate($reservation['released_at'] ?? null, 'released_at');
        $releaseReason = (string) ($reservation['release_reason'] ?? '');
        $terminalExpiry = str_starts_with(
            (string) $reservation['expires_at'],
            '9999-12-31 23:59:59',
        );

        $consistent = match ($state) {
            'reserved' => !$uploadLease && !$cleanupLease
                && !$confirmed && !$released && $releaseReason === '',
            'uploading' => $uploadLease && !$cleanupLease
                && !$confirmed && !$released && $releaseReason === '',
            'object_uploaded' => !$uploadLease && !$cleanupLease
                && !$confirmed && !$released && $releaseReason === '' && $terminalExpiry,
            'cleanup_pending' => !$uploadLease
                && !$confirmed && !$released && $releaseReason === '',
            'confirmed' => !$uploadLease && !$cleanupLease
                && $confirmed && !$released && $releaseReason === '' && $terminalExpiry,
            'released' => !$uploadLease && !$cleanupLease
                && !$confirmed && $released && $releaseReason !== '',
            default => false,
        };
        if (!$consistent) {
            throw new ApiException('上传预留状态与租约/终态事实不一致。', 503);
        }
        if ($state === 'confirmed') {
            $fileId = (string) $reservation['file_id'];
            $asset = $assetsByFileId[$fileId] ?? null;
            if (!is_array($asset) || !$this->confirmedAssetMatches($reservation, $asset)) {
                throw new ApiException('已确认上传预留缺少对应物理附件事实。', 503);
            }
        }

        return $state;
    }

    private function assertLeasePair(mixed $token, mixed $expiresAt, string $label): bool
    {
        if ($token === null && $expiresAt === null) {
            return false;
        }
        if (!is_string($token)
            || preg_match('/^[a-f0-9]{64}$/', $token) !== 1
            || $expiresAt === null) {
            throw new ApiException("上传预留 {$label} 租约事实非法。", 503);
        }
        $this->assertDate($expiresAt, "reservation.{$label}_lease_expires_at");

        return true;
    }

    private function optionalDate(mixed $value, string $label): bool
    {
        if ($value === null) {
            return false;
        }
        $this->assertDate($value, "reservation.{$label}");

        return true;
    }

    private function assertDate(mixed $value, string $label): void
    {
        if (!is_string($value) || $value === '' || strtotime($value) === false) {
            throw new ApiException("权威 {$label} 非法。", 503);
        }
    }

    /** @param array<string,mixed> $reservation @param array<string,mixed> $asset */
    private function confirmedAssetMatches(array $reservation, array $asset): bool
    {
        foreach ([
            'organization' => 'organization',
            'file_id' => 'file_id',
            'user_id' => 'user_id',
            'kind' => 'kind',
            'filename' => 'name',
            'storage_path' => 'storage_path',
            'size_bytes' => 'size_byte',
            'mime_type' => 'mime_type',
            'extension' => 'extension',
        ] as $reservationField => $assetField) {
            if ((string) ($reservation[$reservationField] ?? '')
                !== (string) ($asset[$assetField] ?? '')) {
                return false;
            }
        }

        return (string) ($asset['url'] ?? '') === '';
    }
}
