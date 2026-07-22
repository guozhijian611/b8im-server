<?php

declare(strict_types=1);

namespace plugin\saimulti\process;

use Closure;
use B8im\Module\Search\Rebuild\Config;
use B8im\Module\Search\Rebuild\Runtime;
use plugin\saimulti\service\searchRebuild\SearchRebuildFactory;
use RuntimeException;
use Workerman\Timer;

final class SearchRebuildProcess
{
    private readonly Closure $configLoader;

    private readonly Closure $runtimeFactory;

    private readonly Closure $timerAdder;

    private readonly Closure $timerDeleter;

    private ?int $timerId = null;

    private ?Runtime $runtime = null;

    public function __construct(
        ?Closure $configLoader = null,
        ?Closure $runtimeFactory = null,
        ?Closure $timerAdder = null,
        ?Closure $timerDeleter = null,
    ) {
        $this->configLoader = $configLoader
            ?? static fn (): mixed => config('plugin.saimulti.search_rebuild');
        $this->runtimeFactory = $runtimeFactory
            ?? static fn (Config $config): Runtime => SearchRebuildFactory::runtime($config);
        $this->timerAdder = $timerAdder
            ?? static fn (float $interval, callable $callback): int => Timer::add($interval, $callback);
        $this->timerDeleter = $timerDeleter
            ?? static fn (int $timerId): bool => Timer::del($timerId);
    }

    public function onWorkerStart(): void
    {
        $values = ($this->configLoader)();
        if (!is_array($values)) {
            throw new RuntimeException('Search rebuild configuration is missing.');
        }
        if (($values['enabled'] ?? null) === false) {
            return;
        }
        $config = Config::fromArray($values);
        if (!$config->enabled) {
            return;
        }
        $runtime = ($this->runtimeFactory)($config);
        if (!$runtime instanceof Runtime) {
            throw new RuntimeException('Search rebuild runtime factory returned an invalid value.');
        }
        $runtime->start();
        try {
            $timerId = ($this->timerAdder)(
                $config->pollIntervalSeconds,
                static fn () => $runtime->tick(),
            );
        } catch (\Throwable $exception) {
            $runtime->stop();
            throw $exception;
        }
        if (!is_int($timerId) || $timerId < 1) {
            $runtime->stop();
            throw new RuntimeException('Search rebuild timer registration failed.');
        }
        $this->timerId = $timerId;
        $this->runtime = $runtime;
    }

    public function onWorkerStop(): void
    {
        if ($this->timerId !== null) {
            ($this->timerDeleter)($this->timerId);
            $this->timerId = null;
        }
        if ($this->runtime !== null) {
            $this->runtime->stop();
            $this->runtime = null;
        }
    }
}
