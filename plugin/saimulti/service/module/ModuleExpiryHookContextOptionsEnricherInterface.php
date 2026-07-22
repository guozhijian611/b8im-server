<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Manifest\Manifest;

interface ModuleExpiryHookContextOptionsEnricherInterface
{
    public function supportsExpiry(Manifest $manifest, LifecycleOperation $operation): bool;

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function enrichExpiry(
        Manifest $manifest,
        LifecycleOperation $operation,
        int $organization,
        array $task,
        array $options,
    ): array;
}
