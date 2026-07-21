<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\quota\CanonicalPhysicalStoragePath;

final class WebImUploadReservationInvariant
{
    private const STATES = [
        'reserved',
        'uploading',
        'object_uploaded',
        'cleanup_pending',
        'confirmed',
        'released',
    ];

    /** @param array<string,mixed> $row */
    public function assert(array $row, ?int $expectedOrganization = null): string
    {
        $organization = $this->positive($row['organization'] ?? null, 'organization');
        $size = $this->positive($row['size_bytes'] ?? null, 'size_bytes');
        $this->positive($row['id'] ?? null, 'id');
        $this->positive($row['version'] ?? null, 'version');
        $state = (string) ($row['state'] ?? '');
        if (($expectedOrganization !== null && $organization !== $expectedOrganization)
            || !in_array($state, self::STATES, true)
            || preg_match('/^[a-f0-9]{64}$/', (string) ($row['upload_id'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{32}$/', (string) ($row['idempotency_key'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($row['intent_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{40}$/', (string) ($row['file_id'] ?? '')) !== 1
            || trim((string) ($row['user_id'] ?? '')) === ''
            || !in_array((string) ($row['client_family'] ?? ''), ['web', 'app'], true)
            || !in_array((string) ($row['kind'] ?? ''), ['image', 'file', 'voice', 'video'], true)
            || trim((string) ($row['filename'] ?? '')) === ''
            || trim((string) ($row['mime_type'] ?? '')) === ''
            || preg_match('/^[A-Za-z0-9]{1,32}$/', (string) ($row['extension'] ?? '')) !== 1) {
            throw new ApiException('上传预留本地权威事实非法。', 503);
        }
        $this->assertCanonicalPath(
            $organization,
            (string) ($row['storage_path'] ?? ''),
        );
        foreach (['expires_at', 'create_time', 'update_time'] as $field) {
            $this->date($row[$field] ?? null, $field);
        }

        $expectedIntentHash = hash('sha256', implode("\0", [
            (string) $organization,
            (string) $row['user_id'],
            (string) $row['client_family'],
            (string) $row['kind'],
            (string) $row['filename'],
            (string) $size,
            (string) $row['mime_type'],
            (string) $row['extension'],
        ]));
        if (!hash_equals((string) $row['intent_hash'], $expectedIntentHash)) {
            throw new ApiException('上传预留意图事实不一致。', 503);
        }

        $uploadLease = $this->lease(
            $row['upload_lease_token'] ?? null,
            $row['upload_lease_expires_at'] ?? null,
            'upload',
        );
        $cleanupLease = $this->lease(
            $row['cleanup_lease_token'] ?? null,
            $row['cleanup_lease_expires_at'] ?? null,
            'cleanup',
        );
        $confirmed = $this->optionalDate($row['confirmed_at'] ?? null, 'confirmed_at');
        $released = $this->optionalDate($row['released_at'] ?? null, 'released_at');
        $reason = (string) ($row['release_reason'] ?? '');
        $terminalExpiry = str_starts_with(
            (string) $row['expires_at'],
            '9999-12-31 23:59:59',
        );
        $consistent = match ($state) {
            'reserved' => !$uploadLease && !$cleanupLease
                && !$confirmed && !$released && $reason === '',
            'uploading' => $uploadLease && !$cleanupLease
                && !$confirmed && !$released && $reason === '',
            'object_uploaded' => !$uploadLease && !$cleanupLease
                && !$confirmed && !$released && $reason === '' && $terminalExpiry,
            'cleanup_pending' => !$uploadLease
                && !$confirmed && !$released && $reason === '',
            'confirmed' => !$uploadLease && !$cleanupLease
                && $confirmed && !$released && $reason === '' && $terminalExpiry,
            'released' => !$uploadLease && !$cleanupLease
                && !$confirmed && $released && $reason !== '',
            default => false,
        };
        if (!$consistent) {
            throw new ApiException('上传预留本地状态事实不一致。', 503);
        }

        return $state;
    }

    private function positive(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($maximum)
                || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0)) {
                return (int) $value;
            }
        }
        throw new ApiException("上传预留 {$field} 非法。", 503);
    }

    private function assertCanonicalPath(int $organization, string $path): void
    {
        (new CanonicalPhysicalStoragePath())->assert($path, $organization);
    }

    private function lease(mixed $token, mixed $expiresAt, string $label): bool
    {
        if ($token === null && $expiresAt === null) {
            return false;
        }
        if (!is_string($token)
            || preg_match('/^[a-f0-9]{64}$/', $token) !== 1
            || $expiresAt === null) {
            throw new ApiException("上传预留 {$label} 租约非法。", 503);
        }
        $this->date($expiresAt, "{$label}_lease_expires_at");

        return true;
    }

    private function optionalDate(mixed $value, string $field): bool
    {
        if ($value === null) {
            return false;
        }
        $this->date($value, $field);

        return true;
    }

    private function date(mixed $value, string $field): void
    {
        if (!is_string($value) || $value === '' || strtotime($value) === false) {
            throw new ApiException("上传预留 {$field} 非法。", 503);
        }
    }
}
