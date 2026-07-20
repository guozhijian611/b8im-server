<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use B8im\Module\Search\Consumer\ProjectionWriter;
use plugin\saimulti\service\module\SearchDocumentProjectionServiceInterface;

final class ServerSearchProjectionWriter implements ProjectionWriter
{
    public function __construct(private readonly SearchDocumentProjectionServiceInterface $search)
    {
    }

    public function write(int $organization, string $messageId): void
    {
        $this->search->upsertMessageDocument($organization, $messageId);
    }
}
