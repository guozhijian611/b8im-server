<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

/**
 * Platform-level switch for cross-organization friends + single chat.
 * Default off. Backed by sm_system_config social_config.cross_org_social_enabled.
 */
final class CrossOrganizationSocialPolicy
{
    public const CONFIG_GROUP = 'social_config';
    public const CONFIG_KEY = 'cross_org_social_enabled';
    public const SNAPSHOT_CONFIG_KEY = 'cross_org_access_snapshot_id';

    public static function clearCache(): void
    {
        // Reads are uncached so every webman worker observes transitions.
    }

    public static function isEnabled(): bool
    {
        $policy = self::load();

        return (bool) ($policy['enabled'] ?? false);
    }

    public static function accessSnapshotId(): string
    {
        $policy = self::load();

        return (string) ($policy['access_snapshot_id'] ?? '0');
    }

    /** @return array{enabled: bool, access_snapshot_id: string} */
    public static function lockSharedInsideTransaction(): array
    {
        return self::read(' LOCK IN SHARE MODE', false);
    }

    /**
     * Serialize attachment derivations before they take any organization quota
     * or asset lock. Config writers already lock this group row first, so the
     * global fence also preserves their established group -> config lock order.
     *
     * @return array{enabled: bool, access_snapshot_id: string}
     */
    public static function lockAssetDeriveExclusiveInsideTransaction(): array
    {
        $groups = Db::query(
            'SELECT id
               FROM sm_system_config_group
              WHERE code = ? AND delete_time IS NULL
              FOR UPDATE',
            [self::CONFIG_GROUP],
        );
        if (count($groups) !== 1 || (int) ($groups[0]['id'] ?? 0) <= 0) {
            throw new ApiException('跨租户社交锁事实不可用。', 503);
        }

        return self::read(' LOCK IN SHARE MODE', false);
    }

    /** @return array{enabled: bool, access_snapshot_id: string} */
    private static function load(): array
    {
        return self::read('', true);
    }

    /** @return array{enabled: bool, access_snapshot_id: string} */
    private static function read(string $lock, bool $failClosed): array
    {
        $enabled = false;
        $snapshotId = '0';

        try {
            $group = Db::query(
                'SELECT id FROM sm_system_config_group
                  WHERE code = ? AND delete_time IS NULL
                  LIMIT 1' . $lock,
                [self::CONFIG_GROUP],
            )[0] ?? null;
            if ($group !== null) {
                $rows = Db::query(
                    'SELECT `key`, `value` FROM sm_system_config
                      WHERE group_id = ?
                        AND `key` IN (?, ?)
                        AND delete_time IS NULL
                   ORDER BY id ASC' . $lock,
                    [(int) $group['id'], self::CONFIG_KEY, self::SNAPSHOT_CONFIG_KEY],
                );
                $values = [];
                foreach ($rows as $row) {
                    $values[(string) $row['key']] = (string) ($row['value'] ?? '');
                }
                $rawSnapshotId = trim((string) ($values[self::SNAPSHOT_CONFIG_KEY] ?? ''));
                if (preg_match('/^[1-9][0-9]*$/', $rawSnapshotId) === 1 && strlen($rawSnapshotId) <= 20) {
                    $snapshotId = $rawSnapshotId;
                    $enabled = self::truthy($values[self::CONFIG_KEY] ?? '0');
                }
            }
        } catch (\Throwable $exception) {
            if (!$failClosed) {
                throw $exception;
            }
            // Missing schema/config is an invalid policy state and must fail closed.
            $enabled = false;
            $snapshotId = '0';
        }

        return [
            'enabled' => $enabled,
            'access_snapshot_id' => $snapshotId,
        ];
    }

    public static function truthy(mixed $value): bool
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * Display label helper: company suffix only for cross-org peers.
     */
    public static function contactDisplayName(
        string $nickname,
        string $account,
        int $viewerOrganization,
        int $peerOrganization,
        string $peerCompanyName,
    ): string {
        $base = trim($nickname) !== '' ? trim($nickname) : trim($account);
        if ($base === '') {
            $base = '用户';
        }
        if ($viewerOrganization <= 0 || $peerOrganization <= 0 || $viewerOrganization === $peerOrganization) {
            return $base;
        }
        $company = trim($peerCompanyName);
        if ($company === '') {
            return $base;
        }

        return $base . ' · ' . $company;
    }
}
