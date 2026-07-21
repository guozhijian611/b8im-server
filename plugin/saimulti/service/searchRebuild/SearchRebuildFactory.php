<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchRebuild;

use B8im\Module\Search\Rebuild\Config;
use B8im\Module\Search\Rebuild\Runtime;
use B8im\Module\Search\Rebuild\WorkerHeartbeat;
use B8im\Module\Search\Rebuild\WorkerReadiness;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\module\SearchService;
use plugin\saimulti\service\searchConsumer\RedisSearchConsumerHeartbeatStore;
use plugin\saimulti\service\searchConsumer\SearchConsumerConfig;
use plugin\saimulti\service\searchConsumer\SearchConsumerReadinessReader;
use plugin\saimulti\service\searchConsumer\SystemClock;
use RuntimeException;

final class SearchRebuildFactory
{
    public static function service(): SearchRebuildService
    {
        $config = self::rebuildConfig();
        $heartbeatStore = new RedisSearchConsumerHeartbeatStore();
        $clock = new SystemClock();

        return new SearchRebuildService(
            self::consumerReadiness($heartbeatStore, $clock),
            new WorkerReadiness($heartbeatStore, $clock, $config),
            new ServerSearchRebuildAccess(ModuleServiceFactory::access()),
            new ThinkOrmSearchRebuildStore(),
        );
    }

    public static function runtime(Config $config): Runtime
    {
        $heartbeatStore = new RedisSearchConsumerHeartbeatStore();
        $clock = new SystemClock();

        return new Runtime(
            $config,
            new WorkerHeartbeat($heartbeatStore, $clock, $config),
            new ServerSearchRebuildAccess(ModuleServiceFactory::access()),
            new ThinkOrmSearchRebuildStore(),
            new ServerSearchRebuildProjection(new SearchService()),
        );
    }

    private static function consumerReadiness(
        RedisSearchConsumerHeartbeatStore $store,
        SystemClock $clock,
    ): SearchRebuildConsumerReadiness {
        $values = config('plugin.saimulti.search');
        if (!is_array($values)) {
            throw new RuntimeException('Search consumer configuration is missing.');
        }

        return new SearchRebuildConsumerReadiness(SearchConsumerReadinessReader::fromConfig(
            $store,
            $clock,
            SearchConsumerConfig::fromArray($values),
        ));
    }

    private static function rebuildConfig(): Config
    {
        $values = config('plugin.saimulti.search_rebuild');
        if (!is_array($values)) {
            throw new RuntimeException('Search rebuild configuration is missing.');
        }

        return Config::fromArray($values);
    }

    private function __construct()
    {
    }
}
