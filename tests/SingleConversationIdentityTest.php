<?php

declare(strict_types=1);

use B8im\ImShared\Support\SingleConversationIdentity as SharedSingleConversationIdentity;
use plugin\saimulti\service\web\SingleConversationIdentity;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS {$message}\n";
        return;
    }

    $failed++;
    echo "FAIL {$message}\n";
};

$assert(
    SingleConversationIdentity::conversationId(1, 'u1', 2, 'u2')
        === 'single_2118193dd11825a86050c3575d1f9aa52849d5e3',
    'cross-organization vector is stable',
);
$assert(
    SingleConversationIdentity::conversationId(1, 'u1', 2, 'u2')
        === SharedSingleConversationIdentity::conversationId(1, 'u1', 2, 'u2'),
    'server facade delegates the canonical vector to im-shared',
);
$assert(
    SingleConversationIdentity::conversationId(2, 'u2', 1, 'u1')
        === 'single_2118193dd11825a86050c3575d1f9aa52849d5e3',
    'participant order does not change conversation id',
);
$assert(
    SingleConversationIdentity::conversationId(1, 'same', 2, 'same')
        === 'single_3d9ff05c919aa120bba0770a87bf422ba31e2e8b',
    'same user_id in different organizations remains unambiguous',
);
$assert(
    SingleConversationIdentity::conversationId(7, 'alice', 7, 'bob')
        === 'single_06077c21d48263b3d726c0c3df9daadb63e2a9b7',
    'same-organization single chat uses the unified algorithm',
);
$assert(
    SingleConversationIdentity::conversationId(1, 'same', 2, 'same')
        !== SingleConversationIdentity::conversationId(1, 'same', 3, 'same'),
    'organization participates in identity hash',
);

try {
    SingleConversationIdentity::conversationId(0, 'u1', 2, 'u2');
    $assert(false, 'missing organization must be rejected');
} catch (InvalidArgumentException) {
    $assert(true, 'missing organization is rejected');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
