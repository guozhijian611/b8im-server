<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;

final class TenantAccountPolicyService
{
    public function __construct(private readonly ?TenantAccountPolicyStoreInterface $store = null)
    {
    }

    /** @return array<string, mixed> */
    public static function defaults(?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');

        return [
            'register_enabled' => 0,
            'invite_required' => 0,
            'tenant_invite_enabled' => 0,
            'user_invite_enabled' => 0,
            'email_verify_enabled' => 0,
            'mobile_verify_enabled' => 0,
            'email_provider_config_id' => null,
            'sms_provider_config_id' => null,
            'realname_required' => 0,
            'invite_code_mode' => 'tenant_single',
            'invite_auto_friend' => 0,
            'invite_bind_customer_service' => 0,
            'status' => 'ENABLED',
            'version' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ];
    }

    public function createDefault(int $organization): void
    {
        if ($organization <= 0) {
            throw new ApiException('机构编号无效。', 422);
        }
        $this->store()->createDefault($organization, self::defaults());
    }

    /** @return array<string, mixed> */
    public function publicPolicy(int $organization): array
    {
        $policy = $this->policy($organization, false);

        return [
            'register_enabled' => $this->enabled($policy, 'register_enabled'),
            'invite_required' => $this->enabled($policy, 'invite_required'),
            'tenant_invite_enabled' => $this->enabled($policy, 'tenant_invite_enabled'),
            'user_invite_enabled' => $this->enabled($policy, 'user_invite_enabled'),
            'email_verify_enabled' => $this->enabled($policy, 'email_verify_enabled'),
            'mobile_verify_enabled' => $this->enabled($policy, 'mobile_verify_enabled'),
            'realname_required' => $this->enabled($policy, 'realname_required'),
            'invite_code_mode' => (string) ($policy['invite_code_mode'] ?? ''),
        ];
    }

    /** Must be called after the organization row is locked. @return array<string, mixed> */
    public function lockOpenRegistration(int $organization): array
    {
        $policy = $this->policy($organization, true);
        if (!$this->enabled($policy, 'register_enabled')) {
            throw new ApiException('当前机构未开放注册。', 403);
        }
        foreach (['invite_required', 'email_verify_enabled', 'mobile_verify_enabled', 'realname_required'] as $requirement) {
            if ($this->enabled($policy, $requirement)) {
                throw new ApiException('当前账号策略要求额外身份验证，Web 开放注册不可用。', 403);
            }
        }

        return $policy;
    }

    /** @return array<string, mixed> */
    private function policy(int $organization, bool $lock): array
    {
        if ($organization <= 0) {
            throw new ApiException('当前应用不可用。', 41003);
        }
        $policy = $this->store()->find($organization, $lock);
        if ($policy === null || !hash_equals('ENABLED', strtoupper(trim((string) ($policy['status'] ?? ''))))) {
            throw new ApiException('当前机构账号策略不可用。', 403);
        }

        return $policy;
    }

    /** @param array<string, mixed> $policy */
    private function enabled(array $policy, string $field): bool
    {
        return (int) ($policy[$field] ?? 0) === 1;
    }

    private function store(): TenantAccountPolicyStoreInterface
    {
        return $this->store ?? new ThinkOrmTenantAccountPolicyStore();
    }
}
