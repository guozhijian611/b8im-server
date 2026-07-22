<?php

declare(strict_types=1);

namespace plugin\saimulti\service\searchRebuild;

use B8im\Module\Search\Rebuild\BarrierStatus;
use B8im\Module\Search\Rebuild\BatchResult;
use B8im\Module\Search\Rebuild\Claim;
use B8im\Module\Search\Rebuild\CleanupResult;
use B8im\ImShared\Protocol\Dto\CanonicalDecimal;
use B8im\Module\Search\Rebuild\Projection;
use B8im\Module\Search\Rebuild\StaleClaimException;
use B8im\Module\Search\Rebuild\Store;
use plugin\saimulti\exception\SearchProjectionIntegrityException;
use B8im\ModuleSdk\State\SystemModuleStatus;
use plugin\saimulti\service\searchConsumer\SearchConsumerGateInterface;
use plugin\saimulti\service\searchConsumer\ThinkOrmSearchConsumerGate;
use RuntimeException;
use support\think\Db;

final class ThinkOrmSearchRebuildStore implements Store
{
    public function __construct(
        private readonly SearchConsumerGateInterface $gate = new ThinkOrmSearchConsumerGate(),
    ) {
    }

    public function enqueue(int $organization, int $actorId): array
    {
        return Db::transaction(function () use ($organization, $actorId): array {
            $this->assertSystemEnabledForUpdate();
            Db::execute(
                <<<'SQL'
INSERT INTO sm_search_index
       (organization,backend,status,doc_count,last_built_at,last_error,
        rebuild_required,lifecycle_fenced,created_by,updated_by,create_time,update_time)
VALUES (?,'mysql','idle',0,NULL,'',1,0,?,?,NOW(),NOW())
ON DUPLICATE KEY UPDATE id=id
SQL,
                [$organization, $actorId, $actorId],
            );
            $index = $this->requireIndexForUpdate($organization);
            $this->assertIndexClaimable($index);
            $activeRows = Db::query(
                $this->jobSelect()
                . " WHERE organization = ? AND job_type = 'rebuild'"
                . " AND status IN ('pending','running') ORDER BY id DESC",
                [$organization],
            );
            if (count($activeRows) > 1) {
                throw new SearchProjectionIntegrityException('Active search rebuild identity is ambiguous.');
            }
            if ($activeRows !== []) {
                return [
                    'job' => $this->formatJob($activeRows[0]),
                    'index' => $this->formatIndex($index),
                ];
            }

            Db::execute(
                'INSERT INTO im_organization_message_sequence
                        (organization,next_global_seq,create_time,update_time)
                 VALUES (?,1,NOW(),NOW())
                 ON DUPLICATE KEY UPDATE organization=organization',
                [$organization],
            );
            $sequenceRows = Db::query(
                'SELECT CAST(CASE WHEN next_global_seq = 0 THEN 0 ELSE next_global_seq - 1 END AS CHAR) AS high_water,
                        CAST(last_search_event_seq AS CHAR) AS source_event_cut
                   FROM im_organization_message_sequence
                  WHERE organization = ? FOR UPDATE',
                [$organization],
            );
            if (count($sequenceRows) !== 1) {
                throw new SearchProjectionIntegrityException('Organization message sequence initialization failed.');
            }
            $highWater = $this->decimalString(
                $sequenceRows[0]['high_water'] ?? null,
                'high water',
            );
            $sourceEventCut = $this->decimalString(
                $sequenceRows[0]['source_event_cut'] ?? null,
                'source event cut',
            );
            $totalRows = Db::query(
                'SELECT CAST(COUNT(*) AS CHAR) AS total
                   FROM im_message_index
                  WHERE organization = ? AND global_seq <= ?',
                [$organization, $highWater],
            );
            $total = $this->decimalString($totalRows[0]['total'] ?? null, 'total');
            $cleanupRows = Db::query(
                'SELECT CAST(COALESCE(MAX(id),0) AS CHAR) AS cleanup_high_water
                   FROM sm_search_doc WHERE organization=?',
                [$organization],
            );
            $cleanupHighWater = $this->decimalString(
                $cleanupRows[0]['cleanup_high_water'] ?? null,
                'cleanup high water',
            );
            Db::execute(
                <<<'SQL'
INSERT INTO sm_search_job
       (organization,job_type,status,processed,total,cursor_global_seq,
        high_water_global_seq,source_event_cut,cleanup_cursor_doc_id,cleanup_high_water_doc_id,
        worker_id,claim_token,locked_until,retry_count,
        next_retry_at,error_message,created_by,updated_by,started_at,finished_at,
        create_time,update_time)
VALUES (?,'rebuild','pending',0,?,0,?,?,0,?,NULL,NULL,NULL,0,NOW(),'',?,?,NULL,NULL,NOW(),NOW())
SQL,
                [$organization, $total, $highWater, $sourceEventCut, $cleanupHighWater, $actorId, $actorId],
            );
            $idRows = Db::query('SELECT CAST(LAST_INSERT_ID() AS CHAR) AS id');
            $jobRows = Db::query(
                $this->jobSelect() . ' WHERE id = ? FOR UPDATE',
                [$this->decimalString($idRows[0]['id'] ?? null, 'job id')],
            );
            if (count($jobRows) !== 1) {
                throw new SearchProjectionIntegrityException('Search rebuild job insert fence failed.');
            }
            Db::execute(
                "UPDATE sm_search_index
                    SET status='building',rebuild_required=1,last_error='',updated_by=?,update_time=NOW()
                  WHERE organization=? AND delete_time IS NULL",
                [$actorId, $organization],
            );

            return [
                'job' => $this->formatJob($jobRows[0]),
                'index' => $this->formatIndex($this->requireIndexForUpdate($organization)),
            ];
        });
    }

    public function claim(string $workerId, int $leaseSeconds): ?Claim
    {
        $this->assertLease($leaseSeconds);
        if (!$this->gate->canFetch()) {
            return null;
        }
        $candidate = Db::query(
            $this->jobSelect()
            . " WHERE job_type='rebuild' AND ("
            . "(status='pending' AND (next_retry_at IS NULL OR next_retry_at <= NOW()))"
            . " OR (status='running' AND locked_until <= NOW()))"
            . ' ORDER BY id ASC LIMIT 1',
        );
        if ($candidate === []) {
            return null;
        }
        $candidateId = $this->decimalString($candidate[0]['id'] ?? null, 'job id');
        $candidateOrganization = (int) ($candidate[0]['organization'] ?? 0);
        return Db::transaction(function () use ($workerId, $leaseSeconds, $candidateId, $candidateOrganization): ?Claim {
            $this->assertSystemEnabledForUpdate();
            $this->assertIndexClaimable($this->requireIndexForUpdate($candidateOrganization));
            $rows = Db::query(
                $this->jobSelect()
                . " WHERE id=? AND organization=? AND job_type='rebuild' AND ("
                . "(status='pending' AND (next_retry_at IS NULL OR next_retry_at <= NOW()))"
                . " OR (status='running' AND locked_until <= NOW()))"
                . ' FOR UPDATE',
                [$candidateId, $candidateOrganization],
            );
            if ($rows === []) {
                return null;
            }
            $jobId = $this->decimalString($rows[0]['id'] ?? null, 'job id');
            $organization = (int) ($rows[0]['organization'] ?? 0);
            if ($organization < 1) {
                throw new SearchProjectionIntegrityException('Search rebuild claim organization is invalid.');
            }
            $token = bin2hex(random_bytes(20));
            Db::execute(
                <<<'SQL'
UPDATE sm_search_job
   SET status='running',worker_id=?,claim_token=?,
       locked_until=TIMESTAMPADD(SECOND,?,NOW()),
       started_at=COALESCE(started_at,NOW()),next_retry_at=NULL,update_time=NOW()
 WHERE id=? AND organization=? AND job_type='rebuild'
   AND ((status='pending' AND (next_retry_at IS NULL OR next_retry_at <= NOW()))
        OR (status='running' AND locked_until <= NOW()))
SQL,
                [$workerId, $token, $leaseSeconds, $jobId, $organization],
            );

            return $this->claimByIdentity($jobId, $organization, $workerId, $token, false);
        });
    }

    public function renew(Claim $claim, int $leaseSeconds): Claim
    {
        $this->assertLease($leaseSeconds);
        return Db::transaction(function () use ($claim, $leaseSeconds): Claim {
            $this->assertClaimableScopeForUpdate($claim->organization);
            $this->ownedJobForUpdate($claim);
            Db::execute(
                "UPDATE sm_search_job
                    SET locked_until=TIMESTAMPADD(SECOND,?,NOW()),update_time=NOW()
                  WHERE id=? AND organization=? AND status='running'
                    AND worker_id=? AND claim_token=?",
                [
                    $leaseSeconds,
                    $claim->jobId,
                    $claim->organization,
                    $claim->workerId,
                    $claim->claimToken,
                ],
            );
            return $this->claimByIdentity(
                $claim->jobId,
                $claim->organization,
                $claim->workerId,
                $claim->claimToken,
                true,
            );
        });
    }

    public function processBatch(
        Claim $claim,
        int $batchSize,
        int $leaseSeconds,
        Projection $projector,
    ): BatchResult {
        $batchSize = $this->boundedBatchSize($batchSize);
        $this->assertLease($leaseSeconds);
        return Db::transaction(function () use (
            $claim,
            $batchSize,
            $leaseSeconds,
            $projector,
        ): BatchResult {
            $this->assertClaimableScopeForUpdate($claim->organization);
            $owned = $this->ownedJobForUpdate($claim);
            $cursor = $this->decimalString($owned['cursor_global_seq'] ?? null, 'cursor');
            $processed = $this->decimalString($owned['processed'] ?? null, 'processed');
            $highWater = $this->decimalString(
                $owned['high_water_global_seq'] ?? null,
                'high water',
            );
            $rows = Db::query(
                'SELECT message_id,CAST(global_seq AS CHAR) AS global_seq
                   FROM im_message_index
                  WHERE organization=? AND global_seq>? AND global_seq<=?
               ORDER BY global_seq ASC LIMIT ' . $batchSize . ' FOR UPDATE',
                [$claim->organization, $cursor, $highWater],
            );
            foreach ($rows as $row) {
                $messageId = (string) ($row['message_id'] ?? '');
                if ($messageId === '') {
                    throw new SearchProjectionIntegrityException('Search rebuild source identity is invalid.');
                }
                $projector->projectMessageDocumentLocked($claim->organization, $messageId);
            }
            $projected = count($rows);
            $nextCursor = $projected === 0
                ? $highWater
                : $this->decimalString($rows[$projected - 1]['global_seq'] ?? null, 'cursor');
            $scanComplete = $projected < $batchSize
                || CanonicalDecimal::compare($nextCursor, $highWater) === 0;
            if ($scanComplete) {
                $nextCursor = $highWater;
            }
            $expectedProcessed = $this->decimalAdd($processed, $projected);
            Db::execute(
                "UPDATE sm_search_job
                    SET cursor_global_seq=?,processed=processed+?,
                        locked_until=TIMESTAMPADD(SECOND,?,NOW()),update_time=NOW()
                  WHERE id=? AND organization=? AND status='running'
                    AND worker_id=? AND claim_token=?",
                [
                    $nextCursor,
                    $projected,
                    $leaseSeconds,
                    $claim->jobId,
                    $claim->organization,
                    $claim->workerId,
                    $claim->claimToken,
                ],
            );
            $fresh = $this->claimByIdentity(
                $claim->jobId,
                $claim->organization,
                $claim->workerId,
                $claim->claimToken,
                true,
            );
            if (CanonicalDecimal::compare($fresh->cursorGlobalSeq, $nextCursor) !== 0
                || CanonicalDecimal::compare($fresh->processed, $expectedProcessed) !== 0) {
                throw new SearchProjectionIntegrityException(
                    'Search rebuild batch progress did not persist atomically.',
                );
            }

            return new BatchResult($fresh, $scanComplete, $projected);
        });
    }

    public function cleanupBatch(
        Claim $claim,
        int $batchSize,
        int $leaseSeconds,
    ): CleanupResult {
        $batchSize = $this->boundedBatchSize($batchSize);
        $this->assertLease($leaseSeconds);
        return Db::transaction(function () use (
            $claim,
            $batchSize,
            $leaseSeconds,
        ): CleanupResult {
            $this->assertClaimableScopeForUpdate($claim->organization);
            $owned = $this->ownedJobForUpdate($claim);
            if (CanonicalDecimal::compare(
                $this->decimalString($owned['cursor_global_seq'] ?? null, 'cursor'),
                $this->decimalString($owned['high_water_global_seq'] ?? null, 'high water'),
            ) !== 0) {
                throw new RuntimeException('Search rebuild cleanup started before the high-water scan completed.');
            }
            $cleanupCursor = $this->decimalString(
                $owned['cleanup_cursor_doc_id'] ?? null,
                'cleanup cursor',
            );
            $cleanupHighWater = $this->decimalString(
                $owned['cleanup_high_water_doc_id'] ?? null,
                'cleanup high water',
            );
            $candidates = Db::query(
                'SELECT CAST(d.id AS CHAR) AS id,d.message_id
                   FROM sm_search_doc d
                  WHERE d.organization=? AND d.id>? AND d.id<=?
               ORDER BY d.id ASC LIMIT ' . $batchSize,
                [$claim->organization, $cleanupCursor, $cleanupHighWater],
            );
            $deleted = 0;
            foreach ($candidates as $candidate) {
                $messageId = (string) ($candidate['message_id'] ?? '');
                $source = Db::query(
                    'SELECT message_id FROM im_message_index
                      WHERE organization=? AND BINARY message_id=BINARY ? FOR UPDATE',
                    [$claim->organization, $messageId],
                );
                if ($source !== []) {
                    continue;
                }
                $docId = $this->decimalString($candidate['id'] ?? null, 'document id');
                $doc = Db::query(
                    'SELECT id FROM sm_search_doc
                      WHERE id=? AND organization=? AND BINARY message_id=BINARY ? FOR UPDATE',
                    [$docId, $claim->organization, $messageId],
                );
                if (count($doc) !== 1) {
                    continue;
                }
                $deleted += Db::execute(
                    'DELETE d FROM sm_search_doc d
                      WHERE d.id=? AND d.organization=?
                        AND NOT EXISTS (
                            SELECT 1 FROM im_message_index mi
                             WHERE mi.organization=d.organization
                               AND BINARY mi.message_id=BINARY d.message_id
                        )',
                    [$docId, $claim->organization],
                );
            }
            $scanned = count($candidates);
            $nextCleanupCursor = $scanned === 0
                ? $cleanupHighWater
                : $this->decimalString(
                    $candidates[$scanned - 1]['id'] ?? null,
                    'cleanup cursor',
                );
            $complete = $scanned < $batchSize
                || CanonicalDecimal::compare($nextCleanupCursor, $cleanupHighWater) === 0;
            if ($complete) {
                $nextCleanupCursor = $cleanupHighWater;
            }
            Db::execute(
                "UPDATE sm_search_job
                    SET cleanup_cursor_doc_id=?,
                        locked_until=TIMESTAMPADD(SECOND,?,NOW()),update_time=NOW()
                  WHERE id=? AND organization=? AND status='running'
                    AND worker_id=? AND claim_token=?",
                [
                    $nextCleanupCursor,
                    $leaseSeconds,
                    $claim->jobId,
                    $claim->organization,
                    $claim->workerId,
                    $claim->claimToken,
                ],
            );
            $fresh = $this->claimByIdentity(
                $claim->jobId,
                $claim->organization,
                $claim->workerId,
                $claim->claimToken,
                true,
            );
            if (CanonicalDecimal::compare(
                $fresh->cleanupCursorDocId,
                $nextCleanupCursor,
            ) !== 0) {
                throw new SearchProjectionIntegrityException(
                    'Search rebuild cleanup progress did not persist atomically.',
                );
            }

            return new CleanupResult(
                $fresh,
                $scanned,
                $deleted,
                $complete,
            );
        });
    }

    public function captureBarrier(Claim $claim, int $timeoutSeconds): Claim
    {
        if ($timeoutSeconds < 10 || $timeoutSeconds > 86400) {
            throw new RuntimeException('Invalid search rebuild barrier timeout.');
        }

        return Db::transaction(function () use ($claim, $timeoutSeconds): Claim {
            $this->assertClaimableScopeForUpdate($claim->organization);
            $owned = $this->ownedJobForUpdate($claim);
            $this->assertRebuildScannedAndCleaned($owned);
            if (($owned['barrier_event_cut'] ?? null) !== null) {
                return $this->toClaim($owned);
            }
            $sequenceRows = Db::query(
                'SELECT CAST(last_search_event_seq AS CHAR) AS barrier_event_cut'
                . ' FROM im_organization_message_sequence WHERE organization=? FOR UPDATE',
                [$claim->organization],
            );
            if (count($sequenceRows) !== 1) {
                throw new SearchProjectionIntegrityException('Search source event sequence is missing.');
            }
            $barrierCut = $this->decimalString(
                $sequenceRows[0]['barrier_event_cut'] ?? null,
                'barrier event cut',
            );
            $sourceCut = $this->decimalString($owned['source_event_cut'] ?? null, 'source event cut');
            if (CanonicalDecimal::compare($barrierCut, $sourceCut) < 0) {
                throw new SearchProjectionIntegrityException('Search barrier event cut regressed.');
            }
            $this->lockCheckpointAndAdvanceBaseline($claim->organization, $sourceCut);
            $this->foldCheckpointLocked($claim->organization);
            Db::execute(
                'UPDATE sm_search_job SET barrier_event_cut=?,barrier_deadline_at=TIMESTAMPADD(SECOND,?,NOW()),'
                . "update_time=NOW() WHERE id=? AND organization=? AND status='running'"
                . ' AND worker_id=? AND claim_token=? AND barrier_event_cut IS NULL',
                [
                    $barrierCut,
                    $timeoutSeconds,
                    $claim->jobId,
                    $claim->organization,
                    $claim->workerId,
                    $claim->claimToken,
                ],
            );

            $fresh = $this->claimByIdentity(
                $claim->jobId,
                $claim->organization,
                $claim->workerId,
                $claim->claimToken,
                true,
            );
            if ($fresh->barrierEventCut === null
                || CanonicalDecimal::compare($fresh->barrierEventCut, $barrierCut) !== 0) {
                throw new SearchProjectionIntegrityException('Search barrier freeze did not persist.');
            }

            return $fresh;
        });
    }

    public function barrierStatus(Claim $claim): BarrierStatus
    {
        return Db::transaction(function () use ($claim): BarrierStatus {
            $this->assertClaimableScopeForUpdate($claim->organization);
            $owned = $this->ownedJobForUpdate($claim);
            $cut = isset($owned['barrier_event_cut'])
                ? $this->decimalString($owned['barrier_event_cut'], 'barrier event cut')
                : null;
            if ($cut === null || ($owned['barrier_deadline_at'] ?? null) === null) {
                throw new SearchProjectionIntegrityException('Search barrier is not frozen.');
            }
            $this->lockCheckpointAndAdvanceBaseline(
                $claim->organization,
                $this->decimalString($owned['source_event_cut'] ?? null, 'source event cut'),
            );
            $checkpoint = $this->foldCheckpointLocked($claim->organization);
            $timeRows = Db::query(
                'SELECT NOW() >= ? AS timed_out',
                [(string) $owned['barrier_deadline_at']],
            );
            $satisfied = CanonicalDecimal::compare($checkpoint, $cut) >= 0;
            $timedOut = !$satisfied && (int) ($timeRows[0]['timed_out'] ?? 0) === 1;

            return new BarrierStatus(
                $checkpoint,
                $satisfied,
                $timedOut,
                $satisfied ? '0' : $this->decimalAdd($checkpoint, 1),
            );
        });
    }

    public function finalize(Claim $claim): void
    {
        Db::transaction(function () use ($claim): void {
            $this->assertClaimableScopeForUpdate($claim->organization);
            $owned = $this->ownedJobForUpdate($claim);
            $this->assertRebuildScannedAndCleaned($owned);
            $barrierCut = isset($owned['barrier_event_cut'])
                ? $this->decimalString($owned['barrier_event_cut'], 'barrier event cut')
                : null;
            if ($barrierCut === null || ($owned['barrier_deadline_at'] ?? null) === null) {
                throw new SearchProjectionIntegrityException(
                    'Search rebuild cannot finalize without a durable event barrier.',
                );
            }
            $checkpoint = $this->lockCheckpointAndAdvanceBaseline(
                $claim->organization,
                $this->decimalString($owned['source_event_cut'] ?? null, 'source event cut'),
            );
            $checkpoint = $this->foldCheckpointLocked($claim->organization, $checkpoint);
            if (CanonicalDecimal::compare($checkpoint, $barrierCut) < 0) {
                throw new SearchProjectionIntegrityException(
                    'Search rebuild cannot finalize before the durable event barrier.',
                );
            }
            $index = $this->requireIndexForUpdate($claim->organization);
            if ((int) ($index['lifecycle_fenced'] ?? 1) !== 0) {
                throw new RuntimeException('Search rebuild is lifecycle fenced.');
            }
            $countRows = Db::query(
                'SELECT CAST(COUNT(*) AS CHAR) AS doc_count'
                . ' FROM sm_search_doc WHERE organization=? AND visibility=1',
                [$claim->organization],
            );
            $docCount = $this->decimalString($countRows[0]['doc_count'] ?? null, 'doc count');
            Db::execute(
                "UPDATE sm_search_index SET status='ready',doc_count=?,rebuild_required=0,"
                . "last_built_at=NOW(),last_error='',update_time=NOW()"
                . ' WHERE organization=? AND delete_time IS NULL AND lifecycle_fenced=0',
                [$docCount, $claim->organization],
            );
            Db::execute(
                "UPDATE sm_search_job SET status='success',processed=total,finalized_checkpoint_event_seq=?,"
                . "error_message='',finished_at=NOW(),"
                . 'worker_id=NULL,claim_token=NULL,locked_until=NULL,next_retry_at=NULL,update_time=NOW()'
                . " WHERE id=? AND organization=? AND status='running' AND worker_id=? AND claim_token=?",
                [$checkpoint, $claim->jobId, $claim->organization, $claim->workerId, $claim->claimToken],
            );
            $finished = $this->requireJobStatusForUpdate($claim, 'success');
            $readyIndex = $this->requireIndexForUpdate($claim->organization);
            if (CanonicalDecimal::compare(
                $this->decimalString($finished['processed'] ?? null, 'processed'),
                $this->decimalString($finished['total'] ?? null, 'total'),
            ) !== 0 || CanonicalDecimal::compare(
                $this->decimalString($finished['cursor_global_seq'] ?? null, 'cursor'),
                $this->decimalString($finished['high_water_global_seq'] ?? null, 'high water'),
            ) !== 0 || CanonicalDecimal::compare(
                $this->decimalString($finished['cleanup_cursor_doc_id'] ?? null, 'cleanup cursor'),
                $this->decimalString($finished['cleanup_high_water_doc_id'] ?? null, 'cleanup high water'),
            ) !== 0 || CanonicalDecimal::compare(
                $this->decimalString($finished['barrier_event_cut'] ?? null, 'barrier event cut'),
                $barrierCut,
            ) !== 0 || CanonicalDecimal::compare(
                $this->decimalString(
                    $finished['finalized_checkpoint_event_seq'] ?? null,
                    'finalized checkpoint',
                ),
                $checkpoint,
            ) !== 0 || CanonicalDecimal::compare($checkpoint, $barrierCut) < 0
                || ($finished['worker_id'] ?? null) !== null
                || ($finished['claim_token'] ?? null) !== null
                || ($finished['locked_until'] ?? null) !== null
                || ($finished['barrier_deadline_at'] ?? null) === null
                || (string) ($readyIndex['status'] ?? '') !== 'ready'
                || (int) ($readyIndex['rebuild_required'] ?? 1) !== 0
                || (int) ($readyIndex['lifecycle_fenced'] ?? 1) !== 0
                || CanonicalDecimal::compare(
                    $this->decimalString($readyIndex['doc_count'] ?? null, 'doc count'),
                    $docCount,
                ) !== 0) {
                throw new SearchProjectionIntegrityException(
                    'Search rebuild finalize state did not persist atomically.',
                );
            }
        });
    }

    public function fail(Claim $claim, string $message): void
    {
        Db::transaction(function () use ($claim, $message): void {
            $this->requireIndexForUpdate($claim->organization);
            $this->ownedJobForUpdate($claim);
            $error = $this->errorMessage($message);
            Db::execute(
                "UPDATE sm_search_job
                    SET status='failed',error_message=?,finished_at=NOW(),
                        worker_id=NULL,claim_token=NULL,locked_until=NULL,next_retry_at=NULL,update_time=NOW()
                  WHERE id=? AND organization=? AND status='running'
                    AND worker_id=? AND claim_token=?",
                [
                    $error,
                    $claim->jobId,
                    $claim->organization,
                    $claim->workerId,
                    $claim->claimToken,
                ],
            );
            Db::execute(
                "UPDATE sm_search_index SET status='error',rebuild_required=1,last_error=?,update_time=NOW()
                  WHERE organization=? AND delete_time IS NULL",
                [$error, $claim->organization],
            );
            $failed = $this->requireJobStatusForUpdate($claim, 'failed');
            $errorIndex = $this->requireIndexForUpdate($claim->organization);
            if ((string) ($failed['error_message'] ?? '') !== $error
                || ($failed['worker_id'] ?? null) !== null
                || ($failed['claim_token'] ?? null) !== null
                || ($failed['locked_until'] ?? null) !== null
                || (string) ($errorIndex['status'] ?? '') !== 'error'
                || (string) ($errorIndex['last_error'] ?? '') !== $error) {
                throw new SearchProjectionIntegrityException(
                    'Search rebuild failure state did not persist atomically.',
                );
            }
        });
    }

    public function retry(Claim $claim, string $message, int $delaySeconds): void
    {
        if ($delaySeconds < 1 || $delaySeconds > 86400) {
            throw new RuntimeException('Invalid search rebuild retry delay.');
        }
        Db::transaction(function () use ($claim, $message, $delaySeconds): void {
            $owned = $this->ownedJobForUpdate($claim);
            $currentRetryCount = (int) ($owned['retry_count'] ?? -1);
            if ($currentRetryCount < 0 || $currentRetryCount >= 100) {
                throw new SearchProjectionIntegrityException('Search rebuild retry count is invalid.');
            }
            $error = $this->errorMessage($message);
            Db::execute(
                "UPDATE sm_search_job
                    SET status='pending',retry_count=retry_count+1,error_message=?,
                        next_retry_at=TIMESTAMPADD(SECOND,?,NOW()),
                        worker_id=NULL,claim_token=NULL,locked_until=NULL,update_time=NOW()
                  WHERE id=? AND organization=? AND status='running'
                    AND worker_id=? AND claim_token=?",
                [
                    $error,
                    $delaySeconds,
                    $claim->jobId,
                    $claim->organization,
                    $claim->workerId,
                    $claim->claimToken,
                ],
            );
            $pending = $this->requireJobStatusForUpdate($claim, 'pending');
            if ((int) ($pending['retry_count'] ?? -1) !== $currentRetryCount + 1
                || (string) ($pending['error_message'] ?? '') !== $error
                || ($pending['next_retry_at'] ?? null) === null
                || ($pending['worker_id'] ?? null) !== null
                || ($pending['claim_token'] ?? null) !== null
                || ($pending['locked_until'] ?? null) !== null) {
                throw new SearchProjectionIntegrityException(
                    'Search rebuild retry state did not persist atomically.',
                );
            }
        });
    }

    /** @return array<string,mixed> */
    private function ownedJobForUpdate(Claim $claim): array
    {
        $rows = Db::query(
            $this->jobSelect()
            . " WHERE id=? AND organization=? AND status='running' AND worker_id=? AND claim_token=?"
            . ' AND locked_until > NOW() FOR UPDATE',
            [$claim->jobId, $claim->organization, $claim->workerId, $claim->claimToken],
        );
        if (count($rows) !== 1) {
            throw new StaleClaimException('Search rebuild ownership is stale.');
        }
        return $rows[0];
    }

    private function claimByIdentity(
        string $jobId,
        int $organization,
        string $workerId,
        string $token,
        bool $requireUnexpired,
    ): Claim {
        $rows = Db::query(
            $this->jobSelect()
            . " WHERE id=? AND organization=? AND status='running' AND worker_id=? AND claim_token=?"
            . ($requireUnexpired ? ' AND locked_until > NOW()' : ''),
            [$jobId, $organization, $workerId, $token],
        );
        if (count($rows) !== 1) {
            throw new StaleClaimException('Search rebuild claim identity is stale.');
        }
        return $this->toClaim($rows[0]);
    }

    /** @return array<string,mixed> */
    private function requireJobStatusForUpdate(Claim $claim, string $status): array
    {
        $rows = Db::query(
            $this->jobSelect()
            . ' WHERE id=? AND organization=? AND job_type=\'rebuild\' AND status=? FOR UPDATE',
            [$claim->jobId, $claim->organization, $status],
        );
        if (count($rows) !== 1) {
            throw new SearchProjectionIntegrityException(
                'Search rebuild job state transition did not persist.',
            );
        }
        return $rows[0];
    }

    /** @return array<string,mixed> */
    private function requireIndexForUpdate(int $organization): array
    {
        $rows = Db::query(
            'SELECT id,organization,backend,status,CAST(doc_count AS CHAR) AS doc_count,
                    rebuild_required,lifecycle_fenced,last_built_at,last_error,create_time,update_time
               FROM sm_search_index
              WHERE organization=? AND delete_time IS NULL FOR UPDATE',
            [$organization],
        );
        if (count($rows) !== 1) {
            throw new SearchProjectionIntegrityException('Search index state is missing or ambiguous.');
        }
        return $rows[0];
    }

    private function assertSystemEnabledForUpdate(): void
    {
        $rows = Db::query(
            'SELECT status FROM sm_module WHERE module_key=? AND delete_time IS NULL FOR UPDATE',
            ['search'],
        );
        if (count($rows) !== 1
            || !hash_equals(SystemModuleStatus::ENABLED->value, (string) ($rows[0]['status'] ?? ''))) {
            throw new RuntimeException('Search system module is not enabled.');
        }
    }

    private function assertClaimableScopeForUpdate(int $organization): void
    {
        if (!$this->gate->canFetch()) {
            throw new RuntimeException('Search module lifecycle is fenced.');
        }
        $this->assertSystemEnabledForUpdate();
        $this->assertIndexClaimable($this->requireIndexForUpdate($organization));
    }

    /** @param array<string,mixed> $index */
    private function assertIndexClaimable(array $index): void
    {
        if ((int) ($index['lifecycle_fenced'] ?? 1) !== 0) {
            throw new RuntimeException('Search index lifecycle is fenced.');
        }
    }

    /** @param array<string,mixed> $owned */
    private function assertRebuildScannedAndCleaned(array $owned): void
    {
        if (CanonicalDecimal::compare(
            $this->decimalString($owned['cursor_global_seq'] ?? null, 'cursor'),
            $this->decimalString($owned['high_water_global_seq'] ?? null, 'high water'),
        ) !== 0 || CanonicalDecimal::compare(
            $this->decimalString($owned['cleanup_cursor_doc_id'] ?? null, 'cleanup cursor'),
            $this->decimalString($owned['cleanup_high_water_doc_id'] ?? null, 'cleanup high water'),
        ) !== 0 || CanonicalDecimal::compare(
            $this->decimalString($owned['processed'] ?? null, 'processed'),
            $this->decimalString($owned['total'] ?? null, 'total'),
        ) !== 0) {
            throw new SearchProjectionIntegrityException(
                'Search rebuild cannot cross the barrier before scan, cleanup, and totals converge.',
            );
        }
    }

    private function lockCheckpointAndAdvanceBaseline(int $organization, string $baseline): string
    {
        $baseline = $this->decimalString($baseline, 'checkpoint baseline');
        Db::execute(
            'INSERT INTO sm_search_projection_checkpoint'
            . ' (organization,reconciled_through_event_seq,update_time) VALUES (?,0,NOW())'
            . ' ON DUPLICATE KEY UPDATE organization=organization',
            [$organization],
        );
        $rows = Db::query(
            'SELECT CAST(reconciled_through_event_seq AS CHAR) AS checkpoint'
            . ' FROM sm_search_projection_checkpoint WHERE organization=? FOR UPDATE',
            [$organization],
        );
        if (count($rows) !== 1) {
            throw new SearchProjectionIntegrityException('Search projection checkpoint is missing.');
        }
        $checkpoint = $this->decimalString($rows[0]['checkpoint'] ?? null, 'projection checkpoint');
        if (CanonicalDecimal::compare($checkpoint, $baseline) < 0) {
            Db::execute(
                'UPDATE sm_search_projection_checkpoint'
                . ' SET reconciled_through_event_seq=?,update_time=NOW() WHERE organization=?',
                [$baseline, $organization],
            );
            $checkpoint = $baseline;
        }

        return $checkpoint;
    }

    private function foldCheckpointLocked(int $organization, ?string $checkpoint = null): string
    {
        if ($checkpoint === null) {
            $rows = Db::query(
                'SELECT CAST(reconciled_through_event_seq AS CHAR) AS checkpoint'
                . ' FROM sm_search_projection_checkpoint WHERE organization=? FOR UPDATE',
                [$organization],
            );
            if (count($rows) !== 1) {
                throw new SearchProjectionIntegrityException('Search projection checkpoint is missing.');
            }
            $checkpoint = $this->decimalString($rows[0]['checkpoint'] ?? null, 'projection checkpoint');
        }
        while (true) {
            $rows = Db::query(
                'SELECT CAST(source_event_seq AS CHAR) AS source_event_seq'
                . ' FROM sm_search_projection_receipt WHERE organization=? AND source_event_seq>?'
                . ' ORDER BY source_event_seq ASC LIMIT 1000',
                [$organization, $checkpoint],
            );
            $advanced = false;
            foreach ($rows as $row) {
                $expected = $this->decimalAdd($checkpoint, 1);
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
            [$checkpoint, $organization],
        );

        return $checkpoint;
    }

    private function decimalAdd(string $decimal, int $increment): string
    {
        $decimal = $this->decimalString($decimal, 'decimal');
        if ($increment < 0) {
            throw new RuntimeException('Decimal increment must be non-negative.');
        }
        $carry = $increment;
        $digits = str_split($decimal);
        for ($index = count($digits) - 1; $index >= 0 && $carry > 0; $index--) {
            $sum = (int) $digits[$index] + ($carry % 10);
            $carry = intdiv($carry, 10) + intdiv($sum, 10);
            $digits[$index] = (string) ($sum % 10);
        }
        while ($carry > 0) {
            array_unshift($digits, (string) ($carry % 10));
            $carry = intdiv($carry, 10);
        }

        return $this->decimalString(implode('', $digits), 'decimal sum');
    }

    private function jobSelect(): string
    {
        return <<<'SQL'
SELECT CAST(id AS CHAR) AS id,organization,job_type,status,
       CAST(processed AS CHAR) AS processed,CAST(total AS CHAR) AS total,
       CAST(cursor_global_seq AS CHAR) AS cursor_global_seq,
       CAST(high_water_global_seq AS CHAR) AS high_water_global_seq,
       CAST(source_event_cut AS CHAR) AS source_event_cut,
       CAST(cleanup_cursor_doc_id AS CHAR) AS cleanup_cursor_doc_id,
       CAST(cleanup_high_water_doc_id AS CHAR) AS cleanup_high_water_doc_id,
       CAST(barrier_event_cut AS CHAR) AS barrier_event_cut,barrier_deadline_at,
       CAST(finalized_checkpoint_event_seq AS CHAR) AS finalized_checkpoint_event_seq,
       worker_id,claim_token,locked_until,retry_count,next_retry_at,
       error_message,created_by,updated_by,started_at,finished_at,create_time,update_time
  FROM sm_search_job
SQL;
    }

    /** @param array<string,mixed> $row */
    private function toClaim(array $row): Claim
    {
        return new Claim(
            $this->decimalString($row['id'] ?? null, 'job id'),
            (int) ($row['organization'] ?? 0),
            $this->decimalString($row['cursor_global_seq'] ?? null, 'cursor'),
            $this->decimalString($row['high_water_global_seq'] ?? null, 'high water'),
            $this->decimalString($row['source_event_cut'] ?? null, 'source event cut'),
            $this->decimalString($row['processed'] ?? null, 'processed'),
            $this->decimalString($row['total'] ?? null, 'total'),
            $this->decimalString($row['cleanup_cursor_doc_id'] ?? null, 'cleanup cursor'),
            $this->decimalString($row['cleanup_high_water_doc_id'] ?? null, 'cleanup high water'),
            isset($row['barrier_event_cut']) ? $this->decimalString($row['barrier_event_cut'], 'barrier event cut') : null,
            isset($row['barrier_deadline_at']) ? (string) $row['barrier_deadline_at'] : null,
            (int) ($row['retry_count'] ?? -1),
            (string) ($row['worker_id'] ?? ''),
            (string) ($row['claim_token'] ?? ''),
            (string) ($row['locked_until'] ?? ''),
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function formatJob(array $row): array
    {
        return [
            'id' => $this->decimalString($row['id'] ?? null, 'job id'),
            'organization' => (int) ($row['organization'] ?? 0),
            'job_type' => (string) ($row['job_type'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'processed' => $this->decimalString($row['processed'] ?? null, 'processed'),
            'total' => $this->decimalString($row['total'] ?? null, 'total'),
            'cursor_global_seq' => $this->decimalString($row['cursor_global_seq'] ?? null, 'cursor'),
            'high_water_global_seq' => $this->decimalString($row['high_water_global_seq'] ?? null, 'high water'),
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

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function formatIndex(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'organization' => (int) ($row['organization'] ?? 0),
            'backend' => (string) ($row['backend'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'doc_count' => $this->decimalString($row['doc_count'] ?? null, 'doc count'),
            'projection_checkpoint' => $this->checkpointForRead((int) ($row['organization'] ?? 0)),
            'last_built_at' => $row['last_built_at'] ?? null,
            'last_error' => (string) ($row['last_error'] ?? ''),
            'rebuild_required' => (int) ($row['rebuild_required'] ?? 1),
            'lifecycle_fenced' => (int) ($row['lifecycle_fenced'] ?? 1),
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    private function errorMessage(string $message): string
    {
        $message = trim($message);
        return substr($message === '' ? 'Search rebuild failed.' : $message, 0, 500);
    }

    private function checkpointForRead(int $organization): string
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

    private function decimalString(mixed $value, string $field): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new SearchProjectionIntegrityException($field . ' is not a decimal value.');
        }

        return CanonicalDecimal::nonNegative((string) $value, $field);
    }

    private function boundedBatchSize(int $batchSize): int
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new RuntimeException('Invalid search rebuild batch size.');
        }
        return $batchSize;
    }

    private function assertLease(int $leaseSeconds): void
    {
        if ($leaseSeconds < 10 || $leaseSeconds > 3600) {
            throw new RuntimeException('Invalid search rebuild lease.');
        }
    }
}
