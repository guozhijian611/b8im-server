<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\tenantPolicy;

interface TenantImPolicyPublisherInterface
{
    /** @param array<string, mixed> $actor */
    public function invalidateAndPublish(int $organization, int $version, array $actor): void;
}
