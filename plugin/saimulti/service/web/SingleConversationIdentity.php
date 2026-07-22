<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use B8im\ImShared\Support\SingleConversationIdentity as SharedSingleConversationIdentity;

/**
 * Server namespace compatibility facade.
 *
 * The identity contract and hash algorithm live exclusively in im-shared.
 */
final class SingleConversationIdentity
{
    public static function conversationId(
        int $leftOrganization,
        string $leftUserId,
        int $rightOrganization,
        string $rightUserId,
    ): string {
        return SharedSingleConversationIdentity::conversationId(
            $leftOrganization,
            $leftUserId,
            $rightOrganization,
            $rightUserId,
        );
    }

    public static function identity(int $organization, string $userId): string
    {
        return SharedSingleConversationIdentity::identity($organization, $userId);
    }
}
