<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImUploadAssetStoreInterface
{
    /** @return array<string, mixed>|null */
    public function findActiveImage(int $organization, string $fileId, ?string $ownerUserId = null): ?array;
}
