<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

final class ModuleServiceFactory
{
    private static ?ManifestCatalog $catalog = null;

    private static ?ModuleAccessService $access = null;

    public static function catalog(): ManifestCatalog
    {
        return self::$catalog ??= new ManifestCatalog();
    }

    public static function access(): ModuleAccessService
    {
        return self::$access ??= new ModuleAccessService(
            new ThinkOrmModuleAccessStore(),
            new ThinkCacheModuleAccessCache(),
        );
    }

    public static function manager(): ModuleManager
    {
        return new ModuleManager(
            self::catalog(),
            new ModuleMigrationRunner(),
            new ModuleLifecycleHookRunner(),
            new ModuleMenuRegistrar(),
            new ModuleDependencyGuard(),
            self::access(),
            new ModuleAuditWriter(),
            new ModuleConfigValidator(),
            new RedisDistributedLock(),
        );
    }

    public static function tenantAssignments(): TenantModuleAssignmentService
    {
        return new TenantModuleAssignmentService(self::manager());
    }

    public static function expiryScanner(): ModuleLicenseExpiryScanner
    {
        return new ModuleLicenseExpiryScanner(
            new RedisDistributedLock(),
            self::access(),
            new ModuleAuditWriter(),
            new ModuleLifecycleHookRunner(),
        );
    }

    public static function clientConfigProjection(): ClientConfigProjectionService
    {
        return new ClientConfigProjectionService(self::access());
    }

    private function __construct()
    {
    }
}
