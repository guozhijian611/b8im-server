<?php

declare(strict_types=1);

namespace plugin\saimulti\app\controller\publicapi;

use plugin\saimulti\basic\OpenController;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\CustomerServiceService;
use plugin\saimulti\service\module\ModuleServiceFactory;
use support\Request;
use support\Response;

/**
 * Public entry resolve — license checked against organization from entry record.
 */
final class CustomerServicePublicController extends OpenController
{
    public function resolveEntry(Request $request): Response
    {
        $code = trim((string) $request->get('code', $request->get('public_entry_code', '')));
        if ($code === '') {
            throw new ApiException('入口编码必填。', 422);
        }
        $payload = (new CustomerServiceService())->resolvePublicEntry($code);
        $organization = (int) $payload['entry']['organization'];
        $access = ModuleServiceFactory::access();
        $ok = $access->isAvailable($organization, 'customer_service', 'server', 'customer_service.public.entry')
            || $access->isAvailable($organization, 'customer_service', 'web', 'customer_service.web.use');
        if (!$ok) {
            throw new ApiException('客服模块未启用。', 403);
        }

        return $this->success($payload);
    }
}
