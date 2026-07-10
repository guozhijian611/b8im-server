<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

interface DistributedLockInterface
{
    public function acquire(string $key, string $token, int $ttlSeconds): bool;

    public function release(string $key, string $token): void;
}
