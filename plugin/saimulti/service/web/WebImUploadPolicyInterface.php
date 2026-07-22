<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImUploadPolicyInterface
{
    public function assertAllowed(int $organization, int $sizeBytes): void;
}
