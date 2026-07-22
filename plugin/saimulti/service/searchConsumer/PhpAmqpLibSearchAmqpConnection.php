<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class PhpAmqpLibSearchAmqpConnection implements SearchAmqpConnectionInterface
{
    public function __construct(private readonly AMQPStreamConnection $connection)
    {
    }

    public function channel(): SearchAmqpChannelInterface
    {
        return new PhpAmqpLibSearchAmqpChannel($this->connection->channel());
    }

    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
