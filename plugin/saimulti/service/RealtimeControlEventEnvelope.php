<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

final class RealtimeControlEventEnvelope
{
    private const FRIEND_REQUEST_VERSION = 'friend_request.v1';

    /** @return array{event_id:string,type:string,organization:string,data:array<string,mixed>} */
    public static function friendRequest(
        string $eventType,
        int $requestId,
        int $fromOrganization,
        string $fromUserId,
        int $toOrganization,
        string $toUserId,
        int $targetOrganization,
        string $targetUserId,
        int $actorOrganization,
        string $actorUserId,
        ?string $crossOrgAccessSnapshotId,
        string $createTime,
        ?string $handleTime,
    ): array {
        $statuses = [
            'friend_request.created' => 1,
            'friend_request.accepted' => 2,
            'friend_request.rejected' => 3,
        ];
        $status = $statuses[$eventType] ?? null;
        if ($requestId <= 0 || $requestId > 9007199254740991 || $status === null) {
            throw new \InvalidArgumentException('Friend request control event identity is invalid.');
        }
        $eventName = substr($eventType, strlen('friend_request.'));
        foreach ([
            [$fromOrganization, $fromUserId],
            [$toOrganization, $toUserId],
            [$targetOrganization, $targetUserId],
            [$actorOrganization, $actorUserId],
        ] as [$organization, $userId]) {
            if ($organization <= 0
                || $organization > 4294967295
                || $userId === ''
                || trim($userId) !== $userId
                || strlen($userId) > 64
                || str_contains($userId, "\0")
                || str_contains($userId, '|')) {
                throw new \InvalidArgumentException('Friend request control event participant is invalid.');
            }
        }
        $crossOrganization = $fromOrganization !== $toOrganization;
        if (!$crossOrganization && hash_equals($fromUserId, $toUserId)) {
            throw new \InvalidArgumentException('Friend request control event cannot target the actor identity.');
        }
        if (($crossOrganization && !self::isPositiveDecimal($crossOrgAccessSnapshotId))
            || (!$crossOrganization && $crossOrgAccessSnapshotId !== null)) {
            throw new \InvalidArgumentException('Friend request control event snapshot is invalid.');
        }
        if (!self::isDateTime($createTime)
            || ($status === 1 && $handleTime !== null)
            || ($status !== 1 && !self::isDateTime($handleTime))) {
            throw new \InvalidArgumentException('Friend request control event time is invalid.');
        }

        $terminal = $status !== 1;
        $expectedTargetOrganization = $terminal ? $fromOrganization : $toOrganization;
        $expectedTargetUserId = $terminal ? $fromUserId : $toUserId;
        $expectedActorOrganization = $terminal ? $toOrganization : $fromOrganization;
        $expectedActorUserId = $terminal ? $toUserId : $fromUserId;
        if ($targetOrganization !== $expectedTargetOrganization
            || !hash_equals($targetUserId, $expectedTargetUserId)
            || $actorOrganization !== $expectedActorOrganization
            || !hash_equals($actorUserId, $expectedActorUserId)) {
            throw new \InvalidArgumentException('Friend request control event direction is invalid.');
        }

        $fromOrganizationText = (string) $fromOrganization;
        $toOrganizationText = (string) $toOrganization;
        $targetOrganizationText = (string) $targetOrganization;
        $actorOrganizationText = (string) $actorOrganization;
        $canonicalTuple = [
            self::FRIEND_REQUEST_VERSION,
            $requestId,
            $eventType,
            $fromOrganizationText,
            $fromUserId,
            $toOrganizationText,
            $toUserId,
            $targetOrganizationText,
            $targetUserId,
            $crossOrgAccessSnapshotId,
        ];
        $eventId = hash('sha256', json_encode(
            $canonicalTuple,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return [
            'event_id' => $eventId,
            'type' => 'friend_request',
            'organization' => $targetOrganizationText,
            'data' => [
                'event' => $eventName,
                'request_id' => $requestId,
                'status' => $status,
                'from_organization' => $fromOrganizationText,
                'from_user_id' => $fromUserId,
                'to_organization' => $toOrganizationText,
                'to_user_id' => $toUserId,
                'target_organization' => $targetOrganizationText,
                'target_user_id' => $targetUserId,
                'actor_organization' => $actorOrganizationText,
                'actor_user_id' => $actorUserId,
                'cross_org_access_snapshot_id' => $crossOrgAccessSnapshotId,
                'create_time' => $createTime,
                'handle_time' => $handleTime,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function encode(string $type, int $organization, array $data, bool $includeTime = false): string
    {
        $type = trim($type);
        if ($type === ''
            || str_starts_with($type, 'friend_request.')
            || $organization <= 0
            || array_is_list($data)) {
            throw new \InvalidArgumentException('Realtime control event envelope is invalid.');
        }

        $identity = [
            'type' => $type,
            'organization' => $organization,
            'data' => $data,
        ];
        $canonical = json_encode(
            self::canonicalize($identity),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $envelope = [
            'event_id' => hash('sha256', $canonical),
            ...$identity,
        ];
        if ($includeTime) {
            $envelope['time'] = time();
        }

        return json_encode(
            $envelope,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private static function isPositiveDecimal(?string $value): bool
    {
        return $value !== null
            && preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1
            && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }

    private static function isDateTime(?string $value): bool
    {
        if ($value === null
            || (int) substr($value, 0, 4) < 1000
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) !== 1) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $value;
    }
}
