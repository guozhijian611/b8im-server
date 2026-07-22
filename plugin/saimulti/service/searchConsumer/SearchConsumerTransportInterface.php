<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

interface SearchConsumerTransportInterface
{
    public function open(SearchConsumerTopology $topology, int $prefetch): void;

    public function next(): ?SearchConsumerDelivery;

    public function ack(SearchConsumerDelivery $delivery): void;

    public function reject(SearchConsumerDelivery $delivery): void;

    public function nackRequeue(SearchConsumerDelivery $delivery): void;

    /** @param array<string, mixed> $headers */
    public function publishRetry(
        string $body,
        string $routingKey,
        array $headers,
        string $messageId,
        int $retryTier,
    ): void;

    public function close(): void;
}
