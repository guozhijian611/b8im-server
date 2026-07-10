<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\tenantPolicy;

use plugin\saimulti\exception\ApiException;
use Throwable;

final class TenantImPolicyService
{
    public const SYNC_UNAVAILABLE = 50304;

    private const CLIENT_FAMILIES = ['web', 'app', 'desktop'];

    private const SAME_DEVICE_POLICIES = ['replace', 'coexist', 'reject'];

    private const CROSS_DEVICE_POLICIES = ['allow', 'kick_old', 'reject_new'];

    private const STATUSES = ['ENABLED', 'DISABLED'];

    private const MUTABLE_FIELDS = [
        'allowed_client_families',
        'allow_multi_device_online',
        'max_online_devices',
        'same_device_login_policy',
        'cross_device_login_policy',
        'max_message_concurrency',
        'max_message_qps',
        'default_group_display_member_count',
        'message_recall_window_seconds',
        'message_edit_window_seconds',
        'recall_notice_enabled',
        'group_recall_notice_enabled',
        'status',
    ];

    public function __construct(
        private readonly TenantImPolicyStoreInterface $store = new ThinkOrmTenantImPolicyStore(),
        private readonly TenantImPolicyPublisherInterface $publisher = new ThinkCacheTenantImPolicyPublisher(),
    ) {
    }

    /** @return array<string, mixed> */
    public static function defaults(?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');

        return [
            'allowed_client_families_json' => json_encode(self::CLIENT_FAMILIES, JSON_THROW_ON_ERROR),
            'allow_multi_device_online' => 1,
            'max_online_devices' => 5,
            'same_device_login_policy' => 'replace',
            'cross_device_login_policy' => 'allow',
            'max_message_concurrency' => 8,
            'max_message_qps' => 20,
            'default_group_display_member_count' => 50,
            'message_recall_window_seconds' => 120,
            'message_edit_window_seconds' => 120,
            'recall_notice_enabled' => 1,
            'group_recall_notice_enabled' => 1,
            'status' => 'ENABLED',
            'version' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ];
    }

    public function ensureDefault(int $organization): void
    {
        $this->assertOrganization($organization);
        $this->store->transaction(function () use ($organization): void {
            if (!$this->store->organizationExists($organization)) {
                throw new ApiException('机构不存在。', 404);
            }
            if ($this->store->find($organization, true) === null) {
                $this->store->createDefault($organization, self::defaults());
            }
        });
    }

    /** @return array<string, mixed> */
    public function read(int $organization): array
    {
        $this->assertOrganization($organization);
        $row = $this->store->find($organization);
        if ($row === null) {
            throw new ApiException('机构 IM 运行策略未初始化。', 404);
        }

        return $this->present($row);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function update(int $organization, array $input, array $actor): array
    {
        $this->assertOrganization($organization);
        $unknown = array_diff(array_keys($input), array_merge(self::MUTABLE_FIELDS, ['version']));
        if ($unknown !== []) {
            throw new ApiException('包含不可写的 IM 策略字段: ' . implode(', ', $unknown), 422);
        }
        $expectedVersion = $this->positiveInteger($input['version'] ?? null, 'version', 1, PHP_INT_MAX);

        $result = $this->store->transaction(function () use ($organization, $input, $expectedVersion): array {
            $current = $this->store->find($organization, true);
            if ($current === null) {
                throw new ApiException('机构 IM 运行策略未初始化。', 404);
            }
            if ((int) $current['version'] !== $expectedVersion) {
                // The database commit may have succeeded while the after-commit
                // Redis invalidation/event publication failed. Retrying the
                // exact request with its original version must republish the
                // already-persisted version instead of becoming a dead-end 409.
                if (
                    (int) $current['version'] === $expectedVersion + 1
                    && $this->requestedStateMatches($current, $input)
                ) {
                    return $current;
                }
                throw new ApiException('IM 策略已被其他操作者更新，请刷新后重试。', 409);
            }

            $normalized = $this->normalize(array_merge($current, $input));
            $normalized['version'] = $expectedVersion + 1;
            $normalized['update_time'] = date('Y-m-d H:i:s');
            if (!$this->store->update($organization, $expectedVersion, $normalized)) {
                throw new ApiException('IM 策略已被其他操作者更新，请刷新后重试。', 409);
            }

            return array_merge($current, $normalized, ['organization' => $organization]);
        });

        try {
            $this->publisher->invalidateAndPublish($organization, (int) $result['version'], $actor);
        } catch (Throwable $exception) {
            throw new ApiException(
                'IM 策略已保存，但缓存失效和踢线事件发布失败，请重试当前操作。',
                self::SYNC_UNAVAILABLE,
                $exception,
            );
        }

        return $this->present($result);
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $input */
    private function requestedStateMatches(array $current, array $input): bool
    {
        return $this->normalize($current) === $this->normalize(array_merge($current, $input));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalize(array $data): array
    {
        $families = $data['allowed_client_families'] ?? $data['allowed_client_families_json'] ?? null;
        if (is_string($families)) {
            $families = json_decode($families, true);
        }
        if (!is_array($families) || $families === []) {
            throw new ApiException('allowed_client_families 必须是非空客户端形态数组。', 422);
        }
        $families = array_values(array_unique(array_map(static fn (mixed $value): string => trim((string) $value), $families)));
        if (array_diff($families, self::CLIENT_FAMILIES) !== []) {
            throw new ApiException('客户端形态只允许 web、app、desktop。', 422);
        }

        $allowMulti = $this->boolean($data['allow_multi_device_online'] ?? null, 'allow_multi_device_online');
        $samePolicy = (string) ($data['same_device_login_policy'] ?? '');
        $crossPolicy = (string) ($data['cross_device_login_policy'] ?? '');
        if (!in_array($samePolicy, self::SAME_DEVICE_POLICIES, true)) {
            throw new ApiException('same_device_login_policy 无效。', 422);
        }
        if (!in_array($crossPolicy, self::CROSS_DEVICE_POLICIES, true)) {
            throw new ApiException('cross_device_login_policy 无效。', 422);
        }
        if (!$allowMulti && $crossPolicy === 'allow') {
            throw new ApiException('禁止多设备在线时，跨设备策略不能是 allow。', 422);
        }
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            throw new ApiException('status 只允许 ENABLED 或 DISABLED。', 422);
        }

        return [
            'allowed_client_families_json' => json_encode($families, JSON_THROW_ON_ERROR),
            'allow_multi_device_online' => $allowMulti ? 1 : 0,
            'max_online_devices' => $this->positiveInteger($data['max_online_devices'] ?? null, 'max_online_devices', 1, 100),
            'same_device_login_policy' => $samePolicy,
            'cross_device_login_policy' => $crossPolicy,
            'max_message_concurrency' => $this->positiveInteger($data['max_message_concurrency'] ?? null, 'max_message_concurrency', 1, 1000),
            'max_message_qps' => $this->positiveInteger($data['max_message_qps'] ?? null, 'max_message_qps', 1, 10000),
            'default_group_display_member_count' => $this->positiveInteger($data['default_group_display_member_count'] ?? null, 'default_group_display_member_count', 1, 100000),
            'message_recall_window_seconds' => $this->positiveInteger($data['message_recall_window_seconds'] ?? null, 'message_recall_window_seconds', 0, 86400),
            'message_edit_window_seconds' => $this->positiveInteger($data['message_edit_window_seconds'] ?? null, 'message_edit_window_seconds', 0, 86400),
            'recall_notice_enabled' => $this->boolean($data['recall_notice_enabled'] ?? null, 'recall_notice_enabled') ? 1 : 0,
            'group_recall_notice_enabled' => $this->boolean($data['group_recall_notice_enabled'] ?? null, 'group_recall_notice_enabled') ? 1 : 0,
            'status' => $status,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $families = $row['allowed_client_families_json'] ?? [];
        if (is_string($families)) {
            $families = json_decode($families, true);
        }
        $row['allowed_client_families'] = is_array($families) ? array_values($families) : [];
        unset($row['allowed_client_families_json']);
        foreach (['allow_multi_device_online', 'recall_notice_enabled', 'group_recall_notice_enabled'] as $field) {
            $row[$field] = (bool) ($row[$field] ?? false);
        }

        return $row;
    }

    private function assertOrganization(int $organization): void
    {
        if ($organization <= 0) {
            throw new ApiException('organization 无效。', 422);
        }
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }

        throw new ApiException($field . ' 必须是布尔值。', 422);
    }

    private function positiveInteger(mixed $value, string $field, int $minimum, int $maximum): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ApiException(sprintf('%s 必须是 %d 到 %d 之间的整数。', $field, $minimum, $maximum), 422);
        }

        return $value;
    }
}
