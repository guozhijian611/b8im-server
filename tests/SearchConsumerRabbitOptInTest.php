<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use plugin\saimulti\service\searchConsumer\PhpAmqpLibSearchConsumerTransport;
use plugin\saimulti\service\searchConsumer\SearchConsumerConfig;
use plugin\saimulti\service\searchConsumer\SearchConsumerRuntime;
use plugin\saimulti\service\searchConsumer\SearchConsumerTopology;

if (getenv('SEARCH_CONSUMER_RABBIT_INTEGRATION') !== '1') {
    echo "SearchConsumerRabbitOptInTest: skipped (set SEARCH_CONSUMER_RABBIT_INTEGRATION=1)\n";
    exit(0);
}

$host = (string) (getenv('SEARCH_CONSUMER_RABBIT_HOST') ?: '127.0.0.1');
$port = (int) (getenv('SEARCH_CONSUMER_RABBIT_PORT') ?: 5672);
$user = (string) (getenv('SEARCH_CONSUMER_RABBIT_USER') ?: 'guest');
$password = (string) (getenv('SEARCH_CONSUMER_RABBIT_PASSWORD') ?: 'guest');
$vhost = (string) (getenv('SEARCH_CONSUMER_RABBIT_VHOST') ?: '/');
$suffix = sprintf('%d.%s', getmypid(), bin2hex(random_bytes(5)));
$topology = new SearchConsumerTopology(
    SearchConsumerTopology::SOURCE_EXCHANGE,
    'b8im.search.integration.' . $suffix . '.work',
    'b8im.search.integration.' . $suffix . '.main',
    'b8im.search.integration.' . $suffix . '.retry.exchange',
    'b8im.search.integration.' . $suffix . '.retry.queue',
    'b8im.search.integration.' . $suffix . '.dead.exchange',
    'b8im.search.integration.' . $suffix . '.dead.queue',
    [400, 100, 200, 300],
);
$config = SearchConsumerConfig::fromArray([
    'enabled' => true,
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'password' => $password,
    'vhost' => $vhost,
    'connection_timeout_seconds' => 2.0,
    'read_write_timeout_seconds' => 2.0,
    'confirm_timeout_seconds' => 2.0,
    'prefetch' => 1,
    'poll_interval_seconds' => 0.25,
    'max_messages_per_tick' => 3,
    'max_tick_duration_ms' => 200,
    'deployment_id' => 'rabbit-integration',
    'instance_id' => 'rabbit-integration-' . $suffix,
    'heartbeat_key' => 'b8im:search:integration:' . $suffix,
    'heartbeat_ttl_seconds' => 15,
]);
$transport = new PhpAmqpLibSearchConsumerTransport($config);
$recoveryTransport = null;
$connection = null;
$channel = null;
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
};
$poll = static function (callable $read): mixed {
    $deadline = microtime(true) + 3.0;
    do {
        $value = $read();
        if ($value !== null) {
            return $value;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    return null;
};

try {
    $transport->open($topology, 1);
    $connection = new AMQPStreamConnection(
        $host,
        $port,
        $user,
        $password,
        $vhost,
        false,
        'AMQPLAIN',
        null,
        'en_US',
        2.0,
        2.0,
        null,
        false,
        0,
        2.0,
    );
    $channel = $connection->channel();
    $body = json_encode(['integration' => $suffix], JSON_THROW_ON_ERROR);
    $channel->basic_publish(
        new AMQPMessage($body, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]),
        $topology->workExchange,
        'message.edited',
    );
    $original = $poll(static fn () => $transport->next());
    $assert($original !== null, 'isolated main queue did not receive work exchange message');
    $assert($original->body === $body && $original->routingKey === 'message.edited', 'isolated main delivery identity changed');

    $unroutableTier = $topology->retryTier(3);
    $channel->queue_unbind($unroutableTier['queue'], $unroutableTier['exchange'], '#');
    try {
        $transport->publishRetry($body, 'message.edited', [], 3);
        throw new RuntimeException('mandatory unroutable retry publish did not fail');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() !== 'mandatory unroutable retry publish did not fail', 'mandatory unroutable retry publish did not fail');
    } finally {
        $channel->queue_bind($unroutableTier['queue'], $unroutableTier['exchange'], '#');
    }

    $transport->publishRetry(
        $original->body,
        $original->routingKey,
        [SearchConsumerRuntime::RETRY_COUNT_HEADER => 1],
        2,
    );
    $transport->ack($original);
    $retried = $poll(static fn () => $transport->next());
    $assert($retried !== null, 'retry TTL did not DLX back to dedicated work exchange');
    $assert($retried->body === $body && $retried->routingKey === 'message.edited', 'retry changed original body/routing key');
    $assert(($retried->headers[SearchConsumerRuntime::RETRY_COUNT_HEADER] ?? null) === 1, 'retry header did not survive Rabbit DLX');

    $transport->reject($retried);
    $dead = $poll(static fn () => $channel->basic_get($topology->deadQueue, false));
    $assert($dead instanceof AMQPMessage, 'reject(no requeue) did not route to isolated DLQ');
    $assert($dead->getBody() === $body && (string) $dead->get('routing_key') === 'message.edited', 'DLQ changed original body/routing key');
    $channel->basic_ack($dead->getDeliveryTag());

    $longBody = json_encode(['delay' => 'long', 'suffix' => $suffix], JSON_THROW_ON_ERROR);
    $shortBody = json_encode(['delay' => 'short', 'suffix' => $suffix], JSON_THROW_ON_ERROR);
    $transport->publishRetry($longBody, 'message.created', [SearchConsumerRuntime::RETRY_COUNT_HEADER => 1], 1);
    $transport->publishRetry($shortBody, 'message.created', [SearchConsumerRuntime::RETRY_COUNT_HEADER => 2], 2);
    $shortDelivery = $poll(static fn () => $transport->next());
    $assert($shortDelivery !== null && $shortDelivery->body === $shortBody, 'long-first retry blocked later short tier (HOL)');
    $transport->ack($shortDelivery);
    $longDelivery = $poll(static fn () => $transport->next());
    $assert($longDelivery !== null && $longDelivery->body === $longBody, 'long retry tier did not return after independent TTL');
    $transport->ack($longDelivery);

    $requeueBody = json_encode(['requeue_after_close' => $suffix], JSON_THROW_ON_ERROR);
    $channel->basic_publish(
        new AMQPMessage($requeueBody, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]),
        $topology->workExchange,
        'message.created',
    );
    $unacked = $poll(static fn () => $transport->next());
    $assert($unacked !== null && $unacked->body === $requeueBody, 'close fixture did not receive unacked message');
    $transport->close();
    $recoveryTransport = new PhpAmqpLibSearchConsumerTransport($config);
    $recoveryTransport->open($topology, 1);
    $requeued = $poll(static fn () => $recoveryTransport->next());
    $assert($requeued !== null && $requeued->body === $requeueBody, 'channel close did not requeue unacked message');
    $recoveryTransport->ack($requeued);
} finally {
    $transport->close();
    if ($recoveryTransport !== null) {
        $recoveryTransport->close();
    }
    if ($channel !== null) {
        foreach (array_column($topology->queues(), 'queue') as $queue) {
            try {
                $channel->queue_delete($queue);
            } catch (Throwable) {
            }
        }
        foreach (array_column($topology->exchanges(), 'name') as $exchange) {
            if ($exchange === SearchConsumerTopology::SOURCE_EXCHANGE) {
                continue;
            }
            try {
                $channel->exchange_delete($exchange);
            } catch (Throwable) {
            }
        }
        try {
            $channel->close();
        } catch (Throwable) {
        }
    }
    if ($connection !== null) {
        try {
            $connection->close();
        } catch (Throwable) {
        }
    }
}

echo sprintf("SearchConsumerRabbitOptInTest: %d assertions passed\n", $passed);
