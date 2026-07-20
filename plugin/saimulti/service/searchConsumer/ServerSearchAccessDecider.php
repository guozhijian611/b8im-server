<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use B8im\Module\Search\Consumer\AccessDecider;
use B8im\Module\Search\Consumer\AccessDecision;
use plugin\saimulti\service\module\ModuleAccessDecision;
use plugin\saimulti\service\module\ModuleAccessService;

final class ServerSearchAccessDecider implements AccessDecider
{
    public function __construct(private readonly ModuleAccessService $access)
    {
    }

    public function decide(int $organization): AccessDecision
    {
        return match ($this->access->decideAuthoritatively(
            $organization,
            'search',
            'server',
            'search.index.write',
        )) {
            ModuleAccessDecision::AVAILABLE => AccessDecision::AVAILABLE,
            ModuleAccessDecision::DENIED => AccessDecision::DENIED,
            ModuleAccessDecision::UNAVAILABLE => AccessDecision::UNAVAILABLE,
        };
    }
}
