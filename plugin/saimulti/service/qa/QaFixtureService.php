<?php

declare(strict_types=1);

namespace plugin\saimulti\service\qa;

use B8im\ModuleSdk\State\SystemModuleStatus;
use B8im\ModuleSdk\State\TenantModuleStatus;
use plugin\saimulti\service\module\ModuleServiceFactory;
use plugin\saimulti\service\routing\RoutingConfigService;
use plugin\saimulti\service\web\RedisWebImLoginRateLimiter;
use RuntimeException;
use support\think\Db;

final class QaFixtureService
{
    public const MARKER = '[QA_FIXTURE:b8im-im-reliability-v1]';
    public const ADMIN_USERNAME = 'admin';
    public const ADMIN_PASSWORD = '123456';
    public const TENANT_USERNAME = 'qa_tenant_admin';
    public const TENANT_PASSWORD = 'QaTenant123456!';
    public const IM_PASSWORD = 'QaIm123456!';
    public const PRIMARY_CODE = 'qa_im_primary';
    public const ISOLATED_CODE = 'qa_im_isolated';

    /** @return array<string, mixed> */
    public function provision(): array
    {
        $fixture = Db::transaction(function (): array {
            $this->databaseName();
            $now = date('Y-m-d H:i:s');
            $this->resetAdmin($now);
            $primary = $this->upsertOrganization(self::PRIMARY_CODE, 'QA IM 主机构', $now);
            $isolated = $this->upsertOrganization(self::ISOLATED_CODE, 'QA IM 隔离机构', $now);
            $this->upsertTenant((int) $primary['id'], $now);
            $this->upsertPolicy((int) $primary['id'], $now);
            $this->upsertPolicy((int) $isolated['id'], $now);
            $this->upsertAnnouncementLicense((int) $primary['id'], $now);
            $this->upsertRouting((int) $primary['id'], self::PRIMARY_CODE);
            $this->upsertRouting((int) $isolated['id'], self::ISOLATED_CODE);
            $this->upsertImUser((int) $primary['id'], 'qa-im-user-a', 'qa_im_a', 'QA IM User A', '89000001', $now);
            $this->upsertImUser((int) $primary['id'], 'qa-im-user-b', 'qa_im_b', 'QA IM User B', '89000002', $now);
            $this->upsertImUser((int) $isolated['id'], 'qa-im-user-x', 'qa_im_x', 'QA IM User X', '89000003', $now);
            $this->upsertFriendship((int) $primary['id'], 'qa-im-user-a', 'qa-im-user-b', $now);

            return $this->status();
        });

        ModuleServiceFactory::access()->invalidate(
            (int) $fixture['organizations'][self::PRIMARY_CODE]['id'],
            'announcement',
        );
        $this->resetImLoginRateLimits($fixture);

        return $fixture;
    }

    /** @param array<string, mixed> $fixture */
    private function resetImLoginRateLimits(array $fixture): void
    {
        $allowedAccounts = ['qa_im_a' => true, 'qa_im_b' => true, 'qa_im_x' => true];
        $limiter = new RedisWebImLoginRateLimiter();
        foreach ((array) ($fixture['im_users'] ?? []) as $user) {
            if (!is_array($user)) {
                continue;
            }
            $account = (string) ($user['account'] ?? '');
            $organization = (int) ($user['organization'] ?? 0);
            if (!isset($allowedAccounts[$account]) || $organization <= 0) {
                throw new RuntimeException('Refusing to reset a non-QA login limiter scope.');
            }
            $limiter->resetAccountAttempts($organization, $account);
        }
    }

    /** @return array<string, mixed> */
    public function cleanup(): array
    {
        return Db::transaction(function (): array {
            $organizations = Db::table('sm_system_organization')
                ->whereIn('enterprise_code', [self::PRIMARY_CODE, self::ISOLATED_CODE])
                ->select()
                ->toArray();
            foreach ($organizations as $organization) {
                if (!hash_equals(self::MARKER, (string) ($organization['remark'] ?? ''))) {
                    throw new RuntimeException('Refusing to clean organization without the exact QA marker: ' . $organization['enterprise_code']);
                }
            }
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $organizations);
            if ($ids === []) {
                return ['cleaned' => true, 'organization_ids' => []];
            }

            $tenantUserIds = Db::table('sm_tenant_user')->whereIn('organization', $ids)->column('id');
            if ($tenantUserIds !== []) {
                foreach (['sm_tenant_user_role', 'sm_tenant_user_post', 'sm_tenant_dept_leader'] as $table) {
                    if ($this->tableExists($table)) {
                        Db::table($table)->whereIn('user_id', $tenantUserIds)->delete();
                    }
                }
            }
            $roleIds = $this->idsFor('sm_tenant_role', $ids);
            if ($roleIds !== [] && $this->tableExists('sm_tenant_role_menu')) {
                Db::table('sm_tenant_role_menu')->whereIn('role_id', $roleIds)->delete();
            }

            foreach ($this->organizationTables() as $table) {
                Db::table($table)->whereIn('organization', $ids)->delete();
            }
            $routePoolIds = [$this->routePoolId(self::PRIMARY_CODE), $this->routePoolId(self::ISOLATED_CODE)];
            foreach (['sm_server_route_pool_item', 'sm_server_route_pool_version', 'sm_server_route_pool'] as $table) {
                Db::table($table)
                    ->where('deployment_id', (string) env('DEPLOYMENT_ID', 'b8im-local'))
                    ->whereIn('route_pool_id', $routePoolIds)
                    ->delete();
            }
            Db::table('sm_system_organization')->whereIn('id', $ids)->delete();

            return ['cleaned' => true, 'organization_ids' => $ids];
        });
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $admin = Db::table('sm_admin')->where('username', self::ADMIN_USERNAME)->find();
        $organizations = [];
        foreach ([self::PRIMARY_CODE, self::ISOLATED_CODE] as $code) {
            $row = Db::table('sm_system_organization')->where('enterprise_code', $code)->find();
            if (!$row || !hash_equals(self::MARKER, (string) ($row['remark'] ?? ''))) {
                throw new RuntimeException("QA organization is missing or untrusted: {$code}");
            }
            $organizations[$code] = [
                'id' => (int) $row['id'],
                'enterprise_code' => $code,
                'deployment_id' => (string) $row['deployment_id'],
            ];
        }
        $primaryId = $organizations[self::PRIMARY_CODE]['id'];
        $isolatedId = $organizations[self::ISOLATED_CODE]['id'];
        $tenant = Db::table('sm_tenant_user')
            ->where('organization', $primaryId)
            ->where('username', self::TENANT_USERNAME)
            ->find();
        $users = Db::table('im_user')
            ->whereIn('organization', [$primaryId, $isolatedId])
            ->whereIn('account', ['qa_im_a', 'qa_im_b', 'qa_im_x'])
            ->order('account')
            ->select()
            ->toArray();

        if (!$admin || !password_verify(self::ADMIN_PASSWORD, (string) $admin['password']) || (int) $admin['status'] !== 1) {
            throw new RuntimeException('QA admin credentials are not valid.');
        }
        if (!$tenant || !password_verify(self::TENANT_PASSWORD, (string) $tenant['password']) || (int) $tenant['user_type'] !== 100) {
            throw new RuntimeException('QA tenant credentials are not valid.');
        }
        if (count($users) !== 3) {
            throw new RuntimeException('QA IM users are incomplete.');
        }
        foreach ($users as $user) {
            if (!password_verify(self::IM_PASSWORD, (string) $user['password_hash']) || (int) $user['status'] !== 1) {
                throw new RuntimeException('QA IM credentials are not valid: ' . $user['account']);
            }
            $groupAccessState = Db::table('im_user_group_access_state')
                ->where('organization', (int) $user['organization'])
                ->where('user_id', (string) $user['user_id'])
                ->find();
            if (!$groupAccessState
                || preg_match('/^[1-9][0-9]*$/', (string) ($groupAccessState['access_snapshot_id'] ?? '')) !== 1) {
                throw new RuntimeException('QA IM group access state is not valid: ' . $user['account']);
            }
        }
        $announcementLicense = Db::table('sm_tenant_module_license')
            ->where('organization', $primaryId)
            ->where('module_key', 'announcement')
            ->whereNull('delete_time')
            ->find();
        if (!$announcementLicense
            || !hash_equals(TenantModuleStatus::ENABLED->value, (string) $announcementLicense['status'])
            || !hash_equals(self::MARKER, (string) ($announcementLicense['remark'] ?? ''))
            || $announcementLicense['expire_at'] !== null) {
            throw new RuntimeException('QA primary organization announcement license is not enabled and trusted.');
        }

        return [
            'marker' => self::MARKER,
            'admin' => ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD],
            'organizations' => $organizations,
            'tenant' => [
                'organization' => $primaryId,
                'enterprise_code' => self::PRIMARY_CODE,
                'username' => self::TENANT_USERNAME,
                'password' => self::TENANT_PASSWORD,
            ],
            'im_users' => array_map(static fn (array $user): array => [
                'organization' => (int) $user['organization'],
                'user_id' => (string) $user['user_id'],
                'account' => (string) $user['account'],
                'password' => self::IM_PASSWORD,
            ], $users),
            'announcement_license' => [
                'organization' => $primaryId,
                'module_key' => 'announcement',
                'status' => TenantModuleStatus::ENABLED->value,
                'remark' => self::MARKER,
            ],
        ];
    }

    private function resetAdmin(string $now): void
    {
        $admin = Db::table('sm_admin')->where('username', self::ADMIN_USERNAME)->find();
        if (!$admin) {
            throw new RuntimeException('Default super admin does not exist; refusing to invent a replacement row.');
        }
        Db::table('sm_admin')->where('id', (int) $admin['id'])->update([
            'password' => password_hash(self::ADMIN_PASSWORD, PASSWORD_DEFAULT),
            'user_type' => 100,
            'status' => 1,
            'delete_time' => null,
            'update_time' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function upsertOrganization(string $code, string $name, string $now): array
    {
        $existing = Db::table('sm_system_organization')->where('enterprise_code', $code)->find();
        if ($existing && !hash_equals(self::MARKER, (string) ($existing['remark'] ?? ''))) {
            throw new RuntimeException("Enterprise code {$code} is owned by non-QA data.");
        }
        $data = [
            'deployment_id' => (string) env('DEPLOYMENT_ID', 'b8im-local'),
            'organization_name' => $name,
            'title' => $name,
            'status' => 1,
            'remark' => self::MARKER,
            'is_init' => 1,
            'delete_time' => null,
            'update_time' => $now,
        ];
        if ($existing) {
            Db::table('sm_system_organization')->where('id', (int) $existing['id'])->update($data);
        } else {
            Db::table('sm_system_organization')->insert($data + [
                'enterprise_code' => $code,
                'create_time' => $now,
            ]);
        }
        return Db::table('sm_system_organization')->where('enterprise_code', $code)->find();
    }

    private function upsertTenant(int $organization, string $now): void
    {
        $data = [
            'password' => password_hash(self::TENANT_PASSWORD, PASSWORD_DEFAULT),
            'user_type' => 100,
            'nickname' => 'QA Tenant Admin',
            'status' => 1,
            'remark' => self::MARKER,
            'delete_time' => null,
            'update_time' => $now,
        ];
        $existing = Db::table('sm_tenant_user')->where('organization', $organization)->where('username', self::TENANT_USERNAME)->find();
        if ($existing) {
            if (!hash_equals(self::MARKER, (string) ($existing['remark'] ?? ''))) {
                throw new RuntimeException('QA tenant username is owned by non-QA data.');
            }
            Db::table('sm_tenant_user')->where('id', (int) $existing['id'])->update($data);
        } else {
            Db::table('sm_tenant_user')->insert($data + [
                'organization' => $organization,
                'username' => self::TENANT_USERNAME,
                'create_time' => $now,
            ]);
        }
    }

    private function upsertPolicy(int $organization, string $now): void
    {
        $data = [
            'allowed_client_families_json' => json_encode(['web', 'app', 'desktop'], JSON_THROW_ON_ERROR),
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
            'update_time' => $now,
        ];
        $existing = Db::table('sm_tenant_im_policy')->where('organization', $organization)->find();
        if ($existing) {
            Db::table('sm_tenant_im_policy')->where('id', (int) $existing['id'])->update($data);
        } else {
            Db::table('sm_tenant_im_policy')->insert($data + ['organization' => $organization, 'version' => 1, 'create_time' => $now]);
        }
    }

    private function upsertAnnouncementLicense(int $organization, string $now): void
    {
        $module = Db::table('sm_module')
            ->where('module_key', 'announcement')
            ->whereNull('delete_time')
            ->find();
        if (!$module || !hash_equals(SystemModuleStatus::ENABLED->value, (string) $module['status'])) {
            throw new RuntimeException('QA fixtures require the existing announcement module to be ENABLED.');
        }

        $license = Db::table('sm_tenant_module_license')
            ->where('organization', $organization)
            ->where('module_key', 'announcement')
            ->find();
        if ($license
            && hash_equals(TenantModuleStatus::ENABLED->value, (string) $license['status'])
            && hash_equals(self::MARKER, (string) ($license['remark'] ?? ''))
            && $license['expire_at'] === null
            && $license['delete_time'] === null) {
            return;
        }

        $data = [
            'status' => TenantModuleStatus::ENABLED->value,
            'expire_at' => null,
            'granted_by' => null,
            'revoked_by' => null,
            'authorized_at' => $now,
            'enabled_at' => $now,
            'disabled_at' => null,
            'revoked_at' => null,
            'remark' => self::MARKER,
            'update_time' => $now,
            'delete_time' => null,
        ];
        if ($license) {
            Db::table('sm_tenant_module_license')->where('id', (int) $license['id'])->update($data + [
                'version' => (int) $license['version'] + 1,
            ]);

            return;
        }

        Db::table('sm_tenant_module_license')->insert($data + [
            'organization' => $organization,
            'module_key' => 'announcement',
            'version' => 1,
            'create_time' => $now,
        ]);
    }

    private function upsertRouting(int $organization, string $code): void
    {
        $source = Db::table('sm_system_organization')
            ->where('enterprise_code', 'org_1')
            ->where('status', 1)
            ->whereNull('delete_time')
            ->find();
        if (!$source) {
            throw new RuntimeException('The local routing template organization org_1 is unavailable.');
        }
        $qa = Db::table('sm_system_organization')->where('id', $organization)->find();
        if (!$qa || !hash_equals((string) $source['deployment_id'], (string) $qa['deployment_id'])) {
            throw new RuntimeException("QA organization {$code} does not share the org_1 local deployment trust domain.");
        }

        $routing = new RoutingConfigService();
        $sourceInfo = $routing->read((int) $source['id'], 'web')['server_info'];
        $routePoolId = $this->routePoolId($code);
        $mode = (string) ($sourceInfo['policy']['mode'] ?? '');
        $routes = array_map(static fn (array $route): array => [
            'route_id' => (string) $route['route_id'],
            'name' => (string) $route['name'],
            'priority' => (int) $route['priority'],
            'weight' => (int) $route['weight'],
            'region' => (string) ($route['region'] ?? ''),
            'carrier' => (string) ($route['carrier'] ?? ''),
            'failure_domain' => (string) ($route['failure_domain'] ?? ''),
            'endpoints' => (array) $route['endpoints'],
        ], (array) ($sourceInfo['routes'] ?? []));
        if ($routes === [] || !in_array($mode, RoutingConfigService::MODES, true)) {
            throw new RuntimeException('The org_1 local routing template is incomplete.');
        }

        $expectedFingerprint = $this->routingFingerprint($mode, $routePoolId, $routes);
        $familiesReady = true;
        foreach (RoutingConfigService::CLIENT_FAMILIES as $family) {
            try {
                $current = $routing->read($organization, $family)['server_info'];
                if ($this->routingFingerprint(
                    (string) ($current['policy']['mode'] ?? ''),
                    (string) ($current['route_pool_id'] ?? ''),
                    (array) ($current['routes'] ?? []),
                ) !== $expectedFingerprint) {
                    $familiesReady = false;
                    break;
                }
            } catch (\Throwable) {
                $familiesReady = false;
                break;
            }
        }
        if ($familiesReady) {
            return;
        }

        $deploymentName = (string) (Db::table('sm_server_deployment')
            ->where('deployment_id', (string) $source['deployment_id'])
            ->value('name') ?: $source['deployment_id']);
        $routing->publish([
            'organization' => $organization,
            'deployment_id' => (string) $source['deployment_id'],
            'deployment_name' => $deploymentName,
            'route_pool_id' => $routePoolId,
            'route_pool_name' => "QA {$code} local routing",
            'mode' => $mode,
            'client_families' => RoutingConfigService::CLIENT_FAMILIES,
            'routes' => $routes,
        ]);
    }

    /** @param list<array<string, mixed>> $routes */
    private function routingFingerprint(string $mode, string $routePoolId, array $routes): string
    {
        $normalized = array_map(static fn (array $route): array => [
            'route_id' => (string) ($route['route_id'] ?? ''),
            'name' => (string) ($route['name'] ?? ''),
            'priority' => (int) ($route['priority'] ?? 0),
            'weight' => (int) ($route['weight'] ?? 0),
            'region' => (string) ($route['region'] ?? ''),
            'carrier' => (string) ($route['carrier'] ?? ''),
            'endpoints' => (array) ($route['endpoints'] ?? []),
        ], $routes);
        return hash('sha256', json_encode([
            'mode' => $mode,
            'route_pool_id' => $routePoolId,
            'routes' => $normalized,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function routePoolId(string $code): string
    {
        return str_replace('_', '-', $code) . '-local';
    }

    private function upsertImUser(int $organization, string $userId, string $account, string $nickname, string $shortNo, string $now): void
    {
        $data = [
            'password_hash' => password_hash(self::IM_PASSWORD, PASSWORD_DEFAULT),
            'nickname' => $nickname,
            'status' => 1,
            'is_system' => 2,
            'remark' => self::MARKER,
            'delete_time' => null,
            'update_time' => $now,
        ];
        $existing = Db::table('im_user')->where('organization', $organization)->where('account', $account)->find();
        $identityOwner = Db::table('im_user')
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->find();
        if ($existing) {
            if (!hash_equals(self::MARKER, (string) ($existing['remark'] ?? ''))) {
                throw new RuntimeException("QA IM account {$account} is owned by non-QA data.");
            }
            if (!hash_equals($userId, (string) $existing['user_id'])) {
                throw new RuntimeException("QA IM account {$account} has an unexpected user identity; cleanup is required.");
            }
            if ($identityOwner && (int) $identityOwner['id'] !== (int) $existing['id']) {
                throw new RuntimeException("QA IM user identity {$userId} is owned by another account.");
            }
            Db::table('im_user')->where('id', (int) $existing['id'])->update($data + ['im_short_no' => $shortNo]);
        } else {
            if ($identityOwner) {
                throw new RuntimeException("QA IM user identity {$userId} is already in use.");
            }
            Db::table('im_user')->insert($data + [
                'organization' => $organization,
                'user_id' => $userId,
                'im_short_no' => $shortNo,
                'account' => $account,
                'create_time' => $now,
            ]);
        }
        $profile = ['signature' => self::MARKER, 'status' => 1, 'delete_time' => null, 'update_time' => $now];
        $existingProfile = Db::table('im_user_profile')->where('organization', $organization)->where('user_id', $userId)->find();
        if ($existingProfile) {
            Db::table('im_user_profile')->where('id', (int) $existingProfile['id'])->update($profile);
        } else {
            Db::table('im_user_profile')->insert($profile + ['organization' => $organization, 'user_id' => $userId, 'create_time' => $now]);
        }
        Db::execute(
            'INSERT INTO im_user_privacy_setting
                (organization, user_id, allow_add_by_mobile, allow_add_by_short_no, allow_add_by_username, create_time, update_time)
             VALUES (?, ?, 1, 1, 1, ?, ?)
             ON DUPLICATE KEY UPDATE allow_add_by_mobile = 1, allow_add_by_short_no = 1,
                allow_add_by_username = 1, update_time = VALUES(update_time)',
            [$organization, $userId, $now, $now],
        );
        $groupAccessState = Db::table('im_user_group_access_state')
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->lock(true)
            ->find();
        if (!$groupAccessState) {
            $accessSnapshotId = $existing
                ? $this->recoverQaGroupAccessSnapshot($organization, $userId)
                : '1';
            Db::table('im_user_group_access_state')->insert([
                'organization' => $organization,
                'user_id' => $userId,
                'access_snapshot_id' => $accessSnapshotId,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } elseif (preg_match('/^[1-9][0-9]*$/', (string) ($groupAccessState['access_snapshot_id'] ?? '')) !== 1) {
            throw new RuntimeException("QA IM group access state is invalid for {$account}.");
        }
    }

    private function recoverQaGroupAccessSnapshot(int $organization, string $userId): string
    {
        $audit = Db::query(
            'SELECT MAX(access_snapshot_id) AS access_snapshot_id
               FROM im_group_member_access_audit
              WHERE member_organization = ? AND BINARY user_id = BINARY ?',
            [$organization, $userId],
        )[0] ?? [];
        $snapshot = (string) ($audit['access_snapshot_id'] ?? '');
        if ($snapshot !== '') {
            if (preg_match('/^[1-9][0-9]*$/', $snapshot) !== 1) {
                throw new RuntimeException('QA IM group access audit snapshot is invalid.');
            }
            return $snapshot;
        }

        $groupMemberships = Db::query(
            'SELECT COUNT(*) AS aggregate
               FROM im_conversation_member member
          LEFT JOIN im_conversation conversation
                 ON conversation.organization = member.organization
                AND BINARY conversation.conversation_id = BINARY member.conversation_id
              WHERE member.member_organization = ?
                AND BINARY member.user_id = BINARY ?
                AND (
                    conversation.id IS NULL
                    OR conversation.conversation_type = 2
                )',
            [$organization, $userId],
        )[0]['aggregate'] ?? 0;
        $groupPeriods = Db::query(
            'SELECT COUNT(*) AS aggregate
               FROM im_conversation_membership_period period
          LEFT JOIN im_conversation conversation
                 ON conversation.organization = period.organization
                AND BINARY conversation.conversation_id = BINARY period.conversation_id
              WHERE period.member_organization = ?
                AND BINARY period.user_id = BINARY ?
                AND (
                    conversation.id IS NULL
                    OR conversation.conversation_type = 2
                )',
            [$organization, $userId],
        )[0]['aggregate'] ?? 0;
        if ((int) $groupMemberships !== 0 || (int) $groupPeriods !== 0) {
            throw new RuntimeException('QA IM group access state cannot be recovered without immutable audit.');
        }

        return '1';
    }

    private function upsertFriendship(int $organization, string $userA, string $userB, string $now): void
    {
        foreach ([[$userA, $userB], [$userB, $userA]] as [$userId, $friendUserId]) {
            Db::execute(
                'INSERT INTO im_friend_relation
                    (organization, user_id, friend_organization, friend_user_id, add_method, added_at,
                     status, create_time, update_time, delete_time)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, NULL)
                 ON DUPLICATE KEY UPDATE friend_organization = VALUES(friend_organization),
                    add_method = VALUES(add_method), added_at = VALUES(added_at),
                    status = 1, update_time = VALUES(update_time), delete_time = NULL',
                [$organization, $userId, $organization, $friendUserId, 'auto', $now, $now, $now],
            );
        }
    }

    /** @return list<string> */
    private function organizationTables(): array
    {
        $database = $this->databaseName();
        $rows = Db::query(
            'SELECT TABLE_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ? AND TABLE_NAME <> ? ORDER BY TABLE_NAME',
            [$database, 'organization', 'sm_system_organization'],
        );
        return array_values(array_unique(array_map(static fn (array $row): string => (string) $row['TABLE_NAME'], $rows)));
    }

    /** @return list<int> */
    private function idsFor(string $table, array $organizationIds): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }
        return array_map('intval', Db::table($table)->whereIn('organization', $organizationIds)->column('id'));
    }

    private function tableExists(string $table): bool
    {
        $database = $this->databaseName();
        return Db::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->count() === 1;
    }

    private function databaseName(): string
    {
        $row = Db::query('SELECT DATABASE() AS database_name')[0] ?? [];
        $database = (string) ($row['database_name'] ?? '');
        if ($database !== 'nb8im') {
            throw new RuntimeException("QA fixtures only accept the nb8im development database; got {$database}");
        }
        return $database;
    }
}
