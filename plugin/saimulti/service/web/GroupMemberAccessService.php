<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\trace\Telemetry;
use support\think\Db;

/** The only Server-side writer for group membership access facts. */
final class GroupMemberAccessService
{
    private const EVENT_TYPE = 'group.member_access_changed';
    private const GROUP = 2;
    private const UNSIGNED_BIGINT_MAX = '18446744073709551615';

    /** @param list<string> $memberIds */
    public function createGroup(
        int $organization,
        string $ownerUserId,
        string $title,
        array $memberIds,
        string $now,
    ): string {
        $memberIds = $this->canonicalUserIds($memberIds);
        return Db::transaction(function () use ($organization, $ownerUserId, $title, $memberIds, $now): string {
            $conversationId = 'group_' . bin2hex(random_bytes(16));
            Db::table('im_conversation')->insert([
                'organization' => $organization,
                'conversation_id' => $conversationId,
                'conversation_type' => self::GROUP,
                'title' => $title,
                'owner_user_id' => $ownerUserId,
                'owner_organization' => $organization,
                'status' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $conversation = $this->lockCreatedConversation($organization, $conversationId);
            Db::table('im_group_profile')->insert([
                'organization' => $organization,
                'conversation_id' => $conversationId,
                'owner_user_id' => $ownerUserId,
                'group_kind' => 'normal',
                'history_visibility' => 'since_join',
                'status' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $this->lockActiveUsers($organization, $memberIds);
            foreach ($memberIds as $memberId) {
                if ($memberId !== $ownerUserId) {
                    $this->assertFriends($organization, $ownerUserId, $memberId);
                }
            }
            foreach ($memberIds as $memberId) {
                Db::table('im_conversation_member')->insert([
                    'organization' => $organization,
                    'conversation_id' => $conversationId,
                    'user_id' => $memberId,
                    'member_organization' => $organization,
                    'member_role' => $memberId === $ownerUserId ? 'owner' : 'member',
                    'inviter_user_id' => $memberId === $ownerUserId ? null : $ownerUserId,
                    'inviter_organization' => $memberId === $ownerUserId ? 0 : $organization,
                    'status' => 1,
                    'mute_status' => 0,
                    'access_version' => 1,
                    'access_state' => 'active',
                    'join_at' => $now,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
            $lockedMembers = $this->lockMembers($organization, $conversationId, $memberIds);
            foreach ($memberIds as $memberId) {
                $this->insertPeriod($organization, $conversationId, $memberId, 1, 1, $now);
            }
            $lockedPeriods = $this->lockPeriods($organization, $conversationId, $memberIds);
            foreach ($memberIds as $memberId) {
                $this->validateLockedMemberFacts(
                    $lockedMembers[$memberId] ?? null,
                    $lockedPeriods[$memberId] ?? [],
                    (string) $conversation['last_message_seq'],
                );
            }
            $this->lockOrCreateAccessStates($organization, $memberIds, $now);
            foreach ($memberIds as $memberId) {
                $snapshot = $this->incrementSnapshot($organization, $memberId, $now);
                $this->recordTransition(
                    $conversation, $organization, $conversationId, $memberId,
                    $snapshot, '1', 'active', 'join', $organization, $ownerUserId, $now,
                );
            }
            return $conversationId;
        });
    }

    /**
     * @param list<string> $memberIds
     * @param array<string,string> $expectedVersions Every target requires its current version; "0" means absent.
     */
    public function join(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        array $memberIds,
        array $expectedVersions,
        string $now,
    ): void {
        $memberIds = $this->canonicalUserIds($memberIds);
        Db::transaction(function () use ($organization, $operatorUserId, $conversationId, $memberIds, $expectedVersions, $now): void {
            $conversation = $this->lockConversation($organization, $conversationId);
            $lockedUserIds = $this->canonicalUserIds(array_merge([$operatorUserId], $memberIds));
            $members = $this->lockMembers(
                $organization,
                $conversationId,
                $lockedUserIds,
            );
            $this->assertRole($members[$operatorUserId] ?? null, ['owner', 'admin']);
            $periods = $this->lockPeriods($organization, $conversationId, $lockedUserIds);
            foreach ($lockedUserIds as $lockedUserId) {
                $this->validateLockedMemberFacts(
                    $members[$lockedUserId] ?? null,
                    $periods[$lockedUserId] ?? [],
                    (string) $conversation['last_message_seq'],
                );
            }

            $plans = [];
            $committedRetries = [];
            foreach ($memberIds as $memberId) {
                $current = $members[$memberId] ?? null;
                $expected = $expectedVersions[$memberId] ?? null;
                if ($expected === null) {
                    throw new ApiException('expected_access_versions 缺少目标成员。', 422);
                }
                if ($current !== null && $this->isActive($current)) {
                    if ($expected === '0' && (string) $current['access_version'] === '1') {
                        $latest = $this->latestAudit($current);
                        if (($latest['reason'] ?? null) === 'join'
                            && (int) ($latest['actor_organization'] ?? 0) === $organization
                            && (string) ($latest['actor_user_id'] ?? '') === $operatorUserId) {
                            $committedRetries[] = $memberId;
                            continue;
                        }
                    }
                    if ($expected === '0') {
                        $this->conflict();
                    }
                    if ($this->assertExpected(
                        $current,
                        $expected,
                        'join',
                        $organization,
                        $operatorUserId,
                    )) {
                        $committedRetries[] = $memberId;
                    }
                    continue;
                }
                if ($current === null) {
                    if ($expected !== '0') {
                        $this->conflict();
                    }
                    $nextVersion = '1';
                } else {
                    if ($expected === '0') {
                        $this->conflict();
                    }
                    if ($this->assertExpected(
                        $current,
                        $expected,
                        'join',
                        $organization,
                        $operatorUserId,
                    )) {
                        throw new \RuntimeException(
                            'A committed join audit cannot describe a non-active member.',
                        );
                    }
                    $latest = $this->latestAudit($current);
                    if ($latest === null) {
                        throw new \RuntimeException(
                            'Existing group member access audit is missing.',
                        );
                    }
                    if ((string) $current['access_state'] === 'revoked'
                        && ($latest['reason'] ?? null) === 'suspend') {
                        throw new ApiException('已封禁成员只能通过恢复操作重新加入。', 409);
                    }
                    $nextVersion = $this->nextVersion((string) $current['access_version']);
                }
                $plans['user:' . $memberId] = [
                    'user_id' => $memberId,
                    'current' => $current,
                    'access_version' => $nextVersion,
                ];
            }

            if ($committedRetries !== [] && $plans !== []) {
                $this->conflict();
            }

            $transitionUserIds = $this->canonicalUserIds(array_column($plans, 'user_id'));
            if ($transitionUserIds !== []) {
                $this->lockActiveUsers($organization, $transitionUserIds);
                foreach ($transitionUserIds as $memberId) {
                    $this->assertFriends($organization, $operatorUserId, $memberId);
                }
            }

            $newMemberIds = [];
            foreach ($transitionUserIds as $memberId) {
                $plan = $plans['user:' . $memberId];
                $current = $plan['current'];
                $nextVersion = (string) $plan['access_version'];
                $memberPeriods = $periods[$memberId] ?? [];
                $visibleFrom = $this->visibleFrom(
                    (string) $conversation['history_visibility'],
                    (string) $conversation['last_message_seq'],
                    $memberPeriods,
                );
                if ($current === null) {
                    $newMemberIds[] = $memberId;
                    Db::table('im_conversation_member')->insert([
                        'organization' => $organization,
                        'conversation_id' => $conversationId,
                        'user_id' => $memberId,
                        'member_organization' => $organization,
                        'member_role' => 'member',
                        'inviter_user_id' => $operatorUserId,
                        'inviter_organization' => $organization,
                        'status' => 1,
                        'mute_status' => 0,
                        'access_version' => $nextVersion,
                        'access_state' => 'active',
                        'join_at' => $now,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                } else {
                    if ((string) $current['member_role'] === 'owner') {
                        throw new \RuntimeException('Group owner cannot be rejoined.');
                    }
                    Db::execute(
                        'UPDATE im_conversation_member
                            SET member_role = "member", inviter_user_id = ?,
                                inviter_organization = ?, status = 1,
                                mute_status = 0, mute_until = NULL,
                                access_version = ?, access_state = "active",
                                join_at = ?, delete_time = NULL, update_time = ?
                          WHERE id = ?',
                        [$operatorUserId, $organization, $nextVersion, $now, $now, $current['id']],
                    );
                }
                $this->insertPeriod(
                    $organization, $conversationId, $memberId,
                    $this->nextPeriodNo($memberPeriods), $visibleFrom, $now,
                );
            }

            $this->lockJoinAccessStates(
                $organization,
                $memberIds,
                $newMemberIds,
                $now,
            );
            if ($transitionUserIds === []) {
                return;
            }
            $this->validateCurrentMemberFacts(
                $organization,
                $conversationId,
                $transitionUserIds,
                (string) $conversation['last_message_seq'],
            );
            foreach ($transitionUserIds as $memberId) {
                $snapshot = $this->incrementSnapshot($organization, $memberId, $now);
                $this->recordTransition(
                    $conversation, $organization, $conversationId, $memberId,
                    $snapshot, (string) $plans['user:' . $memberId]['access_version'], 'active', 'join',
                    $organization, $operatorUserId, $now,
                );
            }
        });
    }

    /** @return array{access_version:string,access_snapshot_id:string,access_state:string} */
    public function leave(int $organization, string $userId, string $conversationId, string $expectedVersion, string $now): array
    {
        return $this->close($organization, $userId, $conversationId, $userId, $expectedVersion, 'leave', $now);
    }

    public function remove(int $organization, string $operatorUserId, string $conversationId, string $userId, string $expectedVersion, string $now): void
    {
        $this->close($organization, $operatorUserId, $conversationId, $userId, $expectedVersion, 'remove', $now);
    }

    public function suspend(int $organization, string $operatorUserId, string $conversationId, string $userId, string $expectedVersion, string $now): void
    {
        Db::transaction(function () use ($organization, $operatorUserId, $conversationId, $userId, $expectedVersion, $now): void {
            $conversation = $this->lockConversation($organization, $conversationId);
            $lockedUserIds = $this->canonicalUserIds([$operatorUserId, $userId]);
            $members = $this->lockMembers(
                $organization,
                $conversationId,
                $lockedUserIds,
            );
            $this->assertCanManage($members[$operatorUserId] ?? null, $members[$userId] ?? null, false);
            $target = $members[$userId];
            $periods = $this->lockPeriods($organization, $conversationId, $lockedUserIds);
            foreach ($lockedUserIds as $lockedUserId) {
                $this->validateLockedMemberFacts(
                    $members[$lockedUserId] ?? null,
                    $periods[$lockedUserId] ?? [],
                    (string) $conversation['last_message_seq'],
                );
            }
            $this->lockAccessStates($organization, [$userId]);
            if ($this->assertExpected(
                $target,
                $expectedVersion,
                'suspend',
                $organization,
                $operatorUserId,
            )) {
                return;
            }
            if (!$this->isActive($target)) {
                throw new ApiException('群成员访问状态已变化，请刷新后重试。', 409);
            }
            Db::execute(
                'UPDATE im_conversation_membership_period
                    SET visible_until_message_seq = CASE
                          WHEN visible_until_message_seq IS NULL
                           AND visible_from_message_seq <= ? THEN ?
                          ELSE visible_until_message_seq END,
                        leave_at = COALESCE(leave_at, ?), status = 2, update_time = ?
                  WHERE organization = ? AND BINARY conversation_id = BINARY ?
                    AND member_organization = ? AND BINARY user_id = BINARY ? AND status = 1',
                [
                    $conversation['last_message_seq'], $conversation['last_message_seq'],
                    $now, $now, $organization, $conversationId, $organization, $userId,
                ],
            );
            $nextVersion = $this->nextVersion((string) $target['access_version']);
            Db::execute(
                'UPDATE im_conversation_member
                    SET member_role = "member", status = 3, mute_status = 0,
                        mute_until = NULL, access_version = ?, access_state = "revoked", update_time = ?
                  WHERE id = ?',
                [$nextVersion, $now, $target['id']],
            );
            $this->validateCurrentMemberFacts(
                $organization,
                $conversationId,
                [$userId],
                (string) $conversation['last_message_seq'],
            );
            $snapshot = $this->incrementSnapshot($organization, $userId, $now);
            $this->recordTransition(
                $conversation, $organization, $conversationId, $userId,
                $snapshot, $nextVersion, 'revoked', 'suspend',
                $organization, $operatorUserId, $now,
            );
        });
    }

    public function restore(int $organization, string $operatorUserId, string $conversationId, string $userId, string $expectedVersion, string $now): void
    {
        Db::transaction(function () use ($organization, $operatorUserId, $conversationId, $userId, $expectedVersion, $now): void {
            $conversation = $this->lockConversation($organization, $conversationId);
            $lockedUserIds = $this->canonicalUserIds([$operatorUserId, $userId]);
            $members = $this->lockMembers(
                $organization,
                $conversationId,
                $lockedUserIds,
            );
            $this->assertRole($members[$operatorUserId] ?? null, ['owner', 'admin']);
            $target = $members[$userId] ?? null;
            if ($target === null || (string) $target['member_role'] === 'owner') {
                throw new ApiException('群成员不存在。', 404);
            }
            $periods = $this->lockPeriods($organization, $conversationId, $lockedUserIds);
            foreach ($lockedUserIds as $lockedUserId) {
                $this->validateLockedMemberFacts(
                    $members[$lockedUserId] ?? null,
                    $periods[$lockedUserId] ?? [],
                    (string) $conversation['last_message_seq'],
                );
            }
            $this->lockAccessStates($organization, [$userId]);
            if ($this->assertExpected(
                $target,
                $expectedVersion,
                'restore',
                $organization,
                $operatorUserId,
            )) {
                return;
            }
            $latest = $this->latestAudit($target);
            if ((string) $target['access_state'] !== 'revoked' || ($latest['reason'] ?? null) !== 'suspend') {
                throw new ApiException('只有已封禁成员可以恢复。', 409);
            }
            $this->lockActiveUsers($organization, [$userId]);
            $nextVersion = $this->nextVersion((string) $target['access_version']);
            Db::execute(
                'UPDATE im_conversation_member
                    SET member_role = "member", status = 1, mute_status = 0,
                        mute_until = NULL, access_version = ?, access_state = "active",
                        join_at = ?, delete_time = NULL, update_time = ?
                  WHERE id = ?',
                [$nextVersion, $now, $now, $target['id']],
            );
            $memberPeriods = $periods[$userId] ?? [];
            $this->insertPeriod(
                $organization, $conversationId, $userId, $this->nextPeriodNo($memberPeriods),
                $this->visibleFrom(
                    (string) $conversation['history_visibility'],
                    (string) $conversation['last_message_seq'],
                    $memberPeriods,
                ),
                $now,
            );
            $this->validateCurrentMemberFacts(
                $organization,
                $conversationId,
                [$userId],
                (string) $conversation['last_message_seq'],
            );
            $snapshot = $this->incrementSnapshot($organization, $userId, $now);
            $this->recordTransition(
                $conversation, $organization, $conversationId, $userId,
                $snapshot, $nextVersion, 'active', 'restore',
                $organization, $operatorUserId, $now,
            );
        });
    }

    /** @param list<string> $periodNumbers Canonical positive decimal strings; empty means all. */
    public function revokeHistory(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        string $userId,
        string $expectedVersion,
        array $periodNumbers,
        string $now,
    ): void {
        Db::transaction(function () use ($organization, $operatorUserId, $conversationId, $userId, $expectedVersion, $periodNumbers, $now): void {
            $conversation = $this->lockConversation($organization, $conversationId);
            $lockedUserIds = $this->canonicalUserIds([$operatorUserId, $userId]);
            $members = $this->lockMembers(
                $organization,
                $conversationId,
                $lockedUserIds,
            );
            $this->assertCanManage($members[$operatorUserId] ?? null, $members[$userId] ?? null, false);
            $target = $members[$userId];
            $periods = $this->lockPeriods($organization, $conversationId, $lockedUserIds);
            foreach ($lockedUserIds as $lockedUserId) {
                $this->validateLockedMemberFacts(
                    $members[$lockedUserId] ?? null,
                    $periods[$lockedUserId] ?? [],
                    (string) $conversation['last_message_seq'],
                );
            }
            $this->lockAccessStates($organization, [$userId]);
            $retry = $this->assertExpected(
                $target,
                $expectedVersion,
                'history_revoke',
                $organization,
                $operatorUserId,
            );
            if ($retry) {
                if ($this->isEquivalentHistoryRevokeRetry($target, $expectedVersion, $periodNumbers)) {
                    return;
                }
                $this->conflict();
            }
            if ((string) $target['access_state'] !== 'history_only') {
                throw new ApiException('只有历史只读成员可以撤销历史。', 409);
            }
            $selected = array_fill_keys($periodNumbers, true);
            if ($selected !== []) {
                $effective = [];
                foreach ($periods[$userId] ?? [] as $period) {
                    if ((int) $period['status'] === 1 && $period['visible_until_message_seq'] !== null) {
                        $effective[(string) $period['period_no']] = true;
                    }
                }
                if (array_diff_key($selected, $effective) !== []) {
                    throw new ApiException('指定的历史周期不存在或已撤销。', 409);
                }
            }
            $changed = 0;
            foreach ($periods[$userId] ?? [] as $period) {
                if ((int) $period['status'] !== 1 || $period['visible_until_message_seq'] === null) {
                    continue;
                }
                if ($selected !== [] && !isset($selected[(string) $period['period_no']])) {
                    continue;
                }
                $changed += Db::execute(
                    'UPDATE im_conversation_membership_period SET status = 2, update_time = ? WHERE id = ? AND status = 1',
                    [$now, $period['id']],
                );
            }
            if ($changed === 0) {
                throw new ApiException('没有可撤销的历史周期。', 409);
            }
            $remaining = Db::query(
                'SELECT COUNT(*) AS aggregate FROM im_conversation_membership_period
                  WHERE organization = ? AND BINARY conversation_id = BINARY ? AND member_organization = ?
                    AND BINARY user_id = BINARY ? AND status = 1 AND visible_until_message_seq IS NOT NULL',
                [$organization, $conversationId, $organization, $userId],
            )[0]['aggregate'] ?? 0;
            $state = (int) $remaining > 0 ? 'history_only' : 'revoked';
            $nextVersion = $this->nextVersion((string) $target['access_version']);
            Db::execute(
                'UPDATE im_conversation_member SET access_version = ?, access_state = ?, update_time = ? WHERE id = ?',
                [$nextVersion, $state, $now, $target['id']],
            );
            $this->validateCurrentMemberFacts(
                $organization,
                $conversationId,
                [$userId],
                (string) $conversation['last_message_seq'],
            );
            $snapshot = $this->incrementSnapshot($organization, $userId, $now);
            $this->recordTransition(
                $conversation, $organization, $conversationId, $userId,
                $snapshot, $nextVersion, $state, 'history_revoke',
                $organization, $operatorUserId, $now,
            );
        });
    }

    /** @return array{access_version:string,access_snapshot_id:string,access_state:string} */
    private function close(
        int $organization,
        string $actorUserId,
        string $conversationId,
        string $userId,
        string $expectedVersion,
        string $reason,
        string $now,
    ): array {
        return Db::transaction(function () use ($organization, $actorUserId, $conversationId, $userId, $expectedVersion, $reason, $now): array {
            $conversation = $this->lockConversation($organization, $conversationId);
            $lockedUserIds = $this->canonicalUserIds([$actorUserId, $userId]);
            $members = $this->lockMembers(
                $organization,
                $conversationId,
                $lockedUserIds,
            );
            $target = $members[$userId] ?? null;
            if ($reason === 'remove') {
                $this->assertCanManage($members[$actorUserId] ?? null, $target, false);
            } else {
                if ($target === null || (string) $target['member_role'] === 'owner') {
                    throw new ApiException('群主不能主动退出群聊。', 403);
                }
            }
            if ($target === null) {
                throw new ApiException('群成员不存在。', 404);
            }
            $periods = $this->lockPeriods($organization, $conversationId, $lockedUserIds);
            foreach ($lockedUserIds as $lockedUserId) {
                $this->validateLockedMemberFacts(
                    $members[$lockedUserId] ?? null,
                    $periods[$lockedUserId] ?? [],
                    (string) $conversation['last_message_seq'],
                );
            }
            $this->lockAccessStates($organization, [$userId]);
            if ($this->assertExpected(
                $target,
                $expectedVersion,
                $reason,
                $organization,
                $actorUserId,
            )) {
                return [
                    'access_version' => (string) $target['access_version'],
                    'access_snapshot_id' => $this->currentSnapshot($organization, $userId),
                    'access_state' => (string) $target['access_state'],
                ];
            }
            if (!$this->isActive($target)) {
                throw new ApiException('群成员访问状态已变化，请刷新后重试。', 409);
            }
            $lastMessageSeq = (string) $conversation['last_message_seq'];
            foreach ($periods[$userId] ?? [] as $period) {
                if ((int) $period['status'] !== 1 || $period['visible_until_message_seq'] !== null) {
                    continue;
                }
                if ($this->compareDecimals(
                    (string) $period['visible_from_message_seq'],
                    $lastMessageSeq,
                ) <= 0) {
                    Db::execute(
                        'UPDATE im_conversation_membership_period
                            SET visible_until_message_seq = ?, leave_at = ?, update_time = ? WHERE id = ?',
                        [$lastMessageSeq, $now, $now, $period['id']],
                    );
                } else {
                    // Joining after the last message grants no historical message.
                    // Revoke the empty period instead of creating to_seq < from_seq.
                    Db::execute(
                        'UPDATE im_conversation_membership_period
                            SET status = 2, leave_at = ?, update_time = ? WHERE id = ?',
                        [$now, $now, $period['id']],
                    );
                }
            }
            $remaining = Db::query(
                'SELECT COUNT(*) AS aggregate FROM im_conversation_membership_period
                  WHERE organization = ? AND BINARY conversation_id = BINARY ? AND member_organization = ?
                    AND BINARY user_id = BINARY ? AND status = 1',
                [$organization, $conversationId, $organization, $userId],
            )[0]['aggregate'] ?? 0;
            $state = (int) $remaining > 0 ? 'history_only' : 'revoked';
            $nextVersion = $this->nextVersion((string) $target['access_version']);
            Db::execute(
                'UPDATE im_conversation_member
                    SET member_role = "member", status = ?, mute_status = 0,
                        mute_until = NULL, access_version = ?, access_state = ?, update_time = ?
                  WHERE id = ?',
                [$reason === 'leave' ? 2 : 3, $nextVersion, $state, $now, $target['id']],
            );
            $this->validateCurrentMemberFacts(
                $organization,
                $conversationId,
                [$userId],
                (string) $conversation['last_message_seq'],
            );
            $snapshot = $this->incrementSnapshot($organization, $userId, $now);
            $this->recordTransition(
                $conversation, $organization, $conversationId, $userId,
                $snapshot, $nextVersion, $state, $reason,
                $organization, $actorUserId, $now,
            );
            return [
                'access_version' => $nextVersion,
                'access_snapshot_id' => $snapshot,
                'access_state' => $state,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function lockCreatedConversation(int $organization, string $conversationId): array
    {
        $row = Db::query(
            'SELECT last_message_seq, last_change_seq
               FROM im_conversation
              WHERE organization = ? AND BINARY conversation_id = BINARY ?
                AND conversation_type = 2 AND status = 1 AND delete_time IS NULL
              LIMIT 1 FOR UPDATE',
            [$organization, $conversationId],
        )[0] ?? null;
        if ($row === null) {
            throw new \RuntimeException('Created group conversation could not be locked.');
        }
        $row['history_visibility'] = 'since_join';
        $this->validateLockedConversationFacts($row);
        return $row;
    }

    /** @return array<string,mixed> */
    private function lockConversation(int $organization, string $conversationId): array
    {
        $row = Db::query(
            'SELECT c.last_message_seq, c.last_change_seq, gp.history_visibility
               FROM im_conversation c
         INNER JOIN im_group_profile gp
                 ON gp.organization = c.organization
                AND BINARY gp.conversation_id = BINARY c.conversation_id
                AND gp.status = 1 AND gp.delete_time IS NULL
              WHERE c.organization = ? AND BINARY c.conversation_id = BINARY ?
                AND c.conversation_type = 2 AND c.status = 1 AND c.delete_time IS NULL
              LIMIT 1 FOR UPDATE',
            [$organization, $conversationId],
        )[0] ?? null;
        if ($row === null) {
            throw new ApiException('群聊不存在。', 404);
        }
        $this->validateLockedConversationFacts($row);
        $foreign = Db::query(
            'SELECT 1 AS present FROM im_conversation_member
              WHERE organization = ? AND BINARY conversation_id = BINARY ?
                AND member_organization <> ? LIMIT 1',
            [$organization, $conversationId, $organization],
        )[0] ?? null;
        if ($foreign !== null) {
            throw new \RuntimeException('Group conversation contains a foreign member.');
        }
        return $row;
    }

    /** @param array<string,mixed> $conversation */
    private function validateLockedConversationFacts(array $conversation): void
    {
        if (!$this->isNonNegativeDecimal((string) ($conversation['last_message_seq'] ?? ''))
            || !$this->isNonNegativeDecimal((string) ($conversation['last_change_seq'] ?? ''))
            || !in_array(
                (string) ($conversation['history_visibility'] ?? ''),
                ['since_join', 'all'],
                true,
            )) {
            throw new \RuntimeException('Group conversation access facts are invalid.');
        }
    }

    /** @param list<string> $userIds @return array<string,array<string,mixed>> */
    private function lockMembers(int $organization, string $conversationId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = Db::query(
            'SELECT * FROM im_conversation_member
              WHERE organization = ? AND BINARY conversation_id = BINARY ? AND member_organization = ?
                AND BINARY user_id IN (' . $placeholders . ')
           ORDER BY member_organization ASC, user_id COLLATE utf8mb4_bin ASC FOR UPDATE',
            array_merge([$organization, $conversationId, $organization], $userIds),
        );
        $result = [];
        foreach ($rows as $row) {
            if ((int) $row['member_organization'] !== $organization) {
                throw new \RuntimeException('Cross-organization group member is forbidden.');
            }
            $rowUserId = (string) $row['user_id'];
            if (!in_array($rowUserId, $userIds, true)) {
                throw new \RuntimeException('Group member identity comparison is not byte exact.');
            }
            $result[$rowUserId] = $row;
        }
        return $result;
    }

    /** @param list<string> $userIds @return array<string,list<array<string,mixed>>> */
    private function lockPeriods(int $organization, string $conversationId, array $userIds): array
    {
        $userIds = $this->canonicalUserIds($userIds);
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = Db::query(
            'SELECT * FROM im_conversation_membership_period
              WHERE organization = ? AND BINARY conversation_id = BINARY ? AND member_organization = ?
                AND BINARY user_id IN (' . $placeholders . ')
           ORDER BY member_organization ASC, user_id COLLATE utf8mb4_bin ASC, period_no ASC FOR UPDATE',
            array_merge([$organization, $conversationId, $organization], $userIds),
        );
        $result = [];
        foreach ($userIds as $userId) {
            $result[$userId] = [];
        }
        foreach ($rows as $row) {
            $rowUserId = (string) $row['user_id'];
            if (!array_key_exists($rowUserId, $result)) {
                throw new \RuntimeException('Membership period identity comparison is not byte exact.');
            }
            $result[$rowUserId][] = $row;
        }
        return $result;
    }

    /** @param array<string,mixed>|null $member @param list<array<string,mixed>> $periods */
    private function validateLockedMemberFacts(
        ?array $member,
        array $periods,
        string $lastMessageSeq,
    ): void
    {
        if (!$this->isNonNegativeDecimal($lastMessageSeq)) {
            throw new \RuntimeException('Group conversation message watermark is invalid.');
        }
        if ($member === null) {
            if ($periods !== []) {
                throw new \RuntimeException('Orphan group membership periods are invalid.');
            }
            return;
        }
        if ($member['delete_time'] !== null
            || !in_array((int) $member['status'], [1, 2, 3], true)
            || !in_array((string) $member['member_role'], ['owner', 'admin', 'member'], true)
            || !in_array((string) $member['access_state'], ['active', 'history_only', 'revoked'], true)
            || !$this->isPositiveDecimal((string) $member['access_version'])) {
            throw new \RuntimeException('Group member access facts are invalid.');
        }

        $effective = [];
        $periodNumbers = [];
        $openCount = 0;
        $closedCount = 0;
        foreach ($periods as $period) {
            $periodNo = (string) ($period['period_no'] ?? '');
            $from = (string) ($period['visible_from_message_seq'] ?? '');
            $untilValue = $period['visible_until_message_seq'] ?? null;
            $until = $untilValue === null ? null : (string) $untilValue;
            $status = (int) ($period['status'] ?? 0);
            if (!$this->isPositiveDecimal($periodNo)
                || !$this->isPositiveDecimal($from)
                || !in_array($status, [1, 2], true)
                || ($until !== null && (!$this->isPositiveDecimal($until)
                    || $this->compareDecimals($until, $from) < 0))
                || isset($periodNumbers[$periodNo])) {
                throw new \RuntimeException('Group membership period facts are invalid.');
            }
            $periodNumbers[$periodNo] = true;
            if ($status !== 1) {
                continue;
            }
            if ($until === null) {
                ++$openCount;
            } else {
                ++$closedCount;
            }
            if (($until !== null && $this->compareDecimals($until, $lastMessageSeq) > 0)
                || ($until === null
                    && $this->compareDecimals(
                        $from,
                        $this->maximumOpenPeriodStart($lastMessageSeq),
                    ) > 0)) {
                throw new \RuntimeException('Group membership period exceeds the conversation watermark.');
            }
            $effective[] = ['period_no' => $periodNo, 'from' => $from, 'to' => $until];
        }
        $previous = null;
        foreach ($effective as $period) {
            if ($previous !== null
                && ($previous['to'] === null
                    || $this->compareDecimals($period['from'], $previous['to']) <= 0)) {
                throw new \RuntimeException(
                    'Group membership periods overlap or are reverse ordered.',
                );
            }
            $previous = $period;
        }

        $state = (string) $member['access_state'];
        $status = (int) $member['status'];
        if (($state === 'active' && ($status !== 1 || $openCount !== 1))
            || ($state === 'history_only'
                && (!in_array($status, [2, 3], true) || $openCount !== 0 || $closedCount < 1))
            || ($state === 'revoked'
                && (!in_array($status, [2, 3], true) || $openCount !== 0 || $closedCount !== 0))) {
            throw new \RuntimeException('Group member state and periods are inconsistent.');
        }
    }

    /** @param list<string> $userIds */
    private function validateCurrentMemberFacts(
        int $organization,
        string $conversationId,
        array $userIds,
        string $lastMessageSeq,
    ): void {
        $userIds = $this->canonicalUserIds($userIds);
        $members = $this->lockMembers($organization, $conversationId, $userIds);
        $periods = $this->lockPeriods($organization, $conversationId, $userIds);
        foreach ($userIds as $userId) {
            $this->validateLockedMemberFacts(
                $members[$userId] ?? null,
                $periods[$userId] ?? [],
                $lastMessageSeq,
            );
        }
    }

    /** @param list<string> $userIds */
    private function lockOrCreateAccessStates(
        int $organization,
        array $userIds,
        string $now,
    ): void {
        foreach ($this->canonicalUserIds($userIds) as $userId) {
            Db::execute(
                'INSERT INTO im_user_group_access_state
                    (organization, user_id, access_snapshot_id, create_time, update_time)
                 VALUES (?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    access_snapshot_id = im_user_group_access_state.access_snapshot_id',
                [$organization, $userId, $now, $now],
            );
            $this->lockAccessState($organization, $userId);
        }
    }

    /** @param list<string> $userIds @param list<string> $newMemberIds */
    private function lockJoinAccessStates(
        int $organization,
        array $userIds,
        array $newMemberIds,
        string $now,
    ): void {
        $newMemberIds = $this->canonicalUserIds($newMemberIds);
        foreach ($this->canonicalUserIds($userIds) as $userId) {
            if (in_array($userId, $newMemberIds, true)) {
                Db::execute(
                    'INSERT INTO im_user_group_access_state
                        (organization, user_id, access_snapshot_id, create_time, update_time)
                     VALUES (?, ?, 1, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        access_snapshot_id = im_user_group_access_state.access_snapshot_id',
                    [$organization, $userId, $now, $now],
                );
            }
            $this->lockAccessState($organization, $userId);
        }
    }

    /** @param list<string> $userIds */
    private function lockAccessStates(
        int $organization,
        array $userIds,
    ): void
    {
        foreach ($this->canonicalUserIds($userIds) as $userId) {
            $this->lockAccessState($organization, $userId);
        }
    }

    private function lockAccessState(int $organization, string $userId): void
    {
        $row = Db::query(
            'SELECT user_id, access_snapshot_id FROM im_user_group_access_state
              WHERE organization = ? AND BINARY user_id = BINARY ? LIMIT 1 FOR UPDATE',
            [$organization, $userId],
        )[0] ?? null;
        if ($row === null
            || !hash_equals($userId, (string) ($row['user_id'] ?? ''))
            || !$this->isPositiveDecimal((string) $row['access_snapshot_id'])) {
            throw new \RuntimeException('Group access snapshot state is invalid.');
        }
    }

    /** @param list<string> $userIds */
    private function lockActiveUsers(int $organization, array $userIds): void
    {
        $userIds = $this->canonicalUserIds($userIds);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = Db::query(
            'SELECT user_id FROM im_user
              WHERE organization = ? AND BINARY user_id IN (' . $placeholders . ')
                AND status = 1 AND delete_time IS NULL
           ORDER BY user_id COLLATE utf8mb4_bin ASC
              FOR UPDATE',
            array_merge([$organization], $userIds),
        );
        $activeUserIds = array_map(
            static fn (array $row): string => (string) $row['user_id'],
            $rows,
        );
        if ($activeUserIds !== $userIds) {
            throw new ApiException('群成员不存在或已停用。', 422);
        }
    }

    private function assertFriends(int $organization, string $operatorUserId, string $userId): void
    {
        if ($operatorUserId === $userId) {
            return;
        }
        $rows = Db::query(
            'SELECT user_id, friend_user_id FROM im_friend_relation
              WHERE organization = ? AND friend_organization = ?
                AND ((BINARY user_id = BINARY ? AND BINARY friend_user_id = BINARY ?)
                  OR (BINARY user_id = BINARY ? AND BINARY friend_user_id = BINARY ?))
                AND status = 1 AND delete_time IS NULL
           ORDER BY user_id COLLATE utf8mb4_bin ASC,
                    friend_user_id COLLATE utf8mb4_bin ASC
              FOR UPDATE',
            [
                $organization, $organization,
                $operatorUserId, $userId,
                $userId, $operatorUserId,
            ],
        );
        $directions = [];
        foreach ($rows as $row) {
            $sourceUserId = (string) ($row['user_id'] ?? '');
            $friendUserId = (string) ($row['friend_user_id'] ?? '');
            $directions[$sourceUserId . "\0" . $friendUserId] = true;
        }
        if (count($directions) !== 2
            || !isset($directions[$operatorUserId . "\0" . $userId])
            || !isset($directions[$userId . "\0" . $operatorUserId])) {
            throw new ApiException('只能邀请自己的好友加入群聊。', 403);
        }
    }

    /** @param list<string> $roles @param array<string,mixed>|null $member */
    private function assertRole(?array $member, array $roles): void
    {
        if ($member === null || !$this->isActive($member)
            || !in_array((string) $member['member_role'], $roles, true)) {
            throw new ApiException('没有群聊管理权限。', 403);
        }
    }

    /** @param array<string,mixed>|null $operator @param array<string,mixed>|null $target */
    private function assertCanManage(?array $operator, ?array $target, bool $targetMustBeActive = true): void
    {
        $this->assertRole($operator, ['owner', 'admin']);
        if ($target === null || ($targetMustBeActive && !$this->isActive($target))) {
            throw new ApiException('群成员不存在。', 404);
        }
        if ((int) $operator['id'] === (int) $target['id']) {
            throw new ApiException('不能对自己执行该操作。', 422);
        }
        $operatorRole = (string) $operator['member_role'];
        $targetRole = (string) $target['member_role'];
        if ($targetRole === 'owner' || ($operatorRole === 'admin' && $targetRole !== 'member')) {
            throw new ApiException('没有管理该成员的权限。', 403);
        }
    }

    /** @param array<string,mixed> $member */
    private function isActive(array $member): bool
    {
        return (int) $member['status'] === 1
            && $member['delete_time'] === null
            && (string) $member['access_state'] === 'active';
    }

    /** @param array<string,mixed> $member */
    private function assertExpected(
        array $member,
        ?string $expectedVersion,
        string $reason,
        int $actorOrganization,
        string $actorUserId,
    ): bool
    {
        $current = (string) $member['access_version'];
        if ($expectedVersion === null || !$this->isPositiveDecimal($expectedVersion)) {
            throw new ApiException('expected_access_version 必须是正规范正十进制字符串。', 422);
        }
        if ($expectedVersion === $current) {
            return false;
        }
        if ($expectedVersion === self::UNSIGNED_BIGINT_MAX) {
            $this->conflict();
        }
        $latest = $this->latestAudit($member);
        if ($this->nextVersion($expectedVersion) === $current
            && ($latest['reason'] ?? null) === $reason
            && (int) ($latest['actor_organization'] ?? 0) === $actorOrganization
            && (string) ($latest['actor_user_id'] ?? '') === $actorUserId) {
            return true;
        }
        $this->conflict();
    }

    /** @param array<string,mixed> $member */
    private function latestAudit(array $member): ?array
    {
        $audit = Db::query(
            'SELECT access_state, reason, actor_organization, actor_user_id, periods_json
               FROM im_group_member_access_audit
              WHERE organization = ? AND BINARY conversation_id = BINARY ? AND member_organization = ?
                AND BINARY user_id = BINARY ? AND access_version = ? LIMIT 1',
            [
                $member['organization'], $member['conversation_id'], $member['member_organization'],
                $member['user_id'], $member['access_version'],
            ],
        )[0] ?? null;
        if ($audit === null) {
            return null;
        }
        $auditPeriods = json_decode(
            (string) ($audit['periods_json'] ?? ''),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $currentPeriods = $this->effectivePeriods(
            (int) $member['organization'],
            (string) $member['conversation_id'],
            (string) $member['user_id'],
        );
        if (!is_array($auditPeriods)
            || !array_is_list($auditPeriods)
            || (string) ($audit['access_state'] ?? '') !== (string) $member['access_state']
            || $auditPeriods !== $currentPeriods) {
            throw new \RuntimeException(
                'Group member audit does not match the locked access facts.',
            );
        }
        return $audit;
    }

    /**
     * @param array<string,mixed> $member
     * @param list<string> $requestedPeriodNumbers
     */
    private function isEquivalentHistoryRevokeRetry(
        array $member,
        string $expectedVersion,
        array $requestedPeriodNumbers,
    ): bool {
        $rows = Db::query(
            'SELECT access_version, periods_json
               FROM im_group_member_access_audit
              WHERE organization = ? AND BINARY conversation_id = BINARY ? AND member_organization = ?
                AND BINARY user_id = BINARY ? AND access_version IN (?, ?)
           ORDER BY access_version ASC',
            [
                $member['organization'],
                $member['conversation_id'],
                $member['member_organization'],
                $member['user_id'],
                $expectedVersion,
                $member['access_version'],
            ],
        );
        $periodsByVersion = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['periods_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Group access audit periods are invalid.');
            }
            $periodsByVersion[(string) $row['access_version']] = array_fill_keys(
                array_map(
                    static fn (array $period): string => (string) ($period['period_no'] ?? ''),
                    $decoded,
                ),
                true,
            );
        }
        $before = $periodsByVersion[$expectedVersion] ?? null;
        $after = $periodsByVersion[(string) $member['access_version']] ?? null;
        if (!is_array($before) || !is_array($after) || array_diff_key($after, $before) !== []) {
            return false;
        }
        $revoked = array_map(
            static fn (int|string $periodNo): string => (string) $periodNo,
            array_keys(array_diff_key($before, $after)),
        );
        sort($revoked, SORT_STRING);
        $requested = $requestedPeriodNumbers === []
            ? array_map(
                static fn (int|string $periodNo): string => (string) $periodNo,
                array_keys($before),
            )
            : array_values(array_unique($requestedPeriodNumbers));
        sort($requested, SORT_STRING);
        return $requested === $revoked;
    }

    private function conflict(): never
    {
        throw new ApiException('群成员访问版本已变化，请刷新后重试。', 409);
    }

    private function insertPeriod(
        int $organization,
        string $conversationId,
        string $userId,
        int|string $periodNo,
        int|string $visibleFrom,
        string $now,
    ): void {
        Db::table('im_conversation_membership_period')->insert([
            'organization' => $organization,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'member_organization' => $organization,
            'period_no' => $periodNo,
            'visible_from_message_seq' => $visibleFrom,
            'visible_until_message_seq' => null,
            'join_at' => $now,
            'leave_at' => null,
            'status' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /** @param list<array<string,mixed>> $periods */
    private function visibleFrom(string $historyVisibility, string $lastMessageSeq, array $periods): string
    {
        // "all" applies to a first membership only. Re-entry starts after the
        // locked watermark so it cannot overlap an earlier valid period.
        return $historyVisibility === 'all' && $periods === []
            ? '1'
            : $this->incrementNonNegativeDecimal($lastMessageSeq, '群聊消息序号已达到上限。');
    }

    private function maximumOpenPeriodStart(string $lastMessageSeq): string
    {
        return $lastMessageSeq === self::UNSIGNED_BIGINT_MAX
            ? self::UNSIGNED_BIGINT_MAX
            : $this->incrementNonNegativeDecimal(
                $lastMessageSeq,
                '群聊消息序号已达到上限。',
            );
    }

    /** @param list<array<string,mixed>> $periods */
    private function nextPeriodNo(array $periods): string
    {
        $maximum = '0';
        foreach ($periods as $period) {
            $periodNo = (string) ($period['period_no'] ?? '');
            if (!$this->isPositiveDecimal($periodNo)) {
                throw new \RuntimeException('Group membership period number is invalid.');
            }
            if ($this->compareDecimals($periodNo, $maximum) > 0) {
                $maximum = $periodNo;
            }
        }
        return $this->incrementNonNegativeDecimal($maximum, '群成员周期编号已达到上限。');
    }

    private function incrementSnapshot(int $organization, string $userId, string $now): string
    {
        $current = $this->currentSnapshot($organization, $userId);
        if ($current === self::UNSIGNED_BIGINT_MAX) {
            throw new ApiException('群成员访问快照已达到上限。', 409);
        }
        $affected = Db::execute(
            'UPDATE im_user_group_access_state
                SET access_snapshot_id = access_snapshot_id + 1, update_time = ?
              WHERE organization = ? AND BINARY user_id = BINARY ?',
            [$now, $organization, $userId],
        );
        if ($affected !== 1) {
            throw new \RuntimeException('Group access snapshot increment affected an unexpected row count.');
        }
        $value = $this->currentSnapshot($organization, $userId, false);
        return $value;
    }

    private function currentSnapshot(int $organization, string $userId, bool $lock = true): string
    {
        $current = (string) (Db::query(
            'SELECT access_snapshot_id FROM im_user_group_access_state
              WHERE organization = ? AND BINARY user_id = BINARY ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [$organization, $userId],
        )[0]['access_snapshot_id'] ?? '0');
        if (!$this->isPositiveDecimal($current)) {
            throw new \RuntimeException('Group access snapshot state is invalid.');
        }
        return $current;
    }

    /** @return list<array{period_no:string,from_seq:string,to_seq:?string}> */
    private function effectivePeriods(int $organization, string $conversationId, string $userId): array
    {
        $rows = Db::query(
            'SELECT period_no, visible_from_message_seq, visible_until_message_seq
               FROM im_conversation_membership_period
              WHERE organization = ? AND BINARY conversation_id = BINARY ? AND member_organization = ?
                AND BINARY user_id = BINARY ? AND status = 1
           ORDER BY period_no ASC',
            [$organization, $conversationId, $organization, $userId],
        );
        return array_map(static fn (array $row): array => [
            'period_no' => (string) $row['period_no'],
            'from_seq' => (string) $row['visible_from_message_seq'],
            'to_seq' => $row['visible_until_message_seq'] === null
                ? null
                : (string) $row['visible_until_message_seq'],
        ], $rows);
    }

    /** @param array<string,mixed> $conversation */
    private function recordTransition(
        array $conversation,
        int $organization,
        string $conversationId,
        string $userId,
        string $snapshot,
        string $version,
        string $state,
        string $reason,
        int $actorOrganization,
        string $actorUserId,
        string $now,
    ): void {
        $periods = $this->effectivePeriods($organization, $conversationId, $userId);
        $eventId = hash('sha256', implode('|', [
            (string) $organization,
            self::EVENT_TYPE,
            $conversationId,
            (string) $organization,
            $userId,
            $snapshot,
            $version,
        ]));
        $lastMessageSeq = (string) $conversation['last_message_seq'];
        $lastChangeSeq = (string) $conversation['last_change_seq'];
        Db::table('im_group_member_access_audit')->insert([
            'event_id' => $eventId,
            'organization' => $organization,
            'conversation_id' => $conversationId,
            'member_organization' => $organization,
            'user_id' => $userId,
            'access_snapshot_id' => $snapshot,
            'access_version' => $version,
            'access_state' => $state,
            'last_message_seq' => $lastMessageSeq,
            'last_change_seq' => $lastChangeSeq,
            'periods_json' => json_encode($periods, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'actor_organization' => $actorOrganization,
            'actor_user_id' => $actorUserId,
            'create_time' => $now,
        ]);
        $payload = [
            'event_id' => $eventId,
            'event_type' => self::EVENT_TYPE,
            'organization' => $organization,
            'conversation_id' => $conversationId,
            'conversation_type' => self::GROUP,
            'target_organization' => $organization,
            'target_user_id' => $userId,
            'access_snapshot_id' => $snapshot,
            'access_version' => $version,
            'access_state' => $state,
            'last_message_seq' => $lastMessageSeq,
            'last_change_seq' => $lastChangeSeq,
            'periods' => $periods,
            'reason' => $reason,
            'actor_organization' => $actorOrganization,
            'actor_user_id' => $actorUserId,
            'recipient_count' => 1,
            'recipient_identities' => [[
                'organization' => $organization,
                'user_id' => $userId,
            ]],
            'created_at' => $now,
        ];
        $aggregate = sha1(implode('|', [
            (string) $organization,
            $userId,
            $conversationId,
            $version,
            $snapshot,
        ]));
        $trace = Telemetry::currentTraceHeaders();
        Db::table('im_message_outbox')->insert([
            'event_id' => $eventId,
            'organization' => $organization,
            'event_type' => self::EVENT_TYPE,
            'routing_key' => self::EVENT_TYPE,
            'message_id' => $aggregate,
            'change_seq' => 0,
            'conversation_id' => $conversationId,
            'conversation_type' => self::GROUP,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'traceparent' => $trace['traceparent'] ?? null,
            'tracestate' => $trace['tracestate'] ?? null,
            'status' => 1,
            'retry_count' => 0,
            'next_retry_at' => $now,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /** @param list<string> $userIds @return list<string> */
    private function canonicalUserIds(array $userIds): array
    {
        $result = [];
        foreach ($userIds as $userId) {
            if (!is_string($userId) || $userId === '' || strlen($userId) > 64
                || trim($userId) !== $userId || str_contains($userId, "\0")
                || str_contains($userId, '|')) {
                throw new ApiException('群成员身份无效。', 422);
            }
            $result['user:' . $userId] = $userId;
        }
        $values = array_values($result);
        sort($values, SORT_STRING);
        return $values;
    }

    private function nextVersion(string $version): string
    {
        if (!$this->isPositiveDecimal($version)) {
            throw new \RuntimeException('Group access version is invalid.');
        }
        if (strlen($version) < 18) {
            return (string) ((int) $version + 1);
        }
        $digits = str_split($version);
        for ($index = count($digits) - 1; $index >= 0; --$index) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);
                $next = implode('', $digits);
                if (!$this->isPositiveDecimal($next)) {
                    throw new ApiException('群成员访问版本已达到上限。', 409);
                }
                return $next;
            }
            $digits[$index] = '0';
        }
        $next = '1' . implode('', $digits);
        if (!$this->isPositiveDecimal($next)) {
            throw new ApiException('群成员访问版本已达到上限。', 409);
        }
        return $next;
    }

    private function incrementNonNegativeDecimal(string $value, string $overflowMessage): string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,19})$/D', $value) !== 1
            || (strlen($value) === 20 && strcmp($value, self::UNSIGNED_BIGINT_MAX) > 0)) {
            throw new \RuntimeException('Unsigned decimal fact is invalid.');
        }
        if ($value === self::UNSIGNED_BIGINT_MAX) {
            throw new ApiException($overflowMessage, 409);
        }
        if ($value === '0') {
            return '1';
        }
        $digits = str_split($value);
        for ($index = count($digits) - 1; $index >= 0; --$index) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);
                return implode('', $digits);
            }
            $digits[$index] = '0';
        }
        return '1' . implode('', $digits);
    }

    private function isPositiveDecimal(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1
            && (strlen($value) < 20 || strcmp($value, self::UNSIGNED_BIGINT_MAX) <= 0);
    }

    private function isNonNegativeDecimal(string $value): bool
    {
        return preg_match('/^(0|[1-9][0-9]{0,19})$/D', $value) === 1
            && (strlen($value) < 20 || strcmp($value, self::UNSIGNED_BIGINT_MAX) <= 0);
    }

    private function compareDecimals(string $left, string $right): int
    {
        $length = strlen($left) <=> strlen($right);
        return $length !== 0 ? $length : strcmp($left, $right);
    }
}
