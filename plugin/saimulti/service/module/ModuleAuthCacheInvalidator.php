<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use Closure;
use InvalidArgumentException;
use plugin\saimulti\app\cache\AdminAuthCache;
use plugin\saimulti\app\cache\TenantAuthCache;

/**
 * 在模块状态事务提交后清理 SaiAdmin 权限投影缓存。
 *
 * TenantAuthCache 当前是全局缓存，尚未按 organization 分组。契约仍保留
 * organization 参数，便于后续缩小失效范围时无需修改生命周期调用方。
 */
final class ModuleAuthCacheInvalidator
{
    /** @var Closure(): mixed */
    private readonly Closure $clearAdmin;

    /** @var Closure(?int): mixed */
    private readonly Closure $clearTenant;

    public function __construct(?callable $clearAdmin = null, ?callable $clearTenant = null)
    {
        $this->clearAdmin = $clearAdmin === null
            ? static fn (): bool => AdminAuthCache::clear()
            : Closure::fromCallable($clearAdmin);
        $this->clearTenant = $clearTenant === null
            ? static fn (?int $_organization): bool => TenantAuthCache::clear()
            : Closure::fromCallable($clearTenant);
    }

    public function systemStateChanged(): void
    {
        try {
            ($this->clearAdmin)();
        } finally {
            // 系统状态会影响所有租户的可分配模块菜单。
            ($this->clearTenant)(null);
        }
    }

    public function tenantStateChanged(int $organization): void
    {
        if ($organization <= 0) {
            throw new InvalidArgumentException('organization 必须为正整数。');
        }

        ($this->clearTenant)($organization);
    }
}
