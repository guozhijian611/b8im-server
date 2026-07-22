<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

interface SearchConsumerGateInterface
{
    /**
     * Fail closed while the system module is not enabled or its lifecycle
     * lock is held. Per-organization authorization is checked after delivery.
     */
    public function canFetch(): bool;
}
