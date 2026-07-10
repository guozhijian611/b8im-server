<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
namespace plugin\saimulti\service;

use InvalidArgumentException;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class ModuleRequired
{
    public function __construct(
        private readonly string $moduleKey,
        private readonly string $platform = 'server',
        private readonly ?string $capability = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $moduleKey)) {
            throw new InvalidArgumentException('ModuleRequired module_key must be snake_case.');
        }
    }

    public function moduleKey(): string
    {
        return $this->moduleKey;
    }

    public function platform(): string
    {
        return $this->platform;
    }

    public function capability(): ?string
    {
        return $this->capability;
    }
}
