<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchRebuild;

use B8im\Module\Search\Rebuild\Projection;
use plugin\saimulti\service\module\SearchDocumentProjectionServiceInterface;

final class ServerSearchRebuildProjection implements Projection
{
    public function __construct(private readonly SearchDocumentProjectionServiceInterface $search)
    {
    }

    public function projectMessageDocumentLocked(int $organization, string $messageId): void
    {
        $this->search->projectMessageDocumentLocked($organization, $messageId);
    }
}
