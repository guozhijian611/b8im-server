<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use SplFileInfo;

interface WebImUploadStorageInterface
{
    public function assertReady(): void;

    /** @return array{storage_path: string, size_byte: int} */
    public function upload(
        int $organization,
        SplFileInfo $file,
        string $extension,
        string $mimeType,
    ): array;

    public function delete(int $organization, string $storagePath): void;
}
