<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\State\SystemModuleStatus;

final class ModuleFailureRecoveryPolicy
{
    public static function target(
        string $operation,
        SystemModuleStatus $current,
        ?SystemModuleStatus $stableBeforeOperation,
    ): SystemModuleStatus {
        if ($operation === 'install' && $current === SystemModuleStatus::DISCOVERED) {
            return SystemModuleStatus::FAILED;
        }

        // Once an upgrade entered UPGRADING, migrations or the new package may
        // already be active. Restoring the previous ENABLED state without a
        // verified schema and code rollback would run an unknown mixed version.
        if ($operation === 'upgrade' && $current === SystemModuleStatus::UPGRADING) {
            return SystemModuleStatus::FAILED;
        }

        return $stableBeforeOperation ?? $current;
    }

    private function __construct()
    {
    }
}
