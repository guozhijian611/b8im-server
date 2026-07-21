<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use SplFileInfo;

interface WebImUploadStorageInterface
{
    public function assertReady(): void;

    public function reservePath(int $organization, string $extension, string $objectId): string;

    public function uploadExact(
        int $organization,
        SplFileInfo $file,
        string $storagePath,
        string $mimeType,
        ?callable $heartbeat = null,
    ): void;

    /** @return array{storage_path:string,size_byte:int,mime_type:string} */
    public function inspect(int $organization, string $storagePath): array;

    public function delete(int $organization, string $storagePath): void;
}
