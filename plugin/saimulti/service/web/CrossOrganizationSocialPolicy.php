<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use support\think\Db;

/**
 * Platform-level switch for cross-organization friends + single chat.
 * Default off. Backed by sm_system_config social_config.cross_organization_social_enabled.
 */
final class CrossOrganizationSocialPolicy
{
    public const CONFIG_GROUP = 'social_config';
    public const CONFIG_KEY = 'cross_org_social_enabled';

    private static ?array $cache = null;

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    public static function isEnabled(): bool
    {
        $cached = self::$cache;
        if (is_array($cached) && (int) ($cached['expire_at'] ?? 0) > time()) {
            return (bool) ($cached['enabled'] ?? false);
        }

        $enabled = false;
        $group = Db::query(
            'SELECT id FROM sm_system_config_group
              WHERE code = ? AND delete_time IS NULL
              LIMIT 1',
            [self::CONFIG_GROUP],
        )[0] ?? null;
        if ($group !== null) {
            $row = Db::query(
                'SELECT `value` FROM sm_system_config
                  WHERE group_id = ? AND `key` = ? AND delete_time IS NULL
                  LIMIT 1',
                [(int) $group['id'], self::CONFIG_KEY],
            )[0] ?? null;
            $enabled = self::truthy($row['value'] ?? '0');
        }

        self::$cache = [
            'expire_at' => time() + 15,
            'enabled' => $enabled,
        ];

        return $enabled;
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
