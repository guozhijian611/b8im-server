<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchConsumer;

use B8im\ImShared\Protocol\Dto\CanonicalDecimal;
use B8im\ImShared\Protocol\Dto\SearchProjectionEvent;
use B8im\Module\Search\Consumer\PoisonMessageException;
use B8im\Module\Search\Consumer\ProjectionWriter;
use B8im\ModuleSdk\State\SystemModuleStatus;
use B8im\ModuleSdk\State\TenantModuleStatus;
use JsonException;
use plugin\saimulti\exception\SearchProjectionIntegrityException;
use plugin\saimulti\service\module\SearchDocumentProjectionServiceInterface;
use RuntimeException;
use support\think\Db;

final class ServerSearchProjectionWriter implements ProjectionWriter
{
    public function __construct(private readonly SearchDocumentProjectionServiceInterface $search)
    {
    }

    public function write(SearchProjectionEvent $event): void
    {
        Db::transaction(function () use ($event): void {
            $this->assertSystemEnabledForUpdate();
            $authorized = $this->tenantAuthorizedForUpdate($event->organization);
            $index = $this->indexForUpdate($event->organization);
            $this->assertOutboxIdentityForUpdate($event);
            if (!$authorized) {
                $this->markRebuildRequiredLocked(
                    $event->organization,
                    'Search projection authorization was denied.',
                );
                return;
            }
            if ((int) ($index['lifecycle_fenced'] ?? 1) !== 0) {
                throw new RuntimeException('Search projection is lifecycle fenced.');
            }

            $this->search->projectMessageDocumentLocked(
                $event->organization,
                $event->messageId,
            );
            $this->recordReceiptAndAdvanceCheckpointLocked($event);
        });
    }

    public function deny(SearchProjectionEvent $event): void
    {
        Db::transaction(function () use ($event): void {
            // A system lifecycle transition is a temporary transport fence,
            // never a tenant denial that may be acknowledged.
            $this->assertSystemEnabledForUpdate();
            $this->tenantAuthorizedForUpdate($event->organization);
            $this->indexForUpdate($event->organization);
            $this->assertOutboxIdentityForUpdate($event);
            $this->markRebuildRequiredLocked(
                $event->organization,
                'Search projection authorization was denied.',
            );
        });
    }

    /** @return array<string,mixed> */
    private function assertSystemEnabledForUpdate(): array
    {
        $rows = Db::query(
            'SELECT status,platforms_json,capabilities_json FROM sm_module'
            . ' WHERE module_key=? AND delete_time IS NULL FOR UPDATE',
            ['search'],
        );
        if (count($rows) !== 1
            || !hash_equals(SystemModuleStatus::ENABLED->value, (string) ($rows[0]['status'] ?? ''))) {
            throw new RuntimeException('Search system module is not enabled.');
        }
        $platforms = json_decode((string) ($rows[0]['platforms_json'] ?? ''), true);
        $capabilities = json_decode((string) ($rows[0]['capabilities_json'] ?? ''), true);
        if (!is_array($platforms) || !in_array('server', $platforms, true)
            || !is_array($capabilities)
            || !in_array('search.index.write', $capabilities['server'] ?? [], true)) {
            throw new RuntimeException('Search system module capability is unavailable.');
        }

        return $rows[0];
    }

    private function tenantAuthorizedForUpdate(int $organization): bool
    {
        $rows = Db::query(
            'SELECT status,expire_at,(expire_at IS NULL OR expire_at>NOW()) AS active'
            . ' FROM sm_tenant_module_license'
            . ' WHERE organization=? AND module_key=? AND delete_time IS NULL FOR UPDATE',
            [$organization, 'search'],
        );
        if (count($rows) > 1) {
            throw new SearchProjectionIntegrityException('Search tenant license identity is ambiguous.');
        }

        return count($rows) === 1
            && hash_equals(TenantModuleStatus::ENABLED->value, (string) ($rows[0]['status'] ?? ''))
            && (int) ($rows[0]['active'] ?? 0) === 1;
    }

    /** @return array<string,mixed> */
    private function indexForUpdate(int $organization): array
    {
        Db::execute(
            <<<'SQL'
INSERT INTO sm_search_index
       (organization,backend,status,doc_count,last_built_at,last_error,
        rebuild_required,lifecycle_fenced,create_time,update_time)
VALUES (?,'mysql','idle',0,NULL,'',1,0,NOW(),NOW())
ON DUPLICATE KEY UPDATE id=id
SQL,
            [$organization],
        );
        $rows = Db::query(
            'SELECT id,status,rebuild_required,lifecycle_fenced FROM sm_search_index'
            . ' WHERE organization=? AND delete_time IS NULL FOR UPDATE',
            [$organization],
        );
        if (count($rows) !== 1) {
            throw new SearchProjectionIntegrityException('Search index state is missing or ambiguous.');
        }

        return $rows[0];
    }

    private function assertOutboxIdentityForUpdate(SearchProjectionEvent $event): void
    {
        $rows = Db::query(
            <<<'SQL'
SELECT event_id,organization,event_type,routing_key,message_id,
       CAST(source_event_seq AS CHAR) AS source_event_seq,payload_json
  FROM im_message_outbox
 WHERE organization=? AND source_event_seq=?
 FOR UPDATE
SQL,
            [$event->organization, $event->sourceEventSeq],
        );
        if (count($rows) !== 1) {
            throw new PoisonMessageException('Search outbox identity is missing or ambiguous.');
        }
        $row = $rows[0];
        $columns = [
            'event_contract' => SearchProjectionEvent::CONTRACT,
            'event_id' => (string) ($row['event_id'] ?? ''),
            'organization' => (int) ($row['organization'] ?? 0),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'source_event_seq' => (string) ($row['source_event_seq'] ?? ''),
            'message_id' => (string) ($row['message_id'] ?? ''),
        ];
        if ((string) ($row['routing_key'] ?? '') !== $event->eventType
            || $columns !== $event->toArray()) {
            throw new PoisonMessageException('Search outbox columns differ from delivery identity.');
        }
        try {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PoisonMessageException('Search outbox payload is invalid JSON.', previous: $exception);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new PoisonMessageException('Search outbox payload must be an object.');
        }
        try {
            $payloadEvent = SearchProjectionEvent::fromArray($payload);
        } catch (\InvalidArgumentException $exception) {
            throw new PoisonMessageException('Search outbox payload identity is invalid.', previous: $exception);
        }
        if ($payloadEvent->toArray() !== $event->toArray()) {
            throw new PoisonMessageException('Search outbox payload differs from delivery identity.');
        }
    }

    private function recordReceiptAndAdvanceCheckpointLocked(SearchProjectionEvent $event): void
    {
        $receipts = Db::query(
            'SELECT organization,CAST(source_event_seq AS CHAR) AS source_event_seq,'
            . ' event_id,event_type,message_id FROM sm_search_projection_receipt'
            . ' WHERE (organization=? AND source_event_seq=?) OR event_id=? FOR UPDATE',
            [$event->organization, $event->sourceEventSeq, $event->eventId],
        );
        if ($receipts === []) {
            Db::execute(
                'INSERT INTO sm_search_projection_receipt'
                . ' (organization,source_event_seq,event_id,event_type,message_id,applied_at)'
                . ' VALUES (?,?,?,?,?,NOW())',
                [
                    $event->organization,
                    $event->sourceEventSeq,
                    $event->eventId,
                    $event->eventType,
                    $event->messageId,
                ],
            );
        } elseif (count($receipts) !== 1 || [
            'organization' => (int) ($receipts[0]['organization'] ?? 0),
            'source_event_seq' => (string) ($receipts[0]['source_event_seq'] ?? ''),
            'event_id' => (string) ($receipts[0]['event_id'] ?? ''),
            'event_type' => (string) ($receipts[0]['event_type'] ?? ''),
            'message_id' => (string) ($receipts[0]['message_id'] ?? ''),
        ] !== [
            'organization' => $event->organization,
            'source_event_seq' => $event->sourceEventSeq,
            'event_id' => $event->eventId,
            'event_type' => $event->eventType,
            'message_id' => $event->messageId,
        ]) {
            throw new PoisonMessageException('Search projection receipt identity conflicts.');
        }

        Db::execute(
            'INSERT INTO sm_search_projection_checkpoint'
            . ' (organization,reconciled_through_event_seq,update_time) VALUES (?,0,NOW())'
            . ' ON DUPLICATE KEY UPDATE organization=organization',
            [$event->organization],
        );
        $checkpointRows = Db::query(
            'SELECT CAST(reconciled_through_event_seq AS CHAR) AS checkpoint'
            . ' FROM sm_search_projection_checkpoint WHERE organization=? FOR UPDATE',
            [$event->organization],
        );
        if (count($checkpointRows) !== 1) {
            throw new SearchProjectionIntegrityException('Search projection checkpoint is missing.');
        }
        $checkpoint = CanonicalDecimal::nonNegative(
            (string) ($checkpointRows[0]['checkpoint'] ?? ''),
            'reconciled_through_event_seq',
        );
        while (true) {
            $rows = Db::query(
                'SELECT CAST(source_event_seq AS CHAR) AS source_event_seq'
                . ' FROM sm_search_projection_receipt'
                . ' WHERE organization=? AND source_event_seq>?'
                . ' ORDER BY source_event_seq ASC LIMIT 1000',
                [$event->organization, $checkpoint],
            );
            $advanced = false;
            foreach ($rows as $row) {
                $expected = CanonicalDecimal::increment($checkpoint, 'reconciled_through_event_seq');
                if ((string) ($row['source_event_seq'] ?? '') !== $expected) {
                    break;
                }
                $checkpoint = $expected;
                $advanced = true;
            }
            if (!$advanced || count($rows) < 1000) {
                break;
            }
        }
        Db::execute(
            'UPDATE sm_search_projection_checkpoint'
            . ' SET reconciled_through_event_seq=?,update_time=NOW() WHERE organization=?',
            [$checkpoint, $event->organization],
        );
    }

    private function markRebuildRequiredLocked(int $organization, string $reason): void
    {
        Db::execute(
            "UPDATE sm_search_index SET status='error',rebuild_required=1,"
            . 'last_error=?,update_time=NOW() WHERE organization=? AND delete_time IS NULL',
            [substr($reason, 0, 500), $organization],
        );
    }
}
