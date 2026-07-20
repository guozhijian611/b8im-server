<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;
use Throwable;

final class SearchService
{
    private const INDEX = 'sm_search_index';
    private const DOC = 'sm_search_doc';
    private const JOB = 'sm_search_job';
    private const INDEX_STATUS = ['idle', 'building', 'ready', 'error'];
    private const JOB_STATUS = ['pending', 'running', 'success', 'failed'];
    private const MAX_QUERY = 120;
    private const MAX_SEARCH_OFFSET = 10000;

    // ---------- index ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function indexList(array $filters): array
    {
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::INDEX)->whereNull('delete_time');
        if (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
            $query->where('status', trim((string) $filters['status']));
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('organization', 'asc')->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatIndex'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function indexRead(int $organization, bool $createIfMissing = true): array
    {
        $this->assertOrg($organization);
        $row = Db::table(self::INDEX)
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->find();
        if ($row === null) {
            if (!$createIfMissing) {
                throw new ApiException('搜索索引不存在。', 404);
            }
            $row = $this->createIndex($organization, 0);
        }

        return $this->formatIndex($row);
    }

    /**
     * Create a rebuild job and run a lightweight refresh of doc_count.
     * Full IM shard scan is deferred to the ES/MQ consumer phase.
     *
     * @return array{job:array<string,mixed>,index:array<string,mixed>}
     */
    public function rebuild(int $organization, int $actorId): array
    {
        $this->assertOrg($organization);
        $this->assertActor($actorId);
        $this->indexRead($organization, true);
        $now = date('Y-m-d H:i:s');
        $jobId = (int) Db::table(self::JOB)->insertGetId([
            'organization' => $organization,
            'job_type' => 'rebuild',
            'status' => 'running',
            'processed' => 0,
            'total' => 0,
            'error_message' => '',
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'started_at' => $now,
            'finished_at' => null,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::table(self::INDEX)->where('organization', $organization)->whereNull('delete_time')->update([
            'status' => 'building',
            'updated_by' => $actorId,
            'update_time' => $now,
            'last_error' => '',
        ]);
        try {
            $count = (int) Db::table(self::DOC)
                ->where('organization', $organization)
                ->where('visibility', 1)
                ->count();
            $finish = date('Y-m-d H:i:s');
            Db::table(self::INDEX)->where('organization', $organization)->whereNull('delete_time')->update([
                'status' => 'ready',
                'doc_count' => $count,
                'last_built_at' => $finish,
                'updated_by' => $actorId,
                'update_time' => $finish,
            ]);
            Db::table(self::JOB)->where('id', $jobId)->update([
                'status' => 'success',
                'processed' => $count,
                'total' => $count,
                'finished_at' => $finish,
                'updated_by' => $actorId,
                'update_time' => $finish,
            ]);
        } catch (Throwable $e) {
            $finish = date('Y-m-d H:i:s');
            $msg = mb_substr($e->getMessage(), 0, 500);
            Db::table(self::INDEX)->where('organization', $organization)->whereNull('delete_time')->update([
                'status' => 'error',
                'last_error' => $msg,
                'updated_by' => $actorId,
                'update_time' => $finish,
            ]);
            Db::table(self::JOB)->where('id', $jobId)->update([
                'status' => 'failed',
                'error_message' => $msg,
                'finished_at' => $finish,
                'updated_by' => $actorId,
                'update_time' => $finish,
            ]);
            throw new ApiException('索引重建失败。', 500);
        }

        return [
            'job' => $this->formatJob($this->jobRow($jobId)),
            'index' => $this->indexRead($organization, false),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function jobList(int $organization, array $filters, bool $allowPlatform = false): array
    {
        if (!$allowPlatform) {
            $this->assertOrg($organization);
        } elseif ($organization < 0) {
            throw new ApiException('机构编号无效。', 422);
        }
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::JOB);
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatJob'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    // ---------- documents (for consumers / admin seed) ----------
    /**
     * Upsert an indexable message document.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function upsertDocument(int $organization, array $input): array
    {
        $this->assertOrg($organization);
        $this->indexRead($organization, true);
        $messageId = $this->idStr((string) ($input['message_id'] ?? ''), 'message_id');
        $conversationId = $this->accessId(
            (string) ($input['conversation_id'] ?? ''),
            'conversation_id',
        );
        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '') {
            throw new ApiException('content 必填。', 422);
        }
        if (mb_strlen($content) > 20000) {
            $content = mb_substr($content, 0, 20000);
        }
        $visibility = array_key_exists('visibility', $input)
            ? $this->bool01($input['visibility'])
            : 1;
        $now = date('Y-m-d H:i:s');
        $existing = Db::table(self::DOC)
            ->where('organization', $organization)
            ->where('message_id', $messageId)
            ->find();
        $payload = [
            'conversation_id' => $conversationId,
            'sender_user_id' => $this->limitStr((string) ($input['sender_user_id'] ?? ''), 64),
            'message_type' => max(0, $this->intVal($input['message_type'] ?? 0, 'message_type')),
            'message_seq' => max(0, $this->intVal($input['message_seq'] ?? 0, 'message_seq')),
            'content' => $content,
            'visibility' => $visibility,
            'sent_at' => $this->nullableDate((string) ($input['sent_at'] ?? '')),
            'update_time' => $now,
        ];
        if ($existing === null) {
            $id = (int) Db::table(self::DOC)->insertGetId($payload + [
                'organization' => $organization,
                'message_id' => $messageId,
                'create_time' => $now,
            ]);
        } else {
            Db::table(self::DOC)->where('id', $existing['id'])->update($payload);
            $id = (int) $existing['id'];
        }
        $this->refreshDocCount($organization);

        return $this->formatDoc($this->docRow($organization, $id));
    }

    public function hideDocument(int $organization, string $messageId): array
    {
        $this->assertOrg($organization);
        $messageId = $this->idStr($messageId, 'message_id');
        $row = Db::table(self::DOC)
            ->where('organization', $organization)
            ->where('message_id', $messageId)
            ->find();
        if ($row === null) {
            throw new ApiException('搜索文档不存在。', 404);
        }
        Db::table(self::DOC)->where('id', $row['id'])->update([
            'visibility' => 0,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $this->refreshDocCount($organization);

        return $this->formatDoc($this->docRow($organization, (int) $row['id']));
    }

    // ---------- search ----------
    /**
     * End-user full-history search against indexed documents. Management index
     * maintenance uses the methods above and is intentionally not scoped by a
     * Web/App member identity.
     *
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int,backend:string}
     */
    public function searchMessages(int $organization, string $userId, array $filters): array
    {
        $this->assertOrg($organization);
        $userId = $this->accessUserId($userId);
        $index = $this->indexRead($organization, true);
        $keyword = trim((string) ($filters['q'] ?? $filters['keyword'] ?? ''));
        if ($keyword === '') {
            throw new ApiException('搜索关键词必填。', 422);
        }
        if (mb_strlen($keyword) > self::MAX_QUERY) {
            throw new ApiException('搜索关键词过长。', 422);
        }
        $conversationId = (string) ($filters['conversation_id'] ?? '');
        if ($conversationId !== '') {
            $conversationId = $this->accessId($conversationId, 'conversation_id');
        }
        if (mb_strlen($keyword) < 2 && $conversationId === '') {
            throw new ApiException('单字符搜索必须指定 conversation_id。', 422);
        }
        [$page, $limit] = $this->searchPagination($filters);
        $query = Db::table(self::DOC)
            ->where('organization', $organization)
            ->where('visibility', 1)
            ->whereRaw($this->endUserMessageAccessSql(), [
                $organization,
                $userId,
                $organization,
                $userId,
                $organization,
                $userId,
                $organization,
                $userId,
                $organization,
                $userId,
                $organization,
                $userId,
                $organization,
                $userId,
            ]);
        if ($conversationId !== '') {
            $query->whereRaw(
                'BINARY conversation_id = BINARY ?',
                [$conversationId],
            );
        }
        $sender = (string) ($filters['sender_user_id'] ?? '');
        if ($sender !== '') {
            $query->whereRaw(
                'BINARY sender_user_id = BINARY ?',
                [$this->accessId($sender, 'sender_user_id')],
            );
        }
        if (isset($filters['message_type']) && $filters['message_type'] !== '' && $filters['message_type'] !== null) {
            $type = $this->intVal($filters['message_type'], 'message_type');
            if ($type > 0) {
                $query->where('message_type', $type);
            }
        }
        // Prefer FULLTEXT boolean mode; fall back to LIKE if words are short/noisy.
        $useFulltext = $this->canFulltext($keyword);
        if ($useFulltext) {
            $ft = $this->toBooleanQuery($keyword);
            $query->whereRaw('MATCH(content) AGAINST (? IN BOOLEAN MODE)', [$ft]);
        } else {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->whereRaw('content LIKE ? ESCAPE \'\\\\\'', [$like]);
        }
        $total = (int) (clone $query)->count();
        $items = $query->order(['sent_at' => 'desc', 'id' => 'desc'])->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatDoc'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
            'backend' => (string) ($index['backend'] ?? 'mysql'),
        ];
    }

    // ---------- internals ----------
    private function endUserMessageAccessSql(): string
    {
        return <<<'SQL'
EXISTS (
    SELECT 1
      FROM im_message_index mi
      INNER JOIN im_conversation c
        ON c.organization = mi.organization
       AND BINARY c.conversation_id = BINARY mi.conversation_id
     WHERE mi.organization = sm_search_doc.organization
       AND BINARY mi.message_id = BINARY sm_search_doc.message_id
       AND BINARY mi.conversation_id = BINARY sm_search_doc.conversation_id
       AND mi.message_seq = sm_search_doc.message_seq
       AND BINARY mi.sender_id = BINARY sm_search_doc.sender_user_id
       AND c.status = 1
       AND c.delete_time IS NULL
       AND OCTET_LENGTH(mi.conversation_id) BETWEEN 1 AND 64
       AND BINARY mi.conversation_id = BINARY TRIM(mi.conversation_id)
       AND LOCATE(CHAR(0), mi.conversation_id) = 0
       AND LOCATE('|', mi.conversation_id) = 0
       AND EXISTS (
           SELECT 1
             FROM sm_system_organization viewer_home
            WHERE viewer_home.id = c.organization
              AND viewer_home.status = 1
              AND viewer_home.delete_time IS NULL
       )
       AND (
           (
               c.conversation_type = 2
               AND NOT EXISTS (
                   SELECT 1
                    FROM im_cross_organization_conversation invalid_group_canonical
                    WHERE BINARY invalid_group_canonical.conversation_id
                        = BINARY mi.conversation_id
               )
               AND NOT EXISTS (
                   SELECT 1
                     FROM im_conversation_member invalid_group_member
                    WHERE invalid_group_member.organization = c.organization
                      AND BINARY invalid_group_member.conversation_id
                          = BINARY mi.conversation_id
                      AND invalid_group_member.delete_time IS NULL
                      AND (
                          invalid_group_member.member_organization <> c.organization
                          OR OCTET_LENGTH(invalid_group_member.user_id) NOT BETWEEN 1 AND 64
                          OR BINARY invalid_group_member.user_id
                              <> BINARY TRIM(invalid_group_member.user_id)
                          OR LOCATE(CHAR(0), invalid_group_member.user_id) <> 0
                          OR LOCATE('|', invalid_group_member.user_id) <> 0
                      )
               )
               AND EXISTS (
                   SELECT 1
                     FROM im_conversation_member group_member
                    WHERE group_member.organization = c.organization
                      AND BINARY group_member.conversation_id = BINARY mi.conversation_id
                      AND group_member.member_organization = ?
                      AND BINARY group_member.user_id = BINARY ?
                      AND group_member.delete_time IS NULL
                      AND (
                          (
                              BINARY group_member.access_state = BINARY 'active'
                              AND group_member.status = 1
                              AND (
                                  SELECT COUNT(*)
                                    FROM im_conversation_membership_period open_period
                                   WHERE open_period.organization = group_member.organization
                                     AND BINARY open_period.conversation_id
                                         = BINARY group_member.conversation_id
                                     AND open_period.member_organization = group_member.member_organization
                                     AND BINARY open_period.user_id = BINARY group_member.user_id
                                     AND open_period.status = 1
                                     AND open_period.visible_from_message_seq >= 1
                                     AND open_period.visible_until_message_seq IS NULL
                              ) = 1
                          )
                          OR (
                              BINARY group_member.access_state = BINARY 'history_only'
                              AND group_member.status IN (2, 3)
                              AND NOT EXISTS (
                                  SELECT 1
                                    FROM im_conversation_membership_period unexpected_open_period
                                   WHERE unexpected_open_period.organization = group_member.organization
                                     AND BINARY unexpected_open_period.conversation_id
                                         = BINARY group_member.conversation_id
                                     AND unexpected_open_period.member_organization = group_member.member_organization
                                     AND BINARY unexpected_open_period.user_id
                                         = BINARY group_member.user_id
                                     AND unexpected_open_period.status = 1
                                     AND unexpected_open_period.visible_until_message_seq IS NULL
                              )
                          )
                      )
                      AND EXISTS (
                          SELECT 1
                            FROM im_conversation_membership_period visible_period
                           WHERE visible_period.organization = group_member.organization
                             AND BINARY visible_period.conversation_id
                                 = BINARY group_member.conversation_id
                             AND visible_period.member_organization = group_member.member_organization
                             AND BINARY visible_period.user_id = BINARY group_member.user_id
                             AND visible_period.status = 1
                             AND visible_period.visible_from_message_seq >= 1
                             AND (
                                 visible_period.visible_until_message_seq IS NULL
                                 OR visible_period.visible_until_message_seq
                                     >= visible_period.visible_from_message_seq
                             )
                             AND mi.message_seq
                                 >= visible_period.visible_from_message_seq
                             AND (
                                 visible_period.visible_until_message_seq IS NULL
                                 OR mi.message_seq
                                     <= visible_period.visible_until_message_seq
                             )
                      )
               )
           )
           OR (
               c.conversation_type = 1
               AND NOT EXISTS (
                   SELECT 1
                    FROM im_cross_organization_conversation same_home_canonical
                    WHERE BINARY same_home_canonical.conversation_id
                        = BINARY mi.conversation_id
               )
               AND (
                   SELECT COUNT(*)
                     FROM im_conversation_member same_home_member_count
                    WHERE same_home_member_count.organization = c.organization
                      AND BINARY same_home_member_count.conversation_id
                          = BINARY mi.conversation_id
                      AND same_home_member_count.status = 1
                      AND same_home_member_count.delete_time IS NULL
               ) = 2
               AND NOT EXISTS (
                   SELECT 1
                     FROM im_conversation_member invalid_same_home_member
                    WHERE invalid_same_home_member.organization = c.organization
                      AND BINARY invalid_same_home_member.conversation_id
                          = BINARY mi.conversation_id
                      AND invalid_same_home_member.status = 1
                      AND invalid_same_home_member.delete_time IS NULL
                      AND (
                          invalid_same_home_member.member_organization <> c.organization
                          OR OCTET_LENGTH(invalid_same_home_member.user_id) NOT BETWEEN 1 AND 64
                          OR BINARY invalid_same_home_member.user_id
                              <> BINARY TRIM(invalid_same_home_member.user_id)
                          OR LOCATE(CHAR(0), invalid_same_home_member.user_id) <> 0
                          OR LOCATE('|', invalid_same_home_member.user_id) <> 0
                      )
               )
               AND EXISTS (
                   SELECT 1
                     FROM im_conversation_member same_home_viewer
                    WHERE same_home_viewer.organization = c.organization
                      AND BINARY same_home_viewer.conversation_id = BINARY mi.conversation_id
                      AND same_home_viewer.member_organization = ?
                      AND BINARY same_home_viewer.user_id = BINARY ?
                      AND same_home_viewer.status = 1
                      AND same_home_viewer.delete_time IS NULL
               )
               AND EXISTS (
                   SELECT 1
                     FROM im_conversation_member same_home_peer
                    WHERE same_home_peer.organization = c.organization
                      AND BINARY same_home_peer.conversation_id = BINARY mi.conversation_id
                      AND same_home_peer.status = 1
                      AND same_home_peer.delete_time IS NULL
                      AND NOT (
                          same_home_peer.member_organization = ?
                          AND BINARY same_home_peer.user_id = BINARY ?
                      )
                      AND BINARY mi.conversation_id = BINARY CONCAT(
                          'single_',
                          SHA1(CONCAT(
                              LEAST(
                                  BINARY CONCAT(?, ':', ?),
                                  BINARY CONCAT(
                                      same_home_peer.member_organization,
                                      ':',
                                      same_home_peer.user_id
                                  )
                              ),
                              '|',
                              GREATEST(
                                  BINARY CONCAT(?, ':', ?),
                                  BINARY CONCAT(
                                      same_home_peer.member_organization,
                                      ':',
                                      same_home_peer.user_id
                                  )
                              )
                          ))
                      )
               )
           )
           OR (
               c.conversation_type = 1
               AND EXISTS (
                   SELECT 1
                     FROM im_cross_organization_conversation canonical
                    WHERE BINARY canonical.conversation_id = BINARY mi.conversation_id
                      AND canonical.status = 1
                      AND canonical.left_organization > 0
                      AND canonical.right_organization > 0
                      AND canonical.left_organization <> canonical.right_organization
                      AND OCTET_LENGTH(canonical.left_user_id) BETWEEN 1 AND 64
                      AND OCTET_LENGTH(canonical.right_user_id) BETWEEN 1 AND 64
                      AND BINARY canonical.left_user_id = BINARY TRIM(canonical.left_user_id)
                      AND BINARY canonical.right_user_id = BINARY TRIM(canonical.right_user_id)
                      AND LOCATE(CHAR(0), canonical.left_user_id) = 0
                      AND LOCATE(CHAR(0), canonical.right_user_id) = 0
                      AND LOCATE('|', canonical.left_user_id) = 0
                      AND LOCATE('|', canonical.right_user_id) = 0
                      AND BINARY CONCAT(
                          canonical.left_organization,
                          ':',
                          canonical.left_user_id
                      ) < BINARY CONCAT(
                          canonical.right_organization,
                          ':',
                          canonical.right_user_id
                      )
                      AND BINARY canonical.conversation_id = BINARY CONCAT(
                          'single_',
                          SHA1(CONCAT(
                              canonical.left_organization,
                              ':',
                              canonical.left_user_id,
                              '|',
                              canonical.right_organization,
                              ':',
                              canonical.right_user_id
                          ))
                      )
                      AND (
                          (
                              canonical.left_organization = ?
                              AND BINARY canonical.left_user_id = BINARY ?
                          )
                          OR (
                              canonical.right_organization = ?
                              AND BINARY canonical.right_user_id = BINARY ?
                          )
                      )
                      AND EXISTS (
                          SELECT 1
                            FROM sm_system_config_group social_group
                            JOIN sm_system_config social_enabled
                              ON social_enabled.group_id = social_group.id
                             AND social_enabled.`key` = 'cross_org_social_enabled'
                             AND social_enabled.delete_time IS NULL
                            JOIN sm_system_config social_snapshot
                              ON social_snapshot.group_id = social_group.id
                             AND social_snapshot.`key` = 'cross_org_access_snapshot_id'
                             AND social_snapshot.delete_time IS NULL
                           WHERE social_group.code = 'social_config'
                             AND social_group.delete_time IS NULL
                             AND LOWER(TRIM(social_enabled.`value`)) IN (
                                 '1', 'true', 'yes', 'on', 'enabled'
                             )
                             AND TRIM(social_snapshot.`value`)
                                 REGEXP BINARY '^[1-9][0-9]{0,19}$'
                      )
                      AND (
                          SELECT COUNT(*)
                            FROM sm_system_organization cross_home
                           WHERE cross_home.id IN (
                               canonical.left_organization,
                               canonical.right_organization
                           )
                             AND cross_home.status = 1
                             AND cross_home.delete_time IS NULL
                      ) = 2
                      AND (
                          SELECT COUNT(*)
                            FROM im_conversation cross_projection
                           WHERE BINARY cross_projection.conversation_id
                               = BINARY canonical.conversation_id
                             AND cross_projection.organization IN (
                                 canonical.left_organization,
                                 canonical.right_organization
                             )
                             AND cross_projection.conversation_type = 1
                             AND cross_projection.status = 1
                             AND cross_projection.delete_time IS NULL
                      ) = 2
                      AND NOT EXISTS (
                          SELECT 1
                            FROM im_conversation_member invalid_cross_member
                           WHERE invalid_cross_member.organization IN (
                               canonical.left_organization,
                               canonical.right_organization
                           )
                             AND BINARY invalid_cross_member.conversation_id
                                 = BINARY canonical.conversation_id
                             AND invalid_cross_member.status = 1
                             AND invalid_cross_member.delete_time IS NULL
                             AND NOT (
                                 (
                                     invalid_cross_member.member_organization
                                         = canonical.left_organization
                                     AND BINARY invalid_cross_member.user_id
                                         = BINARY canonical.left_user_id
                                 )
                                 OR (
                                     invalid_cross_member.member_organization
                                         = canonical.right_organization
                                     AND BINARY invalid_cross_member.user_id
                                         = BINARY canonical.right_user_id
                                 )
                             )
                      )
                      AND (
                          SELECT COUNT(*)
                            FROM im_conversation_member cross_member
                           WHERE cross_member.organization IN (
                               canonical.left_organization,
                               canonical.right_organization
                           )
                             AND BINARY cross_member.conversation_id
                                 = BINARY canonical.conversation_id
                             AND cross_member.status = 1
                             AND cross_member.delete_time IS NULL
                             AND (
                                 (
                                     cross_member.member_organization
                                         = canonical.left_organization
                                     AND BINARY cross_member.user_id
                                         = BINARY canonical.left_user_id
                                 )
                                 OR (
                                     cross_member.member_organization
                                         = canonical.right_organization
                                     AND BINARY cross_member.user_id
                                         = BINARY canonical.right_user_id
                                 )
                             )
                      ) = 4
               )
           )
       )
)
SQL;
    }

    /** @return array<string, mixed> */
    private function createIndex(int $organization, int $actorId): array
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::INDEX)->insertGetId([
            'organization' => $organization,
            'backend' => 'mysql',
            'status' => 'idle',
            'doc_count' => 0,
            'last_built_at' => null,
            'last_error' => '',
            'created_by' => $actorId > 0 ? $actorId : null,
            'updated_by' => $actorId > 0 ? $actorId : null,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $row = Db::table(self::INDEX)->where('id', $id)->find();

        return is_array($row) ? $row : [];
    }

    private function refreshDocCount(int $organization): void
    {
        $count = (int) Db::table(self::DOC)
            ->where('organization', $organization)
            ->where('visibility', 1)
            ->count();
        Db::table(self::INDEX)->where('organization', $organization)->whereNull('delete_time')->update([
            'doc_count' => $count,
            'status' => $count > 0 ? 'ready' : 'idle',
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    private function canFulltext(string $keyword): bool
    {
        // MySQL InnoDB ft_min_token_size is often 3; short CJK may still work with ngram if configured.
        // Use FULLTEXT when keyword has a token of length >= 2.
        return mb_strlen(preg_replace('/\s+/u', '', $keyword) ?? '') >= 2;
    }

    private function toBooleanQuery(string $keyword): string
    {
        $parts = preg_split('/\s+/u', $keyword) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $part = str_replace(['+', '-', '>', '<', '(', ')', '~', '*', '"'], ' ', $part);
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $tokens[] = '+' . $part . '*';
        }
        if ($tokens === []) {
            return $keyword;
        }

        return implode(' ', $tokens);
    }

    /** @return array<string, mixed> */
    private function jobRow(int $id): array
    {
        $row = Db::table(self::JOB)->where('id', $id)->find();
        if ($row === null) {
            throw new ApiException('任务不存在。', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function docRow(int $organization, int $id): array
    {
        $row = Db::table(self::DOC)->where('id', $id)->where('organization', $organization)->find();
        if ($row === null) {
            throw new ApiException('搜索文档不存在。', 404);
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatIndex(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'backend' => (string) $row['backend'],
            'status' => (string) $row['status'],
            'doc_count' => (int) $row['doc_count'],
            'last_built_at' => $row['last_built_at'] ?? null,
            'last_error' => (string) ($row['last_error'] ?? ''),
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatJob(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'job_type' => (string) $row['job_type'],
            'status' => (string) $row['status'],
            'processed' => (int) $row['processed'],
            'total' => (int) $row['total'],
            'error_message' => (string) ($row['error_message'] ?? ''),
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'create_time' => $row['create_time'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatDoc(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'message_id' => (string) $row['message_id'],
            'conversation_id' => (string) $row['conversation_id'],
            'sender_user_id' => (string) ($row['sender_user_id'] ?? ''),
            'message_type' => (int) $row['message_type'],
            'message_seq' => (int) $row['message_seq'],
            'content' => (string) $row['content'],
            'visibility' => (int) $row['visibility'],
            'sent_at' => $row['sent_at'] ?? null,
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    private function assertOrg(int $organization): void
    {
        if ($organization <= 0) {
            throw new ApiException('机构编号无效。', 422);
        }
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0) {
            throw new ApiException('操作人无效。', 422);
        }
    }

    private function idStr(string $value, string $label): string
    {
        if ($value === ''
            || strlen($value) > 64
            || trim($value) !== $value
            || str_contains($value, "\0")
            || str_contains($value, '|')
            || !preg_match('/^[A-Za-z0-9._:-]+$/D', $value)) {
            throw new ApiException($label . '无效。', 422);
        }

        return $value;
    }

    private function accessUserId(string $userId): string
    {
        try {
            return $this->accessId($userId, 'user_id');
        } catch (ApiException) {
            throw new ApiException('搜索身份无效。', 403);
        }
    }

    private function accessId(string $value, string $label): string
    {
        if ($value === ''
            || strlen($value) > 64
            || trim($value) !== $value
            || str_contains($value, "\0")
            || str_contains($value, '|')) {
            throw new ApiException($label . '无效。', 422);
        }

        return $value;
    }

    /** @param array<string, mixed> $filters @return array{0:int,1:int} */
    private function searchPagination(array $filters): array
    {
        $page = $this->searchPositiveInteger(
            $filters['page'] ?? $filters['current_page'] ?? 1,
            'page',
        );
        $limit = $this->searchPositiveInteger(
            $filters['limit'] ?? $filters['per_page'] ?? 20,
            'limit',
        );
        if ($limit > 100) {
            throw new ApiException('limit 不能超过 100。', 422);
        }
        if ($page > intdiv(self::MAX_SEARCH_OFFSET, $limit) + 1) {
            throw new ApiException('搜索分页超过最大 offset。', 422);
        }

        return [$page, $limit];
    }

    private function searchPositiveInteger(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value)
            && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1) {
            $normalized = (int) $value;
        } else {
            throw new ApiException($label . '无效。', 422);
        }
        if ($normalized <= 0) {
            throw new ApiException($label . '无效。', 422);
        }

        return $normalized;
    }

    private function limitStr(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            return mb_substr($value, 0, $max);
        }

        return $value;
    }

    private function bool01(mixed $value): int
    {
        if ($value === 1 || $value === '1' || $value === true || $value === 'true') {
            return 1;
        }
        if ($value === 0 || $value === '0' || $value === false || $value === 'false') {
            return 0;
        }
        throw new ApiException('visibility 无效。', 422);
    }

    private function nullableDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new ApiException('sent_at 无效。', 422);
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function intVal(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        throw new ApiException($label . '无效。', 422);
    }

    /** @param array<string, mixed> $filters @return array{0:int,1:int} */
    private function pagination(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? $filters['current_page'] ?? 1));
        $limit = (int) ($filters['limit'] ?? $filters['per_page'] ?? 20);
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        return [$page, $limit];
    }
}
