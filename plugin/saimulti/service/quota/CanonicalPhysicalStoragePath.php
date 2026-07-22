<?php

declare(strict_types=1);

namespace plugin\saimulti\service\quota;

use plugin\saimulti\exception\ApiException;

final class CanonicalPhysicalStoragePath
{
    public function assert(string $path, ?int $expectedOrganization = null): string
    {
        if ($expectedOrganization !== null && $expectedOrganization <= 0) {
            throw new ApiException('物理附件路径机构无效。', 503);
        }
        $organization = $expectedOrganization === null
            ? '[1-9]\d*'
            : preg_quote((string) $expectedOrganization, '#');
        if ($path === ''
            || strlen($path) > 512
            || trim($path, '/') !== $path
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || preg_match(
                '#^(?:[A-Za-z0-9._-]+/)*organizations/'
                    . $organization
                    . '/im/[0-9]{6}/[a-f0-9]{32,64}\.[A-Za-z0-9]{1,32}$#',
                $path,
            ) !== 1) {
            throw new ApiException('物理附件路径不是 canonical 路径。', 503);
        }

        return $path;
    }
}
