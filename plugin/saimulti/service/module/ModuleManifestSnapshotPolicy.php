<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\State\SystemModuleStatus;

final class ModuleManifestSnapshotPolicy
{
    public static function replaceSnapshotOnDiscover(string $status): bool
    {
        return !in_array($status, [
            SystemModuleStatus::INSTALLED->value,
            SystemModuleStatus::ENABLED->value,
            SystemModuleStatus::DISABLED->value,
            SystemModuleStatus::UPGRADING->value,
        ], true);
    }

    /**
     * @param array<string, mixed> $candidateSnapshot
     * @return array<string, mixed>
     */
    public static function discoveryUpdate(string $status, array $candidateSnapshot): array
    {
        if (self::replaceSnapshotOnDiscover($status)) {
            return $candidateSnapshot;
        }

        return array_intersect_key($candidateSnapshot, array_flip(['available_version', 'manifest_path']));
    }

    private function __construct()
    {
    }
}
