<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use B8im\ImShared\Protocol\MessageType;
use plugin\saimulti\exception\SearchProjectionIntegrityException;
use plugin\saimulti\service\web\MessageShardRouteValidator;
use support\think\Db;
use Throwable;

/** Reads current authoritative message facts for search candidates. */
final class SearchMessageFactReader
{
    private const BATCH_SIZE = 100;

    /**
     * Uses autocommit consistent reads and never takes row locks. The caller
     * must perform a fresh final ACL read after these facts have been loaded.
     *
     * @param list<array<string,mixed>> $documents
     * @return list<array<string,mixed>>
     */
    public function read(array $documents): array
    {
        $facts = [];
        foreach (array_chunk($documents, self::BATCH_SIZE) as $batch) {
            array_push($facts, ...$this->readBatch($batch));
        }

        return $facts;
    }

    /**
     * @param list<array<string,mixed>> $documents
     * @return list<array<string,mixed>>
     */
    private function readBatch(array $documents): array
    {
        if ($documents === []) {
            return [];
        }
        $organization = $this->positiveOrganization($documents[0]['organization'] ?? null);
        $documentByMessage = [];
        foreach ($documents as $document) {
            if ($this->positiveOrganization($document['organization'] ?? null) !== $organization) {
                throw new SearchProjectionIntegrityException(
                    'Search candidate batch crosses home organizations.',
                );
            }
            $messageId = $this->canonicalId($document['message_id'] ?? null, 'message_id');
            if (isset($documentByMessage[$messageId])) {
                throw new SearchProjectionIntegrityException(
                    'Search candidates contain an ambiguous message identity.',
                );
            }
            $documentByMessage[$messageId] = $document;
        }

        $indexes = $this->indexRows($organization, array_keys($documentByMessage));
        $indexByMessage = $this->uniqueRowsByMessage(
            $indexes,
            $documentByMessage,
            'Authoritative IM message index row is missing or ambiguous.',
        );
        $routeValidator = new MessageShardRouteValidator();
        $indexesByShard = [];
        foreach ($indexByMessage as $messageId => $index) {
            $conversationId = $this->canonicalId(
                $documentByMessage[$messageId]['conversation_id'] ?? null,
                'conversation_id',
            );
            try {
                $shard = $routeValidator->assertIndexRoute(
                    $index,
                    $organization,
                    $conversationId,
                );
            } catch (Throwable $exception) {
                throw new SearchProjectionIntegrityException(
                    'Authoritative IM message shard route is inconsistent.',
                    previous: $exception,
                );
            }
            $indexesByShard[$shard][$messageId] = $index;
        }

        $bodyByMessage = [];
        foreach ($indexesByShard as $shard => $shardIndexes) {
            $expected = array_intersect_key($documentByMessage, $shardIndexes);
            $bodies = $this->bodyRows($organization, $shard, array_keys($shardIndexes));
            foreach ($this->uniqueRowsByMessage(
                $bodies,
                $expected,
                'Authoritative IM message shard body is missing or ambiguous.',
            ) as $messageId => $body) {
                $bodyByMessage[$messageId] = $body;
            }
        }

        $facts = [];
        foreach ($documents as $document) {
            $messageId = (string) $document['message_id'];
            $body = $bodyByMessage[$messageId];
            $conversationType = $this->assertBinding(
                $document,
                $indexByMessage[$messageId],
                $body,
            );
            $facts[] = array_replace($document, [
                '_projection_content' => (string) ($document['content'] ?? ''),
                'source_change_seq' => $this->unsignedDecimal(
                    $indexByMessage[$messageId]['authoritative_source_change_seq'] ?? null,
                ),
                'conversation_type' => $conversationType,
                'sender_organization' => (int) $body['sender_organization'],
                'sender_user_id' => (string) $body['sender_id'],
                'message_type' => (int) $body['message_type'],
                'message_seq' => (string) $body['message_seq'],
                'content' => (string) ($body['content'] ?? ''),
                'visibility' => 1,
                'sent_at' => $body['create_time'] ?? null,
            ]);
        }

        return $facts;
    }

    /** @param list<string> $messageIds @return list<array<string,mixed>> */
    private function indexRows(int $organization, array $messageIds): array
    {
        [$predicate, $bindings] = $this->exactMessagePredicate('mi.message_id', $messageIds);

        return Db::query(
            'SELECT mi.organization, mi.message_id, mi.conversation_id, mi.message_seq,
                    mi.sender_id, mi.sender_organization, mi.client_msg_id, mi.storage_node,
                    mi.shard_table, mi.create_time AS index_create_time,
                    c.conversation_type AS index_conversation_type,
                    CAST(COALESCE((
                        SELECT MAX(projection_change.change_seq)
                          FROM im_message_change projection_change
                         WHERE projection_change.organization = mi.organization
                           AND BINARY projection_change.conversation_id
                               = BINARY mi.conversation_id
                           AND BINARY projection_change.message_id = BINARY mi.message_id
                           AND projection_change.message_seq = mi.message_seq
                           AND BINARY projection_change.change_type IN (
                               BINARY \'edit\', BINARY \'recall\', BINARY \'delete_both\'
                           )
                    ), 0) AS CHAR) AS authoritative_source_change_seq
               FROM im_message_index mi
         INNER JOIN im_conversation c
                 ON c.organization = mi.organization
                AND BINARY c.conversation_id = BINARY mi.conversation_id
              WHERE mi.organization = ? AND (' . $predicate . ')',
            array_merge([$organization], $bindings),
        );
    }

    /** @param list<string> $messageIds @return list<array<string,mixed>> */
    private function bodyRows(int $organization, string $shard, array $messageIds): array
    {
        [$predicate, $bindings] = $this->exactMessagePredicate('message_id', $messageIds);

        try {
            return Db::query(
                'SELECT organization, conversation_id, conversation_type, message_id,
                        message_seq, client_msg_id, sender_id, sender_organization,
                        message_type, content, status, create_time, delete_time
                   FROM ' . $this->quoteShard($shard) . '
                  WHERE organization = ? AND (' . $predicate . ')',
                array_merge([$organization], $bindings),
            );
        } catch (Throwable $exception) {
            throw new SearchProjectionIntegrityException(
                'Authoritative IM message shard body cannot be read.',
                previous: $exception,
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,array<string,mixed>> $expected
     * @return array<string,array<string,mixed>>
     */
    private function uniqueRowsByMessage(array $rows, array $expected, string $error): array
    {
        $result = [];
        foreach ($rows as $row) {
            $messageId = (string) ($row['message_id'] ?? '');
            if (!isset($expected[$messageId]) || isset($result[$messageId])) {
                throw new SearchProjectionIntegrityException($error);
            }
            $result[$messageId] = $row;
        }
        if (count($result) !== count($expected)) {
            throw new SearchProjectionIntegrityException($error);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $index
     * @param array<string,mixed> $body
     */
    private function assertBinding(
        array $document,
        array $index,
        array $body,
    ): int {
        $organization = $this->positiveOrganization($document['organization'] ?? null);
        $messageId = $this->canonicalId($document['message_id'] ?? null, 'message_id');
        $conversationId = $this->canonicalId(
            $document['conversation_id'] ?? null,
            'conversation_id',
        );
        $senderOrganization = $this->positiveOrganization(
            $document['sender_organization'] ?? null,
        );
        $senderUserId = $this->canonicalId(
            $document['sender_user_id'] ?? null,
            'sender_user_id',
        );
        $messageType = (int) ($document['message_type'] ?? 0);
        $bodyStatus = (int) ($body['status'] ?? 0);
        $conversationType = (int) ($index['index_conversation_type'] ?? 0);
        $documentConversationType = (int) ($document['conversation_type'] ?? 0);
        $documentSourceChangeSeq = $this->unsignedDecimal(
            $document['source_change_seq'] ?? null,
        );
        $authoritativeSourceChangeSeq = $this->unsignedDecimal(
            $index['authoritative_source_change_seq'] ?? null,
        );
        $documentTime = $this->nullableTime($document['sent_at'] ?? null);
        $indexTime = $this->nullableTime($index['index_create_time'] ?? null);
        $bodyTime = $this->nullableTime($body['create_time'] ?? null);
        $contentMatches = hash_equals(
            (string) ($document['content'] ?? ''),
            (string) ($body['content'] ?? ''),
        );

        if ((int) ($index['organization'] ?? 0) !== $organization
            || (int) ($body['organization'] ?? 0) !== $organization
            || !hash_equals($messageId, (string) ($index['message_id'] ?? ''))
            || !hash_equals($messageId, (string) ($body['message_id'] ?? ''))
            || !hash_equals($conversationId, (string) ($index['conversation_id'] ?? ''))
            || !hash_equals($conversationId, (string) ($body['conversation_id'] ?? ''))
            || (string) ($document['message_seq'] ?? '') !== (string) ($index['message_seq'] ?? '')
            || (string) ($index['message_seq'] ?? '') !== (string) ($body['message_seq'] ?? '')
            || $senderOrganization !== (int) ($index['sender_organization'] ?? 0)
            || $senderOrganization !== (int) ($body['sender_organization'] ?? 0)
            || !hash_equals($senderUserId, (string) ($index['sender_id'] ?? ''))
            || !hash_equals($senderUserId, (string) ($body['sender_id'] ?? ''))
            || !hash_equals(
                (string) ($index['client_msg_id'] ?? ''),
                (string) ($body['client_msg_id'] ?? ''),
            )
            || (string) ($index['client_msg_id'] ?? '') === ''
            || $documentSourceChangeSeq !== $authoritativeSourceChangeSeq
            || $documentTime !== $indexTime
            || $indexTime !== $bodyTime
            || $messageType !== (int) ($body['message_type'] ?? 0)
            || !MessageType::isFirstStage($messageType)
            || !in_array($conversationType, [1, 2], true)
            || !array_key_exists('conversation_type', $document)
            || $documentConversationType !== $conversationType
            || $conversationType !== (int) ($body['conversation_type'] ?? 0)
            || $bodyStatus !== 1
            || ($body['delete_time'] ?? null) !== null
            || !$contentMatches) {
            throw new SearchProjectionIntegrityException(
                'Search document, IM message index and shard body binding is inconsistent.',
                503,
            );
        }

        return $conversationType;
    }

    /** @param list<string> $messageIds @return array{0:string,1:list<string>} */
    private function exactMessagePredicate(string $column, array $messageIds): array
    {
        if ($messageIds === []) {
            throw new SearchProjectionIntegrityException('Search candidate batch is empty.');
        }
        return [
            implode(' OR ', array_fill(
                0,
                count($messageIds),
                'BINARY ' . $column . ' = BINARY ?',
            )),
            array_values($messageIds),
        ];
    }

    private function positiveOrganization(mixed $value): int
    {
        $organization = (int) $value;
        if ($organization <= 0) {
            throw new SearchProjectionIntegrityException(
                'Search candidate organization is invalid.',
            );
        }

        return $organization;
    }

    private function canonicalId(mixed $value, string $field): string
    {
        $value = is_string($value) ? $value : '';
        if ($value === ''
            || strlen($value) > 64
            || trim($value) !== $value
            || preg_match('/[\x00\x09\x0A\x0B\x0D|]/', $value) === 1) {
            throw new SearchProjectionIntegrityException(
                'Search candidate ' . $field . ' is invalid.',
            );
        }

        return $value;
    }

    private function nullableTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new SearchProjectionIntegrityException(
                'Search candidate message time is invalid.',
            );
        }

        return $value;
    }

    private function unsignedDecimal(mixed $value): string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new SearchProjectionIntegrityException(
                'Search candidate source change sequence is invalid.',
            );
        }
        $canonical = ltrim($value, '0');

        return $canonical === '' ? '0' : $canonical;
    }

    private function quoteShard(string $table): string
    {
        if (preg_match('/^im_message_\d{4}_\d{6}$/D', $table) !== 1) {
            throw new SearchProjectionIntegrityException(
                'Authoritative IM message shard table is invalid.',
            );
        }

        return '`' . $table . '`';
    }
}
