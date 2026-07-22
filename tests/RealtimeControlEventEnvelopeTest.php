<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\service\RealtimeControlEventEnvelope;
use plugin\saimulti\service\adminIm\ThinkCacheAdminImRealtimePublisher;
use plugin\saimulti\service\tenantPolicy\ThinkCacheTenantImPolicyPublisher;

final class RealtimeEnvelopeRedis
{
    /** @var list<array{0: string, 1: string}> */
    public array $pushed = [];

    /** @var list<string> */
    public array $deleted = [];

    public function del(string $key): int
    {
        $this->deleted[] = $key;

        return 1;
    }

    public function rPush(string $key, string $value): int
    {
        $this->pushed[] = [$key, $value];

        return count($this->pushed);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};

$left = json_decode(RealtimeControlEventEnvelope::encode(
    'auth.session_revoked',
    7,
    ['session_id' => 'session-a', 'actor' => ['organization' => 7, 'user_id' => 'user-a']],
), true, flags: JSON_THROW_ON_ERROR);
$right = json_decode(RealtimeControlEventEnvelope::encode(
    'auth.session_revoked',
    7,
    ['actor' => ['user_id' => 'user-a', 'organization' => 7], 'session_id' => 'session-a'],
), true, flags: JSON_THROW_ON_ERROR);
$assert($left['event_id'] === $right['event_id'], 'canonical object key order changed event_id');
$assert(preg_match('/^[a-f0-9]{64}$/', $left['event_id']) === 1, 'event_id is not a SHA-256 identifier');
try {
    RealtimeControlEventEnvelope::encode(
        'friend_request.created',
        7,
        ['request_id' => 9],
    );
    $assert(false, 'legacy friend request envelope path was retained');
} catch (InvalidArgumentException) {
    $assert(true, 'friend requests require the strict transactional envelope builder');
}

$redis = new RealtimeEnvelopeRedis();
(new ThinkCacheTenantImPolicyPublisher($redis))->invalidateAndPublish(7, 3, ['type' => 'tenant', 'id' => 8]);
$assert($redis->deleted === ['tenant_im_policy:7'], 'tenant policy publisher did not invalidate first');
$policy = json_decode($redis->pushed[0][1], true, flags: JSON_THROW_ON_ERROR);
$assert($policy['type'] === 'tenant.policy.changed' && preg_match('/^[a-f0-9]{64}$/', $policy['event_id']) === 1, 'policy publisher omitted event_id');

$admin = new ThinkCacheAdminImRealtimePublisher($redis);
$assert($admin->publish('auth.session_revoked', [
    'organization' => 7,
    'user_id' => 'user-a',
    'device_id' => 'device-a',
    'credential_session_ids' => ['credential-a'],
    'occurred_at' => '2026-07-10 12:00:00',
]), 'admin publisher rejected a canonical event');
$adminEvent = json_decode($redis->pushed[1][1], true, flags: JSON_THROW_ON_ERROR);
$assert(preg_match('/^[a-f0-9]{64}$/', $adminEvent['event_id']) === 1, 'admin publisher omitted event_id');

$friendA = RealtimeControlEventEnvelope::friendRequest(
    'friend_request.created',
    99,
    7,
    'user-a',
    8,
    'user-b',
    8,
    'user-b',
    7,
    'user-a',
    '12',
    '2026-07-10 12:00:00',
    null,
);
$friendB = RealtimeControlEventEnvelope::friendRequest(
    'friend_request.created',
    99,
    7,
    'user-a',
    8,
    'user-b',
    8,
    'user-b',
    7,
    'user-a',
    '12',
    '2026-07-10 12:00:00',
    null,
);
$expectedId = hash('sha256', json_encode(
    ['friend_request.v1', 99, 'friend_request.created', '7', 'user-a', '8', 'user-b', '8', 'user-b', '12'],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
));
$assert($friendA['event_id'] === $expectedId, 'friend request event_id tuple changed');
$assert($friendA === $friendB, 'retrying the same friend request changed its immutable envelope');
$assert(
    array_keys($friendA) === ['event_id', 'type', 'organization', 'data']
    && $friendA['type'] === 'friend_request'
    && $friendA['organization'] === '8',
    'friend request top-level envelope contract changed',
);
$assert(
    array_keys($friendA['data']) === [
        'event', 'request_id', 'status', 'from_organization', 'from_user_id',
        'to_organization', 'to_user_id', 'target_organization', 'target_user_id',
        'actor_organization', 'actor_user_id', 'cross_org_access_snapshot_id',
        'create_time', 'handle_time',
    ]
    && $friendA['data']['event'] === 'created'
    && $friendA['data']['status'] === 1
    && $friendA['data']['cross_org_access_snapshot_id'] === '12'
    && $friendA['data']['handle_time'] === null,
    'friend request created data contract changed',
);
$accepted = RealtimeControlEventEnvelope::friendRequest(
    'friend_request.accepted',
    99,
    7,
    'user-a',
    8,
    'user-b',
    7,
    'user-a',
    8,
    'user-b',
    '12',
    '2026-07-10 12:00:00',
    '2026-07-10 12:01:00',
);
$assert(
    $accepted['organization'] === '7'
    && $accepted['data']['event'] === 'accepted'
    && $accepted['data']['target_organization'] === '7'
    && $accepted['data']['actor_organization'] === '8'
    && $accepted['data']['status'] === 2,
    'friend request terminal direction changed',
);
$sameOrg = RealtimeControlEventEnvelope::friendRequest(
    'friend_request.rejected',
    100,
    7,
    'user-a',
    7,
    'user-c',
    7,
    'user-a',
    7,
    'user-c',
    null,
    '2026-07-10 12:00:00',
    '2026-07-10 12:01:00',
);
$assert(
    array_key_exists('cross_org_access_snapshot_id', $sameOrg['data'])
    && $sameOrg['data']['cross_org_access_snapshot_id'] === null,
    'same-org friend request must keep a null cross-org snapshot field',
);
try {
    RealtimeControlEventEnvelope::friendRequest(
        'friend_request.created',
        9007199254740992,
        7,
        'user-a',
        7,
        'user-b',
        7,
        'user-b',
        7,
        'user-a',
        null,
        '2026-07-10 12:00:00',
        null,
    );
    $assert(false, 'unsafe JSON request_id was accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'unsafe JSON request_id fails closed');
}
foreach ([
    [
        'friend_request.created', 101, 7, 'user-a', 8, 'user-b',
        8, 'user-b', 7, 'user-a', '18446744073709551616',
        '2026-07-10 12:00:00', null,
    ],
    [
        'friend_request.created', 102, 7, 'user-a', 7, 'user-a',
        7, 'user-a', 7, 'user-a', null,
        '2026-07-10 12:00:00', null,
    ],
    [
        'friend_request.created', 103, 7, 'user-a', 7, 'user-b',
        7, 'user-b', 7, 'user-a', null,
        '0000-01-01 00:00:00', null,
    ],
] as $invalidFriendEvent) {
    try {
        RealtimeControlEventEnvelope::friendRequest(...$invalidFriendEvent);
        $assert(false, 'invalid friend envelope boundary was accepted');
    } catch (InvalidArgumentException) {
        $assert(true, 'invalid friend envelope boundary fails closed');
    }
}

fwrite(STDOUT, sprintf("Realtime control publisher envelopes: %d assertions passed.\n", $assertions));
