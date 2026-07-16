<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use Throwable;

final class WebImPolicyGuard
{
    public function __construct(private readonly ?WebImPolicyStoreInterface $store = null)
    {
    }

    public function assertAllowed(int $organization, string $clientFamily): void
    {
        if ($organization <= 0) {
            throw new ApiException('客户端 IM 租户策略不可用。', 403);
        }
        try {
            $row = ($this->store ?? new ThinkOrmWebImPolicyStore())->findPolicy($organization);
            self::assertRowAllows($row, $organization, $clientFamily);
            return;
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ApiException('客户端 IM 租户策略不可用。', 403);
        }
    }

    /** @param array<string, mixed>|null $row */
    public static function assertRowAllows(
        ?array $row,
        int $organization,
        string $clientFamily,
    ): void
    {
        $clientFamily = trim($clientFamily);
        if (!in_array($clientFamily, ['web', 'app', 'desktop'], true)) {
            throw new ApiException('client_family 无效。', 422);
        }
        try {
            if (!is_array($row) || (int) ($row['organization'] ?? 0) !== $organization) {
                throw new \UnexpectedValueException('tenant IM policy is missing');
            }
            $familiesJson = trim((string) ($row['allowed_client_families_json'] ?? ''));
            if (!str_starts_with($familiesJson, '[') || !str_ends_with($familiesJson, ']')) {
                throw new \UnexpectedValueException('allowed client families must be a JSON list');
            }
            $families = json_decode($familiesJson, true, flags: JSON_THROW_ON_ERROR);
            if (
                !is_array($families)
                || !array_is_list($families)
                || $families === []
                || array_filter($families, static fn (mixed $family): bool => !is_string($family)) !== []
            ) {
                throw new \UnexpectedValueException('allowed client families are invalid');
            }
            $families = array_values(array_unique(array_map(
                static fn (mixed $family): string => is_string($family) ? trim($family) : '',
                $families,
            )));
            if ($families === [] || array_diff($families, ['web', 'app', 'desktop']) !== []) {
                throw new \UnexpectedValueException('allowed client families contain an invalid value');
            }
        } catch (Throwable) {
            throw new ApiException('客户端 IM 租户策略不可用。', 403);
        }
        if (($row['status'] ?? null) !== 'ENABLED' || !in_array($clientFamily, $families, true)) {
            throw new ApiException('当前租户未启用目标客户端 IM。', 403);
        }
    }

    public static function familyForPath(string $path): ?string
    {
        $path = '/' . ltrim(trim($path), '/');
        foreach (['web', 'app', 'desktop'] as $clientFamily) {
            $prefix = '/saimulti/' . $clientFamily . '/im';
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $clientFamily;
            }
        }

        return null;
    }
}
