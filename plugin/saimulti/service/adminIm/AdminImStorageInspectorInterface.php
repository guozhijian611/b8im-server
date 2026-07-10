<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

interface AdminImStorageInspectorInterface
{
    /**
     * The result must never contain a credential value.
     *
     * @return array{status: string, mode: string, label: string, configured: bool, missing: list<string>}
     */
    public function inspect(): array;
}
