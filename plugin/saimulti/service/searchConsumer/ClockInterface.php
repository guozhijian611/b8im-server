<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

interface ClockInterface
{
    public function now(): int;

    public function monotonicMilliseconds(): int;
}
