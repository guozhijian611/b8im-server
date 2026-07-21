<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

interface SearchDocumentProjectionServiceInterface
{
    /** @return array<string, mixed> */
    public function upsertMessageDocument(int $homeOrganization, string $messageId): array;

    /**
     * Caller owns the outer transaction. The implementation acquires locks in
     * search_index -> source index/shard/change -> search_doc order.
     *
     * @return array<string, mixed>
     */
    public function projectMessageDocumentLocked(int $homeOrganization, string $messageId): array;
}
