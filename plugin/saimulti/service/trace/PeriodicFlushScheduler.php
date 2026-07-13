<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\trace;

use Closure;
use Throwable;
use Workerman\Timer;

/** Registers one periodic flush timer per long-lived Workerman worker. */
final class PeriodicFlushScheduler
{
    private ?int $timerId = null;

    private Closure $addTimer;

    private Closure $deleteTimer;

    public function __construct(?Closure $addTimer = null, ?Closure $deleteTimer = null)
    {
        $this->addTimer = $addTimer
            ?? static fn (float $interval, callable $callback): int => Timer::add($interval, $callback);
        $this->deleteTimer = $deleteTimer
            ?? static fn (int $timerId): bool => Timer::del($timerId);
    }

    public function start(float $intervalSeconds, Closure $flush): bool
    {
        if ($this->timerId !== null) {
            return true;
        }

        try {
            $timerId = ($this->addTimer)(
                max(0.1, $intervalSeconds),
                static function () use ($flush): void {
                    try {
                        $flush();
                    } catch (Throwable) {
                        // Exporter/SDK failures must never escape the event loop.
                    }
                },
            );
            if (!is_int($timerId) || $timerId <= 0) {
                return false;
            }
            $this->timerId = $timerId;

            return true;
        } catch (Throwable) {
            // Timer::add throws in CLI processes without a Workerman loop.
            return false;
        }
    }

    public function stop(): void
    {
        $timerId = $this->timerId;
        $this->timerId = null;
        if ($timerId === null) {
            return;
        }
        try {
            ($this->deleteTimer)($timerId);
        } catch (Throwable) {
            // Worker shutdown must continue even if its event loop is gone.
        }
    }

    public function isStarted(): bool
    {
        return $this->timerId !== null;
    }
}
