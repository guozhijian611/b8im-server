<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

interface AdminImRealtimePublisherInterface
{
    /** @param array<string, mixed> $payload */
    public function publish(string $type, array $payload): bool;
}
