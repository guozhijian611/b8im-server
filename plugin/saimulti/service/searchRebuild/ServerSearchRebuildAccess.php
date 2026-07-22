<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchRebuild;

use B8im\Module\Search\Consumer\AccessDecision;
use B8im\Module\Search\Rebuild\AccessDecider;
use plugin\saimulti\service\module\ModuleAccessDecision;
use plugin\saimulti\service\module\ModuleAccessService;

final class ServerSearchRebuildAccess implements AccessDecider
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
