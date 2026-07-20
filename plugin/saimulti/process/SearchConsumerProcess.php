<?php

declare(strict_types=1);

namespace plugin\saimulti\process;

use B8im\Module\Search\Consumer\MessageEventHandler;
use Closure;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\module\SearchService;
use plugin\saimulti\service\searchConsumer\PhpAmqpLibSearchConsumerTransport;
use plugin\saimulti\service\searchConsumer\RedisSearchConsumerHeartbeatStore;
use plugin\saimulti\service\searchConsumer\SearchConsumerConfig;
use plugin\saimulti\service\searchConsumer\SearchConsumerRuntime;
use plugin\saimulti\service\searchConsumer\ServerSearchAccessDecider;
use plugin\saimulti\service\searchConsumer\ServerSearchProjectionWriter;
use plugin\saimulti\service\searchConsumer\SystemClock;
use RuntimeException;
use Workerman\Timer;

final class SearchConsumerProcess
{
    private readonly Closure $configLoader;

    private readonly Closure $runtimeFactory;

    private readonly Closure $timerAdder;

    private readonly Closure $timerDeleter;

    private ?SearchConsumerRuntime $runtime = null;

    private ?int $timerId = null;

    public function __construct(
        ?Closure $configLoader = null,
        ?Closure $runtimeFactory = null,
        ?Closure $timerAdder = null,
        ?Closure $timerDeleter = null,
    ) {
        $this->configLoader = $configLoader
            ?? static fn (): mixed => config('plugin.saimulti.search');
        $this->runtimeFactory = $runtimeFactory
            ?? static function (SearchConsumerConfig $config): SearchConsumerRuntime {
                return new SearchConsumerRuntime(
                    $config,
                    new PhpAmqpLibSearchConsumerTransport($config),
                    new RedisSearchConsumerHeartbeatStore(),
                    new SystemClock(),
                    new MessageEventHandler(
                        new ServerSearchAccessDecider(ModuleServiceFactory::access()),
                        new ServerSearchProjectionWriter(new SearchService()),
                    ),
                );
            };
        $this->timerAdder = $timerAdder
            ?? static fn (float $interval, callable $callback): int => Timer::add($interval, $callback);
        $this->timerDeleter = $timerDeleter
            ?? static fn (int $timerId): bool => Timer::del($timerId);
    }

    public function onWorkerStart(): void
    {
        $values = ($this->configLoader)();
        if (!is_array($values)) {
            throw new RuntimeException('Search consumer configuration is missing.');
        }
        if (($values['enabled'] ?? null) === false) {
            return;
        }
        $config = SearchConsumerConfig::fromArray($values);
        if (!$config->enabled) {
            return;
        }
        $runtime = ($this->runtimeFactory)($config);
        if (!$runtime instanceof SearchConsumerRuntime) {
            throw new RuntimeException('Search consumer runtime factory returned an invalid value.');
        }
        $this->runtime = $runtime;
        $runtime->start();
        $timerId = ($this->timerAdder)(
            $config->pollIntervalSeconds,
            static fn () => $runtime->tick(),
        );
        if (!is_int($timerId) || $timerId < 1) {
            $runtime->stop();
            $this->runtime = null;
            throw new RuntimeException('Search consumer timer registration failed.');
        }
        $this->timerId = $timerId;
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
