<?php
namespace plugin\saimulti\app\logic\system;

use plugin\saimulti\app\model\system\SystemOrganization;
use plugin\saimulti\app\model\tenant\Role;
use plugin\saimulti\app\model\tenant\User;
use plugin\saimulti\app\model\tenant\UserRole;
use plugin\saimulti\basic\BaseLogic;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\OrganizationDiscovery;
use plugin\saimulti\service\adminIm\OrganizationImAccessService;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\tenantPolicy\TenantImPolicyService;
use plugin\saimulti\service\tenantPolicy\ThinkOrmTenantImPolicyStore;
use plugin\saimulti\service\web\TenantAccountPolicyService;
use support\think\Db;

/**
 * 单位信息逻辑层
 */
class SystemOrganizationLogic extends BaseLogic
{
    private const WRITABLE_FIELDS = [
        'group_id',
        'domain',
        'enterprise_code',
        'deployment_id',
        'title',
        'logo',
        'favicon',
        'icp',
        'public_security_record_no',
        'public_security_record_url',
        'copyright',
        'android_download_url',
        'ios_download_url',
        'user_agreement_title',
        'user_agreement_content',
        'privacy_policy_title',
        'privacy_policy_content',
        'organization_name',
        'province',
        'city',
        'area',
        'address',
        'contact_name',
        'contact_phone',
        'contact_email',
        'status',
        'remark',
    ];

    private const TENANT_PROFILE_FIELDS = [
        'title',
        'logo',
        'favicon',
        'icp',
        'public_security_record_no',
        'public_security_record_url',
        'copyright',
        'android_download_url',
        'ios_download_url',
        'user_agreement_title',
        'user_agreement_content',
        'privacy_policy_title',
        'privacy_policy_content',
        'organization_name',
        'province',
        'city',
        'area',
        'address',
        'contact_name',
        'contact_phone',
        'contact_email',
        'remark',
    ];

    public function __construct()
    {
        $this->model = new SystemOrganization();
    }

    public function initTenant($id)
    {
        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            throw new ApiException('未找到该机构信息');
        }
        if ($info->is_init === 1) {
            throw new ApiException('该租户已初始化过，无需再次初始化');
        }
        $this->transaction(function() use ($info) {
            // 1. 创建角色
            $role = Role::create([
                'organization' => $info->id,
                'parent_id' => 0,
                'level' => 100,
                'name' => '超级管理员',
                'code' => 'superAdmin',
                'status' => 1,
                'sort' => 1,
                'remark' => '系统内置角色，不可删除'
            ]);
            // 2. 创建超级管理员
            $user = User::create([
                'organization' => $info->id,
                'username' => 'admin',
                'nickname' => $info->organization_name,
                'user_type' => '100',
                'password' => password_hash('sa123456@', PASSWORD_DEFAULT),
                'status' => 1,
                'dashboard' => 'statistics'
            ]);
            // 3. 创建对应关系
            UserRole::create([
                'user_id' => $user->id,
                'role_id' => $role->id
            ]);
            // 4. 更新初始化状态
            $info->is_init = 1;
            $info->save();
        });
    }

    public function tenant($id): array
    {
        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            throw new ApiException('未找到该应用');
        }
        if ($info->status !== 1) {
            throw new ApiException('当前应用已关闭,暂时无法访问！');
        }
        return array_intersect_key(
            $info->toArray(),
            array_flip(array_merge(['id', 'config_version'], self::TENANT_PROFILE_FIELDS)),
        );
    }

    public function add($data, array $actor = []): bool
    {
        $data = $this->normalizeWriteData((array) $data);
        $data['config_version'] = 1;

        $saved = Db::transaction(function () use ($data): bool {
            $saved = $this->model->save($data);
            if (!$saved) {
                return false;
            }

            (new ThinkOrmTenantImPolicyStore())->createDefault(
                (int) $this->model->id,
                TenantImPolicyService::defaults(),
            );
            (new TenantAccountPolicyService())->createDefault((int) $this->model->id);
            Db::table('sm_tenant_quota')->insert([
                'organization' => (int) $this->model->id,
                'quota_key' => 'im_user_seats',
                'quota_value' => 0,
                'used_value' => 0,
                'source' => 'package',
                'status' => 'active',
                'order_no' => 'organization-create',
                'remark' => '机构创建后由平台配置购买席位',
                'version' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);

            return true;
        });

        if ($saved && (int) ($data['group_id'] ?? 0) > 0 && (int) ($data['status'] ?? 1) === 1) {
            ModuleServiceFactory::tenantAssignments()->syncOrganizationFromGroup(
                (int) $this->model->id,
                $actor,
            );
        }

        return $saved;
    }

    public function edit($id, $data, array $actor = []): mixed
    {
        $input = (array) $data;
        $statusWasRequested = array_key_exists('status', $input);
        $groupWasRequested = array_key_exists('group_id', $input);
        $access = new OrganizationImAccessService();
        $now = date('Y-m-d H:i:s');

        $result = Db::transaction(function () use (
            $id,
            $input,
            $statusWasRequested,
            $groupWasRequested,
            $access,
            $now,
        ): array {
            $locked = Db::table('sm_system_organization')
                ->where('id', (int) $id)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if (!$locked) {
                throw new ApiException('未找到该机构信息', OrganizationDiscovery::UNAVAILABLE);
            }

            $data = $this->normalizeWriteData($input, $locked);
            $previousStatus = (int) ($locked['status'] ?? 0);
            $nextStatus = (int) ($data['status'] ?? $previousStatus);
            $statusChanged = $previousStatus !== $nextStatus;
            $groupChanged = (int) ($locked['group_id'] ?? 0) !== (int) ($data['group_id'] ?? $locked['group_id'] ?? 0);
            $data['update_time'] = $now;

            $updated = Db::table('sm_system_organization')
                ->where('id', (int) $id)
                ->whereNull('delete_time')
                ->inc('config_version')
                ->update($data);
            if ($updated !== 1) {
                throw new \RuntimeException('机构配置更新未持久化。');
            }

            $credentialSessionIds = [];
            if (self::shouldRevokeImAccess($statusWasRequested, $nextStatus)) {
                $credentialSessionIds = $access->revokeInsideTransaction((int) $id, $now);
            }

            return compact(
                'updated',
                'statusChanged',
                'groupChanged',
                'nextStatus',
                'credentialSessionIds',
            );
        });

        // An explicit status write is also the retry contract for a failed
        // after-commit Redis synchronization. Re-sending status=1 must clear a
        // stale inactive marker even though MySQL already contains status=1.
        if ($result['statusChanged'] || $statusWasRequested) {
            $this->publishOrganizationImAccess(
                $access,
                (int) $id,
                $result['nextStatus'] === 1,
                $result['credentialSessionIds'],
                $now,
                $result['nextStatus'] === 1 ? 'organization_enabled' : 'organization_disabled',
            );
        }

        if (($result['groupChanged'] || $groupWasRequested) && $result['nextStatus'] === 1) {
            ModuleServiceFactory::tenantAssignments()->syncOrganizationFromGroup((int) $id, $actor);
        }

        return $result['updated'];
    }

    private static function shouldRevokeImAccess(bool $statusWasRequested, int $nextStatus): bool
    {
        return $statusWasRequested && $nextStatus !== 1;
    }

    public function destroy($ids): bool
    {
        $ids = self::normalizedOrganizationIds($ids);
        if ($ids === []) {
            throw new ApiException('机构编号无效。', 422);
        }
        $organizations = $this->model->whereIn('id', $ids)->select()->toArray();
        if (count($organizations) !== count($ids)) {
            throw new ApiException('部分机构不存在。', 404);
        }

        $access = new OrganizationImAccessService();
        $now = date('Y-m-d H:i:s');
        $sessions = [];
        $deleted = Db::transaction(function () use ($ids, $access, $now, &$sessions): bool {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $lockedRows = Db::query(
                'SELECT id FROM sm_system_organization '
                . 'WHERE id IN (' . $placeholders . ') AND delete_time IS NULL '
                . 'ORDER BY id ASC FOR UPDATE',
                $ids,
            );
            $lockedIds = array_map(static fn (array $row): int => (int) $row['id'], $lockedRows);
            if ($lockedIds !== $ids) {
                throw new ApiException('部分机构不存在。', 404);
            }

            // Keep the global lock order organization -> auth/access/device.
            // Soft-delete first inside the same transaction so any waiting
            // login observes delete_time and cannot insert a fresh bearer.
            $deleted = $this->model->destroy($ids);
            if (!$deleted) {
                throw new \RuntimeException('机构删除未持久化。');
            }
            foreach ($ids as $organization) {
                $sessions[$organization] = $access->revokeInsideTransaction($organization, $now);
            }

            return true;
        });

        if ($deleted) {
            foreach ($ids as $organization) {
                $this->publishOrganizationImAccess(
                    $access,
                    $organization,
                    false,
                    $sessions[$organization] ?? [],
                    $now,
                    'organization_deleted',
                );
            }
        }

        return $deleted;
    }

    /** @return list<int> */
    private static function normalizedOrganizationIds(mixed $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $ids),
            static fn (int $id): bool => $id > 0,
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Tenant-owned branding and public content. Entry identifiers, deployment
     * trust roots, organization status and server_info remain platform-owned.
     *
     * @param array<string, mixed> $data
     */
    public function editTenantProfile(int $organization, array $data): mixed
    {
        $data = self::tenantProfileData($data);

        return $this->edit($organization, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function tenantProfileData(array $data): array
    {
        return array_intersect_key($data, array_flip(self::TENANT_PROFILE_FIELDS));
    }

    public function appInfo(string $identifier, string $mode, string $clientFamily): array
    {
        return (new OrganizationDiscovery())->resolve($identifier, $mode, $clientFamily);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function normalizeWriteData(array $data, ?array $existing = null): array
    {
        $data = array_intersect_key($data, array_flip(self::WRITABLE_FIELDS));
        $requestedFields = array_keys($data);
        $merged = array_merge($existing ?? [], $data);

        $merged['enterprise_code'] = OrganizationDiscovery::normalizeEnterpriseCode(
            (string) ($merged['enterprise_code'] ?? ''),
        );
        $merged['deployment_id'] = OrganizationDiscovery::assertDeploymentId(
            (string) ($merged['deployment_id'] ?? ''),
        );
        $merged['domain'] = trim((string) ($merged['domain'] ?? ''));
        $merged['domain'] = $merged['domain'] === ''
            ? null
            : OrganizationDiscovery::normalizeDomain($merged['domain']);

        (new OrganizationDiscovery())->validatePublicConfiguration($merged);

        foreach ($requestedFields as $field) {
            if (array_key_exists($field, $merged)) {
                $data[$field] = $merged[$field];
            }
        }

        return $data;
    }

    /** @param list<string> $credentialSessionIds */
    private function publishOrganizationImAccess(
        OrganizationImAccessService $access,
        int $organization,
        bool $active,
        array $credentialSessionIds,
        string $now,
        string $reason,
    ): void {
        try {
            $access->afterCommit($organization, $active, $credentialSessionIds, $now, $reason);
        } catch (\Throwable $throwable) {
            throw new ApiException(
                '机构状态已保存，但 IM 访问状态同步失败，请重试当前操作。',
                OrganizationImAccessService::SYNC_UNAVAILABLE,
                $throwable,
            );
        }
    }
}
