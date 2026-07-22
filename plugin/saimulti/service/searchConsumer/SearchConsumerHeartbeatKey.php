<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

final class SearchConsumerHeartbeatKey
{
    public static function forDeployment(string $baseKey, string $deploymentId): string
    {
        return sprintf(
            '%s:deployment:%s',
            $baseKey,
            substr(hash('sha256', $deploymentId), 0, 32),
        );
    }

    private function __construct()
    {
    }
}
