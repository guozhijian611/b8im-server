<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use B8im\Module\Search\Consumer\ConsumeOutcome;
use B8im\Module\Search\Consumer\MessageEvent;
use B8im\Module\Search\Consumer\MessageEventHandler;
use B8im\Module\Search\Consumer\PoisonMessageException;
use JsonException;
use RuntimeException;
use Throwable;

final class SearchConsumerRuntime
{
    public const RETRY_COUNT_HEADER = 'b8im-search-retry-count';

    private bool $started = false;

    private bool $inTick = false;

    private bool $transportHealthy = false;

    private ?string $lastHeartbeatValue = null;

    public function __construct(
        private readonly SearchConsumerConfig $config,
        private readonly SearchConsumerTransportInterface $transport,
        private readonly SearchConsumerHeartbeatStoreInterface $heartbeat,
        private readonly ClockInterface $clock,
        private readonly MessageEventHandler $handler,
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            throw new RuntimeException('Search consumer runtime is already started.');
        }
        $this->started = true;
        if ($this->ensureTransport()) {
            $this->refreshHeartbeat();
        }
    }

    public function tick(): void
    {
        if (!$this->started || $this->inTick) {
            return;
        }
        $this->inTick = true;
        try {
            if (!$this->ensureTransport()) {
                return;
            }
            $deadlineMs = $this->clock->monotonicMilliseconds() + $this->config->maxTickDurationMs;
            for ($processed = 0; $processed < $this->config->maxMessagesPerTick; $processed++) {
                if ($this->clock->monotonicMilliseconds() >= $deadlineMs) {
                    return;
                }
                if (!$this->refreshHeartbeat()) {
                    return;
                }
                $delivery = $this->transport->next();
                if ($delivery === null) {
                    return;
                }
                if (!$this->consume($delivery)) {
                    return;
                }
            }
        } catch (Throwable) {
            $this->disconnectTransport();
        } finally {
            $this->inTick = false;
        }
    }

    public function stop(): void
    {
        if (!$this->started) {
            return;
        }
        $this->started = false;
        try {
            if ($this->lastHeartbeatValue !== null) {
                $this->heartbeat->deleteIfEquals(
                    $this->config->heartbeatRedisKey,
                    $this->lastHeartbeatValue,
                );
            }
        } catch (Throwable) {
            // Bounded TTL is the cleanup path when Redis is unavailable.
        } finally {
            $this->transportHealthy = false;
            $this->transport->close();
        }
    }

    private function consume(SearchConsumerDelivery $delivery): bool
    {
        try {
            $retryCount = $this->retryCount($delivery->headers);
            $event = MessageEvent::fromJson($delivery->routingKey, $delivery->body);
            $outcome = $this->handler->handle($event);
        } catch (PoisonMessageException) {
            return $this->rejectWhenHeartbeatHealthy($delivery);
        } catch (Throwable) {
            return $this->retry($delivery, $retryCount ?? 0);
        }

        if ($outcome === ConsumeOutcome::ACK) {
            return $this->ackWhenHeartbeatHealthy($delivery);
        }
        return $this->retry($delivery, $retryCount);
    }

    private function retry(SearchConsumerDelivery $delivery, int $retryCount): bool
    {
        if ($retryCount >= $this->config->maxRetries) {
            return $this->rejectWhenHeartbeatHealthy($delivery);
        }
        $headers = $delivery->headers;
        $headers[self::RETRY_COUNT_HEADER] = $retryCount + 1;
        try {
            $this->transport->publishRetry(
                $delivery->body,
                $delivery->routingKey,
                $headers,
                $retryCount + 1,
            );
            return $this->ackWhenHeartbeatHealthy($delivery);
        } catch (Throwable) {
            $this->transport->nackRequeue($delivery);
            return false;
        }
    }

    private function ackWhenHeartbeatHealthy(SearchConsumerDelivery $delivery): bool
    {
        if (!$this->refreshHeartbeat()) {
            $this->transport->nackRequeue($delivery);
            return false;
        }
        $this->transport->ack($delivery);

        return true;
    }

    private function rejectWhenHeartbeatHealthy(SearchConsumerDelivery $delivery): bool
    {
        if (!$this->refreshHeartbeat()) {
            $this->transport->nackRequeue($delivery);
            return false;
        }
        $this->transport->reject($delivery);

        return true;
    }

    /** @param array<string, mixed> $headers */
    private function retryCount(array $headers): int
    {
        if (!array_key_exists(self::RETRY_COUNT_HEADER, $headers)) {
            return 0;
        }
        $value = $headers[self::RETRY_COUNT_HEADER];
        if (!is_int($value) || $value < 1 || $value > $this->config->maxRetries) {
            throw new PoisonMessageException('Invalid search retry count header.');
        }

        return $value;
    }

    private function refreshHeartbeat(): bool
    {
        $now = $this->clock->now();
        try {
            $value = json_encode([
                'deployment' => $this->config->deploymentId,
                'instance' => $this->config->instanceId,
                'queue' => $this->config->topology->mainQueue,
                'topology' => SearchConsumerTopology::VERSION,
                'status' => 'ready',
                'updated_at' => $now,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Search consumer heartbeat encoding failed.', previous: $exception);
        }
        try {
            $renewed = $this->heartbeat->claimOrRenew(
                $this->config->heartbeatRedisKey,
                $this->lastHeartbeatValue,
                $value,
                $this->config->heartbeatTtlSeconds,
            );
        } catch (Throwable) {
            $this->lastHeartbeatValue = null;
            return false;
        }
        if (!$renewed) {
            $this->lastHeartbeatValue = null;
            return false;
        }
        $this->lastHeartbeatValue = $value;

        return true;
    }

    private function ensureTransport(): bool
    {
        if ($this->transportHealthy) {
            return true;
        }
        try {
            $this->transport->open($this->config->topology, $this->config->prefetch);
        } catch (Throwable) {
            return false;
        }
        $this->transportHealthy = true;

        return true;
    }

    private function disconnectTransport(): void
    {
        $this->transportHealthy = false;
        try {
            $this->transport->close();
        } catch (Throwable) {
        }
    }
}
