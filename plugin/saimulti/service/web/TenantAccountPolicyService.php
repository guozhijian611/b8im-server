<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;

final class TenantAccountPolicyService
{
    public const MAX_SAFE_VERSION = 9007199254740991;

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

    /** @return array{organization:int,register_enabled:bool,version:int,update_time:string} */
    public function read(int $organization): array
    {
        $this->assertOrganization($organization);

        return $this->present($this->enabledPolicy($organization));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{organization:int,register_enabled:bool,version:int,update_time:string}
     */
    public function update(int $organization, array $input): array
    {
        $this->assertOrganization($organization);
        $unknown = array_diff(array_keys($input), ['register_enabled', 'version']);
        if ($unknown !== []) {
            throw new ApiException('包含不可写的账号注册策略字段: ' . implode(', ', $unknown), 422);
        }
        if (!array_key_exists('register_enabled', $input) || !is_bool($input['register_enabled'])) {
            throw new ApiException('register_enabled 必须是布尔值。', 422);
        }
        $expectedVersion = $input['version'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 1 || $expectedVersion >= self::MAX_SAFE_VERSION) {
            throw new ApiException('version 必须是可增量的正整数。', 422);
        }
        $registerEnabled = $input['register_enabled'];

        $row = $this->store()->transaction(function () use (
            $organization,
            $expectedVersion,
            $registerEnabled,
        ): array {
            $current = $this->enabledPolicy($organization, true);
            $currentVersion = $this->version($current);
            $currentEnabled = $this->enabled($current, 'register_enabled');
            if ($currentVersion !== $expectedVersion) {
                if ($currentVersion === $expectedVersion + 1 && $currentEnabled === $registerEnabled) {
                    return $current;
                }
                throw new ApiException('账号注册策略已被其他操作者更新，请刷新后重试。', 409);
            }
            if ($currentEnabled === $registerEnabled) {
                return $current;
            }
            if ($registerEnabled) {
                $this->assertOpenRegistrationSupported($current);
            }

            $values = [
                'register_enabled' => $registerEnabled ? 1 : 0,
                'version' => $expectedVersion + 1,
                'update_time' => date('Y-m-d H:i:s'),
            ];
            if (!$this->store()->updateRegisterEnabled($organization, $expectedVersion, $values)) {
                throw new ApiException('账号注册策略已被其他操作者更新，请刷新后重试。', 409);
            }

            return array_merge($current, $values, ['organization' => $organization]);
        });

        return $this->present($row);
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

    /** @return array<string, mixed> */
    private function enabledPolicy(int $organization, bool $lock = false): array
    {
        $policy = $this->store()->find($organization, $lock);
        if ($policy === null) {
            throw new ApiException('当前机构账号策略未初始化。', 404);
        }
        if (!hash_equals('ENABLED', strtoupper(trim((string) ($policy['status'] ?? ''))))) {
            throw new ApiException('当前机构账号策略不可用。', 409);
        }

        return $policy;
    }

    /** @param array<string, mixed> $policy */
    private function assertOpenRegistrationSupported(array $policy): void
    {
        foreach (['invite_required', 'email_verify_enabled', 'mobile_verify_enabled', 'realname_required'] as $field) {
            if ($this->enabled($policy, $field)) {
                throw new ApiException('当前账号策略要求额外身份验证，不能开启 Web 公开注册。', 422);
            }
        }
    }

    /** @param array<string, mixed> $row @return array{organization:int,register_enabled:bool,version:int,update_time:string} */
    private function present(array $row): array
    {
        return [
            'organization' => (int) ($row['organization'] ?? 0),
            'register_enabled' => $this->enabled($row, 'register_enabled'),
            'version' => $this->version($row),
            'update_time' => (string) ($row['update_time'] ?? ''),
        ];
    }

    private function assertOrganization(int $organization): void
    {
        if ($organization <= 0) {
            throw new ApiException('organization 无效。', 422);
        }
    }

    /** @param array<string,mixed> $row */
    private function version(array $row): int
    {
        $version = $row['version'] ?? null;
        if (!is_int($version) && !(is_string($version) && preg_match('/^[1-9][0-9]*$/D', $version) === 1)) {
            throw new ApiException('账号注册策略版本无效。', 409);
        }
        $version = (int) $version;
        if ($version < 1 || $version > self::MAX_SAFE_VERSION) {
            throw new ApiException('账号注册策略版本无效。', 409);
        }
        return $version;
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
