<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

/**
 * Validates the persisted topology before any Web HTTP conversation access.
 *
 * Cross-organization singles are never inferred from one home. The canonical
 * row, both home projections and the exact two composite identities must all
 * agree. Group conversations stay single-home and reject foreign members.
 */
final class WebImConversationAccessGuard
{
    private const CONVERSATION_SINGLE = 1;
    private const CONVERSATION_GROUP = 2;

    /**
     * @return array{
     *   conversation_type:int,
     *   is_cross_organization:bool,
     *   homes:list<int>,
     *   member:array<string,mixed>,
     *   peer_identity:?array{organization:int,user_id:string},
     *   cross_org_access_snapshot_id:?string
     * }
     */
    public function assertAccessible(
        int $organization,
        string $userId,
        string $conversationId,
        bool $lock = false,
    ): array {
        if ($organization <= 0 || trim($userId) === '' || trim($conversationId) === '') {
            throw new ApiException('没有该会话的访问权限。', 403);
        }
        $lockedPolicy = $lock
            ? CrossOrganizationSocialPolicy::lockSharedInsideTransaction()
            : null;

        // Query inactive rows too so a damaged canonical cannot silently
        // downgrade to a same-home conversation. For writes this first read
        // discovers the homes before the policy -> organizations -> canonical
        // lock sequence.
        $canonical = Db::query(
            'SELECT conversation_id, left_organization, left_user_id,
                    right_organization, right_user_id, status
               FROM im_cross_organization_conversation
              WHERE conversation_id = ?
              LIMIT 1',
            [$conversationId],
        )[0] ?? null;

        if ($canonical !== null && $lock) {
            $left = $this->canonicalIdentity($canonical, 'left');
            $right = $this->canonicalIdentity($canonical, 'right');
            $this->assertActiveOrganizations(
                [$left['organization'], $right['organization']],
                true,
            );
            $canonical = Db::query(
                'SELECT conversation_id, left_organization, left_user_id,
                        right_organization, right_user_id, status
                   FROM im_cross_organization_conversation
                  WHERE conversation_id = ?
                  LIMIT 1 FOR UPDATE',
                [$conversationId],
            )[0] ?? null;
            if ($canonical === null) {
                throw new \RuntimeException(
                    'Cross-organization canonical row disappeared while locking.',
                );
            }
            if (
                $this->canonicalIdentity($canonical, 'left') !== $left
                || $this->canonicalIdentity($canonical, 'right') !== $right
            ) {
                throw new \RuntimeException(
                    'Cross-organization canonical identities changed while locking.',
                );
            }
        }

        if ($canonical !== null) {
            return $this->assertCanonicalSingle(
                $organization,
                $userId,
                $conversationId,
                $canonical,
                $lock,
                $lockedPolicy,
            );
        }

        $this->assertActiveOrganizations([$organization], $lock);
        $conversation = $this->activeConversation($organization, $conversationId, $lock);
        if ($conversation === null) {
            throw new ApiException('没有该会话的访问权限。', 403);
        }
        $members = $this->activeMembers($organization, $conversationId, $lock);
        $type = (int) ($conversation['conversation_type'] ?? 0);

        if ($type === self::CONVERSATION_GROUP) {
            $member = $this->assertGroupTopology(
                $organization,
                $userId,
                $members,
            );

            return [
                'conversation_type' => self::CONVERSATION_GROUP,
                'is_cross_organization' => false,
                'homes' => [$organization],
                'member' => $member,
                'peer_identity' => null,
                'cross_org_access_snapshot_id' => null,
            ];
        }
        if ($type !== self::CONVERSATION_SINGLE) {
            throw new \RuntimeException('Conversation type is invalid.');
        }

        [$member, $peer] = $this->singleIdentities(
            $organization,
            $userId,
            $conversationId,
            $members,
        );
        if ($peer['organization'] !== $organization) {
            throw new \RuntimeException(
                'Cross-organization single conversation canonical row is missing.',
            );
        }
        return [
            'conversation_type' => self::CONVERSATION_SINGLE,
            'is_cross_organization' => false,
            'homes' => [$organization],
            'member' => $member,
            'peer_identity' => $peer,
            'cross_org_access_snapshot_id' => null,
        ];
    }

    /** @param list<int> $organizations */
    public function assertActiveOrganizations(array $organizations, bool $lock = false): void
    {
        $organizations = array_values(array_unique(array_filter(
            array_map('intval', $organizations),
            static fn (int $value): bool => $value > 0,
        )));
        sort($organizations, SORT_NUMERIC);
        if ($organizations === []) {
            throw new ApiException('机构不存在或已停用。', 403);
        }
        $placeholders = implode(',', array_fill(0, count($organizations), '?'));
        $rows = Db::query(
            'SELECT id
               FROM sm_system_organization
              WHERE id IN (' . $placeholders . ')
                AND status = 1
                AND delete_time IS NULL
           ORDER BY id ASC' . ($lock ? ' LOCK IN SHARE MODE' : ''),
            $organizations,
        );
        $active = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ($active !== $organizations) {
            throw new ApiException('机构不存在或已停用。', 403);
        }
    }

    /**
     * @param array<string,mixed> $canonical
     * @return array{
     *   conversation_type:int,
     *   is_cross_organization:bool,
     *   homes:list<int>,
     *   member:array<string,mixed>,
     *   peer_identity:array{organization:int,user_id:string},
     *   cross_org_access_snapshot_id:string
     * }
     */
    private function assertCanonicalSingle(
        int $organization,
        string $userId,
        string $conversationId,
        array $canonical,
        bool $lock,
        ?array $lockedPolicy,
    ): array {
        if ((int) ($canonical['status'] ?? 0) !== 1) {
            throw new \RuntimeException(
                'Cross-organization single conversation canonical row is inactive.',
            );
        }
        $left = $this->canonicalIdentity($canonical, 'left');
        $right = $this->canonicalIdentity($canonical, 'right');
        if ($left['organization'] === $right['organization']) {
            throw new \RuntimeException(
                'Cross-organization canonical identities must have distinct organizations.',
            );
        }
        $orderedIdentities = [
            SingleConversationIdentity::identity($left['organization'], $left['user_id']),
            SingleConversationIdentity::identity($right['organization'], $right['user_id']),
        ];
        if (strcmp($orderedIdentities[0], $orderedIdentities[1]) >= 0) {
            throw new \RuntimeException(
                'Cross-organization canonical identities are not in canonical order.',
            );
        }
        if (!hash_equals(
            SingleConversationIdentity::conversationId(
                $left['organization'],
                $left['user_id'],
                $right['organization'],
                $right['user_id'],
            ),
            $conversationId,
        )) {
            throw new \RuntimeException(
                'Cross-organization canonical conversation_id does not match its identities.',
            );
        }

        $viewerIdentity = SingleConversationIdentity::identity($organization, $userId);
        if (!in_array($viewerIdentity, $orderedIdentities, true)) {
            throw new ApiException('没有该会话的访问权限。', 403);
        }
        $crossOrgEnabled = $lockedPolicy !== null
            ? (bool) ($lockedPolicy['enabled'] ?? false)
            : CrossOrganizationSocialPolicy::isEnabled();
        if (!$crossOrgEnabled) {
            throw new ApiException('跨租户单聊未开放。', 403);
        }
        $accessSnapshotId = $lockedPolicy !== null
            ? (string) ($lockedPolicy['access_snapshot_id'] ?? '')
            : CrossOrganizationSocialPolicy::accessSnapshotId();
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $accessSnapshotId) !== 1) {
            throw new ApiException('跨租户单聊访问快照无效。', 403);
        }

        $homes = [$left['organization'], $right['organization']];
        sort($homes, SORT_NUMERIC);
        $homes = array_values(array_unique($homes));
        if (count($homes) !== 2 || !in_array($organization, $homes, true)) {
            throw new \RuntimeException('Cross-organization conversation homes are invalid.');
        }
        // Write paths already hold these organization locks before the
        // canonical row; this recheck is non-expansive and validates the same
        // ordered home set. Read-only paths validate current availability here.
        $this->assertActiveOrganizations($homes, $lock);

        $expected = $orderedIdentities;
        sort($expected, SORT_STRING);
        $viewerMember = null;
        foreach ($homes as $home) {
            // Homes and their members are locked in organization order after
            // the canonical row, before any read cursor or outbox write.
            $conversation = $this->activeConversation($home, $conversationId, $lock);
            if ($conversation === null
                || (int) ($conversation['conversation_type'] ?? 0) !== self::CONVERSATION_SINGLE) {
                throw new \RuntimeException(
                    'Cross-organization single conversation home projection is incomplete.',
                );
            }
            $members = $this->activeMembers($home, $conversationId, $lock);
            $actual = $this->memberIdentityStrings($members);
            if ($actual !== $expected) {
                throw new \RuntimeException(
                    'Cross-organization single conversation home identities do not match canonical.',
                );
            }
            if ($home === $organization) {
                foreach ($members as $member) {
                    if ((int) ($member['member_organization'] ?? 0) === $organization
                        && hash_equals((string) ($member['user_id'] ?? ''), $userId)) {
                        $viewerMember = $member;
                        break;
                    }
                }
            }
        }
        if ($viewerMember === null) {
            throw new ApiException('没有该会话的访问权限。', 403);
        }

        $peer = hash_equals($viewerIdentity, $orderedIdentities[0]) ? $right : $left;

        return [
            'conversation_type' => self::CONVERSATION_SINGLE,
            'is_cross_organization' => true,
            'homes' => $homes,
            'member' => $viewerMember,
            'peer_identity' => $peer,
            'cross_org_access_snapshot_id' => $accessSnapshotId,
        ];
    }

    /** @return array<string,mixed>|null */
    private function activeConversation(int $organization, string $conversationId, bool $lock): ?array
    {
        return Db::query(
            'SELECT *
               FROM im_conversation
              WHERE organization = ?
                AND conversation_id = ?
                AND status = 1
                AND delete_time IS NULL
              LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [$organization, $conversationId],
        )[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    private function activeMembers(int $organization, string $conversationId, bool $lock): array
    {
        return Db::query(
            'SELECT *
               FROM im_conversation_member
              WHERE organization = ?
                AND conversation_id = ?
                AND status = 1
                AND delete_time IS NULL
           ORDER BY member_organization ASC, user_id ASC' . ($lock ? ' FOR UPDATE' : ''),
            [$organization, $conversationId],
        );
    }

    /**
     * @param list<array<string,mixed>> $members
     * @return array<string,mixed>
     */
    private function assertGroupTopology(
        int $organization,
        string $userId,
        array $members,
    ): array {
        $viewer = null;
        foreach ($members as $member) {
            if ((int) ($member['member_organization'] ?? 0) !== $organization) {
                throw new \RuntimeException(
                    'Group conversation contains a member outside its home organization.',
                );
            }
            if (hash_equals((string) ($member['user_id'] ?? ''), $userId)) {
                $viewer = $member;
            }
        }
        if ($viewer === null) {
            throw new ApiException('没有该会话的访问权限。', 403);
        }

        return $viewer;
    }

    /**
     * @param list<array<string,mixed>> $members
     * @return array{0:array<string,mixed>,1:array{organization:int,user_id:string}}
     */
    private function singleIdentities(
        int $organization,
        string $userId,
        string $conversationId,
        array $members,
    ): array {
        if (count($members) !== 2) {
            throw new \RuntimeException(
                'Single conversation must have exactly two active composite identities.',
            );
        }
        $viewer = null;
        $peer = null;
        $identities = [];
        foreach ($members as $member) {
            $memberOrganization = (int) ($member['member_organization'] ?? 0);
            $memberUserId = trim((string) ($member['user_id'] ?? ''));
            $identity = SingleConversationIdentity::identity($memberOrganization, $memberUserId);
            if (isset($identities[$identity])) {
                throw new \RuntimeException(
                    'Single conversation contains duplicate composite identities.',
                );
            }
            $identities[$identity] = true;
            if ($memberOrganization === $organization && hash_equals($memberUserId, $userId)) {
                $viewer = $member;
            } else {
                $peer = ['organization' => $memberOrganization, 'user_id' => $memberUserId];
            }
        }
        if ($viewer === null || $peer === null) {
            throw new \RuntimeException(
                'Single conversation does not contain exactly one viewer identity.',
            );
        }
        if (!hash_equals(
            SingleConversationIdentity::conversationId(
                $organization,
                $userId,
                $peer['organization'],
                $peer['user_id'],
            ),
            $conversationId,
        )) {
            throw new \RuntimeException(
                'Single conversation_id does not match its composite identities.',
            );
        }

        return [$viewer, $peer];
    }

    /**
     * @param list<array<string,mixed>> $members
     * @return list<string>
     */
    private function memberIdentityStrings(array $members): array
    {
        if (count($members) !== 2) {
            throw new \RuntimeException(
                'Single conversation must have exactly two active composite identities.',
            );
        }
        $identities = [];
        foreach ($members as $member) {
            $identity = SingleConversationIdentity::identity(
                (int) ($member['member_organization'] ?? 0),
                (string) ($member['user_id'] ?? ''),
            );
            if (isset($identities[$identity])) {
                throw new \RuntimeException(
                    'Single conversation contains duplicate composite identities.',
                );
            }
            $identities[$identity] = true;
        }
        $values = array_keys($identities);
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param array<string,mixed> $canonical
     * @return array{organization:int,user_id:string}
     */
    private function canonicalIdentity(array $canonical, string $side): array
    {
        $organization = (int) ($canonical[$side . '_organization'] ?? 0);
        $userId = trim((string) ($canonical[$side . '_user_id'] ?? ''));
        SingleConversationIdentity::identity($organization, $userId);

        return ['organization' => $organization, 'user_id' => $userId];
    }
}
