<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use Closure;

interface SearchAmqpChannelInterface
{
    public function declareExchange(string $name, string $type, bool $durable): void;

    /** @param array<string, int|string> $arguments */
    public function declareQueue(string $name, bool $durable, array $arguments): void;

    public function bindQueue(string $queue, string $exchange, string $routingKey): void;

    public function qos(int $prefetch): void;

    public function enablePublisherConfirms(): void;

    public function onPublisherAck(Closure $handler): void;

    public function onPublisherNack(Closure $handler): void;

    public function onReturned(Closure $handler): void;

    public function get(string $queue): ?SearchConsumerDelivery;

    /** @param array<string, mixed> $headers */
    public function publish(
        string $body,
        array $headers,
        string $messageId,
        string $exchange,
        string $routingKey,
        bool $mandatory,
        bool $persistent,
    ): void;

    public function waitForPublisherConfirms(float $timeoutSeconds): void;

    public function ack(mixed $deliveryToken): void;

    public function reject(mixed $deliveryToken, bool $requeue): void;

    public function nack(mixed $deliveryToken, bool $requeue): void;

    public function isOpen(): bool;

    public function close(): void;
}
