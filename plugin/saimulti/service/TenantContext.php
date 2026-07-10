<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | B8IM 租户上下文
// +----------------------------------------------------------------------
namespace plugin\saimulti\service;

use plugin\saimulti\exception\ApiException;

/**
 * 租户上下文解析器。
 *
 * 已登录请求只信任签名 token 中的 organization，App-Id 仅用于做一致性校验。
 * 未登录的租户入口（例如登录）才使用 App-Id 选择目标机构。
 */
final class TenantContext
{
    public const REQUIRED = 41001;
    public const MISMATCH = 41002;

    public static function authenticatedOrganization(): ?int
    {
        $tenant = getTenantInfo();
        if ($tenant === false || !array_key_exists('organization', $tenant)) {
            return null;
        }

        return self::parseOrganization($tenant['organization']);
    }

    public static function requestOrganization(bool $required = true): ?int
    {
        $request = request();
        if (!$request) {
            if ($required) {
                throw new ApiException('租户上下文缺失', self::REQUIRED);
            }
            return null;
        }

        $value = $request->header('App-Id');
        if ($value === null || $value === '') {
            if ($required) {
                throw new ApiException('App-Id 必须填写', self::REQUIRED);
            }
            return null;
        }

        return self::parseOrganization($value);
    }

    public static function organization(bool $required = true): ?int
    {
        $organization = self::authenticatedOrganization();
        if ($organization !== null) {
            return $organization;
        }

        // 平台管理端使用 BaseModel 做全局访问；如果显式操作租户模型，
        // 必须由业务查询/写入显式给出 organization，不信任请求头。
        if (self::isAdminRequest()) {
            return null;
        }

        return self::requestOrganization($required);
    }

    public static function assertRequestMatches(int $organization): void
    {
        $requestOrganization = self::requestOrganization();
        if ($requestOrganization !== $organization) {
            throw new ApiException('App-Id 与登录租户不一致', self::MISMATCH);
        }
    }

    public static function parseOrganization(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        throw new ApiException('无效的租户标识', self::REQUIRED);
    }

    public static function isAdminRequest(): bool
    {
        return getAdminInfo() !== false;
    }
}
