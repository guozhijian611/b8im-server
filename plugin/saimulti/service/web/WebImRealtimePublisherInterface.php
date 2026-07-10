<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImRealtimePublisherInterface
{
    /** @param array<string, mixed> $payload */
    public function publishFriendRequestCreated(int $organization, array $payload): void;

}
