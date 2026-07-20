<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use RuntimeException;
use Throwable;

final class PhpAmqpLibSearchConsumerTransport implements SearchConsumerTransportInterface
{
    private ?SearchAmqpConnectionInterface $connection = null;

    private ?SearchAmqpChannelInterface $channel = null;

    private ?SearchConsumerTopology $topology = null;

    private bool $returned = false;

    private readonly SearchAmqpConnectionFactoryInterface $connectionFactory;

    public function __construct(
        private readonly SearchConsumerConfig $config,
        ?SearchAmqpConnectionFactoryInterface $connectionFactory = null,
    ) {
        $this->connectionFactory = $connectionFactory ?? new PhpAmqpLibSearchAmqpConnectionFactory();
    }

    public function open(SearchConsumerTopology $topology, int $prefetch): void
    {
        if ($this->channel !== null) {
            throw new RuntimeException('Search AMQP transport is already open.');
        }
        try {
            $this->connection = $this->connectionFactory->connect($this->config);
            $this->channel = $this->connection->channel();
            $this->declareTopology($this->channel, $topology);
            $this->channel->qos($prefetch);
            $this->channel->enablePublisherConfirms();
            $this->channel->onPublisherAck(static function (): void {
            });
            $this->channel->onPublisherNack(static function (): void {
                throw new RuntimeException('RabbitMQ rejected the search retry publication.');
            });
            $this->channel->onReturned(function (): void {
                $this->returned = true;
            });
            $this->topology = $topology;
        } catch (Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    public function next(): ?SearchConsumerDelivery
    {
        $channel = $this->requiredChannel();
        $topology = $this->requiredTopology();
        return $channel->get($topology->mainQueue);
    }

    public function ack(SearchConsumerDelivery $delivery): void
    {
        $this->requiredChannel()->ack($delivery->token);
    }

    public function reject(SearchConsumerDelivery $delivery): void
    {
        $this->requiredChannel()->reject($delivery->token, false);
    }

    public function nackRequeue(SearchConsumerDelivery $delivery): void
    {
        $this->requiredChannel()->nack($delivery->token, true);
    }

    public function publishRetry(
        string $body,
        string $routingKey,
        array $headers,
        int $retryTier,
    ): void {
        $channel = $this->requiredChannel();
        $topology = $this->requiredTopology();
        $tier = $topology->retryTier($retryTier);
        $this->returned = false;
        $channel->publish($body, $headers, $tier['exchange'], $routingKey, true, true);
        $channel->waitForPublisherConfirms($this->config->confirmTimeoutSeconds);
        if ($this->returned) {
            throw new RuntimeException('RabbitMQ returned the unroutable search retry publication.');
        }
    }

    public function close(): void
    {
        $channel = $this->channel;
        $connection = $this->connection;
        $this->channel = null;
        $this->connection = null;
        $this->topology = null;
        try {
            if ($channel !== null && $channel->isOpen()) {
                $channel->close();
            }
        } catch (Throwable) {
        }
        try {
            if ($connection !== null && $connection->isConnected()) {
                $connection->close();
            }
        } catch (Throwable) {
        }
    }

    private function declareTopology(SearchAmqpChannelInterface $channel, SearchConsumerTopology $topology): void
    {
        foreach ($topology->exchanges() as $exchange) {
            $channel->declareExchange($exchange['name'], $exchange['type'], $exchange['durable']);
        }
        foreach ($topology->queues() as $queue) {
            $channel->declareQueue($queue['queue'], $queue['durable'], $queue['arguments']);
        }
        foreach ($topology->bindings() as $binding) {
            $channel->bindQueue($binding['queue'], $binding['exchange'], $binding['routing_key']);
        }
    }

    private function requiredChannel(): SearchAmqpChannelInterface
    {
        if ($this->channel === null) {
            throw new RuntimeException('Search AMQP transport is not open.');
        }

        return $this->channel;
    }

    private function requiredTopology(): SearchConsumerTopology
    {
        if ($this->topology === null) {
            throw new RuntimeException('Search AMQP topology is not open.');
        }

        return $this->topology;
    }
}
