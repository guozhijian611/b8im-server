<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\trace;

use Closure;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Throwable;

/**
 * Converts exporter failures into safe boolean results.
 *
 * The SDK's default internal logger includes exception messages and stack
 * traces. This boundary deliberately emits only a stable code and exception
 * class, rate-limited per worker, and never lets telemetry break business.
 */
final class ResilientSpanExporter implements SpanExporterInterface
{
    /** @var array<string, float> */
    private array $lastWarningAt = [];

    private Closure $warningSink;

    private Closure $clock;

    public function __construct(
        private readonly SpanExporterInterface $delegate,
        private readonly float $warningIntervalSeconds = 60.0,
        ?Closure $warningSink = null,
        ?Closure $clock = null,
    ) {
        $this->warningSink = $warningSink ?? static fn (string $warning): bool => error_log($warning);
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        try {
            return $this->delegate->export($batch, $cancellation)
                ->map(function (bool $success): bool {
                    if (!$success) {
                        $this->warn('export_failed');
                    }

                    return $success;
                })
                ->catch(function (Throwable $exception): bool {
                    $this->warn('export_failed', $exception);

                    return false;
                });
        } catch (Throwable $exception) {
            $this->warn('export_failed', $exception);

            return new CompletedFuture(false);
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->guard('shutdown_failed', fn (): bool => $this->delegate->shutdown($cancellation));
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->guard('force_flush_failed', fn (): bool => $this->delegate->forceFlush($cancellation));
    }

    private function guard(string $code, Closure $callback): bool
    {
        try {
            $success = $callback();
            if (!$success) {
                $this->warn($code);
            }

            return $success;
        } catch (Throwable $exception) {
            $this->warn($code, $exception);

            return false;
        }
    }

    private function warn(string $code, ?Throwable $exception = null): void
    {
        $exceptionType = $exception !== null ? $exception::class : 'none';
        if (preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/', $exceptionType) !== 1) {
            $exceptionType = Throwable::class;
        }
        $key = $code . '|' . $exceptionType;
        try {
            $now = ($this->clock)();
        } catch (Throwable) {
            return;
        }
        if (isset($this->lastWarningAt[$key])
            && $now - $this->lastWarningAt[$key] < max(0.0, $this->warningIntervalSeconds)) {
            return;
        }
        $this->lastWarningAt[$key] = $now;
        try {
            ($this->warningSink)(sprintf(
                '[b8im:telemetry] code=%s exception=%s',
                $code,
                $exceptionType,
            ));
        } catch (Throwable) {
            // Even a broken logging sink must not reach the business path.
        }
    }
}
