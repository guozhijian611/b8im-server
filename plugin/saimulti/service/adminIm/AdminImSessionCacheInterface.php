<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

interface AdminImSessionCacheInterface
{
    /** @return array{status: string, max_stale_seconds: int} */
    public function status(): array;

    public function invalidate(int $organization, string $sessionId): bool;

    public function maxStaleSeconds(): int;
}
