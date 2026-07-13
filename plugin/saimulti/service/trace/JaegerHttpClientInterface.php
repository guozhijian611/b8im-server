<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\trace;

interface JaegerHttpClientInterface
{
    /** @param array<string, scalar> $query */
    public function get(string $path, array $query = []): array;
}
