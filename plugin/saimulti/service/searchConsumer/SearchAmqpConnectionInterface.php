<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

interface SearchAmqpConnectionInterface
{
    public function channel(): SearchAmqpChannelInterface;

    public function isConnected(): bool;

    public function close(): void;
}
