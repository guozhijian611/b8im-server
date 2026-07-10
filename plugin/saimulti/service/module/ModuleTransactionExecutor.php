<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use Closure;
use support\think\Db;
use Throwable;

/**
 * Executes database work first and only runs cache/integration callbacks after
 * the transaction runner has returned, i.e. after the database commit.
 */
final class ModuleTransactionExecutor
{
    /** @var Closure(callable): mixed */
    private readonly Closure $transactionRunner;

    /** @var Closure(Throwable): void */
    private readonly Closure $afterCommitFailureReporter;

    public function __construct(?callable $transactionRunner = null, ?callable $afterCommitFailureReporter = null)
    {
        $this->transactionRunner = $transactionRunner === null
            ? static fn (callable $callback): mixed => Db::transaction($callback)
            : Closure::fromCallable($transactionRunner);
        $this->afterCommitFailureReporter = $afterCommitFailureReporter === null
            ? static fn (Throwable $exception): bool => error_log(
                'module after-commit action failed: ' . $exception->getMessage(),
            )
            : Closure::fromCallable($afterCommitFailureReporter);
    }

    /**
     * @param list<callable(): void> $afterCommit
     */
    public function run(callable $transaction, array $afterCommit = []): mixed
    {
        $result = ($this->transactionRunner)($transaction);

        foreach ($afterCommit as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                // The database is already committed. A bounded cache TTL is the
                // final fail-safe, so reporting must not turn success into a
                // misleading rollback-style API failure.
                ($this->afterCommitFailureReporter)($exception);
            }
        }

        return $result;
    }
}
