<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

final class SearchConsumerDelivery
{
    /** @param array<string, mixed> $headers */
    public function __construct(
        public readonly mixed $token,
        public readonly string $body,
        public readonly string $routingKey,
        public readonly array $headers,
    ) {
    }
}
