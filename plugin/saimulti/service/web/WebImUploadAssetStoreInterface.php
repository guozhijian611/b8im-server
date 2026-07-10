<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImUploadAssetStoreInterface
{
    /** @param array<string, mixed> $asset */
    public function create(array $asset): void;

    /** @return array<string, mixed>|null */
    public function findActiveImage(int $organization, string $fileId, ?string $ownerUserId = null): ?array;
}
