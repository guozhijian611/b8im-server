<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchRebuild;

use B8im\Module\Search\Consumer\AccessDecision;
use B8im\Module\Search\Rebuild\AccessDecider;
use B8im\Module\Search\Rebuild\Readiness;
use B8im\Module\Search\Rebuild\Store;
use plugin\saimulti\exception\ApiException;

final class SearchRebuildService
{
    public function __construct(
        private readonly Readiness $consumerReadiness,
        private readonly Readiness $workerReadiness,
        private readonly AccessDecider $access,
        private readonly Store $store,
    ) {
    }

    /** @return array{job:array<string,mixed>,index:array<string,mixed>} */
    public function enqueue(int $organization, int $actorId): array
    {
        if ($organization < 1 || $actorId < 1) {
            throw new ApiException('搜索重建请求身份无效。', 422);
        }
        $decision = $this->access->decide($organization);
        if ($decision === AccessDecision::DENIED) {
            throw new ApiException('搜索模块未授权。', 403);
        }
        if ($decision === AccessDecision::UNAVAILABLE) {
            throw new ApiException('搜索模块授权暂时不可用。', 503);
        }
        if (!$this->consumerReadiness->isReady()) {
            throw new ApiException('搜索消息消费者未就绪。', 503);
        }
        if (!$this->workerReadiness->isReady()) {
            throw new ApiException('搜索重建工作进程未就绪。', 503);
        }

        return $this->store->enqueue($organization, $actorId);
    }
}
