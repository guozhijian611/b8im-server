<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Manifest\Manifest;

interface ModuleLifecycleContextOptionsEnricherInterface
{
    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function enrich(
        Manifest $manifest,
        LifecycleOperation $operation,
        ?int $organization,
        ?string $fromVersion,
        bool $preserveData,
        array $options,
    ): array;
}
