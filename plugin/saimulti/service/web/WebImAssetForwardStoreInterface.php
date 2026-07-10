<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImAssetForwardStoreInterface
{
    /**
     * Owner access does not require a message context. Non-owner access must
     * provide a source message that is visible to the authenticated user.
     *
     * @return array{file_id: string, user_id: string, kind: string, name: string, url: string, storage_path: string, size_byte: int, mime_type: string, extension: string}
     */
    public function accessibleAsset(
        int $organization,
        string $userId,
        string $fileId,
        string $conversationId,
        string $messageId,
    ): array;

    /**
     * @return array{file_id: string, kind: string, name: string, size: int, mime_type: string, extension: string}
     */
    public function deriveVisibleAsset(
        int $organization,
        string $userId,
        string $conversationId,
        string $messageId,
        string $sourceFileId,
        string $kind,
        string $derivedFileId,
        string $now,
    ): array;
}
