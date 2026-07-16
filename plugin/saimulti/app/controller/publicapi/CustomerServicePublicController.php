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
 * Public customer-service entry + external visitor guest APIs.
 * Guest identity comes from X-CS-Guest-Token / Authorization: Guest <token>.
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
        $this->assertModuleEnabled((int) $payload['entry']['organization']);

        return $this->success($payload);
    }

    public function sessionCreate(Request $request): Response
    {
        $input = $request->post();
        if (!is_array($input)) {
            $input = [];
        }
        if (empty($input['origin'])) {
            $input['origin'] = (string) ($request->header('origin') ?? '');
        }
        // resolve org for license from entry first
        $code = trim((string) ($input['public_entry_code'] ?? $input['code'] ?? ''));
        if ($code === '') {
            throw new ApiException('入口编码必填。', 422);
        }
        $resolved = (new CustomerServiceService())->resolvePublicEntry($code);
        $this->assertModuleEnabled((int) $resolved['entry']['organization']);

        $result = (new CustomerServiceService())->createGuestSession($input);

        return $this->success($result);
    }

    public function sessionClose(Request $request): Response
    {
        $token = $this->rawGuestToken($request);
        (new CustomerServiceService())->revokeGuestToken($token);

        return $this->success(['revoked' => true]);
    }

    public function sessionMe(Request $request): Response
    {
        $guest = $this->guest($request);

        return $this->success([
            'organization' => $guest['organization'],
            'visitor_id' => $guest['visitor_id'],
            'entry_id' => $guest['entry_id'],
        ]);
    }

    public function conversationIndex(Request $request): Response
    {
        $guest = $this->guest($request);

        return $this->success((new CustomerServiceService())->guestConversationList($guest, $request->get()));
    }

    public function conversationRead(Request $request): Response
    {
        $guest = $this->guest($request);
        $id = $request->get('id', $request->input('id'));
        if (!is_int($id) && (!is_string($id) || !preg_match('/^\d+$/', $id))) {
            throw new ApiException('编号无效。', 422);
        }

        return $this->success((new CustomerServiceService())->guestConversationRead($guest, (int) $id));
    }

    public function conversationSave(Request $request): Response
    {
        $guest = $this->guest($request);
        $input = $request->post();
        if (!is_array($input)) {
            $input = [];
        }

        return $this->success((new CustomerServiceService())->guestConversationCreate($guest, $input));
    }

    /** @return array{organization:int,visitor_id:string,entry_id:?int,token_id:int,origin:string} */
    private function guest(Request $request): array
    {
        $guest = (new CustomerServiceService())->authenticateGuestToken($this->rawGuestToken($request));
        $this->assertModuleEnabled($guest['organization']);

        return $guest;
    }

    private function rawGuestToken(Request $request): string
    {
        $header = (string) ($request->header('x-cs-guest-token') ?? '');
        if ($header !== '') {
            return trim($header);
        }
        $auth = (string) ($request->header('authorization') ?? '');
        if (preg_match('/^Guest\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        $body = $request->input('guest_token');
        if (is_string($body) && $body !== '') {
            return trim($body);
        }
        throw new ApiException('缺少 guest token。', 401);
    }

    private function assertModuleEnabled(int $organization): void
    {
        $access = ModuleServiceFactory::access();
        $ok = $access->isAvailable($organization, 'customer_service', 'server', 'customer_service.public.entry')
            || $access->isAvailable($organization, 'customer_service', 'web', 'customer_service.web.use');
        if (!$ok) {
            throw new ApiException('客服模块未启用。', 403);
        }
    }
}
