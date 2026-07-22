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
use Throwable;

final class ModuleLifecycleHookRunner
{
    /** @var Closure(callable): mixed */
    private readonly Closure $transactionRunner;

    public function __construct(
        ?callable $transactionRunner = null,
        private readonly ?ModuleLifecycleContextOptionsEnricherInterface $optionsEnricher = null,
    ) {
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
        return $this->invoke(
            $manifest,
            $operation,
            $organization,
            $fromVersion,
            $preserveData,
            $options,
            true,
            true,
        );
    }

    public function expiryHookKind(Manifest $manifest, LifecycleOperation $operation): string
    {
        if ($this->isTransactional($manifest, $operation)) {
            return ModuleExpiryHookContract::KIND_TRANSACTIONAL;
        }
        if ($this->optionsEnricher instanceof ModuleExpiryHookContextOptionsEnricherInterface
            && $this->optionsEnricher->supportsExpiry($manifest, $operation)) {
            return ModuleExpiryHookContract::KIND_TRANSACTIONAL;
        }

        return ModuleExpiryHookContract::KIND_EXTERNAL;
    }

    /**
     * The scanner owns the surrounding license/task/effect/receipt transaction.
     *
     * @param array<string,mixed> $task
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function invokeExpiryInCurrentTransaction(
        Manifest $manifest,
        LifecycleOperation $operation,
        int $organization,
        array $task,
        array $options,
    ): array {
        $this->assertTransactionalOptions($options);
        try {
            if (!$this->isTransactional($manifest, $operation)) {
                if (!$this->optionsEnricher instanceof ModuleExpiryHookContextOptionsEnricherInterface
                    || !$this->optionsEnricher->supportsExpiry($manifest, $operation)) {
                    throw new ModuleExpiryHookContractUnavailable(sprintf(
                        '模块 %s 的非事务 %s hook 缺少 durable authoritative receipt 契约。',
                        $manifest->moduleKey(),
                        $operation->value,
                    ));
                }
                $options = $this->optionsEnricher->enrichExpiry(
                    $manifest,
                    $operation,
                    $organization,
                    $task,
                    $options,
                );
            }
            $this->assertExpiryHookExecutable($manifest, $operation);
        } catch (ModuleExpiryHookContractUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ModuleExpiryHookContractUnavailable(
                'Frozen expiry hook cannot be prepared: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        return $this->invoke(
            $manifest,
            $operation,
            $organization,
            null,
            true,
            $options,
            false,
            false,
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function invoke(
        Manifest $manifest,
        LifecycleOperation $operation,
        ?int $organization,
        ?string $fromVersion,
        bool $preserveData,
        array $options,
        bool $manageTransaction,
        bool $enrichOptions,
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

        $transactional = (bool) ($definition['transactional'] ?? false);
        if ($transactional && $manageTransaction) {
            $this->assertTransactionalOptions($options);
        } elseif (!$transactional && $enrichOptions && $this->optionsEnricher !== null) {
            $options = $this->optionsEnricher->enrich(
                $manifest,
                $operation,
                $organization,
                $fromVersion,
                $preserveData,
                $options,
            );
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
            options: $options + ['transactional' => $transactional],
        );
        $invoke = static fn () => $hook->{$method}($context);
        $result = $transactional && $manageTransaction
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

    /** @param array<string,mixed> $options */
    private function assertTransactionalOptions(array $options): void
    {
        $walk = function (mixed $value) use (&$walk): void {
            if (is_object($value) || is_resource($value)) {
                throw new RuntimeException('Transactional lifecycle options must be data-only.');
            }
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $walk($nested);
                }
            }
        };
        $walk($options);
    }

    private function assertExpiryHookExecutable(
        Manifest $manifest,
        LifecycleOperation $operation,
    ): void {
        $definition = $manifest->hooks()[$operation->value] ?? null;
        $handler = is_array($definition) ? (string) ($definition['handler'] ?? '') : '';
        $parts = explode('::', $handler, 2);
        if (count($parts) !== 2 || $parts[1] !== $operation->value
            || !class_exists($parts[0])
            || !is_subclass_of($parts[0], ModuleLifecycleInterface::class)) {
            throw new ModuleExpiryHookContractUnavailable(
                'Frozen expiry hook handler is unavailable: ' . $handler,
            );
        }
        $reflection = new ReflectionClass($parts[0]);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable()
            || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            throw new ModuleExpiryHookContractUnavailable(
                'Frozen expiry hook handler is not constructible: ' . $handler,
            );
        }
    }
}
