<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

interface SearchDocumentProjectionServiceInterface
{
    /** @return array<string, mixed> */
    public function upsertMessageDocument(int $homeOrganization, string $messageId): array;
}
