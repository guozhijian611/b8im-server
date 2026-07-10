<?php

declare(strict_types=1);

namespace plugin\saimulti\service\adminIm;

use support\think\Cache;
use support\think\Db;

final class OrganizationImAccessService
{
    public const SYNC_UNAVAILABLE = 50303;

    /**
     * The marker is only an immediate deny cache. MySQL remains authoritative,
     * so it must expire instead of being able to override a later enable
     * forever when an after-commit Redis delete fails.
     */
    private const INACTIVE_MARKER_TTL_SECONDS = 60;

    public function __construct(private readonly ?object $rawRedis = null)
    {
    }

    /** @return list<string> */
    public function revokeInsideTransaction(int $organization, string $now): array
    {
        if ($organization <= 0) {
            throw new \InvalidArgumentException('organization must be positive');
        }
        $hasAuthSessions = $this->tableExists('im_auth_session');
        $hasDevices = $this->tableExists('im_user_device');
        $hasWebAccessSessions = $this->tableExists('im_web_access_session');
        if (!$hasAuthSessions && !$hasDevices && !$hasWebAccessSessions) {
            return [];
        }

        $rows = $hasAuthSessions
            ? Db::query(
                'SELECT session_id FROM im_auth_session
                  WHERE organization = ? AND status = 1
                  FOR UPDATE',
                [$organization],
            )
            : [];
        $sessionIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['session_id'] ?? '')),
            $rows,
        ))));

        if ($hasAuthSessions) {
            Db::execute(
                'UPDATE im_auth_session
                    SET status = 2, revoked_at = ?, update_time = ?
                  WHERE organization = ? AND status = 1',
                [$now, $now, $organization],
            );
        }
        if ($hasWebAccessSessions) {
            Db::execute(
                'UPDATE im_web_access_session
                    SET status = 2, revoked_at = ?, update_time = ?
                  WHERE organization = ? AND status = 1',
                [$now, $now, $organization],
            );
        }
        if ($hasDevices) {
            Db::execute(
                'UPDATE im_user_device
                    SET current_online_state = 2,
                        client_id = NULL,
                        session_id = NULL,
                        current_ip = NULL,
                        current_ip_geo = NULL,
                        update_time = ?
                  WHERE organization = ? AND delete_time IS NULL',
                [$now, $organization],
            );
        }

        return $sessionIds;
    }

    /** @param list<string> $credentialSessionIds */
    public function afterCommit(
        int $organization,
        bool $active,
        array $credentialSessionIds,
        string $now,
        string $reason,
    ): void {
        $redis = $this->redis();
        $marker = sprintf('im:auth:organization_inactive:%d', $organization);
        if ($active) {
            $this->assertRedisResult($redis->del($marker), 'clear organization inactive marker');
        } else {
            $this->assertRedisResult(
                $redis->setex($marker, self::INACTIVE_MARKER_TTL_SECONDS, '1'),
                'set organization inactive marker',
            );
            foreach (array_values(array_unique($credentialSessionIds)) as $sessionId) {
                if ($sessionId !== '') {
                    $this->assertRedisResult(
                        $redis->del(sprintf('im:auth:active:%d:%s', $organization, $sessionId)),
                        'invalidate active IM session',
                    );
                }
            }
        }

        $published = (new ThinkCacheAdminImRealtimePublisher($redis))->publish(
            $active ? 'auth.organization_enabled' : 'auth.organization_disabled',
            [
                'organization' => $organization,
                'occurred_at' => $now,
                'reason' => $reason,
            ],
        );
        if (!$published) {
            throw new \RuntimeException('Redis failed to publish organization IM access event');
        }
    }

    private function tableExists(string $table): bool
    {
        $rows = Db::query(
            'SELECT 1 AS present FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table],
        );

        return $rows !== [];
    }

    private function assertRedisResult(mixed $result, string $operation): void
    {
        if ($result === false) {
            throw new \RuntimeException('Redis failed to ' . $operation);
        }
    }

    private function redis(): object
    {
        return $this->rawRedis ?? Cache::store('redis')->handler();
    }
}
