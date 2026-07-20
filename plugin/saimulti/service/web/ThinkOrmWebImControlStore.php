<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use OpenTelemetry\API\Trace\Span;
use plugin\saimulti\service\trace\Telemetry;
use support\think\Db;

final class ThinkOrmWebImControlStore implements WebImControlStoreInterface
{
    private const CONVERSATION_SINGLE = 1;
    private const CONVERSATION_GROUP = 2;

    public function conversations(int $organization, string $userId): array
    {
        $rows = Db::query(
            'SELECT c.*, gp.description, cm.unread_count, cm.is_pinned, cm.is_muted,
                    cm.access_version, cm.access_state,
                    cm.conversation_remark, cm.message_group_id,
                    mg.name AS message_group_name, mi.id AS last_message_index_id,
                    COALESCE(c.last_message_time, c.create_time) AS sort_time
               FROM im_conversation_member cm
         INNER JOIN im_conversation c
                 ON c.organization = cm.organization
                AND BINARY c.conversation_id = BINARY cm.conversation_id
          LEFT JOIN im_group_profile gp
                 ON gp.organization = c.organization
                AND BINARY gp.conversation_id = BINARY c.conversation_id
                AND gp.status = 1
                AND gp.delete_time IS NULL
          LEFT JOIN im_message_group mg
                 ON mg.organization = cm.organization
                AND BINARY mg.user_id = BINARY cm.user_id
                AND mg.id = cm.message_group_id
                AND mg.status = 1
                AND mg.delete_time IS NULL
          LEFT JOIN im_message_index mi
                 ON mi.organization = c.organization
                AND BINARY mi.message_id = BINARY c.last_message_id
              WHERE cm.organization = ?
                AND cm.member_organization = ?
                AND BINARY cm.user_id = BINARY ?
                AND cm.delete_time IS NULL
                AND ((c.conversation_type = 2 AND cm.access_state IN ("active", "history_only"))
                     OR (c.conversation_type <> 2 AND cm.status = 1))
                AND c.status = 1
                AND c.delete_time IS NULL
           ORDER BY cm.is_pinned ASC,
                    CASE WHEN c.last_message_time IS NULL THEN 1 ELSE 0 END ASC,
                    c.last_message_time DESC,
                    COALESCE(mi.id, 0) DESC,
                    c.create_time DESC,
                    c.id DESC',
            [$organization, $organization, $userId],
        );

        $items = [];
        foreach ($rows as $row) {
            $crossOrganization = $this->isCrossOrganizationConversation(
                (string) $row['conversation_id'],
            );
            if ($crossOrganization && !CrossOrganizationSocialPolicy::isEnabled()) {
                continue;
            }
            try {
                $items[] = $this->formatConversation($organization, $userId, $row);
            } catch (ApiException $exception) {
                // A peer organization becoming inactive revokes only that
                // cross-organization projection; unrelated conversations stay
                // listable. Structural corruption still throws below.
                if (!$crossOrganization || $exception->getCode() !== 403) {
                    throw $exception;
                }
            }
        }
        usort($items, static function (array $left, array $right): int {
            $pinned = (int) $right['is_pinned'] <=> (int) $left['is_pinned'];
            if ($pinned !== 0) {
                return $pinned;
            }
            $time = strcmp((string) $right['sort_time'], (string) $left['sort_time']);

            return $time !== 0
                ? $time
                : (int) $right['conversation_sort_id'] <=> (int) $left['conversation_sort_id'];
        });

        return $items;
    }

    public function messageGroups(int $organization, string $userId): array
    {
        $rows = Db::query(
            'SELECT id, name, sort
               FROM im_message_group
              WHERE organization = ?
                AND user_id = ?
                AND status = 1
                AND delete_time IS NULL
           ORDER BY sort ASC, id ASC',
            [$organization, $userId],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'sort' => (int) $row['sort'],
        ], $rows);
    }

    public function createMessageGroup(int $organization, string $userId, string $name, string $now): array
    {
        return Db::transaction(function () use ($organization, $userId, $name, $now): array {
            $existing = Db::query(
                'SELECT id, name, sort, status, delete_time
                   FROM im_message_group
                  WHERE organization = ? AND user_id = ? AND name = ?
                  LIMIT 1
                  FOR UPDATE',
                [$organization, $userId, $name],
            )[0] ?? null;
            if ($existing !== null) {
                if ((int) $existing['status'] !== 1 || $existing['delete_time'] !== null) {
                    Db::execute(
                        'UPDATE im_message_group
                            SET status = 1, delete_time = NULL, update_time = ?
                          WHERE id = ? AND organization = ? AND user_id = ?',
                        [$now, (int) $existing['id'], $organization, $userId],
                    );
                }

                return [
                    'id' => (int) $existing['id'],
                    'name' => (string) $existing['name'],
                    'sort' => (int) $existing['sort'],
                ];
            }

            Db::query(
                'SELECT id FROM im_message_group
                  WHERE organization = ? AND user_id = ?
                  FOR UPDATE',
                [$organization, $userId],
            );
            $maxSort = Db::query(
                'SELECT COALESCE(MAX(sort), 0) AS max_sort
                   FROM im_message_group
                  WHERE organization = ? AND user_id = ?',
                [$organization, $userId],
            )[0]['max_sort'] ?? 0;
            $sort = (int) $maxSort + 10;
            $id = Db::table('im_message_group')->insertGetId([
                'organization' => $organization,
                'user_id' => $userId,
                'name' => $name,
                'sort' => $sort,
                'status' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);

            return ['id' => (int) $id, 'name' => $name, 'sort' => $sort];
        });
    }

    public function updateConversationGroup(
        int $organization,
        string $userId,
        string $conversationId,
        int $messageGroupId,
        string $now,
    ): array {
        return Db::transaction(function () use (
            $organization,
            $userId,
            $conversationId,
            $messageGroupId,
            $now,
        ): array {
            $this->assertActiveConversationMember($organization, $conversationId, $userId, true);
            $name = '';
            if ($messageGroupId > 0) {
                $group = Db::query(
                    'SELECT id, name
                       FROM im_message_group
                      WHERE id = ?
                        AND organization = ?
                        AND user_id = ?
                        AND status = 1
                        AND delete_time IS NULL
                      LIMIT 1
                      FOR UPDATE',
                    [$messageGroupId, $organization, $userId],
                )[0] ?? null;
                if ($group === null) {
                    throw new ApiException('消息分组不存在。', 404);
                }
                $name = (string) $group['name'];
            }

            $affected = Db::execute(
                'UPDATE im_conversation_member
                    SET message_group_id = ?, update_time = ?
                  WHERE organization = ?
                    AND BINARY conversation_id = BINARY ?
                    AND member_organization = ?
                    AND BINARY user_id = BINARY ?
                    AND access_state = "active"
                    AND status = 1
                    AND delete_time IS NULL',
                [$messageGroupId, $now, $organization, $conversationId, $organization, $userId],
            );
            if ($affected > 1) {
                throw new \RuntimeException('Conversation group update affected multiple membership rows.');
            }

            return [
                'conversation_id' => $conversationId,
                'message_group_id' => $messageGroupId,
                'message_group_name' => $name,
            ];
        });
    }

    public function messageConfig(int $organization): array
    {
        $values = [];
        $group = Db::query(
            'SELECT id FROM sm_system_config_group
              WHERE code = ? AND delete_time IS NULL
              LIMIT 1',
            ['message_config'],
        )[0] ?? null;
        if ($group !== null) {
            foreach (Db::query(
                'SELECT `key`, `value` FROM sm_system_config
                  WHERE group_id = ? AND delete_time IS NULL',
                [(int) $group['id']],
            ) as $row) {
                $values[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
            $tenant = Db::query(
                'SELECT `value` FROM sm_tenant_config
                  WHERE organization = ? AND group_id = ? AND delete_time IS NULL
                  LIMIT 1',
                [$organization, (int) $group['id']],
            )[0] ?? null;
            $overrides = json_decode((string) ($tenant['value'] ?? '{}'), true);
            if (is_array($overrides)) {
                foreach ($overrides as $key => $value) {
                    if ($value !== '' && $value !== null) {
                        $values[(string) $key] = (string) $value;
                    }
                }
            }
        }

        return [
            'delete_single_enabled' => (string) ($values['message_delete_single_enabled'] ?? '1') === '1',
            'delete_both_enabled' => (string) ($values['message_delete_both_enabled'] ?? '2') === '1',
        ];
    }

    public function messages(
        int $organization,
        string $userId,
        string $conversationId,
        int $peerOrganization,
        string $peerUserId,
        int $afterSeq,
        int $beforeSeq,
        int $limit,
    ): array {
        if ($conversationId === '' && $peerUserId !== '') {
            if ($peerOrganization !== $organization && !CrossOrganizationSocialPolicy::isEnabled()) {
                throw new ApiException('跨租户单聊未开放。', 403);
            }
            if (!$this->areFriends($organization, $userId, $peerOrganization, $peerUserId)) {
                throw new ApiException('只能查看好友会话。', 403);
            }
            $conversationId = SingleConversationIdentity::conversationId(
                $organization,
                $userId,
                $peerOrganization,
                $peerUserId,
            );
            if (!$this->conversationExists($organization, $conversationId)) {
                return $this->emptyMessagePage($afterSeq, $beforeSeq);
            }
        }
        if ($conversationId === '') {
            return $this->emptyMessagePage($afterSeq, $beforeSeq);
        }
        $access = (new WebImConversationAccessGuard())->assertReadable($organization, $userId, $conversationId);
        $includeSenderUser = (bool) ($access['is_active'] ?? true);
        $this->assertMembershipPeriod($organization, $conversationId, $userId);

        $newer = $afterSeq > 0;
        $comparison = $newer ? '>' : ($beforeSeq > 0 ? '<' : '>=');
        $cursor = $newer ? $afterSeq : ($beforeSeq > 0 ? $beforeSeq : 0);
        $direction = $newer ? 'ASC' : 'DESC';
        $candidates = Db::query(
            'SELECT i.global_seq, i.message_id, i.message_seq, i.shard_table,
                    i.sender_id, i.sender_organization
               FROM im_message_index i
              WHERE i.organization = ?
                AND BINARY i.conversation_id = BINARY ?
                AND i.message_seq ' . $comparison . ' ?
                AND EXISTS (
                    SELECT 1
                      FROM im_conversation_membership_period mp
                     WHERE mp.organization = i.organization
                       AND BINARY mp.conversation_id = BINARY i.conversation_id
                       AND mp.member_organization = ?
                       AND BINARY mp.user_id = BINARY ?
                       AND mp.status = 1
                       AND i.message_seq >= mp.visible_from_message_seq
                       AND (mp.visible_until_message_seq IS NULL OR i.message_seq <= mp.visible_until_message_seq)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM im_message_user_delete ud
                     WHERE ud.organization = i.organization
                       AND BINARY ud.conversation_id = BINARY i.conversation_id
                       AND BINARY ud.message_id = BINARY i.message_id
                       AND ud.user_organization = ?
                       AND BINARY ud.user_id = BINARY ?
                )
           ORDER BY i.message_seq ' . $direction . '
              LIMIT ' . ($limit + 1),
            [$organization, $conversationId, $cursor, $organization, $userId, $organization, $userId],
        );
        $hasMore = count($candidates) > $limit;
        $page = array_slice($candidates, 0, $limit);
        $messages = $this->loadIndexedMessageBodies(
            $organization,
            $conversationId,
            $page,
            $userId,
            $includeSenderUser,
        );
        usort($messages, static fn (array $left, array $right): int =>
            (int) $left['message_seq'] <=> (int) $right['message_seq']);

        $scannedSequences = array_map(static fn (array $row): int => (int) $row['message_seq'], $page);
        $nextAfter = $afterSeq;
        $nextBefore = $beforeSeq;
        if ($scannedSequences !== []) {
            $nextAfter = max($afterSeq, max($scannedSequences));
            $nextBefore = min($scannedSequences);
        }

        return [
            'messages' => $messages,
            'next_after_seq' => $nextAfter,
            'next_before_seq' => $nextBefore,
            'has_more_before' => !$newer && $hasMore,
        ];
    }

    public function markRead(
        int $organization,
        string $userId,
        string $conversationId,
        bool $all,
        string $now,
    ): array {
        return Db::transaction(function () use ($organization, $userId, $conversationId, $all, $now): array {
            $policy = CrossOrganizationSocialPolicy::lockSharedInsideTransaction();
            if ($all) {
                $members = Db::query(
                    'SELECT cm.conversation_id
                       FROM im_conversation_member cm
                 INNER JOIN im_conversation c
                         ON c.organization = cm.organization
                        AND BINARY c.conversation_id = BINARY cm.conversation_id
                      WHERE cm.organization = ?
                        AND cm.member_organization = ?
                        AND BINARY cm.user_id = BINARY ?
                        AND cm.status = 1
                        AND cm.access_state = "active"
                        AND cm.delete_time IS NULL
                        AND c.status = 1
                        AND c.delete_time IS NULL
                   ORDER BY cm.conversation_id ASC',
                    [$organization, $organization, $userId],
                );
                if (!$policy['enabled']) {
                    $members = array_values(array_filter(
                        $members,
                        fn (array $member): bool => !$this->isCrossOrganizationConversation(
                            (string) $member['conversation_id'],
                        ),
                    ));
                }
                $updated = 0;
                foreach ($members as $member) {
                    $memberConversationId = (string) $member['conversation_id'];
                    try {
                        $updated += $this->markConversationRead(
                            $organization,
                            $userId,
                            $memberConversationId,
                            $now,
                        );
                    } catch (ApiException $exception) {
                        if ($exception->getCode() !== 403
                            || !$this->isCrossOrganizationConversation($memberConversationId)) {
                            throw $exception;
                        }
                    }
                }

                return [
                    'updated' => $updated,
                    'user_organization' => $organization,
                    'user_id' => $userId,
                ];
            }

            return [
                'updated' => $this->markConversationRead(
                    $organization,
                    $userId,
                    $conversationId,
                    $now,
                ),
                'user_organization' => $organization,
                'user_id' => $userId,
            ];
        });
    }

    public function contacts(int $organization, string $userId, string $keyword): array
    {
        $crossOrganizationEnabled = CrossOrganizationSocialPolicy::isEnabled();
        $params = [$userId, $organization];
        $sql = 'SELECT u.*, COALESCE(p.signature, "") AS signature,
                       COALESCE(fr.remark_name, "") AS friend_remark,
                       fr.friend_organization AS peer_organization,
                       COALESCE(org.organization_name, org.title, "") AS organization_name
                  FROM im_friend_relation fr
            INNER JOIN sm_system_organization owner_org
                    ON owner_org.id = fr.organization
                   AND owner_org.status = 1
                   AND owner_org.delete_time IS NULL
            INNER JOIN im_user u
                    ON u.organization = fr.friend_organization
                   AND u.user_id = fr.friend_user_id
             LEFT JOIN im_user_profile p
                    ON p.organization = u.organization
                   AND p.user_id = u.user_id
                   AND p.status = 1
                   AND p.delete_time IS NULL
            INNER JOIN sm_system_organization org
                    ON org.id = u.organization
                   AND org.status = 1
                   AND org.delete_time IS NULL
                 WHERE fr.user_id = ?
                   AND fr.organization = ?
                   AND fr.friend_organization > 0
                   AND fr.status = 1
                   AND fr.delete_time IS NULL
                   AND EXISTS (
                       SELECT 1 FROM im_friend_relation reverse_fr
                        WHERE reverse_fr.organization = fr.friend_organization
                          AND reverse_fr.user_id = fr.friend_user_id
                          AND reverse_fr.friend_organization = fr.organization
                          AND reverse_fr.friend_user_id = fr.user_id
                          AND reverse_fr.status = 1
                          AND reverse_fr.delete_time IS NULL
                   )
                   AND u.status = 1
                   AND u.delete_time IS NULL';
        if (!$crossOrganizationEnabled) {
            $sql .= ' AND fr.friend_organization = fr.organization';
        }
        if ($keyword !== '') {
            $pattern = $this->likePattern($keyword);
            $sql .= ' AND (u.account LIKE ? ESCAPE "\\\\"
                       OR u.nickname LIKE ? ESCAPE "\\\\"
                       OR u.mobile LIKE ? ESCAPE "\\\\"
                       OR u.im_short_no LIKE ? ESCAPE "\\\\"
                       OR fr.remark_name LIKE ? ESCAPE "\\\\"
                       OR org.organization_name LIKE ? ESCAPE "\\\\"
                       OR org.title LIKE ? ESCAPE "\\\\")';
            array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
        }
        $sql .= ' ORDER BY u.is_system DESC, COALESCE(NULLIF(fr.remark_name, ""), u.nickname) ASC, u.id ASC';

        return array_map(function (array $row) use ($organization): array {
            return $this->userView($row, (string) $row['friend_remark'], 'friend', $organization);
        }, Db::query($sql, $params));
    }

    public function searchUsers(int $organization, string $userId, string $keyword): array
    {
        (new WebImConversationAccessGuard())->assertActiveOrganizations([$organization]);
        $pattern = $this->likePattern($keyword);
        $rows = Db::query(
            'SELECT u.*, COALESCE(p.signature, "") AS signature,
                    COALESCE(fr.remark_name, "") AS friend_remark,
                    u.organization AS peer_organization,
                    COALESCE(org.organization_name, org.title, "") AS organization_name,
                    CASE
                        WHEN fr.id IS NOT NULL AND EXISTS (
                            SELECT 1 FROM im_friend_relation reverse_fr
                             WHERE reverse_fr.organization = fr.friend_organization
                               AND reverse_fr.user_id = fr.friend_user_id
                               AND reverse_fr.friend_organization = fr.organization
                               AND reverse_fr.friend_user_id = fr.user_id
                               AND reverse_fr.status = 1
                               AND reverse_fr.delete_time IS NULL
                        ) THEN "friend"
                        WHEN EXISTS (
                            SELECT 1 FROM im_friend_request outgoing
                             WHERE outgoing.from_user_id = ?
                               AND outgoing.from_organization = ?
                               AND outgoing.to_user_id = u.user_id
                               AND outgoing.to_organization = u.organization
                               AND outgoing.status = 1
                               AND outgoing.delete_time IS NULL
                        ) THEN "pending_out"
                        WHEN EXISTS (
                            SELECT 1 FROM im_friend_request incoming
                             WHERE incoming.from_user_id = u.user_id
                               AND incoming.from_organization = u.organization
                               AND incoming.to_user_id = ?
                               AND incoming.to_organization = ?
                               AND incoming.status = 1
                               AND incoming.delete_time IS NULL
                        ) THEN "pending_in"
                        ELSE "none"
                    END AS relation_status
               FROM im_user u
          LEFT JOIN im_user_profile p
                 ON p.organization = u.organization
                AND p.user_id = u.user_id
                AND p.status = 1
                AND p.delete_time IS NULL
          LEFT JOIN im_user_privacy_setting ps
                 ON ps.organization = u.organization
                AND ps.user_id = u.user_id
          LEFT JOIN im_friend_relation fr
                ON fr.organization = ?
                AND fr.user_id = ?
                AND fr.friend_organization = u.organization
                AND fr.friend_user_id = u.user_id
                AND fr.status = 1
                AND fr.delete_time IS NULL
         INNER JOIN sm_system_organization org
                 ON org.id = u.organization
                AND org.status = 1
                AND org.delete_time IS NULL
              WHERE u.organization = ?
                AND u.user_id <> ?
                AND u.status = 1
                AND u.is_system = 2
                AND u.delete_time IS NULL
                AND (
                    ((u.account LIKE ? ESCAPE "\\\\" OR u.nickname LIKE ? ESCAPE "\\\\")
                        AND COALESCE(ps.allow_add_by_username, 1) = 1)
                    OR (u.mobile LIKE ? ESCAPE "\\\\" AND COALESCE(ps.allow_add_by_mobile, 1) = 1)
                    OR (u.im_short_no LIKE ? ESCAPE "\\\\" AND COALESCE(ps.allow_add_by_short_no, 1) = 1)
                )
           ORDER BY CASE
                        WHEN u.account = ? OR u.nickname = ? OR u.mobile = ? OR u.im_short_no = ? THEN 0
                        ELSE 1
                    END,
                    u.nickname ASC,
                    u.id ASC
              LIMIT 20',
            [
                $userId,
                $organization,
                $userId,
                $organization,
                $organization,
                $userId,
                $organization,
                $userId,
                $pattern,
                $pattern,
                $pattern,
                $pattern,
                $keyword,
                $keyword,
                $keyword,
                $keyword,
            ],
        );

        if (CrossOrganizationSocialPolicy::isEnabled() && $keyword !== '') {
            $crossRows = Db::query(
                'SELECT u.*, COALESCE(p.signature, "") AS signature,
                        COALESCE(fr.remark_name, "") AS friend_remark,
                        u.organization AS peer_organization,
                        COALESCE(org.organization_name, org.title, "") AS organization_name,
                        CASE
                            WHEN fr.id IS NOT NULL AND EXISTS (
                                SELECT 1 FROM im_friend_relation reverse_fr
                                 WHERE reverse_fr.organization = fr.friend_organization
                                   AND reverse_fr.user_id = fr.friend_user_id
                                   AND reverse_fr.friend_organization = fr.organization
                                   AND reverse_fr.friend_user_id = fr.user_id
                                   AND reverse_fr.status = 1
                                   AND reverse_fr.delete_time IS NULL
                            ) THEN "friend"
                            WHEN EXISTS (
                                SELECT 1 FROM im_friend_request outgoing
                                 WHERE outgoing.from_user_id = ?
                                   AND outgoing.from_organization = ?
                                   AND outgoing.to_user_id = u.user_id
                                   AND outgoing.to_organization = u.organization
                                   AND outgoing.status = 1
                                   AND outgoing.delete_time IS NULL
                            ) THEN "pending_out"
                            WHEN EXISTS (
                                SELECT 1 FROM im_friend_request incoming
                                 WHERE incoming.from_user_id = u.user_id
                                   AND incoming.from_organization = u.organization
                                   AND incoming.to_user_id = ?
                                   AND incoming.to_organization = ?
                                   AND incoming.status = 1
                                   AND incoming.delete_time IS NULL
                            ) THEN "pending_in"
                            ELSE "none"
                        END AS relation_status
                   FROM im_user u
              LEFT JOIN im_user_profile p
                     ON p.organization = u.organization
                    AND p.user_id = u.user_id
                    AND p.status = 1
                    AND p.delete_time IS NULL
              LEFT JOIN im_user_privacy_setting ps
                     ON ps.organization = u.organization
                    AND ps.user_id = u.user_id
              LEFT JOIN im_friend_relation fr
                ON fr.organization = ?
                AND fr.user_id = ?
                AND fr.friend_organization = u.organization
                AND fr.friend_user_id = u.user_id
                    AND fr.status = 1
                    AND fr.delete_time IS NULL
             INNER JOIN sm_system_organization org
                     ON org.id = u.organization
                    AND org.status = 1
                    AND org.delete_time IS NULL
                  WHERE u.organization <> ?
                    AND u.status = 1
                    AND u.is_system = 2
                    AND u.delete_time IS NULL
                    AND (
                        (u.user_id = ? OR u.account = ? OR u.im_short_no = ? OR u.mobile = ?)
                    )
                    AND (
                        ((u.account = ? OR u.user_id = ?) AND COALESCE(ps.allow_add_by_username, 1) = 1)
                        OR (u.mobile = ? AND COALESCE(ps.allow_add_by_mobile, 1) = 1)
                        OR (u.im_short_no = ? AND COALESCE(ps.allow_add_by_short_no, 1) = 1)
                    )
               ORDER BY u.nickname ASC, u.id ASC
                  LIMIT 20',
                [
                    $userId,
                    $organization,
                    $userId,
                    $organization,
                    $organization,
                    $userId,
                    $organization,
                    $keyword,
                    $keyword,
                    $keyword,
                    $keyword,
                    $keyword,
                    $keyword,
                    $keyword,
                    $keyword,
                ],
            );
            $seen = [];
            foreach ($rows as $row) {
                $seen[SingleConversationIdentity::identity(
                    (int) $row['organization'],
                    (string) $row['user_id'],
                )] = true;
            }
            foreach ($crossRows as $row) {
                $identity = SingleConversationIdentity::identity(
                    (int) $row['organization'],
                    (string) $row['user_id'],
                );
                if (!isset($seen[$identity])) {
                    $rows[] = $row;
                    $seen[$identity] = true;
                }
            }
        }

        return array_map(fn (array $row): array => $this->userView(
            $row,
            (string) ($row['friend_remark'] ?? ''),
            (string) ($row['relation_status'] ?? 'none'),
            $organization,
        ), $rows);
    }

    public function friendRequests(int $organization, string $userId): array
    {
        $crossOrganizationEnabled = CrossOrganizationSocialPolicy::isEnabled();
        $policySql = $crossOrganizationEnabled ? '' : ' AND from_organization = to_organization';
        $rows = Db::query(
            'SELECT * FROM im_friend_request
              WHERE delete_time IS NULL
                AND status IN (1, 2, 3)
                AND EXISTS (
                    SELECT 1
                      FROM sm_system_organization from_org
                     WHERE from_org.id = im_friend_request.from_organization
                       AND from_org.status = 1
                       AND from_org.delete_time IS NULL
                )
                AND EXISTS (
                    SELECT 1
                      FROM sm_system_organization to_org
                     WHERE to_org.id = im_friend_request.to_organization
                       AND to_org.status = 1
                       AND to_org.delete_time IS NULL
                )
                AND (
                    (from_organization = ? AND from_user_id = ?)
                    OR (to_organization = ? AND to_user_id = ?)
                )' . $policySql . '
           ORDER BY id DESC
              LIMIT 100',
            [$organization, $userId, $organization, $userId],
        );
        if ($rows === []) {
            return [];
        }

        $users = [];
        foreach ($rows as $row) {
            $fromOrg = $this->requiredOrganization($row, 'from_organization', '好友申请');
            $toOrg = $this->requiredOrganization($row, 'to_organization', '好友申请');
            $fromUserId = (string) $row['from_user_id'];
            $toUserId = (string) $row['to_user_id'];
            $from = $this->userById($fromOrg, $fromUserId, false);
            $to = $this->userById($toOrg, $toUserId, false);
            if ($from !== null) {
                $users[SingleConversationIdentity::identity($fromOrg, $fromUserId)] = $from;
            }
            if ($to !== null) {
                $users[SingleConversationIdentity::identity($toOrg, $toUserId)] = $to;
            }
        }

        return array_map(function (array $row) use ($userId, $users, $organization): array {
            $fromUserId = (string) $row['from_user_id'];
            $toUserId = (string) $row['to_user_id'];
            $fromOrg = $this->requiredOrganization($row, 'from_organization', '好友申请');
            $toOrg = $this->requiredOrganization($row, 'to_organization', '好友申请');
            $fromIdentity = SingleConversationIdentity::identity($fromOrg, $fromUserId);
            $toIdentity = SingleConversationIdentity::identity($toOrg, $toUserId);

            return [
                'id' => (int) $row['id'],
                'direction' => $toOrg === $organization && hash_equals($toUserId, $userId)
                    ? 'incoming'
                    : 'outgoing',
                'message' => (string) ($row['message'] ?? ''),
                'status' => (int) $row['status'],
                'status_text' => $this->requestStatusText((int) $row['status']),
                'create_time' => (string) ($row['create_time'] ?? ''),
                'handle_time' => (string) ($row['handle_time'] ?? ''),
                'from_organization' => $fromOrg,
                'to_organization' => $toOrg,
                'from_user' => isset($users[$fromIdentity])
                    ? $this->userView($users[$fromIdentity], '', 'none', $organization)
                    : null,
                'to_user' => isset($users[$toIdentity])
                    ? $this->userView($users[$toIdentity], '', 'none', $organization)
                    : null,
            ];
        }, $rows);
    }

    public function sendFriendRequest(
        int $organization,
        string $fromUserId,
        int $toOrganization,
        string $toUserId,
        string $message,
        string $now,
    ): array {
        return Db::transaction(function () use (
            $organization,
            $fromUserId,
            $toOrganization,
            $toUserId,
            $message,
            $now,
        ): array {
            $crossOrg = $toOrganization !== $organization;
            $crossOrgAccessSnapshotId = null;
            if ($crossOrg) {
                $policy = CrossOrganizationSocialPolicy::lockSharedInsideTransaction();
                if (!$policy['enabled']) {
                    throw new ApiException('跨租户好友未开放。', 403);
                }
                $crossOrgAccessSnapshotId = $policy['access_snapshot_id'];
            }
            (new WebImConversationAccessGuard())->assertActiveOrganizations(
                [$organization, $toOrganization],
                true,
            );
            [$fromUser, $toUser] = $this->activeUserPairForUpdate(
                $organization,
                $fromUserId,
                $toOrganization,
                $toUserId,
            );

            $pendingRows = $this->pendingFriendRequestsForUpdate(
                $organization,
                $fromUserId,
                $toOrganization,
                $toUserId,
            );
            if ($this->areFriends(
                $organization,
                $fromUserId,
                $toOrganization,
                $toUserId,
                true,
            )) {
                return ['status' => 'accepted', 'message' => '对方已经是你的好友'];
            }
            $privacy = Db::query(
                'SELECT allow_add_by_username FROM im_user_privacy_setting
                  WHERE organization = ? AND user_id = ?
                  LIMIT 1
                  FOR UPDATE',
                [$toOrganization, $toUserId],
            )[0] ?? null;
            if ($privacy !== null && (int) $privacy['allow_add_by_username'] !== 1) {
                throw new ApiException('对方不允许通过用户名添加好友。', 403);
            }
            $reversePending = null;
            $outgoingPending = null;
            foreach ($pendingRows as $pending) {
                if ((int) $pending['from_organization'] === $organization
                    && hash_equals((string) $pending['from_user_id'], $fromUserId)) {
                    $outgoingPending = $pending;
                    continue;
                }
                $reversePending = $pending;
            }
            if ($reversePending !== null) {
                $this->createFriendPairAcross(
                    $organization,
                    $fromUserId,
                    $toOrganization,
                    $toUserId,
                    'username',
                    $now,
                );
                Db::execute(
                    'UPDATE im_friend_request
                        SET status = 2, handle_time = ?, update_time = ?
                      WHERE id = ? AND status = 1',
                    [$now, $now, (int) $reversePending['id']],
                );

                return ['status' => 'accepted', 'message' => '已接受对方的好友申请'];
            }
            if ($outgoingPending !== null) {
                return ['status' => 'pending', 'message' => '好友申请已发送'];
            }

            // Store under recipient organization so they see the request in their list.
            $requestId = Db::table('im_friend_request')->insertGetId([
                'organization' => $toOrganization,
                'from_organization' => $organization,
                'to_organization' => $toOrganization,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'add_method' => 'username',
                'message' => $message !== '' ? $message : null,
                'status' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $pendingCount = Db::query(
                'SELECT COUNT(*) AS aggregate FROM im_friend_request
                  WHERE to_organization = ?
                    AND to_user_id = ?
                    AND status = 1
                    AND delete_time IS NULL',
                [$toOrganization, $toUserId],
            )[0]['aggregate'] ?? 0;

            return [
                'status' => 'pending',
                'message' => '好友申请已发送',
                '_realtime_event_organization' => $toOrganization,
                '_realtime_event' => [
                    ...($crossOrgAccessSnapshotId !== null ? [
                        'cross_org_access_snapshot_id' => $crossOrgAccessSnapshotId,
                    ] : []),
                    'request_id' => (int) $requestId,
                    'from_user_id' => $fromUserId,
                    'to_user_id' => $toUserId,
                    'from_organization' => $organization,
                    'to_organization' => $toOrganization,
                    'message' => $message,
                    'pending_count' => (int) $pendingCount,
                    'create_time' => $now,
                    'from_user' => $this->userView($fromUser, '', 'none', $toOrganization),
                ],
            ];
        });
    }

    public function handleFriendRequest(
        int $organization,
        string $userId,
        int $requestId,
        string $action,
        string $now,
    ): array {
        // Discover the participant homes before the transaction. The exact
        // request is locked and revalidated only after the common
        // policy -> organizations -> users prefix below.
        $preview = Db::query(
            'SELECT from_organization, from_user_id, to_organization
               FROM im_friend_request
              WHERE id = ?
                AND to_user_id = ?
                AND to_organization = ?
                AND organization = to_organization
                AND delete_time IS NULL
              LIMIT 1',
            [$requestId, $userId, $organization],
        )[0] ?? null;
        if ($preview === null) {
            throw new ApiException('好友申请不存在。', 404);
        }
        $fromUserId = (string) $preview['from_user_id'];
        $fromOrganization = $this->requiredOrganization($preview, 'from_organization', '好友申请');
        $toOrganization = $this->requiredOrganization($preview, 'to_organization', '好友申请');

        return Db::transaction(function () use (
            $organization,
            $userId,
            $requestId,
            $action,
            $now,
            $fromUserId,
            $fromOrganization,
            $toOrganization,
        ): array {
            $policy = CrossOrganizationSocialPolicy::lockSharedInsideTransaction();
            $crossOrg = $fromOrganization !== $toOrganization;
            if ($crossOrg && !$policy['enabled']) {
                throw new ApiException('跨租户好友未开放。', 403);
            }
            (new WebImConversationAccessGuard())->assertActiveOrganizations(
                [$fromOrganization, $toOrganization],
                true,
            );
            if ($action === 'accept') {
                $this->activeUserPairForUpdate(
                    $fromOrganization,
                    $fromUserId,
                    $toOrganization,
                    $userId,
                );
            }
            $request = Db::query(
                'SELECT * FROM im_friend_request
                  WHERE id = ?
                    AND from_organization = ?
                    AND from_user_id = ?
                    AND to_organization = ?
                    AND to_user_id = ?
                    AND organization = ?
                    AND organization = to_organization
                    AND delete_time IS NULL
                  LIMIT 1
                  FOR UPDATE',
                [
                    $requestId,
                    $fromOrganization,
                    $fromUserId,
                    $toOrganization,
                    $userId,
                    $organization,
                ],
            )[0] ?? null;
            if ($request === null) {
                throw new ApiException('好友申请不存在。', 404);
            }
            $lockedFromOrganization = $this->requiredOrganization(
                $request,
                'from_organization',
                '好友申请',
            );
            $lockedFromUserId = (string) ($request['from_user_id'] ?? '');
            $lockedToOrganization = $this->requiredOrganization(
                $request,
                'to_organization',
                '好友申请',
            );
            $lockedToUserId = (string) ($request['to_user_id'] ?? '');
            if (trim($lockedFromUserId) === '' || trim($lockedToUserId) === '') {
                throw new \RuntimeException('好友申请参与者身份无效。');
            }
            $status = (int) $request['status'];
            if ($status !== 1) {
                if (($status === 2 && $action === 'accept') || ($status === 3 && $action === 'reject')) {
                    return ['status' => $action === 'accept' ? 'accepted' : 'rejected'];
                }
                throw new ApiException('好友申请已被处理。', 409);
            }

            if ($action === 'accept') {
                $this->createFriendPairAcross(
                    $lockedFromOrganization,
                    $lockedFromUserId,
                    $lockedToOrganization,
                    $lockedToUserId,
                    (string) $request['add_method'],
                    $now,
                );
                Db::execute(
                    'UPDATE im_friend_request
                        SET status = 2, handle_time = ?, update_time = ?
                      WHERE id = ? AND status = 1',
                    [$now, $now, $requestId],
                );

                return ['status' => 'accepted'];
            }

            Db::execute(
                'UPDATE im_friend_request
                    SET status = 3, handle_time = ?, update_time = ?
                  WHERE id = ? AND status = 1',
                [$now, $now, $requestId],
            );

            return ['status' => 'rejected'];
        });
    }

    public function createGroup(
        int $organization,
        string $ownerUserId,
        string $title,
        array $memberIds,
        string $now,
    ): array {
        $conversationId = (new GroupMemberAccessService())->createGroup(
            $organization,
            $ownerUserId,
            $title,
            $memberIds,
            $now,
        );

        $row = $this->conversationRow($organization, $ownerUserId, $conversationId);
        if ($row === null) {
            throw new \RuntimeException('Created group conversation cannot be read back.');
        }

        return $this->formatConversation($organization, $ownerUserId, $row);
    }

    public function groupMembers(int $organization, string $userId, string $conversationId): array
    {
        $viewer = $this->assertGroupRole(
            $organization,
            $conversationId,
            $userId,
            ['owner', 'admin', 'member'],
        );

        return $this->groupMemberRows(
            $organization,
            $conversationId,
            in_array((string) $viewer['member_role'], ['owner', 'admin'], true),
        );
    }

    public function addGroupMembers(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        array $memberIds,
        array $expectedVersions,
        string $now,
    ): array {
        (new GroupMemberAccessService())->join(
            $organization,
            $operatorUserId,
            $conversationId,
            $memberIds,
            $expectedVersions,
            $now,
        );

        return $this->groupMembers($organization, $operatorUserId, $conversationId);
    }

    public function updateGroupProfile(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        ?string $title,
        ?string $avatarFileId,
        ?string $description,
        bool $notifyAll,
        string $now,
    ): array {
        $noticeMessage = null;
        $noticeRecipientUserIds = [];
        Db::transaction(function () use (
            $organization,
            $operatorUserId,
            $conversationId,
            $title,
            $avatarFileId,
            $description,
            $notifyAll,
            $now,
            &$noticeMessage,
            &$noticeRecipientUserIds,
        ): void {
            $operator = $this->assertGroupRole(
                $organization,
                $conversationId,
                $operatorUserId,
                ['owner', 'admin'],
                true,
            );
            $conversationUpdates = ['update_time' => $now];
            if ($title !== null) {
                $conversationUpdates['title'] = $title;
            }
            if ($avatarFileId !== null) {
                $conversationUpdates['avatar'] = $avatarFileId !== '' ? $avatarFileId : null;
            }
            if (count($conversationUpdates) > 1) {
                $assignments = [];
                $params = [];
                foreach ($conversationUpdates as $column => $value) {
                    $assignments[] = $column . ' = ?';
                    $params[] = $value;
                }
                Db::execute(
                    'UPDATE im_conversation SET ' . implode(', ', $assignments) . '
                      WHERE organization = ? AND BINARY conversation_id = BINARY ?
                        AND conversation_type = 2 AND status = 1 AND delete_time IS NULL',
                    array_merge($params, [$organization, $conversationId]),
                );
            }
            $descriptionChanged = $description !== null
                && !hash_equals(
                    (string) ($operator['group_description'] ?? ''),
                    $description,
                );
            if ($descriptionChanged) {
                $affected = Db::execute(
                    'UPDATE im_group_profile
                        SET description = ?, update_time = ?
                      WHERE organization = ?
                        AND BINARY conversation_id = BINARY ?
                        AND status = 1
                        AND delete_time IS NULL',
                    [$description !== '' ? $description : null, $now, $organization, $conversationId],
                );
                if ($affected > 1) {
                    throw new \RuntimeException('Group profile update affected multiple rows.');
                }
            }
            if ($notifyAll && $descriptionChanged) {
                $noticeState = Telemetry::inSpan(
                    'b8im.message.notice.persist',
                    'message.notice.persist',
                    [
                        'b8im.organization' => $organization,
                        'b8im.conversation_id' => $conversationId,
                        'b8im.conversation_type' => self::CONVERSATION_GROUP,
                    ],
                    fn (): array => $this->appendGroupDescriptionNotice(
                        $organization,
                        $conversationId,
                        $operatorUserId,
                        $description,
                        $now,
                    ),
                );
                $noticeMessage = $noticeState['message'];
                $noticeRecipientUserIds = $noticeState['recipient_user_ids'];
            }
        });

        $row = $this->conversationRow($organization, $operatorUserId, $conversationId);
        if ($row === null) {
            throw new \RuntimeException('Updated group conversation cannot be read back.');
        }
        $conversation = $this->formatConversation($organization, $operatorUserId, $row);
        $conversation['notice_message'] = $noticeMessage;
        if ($noticeMessage !== null) {
            $conversation['_notice_recipient_user_ids'] = $noticeRecipientUserIds;
        }

        return $conversation;
    }

    public function updateGroupManagers(
        int $organization,
        string $ownerUserId,
        string $conversationId,
        array $managerUserIds,
        string $now,
    ): array {
        Db::transaction(function () use (
            $organization,
            $ownerUserId,
            $conversationId,
            $managerUserIds,
            $now,
        ): void {
            $this->assertGroupRole($organization, $conversationId, $ownerUserId, ['owner'], true);
            $memberRows = Db::query(
                'SELECT user_id, member_role
                   FROM im_conversation_member
                  WHERE organization = ?
                    AND BINARY conversation_id = BINARY ?
                    AND member_organization = ?
                    AND status = 1
                    AND access_state = "active"
                    AND delete_time IS NULL
                  FOR UPDATE',
                [$organization, $conversationId, $organization],
            );
            $activeIds = [];
            foreach ($memberRows as $member) {
                $activeIds[(string) $member['user_id']] = true;
            }
            foreach ($managerUserIds as $managerUserId) {
                if (!isset($activeIds[$managerUserId]) || hash_equals($managerUserId, $ownerUserId)) {
                    throw new ApiException('只能把当前群成员设为管理员。', 422);
                }
            }

            Db::execute(
                'UPDATE im_conversation_member
                    SET member_role = "member", update_time = ?
                  WHERE organization = ?
                    AND BINARY conversation_id = BINARY ?
                    AND member_organization = ?
                    AND member_role = "admin"
                    AND status = 1
                    AND access_state = "active"
                    AND delete_time IS NULL',
                [$now, $organization, $conversationId, $organization],
            );
            if ($managerUserIds !== []) {
                $placeholders = implode(',', array_fill(0, count($managerUserIds), '?'));
                Db::execute(
                    'UPDATE im_conversation_member
                        SET member_role = "admin", update_time = ?
                      WHERE organization = ?
                        AND BINARY conversation_id = BINARY ?
                        AND member_organization = ?
                        AND BINARY user_id IN (' . $placeholders . ')
                        AND status = 1
                        AND access_state = "active"
                        AND delete_time IS NULL',
                    array_merge([$now, $organization, $conversationId, $organization], $managerUserIds),
                );
            }
        });

        return $this->groupMembers($organization, $ownerUserId, $conversationId);
    }

    public function updateGroupMemberStatus(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        string $memberUserId,
        int $status,
        ?string $muteUntil,
        string $now,
    ): array {
        Db::transaction(function () use (
            $organization,
            $operatorUserId,
            $conversationId,
            $memberUserId,
            $status,
            $muteUntil,
            $now,
        ): void {
            $this->assertCanManageGroupMember(
                $organization,
                $conversationId,
                $operatorUserId,
                $memberUserId,
                true,
            );
            $affected = Db::execute(
                'UPDATE im_conversation_member
                    SET mute_status = ?, mute_until = ?, update_time = ?
                  WHERE organization = ?
                    AND BINARY conversation_id = BINARY ?
                    AND member_organization = ?
                    AND BINARY user_id = BINARY ?
                    AND status = 1
                    AND access_state = "active"
                    AND delete_time IS NULL',
                [
                    $status === 2 ? 1 : 0,
                    $status === 2 ? $muteUntil : null,
                    $now,
                    $organization,
                    $conversationId,
                    $organization,
                    $memberUserId,
                ],
            );
            if ($affected > 1) {
                throw new \RuntimeException('Group member mute update affected multiple rows.');
            }
        });

        return $this->groupMembers($organization, $operatorUserId, $conversationId);
    }

    public function removeGroupMember(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        string $memberUserId,
        string $expectedVersion,
        string $now,
    ): array {
        (new GroupMemberAccessService())->remove(
            $organization, $operatorUserId, $conversationId, $memberUserId, $expectedVersion, $now,
        );

        return $this->groupMembers($organization, $operatorUserId, $conversationId);
    }

    public function leaveGroup(int $organization, string $userId, string $conversationId, string $expectedVersion, string $now): array
    {
        (new GroupMemberAccessService())->leave($organization, $userId, $conversationId, $expectedVersion, $now);
        return ['conversation_id' => $conversationId, 'left' => true];
    }

    public function suspendGroupMember(int $organization, string $operatorUserId, string $conversationId, string $memberUserId, string $expectedVersion, string $now): array
    {
        (new GroupMemberAccessService())->suspend($organization, $operatorUserId, $conversationId, $memberUserId, $expectedVersion, $now);
        return $this->groupMembers($organization, $operatorUserId, $conversationId);
    }

    public function restoreGroupMember(int $organization, string $operatorUserId, string $conversationId, string $memberUserId, string $expectedVersion, string $now): array
    {
        (new GroupMemberAccessService())->restore($organization, $operatorUserId, $conversationId, $memberUserId, $expectedVersion, $now);
        return $this->groupMembers($organization, $operatorUserId, $conversationId);
    }

    public function revokeGroupMemberHistory(int $organization, string $operatorUserId, string $conversationId, string $memberUserId, string $expectedVersion, array $periodNumbers, string $now): array
    {
        (new GroupMemberAccessService())->revokeHistory(
            $organization, $operatorUserId, $conversationId, $memberUserId,
            $expectedVersion, $periodNumbers, $now,
        );
        return $this->groupMembers($organization, $operatorUserId, $conversationId);
    }

    public function updateConversationSetting(
        int $organization,
        string $userId,
        string $conversationId,
        ?bool $isPinned,
        ?bool $isMuted,
        string $now,
    ): array {
        return Db::transaction(function () use (
            $organization,
            $userId,
            $conversationId,
            $isPinned,
            $isMuted,
            $now,
        ): array {
            $member = $this->assertActiveConversationMember($organization, $conversationId, $userId, true);
            $updates = ['update_time' => $now];
            if ($isPinned !== null) {
                $updates['is_pinned'] = $isPinned ? 1 : 2;
            }
            if ($isMuted !== null) {
                $updates['is_muted'] = $isMuted ? 1 : 2;
            }
            $assignments = [];
            $params = [];
            foreach ($updates as $column => $value) {
                $assignments[] = $column . ' = ?';
                $params[] = $value;
            }
            $params = array_merge($params, [(int) $member['id'], $organization, $organization, $userId]);
            Db::execute(
                'UPDATE im_conversation_member SET ' . implode(', ', $assignments) . '
                  WHERE id = ? AND organization = ? AND member_organization = ?
                    AND BINARY user_id = BINARY ? AND status = 1 AND access_state = "active"',
                $params,
            );
            $current = Db::query(
                'SELECT is_pinned, is_muted FROM im_conversation_member
                  WHERE id = ? AND organization = ? AND member_organization = ?
                    AND BINARY user_id = BINARY ? AND status = 1 AND access_state = "active"
                  LIMIT 1',
                [(int) $member['id'], $organization, $organization, $userId],
            )[0] ?? null;
            if ($current === null) {
                throw new \RuntimeException('Updated conversation setting cannot be read back.');
            }

            return [
                'conversation_id' => $conversationId,
                'is_pinned' => (int) $current['is_pinned'] === 1,
                'is_muted' => (int) $current['is_muted'] === 1,
            ];
        });
    }

    public function updateFriendRemark(
        int $organization,
        string $userId,
        int $friendOrganization,
        string $friendUserId,
        string $remark,
        string $now,
    ): array {
        return Db::transaction(function () use (
            $organization,
            $userId,
            $friendOrganization,
            $friendUserId,
            $remark,
            $now,
        ): array {
            if ($organization !== $friendOrganization) {
                $policy = CrossOrganizationSocialPolicy::lockSharedInsideTransaction();
                if (!$policy['enabled']) {
                    throw new ApiException('跨租户好友未开放。', 403);
                }
            }
            if (!$this->areFriends(
                $organization,
                $userId,
                $friendOrganization,
                $friendUserId,
                true,
            )) {
                throw new ApiException('好友不存在。', 404);
            }
            $relation = Db::query(
                'SELECT id FROM im_friend_relation
                  WHERE organization = ?
                    AND user_id = ?
                    AND friend_organization = ?
                    AND friend_user_id = ?
                    AND status = 1
                    AND delete_time IS NULL
                  LIMIT 1
                  FOR UPDATE',
                [$organization, $userId, $friendOrganization, $friendUserId],
            )[0] ?? null;
            if ($relation === null) {
                throw new ApiException('好友不存在。', 404);
            }
            Db::execute(
                'UPDATE im_friend_relation
                    SET remark_name = ?, update_time = ?
                  WHERE id = ? AND organization = ? AND user_id = ?',
                [$remark !== '' ? $remark : null, $now, (int) $relation['id'], $organization, $userId],
            );

            return [
                'friend_organization' => $friendOrganization,
                'friend_user_id' => $friendUserId,
                'remark' => $remark,
            ];
        });
    }

    public function searchMessages(
        int $organization,
        string $userId,
        string $conversationId,
        string $keyword,
        int $messageType,
        int $limit,
    ): array {
        $access = (new WebImConversationAccessGuard())->assertReadable($organization, $userId, $conversationId);
        $includeSenderUser = (bool) ($access['is_active'] ?? true);
        $this->assertMembershipPeriod($organization, $conversationId, $userId);
        $shards = Db::query(
            'SELECT DISTINCT i.shard_table
               FROM im_message_index i
              WHERE i.organization = ?
                AND BINARY i.conversation_id = BINARY ?
                AND EXISTS (
                    SELECT 1 FROM im_conversation_membership_period mp
                     WHERE mp.organization = i.organization
                       AND BINARY mp.conversation_id = BINARY i.conversation_id
                       AND mp.member_organization = ?
                       AND BINARY mp.user_id = BINARY ?
                       AND mp.status = 1
                       AND i.message_seq >= mp.visible_from_message_seq
                       AND (mp.visible_until_message_seq IS NULL OR i.message_seq <= mp.visible_until_message_seq)
                )',
            [$organization, $conversationId, $organization, $userId],
        );

        $messages = [];
        foreach ($shards as $shard) {
            $shardTable = (string) $shard['shard_table'];
            $table = $this->quoteShard($shardTable);
            $params = [
                $shardTable,
                $organization,
                $conversationId,
                $organization,
                $userId,
                $organization,
                $userId,
            ];
            $sql = 'SELECT m.*, i.global_seq AS home_global_seq
                      FROM ' . $table . ' m
                INNER JOIN im_message_index i
                        ON i.organization = m.organization
                       AND BINARY i.message_id = BINARY m.message_id
                       AND BINARY i.conversation_id = BINARY m.conversation_id
                       AND i.message_seq = m.message_seq
                       AND i.sender_organization = m.sender_organization
                       AND BINARY i.sender_id = BINARY m.sender_id
                       AND BINARY i.shard_table = BINARY ?
                     WHERE m.organization = ?
                       AND BINARY m.conversation_id = BINARY ?
                       AND m.status = 1
                       AND m.delete_time IS NULL
                       AND EXISTS (
                           SELECT 1 FROM im_conversation_membership_period mp
                            WHERE mp.organization = m.organization
                              AND BINARY mp.conversation_id = BINARY m.conversation_id
                              AND mp.member_organization = ?
                              AND BINARY mp.user_id = BINARY ?
                              AND mp.status = 1
                              AND m.message_seq >= mp.visible_from_message_seq
                              AND (mp.visible_until_message_seq IS NULL OR m.message_seq <= mp.visible_until_message_seq)
                       )
                       AND NOT EXISTS (
                           SELECT 1 FROM im_message_user_delete ud
                            WHERE ud.organization = m.organization
                              AND BINARY ud.conversation_id = BINARY m.conversation_id
                              AND BINARY ud.message_id = BINARY m.message_id
                              AND ud.user_organization = ?
                              AND BINARY ud.user_id = BINARY ?
                       )';
            if ($messageType > 0) {
                $sql .= ' AND m.message_type = ?';
                $params[] = $messageType;
            }
            if ($keyword !== '') {
                $sql .= ' AND m.content LIKE ? ESCAPE "\\\\"';
                $params[] = $this->likePattern($keyword);
            }
            $sql .= ' ORDER BY m.message_seq DESC LIMIT ' . $limit;
            foreach (Db::query($sql, $params) as $message) {
                $message['global_seq'] = (string) ($message['home_global_seq'] ?? '');
                $messages[] = $this->formatMessage($message, $organization, $includeSenderUser);
            }
        }
        usort($messages, static fn (array $left, array $right): int =>
            (int) $right['message_seq'] <=> (int) $left['message_seq']);

        return array_slice($messages, 0, $limit);
    }

    /** @return array{message: array<string, mixed>, recipient_user_ids: list<string>} */
    private function appendGroupDescriptionNotice(
        int $organization,
        string $conversationId,
        string $actorUserId,
        string $description,
        string $now,
    ): array {
        $conversation = Db::query(
            'SELECT next_message_seq FROM im_conversation
              WHERE organization = ?
                AND BINARY conversation_id = BINARY ?
                AND conversation_type = 2
                AND status = 1
                AND delete_time IS NULL
              LIMIT 1
              FOR UPDATE',
            [$organization, $conversationId],
        )[0] ?? null;
        if ($conversation === null) {
            throw new ApiException('群聊不存在。', 404);
        }
        $sequence = Db::query(
            'SELECT next_global_seq FROM im_organization_message_sequence
              WHERE organization = ? LIMIT 1 FOR UPDATE',
            [$organization],
        )[0] ?? null;
        if ($sequence === null) {
            throw new \RuntimeException('IM organization global sequence is not initialized.');
        }
        $messageSeq = max((int) $conversation['next_message_seq'], 1);
        $globalSeq = max((int) $sequence['next_global_seq'], 1);
        $runtime = Db::query(
            'SELECT config_value FROM im_runtime_config
              WHERE config_key = "message_shard_buckets" LIMIT 1',
        )[0] ?? null;
        $buckets = (int) ($runtime['config_value'] ?? 0);
        if ($buckets < 1 || $buckets > 1024) {
            throw new \RuntimeException('IM message_shard_buckets runtime config is invalid.');
        }
        $bucket = abs(crc32($organization . ':' . $conversationId)) % $buckets;
        $shardTable = sprintf('im_message_%04d_%s', $bucket, date('Ym', strtotime($now) ?: time()));
        $this->quoteShard($shardTable);
        $exists = Db::query(
            'SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$shardTable],
        )[0] ?? null;
        if ($exists === null) {
            throw new ApiException('当前月份的 IM 消息分片未预先创建。', 503);
        }

        $actor = $this->userById($organization, $actorUserId, true);
        if ($actor === null || (int) ($actor['is_system'] ?? 1) !== 2) {
            throw new ApiException('用户不存在或已停用。', 401);
        }
        $actorName = trim((string) ($actor['nickname'] ?? $actor['account'] ?? $actorUserId));
        $text = $description === ''
            ? $actorName . '更新了群说明'
            : $actorName . '更新了群说明：' . mb_substr($description, 0, 80);
        $content = [
            'event' => 'group_description',
            'text' => '@全体成员 ' . $text,
            'actor_user_id' => $actorUserId,
            'actor_organization' => $organization,
            'actor_name' => $actorName !== '' ? $actorName : $actorUserId,
            'description' => $description,
            'mention_all' => true,
        ];
        $messageId = 'n' . bin2hex(random_bytes(16));
        Span::getCurrent()->setAttribute('b8im.message_id', $messageId);
        $clientMessageId = 'web-notice-' . $messageId;
        Db::execute(
            'INSERT INTO ' . $this->quoteShard($shardTable) . '
                (organization, conversation_id, conversation_type, message_id, message_seq,
                 client_msg_id, sender_id, sender_organization, message_type, content, status, create_time, update_time)
             VALUES (?, ?, 2, ?, ?, ?, "system_notification", ?, 5, ?, 1, ?, ?)',
            [
                $organization,
                $conversationId,
                $messageId,
                $messageSeq,
                $clientMessageId,
                $organization,
                json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $now,
                $now,
            ],
        );
        Db::table('im_message_index')->insert([
            'organization' => $organization,
            'global_seq' => $globalSeq,
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'message_seq' => $messageSeq,
            'sender_id' => 'system_notification',
            'sender_organization' => $organization,
            'client_msg_id' => $clientMessageId,
            'storage_node' => 'mysql-primary',
            'shard_table' => $shardTable,
            'create_time' => $now,
        ]);

        $members = Db::query(
            'SELECT member_organization, user_id FROM im_conversation_member
              WHERE organization = ?
                AND BINARY conversation_id = BINARY ?
                AND member_organization = ?
                AND status = 1
                AND access_state = "active"
                AND delete_time IS NULL
              FOR UPDATE',
            [$organization, $conversationId, $organization],
        );
        $recipientUserIds = [];
        $recipientIdentities = [];
        foreach ($members as $member) {
            $memberUserId = (string) $member['user_id'];
            $memberOrganization = (int) $member['member_organization'];
            $isActor = hash_equals($memberUserId, $actorUserId);
            $recipientIdentities[] = [
                'organization' => $memberOrganization,
                'user_id' => $memberUserId,
            ];
            Db::table('im_message_receipt')->insert([
                'organization' => $organization,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'user_id' => $memberUserId,
                'user_organization' => $memberOrganization,
                'status' => $isActor ? 3 : 1,
                'delivered_time' => $isActor ? $now : null,
                'read_time' => $isActor ? $now : null,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            if (!$isActor) {
                $recipientUserIds[] = $memberUserId;
            }
        }
        if ($recipientUserIds !== []) {
            $placeholders = implode(',', array_fill(0, count($recipientUserIds), '?'));
            Db::execute(
                'UPDATE im_conversation_member
                    SET unread_count = unread_count + 1, update_time = ?
                  WHERE organization = ?
                    AND BINARY conversation_id = BINARY ?
                    AND member_organization = ?
                    AND BINARY user_id IN (' . $placeholders . ')
                    AND status = 1
                    AND access_state = "active"
                    AND delete_time IS NULL',
                array_merge([$now, $organization, $conversationId, $organization], $recipientUserIds),
            );
        }
        $conversationUpdated = Db::execute(
            'UPDATE im_conversation
                SET next_message_seq = ?,
                    last_message_seq = ?,
                    last_message_id = ?,
                    last_message_time = ?,
                    last_message_summary = ?,
                    update_time = ?
              WHERE organization = ? AND BINARY conversation_id = BINARY ?',
            [
                $messageSeq + 1,
                $messageSeq,
                $messageId,
                $now,
                '@全体成员 ' . $text,
                $now,
                $organization,
                $conversationId,
            ],
        );
        if ($conversationUpdated !== 1) {
            throw new \RuntimeException('Group notice conversation state update failed.');
        }
        $sequenceUpdated = Db::execute(
            'UPDATE im_organization_message_sequence
                SET next_global_seq = ?, update_time = ?
              WHERE organization = ? AND next_global_seq = ?',
            [$globalSeq + 1, $now, $organization, $globalSeq],
        );
        if ($sequenceUpdated !== 1) {
            throw new \RuntimeException('Group notice global sequence update failed.');
        }

        $message = Db::query(
            'SELECT * FROM ' . $this->quoteShard($shardTable) . '
              WHERE organization = ? AND BINARY message_id = BINARY ? LIMIT 1',
            [$organization, $messageId],
        )[0] ?? null;
        if ($message === null) {
            throw new \RuntimeException('Group description notice cannot be read back.');
        }
        $message['global_seq'] = (string) $globalSeq;
        $realtimeMessage = $this->formatMessage($message);
        $realtimeMessage['status'] = 'normal';
        $realtimeMessage['update_time'] = (string) ($message['update_time'] ?? '');
        $eventId = hash('sha256', implode('|', [
            $organization,
            'message.created',
            $messageId,
        ]));
        $eventPayload = [
            'event_id' => $eventId,
            'event_type' => 'message.created',
            'organization' => $organization,
            'message_id' => $messageId,
            'message_seq' => $messageSeq,
            'global_seq' => (string) $globalSeq,
            'conversation_id' => $conversationId,
            'conversation_type' => self::CONVERSATION_GROUP,
            'sender_id' => 'system_notification',
            'sender_organization' => $organization,
            'actor_user_id' => $actorUserId,
            'actor_organization' => $organization,
            'origin_user_id' => $actorUserId,
            'origin_organization' => $organization,
            'origin_client_id' => 'web-control-' . $messageId,
            'recipient_count' => count($recipientIdentities),
            'recipient_identities' => $recipientIdentities,
            'message' => $realtimeMessage,
            'created_at' => $now,
        ];
        $traceHeaders = Telemetry::currentTraceHeaders();
        Db::table('im_message_outbox')->insert([
            'event_id' => $eventId,
            'organization' => $organization,
            'event_type' => 'message.created',
            'routing_key' => 'message.created',
            'message_id' => $messageId,
            'change_seq' => 0,
            'conversation_id' => $conversationId,
            'conversation_type' => self::CONVERSATION_GROUP,
            'payload_json' => json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'traceparent' => $traceHeaders['traceparent'] ?? null,
            'tracestate' => $traceHeaders['tracestate'] ?? null,
            'status' => 1,
            'retry_count' => 0,
            'next_retry_at' => $now,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return [
            'message' => $this->formatMessage($message),
            'recipient_user_ids' => $recipientUserIds,
        ];
    }

    private function markConversationRead(
        int $organization,
        string $userId,
        string $conversationId,
        string $now,
    ): int {
        $access = (new WebImConversationAccessGuard())->assertAccessible(
            $organization,
            $userId,
            $conversationId,
            true,
        );
        $homes = $access['homes'];
        $accessSnapshotId = $access['is_cross_organization']
            ? (string) ($access['cross_org_access_snapshot_id'] ?? '')
            : null;
        if ($access['is_cross_organization']
            && preg_match('/^[1-9][0-9]{0,19}$/D', $accessSnapshotId) !== 1) {
            throw new \RuntimeException('Cross-organization read snapshot is invalid.');
        }
        $visible = Db::query(
            'SELECT i.message_id, i.message_seq
               FROM im_message_index i
              WHERE i.organization = ?
                AND BINARY i.conversation_id = BINARY ?
                AND EXISTS (
                    SELECT 1 FROM im_conversation_membership_period mp
                     WHERE mp.organization = i.organization
                       AND BINARY mp.conversation_id = BINARY i.conversation_id
                       AND mp.member_organization = ?
                       AND BINARY mp.user_id = BINARY ?
                       AND mp.status = 1
                       AND i.message_seq >= mp.visible_from_message_seq
                       AND (mp.visible_until_message_seq IS NULL OR i.message_seq <= mp.visible_until_message_seq)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM im_message_user_delete ud
                     WHERE ud.organization = i.organization
                       AND BINARY ud.conversation_id = BINARY i.conversation_id
                       AND BINARY ud.message_id = BINARY i.message_id
                       AND ud.user_organization = ?
                       AND BINARY ud.user_id = BINARY ?
                )
           ORDER BY i.message_seq DESC
              LIMIT 1',
            [$organization, $conversationId, $organization, $userId, $organization, $userId],
        )[0] ?? null;
        if ($visible === null) {
            $currentUpdated = 0;
            foreach ($homes as $homeOrganization) {
                $updated = (int) Db::execute(
                    'UPDATE im_conversation_member
                        SET unread_count = 0, update_time = ?
                      WHERE organization = ?
                        AND BINARY conversation_id = BINARY ?
                        AND member_organization = ?
                        AND BINARY user_id = BINARY ?
                        AND status = 1
                        AND access_state = "active"
                        AND delete_time IS NULL',
                    [$now, $homeOrganization, $conversationId, $organization, $userId],
                );
                if ($homeOrganization === $organization) {
                    $currentUpdated = $updated;
                }
            }

            return $currentUpdated;
        }

        $messageId = (string) $visible['message_id'];
        $messageSeq = (int) $visible['message_seq'];
        if ($messageId === '' || $messageSeq <= 0) {
            throw new \RuntimeException('Visible read cursor is invalid.');
        }

        $currentUpdated = 0;
        foreach ($homes as $homeOrganization) {
            $conversation = Db::query(
                'SELECT conversation_type
                   FROM im_conversation
                  WHERE organization = ?
                    AND BINARY conversation_id = BINARY ?
                    AND status = 1
                    AND delete_time IS NULL
                  LIMIT 1
                  FOR UPDATE',
                [$homeOrganization, $conversationId],
            )[0] ?? null;
            $homeMessage = Db::query(
                'SELECT message_id, message_seq
                   FROM im_message_index
                  WHERE organization = ?
                    AND BINARY conversation_id = BINARY ?
                    AND BINARY message_id = BINARY ?
                    AND message_seq = ?
                  LIMIT 1',
                [$homeOrganization, $conversationId, $messageId, $messageSeq],
            )[0] ?? null;
            if ($conversation === null || $homeMessage === null) {
                throw new \RuntimeException('Dual-home read projection is incomplete.');
            }

            $readState = $this->advanceConversationReadHome(
                $homeOrganization,
                $organization,
                $userId,
                $conversationId,
                $messageId,
                $messageSeq,
                $now,
            );
            $this->markConversationReceiptsRead(
                $homeOrganization,
                $organization,
                $userId,
                $conversationId,
                $readState['last_read_seq'],
                $now,
            );
            $this->appendConversationReadOutbox(
                $homeOrganization,
                $organization,
                $userId,
                $conversationId,
                (int) $conversation['conversation_type'],
                $readState,
                $accessSnapshotId,
                $now,
            );
            if ($homeOrganization === $organization) {
                $currentUpdated = $readState['updated'];
            }
        }

        return $currentUpdated;
    }

    /**
     * @return array{updated:int,last_read_message_id:string,last_read_seq:int,unread_count:int}
     */
    private function advanceConversationReadHome(
        int $homeOrganization,
        int $userOrganization,
        string $userId,
        string $conversationId,
        string $requestedMessageId,
        int $requestedSeq,
        string $now,
    ): array {
        $member = Db::query(
            'SELECT id, last_read_message_id, last_read_seq
               FROM im_conversation_member
              WHERE organization = ?
                AND BINARY conversation_id = BINARY ?
                AND member_organization = ?
                AND BINARY user_id = BINARY ?
                AND status = 1
                AND access_state = "active"
                AND delete_time IS NULL
              LIMIT 1
              FOR UPDATE',
            [$homeOrganization, $conversationId, $userOrganization, $userId],
        )[0] ?? null;
        if ($member === null) {
            throw new \RuntimeException('Dual-home reader membership is missing.');
        }
        $currentSeq = max(0, (int) ($member['last_read_seq'] ?? 0));
        $effectiveSeq = max($currentSeq, $requestedSeq);
        $effectiveMessageId = $requestedSeq > $currentSeq
            ? $requestedMessageId
            : trim((string) ($member['last_read_message_id'] ?? ''));
        if ($effectiveMessageId === '') {
            $effectiveMessageId = $requestedMessageId;
        }
        $cursor = Db::query(
            'SELECT message_id
               FROM im_message_index
              WHERE organization = ?
                AND BINARY conversation_id = BINARY ?
                AND message_seq = ?
                AND BINARY message_id = BINARY ?
              LIMIT 1',
            [$homeOrganization, $conversationId, $effectiveSeq, $effectiveMessageId],
        )[0] ?? null;
        if ($cursor === null) {
            throw new \RuntimeException('Dual-home effective read cursor is missing.');
        }

        $unread = Db::query(
            'SELECT COUNT(*) AS aggregate
               FROM im_message_index i
              WHERE i.organization = ?
                AND BINARY i.conversation_id = BINARY ?
                AND i.message_seq > ?
                AND NOT (i.sender_organization = ? AND BINARY i.sender_id = BINARY ?)
                AND EXISTS (
                    SELECT 1 FROM im_conversation_membership_period mp
                     WHERE mp.organization = i.organization
                       AND BINARY mp.conversation_id = BINARY i.conversation_id
                       AND mp.member_organization = ?
                       AND BINARY mp.user_id = BINARY ?
                       AND mp.status = 1
                       AND i.message_seq >= mp.visible_from_message_seq
                       AND (mp.visible_until_message_seq IS NULL OR i.message_seq <= mp.visible_until_message_seq)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM im_message_user_delete ud
                     WHERE ud.organization = i.organization
                       AND BINARY ud.message_id = BINARY i.message_id
                       AND ud.user_organization = ?
                       AND BINARY ud.user_id = BINARY ?
                )
                AND NOT EXISTS (
                    SELECT 1 FROM im_message_change mc
                     WHERE mc.organization = i.organization
                       AND BINARY mc.message_id = BINARY i.message_id
                       AND mc.change_type = "delete_both"
                )',
            [
                $homeOrganization,
                $conversationId,
                $effectiveSeq,
                $userOrganization,
                $userId,
                $userOrganization,
                $userId,
                $userOrganization,
                $userId,
            ],
        )[0] ?? ['aggregate' => 0];
        $unreadCount = max(0, (int) ($unread['aggregate'] ?? 0));
        $updated = (int) Db::execute(
            'UPDATE im_conversation_member
                SET last_read_message_id = ?,
                    last_read_seq = ?,
                    unread_count = ?,
                    update_time = ?
              WHERE id = ?
                AND organization = ?
                AND member_organization = ?
                AND BINARY user_id = BINARY ?
                AND status = 1
                AND access_state = "active"
                AND delete_time IS NULL',
            [
                $effectiveMessageId,
                $effectiveSeq,
                $unreadCount,
                $now,
                (int) $member['id'],
                $homeOrganization,
                $userOrganization,
                $userId,
            ],
        );

        return [
            'updated' => $updated,
            'last_read_message_id' => $effectiveMessageId,
            'last_read_seq' => $effectiveSeq,
            'unread_count' => $unreadCount,
        ];
    }

    private function markConversationReceiptsRead(
        int $homeOrganization,
        int $userOrganization,
        string $userId,
        string $conversationId,
        int $lastReadSeq,
        string $now,
    ): void {
        Db::execute(
            'INSERT INTO im_message_receipt
                (organization, conversation_id, message_id, user_id, user_organization,
                 status, delivered_time, read_time, create_time, update_time)
             SELECT ?, i.conversation_id, i.message_id, ?, ?, 3, ?, ?, ?, ?
               FROM im_message_index i
              WHERE i.organization = ?
                AND BINARY i.conversation_id = BINARY ?
                AND i.message_seq <= ?
                AND EXISTS (
                    SELECT 1 FROM im_conversation_membership_period mp
                     WHERE mp.organization = i.organization
                       AND BINARY mp.conversation_id = BINARY i.conversation_id
                       AND mp.member_organization = ?
                       AND BINARY mp.user_id = BINARY ?
                       AND mp.status = 1
                       AND i.message_seq >= mp.visible_from_message_seq
                       AND (mp.visible_until_message_seq IS NULL OR i.message_seq <= mp.visible_until_message_seq)
                )
             ON DUPLICATE KEY UPDATE
                delivered_time = COALESCE(delivered_time, VALUES(delivered_time)),
                read_time = COALESCE(read_time, VALUES(read_time)),
                update_time = VALUES(update_time)',
            [
                $homeOrganization,
                $userId,
                $userOrganization,
                $now,
                $now,
                $now,
                $now,
                $homeOrganization,
                $conversationId,
                $lastReadSeq,
                $userOrganization,
                $userId,
            ],
        );
    }

    /**
     * @param array{updated:int,last_read_message_id:string,last_read_seq:int,unread_count:int} $readState
     */
    private function appendConversationReadOutbox(
        int $homeOrganization,
        int $userOrganization,
        string $userId,
        string $conversationId,
        int $conversationType,
        array $readState,
        ?string $accessSnapshotId,
        string $now,
    ): void {
        if ($accessSnapshotId !== null
            && preg_match('/^[1-9][0-9]{0,19}$/D', $accessSnapshotId) !== 1) {
            throw new \InvalidArgumentException('conversation.read access snapshot is invalid');
        }
        $members = Db::query(
            'SELECT member_organization, user_id
               FROM im_conversation_member
              WHERE organization = ?
                AND BINARY conversation_id = BINARY ?
                AND member_organization = ?
                AND status = 1
                AND access_state = "active"
                AND delete_time IS NULL
           ORDER BY user_id ASC',
            [$homeOrganization, $conversationId, $homeOrganization],
        );
        $recipientIdentities = array_values(array_map(
            static fn (array $member): array => [
                'organization' => (int) $member['member_organization'],
                'user_id' => (string) $member['user_id'],
            ],
            $members,
        ));
        $eventIdTuple = [
            $homeOrganization,
            'conversation.read',
            $conversationId,
            $userOrganization,
            $userId,
            $readState['last_read_seq'],
        ];
        $clientIdTuple = [
            $userOrganization,
            $userId,
            $conversationId,
            $readState['last_read_seq'],
        ];
        if ($accessSnapshotId !== null) {
            $eventIdTuple[] = $accessSnapshotId;
            $clientIdTuple[] = $accessSnapshotId;
        }
        $eventId = hash('sha256', implode('|', $eventIdTuple));
        $clientId = 'web-http-read-' . substr(hash(
            'sha256',
            implode('|', $clientIdTuple),
        ), 0, 32);
        $projectedReadState = [
            'conversation_id' => $conversationId,
            'last_read_message_id' => $readState['last_read_message_id'],
            'last_read_seq' => $readState['last_read_seq'],
            'unread_count' => $readState['unread_count'],
            'user_organization' => $userOrganization,
            'user_id' => $userId,
            'time' => $now,
            ...($accessSnapshotId === null
                ? []
                : ['cross_org_access_snapshot_id' => $accessSnapshotId]),
        ];
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'conversation.read',
            'organization' => $homeOrganization,
            'conversation_id' => $conversationId,
            'conversation_type' => $conversationType,
            'message_id' => $readState['last_read_message_id'],
            'message_seq' => $readState['last_read_seq'],
            'change_seq' => 0,
            'user_organization' => $userOrganization,
            'user_id' => $userId,
            'actor_organization' => $userOrganization,
            'actor_user_id' => $userId,
            'origin_organization' => $userOrganization,
            'origin_user_id' => $userId,
            'origin_client_id' => $clientId,
            'recipient_count' => count($recipientIdentities),
            'recipient_identities' => $recipientIdentities,
            'read_state' => $projectedReadState,
            'created_at' => $now,
            ...($accessSnapshotId === null
                ? []
                : ['cross_org_access_snapshot_id' => $accessSnapshotId]),
        ];
        $traceHeaders = Telemetry::currentTraceHeaders();
        Db::execute(
            'INSERT INTO im_message_outbox
                (event_id, organization, event_type, routing_key, message_id, change_seq,
                 conversation_id, conversation_type, payload_json, traceparent, tracestate,
                 status, retry_count, next_retry_at, create_time, update_time)
             VALUES (?, ?, "conversation.read", "conversation.read", ?, 0, ?, ?, ?, ?, ?,
                     1, 0, ?, ?, ?)
             ON DUPLICATE KEY UPDATE event_id = VALUES(event_id)',
            [
                $eventId,
                $homeOrganization,
                $readState['last_read_message_id'],
                $conversationId,
                $conversationType,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $traceHeaders['traceparent'] ?? null,
                $traceHeaders['tracestate'] ?? null,
                $now,
                $now,
                $now,
            ],
        );
    }

    /** @return array<string, mixed>|null */
    private function conversationRow(int $organization, string $userId, string $conversationId): ?array
    {
        return Db::query(
            'SELECT c.*, gp.description, cm.unread_count, cm.is_pinned, cm.is_muted,
                    cm.conversation_remark, cm.message_group_id,
                    mg.name AS message_group_name, mi.id AS last_message_index_id,
                    COALESCE(c.last_message_time, c.create_time) AS sort_time
               FROM im_conversation_member cm
         INNER JOIN im_conversation c
                 ON c.organization = cm.organization
                AND BINARY c.conversation_id = BINARY cm.conversation_id
          LEFT JOIN im_group_profile gp
                 ON gp.organization = c.organization
                AND BINARY gp.conversation_id = BINARY c.conversation_id
                AND gp.status = 1
                AND gp.delete_time IS NULL
          LEFT JOIN im_message_group mg
                 ON mg.organization = cm.organization
                AND BINARY mg.user_id = BINARY cm.user_id
                AND mg.id = cm.message_group_id
                AND mg.status = 1
                AND mg.delete_time IS NULL
          LEFT JOIN im_message_index mi
                 ON mi.organization = c.organization
                AND BINARY mi.message_id = BINARY c.last_message_id
              WHERE cm.organization = ?
                AND cm.member_organization = ?
                AND BINARY cm.user_id = BINARY ?
                AND BINARY cm.conversation_id = BINARY ?
                AND cm.status = 1
                AND cm.access_state = "active"
                AND cm.delete_time IS NULL
                AND c.status = 1
                AND c.delete_time IS NULL
              LIMIT 1',
            [$organization, $organization, $userId, $conversationId],
        )[0] ?? null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatConversation(int $organization, string $userId, array $row): array
    {
        $type = (int) $row['conversation_type'];
        $access = (new WebImConversationAccessGuard())->assertReadable(
            $organization,
            $userId,
            (string) $row['conversation_id'],
        );
        if ($access['conversation_type'] !== $type) {
            throw new \RuntimeException('Conversation projection type does not match persisted topology.');
        }
        $activeAccess = (bool) ($access['is_active'] ?? true);
        $peer = null;
        $friendRemark = '';
        if ($type === self::CONVERSATION_SINGLE) {
            $peerIdentity = $access['peer_identity'];
            if (!is_array($peerIdentity)) {
                throw new \RuntimeException('Single conversation peer identity is missing.');
            }
            $peerRow = Db::query(
                'SELECT u.*, COALESCE(p.signature, "") AS signature,
                        u.organization AS peer_organization,
                        COALESCE(org.organization_name, org.title, "") AS organization_name,
                        COALESCE(fr.remark_name, "") AS friend_remark
                   FROM im_user u
              LEFT JOIN im_friend_relation fr
                     ON fr.organization = ?
                    AND fr.user_id = ?
                    AND fr.friend_organization = u.organization
                    AND fr.friend_user_id = u.user_id
                    AND fr.status = 1
                    AND fr.delete_time IS NULL
              LEFT JOIN im_user_profile p
                     ON p.organization = u.organization
                    AND p.user_id = u.user_id
                    AND p.status = 1
                    AND p.delete_time IS NULL
             INNER JOIN sm_system_organization org
                     ON org.id = u.organization
                    AND org.status = 1
                    AND org.delete_time IS NULL
                  WHERE u.organization = ?
                    AND u.user_id = ?
                    AND u.delete_time IS NULL
                  LIMIT 1',
                [
                    $organization,
                    $userId,
                    $peerIdentity['organization'],
                    $peerIdentity['user_id'],
                ],
            )[0] ?? null;
            if ($peerRow === null) {
                throw new \RuntimeException('Single conversation peer identity does not resolve to a user.');
            }
            $friendRemark = (string) ($peerRow['friend_remark'] ?? '');
            $relationStatus = $this->areFriends(
                $organization,
                $userId,
                $peerIdentity['organization'],
                $peerIdentity['user_id'],
            )
                ? 'friend'
                : 'none';
            $peer = $this->userView($peerRow, $friendRemark, $relationStatus, $organization);
        }
        $conversationRemark = $activeAccess ? (string) ($row['conversation_remark'] ?? '') : '';
        $title = $type === self::CONVERSATION_SINGLE
            ? ($friendRemark !== ''
                ? $friendRemark
                : (string) ($peer['display_name'] ?? $peer['nickname'] ?? '单聊'))
            : (!$activeAccess
                ? '群聊'
                : ($conversationRemark !== '' ? $conversationRemark : ((string) ($row['title'] ?? '') ?: '群聊')));
        $last = $this->visibleConversationLastState(
            $organization,
            $userId,
            (string) $row['conversation_id'],
            (string) ($row['create_time'] ?? ''),
        );

        return [
            'organization' => $organization,
            'conversation_id' => (string) $row['conversation_id'],
            'conversation_sort_id' => (int) $row['id'],
            'conversation_type' => $type,
            'title' => $title,
            'avatar' => $type === self::CONVERSATION_SINGLE
                ? (string) ($peer['avatar_file_id'] ?? '')
                : ($activeAccess ? (string) ($row['avatar'] ?? '') : ''),
            'description' => $type === self::CONVERSATION_GROUP && $activeAccess
                ? (string) ($row['description'] ?? '')
                : '',
            'avatar_members' => $type === self::CONVERSATION_GROUP && $activeAccess
                ? $this->groupAvatarMembers($organization, (string) $row['conversation_id'])
                : [],
            'peer_user' => $peer,
            'last_message_id' => $last['message_id'],
            'last_message_seq' => $last['message_seq'],
            'last_message_index_id' => $last['index_id'],
            'last_message_summary' => $last['summary'],
            'last_message_time' => $last['message_time'],
            'sort_time' => $last['sort_time'],
            'unread_count' => $activeAccess ? (int) ($row['unread_count'] ?? 0) : 0,
            'is_pinned' => $activeAccess && (int) ($row['is_pinned'] ?? 2) === 1,
            'is_muted' => $activeAccess && (int) ($row['is_muted'] ?? 2) === 1,
            'message_group_id' => $activeAccess ? (int) ($row['message_group_id'] ?? 0) : 0,
            'message_group_name' => $activeAccess ? (string) ($row['message_group_name'] ?? '') : '',
            'access_version' => $type === self::CONVERSATION_GROUP
                ? (string) ($row['access_version'] ?? $access['member']['access_version'] ?? '')
                : null,
            'access_state' => $type === self::CONVERSATION_GROUP
                ? (string) ($row['access_state'] ?? $access['member']['access_state'] ?? '')
                : null,
            'periods' => $type === self::CONVERSATION_GROUP ? ($access['periods'] ?? []) : [],
        ];
    }

    /** @return array{message_id: string, message_seq: int, index_id: int, summary: string, message_time: string, sort_time: string} */
    private function visibleConversationLastState(
        int $organization,
        string $userId,
        string $conversationId,
        string $conversationCreatedAt,
    ): array {
        $candidates = Db::query(
            'SELECT i.id, i.message_id, i.message_seq, i.shard_table,
                    i.sender_id, i.sender_organization
               FROM im_message_index i
              WHERE i.organization = ?
                AND BINARY i.conversation_id = BINARY ?
                AND EXISTS (
                    SELECT 1 FROM im_conversation_membership_period mp
                     WHERE mp.organization = i.organization
                       AND BINARY mp.conversation_id = BINARY i.conversation_id
                       AND mp.member_organization = ?
                       AND BINARY mp.user_id = BINARY ?
                       AND mp.status = 1
                       AND i.message_seq >= mp.visible_from_message_seq
                       AND (mp.visible_until_message_seq IS NULL OR i.message_seq <= mp.visible_until_message_seq)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM im_message_user_delete ud
                     WHERE ud.organization = i.organization
                       AND BINARY ud.conversation_id = BINARY i.conversation_id
                       AND BINARY ud.message_id = BINARY i.message_id
                       AND ud.user_organization = ?
                       AND BINARY ud.user_id = BINARY ?
                )
           ORDER BY i.message_seq DESC
              LIMIT 50',
            [$organization, $conversationId, $organization, $userId, $organization, $userId],
        );
        foreach ($candidates as $candidate) {
            $message = Db::query(
                'SELECT * FROM ' . $this->quoteShard((string) $candidate['shard_table']) . '
                  WHERE organization = ? AND BINARY conversation_id = BINARY ?
                    AND BINARY message_id = BINARY ? LIMIT 1',
                [$organization, $conversationId, (string) $candidate['message_id']],
            )[0] ?? null;
            if ($message === null) {
                throw new \RuntimeException('IM message index points to a missing shard body.');
            }
            $this->assertIndexedMessageBinding($candidate, $message, $conversationId);
            if (($message['delete_time'] ?? null) !== null || (int) ($message['status'] ?? 0) === 3) {
                continue;
            }
            $messageTime = (string) ($message['create_time'] ?? '');

            return [
                'message_id' => (string) $message['message_id'],
                'message_seq' => (int) $message['message_seq'],
                'index_id' => (int) $candidate['id'],
                'summary' => $this->messageSummary($message),
                'message_time' => $messageTime,
                'sort_time' => $messageTime !== '' ? $messageTime : $conversationCreatedAt,
            ];
        }

        return [
            'message_id' => '',
            'message_seq' => 0,
            'index_id' => 0,
            'summary' => '',
            'message_time' => '',
            'sort_time' => $conversationCreatedAt,
        ];
    }

    /** @param array<string, mixed> $message */
    private function messageSummary(array $message): string
    {
        if ((int) ($message['status'] ?? 0) === 2) {
            return '消息已撤回';
        }
        $content = json_decode((string) ($message['content'] ?? '{}'), true);
        $content = is_array($content) ? $content : [];

        return match ((int) ($message['message_type'] ?? 0)) {
            1 => mb_substr((string) ($content['text'] ?? ''), 0, 120),
            2 => '[图片]',
            3 => '[文件]',
            4 => '[语音]',
            5 => mb_substr((string) ($content['text'] ?? '[系统通知]'), 0, 120),
            11 => '[视频]',
            default => '[消息]',
        };
    }

    /** @return list<array<string, mixed>> */
    private function groupAvatarMembers(int $organization, string $conversationId): array
    {
        $rows = Db::query(
            'SELECT u.*, COALESCE(p.signature, "") AS signature
               FROM im_conversation_member cm
         INNER JOIN im_user u
                 ON u.organization = cm.member_organization
                AND BINARY u.user_id = BINARY cm.user_id
          LEFT JOIN im_user_profile p
                 ON p.organization = u.organization
                AND BINARY p.user_id = BINARY u.user_id
                AND p.status = 1
                AND p.delete_time IS NULL
              WHERE cm.organization = ?
                AND BINARY cm.conversation_id = BINARY ?
                AND cm.status = 1
                AND cm.access_state = "active"
                AND cm.delete_time IS NULL
                AND u.delete_time IS NULL
           ORDER BY FIELD(cm.member_role, "owner", "admin", "member"), cm.id ASC
              LIMIT 4',
            [$organization, $conversationId],
        );

        return array_map(fn (array $row): array => $this->userView($row), $rows);
    }

    /** @return list<array<string, mixed>> */
    private function groupMemberRows(
        int $organization,
        string $conversationId,
        bool $includeInactive,
    ): array
    {
        $sql = 'SELECT cm.member_role, cm.status AS membership_status,
                    cm.mute_status, cm.mute_until, cm.join_at,
                    cm.access_version, cm.access_state,
                    u.*, COALESCE(p.signature, "") AS signature
               FROM im_conversation_member cm
         INNER JOIN im_user u
                 ON u.organization = cm.member_organization
                AND BINARY u.user_id = BINARY cm.user_id
          LEFT JOIN im_user_profile p
                 ON p.organization = u.organization
                AND BINARY p.user_id = BINARY u.user_id
                AND p.status = 1
                AND p.delete_time IS NULL
              WHERE cm.organization = ?
                AND BINARY cm.conversation_id = BINARY ?
                AND cm.delete_time IS NULL
                AND u.delete_time IS NULL';
        if (!$includeInactive) {
            $sql .= ' AND cm.status = 1 AND cm.access_state = "active"';
        }
        $sql .= ' ORDER BY FIELD(cm.member_role, "owner", "admin", "member"), cm.id ASC';
        $rows = Db::query($sql, [$organization, $conversationId]);

        return array_map(function (array $row): array {
            $muteStatus = (int) ($row['mute_status'] ?? 0);

            return [
                'user' => $this->userView($row),
                'role' => match ((string) $row['member_role']) {
                    'owner' => 2,
                    'admin' => 3,
                    default => 1,
                },
                'status' => $muteStatus === 1 ? 2 : 1,
                'mute_until' => $muteStatus === 1 ? (string) ($row['mute_until'] ?? '') : '',
                'join_time' => (string) ($row['join_at'] ?? ''),
                'membership_status' => (int) $row['membership_status'],
                'access_version' => (string) $row['access_version'],
                'access_state' => (string) $row['access_state'],
            ];
        }, $rows);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function userView(
        array $row,
        string $remark = '',
        string $relationStatus = 'none',
        ?int $viewerOrganization = null,
    ): array {
        $status = (int) ($row['status'] ?? 0);
        $peerOrganization = $this->requiredOrganization($row, 'organization', '用户投影');
        if (array_key_exists('peer_organization', $row)
            && (int) $row['peer_organization'] !== $peerOrganization) {
            throw new \RuntimeException('用户投影机构身份不一致。');
        }
        $companyName = trim((string) ($row['organization_name'] ?? ''));
        if ($companyName === '' && $peerOrganization > 0) {
            $org = Db::query(
                'SELECT COALESCE(organization_name, title, "") AS organization_name
                   FROM sm_system_organization
                  WHERE id = ? AND delete_time IS NULL
                  LIMIT 1',
                [$peerOrganization],
            )[0] ?? null;
            $companyName = trim((string) ($org['organization_name'] ?? ''));
        }
        $nickname = (string) ($row['nickname'] ?? '');
        $account = (string) ($row['account'] ?? '');
        $viewerOrg = $viewerOrganization ?? $peerOrganization;
        $displayName = CrossOrganizationSocialPolicy::contactDisplayName(
            $nickname,
            $account,
            (int) $viewerOrg,
            $peerOrganization,
            $companyName,
        );
        $isCrossOrg = $viewerOrg > 0 && $peerOrganization > 0 && $viewerOrg !== $peerOrganization;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'user_id' => (string) ($row['user_id'] ?? ''),
            'account' => $account,
            'nickname' => $nickname,
            'display_name' => $displayName,
            'organization' => $peerOrganization,
            'organization_name' => $isCrossOrg ? $companyName : '',
            'company_name' => $isCrossOrg ? $companyName : '',
            'is_cross_organization' => $isCrossOrg,
            'signature' => (string) ($row['signature'] ?? ''),
            'avatar_file_id' => (string) ($row['avatar'] ?? ''),
            'avatar_url' => '',
            'avatar_expires_at' => 0,
            'mobile' => (string) ($row['mobile'] ?? ''),
            'im_short_no' => (string) ($row['im_short_no'] ?? ''),
            'gender' => (int) ($row['gender'] ?? 0),
            'status' => $status,
            'status_text' => match ($status) {
                2 => '停用',
                3 => '封禁',
                default => '正常',
            },
            'remark' => $remark !== '' ? $remark : (string) ($row['remark'] ?? ''),
            'login_time' => (string) ($row['login_time'] ?? ''),
            'relation_status' => $relationStatus,
            'is_system' => (int) ($row['is_system'] ?? 2) === 1,
            'system_code' => (string) ($row['system_code'] ?? ''),
        ];
    }

    /** @param list<array<string, mixed>> $candidates @return list<array<string, mixed>> */
    private function loadIndexedMessageBodies(
        int $organization,
        string $conversationId,
        array $candidates,
        string $viewerUserId,
        bool $includeSenderUser,
    ): array {
        if ($candidates === []) {
            return [];
        }
        $byTable = [];
        foreach ($candidates as $candidate) {
            $table = (string) $candidate['shard_table'];
            $this->quoteShard($table);
            $byTable[$table][] = (string) $candidate['message_id'];
        }

        $bodies = [];
        foreach ($byTable as $table => $messageIds) {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $rows = Db::query(
                'SELECT * FROM ' . $this->quoteShard($table) . '
                  WHERE organization = ?
                    AND BINARY conversation_id = BINARY ?
                    AND BINARY message_id IN (' . $placeholders . ')',
                array_merge([$organization, $conversationId], $messageIds),
            );
            foreach ($rows as $row) {
                $bodies[(string) $row['message_id']] = $row;
            }
        }

        $messages = [];
        foreach ($candidates as $candidate) {
            $messageId = (string) $candidate['message_id'];
            if (!isset($bodies[$messageId])) {
                throw new \RuntimeException('IM message index points to a missing shard body.');
            }
            $body = $bodies[$messageId];
            $this->assertIndexedMessageBinding($candidate, $body, $conversationId);
            if (($body['delete_time'] ?? null) !== null || (int) ($body['status'] ?? 0) === 3) {
                continue;
            }
            $body['global_seq'] = (string) ($candidate['global_seq'] ?? '');
            $messages[] = $this->formatMessage($body, $organization, $includeSenderUser);
        }

        $outgoingMessageIds = [];
        foreach ($messages as $message) {
            if ((int) $message['sender_organization'] === $organization
                && hash_equals((string) $message['sender_id'], $viewerUserId)) {
                $outgoingMessageIds[] = (string) $message['message_id'];
            }
        }
        $deliveryStatuses = $this->outgoingDeliveryStatuses(
            $organization,
            $viewerUserId,
            $outgoingMessageIds,
        );
        foreach ($messages as &$message) {
            $messageId = (string) $message['message_id'];
            $message['delivery_status'] = (int) $message['sender_organization'] === $organization
                && hash_equals((string) $message['sender_id'], $viewerUserId)
                ? ($deliveryStatuses[$messageId] ?? 'sent')
                : '';
        }
        unset($message);

        return $messages;
    }

    /** @param array<string,mixed> $index @param array<string,mixed> $body */
    private function assertIndexedMessageBinding(
        array $index,
        array $body,
        string $conversationId,
    ): void {
        if (!hash_equals($conversationId, (string) ($body['conversation_id'] ?? ''))
            || !hash_equals((string) ($index['message_id'] ?? ''), (string) ($body['message_id'] ?? ''))
            || (string) ($index['message_seq'] ?? '') !== (string) ($body['message_seq'] ?? '')
            || (int) ($index['sender_organization'] ?? 0) !== (int) ($body['sender_organization'] ?? 0)
            || !hash_equals((string) ($index['sender_id'] ?? ''), (string) ($body['sender_id'] ?? ''))) {
            throw new \RuntimeException('IM message index and shard body binding is inconsistent.');
        }
    }

    /** @param list<string> $messageIds @return array<string, string> */
    private function outgoingDeliveryStatuses(
        int $organization,
        string $senderUserId,
        array $messageIds,
    ): array {
        if ($messageIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $rows = Db::query(
            'SELECT recipient.message_id, MIN(recipient.max_status) AS delivery_status
               FROM (
                    SELECT r.message_id, r.user_id, MAX(r.status) AS max_status
                      FROM im_message_receipt r
                     WHERE r.organization = ?
                       AND BINARY r.message_id IN (' . $placeholders . ')
                       AND (
                           r.user_organization <> ?
                           OR BINARY r.user_id <> BINARY ?
                       )
                  GROUP BY r.message_id, r.user_organization, r.user_id
               ) recipient
           GROUP BY recipient.message_id',
            array_merge([$organization], $messageIds, [$organization, $senderUserId]),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['message_id']] = match ((int) $row['delivery_status']) {
                3 => 'read',
                2 => 'delivered',
                default => 'sent',
            };
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function formatMessage(
        array $message,
        ?int $viewerOrganization = null,
        bool $includeSenderUser = true,
    ): array
    {
        $status = (int) ($message['status'] ?? 0);
        $content = json_decode((string) ($message['content'] ?? '{}'), true);
        $senderId = (string) ($message['sender_id'] ?? '');
        $senderOrganization = (int) ($message['sender_organization'] ?? 0);
        if ($senderOrganization <= 0) {
            throw new \RuntimeException('IM message is missing sender_organization.');
        }
        $messageOrganization = $this->requiredOrganization($message, 'organization', '消息投影');
        $globalSeq = trim((string) ($message['global_seq'] ?? ''));
        if (preg_match('/^[1-9][0-9]*$/', $globalSeq) !== 1) {
            throw new \RuntimeException('IM message is missing a valid home global_seq.');
        }
        $viewerOrg = $viewerOrganization !== null && $viewerOrganization > 0
            ? $viewerOrganization
            : $messageOrganization;
        $sender = $includeSenderUser
            ? $this->resolveMessageSender($senderOrganization, $senderId)
            : null;

        return [
            'id' => (int) $message['id'],
            'organization' => $messageOrganization,
            'conversation_id' => (string) $message['conversation_id'],
            'conversation_type' => (int) $message['conversation_type'],
            'message_id' => (string) $message['message_id'],
            'message_seq' => (int) $message['message_seq'],
            'global_seq' => $globalSeq,
            'client_msg_id' => (string) $message['client_msg_id'],
            'sender_organization' => $senderOrganization,
            'sender_id' => $senderId,
            'sender_user' => $sender !== null
                ? $this->userView($sender, '', 'none', $viewerOrg)
                : null,
            'message_type' => (int) $message['message_type'],
            'content' => $status === 1 && is_array($content) ? $content : [],
            'status' => $status,
            'edit_time' => (string) ($message['edit_time'] ?? ''),
            'edit_count' => (int) ($message['edit_count'] ?? 0),
            'create_time' => (string) ($message['create_time'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMessageSender(int $senderOrganization, string $senderId): ?array
    {
        if ($senderId === '') {
            return null;
        }
        if ($senderOrganization <= 0) {
            throw new \RuntimeException('IM message sender_organization is invalid.');
        }

        return $this->userById($senderOrganization, $senderId, false);
    }

    /** @return array<string, mixed> */
    private function assertActiveConversationMember(
        int $organization,
        string $conversationId,
        string $userId,
        bool $lock = false,
    ): array {
        return (new WebImConversationAccessGuard())->assertAccessible(
            $organization,
            $userId,
            $conversationId,
            $lock,
        )['member'];
    }

    private function isCrossOrganizationConversation(string $conversationId): bool
    {
        return (Db::query(
            'SELECT 1 AS present
               FROM im_cross_organization_conversation
              WHERE BINARY conversation_id = BINARY ?
              LIMIT 1',
            [$conversationId],
        )[0] ?? null) !== null
            || (Db::query(
                'SELECT 1 AS present
                   FROM im_conversation_member
                  WHERE BINARY conversation_id = BINARY ?
                    AND member_organization <> organization
                    AND status = 1
                    AND delete_time IS NULL
                  LIMIT 1',
                [$conversationId],
            )[0] ?? null) !== null;
    }

    private function assertMembershipPeriod(int $organization, string $conversationId, string $userId): void
    {
        $period = Db::query(
            'SELECT id FROM im_conversation_membership_period
              WHERE organization = ?
                AND BINARY conversation_id = BINARY ?
                AND member_organization = ?
                AND BINARY user_id = BINARY ?
                AND status = 1
              LIMIT 1',
            [$organization, $conversationId, $organization, $userId],
        )[0] ?? null;
        if ($period === null) {
            throw new ApiException('该会话没有可见周期。', 403);
        }
    }

    /** @param list<string> $roles @return array<string, mixed> */
    private function assertGroupRole(
        int $organization,
        string $conversationId,
        string $userId,
        array $roles,
        bool $lock = false,
    ): array {
        $access = (new WebImConversationAccessGuard())->assertAccessible(
            $organization,
            $userId,
            $conversationId,
            $lock,
        );
        if ($access['conversation_type'] !== self::CONVERSATION_GROUP) {
            throw new ApiException('群聊不存在或无访问权限。', 403);
        }
        $sql = 'SELECT cm.*, gp.owner_user_id, gp.history_visibility,
                       gp.description AS group_description,
                       c.next_message_seq, c.last_message_seq
                  FROM im_conversation_member cm
            INNER JOIN im_conversation c
                    ON c.organization = cm.organization
                   AND BINARY c.conversation_id = BINARY cm.conversation_id
                   AND c.conversation_type = 2
                   AND c.status = 1
                   AND c.delete_time IS NULL
            INNER JOIN im_group_profile gp
                    ON gp.organization = c.organization
                   AND BINARY gp.conversation_id = BINARY c.conversation_id
                   AND gp.status = 1
                   AND gp.delete_time IS NULL
                 WHERE cm.organization = ?
                   AND BINARY cm.conversation_id = BINARY ?
                   AND cm.member_organization = ?
                   AND BINARY cm.user_id = BINARY ?
                   AND cm.status = 1
                   AND cm.access_state = "active"
                   AND cm.delete_time IS NULL
                 LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
        $member = Db::query($sql, [$organization, $conversationId, $organization, $userId])[0] ?? null;
        if ($member === null) {
            throw new ApiException('群聊不存在或无访问权限。', 403);
        }
        if (!in_array((string) $member['member_role'], $roles, true)) {
            throw new ApiException('没有群管理权限。', 403);
        }

        return $member;
    }

    private function assertCanManageGroupMember(
        int $organization,
        string $conversationId,
        string $operatorUserId,
        string $memberUserId,
        bool $lock,
    ): void {
        if (hash_equals($operatorUserId, $memberUserId)) {
            throw new ApiException('不能对自己执行该操作。', 422);
        }
        $operator = $this->assertGroupRole(
            $organization,
            $conversationId,
            $operatorUserId,
            ['owner', 'admin'],
            $lock,
        );
        $target = $this->groupMemberForUpdate($organization, $conversationId, $memberUserId, $lock);
        if ($target === null || (int) $target['status'] !== 1 || $target['delete_time'] !== null) {
            throw new ApiException('群成员不存在。', 404);
        }
        $operatorRole = (string) $operator['member_role'];
        $targetRole = (string) $target['member_role'];
        if ($targetRole === 'owner' || ($operatorRole === 'admin' && $targetRole !== 'member')) {
            throw new ApiException('没有管理该成员的权限。', 403);
        }
    }

    /** @return array<string, mixed>|null */
    private function groupMemberForUpdate(
        int $organization,
        string $conversationId,
        string $userId,
        bool $lock = true,
    ): ?array {
        $sql = 'SELECT * FROM im_conversation_member
                 WHERE organization = ? AND BINARY conversation_id = BINARY ?
                   AND member_organization = ? AND BINARY user_id = BINARY ?
                 LIMIT 1' . ($lock ? ' FOR UPDATE' : '');

        return Db::query($sql, [$organization, $conversationId, $organization, $userId])[0] ?? null;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function activeUserPairForUpdate(
        int $leftOrganization,
        string $leftUserId,
        int $rightOrganization,
        string $rightUserId,
    ): array {
        $leftIdentity = SingleConversationIdentity::identity(
            $leftOrganization,
            $leftUserId,
        );
        $rightIdentity = SingleConversationIdentity::identity(
            $rightOrganization,
            $rightUserId,
        );
        if (hash_equals($leftIdentity, $rightIdentity)) {
            $user = $this->activeUserForUpdate(
                $leftOrganization,
                $leftUserId,
                false,
            );

            return [$user, $user];
        }

        $users = [
            [
                'identity' => $leftIdentity,
                'organization' => $leftOrganization,
                'user_id' => $leftUserId,
            ],
            [
                'identity' => $rightIdentity,
                'organization' => $rightOrganization,
                'user_id' => $rightUserId,
            ],
        ];
        usort(
            $users,
            static fn (array $left, array $right): int => strcmp(
                $left['identity'],
                $right['identity'],
            ),
        );

        $locked = [];
        foreach ($users as $user) {
            $locked[$user['identity']] = $this->activeUserForUpdate(
                $user['organization'],
                $user['user_id'],
                false,
            );
        }

        return [$locked[$leftIdentity], $locked[$rightIdentity]];
    }

    /** @return array<string, mixed> */
    private function activeUserForUpdate(int $organization, string $userId, bool $allowSystem): array
    {
        $sql = 'SELECT u.*
                  FROM im_user u
                 WHERE u.organization = ?
                   AND u.user_id = ?
                   AND u.status = 1
                   AND u.delete_time IS NULL'
            . ($allowSystem ? '' : ' AND u.is_system = 2')
            . ' LIMIT 1 FOR UPDATE';
        $user = Db::query($sql, [$organization, $userId])[0] ?? null;
        if ($user === null) {
            throw new ApiException('用户不存在或已停用。', 404);
        }

        return $user;
    }

    /** @param list<string> $userIds @return array<string, array<string, mixed>> */
    private function activeUsersForUpdate(int $organization, array $userIds, bool $allowSystem): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = 'SELECT u.*
                  FROM im_user u
            INNER JOIN sm_system_organization org
                    ON org.id = u.organization
                   AND org.status = 1
                   AND org.delete_time IS NULL
                 WHERE u.organization = ?
                   AND u.user_id IN (' . $placeholders . ')
                   AND u.status = 1
                   AND u.delete_time IS NULL'
            . ($allowSystem ? '' : ' AND u.is_system = 2')
            . ' FOR UPDATE';
        $rows = Db::query($sql, array_merge([$organization], $userIds));
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['user_id']] = $row;
        }

        return $result;
    }

    private function areFriends(
        int $organization,
        string $userId,
        int $friendOrganization,
        string $friendUserId,
        bool $lock = false,
    ): bool {
        if ($lock || $organization !== $friendOrganization) {
            (new WebImConversationAccessGuard())->assertActiveOrganizations(
                [$organization, $friendOrganization],
                $lock,
            );
        }
        if ($lock) {
            $this->activeUserPairForUpdate(
                $organization,
                $userId,
                $friendOrganization,
                $friendUserId,
            );
        }
        $lockSql = $lock ? ' FOR UPDATE' : '';
        $friends = true;
        foreach (self::canonicalFriendDirections(
            $organization,
            $userId,
            $friendOrganization,
            $friendUserId,
        ) as $direction) {
            $relation = Db::query(
                'SELECT id, organization, user_id, friend_organization, friend_user_id
                   FROM im_friend_relation
                  WHERE organization = ?
                    AND user_id = ?
                    AND friend_organization = ?
                    AND friend_user_id = ?
                    AND status = 1
                    AND delete_time IS NULL
                  LIMIT 1' . $lockSql,
                [
                    $direction['organization'],
                    $direction['user_id'],
                    $direction['friend_organization'],
                    $direction['friend_user_id'],
                ],
            )[0] ?? null;
            // Do not short-circuit: a locking read must acquire both exact
            // directional rows or gaps in canonical order even when one is
            // absent.
            $friends = $friends && $relation !== null;
        }

        return $friends;
    }

    private function createFriendPair(
        int $organization,
        string $leftUserId,
        string $rightUserId,
        string $addMethod,
        string $now,
    ): void {
        $this->createFriendPairAcross($organization, $leftUserId, $organization, $rightUserId, $addMethod, $now);
    }

    private function createFriendPairAcross(
        int $leftOrganization,
        string $leftUserId,
        int $rightOrganization,
        string $rightUserId,
        string $addMethod,
        string $now,
    ): void {
        foreach (self::canonicalFriendDirections(
            $leftOrganization,
            $leftUserId,
            $rightOrganization,
            $rightUserId,
        ) as $direction) {
            $this->upsertFriend(
                $direction['organization'],
                $direction['user_id'],
                $direction['friend_user_id'],
                $direction['friend_organization'],
                $addMethod,
                $now,
            );
        }
    }

    /**
     * @return list<array{
     *   organization:int,
     *   user_id:string,
     *   friend_organization:int,
     *   friend_user_id:string
     * }>
     */
    private static function canonicalFriendDirections(
        int $leftOrganization,
        string $leftUserId,
        int $rightOrganization,
        string $rightUserId,
    ): array {
        $identities = [
            [
                'identity' => SingleConversationIdentity::identity($leftOrganization, $leftUserId),
                'organization' => $leftOrganization,
                'user_id' => $leftUserId,
            ],
            [
                'identity' => SingleConversationIdentity::identity($rightOrganization, $rightUserId),
                'organization' => $rightOrganization,
                'user_id' => $rightUserId,
            ],
        ];
        usort(
            $identities,
            static fn (array $left, array $right): int => strcmp(
                $left['identity'],
                $right['identity'],
            ),
        );

        return [
            [
                'organization' => $identities[0]['organization'],
                'user_id' => $identities[0]['user_id'],
                'friend_organization' => $identities[1]['organization'],
                'friend_user_id' => $identities[1]['user_id'],
            ],
            [
                'organization' => $identities[1]['organization'],
                'user_id' => $identities[1]['user_id'],
                'friend_organization' => $identities[0]['organization'],
                'friend_user_id' => $identities[0]['user_id'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pendingFriendRequestsForUpdate(
        int $leftOrganization,
        string $leftUserId,
        int $rightOrganization,
        string $rightUserId,
    ): array {
        $pending = [];
        foreach (self::canonicalFriendDirections(
            $leftOrganization,
            $leftUserId,
            $rightOrganization,
            $rightUserId,
        ) as $direction) {
            $rows = Db::query(
                'SELECT * FROM im_friend_request
                  WHERE from_organization = ?
                    AND from_user_id = ?
                    AND to_organization = ?
                    AND to_user_id = ?
                    AND status = 1
                    AND delete_time IS NULL
               ORDER BY id ASC
                  FOR UPDATE',
                [
                    $direction['organization'],
                    $direction['user_id'],
                    $direction['friend_organization'],
                    $direction['friend_user_id'],
                ],
            );
            array_push($pending, ...$rows);
        }

        return $pending;
    }

    private function upsertFriend(
        int $organization,
        string $userId,
        string $friendUserId,
        int $friendOrganization,
        string $addMethod,
        string $now,
    ): void {
        if ($friendOrganization <= 0) {
            throw new \InvalidArgumentException('friend_organization must be explicit.');
        }
        Db::execute(
            'INSERT INTO im_friend_relation
                (organization, user_id, friend_user_id, friend_organization, add_method, added_at, status, create_time, update_time)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                friend_organization = VALUES(friend_organization),
                add_method = VALUES(add_method),
                added_at = IF(status = 1 AND delete_time IS NULL, added_at, VALUES(added_at)),
                status = 1,
                delete_time = NULL,
                update_time = VALUES(update_time)',
            [$organization, $userId, $friendUserId, $friendOrganization, $addMethod, $now, $now, $now],
        );
    }

    /** @param list<string> $userIds @return array<string, array<string, mixed>> */
    private function usersByIds(int $organization, array $userIds, bool $activeOnly): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = 'SELECT u.*, COALESCE(p.signature, "") AS signature
                  FROM im_user u
            INNER JOIN sm_system_organization org
                    ON org.id = u.organization
                   AND org.status = 1
                   AND org.delete_time IS NULL
             LEFT JOIN im_user_profile p
                    ON p.organization = u.organization
                   AND p.user_id = u.user_id
                   AND p.status = 1
                   AND p.delete_time IS NULL
                 WHERE u.organization = ?
                   AND u.user_id IN (' . $placeholders . ')
                   AND u.delete_time IS NULL';
        if ($activeOnly) {
            $sql .= ' AND u.status = 1';
        }
        $rows = Db::query($sql, array_merge([$organization], $userIds));
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['user_id']] = $row;
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function userById(int $organization, string $userId, bool $activeOnly): ?array
    {
        if ($userId === '') {
            return null;
        }
        $users = $this->usersByIds($organization, [$userId], $activeOnly);

        return $users[$userId] ?? null;
    }

    private function conversationExists(int $organization, string $conversationId): bool
    {
        return (Db::query(
            'SELECT id FROM im_conversation
              WHERE organization = ? AND BINARY conversation_id = BINARY ?
                AND status = 1 AND delete_time IS NULL
              LIMIT 1',
            [$organization, $conversationId],
        )[0] ?? null) !== null;
    }

    /** @return array{messages: array<never>, next_after_seq: int, next_before_seq: int, has_more_before: bool} */
    private function emptyMessagePage(int $afterSeq, int $beforeSeq): array
    {
        return [
            'messages' => [],
            'next_after_seq' => $afterSeq,
            'next_before_seq' => $beforeSeq,
            'has_more_before' => false,
        ];
    }

    /** @param array<string, mixed> $row */
    private function requiredOrganization(array $row, string $key, string $context): int
    {
        $organization = (int) ($row[$key] ?? 0);
        if ($organization <= 0) {
            throw new \RuntimeException($context . '缺少必需机构字段 ' . $key . '。');
        }

        return $organization;
    }

    private function quoteShard(string $table): string
    {
        if (preg_match('/^im_message_\d{4}_\d{6}$/', $table) !== 1) {
            throw new \RuntimeException('IM message index contains an invalid shard table.');
        }

        return '`' . $table . '`';
    }

    private function likePattern(string $value): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
    }

    private function requestStatusText(int $status): string
    {
        return match ($status) {
            2 => '已通过',
            3 => '已拒绝',
            4 => '已取消',
            default => '待处理',
        };
    }
}
