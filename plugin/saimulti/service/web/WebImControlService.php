<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\exception\ApiException;

final class WebImControlService
{
    private WebImControlStoreInterface $store;

    private Closure $clock;

    private WebImRealtimePublisherInterface $realtime;

    private WebImAvatarServiceInterface $avatars;

    public function __construct(
        ?WebImControlStoreInterface $store = null,
        ?Closure $clock = null,
        ?WebImRealtimePublisherInterface $realtime = null,
        ?WebImAvatarServiceInterface $avatars = null,
    ) {
        $this->store = $store ?? new ThinkOrmWebImControlStore();
        $this->clock = $clock ?? static fn (): int => time();
        $this->realtime = $realtime ?? new RedisWebImRealtimePublisher();
        $this->avatars = $avatars ?? new WebImAvatarService();
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function conversations(array $identity): array
    {
        [$organization, $userId] = $this->identity($identity);

        return array_map(
            fn (array $conversation): array => $this->projectConversation($organization, $conversation),
            $this->store->conversations($organization, $userId),
        );
    }

    /** @param array<string, mixed> $identity @return list<array{id: int, name: string, sort: int}> */
    public function messageGroups(array $identity): array
    {
        [$organization, $userId] = $this->identity($identity);

        return $this->store->messageGroups($organization, $userId);
    }

    /** @param array<string, mixed> $identity @return array{id: int, name: string, sort: int} */
    public function createMessageGroup(array $identity, string $name): array
    {
        [$organization, $userId] = $this->identity($identity);
        $name = $this->text($name, '分组名称', 40, false);

        return $this->store->createMessageGroup($organization, $userId, $name, $this->nowText());
    }

    /** @param array<string, mixed> $identity */
    public function updateConversationGroup(
        array $identity,
        string $conversationId,
        int $messageGroupId,
    ): array {
        [$organization, $userId] = $this->identity($identity);
        $conversationId = $this->identifier($conversationId, 'conversation_id', 64);
        if ($messageGroupId < 0) {
            throw new ApiException('message_group_id 格式无效。', 422);
        }

        return $this->store->updateConversationGroup(
            $organization,
            $userId,
            $conversationId,
            $messageGroupId,
            $this->nowText(),
        );
    }

    /** @param array<string, mixed> $identity @return array{delete_single_enabled: bool, delete_both_enabled: bool} */
    public function messageConfig(array $identity): array
    {
        [$organization] = $this->identity($identity);

        return $this->store->messageConfig($organization);
    }

    /** @param array<string, mixed> $identity */
    public function messages(
        array $identity,
        string $conversationId,
        int $peerOrganization,
        string $peerUserId,
        int $afterSeq,
        int $beforeSeq,
        int $limit,
    ): array {
        [$organization, $userId] = $this->identity($identity);
        $conversationId = $this->optionalIdentifier($conversationId, 'conversation_id', 64);
        $peerUserId = $this->optionalIdentifier($peerUserId, 'peer_user_id', 64);
        if ($peerUserId !== '' && $peerOrganization <= 0) {
            throw new ApiException('peer_organization 格式无效。', 422);
        }
        if ($peerUserId === '') {
            $peerOrganization = 0;
        }
        if ($afterSeq < 0 || $beforeSeq < 0 || ($afterSeq > 0 && $beforeSeq > 0)) {
            throw new ApiException('消息游标格式无效。', 422);
        }

        $result = $this->store->messages(
            $organization,
            $userId,
            $conversationId,
            $peerOrganization,
            $peerUserId,
            $afterSeq,
            $beforeSeq,
            min(max($limit, 1), 100),
        );
        if (is_array($result['messages'] ?? null)) {
            $result['messages'] = array_map(
                fn (array $message): array => $this->projectMessage($message),
                $result['messages'],
            );
        }

        return $result;
    }

    /** @param array<string, mixed> $identity @return array{updated: int, user_organization: int, user_id: string} */
    public function markRead(array $identity, string $conversationId, bool $all): array
    {
        [$organization, $userId] = $this->identity($identity);
        $conversationId = $this->optionalIdentifier($conversationId, 'conversation_id', 64);
        if (!$all && $conversationId === '') {
            throw new ApiException('请选择会话。', 422);
        }

        return $this->store->markRead(
            $organization,
            $userId,
            $conversationId,
            $all,
            $this->nowText(),
        );
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function contacts(array $identity, string $keyword): array
    {
        [$organization, $userId] = $this->identity($identity);
        $keyword = $this->text($keyword, '搜索关键词', 64, true);

        return array_map(
            fn (array $user): array => $this->projectUser($user),
            $this->store->contacts($organization, $userId, $keyword),
        );
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function searchUsers(array $identity, string $keyword): array
    {
        [$organization, $userId] = $this->identity($identity);
        $keyword = $this->text($keyword, '搜索关键词', 64, true);
        if ($keyword === '') {
            return [];
        }

        return array_map(
            fn (array $user): array => $this->projectUser($user),
            $this->store->searchUsers($organization, $userId, $keyword),
        );
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function friendRequests(array $identity): array
    {
        [$organization, $userId] = $this->identity($identity);

        return array_map(
            fn (array $request): array => $this->projectFriendRequest($request),
            $this->store->friendRequests($organization, $userId),
        );
    }

    /** @param array<string, mixed> $identity @return array{status: string, message: string} */
    public function sendFriendRequest(
        array $identity,
        int $toOrganization,
        string $toUserId,
        string $message,
    ): array
    {
        [$organization, $userId] = $this->identity($identity);
        if ($toOrganization <= 0) {
            throw new ApiException('to_organization 格式无效。', 422);
        }
        $toUserId = $this->identifier($toUserId, 'to_user_id', 64);
        if ($organization === $toOrganization && hash_equals($userId, $toUserId)) {
            throw new ApiException('不能添加自己为好友。', 422);
        }
        $message = $this->text($message, '好友申请', 120, true);

        $result = $this->store->sendFriendRequest(
            $organization,
            $userId,
            $toOrganization,
            $toUserId,
            $message,
            $this->nowText(),
        );
        if (is_array($result['_realtime_event'] ?? null)) {
            $event = $result['_realtime_event'];
            $eventOrganization = (int) ($result['_realtime_event_organization'] ?? $organization);
            if (is_array($event['from_user'] ?? null)) {
                $event['from_user'] = $this->projectUser($event['from_user']);
            }
            $this->publishRealtime(static function (WebImRealtimePublisherInterface $publisher) use (
                $eventOrganization,
                $event,
            ): void {
                $publisher->publishFriendRequestCreated($eventOrganization, $event);
            });
        }

        return [
            'status' => (string) $result['status'],
            'message' => (string) $result['message'],
        ];
    }

    /** @param array<string, mixed> $identity @return array{status: string} */
    public function handleFriendRequest(array $identity, int $requestId, string $action): array
    {
        [$organization, $userId] = $this->identity($identity);
        if ($requestId <= 0) {
            throw new ApiException('好友申请 id 无效。', 422);
        }
        $action = strtolower(trim($action));
        if (!in_array($action, ['accept', 'reject'], true)) {
            throw new ApiException('好友申请处理动作无效。', 422);
        }

        return $this->store->handleFriendRequest(
            $organization,
            $userId,
            $requestId,
            $action,
            $this->nowText(),
        );
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    public function createGroup(array $identity, string $title, mixed $memberIds): array
    {
        [$organization, $userId] = $this->identity($identity);
        $title = $this->text($title, '群聊名称', 100, true);
        $members = $this->userIds($memberIds);
        $members[] = $userId;
        $members = array_values(array_unique($members));
        if (count($members) < 3) {
            throw new ApiException('群聊至少需要包含自己和 2 位好友。', 422);
        }

        return $this->projectConversation($organization, $this->store->createGroup(
            $organization,
            $userId,
            $title !== '' ? $title : '群聊',
            $members,
            $this->nowText(),
        ));
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function groupMembers(array $identity, string $conversationId): array
    {
        [$organization, $userId] = $this->identity($identity);

        return array_map(
            fn (array $member): array => $this->projectGroupMember($member),
            $this->store->groupMembers(
            $organization,
            $userId,
            $this->identifier($conversationId, 'conversation_id', 64),
            ),
        );
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function addGroupMembers(array $identity, string $conversationId, mixed $memberIds): array
    {
        [$organization, $userId] = $this->identity($identity);
        $members = array_values(array_filter(
            $this->userIds($memberIds),
            static fn (string $memberId): bool => !hash_equals($userId, $memberId),
        ));
        if ($members === []) {
            throw new ApiException('请选择需要邀请的成员。', 422);
        }

        return array_map(
            fn (array $member): array => $this->projectGroupMember($member),
            $this->store->addGroupMembers(
            $organization,
            $userId,
            $this->identifier($conversationId, 'conversation_id', 64),
            $members,
            $this->nowText(),
            ),
        );
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    public function updateGroupProfile(
        array $identity,
        string $conversationId,
        mixed $title,
        mixed $avatarFileId,
        mixed $description,
        bool $notifyAll,
    ): array {
        [$organization, $userId] = $this->identity($identity);
        $title = $title === null ? null : $this->text((string) $title, '群聊名称', 100, false);
        $avatarFileId = $avatarFileId === null
            ? null
            : $this->avatarFileId((string) $avatarFileId, $organization, $userId);
        $description = $description === null
            ? null
            : $this->text((string) $description, '群说明', 500, true);
        if ($title === null && $avatarFileId === null && $description === null) {
            throw new ApiException('没有需要更新的群资料。', 422);
        }

        $result = $this->store->updateGroupProfile(
            $organization,
            $userId,
            $this->identifier($conversationId, 'conversation_id', 64),
            $title,
            $avatarFileId,
            $description,
            $notifyAll,
            $this->nowText(),
        );
        // 群说明通知与消息本体同事务写入 outbox，RabbitMQ 是唯一权威投递链路。
        // 这里不得再走 Redis 直推，否则客户端会对同一 message_id 执行两次副作用。
        unset($result['_notice_recipient_user_ids']);
        if (is_array($result['notice_message'] ?? null)) {
            $result['notice_message'] = $this->projectMessage($result['notice_message']);
        }

        return $this->projectConversation($organization, $result);
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function updateGroupManagers(array $identity, string $conversationId, mixed $managerUserIds): array
    {
        [$organization, $userId] = $this->identity($identity);
        $managerIds = array_values(array_filter(
            $this->userIds($managerUserIds, true),
            static fn (string $memberId): bool => !hash_equals($userId, $memberId),
        ));

        return array_map(
            fn (array $member): array => $this->projectGroupMember($member),
            $this->store->updateGroupManagers(
            $organization,
            $userId,
            $this->identifier($conversationId, 'conversation_id', 64),
            $managerIds,
            $this->nowText(),
            ),
        );
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function updateGroupMemberStatus(
        array $identity,
        string $conversationId,
        string $memberUserId,
        int $status,
        string $muteUntil,
    ): array {
        [$organization, $userId] = $this->identity($identity);
        if (!in_array($status, [1, 2], true)) {
            throw new ApiException('成员状态无效。', 422);
        }
        $muteUntilValue = null;
        if ($status === 2) {
            $muteTimestamp = strtotime(trim($muteUntil));
            if ($muteTimestamp === false || $muteTimestamp <= $this->now()) {
                throw new ApiException('请选择未来的禁言截止时间。', 422);
            }
            $muteUntilValue = date('Y-m-d H:i:s', $muteTimestamp);
        }

        return array_map(
            fn (array $member): array => $this->projectGroupMember($member),
            $this->store->updateGroupMemberStatus(
            $organization,
            $userId,
            $this->identifier($conversationId, 'conversation_id', 64),
            $this->identifier($memberUserId, 'member_user_id', 64),
            $status,
            $muteUntilValue,
            $this->nowText(),
            ),
        );
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function removeGroupMember(array $identity, string $conversationId, string $memberUserId): array
    {
        [$organization, $userId] = $this->identity($identity);

        return array_map(
            fn (array $member): array => $this->projectGroupMember($member),
            $this->store->removeGroupMember(
            $organization,
            $userId,
            $this->identifier($conversationId, 'conversation_id', 64),
            $this->identifier($memberUserId, 'member_user_id', 64),
            $this->nowText(),
            ),
        );
    }

    /** @param array<string, mixed> $identity @return array{conversation_id: string, is_pinned: bool, is_muted: bool} */
    public function updateConversationSetting(
        array $identity,
        string $conversationId,
        mixed $isPinned,
        mixed $isMuted,
    ): array {
        [$organization, $userId] = $this->identity($identity);
        $pinned = $this->optionalBoolean($isPinned, 'is_pinned');
        $muted = $this->optionalBoolean($isMuted, 'is_muted');
        if ($pinned === null && $muted === null) {
            throw new ApiException('没有需要更新的会话设置。', 422);
        }

        return $this->store->updateConversationSetting(
            $organization,
            $userId,
            $this->identifier($conversationId, 'conversation_id', 64),
            $pinned,
            $muted,
            $this->nowText(),
        );
    }

    /** @param array<string, mixed> $identity @return array{friend_organization: int, friend_user_id: string, remark: string} */
    public function updateFriendRemark(
        array $identity,
        int $friendOrganization,
        string $friendUserId,
        string $remark,
    ): array
    {
        [$organization, $userId] = $this->identity($identity);
        if ($friendOrganization <= 0) {
            throw new ApiException('friend_organization 格式无效。', 422);
        }
        $friendUserId = $this->identifier($friendUserId, 'friend_user_id', 64);
        $remark = $this->text($remark, '好友备注', 64, true);

        return $this->store->updateFriendRemark(
            $organization,
            $userId,
            $friendOrganization,
            $friendUserId,
            $remark,
            $this->nowText(),
        );
    }

    /** @param array<string, mixed> $identity @return list<array<string, mixed>> */
    public function searchMessages(
        array $identity,
        string $conversationId,
        string $keyword,
        int $messageType,
        int $limit,
    ): array {
        [$organization, $userId] = $this->identity($identity);
        $keyword = $this->text($keyword, '消息搜索关键词', 120, true);
        if ($messageType < 0 || $messageType > 255 || ($keyword === '' && $messageType === 0)) {
            throw new ApiException('请提供消息搜索关键词或类型。', 422);
        }

        return array_map(
            fn (array $message): array => $this->projectMessage($message),
            $this->store->searchMessages(
            $organization,
            $userId,
            $this->identifier($conversationId, 'conversation_id', 64),
            $keyword,
            $messageType,
            min(max($limit, 1), 100),
            ),
        );
    }

    /** @param array<string, mixed> $identity @return array{int, string} */
    private function identity(array $identity): array
    {
        $organization = (int) ($identity['organization'] ?? 0);
        if ($organization <= 0) {
            throw new ApiException('Web 登录上下文无效。', 401);
        }

        return [
            $organization,
            $this->identifier((string) ($identity['user_id'] ?? ''), 'user_id', 64, 401),
        ];
    }

    private function identifier(string $value, string $name, int $maxLength, int $code = 422): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/', $value) !== 1
        ) {
            throw new ApiException($name . ' 格式无效。', $code);
        }

        return $value;
    }

    private function optionalIdentifier(string $value, string $name, int $maxLength): string
    {
        $value = trim($value);

        return $value === '' ? '' : $this->identifier($value, $name, $maxLength);
    }

    private function text(string $value, string $name, int $maxLength, bool $allowEmpty): string
    {
        $value = trim($value);
        if (
            (!$allowEmpty && $value === '')
            || mb_strlen($value) > $maxLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1
        ) {
            throw new ApiException($name . ' 格式无效。', 422);
        }

        return $value;
    }

    /** @return list<string> */
    private function userIds(mixed $value, bool $allowEmpty = false): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            throw new ApiException('成员列表格式无效。', 422);
        }
        $result = [];
        foreach ($value as $item) {
            $id = $this->identifier((string) $item, 'member_user_id', 64);
            $result[$id] = $id;
        }
        if (!$allowEmpty && $result === []) {
            throw new ApiException('成员列表不能为空。', 422);
        }
        if (count($result) > 500) {
            throw new ApiException('单次成员数量超过限制。', 422);
        }

        return array_values($result);
    }

    private function optionalBoolean(mixed $value, string $name): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true', 'on'], true)) {
            return true;
        }
        if (in_array($value, [0, 2, '0', '2', 'false', 'off'], true)) {
            return false;
        }

        throw new ApiException($name . ' 格式无效。', 422);
    }

    private function avatarFileId(
        string $fileId,
        int $organization,
        string $operatorUserId,
    ): string {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return '';
        }

        return $this->avatars->assertOwnedImage($organization, $operatorUserId, $fileId);
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private function projectUser(array $user): array
    {
        $userOrganization = (int) ($user['organization'] ?? 0);
        if ($userOrganization <= 0) {
            throw new \RuntimeException('IM user projection requires organization.');
        }
        $fileId = trim((string) ($user['avatar_file_id'] ?? $user['avatar'] ?? ''));
        unset($user['avatar'], $user['avatar_file_id'], $user['avatar_url'], $user['avatar_expires_at']);

        return array_merge($user, $this->avatarProjection($userOrganization, $fileId));
    }

    /** @param array<string, mixed> $conversation @return array<string, mixed> */
    private function projectConversation(int $organization, array $conversation): array
    {
        $type = (int) ($conversation['conversation_type'] ?? 0);
        $peer = is_array($conversation['peer_user'] ?? null)
            ? $this->projectUser($conversation['peer_user'])
            : null;
        $members = is_array($conversation['avatar_members'] ?? null)
            ? array_map(
                fn (array $user): array => $this->projectUser($user),
                $conversation['avatar_members'],
            )
            : [];
        $fileId = $type === 1
            ? (string) ($peer['avatar_file_id'] ?? '')
            : trim((string) ($conversation['avatar'] ?? ''));
        $avatarOrganization = $type === 1
            ? (int) ($peer['organization'] ?? 0)
            : $organization;
        if ($type === 1 && $avatarOrganization <= 0) {
            throw new \RuntimeException('Single conversation avatar requires the peer organization.');
        }
        unset($conversation['avatar']);
        $conversation['peer_user'] = $peer;
        $conversation['avatar_members'] = $members;

        return array_merge($conversation, $this->avatarProjection($avatarOrganization, $fileId));
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function projectFriendRequest(array $request): array
    {
        foreach (['from_user', 'to_user'] as $key) {
            if (is_array($request[$key] ?? null)) {
                $request[$key] = $this->projectUser($request[$key]);
            }
        }

        return $request;
    }

    /** @param array<string, mixed> $member @return array<string, mixed> */
    private function projectGroupMember(array $member): array
    {
        if (is_array($member['user'] ?? null)) {
            $member['user'] = $this->projectUser($member['user']);
        }

        return $member;
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    private function projectMessage(array $message): array
    {
        if (is_array($message['sender_user'] ?? null)) {
            $senderOrg = (int) ($message['sender_organization'] ?? 0);
            if ($senderOrg <= 0) {
                throw new \RuntimeException('IM message projection requires sender_organization.');
            }
            if ((int) ($message['sender_user']['organization'] ?? 0) !== $senderOrg) {
                throw new \RuntimeException('IM message sender identity is inconsistent.');
            }
            $message['sender_user'] = $this->projectUser($message['sender_user']);
        }

        return $message;
    }

    /** @return array{avatar_file_id: string, avatar_url: string, avatar_expires_at: int} */
    private function avatarProjection(int $organization, string $fileId): array
    {
        if ($fileId === '') {
            return [
                'avatar_file_id' => '',
                'avatar_url' => '',
                'avatar_expires_at' => 0,
            ];
        }

        return $this->avatars->project($organization, $fileId);
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if ($now <= 0) {
            throw new \RuntimeException('Web IM control clock returned an invalid timestamp.');
        }

        return $now;
    }

    private function nowText(): string
    {
        return date('Y-m-d H:i:s', $this->now());
    }

    private function publishRealtime(Closure $publish): void
    {
        try {
            $publish($this->realtime);
        } catch (\Throwable $throwable) {
            error_log('Web IM realtime event enqueue failed: ' . $throwable->getMessage());
        }
    }
}
