<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

interface ModuleAccessCacheInterface
{
    /** @return array<string, mixed>|null */
    public function get(string $key): ?array;

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value): void;

    public function delete(string $key): void;
}
