<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class ThinkOrmWebImAssetForwardStore implements WebImAssetForwardStoreInterface
{
    private const MESSAGE_TYPES = [
        'image' => 2,
        'file' => 3,
        'voice' => 4,
        'video' => 11,
    ];

    public function accessibleAsset(
        int $organization,
        string $userId,
        string $fileId,
        string $conversationId,
        string $messageId,
    ): array {
        return Db::transaction(function () use (
            $organization,
            $userId,
            $fileId,
            $conversationId,
            $messageId,
        ): array {
            $owned = $this->activeAsset($organization, $fileId, $userId, false);
            if ($owned !== null) {
                return $owned;
            }
            if ($conversationId === '' || $messageId === '') {
                throw new ApiException('附件不存在或无访问权限。', 404);
            }

            return $this->visibleMessageAsset(
                $organization,
                $userId,
                $conversationId,
                $messageId,
                $fileId,
                null,
                false,
            );
        });
    }

    public function deriveVisibleAsset(
        int $organization,
        string $userId,
        string $conversationId,
        string $messageId,
        string $sourceFileId,
        string $kind,
        string $derivedFileId,
        string $now,
    ): array {
        return Db::transaction(function () use (
            $organization,
            $userId,
            $conversationId,
            $messageId,
            $sourceFileId,
            $kind,
            $derivedFileId,
            $now,
        ): array {
            $asset = $this->visibleMessageAsset(
                $organization,
                $userId,
                $conversationId,
                $messageId,
                $sourceFileId,
                $kind,
                true,
            );

            Db::execute(
                'INSERT INTO im_upload_asset
                    (organization, file_id, user_id, kind, name, url, storage_path,
                     size_byte, mime_type, extension, status, create_time, update_time)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE file_id = VALUES(file_id)',
                [
                    $organization,
                    $derivedFileId,
                    $userId,
                    $kind,
                    (string) $asset['name'],
                    '',
                    (string) $asset['storage_path'],
                    (int) $asset['size_byte'],
                    (string) $asset['mime_type'],
                    (string) $asset['extension'],
                    $now,
                    $now,
                ],
            );
            $derived = Db::query(
                'SELECT file_id, user_id, kind, name, url, storage_path, size_byte, mime_type, extension,
                        status, delete_time
                   FROM im_upload_asset
                  WHERE organization = ? AND BINARY file_id = BINARY ?
                  LIMIT 1
                  FOR UPDATE',
                [$organization, $derivedFileId],
            )[0] ?? null;
            if ($derived === null || !$this->sameDerivedAsset($derived, $userId, $asset)) {
                throw new ApiException('派生附件标识冲突，请稍后重试。', 409);
            }

            return $this->response($derived, $kind);
        });
    }

    /** @return array<string, mixed>|null */
    private function activeAsset(
        int $organization,
        string $fileId,
        ?string $ownerUserId,
        bool $lock,
    ): ?array {
        $sql = 'SELECT file_id, user_id, kind, name, url, storage_path, size_byte, mime_type, extension
                  FROM im_upload_asset
                 WHERE organization = ? AND BINARY file_id = BINARY ?
                   AND status = 1 AND delete_time IS NULL';
        $params = [$organization, $fileId];
        if ($ownerUserId !== null) {
            $sql .= ' AND BINARY user_id = BINARY ?';
            $params[] = $ownerUserId;
        }
        $sql .= ' LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        return Db::query($sql, $params)[0] ?? null;
    }

    /** @return array<string, mixed> */
    private function visibleMessageAsset(
        int $organization,
        string $userId,
        string $conversationId,
        string $messageId,
        string $fileId,
        ?string $expectedKind,
        bool $requireActive,
    ): array {
        CrossOrganizationSocialPolicy::lockSharedInsideTransaction();
        $viewerMembership = Db::query(
            'SELECT 1 AS present FROM im_conversation_member
              WHERE organization = ? AND BINARY conversation_id = BINARY ?
                AND member_organization = ? AND BINARY user_id = BINARY ?
                AND delete_time IS NULL LIMIT 1',
            [$organization, $conversationId, $organization, $userId],
        )[0] ?? null;
        if ($viewerMembership === null) {
            throw new ApiException('原附件消息不存在或不可见。', 404);
        }
        $guard = new WebImConversationAccessGuard();
        if ($requireActive) {
            $guard->assertAccessible($organization, $userId, $conversationId, true);
        } else {
            $guard->assertReadable($organization, $userId, $conversationId, true);
        }
        $membershipClause = $requireActive
            ? 'cm.status = 1 AND (c.conversation_type <> 2 OR cm.access_state = "active")'
            : '((c.conversation_type = 2 AND cm.access_state IN ("active", "history_only"))
                OR (c.conversation_type <> 2 AND cm.status = 1))';
        $source = Db::query(
            'SELECT i.conversation_id, i.message_id, i.message_seq, i.shard_table,
                    i.sender_id, i.sender_organization
               FROM im_message_index i
         INNER JOIN im_conversation c
                 ON c.organization = i.organization
                AND BINARY c.conversation_id = BINARY i.conversation_id
                AND c.status = 1
                AND c.delete_time IS NULL
         INNER JOIN im_conversation_member cm
                ON cm.organization = i.organization
                AND BINARY cm.conversation_id = BINARY i.conversation_id
                AND cm.member_organization = ?
                AND BINARY cm.user_id = BINARY ?
                AND cm.delete_time IS NULL
                AND ' . $membershipClause . '
              WHERE i.organization = ?
                AND BINARY i.conversation_id = BINARY ?
                AND BINARY i.message_id = BINARY ?
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
                    SELECT 1
                      FROM im_message_user_delete ud
                     WHERE ud.organization = i.organization
                       AND BINARY ud.conversation_id = BINARY i.conversation_id
                       AND BINARY ud.message_id = BINARY i.message_id
                       AND ud.user_organization = ?
                       AND BINARY ud.user_id = BINARY ?
                )
              LIMIT 1
              FOR UPDATE',
            [
                $organization,
                $userId,
                $organization,
                $conversationId,
                $messageId,
                $organization,
                $userId,
                $organization,
                $userId,
            ],
        )[0] ?? null;
        if ($source === null) {
            throw new ApiException('原附件消息不存在或不可见。', 404);
        }

        $message = Db::query(
            'SELECT conversation_id, message_id, message_seq, sender_id, sender_organization,
                    message_type, content, status, delete_time
               FROM ' . $this->quoteShard((string) $source['shard_table']) . '
              WHERE organization = ? AND BINARY conversation_id = BINARY ?
                AND BINARY message_id = BINARY ?
              LIMIT 1
              FOR UPDATE',
            [$organization, $conversationId, $messageId],
        )[0] ?? null;
        $kind = $message === null ? null : array_search((int) ($message['message_type'] ?? 0), self::MESSAGE_TYPES, true);
        if ($message === null
            || (int) ($message['status'] ?? 0) !== 1
            || ($message['delete_time'] ?? null) !== null
            || !is_string($kind)
            || ($expectedKind !== null && $kind !== $expectedKind)) {
            throw new ApiException('原附件消息不存在或不可见。', 404);
        }
        $sourceOrganization = (int) ($source['sender_organization'] ?? 0);
        if (
            $sourceOrganization <= 0
            || !hash_equals((string) $source['conversation_id'], (string) $message['conversation_id'])
            || !hash_equals((string) $source['message_id'], (string) $message['message_id'])
            || (string) $source['message_seq'] !== (string) $message['message_seq']
            || (int) ($message['sender_organization'] ?? 0) !== $sourceOrganization
            || !hash_equals((string) $source['sender_id'], (string) $message['sender_id'])
        ) {
            throw new \RuntimeException('Attachment source index and shard body binding is inconsistent.');
        }

        $content = json_decode((string) ($message['content'] ?? ''), true);
        if (!is_array($content)
            || !is_string($content['file_id'] ?? null)
            || !hash_equals($fileId, (string) $content['file_id'])) {
            throw new ApiException('原附件消息与 file_id 不匹配。', 404);
        }

        $asset = $this->activeAsset($sourceOrganization, $fileId, null, true);
        if ($asset === null || (string) $asset['kind'] !== $kind) {
            throw new ApiException('原附件不存在或已失效。', 404);
        }
        $this->assertCanonicalMetadata($content, $asset);

        return $asset;
    }

    /** @param array<string, mixed> $content @param array<string, mixed> $asset */
    private function assertCanonicalMetadata(array $content, array $asset): void
    {
        if (
            (string) ($content['url'] ?? '') !== (string) $asset['url']
            || (string) ($content['name'] ?? '') !== (string) $asset['name']
            || (int) ($content['size'] ?? -1) !== (int) $asset['size_byte']
            || (string) ($content['mime_type'] ?? '') !== (string) $asset['mime_type']
            || (string) ($content['extension'] ?? '') !== (string) $asset['extension']
        ) {
            throw new ApiException('原附件消息元数据校验失败。', 409);
        }
    }

    /** @param array<string, mixed> $derived @param array<string, mixed> $source */
    private function sameDerivedAsset(array $derived, string $userId, array $source): bool
    {
        return hash_equals($userId, (string) $derived['user_id'])
            && (string) $derived['kind'] === (string) $source['kind']
            && (string) $derived['name'] === (string) $source['name']
            && (string) $derived['url'] === ''
            && (string) $derived['storage_path'] === (string) $source['storage_path']
            && (int) $derived['size_byte'] === (int) $source['size_byte']
            && (string) $derived['mime_type'] === (string) $source['mime_type']
            && (string) $derived['extension'] === (string) $source['extension']
            && (int) $derived['status'] === 1
            && ($derived['delete_time'] ?? null) === null;
    }

    /**
     * @param array<string, mixed> $asset
     * @return array{file_id: string, kind: string, name: string, size: int, mime_type: string, extension: string}
     */
    private function response(array $asset, string $kind): array
    {
        return [
            'file_id' => (string) $asset['file_id'],
            'kind' => $kind,
            'name' => (string) $asset['name'],
            'size' => (int) $asset['size_byte'],
            'mime_type' => (string) $asset['mime_type'],
            'extension' => (string) $asset['extension'],
        ];
    }

    private function quoteShard(string $table): string
    {
        if (preg_match('/^im_message_\d{4}_\d{6}$/', $table) !== 1) {
            throw new \RuntimeException('IM message index contains an invalid shard table.');
        }

        return '`' . $table . '`';
    }
}
