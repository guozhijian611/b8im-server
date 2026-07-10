<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\service\RealtimeControlEventEnvelope;
use plugin\saimulti\service\adminIm\ThinkCacheAdminImRealtimePublisher;
use plugin\saimulti\service\tenantPolicy\ThinkCacheTenantImPolicyPublisher;
use plugin\saimulti\service\web\RedisWebImRealtimePublisher;

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
    'friend_request.created',
    7,
    ['request_id' => 9, 'from_user' => ['nickname' => 'Alice', 'user_id' => 'user-a']],
), true, flags: JSON_THROW_ON_ERROR);
$right = json_decode(RealtimeControlEventEnvelope::encode(
    'friend_request.created',
    7,
    ['from_user' => ['user_id' => 'user-a', 'nickname' => 'Alice'], 'request_id' => 9],
), true, flags: JSON_THROW_ON_ERROR);
$assert($left['event_id'] === $right['event_id'], 'canonical object key order changed event_id');
$assert(preg_match('/^[a-f0-9]{64}$/', $left['event_id']) === 1, 'event_id is not a SHA-256 identifier');

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

$web = new RedisWebImRealtimePublisher($redis);
$friendPayload = [
    'request_id' => 99,
    'from_user_id' => 'user-a',
    'to_user_id' => 'user-b',
    'message' => 'hello',
    'pending_count' => 1,
    'create_time' => '2026-07-10 12:00:00',
];
$web->publishFriendRequestCreated(7, $friendPayload);
$web->publishFriendRequestCreated(7, $friendPayload);
$friendA = json_decode($redis->pushed[2][1], true, flags: JSON_THROW_ON_ERROR);
$friendB = json_decode($redis->pushed[3][1], true, flags: JSON_THROW_ON_ERROR);
$assert(preg_match('/^[a-f0-9]{64}$/', $friendA['event_id']) === 1, 'friend request publisher omitted event_id');
$assert($friendA['event_id'] === $friendB['event_id'], 'retrying the same friend request changed event_id');
$assert(array_keys($friendA) === ['event_id', 'type', 'organization', 'data', 'time'], 'control envelope contract changed');

fwrite(STDOUT, sprintf("Realtime control publisher envelopes: %d assertions passed.\n", $assertions));
