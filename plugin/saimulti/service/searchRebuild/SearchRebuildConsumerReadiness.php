<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchRebuild;

use B8im\Module\Search\Rebuild\Readiness;
use plugin\saimulti\service\searchConsumer\SearchConsumerReadinessReader;

final class SearchRebuildConsumerReadiness implements Readiness
{
    public function __construct(private readonly SearchConsumerReadinessReader $reader)
    {
    }

    public function isReady(): bool
    {
        return $this->reader->isReady();
    }
}
