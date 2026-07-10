<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImAvatarServiceInterface
{
    public function assertOwnedImage(int $organization, string $ownerUserId, string $fileId): string;

    /** @return array{avatar_file_id: string, avatar_url: string, avatar_expires_at: int} */
    public function project(int $organization, string $fileId): array;
}
