<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use B8im\ModuleSdk\State\SystemModuleStatus;
use plugin\saimulti\service\module\ModuleLockExecutor;
use support\think\Cache;
use support\think\Db;
use Throwable;

final class ThinkOrmSearchConsumerGate implements SearchConsumerGateInterface
{
    public function canFetch(): bool
    {
        try {
            if (Cache::has(ModuleLockExecutor::key('search'))) {
                return false;
            }
            $rows = Db::query(
                'SELECT status FROM sm_module'
                . ' WHERE module_key=? AND delete_time IS NULL LIMIT 2',
                ['search'],
            );

            return count($rows) === 1
                && hash_equals(SystemModuleStatus::ENABLED->value, (string) ($rows[0]['status'] ?? ''));
        } catch (Throwable) {
            return false;
        }
    }
}
