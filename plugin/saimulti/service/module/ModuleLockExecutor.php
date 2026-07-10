<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

final class ModuleLockExecutor
{
    /** @var array<string, true> */
    private array $held = [];

    public function __construct(
        private readonly DistributedLockInterface $lock,
        private readonly int $ttlSeconds,
    ) {
        if ($ttlSeconds < 600) {
            throw new \InvalidArgumentException('模块生命周期锁 TTL 不能小于 600 秒。');
        }
    }

    public function run(string $moduleKey, callable $callback): mixed
    {
        $key = self::key($moduleKey);
        if (isset($this->held[$key])) {
            return $callback();
        }

        $token = bin2hex(random_bytes(20));
        if (!$this->lock->acquire($key, $token, $this->ttlSeconds)) {
            throw new ModuleLockUnavailable(sprintf('模块 %s 正在执行其他生命周期操作。', $moduleKey));
        }

        $this->held[$key] = true;
        try {
            return $callback();
        } finally {
            unset($this->held[$key]);
            $this->lock->release($key, $token);
        }
    }

    public function isHeld(string $moduleKey): bool
    {
        return isset($this->held[self::key($moduleKey)]);
    }

    public static function key(string $moduleKey): string
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $moduleKey)) {
            throw new \InvalidArgumentException('module_key must be snake_case.');
        }

        return 'module_lifecycle:' . $moduleKey;
    }
}
