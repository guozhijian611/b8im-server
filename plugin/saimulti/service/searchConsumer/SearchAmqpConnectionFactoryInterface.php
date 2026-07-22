<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

interface SearchAmqpConnectionFactoryInterface
{
    public function connect(SearchConsumerConfig $config): SearchAmqpConnectionInterface;
}
