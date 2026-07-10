<?php

declare(strict_types=1);

namespace plugin\saimulti\basic;

use plugin\saimulti\exception\ApiException;

abstract class WebController extends OpenController
{
    /** @var array<string, mixed> */
    protected array $webIdentity;

    protected int $organization;

    protected string $deploymentId;

    public function init(): void
    {
        if (in_array((string) request()->action, $this->publicActions(), true)) {
            return;
        }
        $identity = request()->header('check_saimulti_web');
        if (!is_array($identity) || empty($identity['organization']) || empty($identity['user_id'])) {
            throw new ApiException('Web 登录上下文缺失。', 401);
        }
        $this->webIdentity = $identity;
        $this->organization = (int) $identity['organization'];
        $this->deploymentId = (string) $identity['deployment_id'];
    }

    /** @return list<string> */
    protected function publicActions(): array
    {
        return [];
    }
}
