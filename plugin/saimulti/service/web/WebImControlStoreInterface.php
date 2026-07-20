<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

interface WebImControlStoreInterface
{
    /** @return list<array<string, mixed>> */
    public function conversations(int $organization, string $userId): array;

    /** @return list<array{id: int, name: string, sort: int}> */
    public function messageGroups(int $organization, string $userId): array;

    /** @return array{id: int, name: string, sort: int} */
    public function createMessageGroup(int $organization, string $userId, string $name, string $now): array;

    /** @return array{conversation_id: string, message_group_id: int, message_group_name: string} */
    public function updateConversationGroup(
        int $organization,
        string $userId,
        string $conversationId,
        int $messageGroupId,
        string $now,
    ): array;

    /** @return array{delete_single_enabled: bool, delete_both_enabled: bool} */
    public function messageConfig(int $organization): array;

    /** @return array{messages: list<array<string, mixed>>, next_after_seq: int, next_before_seq: int, has_more_before: bool} */
    public function messages(
        int $organization,
        string $userId,
        string $conversationId,
        int $peerOrganization,
        string $peerUserId,
        int $afterSeq,
        int $beforeSeq,
        int $limit,
    ): array;

    /** @return array{updated: int, user_organization: int, user_id: string} */
    public function markRead(
        int $organization,
        string $userId,
        string $conversationId,
        bool $all,
        string $now,
    ): array;

    /** @return list<array<string, mixed>> */
    public function contacts(int $organization, string $userId, string $keyword): array;

    /** @return list<array<string, mixed>> */
    public function searchUsers(int $organization, string $userId, string $keyword): array;

    /** @return list<array<string, mixed>> */
    public function friendRequests(int $organization, string $userId): array;

    /** @return array<string, mixed> */
    public function sendFriendRequest(
        int $organization,
        string $fromUserId,
        int $toOrganization,
        string $toUserId,
        string $message,
        string $now,
    ): array;

    /** @return array{status: string} */
    public function handleFriendRequest(
        int $organization,
        string $userId,
        int $requestId,
        string $action,
        string $now,
    ): array;

    /** @param list<string> $memberIds @return array<string, mixed> */
    public function createGroup(
        int $organization,
        string $ownerUserId,
        string $title,
        array $memberIds,
        string $now,
    ): array;

    /** @return list<array<string, mixed>> */
    public function groupMembers(int $organization, string $userId, string $conversationId): array;

    /** @param list<string> $memberIds @return list<array<string, mixed>> */
    public function addGroupMembers(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        array $memberIds,
        string $now,
    ): array;

    /** @return array<string, mixed> */
    public function updateGroupProfile(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        ?string $title,
        ?string $avatarFileId,
        ?string $description,
        bool $notifyAll,
        string $now,
    ): array;

    /** @param list<string> $managerUserIds @return list<array<string, mixed>> */
    public function updateGroupManagers(
        int $organization,
        string $ownerUserId,
        string $conversationId,
        array $managerUserIds,
        string $now,
    ): array;

    /** @return list<array<string, mixed>> */
    public function updateGroupMemberStatus(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        string $memberUserId,
        int $status,
        ?string $muteUntil,
        string $now,
    ): array;

    /** @return list<array<string, mixed>> */
    public function removeGroupMember(
        int $organization,
        string $operatorUserId,
        string $conversationId,
        string $memberUserId,
        string $now,
    ): array;

    /** @return array{conversation_id: string, is_pinned: bool, is_muted: bool} */
    public function updateConversationSetting(
        int $organization,
        string $userId,
        string $conversationId,
        ?bool $isPinned,
        ?bool $isMuted,
        string $now,
    ): array;

    /** @return array{friend_organization: int, friend_user_id: string, remark: string} */
    public function updateFriendRemark(
        int $organization,
        string $userId,
        int $friendOrganization,
        string $friendUserId,
        string $remark,
        string $now,
    ): array;

    /** @return list<array<string, mixed>> */
    public function searchMessages(
        int $organization,
        string $userId,
        string $conversationId,
        string $keyword,
        int $messageType,
        int $limit,
    ): array;
}
