<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImAssetUrlSignerInterface
{
    public function sign(int $organization, string $storagePath, int $expiresAt): string;
}
