<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

final class SystemClock implements ClockInterface
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
