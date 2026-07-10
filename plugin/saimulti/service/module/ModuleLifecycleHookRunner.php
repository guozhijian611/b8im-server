<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ModuleSdk\Lifecycle\LifecycleContext;
use B8im\ModuleSdk\Lifecycle\LifecycleOperation;
use B8im\ModuleSdk\Lifecycle\ModuleLifecycleInterface;
use B8im\ModuleSdk\Manifest\Manifest;
use Closure;
use ReflectionClass;
use RuntimeException;
use support\think\Db;

final class ModuleLifecycleHookRunner
{
    /** @var Closure(callable): mixed */
    private readonly Closure $transactionRunner;

    public function __construct(?callable $transactionRunner = null)
    {
        $this->transactionRunner = $transactionRunner === null
            ? static fn (callable $callback): mixed => Db::transaction($callback)
            : Closure::fromCallable($transactionRunner);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(
        Manifest $manifest,
        LifecycleOperation $operation,
        ?int $organization = null,
        ?string $fromVersion = null,
        bool $preserveData = true,
        array $options = [],
    ): array {
        $definition = $manifest->hooks()[$operation->value] ?? null;
        if (!is_array($definition)) {
            throw new RuntimeException(sprintf('模块 %s 缺少 %s hook。', $manifest->moduleKey(), $operation->value));
        }

        $scope = $definition['scope'];
        $tenantScoped = $organization !== null;
        if (($tenantScoped && !in_array($scope, ['tenant', 'both'], true))
            || (!$tenantScoped && !in_array($scope, ['system', 'both'], true))) {
            throw new RuntimeException(sprintf(
                '模块 %s 的 %s hook 不允许 %s 作用域。',
                $manifest->moduleKey(),
                $operation->value,
                $tenantScoped ? 'tenant' : 'system',
            ));
        }

        [$class, $method] = explode('::', $definition['handler'], 2);
        if ($method !== $operation->value) {
            throw new RuntimeException(sprintf('hook 方法必须与操作同名: %s', $definition['handler']));
        }
        if (!class_exists($class) || !is_subclass_of($class, ModuleLifecycleInterface::class)) {
            throw new RuntimeException(sprintf('hook 类不可用或未实现 ModuleLifecycleInterface: %s', $class));
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            throw new RuntimeException(sprintf('hook 类必须可以无参实例化: %s', $class));
        }

        /** @var ModuleLifecycleInterface $hook */
        $hook = $reflection->newInstance();
        $context = new LifecycleContext(
            operation: $operation,
            manifest: $manifest,
            organization: $organization,
            fromVersion: $fromVersion,
            preserveData: $preserveData,
            options: $options + ['transactional' => (bool) $definition['transactional']],
        );
        $invoke = static fn () => $hook->{$method}($context);
        $result = ($definition['transactional'] ?? false)
            ? ($this->transactionRunner)($invoke)
            : $invoke();
        if (!$result->isSuccessful()) {
            throw new RuntimeException($result->message() ?: sprintf('%s hook 执行失败。', $operation->value));
        }

        return $result->metadata() + ['message' => $result->message()];
    }

    public function isTransactional(Manifest $manifest, LifecycleOperation $operation): bool
    {
        return (bool) ($manifest->hooks()[$operation->value]['transactional'] ?? false);
    }
}
