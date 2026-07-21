<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ImShared\Protocol\MessageType;
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\exception\SearchProjectionIntegrityException;
use B8im\ImShared\Protocol\Dto\CanonicalDecimal;
use plugin\saimulti\service\searchRebuild\SearchRebuildFactory;
use plugin\saimulti\service\web\MessageShardRouteValidator;
use support\think\Db;

final class SearchService implements SearchDocumentProjectionServiceInterface
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

    /** @return array{job:array<string,mixed>,index:array<string,mixed>} */
    public function rebuild(int $organization, int $actorId): array
    {
        $this->assertOrg($organization);
        $this->assertActor($actorId);
        return SearchRebuildFactory::service()->enqueue($organization, $actorId);
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

    // ---------- documents (internal consumers only) ----------
    /**
     * Rebuild one search document exclusively from the authoritative home
     * message index and its canonical physical body.
     *
     * @return array<string, mixed>
     */
    public function upsertMessageDocument(int $homeOrganization, string $messageId): array
    {
        $this->assertOrg($homeOrganization);
        $messageId = $this->idStr($messageId, 'message_id');
        $this->indexRead($homeOrganization, true);

        return Db::transaction(
            fn (): array => $this->projectMessageDocumentLocked($homeOrganization, $messageId),
        );
    }

    public function projectMessageDocumentLocked(int $homeOrganization, string $messageId): array
    {
        $this->assertOrg($homeOrganization);
        $messageId = $this->idStr($messageId, 'message_id');
        $searchIndexRows = Db::query(
            'SELECT id FROM sm_search_index
              WHERE organization = ? AND delete_time IS NULL
              FOR UPDATE',
            [$homeOrganization],
        );
        if (count($searchIndexRows) !== 1) {
            throw new SearchProjectionIntegrityException('Search index state is missing or ambiguous.');
        }
        $indexRows = Db::query(
            'SELECT organization, message_id, conversation_id, message_seq,
                    sender_id, sender_organization, client_msg_id, storage_node,
                    shard_table, create_time AS index_create_time
               FROM im_message_index
              WHERE organization = ?
                AND BINARY message_id = BINARY ?
              FOR UPDATE',
            [$homeOrganization, $messageId],
        );
        if (count($indexRows) !== 1) {
            throw new SearchProjectionIntegrityException(
                'Authoritative IM message index row is missing or ambiguous.',
            );
        }
        $index = $indexRows[0];
        $conversationId = $this->authoritativeAccessId(
            (string) ($index['conversation_id'] ?? ''),
            'conversation_id',
        );
        try {
            $shardTable = (new MessageShardRouteValidator())->assertIndexRoute(
                $index,
                $homeOrganization,
                $conversationId,
            );
        } catch (\Throwable $exception) {
            throw new SearchProjectionIntegrityException(
                'Authoritative IM message shard route is inconsistent.',
                previous: $exception,
            );
        }
        try {
            $bodyRows = Db::query(
                'SELECT organization, conversation_id, conversation_type, message_id,
                        message_seq, client_msg_id, sender_id, sender_organization,
                        message_type, content, status, create_time, delete_time
                   FROM ' . $this->quoteShard($shardTable) . '
                  WHERE organization = ?
                    AND BINARY conversation_id = BINARY ?
                    AND BINARY message_id = BINARY ?
                  FOR UPDATE',
                [$homeOrganization, $conversationId, $messageId],
            );
        } catch (\Throwable $exception) {
            throw new SearchProjectionIntegrityException(
                'Authoritative IM message shard body cannot be read.',
                previous: $exception,
            );
        }
        if (count($bodyRows) !== 1) {
            throw new SearchProjectionIntegrityException(
                'Authoritative IM message shard body is missing or ambiguous.',
            );
        }
        $body = $bodyRows[0];
        $sentAt = $this->assertIndexedBodyBinding(
            $index,
            $body,
            $homeOrganization,
            $conversationId,
            $messageId,
        );
        $sourceChangeSeq = $this->latestProjectionChangeSeq(
            $homeOrganization,
            $conversationId,
            $messageId,
            (string) $body['message_seq'],
        );

        $now = date('Y-m-d H:i:s');
        $existing = Db::table(self::DOC)
            ->where('organization', $homeOrganization)
            ->whereRaw('BINARY message_id = BINARY ?', [$messageId])
            ->lock(true)
            ->find();
        $previousVisibility = $existing === null ? 0 : (int) ($existing['visibility'] ?? 0);
        $payload = [
            'conversation_id' => $conversationId,
            'conversation_type' => (int) $body['conversation_type'],
            'sender_organization' => (int) $body['sender_organization'],
            'sender_user_id' => (string) $body['sender_id'],
            'message_type' => (int) $body['message_type'],
            'message_seq' => (string) $body['message_seq'],
            'source_change_seq' => $sourceChangeSeq,
            'content' => (string) ($body['content'] ?? ''),
            'visibility' => (int) $body['status'] === 1
                && ($body['delete_time'] ?? null) === null ? 1 : 0,
            'sent_at' => $sentAt,
            'update_time' => $now,
        ];
        if ($existing === null) {
            $id = $this->decimalString(Db::table(self::DOC)->insertGetId($payload + [
                'organization' => $homeOrganization,
                'message_id' => $messageId,
                'create_time' => $now,
            ]), 'search document id');
        } else {
            $id = $this->decimalString($existing['id'] ?? null, 'search document id');
            Db::table(self::DOC)->where('id', $id)->update($payload);
        }
        $this->adjustVisibleDocCountLocked(
            $homeOrganization,
            (int) $payload['visibility'] - $previousVisibility,
        );

        return $this->formatDoc($this->docRow($homeOrganization, $id));
    }

    public function hideDocument(int $organization, string $messageId): array
    {
        $this->assertOrg($organization);
        $messageId = $this->idStr($messageId, 'message_id');
        return Db::transaction(function () use ($organization, $messageId): array {
            $indexRows = Db::query(
                'SELECT id FROM sm_search_index
                  WHERE organization = ? AND delete_time IS NULL
                  FOR UPDATE',
                [$organization],
            );
            if (count($indexRows) !== 1) {
                throw new SearchProjectionIntegrityException('Search index state is missing or ambiguous.');
            }
            $row = Db::table(self::DOC)
                ->where('organization', $organization)
                ->where('message_id', $messageId)
                ->lock(true)
                ->find();
            if ($row === null) {
                throw new ApiException('搜索文档不存在。', 404);
            }
            $id = $this->decimalString($row['id'] ?? null, 'search document id');
            Db::table(self::DOC)->where('id', $id)->update([
                'visibility' => 0,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            if ((int) ($row['visibility'] ?? 0) === 1) {
                $this->adjustVisibleDocCountLocked($organization, -1);
            }

            return $this->formatDoc($this->docRow($organization, $id));
        });
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
        $indexFence = $this->searchIndexFence($organization);
        if ($indexFence === null) {
            throw new ApiException('搜索索引不存在。', 404);
        }
        if (!hash_equals('ready', $indexFence['status'])
            || $indexFence['rebuild_required'] !== '0'
            || $indexFence['lifecycle_fenced'] !== '0') {
            throw new ApiException('搜索索引尚未就绪。', 503);
        }
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
        $query = $this->endUserCandidateQuery($organization, $userId, $indexFence);
        if ($conversationId !== '') {
            $query->whereRaw(
                'BINARY conversation_id = BINARY ?',
                [$conversationId],
            );
        }
        $hasSenderOrganization = array_key_exists('sender_organization', $filters);
        $hasSenderUserId = array_key_exists('sender_user_id', $filters);
        if ($hasSenderOrganization !== $hasSenderUserId) {
            throw new ApiException(
                '发送者筛选必须同时提供 sender_organization 和 sender_user_id。',
                422,
            );
        }
        if ($hasSenderOrganization) {
            $senderOrganization = $this->intVal(
                $filters['sender_organization'],
                'sender_organization',
            );
            if ($senderOrganization <= 0) {
                throw new ApiException('sender_organization 无效。', 422);
            }
            $senderUserId = $this->accessId(
                (string) $filters['sender_user_id'],
                'sender_user_id',
            );
            $query->where('sender_organization', $senderOrganization);
            $query->whereRaw(
                'BINARY sender_user_id = BINARY ?',
                [$senderUserId],
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
        $candidateCount = (int) (clone $query)->count();
        $candidates = (clone $query)
            ->order(['sent_at' => 'desc', 'id' => 'desc'])
            ->page($page, $limit)
            ->select()
            ->toArray();
        $expectedPageCount = $this->pageCardinality($candidateCount, $page, $limit);
        if (count($candidates) !== $expectedPageCount) {
            throw new SearchProjectionIntegrityException(
                'Search page changed between candidate count and selection.',
            );
        }
        $facts = (new SearchMessageFactReader())->read(array_values($candidates));

        // The final SQL statement is the linearization point. A non-empty page
        // recomputes the exact ordered page and total through one window query;
        // an empty page uses one index-anchored scalar count. Both repeat the
        // full ACL/projection predicates and the initial exact index fence.
        if ($candidates === []) {
            $finalCount = $this->finalAccessibleCount(
                $organization,
                $userId,
                $filters,
                $keyword,
                $indexFence,
            );
            if ($finalCount !== $candidateCount || $expectedPageCount !== 0) {
                throw new SearchProjectionIntegrityException(
                    'Empty search page changed during final verification.',
                );
            }

            return [
                'current_page' => $page,
                'data' => [],
                'per_page' => $limit,
                'total' => $finalCount,
                'backend' => $indexFence['backend'],
            ];
        }
        $finalDocuments = $this->finalAccessiblePage(
            $organization,
            $userId,
            $filters,
            $keyword,
            $page,
            $limit,
            $indexFence,
        );
        $finalCount = $this->verifiedPageTotal($finalDocuments);
        $factByMessage = [];
        foreach ($facts as $fact) {
            $factByMessage[(string) $fact['message_id']] = $fact;
        }
        $finalByMessage = [];
        foreach ($finalDocuments as $document) {
            $finalByMessage[(string) $document['message_id']] = $document;
        }
        if ($finalCount !== $candidateCount
            || count($facts) !== $expectedPageCount
            || count($finalDocuments) !== $expectedPageCount) {
            throw new SearchProjectionIntegrityException(
                'Search visibility changed during authoritative verification.',
                503,
            );
        }
        $data = [];
        foreach ($candidates as $candidate) {
            $messageId = (string) $candidate['message_id'];
            $fact = $factByMessage[$messageId] ?? null;
            $finalDocument = $finalByMessage[$messageId] ?? null;
            if (!is_array($fact)
                || !is_array($finalDocument)
                || !$this->sameSearchDocumentIdentity($finalDocument, $fact)) {
                throw new SearchProjectionIntegrityException(
                    'Search projection changed during authoritative verification.',
                    503,
                );
            }
            $data[] = $this->formatDoc($fact);
        }
        return [
            'current_page' => $page,
            'data' => $data,
            'per_page' => $limit,
            'total' => $finalCount,
            'backend' => $indexFence['backend'],
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
       AND mi.create_time <=> sm_search_doc.sent_at
       AND mi.sender_organization = sm_search_doc.sender_organization
       AND BINARY mi.sender_id = BINARY sm_search_doc.sender_user_id
       AND sm_search_doc.source_change_seq = COALESCE((
           SELECT MAX(projection_change.change_seq)
             FROM im_message_change projection_change
            WHERE projection_change.organization = mi.organization
              AND BINARY projection_change.conversation_id = BINARY mi.conversation_id
              AND BINARY projection_change.message_id = BINARY mi.message_id
              AND projection_change.message_seq = mi.message_seq
              AND BINARY projection_change.change_type IN (
                  BINARY 'edit', BINARY 'recall', BINARY 'delete_both'
              )
       ), 0)
       AND NOT EXISTS (
           SELECT 1
             FROM im_message_user_delete viewer_delete
            WHERE viewer_delete.organization = mi.organization
              AND BINARY viewer_delete.message_id = BINARY mi.message_id
              AND viewer_delete.user_organization = ?
              AND BINARY viewer_delete.user_id = BINARY ?
       )
       AND NOT EXISTS (
           SELECT 1
             FROM im_message_change hidden_mutation
            WHERE hidden_mutation.organization = mi.organization
              AND BINARY hidden_mutation.conversation_id = BINARY mi.conversation_id
              AND BINARY hidden_mutation.message_id = BINARY mi.message_id
              AND hidden_mutation.message_seq = mi.message_seq
              AND BINARY hidden_mutation.change_type
                  IN (BINARY 'recall', BINARY 'delete_both')
       )
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

    /** @param array{id:string,organization:string,backend:string,status:string,last_built_at:?string,update_time:?string} $indexFence */
    private function endUserCandidateQuery(
        int $organization,
        string $userId,
        array $indexFence,
    ): mixed
    {
        return Db::table(self::DOC)
            ->where('organization', $organization)
            ->where('visibility', 1)
            ->whereRaw(
                'EXISTS (
                    SELECT 1 FROM sm_search_index ready_index
                     WHERE ready_index.organization = sm_search_doc.organization
                       AND ' . $this->indexFenceSql('ready_index') . '
                )',
                $this->indexFenceBindings($indexFence),
            )
            ->whereRaw(
                $this->endUserMessageAccessSql(),
                $this->endUserMessageAccessBindings($organization, $userId),
            );
    }

    /** @return list<int|string> */
    private function endUserMessageAccessBindings(int $organization, string $userId): array
    {
        return [
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
            $organization,
            $userId,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @param array{id:string,organization:string,backend:string,status:string,last_built_at:?string,update_time:?string} $indexFence
     * @return list<array<string,mixed>>
     */
    private function finalAccessiblePage(
        int $organization,
        string $userId,
        array $filters,
        string $keyword,
        int $page,
        int $limit,
        array $indexFence,
    ): array {
        $query = $this->endUserCandidateQuery($organization, $userId, $indexFence);
        $this->applySearchFilters($query, $filters, $keyword);

        return array_values($query
            ->fieldRaw('sm_search_doc.*, COUNT(*) OVER() AS __verified_total')
            ->order(['sent_at' => 'desc', 'id' => 'desc'])
            ->page($page, $limit)
            ->select()
            ->toArray());
    }

    /**
     * @param array<string,mixed> $filters
     * @param array{id:string,organization:string,backend:string,status:string,last_built_at:?string,update_time:?string} $indexFence
     */
    private function finalAccessibleCount(
        int $organization,
        string $userId,
        array $filters,
        string $keyword,
        array $indexFence,
    ): int {
        [$filterSql, $filterBindings] = $this->finalCountFilterSql($filters, $keyword);
        $rows = Db::query(
            'SELECT CAST(COUNT(sm_search_doc.id) AS CHAR) AS __verified_total
               FROM sm_search_index final_index
               LEFT JOIN sm_search_doc
                 ON sm_search_doc.organization = ?
                AND sm_search_doc.visibility = 1
                AND ' . $filterSql . '
                AND ' . $this->endUserMessageAccessSql() . '
              WHERE ' . $this->indexFenceSql('final_index') . '
              GROUP BY final_index.id',
            array_merge(
                [$organization],
                $filterBindings,
                $this->endUserMessageAccessBindings($organization, $userId),
                $this->indexFenceBindings($indexFence),
            ),
        );
        if (count($rows) !== 1) {
            throw new SearchProjectionIntegrityException(
                'Search index changed before empty-page verification.',
            );
        }

        return $this->verifiedCount($rows[0]['__verified_total'] ?? null);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{string,list<mixed>}
     */
    private function finalCountFilterSql(array $filters, string $keyword): array
    {
        $predicates = [];
        $bindings = [];
        $conversationId = (string) ($filters['conversation_id'] ?? '');
        if ($conversationId !== '') {
            $predicates[] = 'BINARY sm_search_doc.conversation_id = BINARY ?';
            $bindings[] = $this->accessId($conversationId, 'conversation_id');
        }
        if (array_key_exists('sender_organization', $filters)) {
            $predicates[] = 'sm_search_doc.sender_organization = ?';
            $bindings[] = $this->intVal(
                $filters['sender_organization'],
                'sender_organization',
            );
            $predicates[] = 'BINARY sm_search_doc.sender_user_id = BINARY ?';
            $bindings[] = $this->accessId(
                (string) $filters['sender_user_id'],
                'sender_user_id',
            );
        }
        if (isset($filters['message_type'])
            && $filters['message_type'] !== ''
            && $filters['message_type'] !== null) {
            $type = $this->intVal($filters['message_type'], 'message_type');
            if ($type > 0) {
                $predicates[] = 'sm_search_doc.message_type = ?';
                $bindings[] = $type;
            }
        }
        if ($this->canFulltext($keyword)) {
            $predicates[] = 'MATCH(sm_search_doc.content) AGAINST (? IN BOOLEAN MODE)';
            $bindings[] = $this->toBooleanQuery($keyword);
        } else {
            $predicates[] = 'sm_search_doc.content LIKE ? ESCAPE \'\\\\\'';
            $bindings[] = '%' . addcslashes($keyword, '%_\\') . '%';
        }

        return [implode("\n                AND ", $predicates), $bindings];
    }

    /** @param array<string,mixed> $filters */
    private function applySearchFilters(mixed $query, array $filters, string $keyword): void
    {
        $conversationId = (string) ($filters['conversation_id'] ?? '');
        if ($conversationId !== '') {
            $query->whereRaw(
                'BINARY conversation_id = BINARY ?',
                [$this->accessId($conversationId, 'conversation_id')],
            );
        }
        if (array_key_exists('sender_organization', $filters)) {
            $query->where('sender_organization', $this->intVal(
                $filters['sender_organization'],
                'sender_organization',
            ));
            $query->whereRaw(
                'BINARY sender_user_id = BINARY ?',
                [$this->accessId((string) $filters['sender_user_id'], 'sender_user_id')],
            );
        }
        if (isset($filters['message_type'])
            && $filters['message_type'] !== ''
            && $filters['message_type'] !== null) {
            $type = $this->intVal($filters['message_type'], 'message_type');
            if ($type > 0) {
                $query->where('message_type', $type);
            }
        }
        if ($this->canFulltext($keyword)) {
            $query->whereRaw(
                'MATCH(content) AGAINST (? IN BOOLEAN MODE)',
                [$this->toBooleanQuery($keyword)],
            );
        } else {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->whereRaw('content LIKE ? ESCAPE \'\\\\\'', [$like]);
        }
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $fact */
    private function sameSearchDocumentIdentity(array $current, array $fact): bool
    {
        foreach ([
            'id',
            'organization',
            'message_id',
            'conversation_id',
            'conversation_type',
            'sender_organization',
            'sender_user_id',
            'message_type',
            'message_seq',
            'visibility',
            'update_time',
        ] as $field) {
            if ((string) ($current[$field] ?? '') !== (string) ($fact[$field] ?? '')) {
                return false;
            }
        }
        if (!array_key_exists('sent_at', $current)
            || !array_key_exists('sent_at', $fact)
            || $current['sent_at'] !== $fact['sent_at']) {
            return false;
        }
        if ($this->unsignedDecimal(
            $current['source_change_seq'] ?? null,
            'search document source_change_seq',
        ) !== $this->unsignedDecimal(
            $fact['source_change_seq'] ?? null,
            'authoritative source_change_seq',
        )) {
            return false;
        }

        return hash_equals(
            (string) ($current['content'] ?? ''),
            (string) ($fact['_projection_content'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    private function createIndex(int $organization, int $actorId): array
    {
        $now = date('Y-m-d H:i:s');
        $actor = $actorId > 0 ? $actorId : null;
        Db::execute(
            <<<'SQL'
INSERT INTO sm_search_index
                (organization, backend, status, doc_count, last_built_at,
                 last_error, created_by, updated_by, create_time, update_time)
VALUES (?, 'mysql', 'idle', 0, NULL, '', ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE id = id
SQL,
            [$organization, $actor, $actor, $now, $now],
        );
        $row = Db::table(self::INDEX)
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->find();
        if (!is_array($row)) {
            throw new SearchProjectionIntegrityException(
                'Search index state cannot be created because its unique identity is inactive.',
            );
        }

        return $row;
    }

    private function adjustVisibleDocCountLocked(int $organization, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $affected = Db::execute(
            'UPDATE sm_search_index
                SET doc_count = doc_count + ?,
                    update_time = NOW()
              WHERE organization = ? AND delete_time IS NULL
                AND (? >= 0 OR doc_count >= ?)',
            [$delta, $organization, $delta, abs($delta)],
        );
        if ($affected !== 1) {
            throw new SearchProjectionIntegrityException(
                'Search index doc_count fence failed or would underflow.',
            );
        }
    }

    /** @param array<string, mixed> $index @param array<string, mixed> $body */
    private function assertIndexedBodyBinding(
        array $index,
        array $body,
        int $homeOrganization,
        string $conversationId,
        string $messageId,
    ): ?string {
        $senderOrganization = (int) ($index['sender_organization'] ?? 0);
        $senderUserId = (string) ($index['sender_id'] ?? '');
        $messageType = (int) ($body['message_type'] ?? 0);
        $status = (int) ($body['status'] ?? 0);
        $indexTime = $this->nullableMessageTime($index['index_create_time'] ?? null);
        $bodyTime = $this->nullableMessageTime($body['create_time'] ?? null);
        if ((int) ($index['organization'] ?? 0) !== $homeOrganization
            || (int) ($body['organization'] ?? 0) !== $homeOrganization
            || !hash_equals($messageId, (string) ($index['message_id'] ?? ''))
            || !hash_equals($messageId, (string) ($body['message_id'] ?? ''))
            || !hash_equals($conversationId, (string) ($body['conversation_id'] ?? ''))
            || (string) ($index['message_seq'] ?? '') !== (string) ($body['message_seq'] ?? '')
            || !hash_equals(
                (string) ($index['client_msg_id'] ?? ''),
                (string) ($body['client_msg_id'] ?? ''),
            )
            || $indexTime !== $bodyTime
            || $senderOrganization <= 0
            || $senderOrganization !== (int) ($body['sender_organization'] ?? 0)
            || $senderUserId === ''
            || !hash_equals($senderUserId, (string) ($body['sender_id'] ?? ''))
            || !in_array((int) ($body['conversation_type'] ?? 0), [1, 2], true)
            || !MessageType::isFirstStage($messageType)
            || !in_array($status, [1, 2, 3], true)) {
            throw new SearchProjectionIntegrityException(
                'IM message index and shard body binding is inconsistent.',
            );
        }
        $this->authoritativeAccessId($senderUserId, 'sender_user_id');
        if ((string) ($index['client_msg_id'] ?? '') === '') {
            throw new SearchProjectionIntegrityException(
                'IM message index client identity is invalid.',
            );
        }

        return $bodyTime;
    }

    private function nullableMessageTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new SearchProjectionIntegrityException(
                'IM message index or shard body time is invalid.',
            );
        }

        return $value;
    }

    private function latestProjectionChangeSeq(
        int $organization,
        string $conversationId,
        string $messageId,
        string $messageSeq,
    ): string {
        $rows = Db::query(
            'SELECT CAST(COALESCE(MAX(change_seq), 0) AS CHAR) AS source_change_seq
               FROM im_message_change
              WHERE organization = ?
                AND BINARY conversation_id = BINARY ?
                AND BINARY message_id = BINARY ?
                AND message_seq = ?
                AND BINARY change_type IN (
                    BINARY \'edit\', BINARY \'recall\', BINARY \'delete_both\'
                )
              FOR UPDATE',
            [$organization, $conversationId, $messageId, $messageSeq],
        );
        if (count($rows) !== 1) {
            throw new SearchProjectionIntegrityException(
                'Authoritative IM message change version is ambiguous.',
            );
        }

        return $this->unsignedDecimal(
            $rows[0]['source_change_seq'] ?? null,
            'authoritative source_change_seq',
        );
    }

    private function unsignedDecimal(mixed $value, string $field): string
    {
        return $this->decimalString($value, $field);
    }

    private function decimalString(mixed $value, string $field): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new SearchProjectionIntegrityException($field . ' is invalid.');
        }
        try {
            return CanonicalDecimal::nonNegative((string) $value, $field);
        } catch (\InvalidArgumentException $exception) {
            throw new SearchProjectionIntegrityException(
                $field . ' is invalid.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array{
     *   id:string,organization:string,backend:string,status:string,
     *   rebuild_required:string,lifecycle_fenced:string,
     *   last_built_at:?string,update_time:?string
     * }|null
     */
    private function searchIndexFence(int $organization): ?array
    {
        $rows = Db::query(
            'SELECT CAST(id AS CHAR) AS id, CAST(organization AS CHAR) AS organization,
                    backend, status, CAST(rebuild_required AS CHAR) AS rebuild_required,
                    CAST(lifecycle_fenced AS CHAR) AS lifecycle_fenced,
                    last_built_at, update_time
               FROM sm_search_index
              WHERE organization = ? AND delete_time IS NULL',
            [$organization],
        );
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new SearchProjectionIntegrityException(
                'Search index state is ambiguous.',
            );
        }
        $row = $rows[0];
        $id = $this->unsignedDecimal($row['id'] ?? null, 'search index id');
        $fenceOrganization = $this->unsignedDecimal(
            $row['organization'] ?? null,
            'search index organization',
        );
        if ($fenceOrganization !== (string) $organization) {
            throw new SearchProjectionIntegrityException(
                'Search index organization is inconsistent.',
            );
        }
        $backend = (string) ($row['backend'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $rebuildRequired = (string) ($row['rebuild_required'] ?? '');
        $lifecycleFenced = (string) ($row['lifecycle_fenced'] ?? '');
        $lastBuiltAt = $this->nullableMessageTime($row['last_built_at'] ?? null);
        $updateTime = $this->nullableMessageTime($row['update_time'] ?? null);
        if ($backend === '' || !in_array($status, self::INDEX_STATUS, true)
            || !in_array($rebuildRequired, ['0', '1'], true)
            || !in_array($lifecycleFenced, ['0', '1'], true)) {
            throw new SearchProjectionIntegrityException('Search index state is invalid.');
        }

        return [
            'id' => $id,
            'organization' => $fenceOrganization,
            'backend' => $backend,
            'status' => $status,
            'rebuild_required' => $rebuildRequired,
            'lifecycle_fenced' => $lifecycleFenced,
            'last_built_at' => $lastBuiltAt,
            'update_time' => $updateTime,
        ];
    }

    private function indexFenceSql(string $alias): string
    {
        if (!in_array($alias, ['ready_index', 'final_index', self::INDEX], true)) {
            throw new \LogicException('Unsupported search index fence alias.');
        }

        return 'CAST(' . $alias . '.id AS CHAR) = ?
            AND CAST(' . $alias . '.organization AS CHAR) = ?
            AND BINARY ' . $alias . '.backend = BINARY ?
            AND BINARY ' . $alias . '.status = BINARY ?
            AND CAST(' . $alias . '.rebuild_required AS CHAR) = ?
            AND CAST(' . $alias . '.lifecycle_fenced AS CHAR) = ?
            AND ' . $alias . '.last_built_at <=> ?
            AND ' . $alias . '.update_time <=> ?
            AND ' . $alias . '.delete_time IS NULL';
    }

    /**
     * @param array{id:string,organization:string,backend:string,status:string,last_built_at:?string,update_time:?string} $fence
     * @return array{string,string,string,string,?string,?string}
     */
    private function indexFenceBindings(array $fence): array
    {
        return [
            $fence['id'],
            $fence['organization'],
            $fence['backend'],
            $fence['status'],
            $fence['rebuild_required'],
            $fence['lifecycle_fenced'],
            $fence['last_built_at'],
            $fence['update_time'],
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function verifiedPageTotal(array $rows): int
    {
        if ($rows === []) {
            throw new SearchProjectionIntegrityException(
                'Final search page disappeared during verification.',
            );
        }
        $total = null;
        foreach ($rows as $row) {
            $rowTotal = $this->verifiedCount($row['__verified_total'] ?? null);
            if ($total !== null && $rowTotal !== $total) {
                throw new SearchProjectionIntegrityException(
                    'Final search page contains inconsistent totals.',
                );
            }
            $total = $rowTotal;
        }

        return $total ?? 0;
    }

    private function verifiedCount(mixed $value): int
    {
        $decimal = $this->unsignedDecimal($value, 'verified search total');
        $count = (int) $decimal;
        if ((string) $count !== $decimal) {
            throw new SearchProjectionIntegrityException(
                'Verified search total exceeds the supported range.',
            );
        }

        return $count;
    }

    private function pageCardinality(int $total, int $page, int $limit): int
    {
        $remaining = $total - (($page - 1) * $limit);
        if ($remaining <= 0) {
            return 0;
        }

        return min($limit, $remaining);
    }

    private function quoteShard(string $table): string
    {
        if (preg_match('/^im_message_\d{4}_\d{6}$/D', $table) !== 1) {
            throw new SearchProjectionIntegrityException(
                'IM message index contains an invalid shard table.',
            );
        }

        return '`' . $table . '`';
    }

    private function canFulltext(string $keyword): bool
    {
        $tokens = $this->normalizedQueryTokens($keyword);
        if ($tokens === []) {
            return false;
        }
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 3) {
                return false;
            }
        }

        return true;
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

    /** @return list<string> */
    private function normalizedQueryTokens(string $keyword): array
    {
        $count = preg_match_all('/[\p{L}\p{N}_]+/u', $keyword, $matches);
        if ($count === false || $count === 0) {
            return [];
        }

        return array_values($matches[0]);
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
    private function docRow(int $organization, string $id): array
    {
        $id = $this->decimalString($id, 'search document id');
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
            'id' => $this->decimalString($row['id'] ?? null, 'search index id'),
            'organization' => (int) $row['organization'],
            'backend' => (string) $row['backend'],
            'status' => (string) $row['status'],
            'doc_count' => $this->decimalString($row['doc_count'] ?? null, 'search document count'),
            'projection_checkpoint' => $this->projectionCheckpoint((int) $row['organization']),
            'last_built_at' => $row['last_built_at'] ?? null,
            'last_error' => (string) ($row['last_error'] ?? ''),
            'rebuild_required' => (int) ($row['rebuild_required'] ?? 1),
            'lifecycle_fenced' => (int) ($row['lifecycle_fenced'] ?? 1),
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatJob(array $row): array
    {
        return [
            'id' => $this->decimalString($row['id'] ?? null, 'job id'),
            'organization' => (int) $row['organization'],
            'job_type' => (string) $row['job_type'],
            'status' => (string) $row['status'],
            'processed' => $this->decimalString($row['processed'] ?? null, 'processed'),
            'total' => $this->decimalString($row['total'] ?? null, 'total'),
            'cursor_global_seq' => $this->decimalString(
                $row['cursor_global_seq'] ?? null,
                'cursor',
            ),
            'high_water_global_seq' => $this->decimalString(
                $row['high_water_global_seq'] ?? null,
                'high water',
            ),
            'source_event_cut' => $this->decimalString($row['source_event_cut'] ?? null, 'source event cut'),
            'cleanup_cursor_doc_id' => $this->decimalString(
                $row['cleanup_cursor_doc_id'] ?? null,
                'cleanup cursor',
            ),
            'cleanup_high_water_doc_id' => $this->decimalString(
                $row['cleanup_high_water_doc_id'] ?? null,
                'cleanup high water',
            ),
            'barrier_event_cut' => isset($row['barrier_event_cut'])
                ? $this->decimalString($row['barrier_event_cut'], 'barrier event cut') : null,
            'barrier_deadline_at' => $row['barrier_deadline_at'] ?? null,
            'finalized_checkpoint_event_seq' => isset($row['finalized_checkpoint_event_seq'])
                ? $this->decimalString($row['finalized_checkpoint_event_seq'], 'finalized checkpoint') : null,
            'worker_id' => $row['worker_id'] ?? null,
            'locked_until' => $row['locked_until'] ?? null,
            'retry_count' => (int) ($row['retry_count'] ?? 0),
            'next_retry_at' => $row['next_retry_at'] ?? null,
            'error_message' => (string) ($row['error_message'] ?? ''),
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    private function projectionCheckpoint(int $organization): string
    {
        $rows = Db::query(
            'SELECT CAST(reconciled_through_event_seq AS CHAR) AS checkpoint'
            . ' FROM sm_search_projection_checkpoint WHERE organization=?',
            [$organization],
        );
        if ($rows === []) {
            return '0';
        }
        if (count($rows) !== 1) {
            throw new SearchProjectionIntegrityException('Search projection checkpoint is ambiguous.');
        }

        return $this->decimalString($rows[0]['checkpoint'] ?? null, 'projection checkpoint');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatDoc(array $row): array
    {
        $conversationType = (int) ($row['conversation_type'] ?? 0);
        if (!array_key_exists('conversation_type', $row)
            || !in_array($conversationType, [1, 2], true)) {
            throw new SearchProjectionIntegrityException(
                'Search document conversation_type must be exactly 1 or 2.',
            );
        }

        return [
            'id' => $this->decimalString($row['id'] ?? null, 'search document id'),
            'organization' => (int) $row['organization'],
            'message_id' => (string) $row['message_id'],
            'conversation_id' => (string) $row['conversation_id'],
            'conversation_type' => $conversationType,
            'sender_organization' => (int) ($row['sender_organization'] ?? 0),
            'sender_user_id' => (string) ($row['sender_user_id'] ?? ''),
            'message_type' => (int) $row['message_type'],
            'message_seq' => $this->decimalString($row['message_seq'] ?? null, 'message sequence'),
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

    private function authoritativeAccessId(string $value, string $label): string
    {
        try {
            return $this->accessId($value, $label);
        } catch (ApiException $exception) {
            throw new SearchProjectionIntegrityException(
                'Authoritative IM message ' . $label . ' is invalid.',
                previous: $exception,
            );
        }
    }

    private function accessId(string $value, string $label): string
    {
        if ($value === ''
            || strlen($value) > 64
            || trim($value) !== $value
            || preg_match('/[\x00\x09\x0A\x0B\x0D|]/', $value) === 1) {
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
