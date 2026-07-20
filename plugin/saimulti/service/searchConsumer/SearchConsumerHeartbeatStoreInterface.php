<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

interface SearchConsumerHeartbeatStoreInterface
{
    public function claimOrRenew(
        string $key,
        ?string $expectedValue,
        string $newValue,
        int $ttlSeconds,
    ): bool;

    public function read(string $key): ?string;

    public function deleteIfEquals(string $key, string $expectedValue): bool;
}
