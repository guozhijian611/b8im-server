<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use Closure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

final class PhpAmqpLibSearchAmqpChannel implements SearchAmqpChannelInterface
{
    public function __construct(private readonly AMQPChannel $channel)
    {
    }

    public function declareExchange(string $name, string $type, bool $durable): void
    {
        $this->channel->exchange_declare($name, $type, false, $durable, false);
    }

    public function declareQueue(string $name, bool $durable, array $arguments): void
    {
        $this->channel->queue_declare(
            $name,
            false,
            $durable,
            false,
            false,
            false,
            new AMQPTable($arguments),
        );
    }

    public function bindQueue(string $queue, string $exchange, string $routingKey): void
    {
        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }

    public function qos(int $prefetch): void
    {
        $this->channel->basic_qos(null, $prefetch, null);
    }

    public function enablePublisherConfirms(): void
    {
        $this->channel->confirm_select();
    }

    public function onPublisherAck(Closure $handler): void
    {
        $this->channel->set_ack_handler($handler);
    }

    public function onPublisherNack(Closure $handler): void
    {
        $this->channel->set_nack_handler($handler);
    }

    public function onReturned(Closure $handler): void
    {
        $this->channel->set_return_listener($handler);
    }

    public function get(string $queue): ?SearchConsumerDelivery
    {
        $message = $this->channel->basic_get($queue, false);
        if (!$message instanceof AMQPMessage) {
            return null;
        }
        $properties = $message->get_properties();
        $applicationHeaders = $properties['application_headers'] ?? null;
        $headers = $applicationHeaders instanceof AMQPTable
            ? $applicationHeaders->getNativeData()
            : [];
        if (!is_array($headers)) {
            $headers = [];
        }

        return new SearchConsumerDelivery(
            $message->getDeliveryTag(),
            $message->getBody(),
            (string) $message->get('routing_key'),
            $headers,
        );
    }

    public function publish(
        string $body,
        array $headers,
        string $exchange,
        string $routingKey,
        bool $mandatory,
        bool $persistent,
    ): void {
        $message = new AMQPMessage($body, [
            'content_type' => 'application/json',
            'delivery_mode' => $persistent
                ? AMQPMessage::DELIVERY_MODE_PERSISTENT
                : AMQPMessage::DELIVERY_MODE_NON_PERSISTENT,
            'application_headers' => new AMQPTable($headers),
        ]);
        $this->channel->basic_publish($message, $exchange, $routingKey, $mandatory);
    }

    public function waitForPublisherConfirms(float $timeoutSeconds): void
    {
        $this->channel->wait_for_pending_acks_returns($timeoutSeconds);
    }

    public function ack(mixed $deliveryToken): void
    {
        $this->channel->basic_ack($this->deliveryTag($deliveryToken));
    }

    public function reject(mixed $deliveryToken, bool $requeue): void
    {
        $this->channel->basic_reject($this->deliveryTag($deliveryToken), $requeue);
    }

    public function nack(mixed $deliveryToken, bool $requeue): void
    {
        $this->channel->basic_nack($this->deliveryTag($deliveryToken), false, $requeue);
    }

    public function isOpen(): bool
    {
        return $this->channel->is_open();
    }

    public function close(): void
    {
        $this->channel->close();
    }

    private function deliveryTag(mixed $deliveryToken): int
    {
        if (!is_int($deliveryToken) || $deliveryToken < 1) {
            throw new RuntimeException('Invalid search AMQP delivery tag.');
        }

        return $deliveryToken;
    }
}
