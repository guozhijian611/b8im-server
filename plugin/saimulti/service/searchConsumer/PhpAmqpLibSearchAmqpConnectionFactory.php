<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class PhpAmqpLibSearchAmqpConnectionFactory implements SearchAmqpConnectionFactoryInterface
{
    public function connect(SearchConsumerConfig $config): SearchAmqpConnectionInterface
    {
        return new PhpAmqpLibSearchAmqpConnection(new AMQPStreamConnection(
            $config->host,
            $config->port,
            $config->user,
            $config->password,
            $config->vhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $config->connectionTimeoutSeconds,
            $config->readWriteTimeoutSeconds,
            null,
            false,
            0,
            $config->readWriteTimeoutSeconds,
        ));
    }
}
