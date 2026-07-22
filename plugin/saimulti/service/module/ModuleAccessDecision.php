<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

enum ModuleAccessDecision: string
{
    case AVAILABLE = 'available';
    case DENIED = 'denied';
    case UNAVAILABLE = 'unavailable';
}
