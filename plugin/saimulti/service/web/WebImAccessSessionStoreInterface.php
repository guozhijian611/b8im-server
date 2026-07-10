<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImAccessSessionStoreInterface
{
    /** @return array<string, mixed>|null */
    public function findByJti(int $organization, string $jti): ?array;
}
