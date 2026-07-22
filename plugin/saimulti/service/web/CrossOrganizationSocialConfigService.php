<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\trace\Telemetry;
use support\think\Db;

final class CrossOrganizationSocialConfigService
{
    /** @param array<int|string, mixed> $config */
    public function batchUpdate(int $groupId, array $config): void
    {
        $mutations = [];
        foreach ($config as $value) {
            if (!is_array($value)) {
                throw new ApiException('配置项格式无效。', 422);
            }
            $mutations[] = $value;
        }
        $this->mutate($groupId, $mutations);
    }

    /** @param array<string, mixed> $data */
    public function edit(int $id, array $data): void
    {
        $groupId = (int) ($data['group_id'] ?? 0);
        if ($id <= 0 || $groupId <= 0) {
            throw new ApiException('配置项格式无效。', 422);
        }
        $this->mutate($groupId, [[...$data, 'id' => $id]]);
    }

    /**
     * Acquire the managed-config lock before an organization row is locked.
     * This keeps organization availability transitions serialized with a
     * concurrent global cross-organization switch change.
     */
    public function lockOrganizationAvailabilityTransitionInsideTransaction(): void
    {
        $this->lockedPolicyState();
    }

    /**
     * Advance the global access snapshot only when one or more persisted edges
     * actually change availability because an organization became active or
     * inactive. The caller must invoke this inside the organization mutation
     * transaction after persisting the new status/delete_time.
     *
     * @param array<int,bool> $beforeActive organization => active before mutation
     */
    public function transitionOrganizationAvailabilityInsideTransaction(
        array $beforeActive,
        string $now,
    ): ?string {
        $normalizedBefore = [];
        foreach ($beforeActive as $organization => $active) {
            $organization = (int) $organization;
            if ($organization <= 0) {
                throw new \InvalidArgumentException('organization must be positive');
            }
            $normalizedBefore[$organization] = (bool) $active;
        }
        if ($normalizedBefore === []) {
            return null;
        }

        $policy = $this->lockedPolicyState();
        if (!$policy['enabled']) {
            return null;
        }
        $edges = array_filter(
            $this->collectAccessEdges(),
            static fn (array $edge): bool =>
                array_key_exists((int) $edge['target_organization'], $normalizedBefore)
                || array_key_exists((int) $edge['peer_organization'], $normalizedBefore),
        );
        if ($edges === []) {
            return null;
        }

        $organizations = [];
        foreach ($edges as $edge) {
            $organizations[(int) $edge['target_organization']] = true;
            $organizations[(int) $edge['peer_organization']] = true;
        }
        $organizationIds = array_keys($organizations);
        sort($organizationIds, SORT_NUMERIC);
        $placeholders = implode(',', array_fill(0, count($organizationIds), '?'));
        $activeOrganizationRows = Db::query(
            'SELECT id
               FROM sm_system_organization
              WHERE id IN (' . $placeholders . ')
                AND status = 1
                AND delete_time IS NULL',
            $organizationIds,
        );
        $afterActive = [];
        foreach ($organizationIds as $organization) {
            $afterActive[$organization] = false;
        }
        foreach ($activeOrganizationRows as $row) {
            $afterActive[(int) $row['id']] = true;
        }
        $activeIdentities = $this->activeIdentityMap($edges);

        $changedEdges = [];
        foreach ($edges as $key => $edge) {
            $targetOrganization = (int) $edge['target_organization'];
            $peerOrganization = (int) $edge['peer_organization'];
            $targetIdentity = SingleConversationIdentity::identity(
                $targetOrganization,
                (string) $edge['target_user_id'],
            );
            $peerIdentity = SingleConversationIdentity::identity(
                $peerOrganization,
                (string) $edge['peer_user_id'],
            );
            $usersActive = isset($activeIdentities[$targetIdentity], $activeIdentities[$peerIdentity]);
            $targetBefore = $normalizedBefore[$targetOrganization]
                ?? $afterActive[$targetOrganization]
                ?? false;
            $peerBefore = $normalizedBefore[$peerOrganization]
                ?? $afterActive[$peerOrganization]
                ?? false;
            $beforeAllowed = $usersActive && $targetBefore && $peerBefore;
            $afterAllowed = $usersActive
                && ($afterActive[$targetOrganization] ?? false)
                && ($afterActive[$peerOrganization] ?? false);
            if ($beforeAllowed === $afterAllowed) {
                continue;
            }
            $edge['allowed'] = $afterAllowed;
            $changedEdges[$key] = $edge;
        }
        if ($changedEdges === []) {
            return null;
        }

        $snapshotId = self::incrementDecimal($policy['snapshot_id']);
        $updated = Db::execute(
            'UPDATE sm_system_config
                SET `value` = ?, update_time = ?
              WHERE id = ? AND group_id = ? AND delete_time IS NULL',
            [
                $snapshotId,
                $now,
                $policy['snapshot_row_id'],
                $policy['group_id'],
            ],
        );
        if ($updated !== 1) {
            throw new \RuntimeException('Cross-organization access snapshot update failed.');
        }
        $this->appendAccessChangedEdges($snapshotId, $changedEdges, $now);

        return $snapshotId;
    }

    /** @param list<array<string, mixed>> $mutations */
    private function mutate(int $groupId, array $mutations): void
    {
        Db::transaction(function () use ($groupId, $mutations): void {
            $group = Db::query(
                'SELECT id, code
                   FROM sm_system_config_group
                  WHERE id = ? AND delete_time IS NULL
                  LIMIT 1
                  FOR UPDATE',
                [$groupId],
            )[0] ?? null;
            if ($group === null || (string) $group['code'] !== CrossOrganizationSocialPolicy::CONFIG_GROUP) {
                throw new ApiException('社交边界配置组未找到。', 404);
            }
            $rows = Db::query(
                'SELECT *
                   FROM sm_system_config
                  WHERE group_id = ? AND delete_time IS NULL
                  FOR UPDATE',
                [$groupId],
            );
            $byId = [];
            $byKey = [];
            foreach ($rows as $row) {
                $byId[(int) $row['id']] = $row;
                $key = (string) $row['key'];
                if (isset($byKey[$key])) {
                    throw new ApiException('配置组存在重复配置标识。', 409);
                }
                $byKey[$key] = $row;
            }
            $switch = $byKey[CrossOrganizationSocialPolicy::CONFIG_KEY] ?? null;
            if ($switch === null) {
                throw new ApiException('跨租户社交开关不存在。', 409);
            }
            $snapshot = $byKey[CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY] ?? null;
            $rawSnapshot = trim((string) ($snapshot['value'] ?? ''));
            $snapshotValid = preg_match('/^[1-9][0-9]*$/', $rawSnapshot) === 1
                && strlen($rawSnapshot) <= 20;
            $currentEnabled = $snapshotValid
                && CrossOrganizationSocialPolicy::truthy($switch['value'] ?? '0');
            $requestedEnabled = $currentEnabled;
            $now = date('Y-m-d H:i:s');
            $seenMutationIds = [];

            foreach ($mutations as $mutation) {
                $id = (int) ($mutation['id'] ?? 0);
                if ($id <= 0 || isset($seenMutationIds[$id])) {
                    throw new ApiException('配置项编号重复或无效。', 422);
                }
                $seenMutationIds[$id] = true;
                $existing = $byId[$id] ?? null;
                if ($existing === null) {
                    throw new ApiException('配置项不存在或不属于当前配置组。', 404);
                }
                if (array_key_exists('group_id', $mutation)
                    && (int) $mutation['group_id'] !== $groupId) {
                    throw new ApiException('配置项禁止跨配置组移动。', 422);
                }
                $resultKey = trim((string) ($mutation['key'] ?? $existing['key']));
                if ($resultKey === '') {
                    throw new ApiException('配置标识不能为空。', 422);
                }
                $existingKey = (string) $existing['key'];
                $managedKeys = [
                    CrossOrganizationSocialPolicy::CONFIG_KEY,
                    CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY,
                ];
                if (
                    (in_array($existingKey, $managedKeys, true) && $resultKey !== $existingKey)
                    || (in_array($resultKey, $managedKeys, true) && $resultKey !== $existingKey)
                ) {
                    throw new ApiException('跨租户社交系统配置标识禁止修改。', 422);
                }
                if (in_array($existingKey, $managedKeys, true)) {
                    $this->assertManagedMutation($existing, $mutation);
                    if ($existingKey === CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY) {
                        continue;
                    }
                    if (array_key_exists('value', $mutation)) {
                        $switchValue = trim((string) $mutation['value']);
                        if (!in_array($switchValue, ['0', '1'], true)) {
                            throw new ApiException('跨租户社交开关值只能是 0 或 1。', 422);
                        }
                        $requestedEnabled = $switchValue === '1';
                        Db::execute(
                            'UPDATE sm_system_config
                                SET `value` = ?, updated_by = ?, update_time = ?
                              WHERE id = ? AND group_id = ? AND delete_time IS NULL',
                            [
                                $switchValue,
                                $mutation['updated_by'] ?? $existing['updated_by'] ?? null,
                                $now,
                                $id,
                                $groupId,
                            ],
                        );
                    }
                    continue;
                }
                $assignments = [];
                $params = [];
                foreach ([
                    'name',
                    'key',
                    'value',
                    'input_type',
                    'config_select_data',
                    'sort',
                    'remark',
                    'updated_by',
                ] as $field) {
                    if (!array_key_exists($field, $mutation)) {
                        continue;
                    }
                    $assignments[] = '`' . $field . '` = ?';
                    $params[] = $mutation[$field];
                }
                if ($assignments !== []) {
                    $assignments[] = '`update_time` = ?';
                    $params[] = $now;
                    $params[] = $id;
                    $params[] = $groupId;
                    Db::execute(
                        'UPDATE sm_system_config
                            SET ' . implode(', ', $assignments) . '
                          WHERE id = ? AND group_id = ? AND delete_time IS NULL',
                        $params,
                    );
                }
            }

            if ($snapshot === null) {
                Db::execute(
                    'INSERT INTO sm_system_config
                        (group_id, `key`, `value`, name, input_type, sort, remark,
                         created_by, updated_by, create_time, update_time)
                     VALUES (?, ?, "0", "跨租户社交访问快照序号", "hidden", 99,
                             "系统管理的十进制单调序号；禁止人工修改。", 1, 1, ?, ?)',
                    [
                        $groupId,
                        CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY,
                        $now,
                        $now,
                    ],
                );
                $snapshotId = '0';
                $createdSnapshot = Db::query(
                    'SELECT id
                       FROM sm_system_config
                      WHERE group_id = ? AND `key` = ? AND delete_time IS NULL
                      LIMIT 1
                      FOR UPDATE',
                    [$groupId, CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY],
                )[0] ?? null;
                if ($createdSnapshot === null) {
                    throw new \RuntimeException('Cross-organization access snapshot config creation failed.');
                }
                $snapshotRowId = (int) $createdSnapshot['id'];
            } else {
                $snapshotId = $snapshotValid ? $rawSnapshot : '0';
                $snapshotRowId = (int) $snapshot['id'];
                if (!$snapshotValid) {
                    Db::execute(
                        'UPDATE sm_system_config
                            SET `value` = "0", update_time = ?
                          WHERE id = ? AND group_id = ?',
                        [$now, $snapshotRowId, $groupId],
                    );
                }
            }

            if ($requestedEnabled === $currentEnabled) {
                return;
            }
            $snapshotId = self::incrementDecimal($snapshotId);
            $updated = Db::execute(
                'UPDATE sm_system_config
                    SET `value` = ?, update_time = ?
                  WHERE id = ? AND group_id = ? AND delete_time IS NULL',
                [$snapshotId, $now, $snapshotRowId, $groupId],
            );
            if ($updated !== 1) {
                throw new \RuntimeException('Cross-organization access snapshot update failed.');
            }
            $this->appendAccessChangedOutboxes($snapshotId, $requestedEnabled, $now);
        });
        CrossOrganizationSocialPolicy::clearCache();
    }

    private function appendAccessChangedOutboxes(
        string $snapshotId,
        bool $accessAllowed,
        string $now,
    ): void {
        $edges = $this->collectAccessEdges();
        $currentlyAvailable = $accessAllowed
            ? $this->currentlyAvailableAccessEdges($edges)
            : [];
        foreach ($edges as $key => &$edge) {
            $edge['allowed'] = $accessAllowed && isset($currentlyAvailable[$key]);
        }
        unset($edge);
        $this->appendAccessChangedEdges($snapshotId, $edges, $now);
    }

    /**
     * @param array<string,array{
     *   target_organization:int,
     *   target_user_id:string,
     *   peer_organization:int,
     *   peer_user_id:string,
     *   conversation_id:string
     * }> $edges
     * @return array<string,array{
     *   target_organization:int,
     *   target_user_id:string,
     *   peer_organization:int,
     *   peer_user_id:string,
     *   conversation_id:string
     * }>
     */
    private function currentlyAvailableAccessEdges(array $edges): array
    {
        if ($edges === []) {
            return [];
        }
        $organizationIds = [];
        foreach ($edges as $edge) {
            $organizationIds[(int) $edge['target_organization']] = true;
            $organizationIds[(int) $edge['peer_organization']] = true;
        }
        $ids = array_keys($organizationIds);
        sort($ids, SORT_NUMERIC);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $activeOrganizationRows = Db::query(
            'SELECT id
               FROM sm_system_organization
              WHERE id IN (' . $placeholders . ')
                AND status = 1
                AND delete_time IS NULL',
            $ids,
        );
        $activeOrganizations = [];
        foreach ($activeOrganizationRows as $row) {
            $activeOrganizations[(int) $row['id']] = true;
        }
        $activeIdentities = $this->activeIdentityMap($edges);

        return array_filter(
            $edges,
            static function (array $edge) use ($activeOrganizations, $activeIdentities): bool {
                $targetOrganization = (int) $edge['target_organization'];
                $peerOrganization = (int) $edge['peer_organization'];

                return isset(
                    $activeOrganizations[$targetOrganization],
                    $activeOrganizations[$peerOrganization],
                    $activeIdentities[SingleConversationIdentity::identity(
                        $targetOrganization,
                        (string) $edge['target_user_id'],
                    )],
                    $activeIdentities[SingleConversationIdentity::identity(
                        $peerOrganization,
                        (string) $edge['peer_user_id'],
                    )],
                );
            },
        );
    }

    /**
     * @return array<string,array{
     *   target_organization:int,
     *   target_user_id:string,
     *   peer_organization:int,
     *   peer_user_id:string,
     *   conversation_id:string
     * }>
     */
    private function collectAccessEdges(): array
    {
        $edges = [];
        $relations = Db::query(
            'SELECT organization AS target_organization, user_id AS target_user_id,
                    friend_organization AS peer_organization, friend_user_id AS peer_user_id
               FROM im_friend_relation
              WHERE organization <> friend_organization
                AND status = 1
                AND delete_time IS NULL
              FOR UPDATE',
        );
        foreach ($relations as $relation) {
            self::addAccessEdge($edges, $relation);
        }
        $requests = Db::query(
            'SELECT from_organization, from_user_id, to_organization, to_user_id
               FROM im_friend_request
              WHERE from_organization <> to_organization
                AND status IN (1, 2, 3)
                AND delete_time IS NULL
              FOR UPDATE',
        );
        foreach ($requests as $request) {
            self::addAccessEdge($edges, [
                'target_organization' => $request['from_organization'],
                'target_user_id' => $request['from_user_id'],
                'peer_organization' => $request['to_organization'],
                'peer_user_id' => $request['to_user_id'],
            ]);
            self::addAccessEdge($edges, [
                'target_organization' => $request['to_organization'],
                'target_user_id' => $request['to_user_id'],
                'peer_organization' => $request['from_organization'],
                'peer_user_id' => $request['from_user_id'],
            ]);
        }
        $canonicalConversations = Db::query(
            'SELECT left_organization, left_user_id, right_organization, right_user_id
               FROM im_cross_organization_conversation
              WHERE status = 1
              FOR UPDATE',
        );
        foreach ($canonicalConversations as $conversation) {
            self::addAccessEdge($edges, [
                'target_organization' => $conversation['left_organization'],
                'target_user_id' => $conversation['left_user_id'],
                'peer_organization' => $conversation['right_organization'],
                'peer_user_id' => $conversation['right_user_id'],
            ]);
            self::addAccessEdge($edges, [
                'target_organization' => $conversation['right_organization'],
                'target_user_id' => $conversation['right_user_id'],
                'peer_organization' => $conversation['left_organization'],
                'peer_user_id' => $conversation['left_user_id'],
            ]);
        }
        ksort($edges, SORT_STRING);

        return $edges;
    }

    /**
     * @param array<string,array{
     *   target_organization:int,
     *   target_user_id:string,
     *   peer_organization:int,
     *   peer_user_id:string,
     *   conversation_id:string,
     *   allowed:bool
     * }> $edges
     */
    private function appendAccessChangedEdges(
        string $snapshotId,
        array $edges,
        string $now,
    ): void {
        ksort($edges, SORT_STRING);
        $trace = Telemetry::currentTraceHeaders();
        foreach ($edges as $edge) {
            $homeOrganization = $edge['target_organization'];
            $conversationId = $edge['conversation_id'];
            $recipientIdentities = [[
                'organization' => $homeOrganization,
                'user_id' => $edge['target_user_id'],
            ]];
            $eventId = hash('sha256', implode('|', [
                $homeOrganization,
                'conversation.access_changed',
                $conversationId,
                $snapshotId,
            ]));
            $payload = [
                'event_id' => $eventId,
                'event_type' => 'conversation.access_changed',
                'organization' => $homeOrganization,
                'conversation_id' => $conversationId,
                'conversation_type' => 1,
                'cross_org_access_snapshot_id' => $snapshotId,
                'allowed' => (bool) $edge['allowed'],
                'target_organization' => $homeOrganization,
                'target_user_id' => $edge['target_user_id'],
                'peer_organization' => $edge['peer_organization'],
                'peer_user_id' => $edge['peer_user_id'],
                'recipient_count' => 1,
                'recipient_identities' => $recipientIdentities,
                'created_at' => $now,
            ];
            $outboxMessageId = sha1(
                'conversation.access_changed|' . $conversationId . '|' . $snapshotId,
            );
            Db::execute(
                'INSERT INTO im_message_outbox
                    (event_id, organization, event_type, routing_key, message_id, change_seq,
                     conversation_id, conversation_type, payload_json, traceparent, tracestate,
                     status, retry_count, next_retry_at, create_time, update_time)
                 VALUES (?, ?, "conversation.access_changed", "conversation.access_changed",
                         ?, 0, ?, 1, ?, ?, ?, 1, 0, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE event_id = VALUES(event_id)',
                [
                    $eventId,
                    $homeOrganization,
                    $outboxMessageId,
                    $conversationId,
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $trace['traceparent'] ?? null,
                    $trace['tracestate'] ?? null,
                    $now,
                    $now,
                    $now,
                ],
            );
        }
    }

    /**
     * @param array<string,array{
     *   target_organization:int,
     *   target_user_id:string,
     *   peer_organization:int,
     *   peer_user_id:string,
     *   conversation_id:string
     * }> $edges
     * @return array<string,true>
     */
    private function activeIdentityMap(array $edges): array
    {
        $identities = [];
        foreach ($edges as $edge) {
            foreach ([
                [(int) $edge['target_organization'], (string) $edge['target_user_id']],
                [(int) $edge['peer_organization'], (string) $edge['peer_user_id']],
            ] as [$organization, $userId]) {
                $identities[SingleConversationIdentity::identity($organization, $userId)] = [
                    'organization' => $organization,
                    'user_id' => $userId,
                ];
            }
        }

        $active = [];
        foreach (array_chunk(array_values($identities), 200) as $chunk) {
            $predicates = [];
            $params = [];
            foreach ($chunk as $identity) {
                $predicates[] = '(organization = ? AND user_id = ?)';
                $params[] = $identity['organization'];
                $params[] = $identity['user_id'];
            }
            $rows = Db::query(
                'SELECT organization, user_id
                   FROM im_user
                  WHERE status = 1
                    AND delete_time IS NULL
                    AND (' . implode(' OR ', $predicates) . ')',
                $params,
            );
            foreach ($rows as $row) {
                $active[SingleConversationIdentity::identity(
                    (int) $row['organization'],
                    (string) $row['user_id'],
                )] = true;
            }
        }

        return $active;
    }

    /**
     * @return array{
     *   group_id:int,
     *   snapshot_row_id:int,
     *   snapshot_id:string,
     *   enabled:bool
     * }
     */
    private function lockedPolicyState(): array
    {
        $groups = Db::query(
            'SELECT id
               FROM sm_system_config_group
              WHERE code = ? AND delete_time IS NULL
              FOR UPDATE',
            [CrossOrganizationSocialPolicy::CONFIG_GROUP],
        );
        if (count($groups) !== 1) {
            throw new ApiException('社交边界配置组不存在或不唯一。', 409);
        }
        $groupId = (int) $groups[0]['id'];
        $rows = Db::query(
            'SELECT id, `key`, `value`
               FROM sm_system_config
              WHERE group_id = ?
                AND `key` IN (?, ?)
                AND delete_time IS NULL
              FOR UPDATE',
            [
                $groupId,
                CrossOrganizationSocialPolicy::CONFIG_KEY,
                CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY,
            ],
        );
        $byKey = [];
        foreach ($rows as $row) {
            $key = (string) $row['key'];
            if (isset($byKey[$key])) {
                throw new ApiException('跨租户社交系统配置存在重复标识。', 409);
            }
            $byKey[$key] = $row;
        }
        $switch = $byKey[CrossOrganizationSocialPolicy::CONFIG_KEY] ?? null;
        $snapshot = $byKey[CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY] ?? null;
        if ($switch === null || $snapshot === null) {
            throw new ApiException('跨租户社交系统配置不完整。', 409);
        }
        $snapshotId = trim((string) ($snapshot['value'] ?? ''));
        if (preg_match('/^(0|[1-9][0-9]*)$/', $snapshotId) !== 1 || strlen($snapshotId) > 20) {
            throw new ApiException('跨租户社交访问快照无效。', 409);
        }

        return [
            'group_id' => $groupId,
            'snapshot_row_id' => (int) $snapshot['id'],
            'snapshot_id' => $snapshotId,
            'enabled' => $snapshotId !== '0'
                && CrossOrganizationSocialPolicy::truthy($switch['value'] ?? '0'),
        ];
    }

    /**
     * @param array<string, array{
     *   target_organization:int,
     *   target_user_id:string,
     *   peer_organization:int,
     *   peer_user_id:string,
     *   conversation_id:string
     * }> $edges
     * @param array<string, mixed> $row
     */
    private static function addAccessEdge(array &$edges, array $row): void
    {
        $targetOrganization = (int) ($row['target_organization'] ?? 0);
        $targetUserId = trim((string) ($row['target_user_id'] ?? ''));
        $peerOrganization = (int) ($row['peer_organization'] ?? 0);
        $peerUserId = trim((string) ($row['peer_user_id'] ?? ''));
        if (
            $targetOrganization <= 0
            || $targetUserId === ''
            || $peerOrganization <= 0
            || $peerUserId === ''
            || $targetOrganization === $peerOrganization
        ) {
            throw new \RuntimeException('Cross-organization access edge identity is invalid.');
        }
        $targetIdentity = SingleConversationIdentity::identity($targetOrganization, $targetUserId);
        $peerIdentity = SingleConversationIdentity::identity($peerOrganization, $peerUserId);
        $edges[$targetIdentity . '|' . $peerIdentity] = [
            'target_organization' => $targetOrganization,
            'target_user_id' => $targetUserId,
            'peer_organization' => $peerOrganization,
            'peer_user_id' => $peerUserId,
            'conversation_id' => SingleConversationIdentity::conversationId(
                $targetOrganization,
                $targetUserId,
                $peerOrganization,
                $peerUserId,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $mutation
     */
    private function assertManagedMutation(array $existing, array $mutation): void
    {
        $existingKey = (string) $existing['key'];
        foreach ([
            'name',
            'key',
            'input_type',
            'config_select_data',
            'sort',
            'remark',
        ] as $field) {
            if (!array_key_exists($field, $mutation) || !array_key_exists($field, $existing)) {
                continue;
            }
            if ($this->managedComparable($field, $mutation[$field])
                !== $this->managedComparable($field, $existing[$field])) {
                throw new ApiException('跨租户社交系统配置元数据禁止修改。', 422);
            }
        }
        if ($existingKey === CrossOrganizationSocialPolicy::SNAPSHOT_CONFIG_KEY
            && array_key_exists('value', $mutation)
            && trim((string) $mutation['value']) !== trim((string) ($existing['value'] ?? ''))) {
            throw new ApiException('跨租户社交访问快照禁止人工修改。', 422);
        }
    }

    private function managedComparable(string $field, mixed $value): string
    {
        if ($field === 'sort') {
            return (string) (int) $value;
        }
        if ($field === 'config_select_data') {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : $value;
            }
            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        }

        return trim((string) ($value ?? ''));
    }

    private static function incrementDecimal(string $value): string
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            $value = '0';
        }
        $digits = str_split($value);
        $carry = 1;
        for ($index = count($digits) - 1; $index >= 0 && $carry === 1; $index--) {
            $digit = ((int) $digits[$index]) + 1;
            $digits[$index] = (string) ($digit % 10);
            $carry = $digit >= 10 ? 1 : 0;
        }
        if ($carry === 1) {
            array_unshift($digits, '1');
        }
        $incremented = implode('', $digits);
        if (strlen($incremented) > 20) {
            throw new \OverflowException(
                'Cross-organization access snapshot exceeds the 20-digit storage contract.',
            );
        }

        return $incremented;
    }
}
