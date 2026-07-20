<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use B8im\ImShared\Support\MessageShardIdentity;
use support\think\Db;

/** Derives physical message tables independently from mutable index routes. */
final class MessageShardRouteValidator
{
    private ?int $bucketCount = null;

    public function expectedTable(
        int $organization,
        string $conversationId,
        string $createTime,
    ): string {
        try {
            return MessageShardIdentity::tableName(
                $organization,
                $conversationId,
                $createTime,
                $this->bucketCount(),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(
                'IM message shard identity is invalid.',
                0,
                $exception,
            );
        }
    }

    /** @param array<string,mixed> $index */
    public function assertIndexRoute(
        array $index,
        int $organization,
        string $conversationId,
    ): string {
        $indexedConversationId = (string) ($index['conversation_id'] ?? '');
        $indexedCreateTime = (string) ($index['index_create_time'] ?? '');
        $expected = $this->expectedTable(
            $organization,
            $conversationId,
            $indexedCreateTime,
        );
        if ((int) ($index['organization'] ?? 0) !== $organization
            || !hash_equals($conversationId, $indexedConversationId)
            || (string) ($index['storage_node'] ?? '') !== 'mysql-primary'
            || !hash_equals($expected, (string) ($index['shard_table'] ?? ''))) {
            throw new \RuntimeException('IM message index shard route is inconsistent.');
        }
        return $expected;
    }

    private function bucketCount(): int
    {
        if ($this->bucketCount !== null) {
            return $this->bucketCount;
        }
        $row = Db::query(
            'SELECT config_value FROM im_runtime_config
              WHERE config_key = "message_shard_buckets" LIMIT 1',
        )[0] ?? null;
        $value = (string) ($row['config_value'] ?? '');
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $value) !== 1) {
            throw new \RuntimeException('IM message_shard_buckets runtime config is invalid.');
        }
        $bucketCount = (int) $value;
        try {
            MessageShardIdentity::assertValidBucketCount($bucketCount);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(
                'IM message_shard_buckets runtime config is invalid.',
                0,
                $exception,
            );
        }
        $this->bucketCount = $bucketCount;
        return $bucketCount;
    }
}
