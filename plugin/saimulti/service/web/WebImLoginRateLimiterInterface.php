<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImLoginRateLimiterInterface
{
    public function assertAllowed(int $organization, string $account, string $clientIp): void;

    public function resetAccountAttempts(int $organization, string $account): void;
}
