<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImPolicyStoreInterface
{
    /** @return array<string, mixed>|null */
    public function findPolicy(int $organization): ?array;
}
