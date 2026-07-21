<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use B8im\Module\Search\Rebuild\Clock;

final class SystemClock implements ClockInterface, Clock
{
    public function now(): int
    {
        return time();
    }

    public function monotonicMilliseconds(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }
}
