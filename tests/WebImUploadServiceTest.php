<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\web\S3WebImUploadStorage;
use plugin\saimulti\service\web\WebImPrivateS3Config;
use plugin\saimulti\service\web\WebImUploadCleanupService;
use plugin\saimulti\service\web\WebImUploadPolicyInterface;
use plugin\saimulti\service\web\WebImUploadReservationServiceInterface;
use plugin\saimulti\service\web\WebImUploadService;
use plugin\saimulti\service\web\WebImUploadStorageInterface;
use support\Request;
use Webman\Http\UploadFile;

final class TestUploadPolicy implements WebImUploadPolicyInterface
{
    public int $calls = 0;
    public bool $unavailable = false;

    public function assertAllowed(int $organization, int $sizeBytes): void
    {
        $this->calls++;
        if ($this->unavailable) {
            throw new ApiException('policy unavailable', 503);
        }
        if ($sizeBytes > 100 * 1024 * 1024) {
            throw new ApiException('base limit', 422);
        }
    }
}

final class TestUploadStorage implements WebImUploadStorageInterface
{
    public int $readyCalls = 0;
    public int $putCalls = 0;
    public int $inspectCalls = 0;
    public bool $wrongHead = false;
    public bool $unavailable = false;
    /** @var list<string> */
    public array $deletes = [];

    public function assertReady(): void
    {
        $this->readyCalls++;
        if ($this->unavailable) {
            throw new RuntimeException('simulated object storage outage');
        }
    }

    public function reservePath(int $organization, string $extension, string $objectId): string
    {
        return "private/organizations/{$organization}/im/202607/{$objectId}.{$extension}";
    }

    public function uploadExact(
        int $organization,
        SplFileInfo $file,
        string $storagePath,
        string $mimeType,
        ?callable $heartbeat = null,
    ): void {
        $this->putCalls++;
        $heartbeat?->__invoke();
    }

    public function inspect(int $organization, string $storagePath): array
    {
        $this->inspectCalls++;
        return [
            'storage_path' => $storagePath,
            'size_byte' => $this->wrongHead ? 999 : 12,
            'mime_type' => 'image/webp',
        ];
    }

    public function delete(int $organization, string $storagePath): void
    {
        $this->deletes[] = $storagePath;
    }
}

final class TestReservations implements WebImUploadReservationServiceInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $rows = [];
    public int $confirmedCharges = 0;
    public bool $failConfirm = false;
    public bool $renewAllowed = true;
    public bool $cleanupCasHit = true;
    public bool $cleanupDeleteAuthorized = true;
    public bool $claimUnavailable = false;
    public int $refreshCalls = 0;
    /** @var list<array<string,mixed>> */
    public array $cleanup = [];
    /** @var list<array{reservation_id:int,phase:string,code:int,message:string}> */
    public array $cleanupClaimErrors = [];

    public function prepare(array $reservation): array
    {
        foreach ($this->rows as $row) {
            if ($row['idempotency_key'] === $reservation['idempotency_key']) {
                if ($row['intent_hash'] !== $reservation['intent_hash']) {
                    throw new ApiException('idempotency conflict', 409);
                }
                return $row;
            }
        }
        $reservation['id'] = count($this->rows) + 1;
        $reservation['version'] = 1;
        $this->rows[$reservation['upload_id']] = $reservation;
        return $reservation;
    }

    public function findPrepare(array $intent): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['organization'] === $intent['organization']
                && $row['idempotency_key'] === $intent['idempotency_key']
                && $row['user_id'] === $intent['user_id']
                && $row['client_family'] === $intent['client_family']) {
                if ($row['intent_hash'] !== $intent['intent_hash']) {
                    throw new ApiException('idempotency conflict', 409);
                }
                return $row;
            }
        }

        return null;
    }

    public function refreshPrepare(array $intent): array
    {
        $this->refreshCalls++;
        foreach ($this->rows as $uploadId => $row) {
            if ($row['organization'] === $intent['organization']
                && $row['idempotency_key'] === $intent['idempotency_key']
                && $row['intent_hash'] === $intent['intent_hash']) {
                if (in_array($row['state'], ['reserved', 'uploading'], true)) {
                    $row['expires_at'] = date('Y-m-d H:i:s', time() + 900);
                    $row['version']++;
                    $this->rows[$uploadId] = $row;
                }
                return $row;
            }
        }
        throw new ApiException('not found', 404);
    }

    public function claim(array $identity, string $uploadId): array
    {
        if ($this->claimUnavailable) {
            throw new ApiException('central authority unavailable', 503);
        }
        $row = $this->owned($identity, $uploadId);
        if (in_array($row['state'], ['confirmed', 'object_uploaded'], true)) {
            return $row;
        }
        if ($row['state'] !== 'reserved') {
            throw new ApiException('already claimed', 409);
        }
        $row['state'] = 'uploading';
        $row['upload_lease_token'] = str_repeat('a', 64);
        $this->rows[$uploadId] = $row;
        return $row;
    }

    public function releaseBeforeObject(int $reservationId, string $leaseToken, string $reason): void
    {
        $this->mutateById($reservationId, static function (array $row): array {
            $row['state'] = 'released';
            return $row;
        });
    }

    public function renewUploadLease(int $reservationId, string $leaseToken): bool
    {
        return $this->renewAllowed;
    }

    public function markObjectUploaded(int $reservationId, string $leaseToken): void
    {
        $this->mutateById($reservationId, static function (array $row): array {
            $row['state'] = 'object_uploaded';
            $row['expires_at'] = '9999-12-31 23:59:59';
            return $row;
        });
    }

    public function confirm(array $identity, string $uploadId): array
    {
        if ($this->failConfirm) {
            throw new RuntimeException('simulated confirm database failure');
        }
        $row = $this->owned($identity, $uploadId);
        if ($row['state'] !== 'confirmed') {
            $row['state'] = 'confirmed';
            $row['expires_at'] = '9999-12-31 23:59:59';
            $this->rows[$uploadId] = $row;
            $this->confirmedCharges++;
        }
        return $this->metadata($row);
    }

    public function registerObjectCleanup(int $reservationId, string $reason): void
    {
        $this->mutateById($reservationId, static function (array $row): array {
            $row['state'] = 'cleanup_pending';
            return $row;
        });
    }

    public function release(array $identity, string $uploadId): array
    {
        $row = $this->owned($identity, $uploadId);
        if ($row['state'] === 'confirmed') {
            return ['released' => false, 'state' => 'confirmed'];
        }
        if ($row['state'] === 'reserved') {
            $row['state'] = 'released';
            $this->rows[$uploadId] = $row;
            return ['released' => true, 'state' => 'released'];
        }
        return ['released' => false, 'state' => $row['state']];
    }

    public function claimCleanupBatch(int $limit): array
    {
        $rows = array_slice($this->cleanup, 0, $limit);
        $errors = array_slice(
            $this->cleanupClaimErrors,
            0,
            max(0, $limit - count($rows)),
        );
        return [
            'scanned' => count($rows) + count($errors),
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    public function cleanupSucceeded(int $id, string $leaseToken, int $claimedVersion): bool
    {
        if ($this->cleanupCasHit) {
            $this->cleanup = [];
        }

        return $this->cleanupCasHit;
    }

    public function authorizeCleanupDelete(
        int $id,
        string $leaseToken,
        int $claimedVersion,
        int $organization,
        string $storagePath,
    ): bool {
        return $this->cleanupDeleteAuthorized;
    }

    public function cleanupFailed(
        int $id,
        string $leaseToken,
        int $claimedVersion,
        string $error,
    ): bool
    {
        return $this->cleanupCasHit;
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function owned(array $identity, string $uploadId): array
    {
        $row = $this->rows[$uploadId] ?? null;
        if (!is_array($row)
            || $row['organization'] !== $identity['organization']
            || $row['user_id'] !== $identity['user_id']) {
            throw new ApiException('not found', 404);
        }
        return $row;
    }

    private function mutateById(int $id, callable $callback): void
    {
        foreach ($this->rows as $uploadId => $row) {
            if ($row['id'] === $id) {
                $this->rows[$uploadId] = $callback($row);
            }
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function metadata(array $row): array
    {
        return [
            'file_id' => $row['file_id'],
            'kind' => $row['kind'],
            'name' => $row['filename'],
            'size' => $row['size_bytes'],
            'mime_type' => $row['mime_type'],
            'extension' => $row['extension'],
        ];
    }
}

final class TestUploadRequest extends Request
{
    public int $fileCalls = 0;

    /** @param array<string,mixed> $extraPost */
    public function __construct(
        private ?UploadFile $upload,
        private readonly string $uploadId,
        private readonly array $extraPost = [],
        private readonly bool $extraFile = false,
    )
    {
        parent::__construct("POST /saimulti/web/im/upload HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    public function post(?string $name = null, mixed $default = null): mixed
    {
        $post = ['upload_id' => $this->uploadId] + $this->extraPost;
        return $name === null ? $post : ($post[$name] ?? $default);
    }

    public function file(?string $name = null): array|null|UploadFile
    {
        $this->fileCalls++;
        $files = $this->upload === null ? [] : ['file' => $this->upload];
        if ($this->extraFile) {
            $files['legacy_file'] = $this->upload;
        }
        return $name === null ? $files : ($files[$name] ?? null);
    }
}

final class TestS3Client
{
    /** @var list<array<string,mixed>> */
    public array $puts = [];
    public function putObject(array $input): void { $this->puts[] = $input; }
    public function headObject(array $input): array
    {
        return ['ContentLength' => 12, 'ContentType' => 'image/webp'];
    }
    public function deleteObject(array $input): void {}
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$expectCode = static function (int $code, callable $callback) use ($assert): void {
    try {
        $callback();
    } catch (ApiException $exception) {
        $assert($exception->getCode() === $code, 'wrong ApiException code');
        return;
    }
    throw new RuntimeException('expected ApiException');
};

$temporary = tempnam(sys_get_temp_dir(), 'upload-quota-');
if ($temporary === false || file_put_contents($temporary, 'private-data') === false) {
    throw new RuntimeException('fixture failed');
}
try {
    $storage = new TestUploadStorage();
    $policy = new TestUploadPolicy();
    $reservations = new TestReservations();
    $service = new WebImUploadService($storage, $reservations, $policy);
    $identity = ['organization' => 901, 'user_id' => 'user_a', 'client_family' => 'web'];
    $prepared = $service->prepare(
        $identity,
        str_repeat('1', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $assert(
        preg_match('/^[a-f0-9]{64}$/', $prepared['upload_id']) === 1
        && $prepared['upload_path'] === '/saimulti/web/im/upload'
        && $prepared['expires_at'] > time(),
        'prepare contract invalid',
    );
    $retry = $service->prepare(
        $identity,
        str_repeat('1', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $assert($retry['upload_id'] === $prepared['upload_id'], 'prepare was not idempotent');
    $assert(
        $reservations->refreshCalls === 1 && $retry['expires_at'] > time() + 800,
        'reserved prepare retry did not revalidate and refresh its expiry',
    );
    $expectCode(409, static fn () => $service->prepare(
        $identity,
        str_repeat('1', 32),
        'image',
        'different.webp',
        12,
        'image/webp',
    ));
    $expectCode(422, static fn () => $service->prepare(
        $identity,
        str_repeat('A', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    ));
    $expectCode(422, static fn () => $service->prepare(
        $identity,
        str_repeat('c', 32),
        'file',
        "bad-\xC3\x28.txt",
        12,
        'text/plain',
    ));

    $request = new TestUploadRequest(new UploadFile(
        $temporary,
        'avatar.webp',
        'image/webp',
        UPLOAD_ERR_OK,
    ), $prepared['upload_id']);
    $uploaded = $service->upload($identity, $request, $prepared['upload_id']);
    $assert(
        $uploaded['file_id'] !== '' && $storage->putCalls === 1
        && $reservations->confirmedCharges === 1,
        'upload did not confirm once',
    );
    $retryRequest = new TestUploadRequest(new UploadFile(
        $temporary,
        'avatar.webp',
        'image/webp',
        UPLOAD_ERR_OK,
    ), $prepared['upload_id']);
    $assert(
        $service->upload($identity, $retryRequest, $prepared['upload_id']) === $uploaded
        && $retryRequest->fileCalls === 1
        && $storage->putCalls === 1
        && $reservations->confirmedCharges === 1,
        'confirmed retry reuploaded or recharged',
    );
    $putsBeforeTerminalShapeChecks = $storage->putCalls;
    $expectCode(422, static fn () => $service->upload(
        $identity,
        new TestUploadRequest(null, $prepared['upload_id']),
        $prepared['upload_id'],
    ));
    $expectCode(422, static fn () => $service->upload(
        $identity,
        new TestUploadRequest(
            new UploadFile($temporary, 'avatar.webp', 'image/webp', UPLOAD_ERR_OK),
            $prepared['upload_id'],
            [],
            true,
        ),
        $prepared['upload_id'],
    ));
    $expectCode(422, static fn () => $service->upload(
        $identity,
        new TestUploadRequest(
            new UploadFile($temporary, 'wrong.webp', 'image/webp', UPLOAD_ERR_OK),
            $prepared['upload_id'],
        ),
        $prepared['upload_id'],
    ));
    $assert(
        $storage->putCalls === $putsBeforeTerminalShapeChecks,
        'terminal multipart validation performed a new object write',
    );
    $readyBeforeTerminalPrepare = $storage->readyCalls;
    $policyBeforeTerminalPrepare = $policy->calls;
    $storage->unavailable = true;
    $policy->unavailable = true;
    $terminalPrepare = $service->prepare(
        $identity,
        str_repeat('1', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $assert(
        $terminalPrepare['upload_id'] === $prepared['upload_id']
        && $storage->readyCalls === $readyBeforeTerminalPrepare
        && $policy->calls === $policyBeforeTerminalPrepare,
        'confirmed prepare retry consulted unavailable dependencies',
    );
    $storage->unavailable = false;
    $policy->unavailable = false;
    $assert(
        $service->release($identity, $prepared['upload_id'])
            === ['released' => false, 'state' => 'confirmed'],
        'confirmed upload was releasable',
    );

    $second = $service->prepare(
        $identity,
        str_repeat('2', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $blocked = new TestUploadRequest(new UploadFile(
        $temporary,
        'avatar.webp',
        'image/webp',
        UPLOAD_ERR_OK,
    ), $second['upload_id']);
    $policy->unavailable = true;
    $expectCode(503, static fn () => $service->upload($identity, $blocked, $second['upload_id']));
    $assert($blocked->fileCalls === 1, 'upload request shape was not validated before policy failure');
    $policy->unavailable = false;

    $emptyMime = $service->prepare(
        $identity,
        str_repeat('3', 32),
        'file',
        'data.txt',
        12,
        '',
    );
    $assert(
        $emptyMime['mime_type'] === 'application/octet-stream',
        'empty MIME was not canonicalized',
    );

    $confirmFailure = $service->prepare(
        $identity,
        str_repeat('4', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $reservations->failConfirm = true;
    try {
        $service->upload(
            $identity,
            new TestUploadRequest(new UploadFile(
                $temporary,
                'avatar.webp',
                'image/webp',
                UPLOAD_ERR_OK,
            ), $confirmFailure['upload_id']),
            $confirmFailure['upload_id'],
        );
        throw new RuntimeException('expected confirm failure');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'expected confirm failure') {
            throw $exception;
        }
    }
    $assert(
        $reservations->rows[$confirmFailure['upload_id']]['state'] === 'object_uploaded',
        'confirm failure destroyed the durable object_uploaded retry state',
    );
    $reservations->failConfirm = false;
    $storage->unavailable = true;
    $policy->unavailable = true;
    $confirmRetryRequest = new TestUploadRequest(new UploadFile(
        $temporary,
        'avatar.webp',
        'image/webp',
        UPLOAD_ERR_OK,
    ), $confirmFailure['upload_id']);
    $putsBeforeConfirmRetry = $storage->putCalls;
    $readyBeforeConfirmRetry = $storage->readyCalls;
    $confirmedAfterRetry = $service->upload(
        $identity,
        $confirmRetryRequest,
        $confirmFailure['upload_id'],
    );
    $assert(
        $confirmedAfterRetry['file_id'] !== ''
        && $confirmRetryRequest->fileCalls === 1
        && $storage->putCalls === $putsBeforeConfirmRetry
        && $storage->readyCalls === $readyBeforeConfirmRetry
        && $reservations->rows[$confirmFailure['upload_id']]['state'] === 'confirmed',
        'object_uploaded retry touched unavailable S3/policy or failed to confirm',
    );
    $storage->unavailable = false;
    $policy->unavailable = false;

    $activePrepare = $service->prepare(
        $identity,
        str_repeat('8', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $reservations->rows[$activePrepare['upload_id']]['state'] = 'uploading';
    $reservations->rows[$activePrepare['upload_id']]['expires_at'] = date('Y-m-d H:i:s', time() - 5);
    $reservations->rows[$activePrepare['upload_id']]['upload_lease_expires_at'] = date('Y-m-d H:i:s', time() + 60);
    $activeRetry = $service->prepare(
        $identity,
        str_repeat('8', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $assert(
        $activeRetry['upload_id'] === $activePrepare['upload_id']
        && $activeRetry['expires_at'] > time() + 800,
        'active uploading prepare retry did not refresh an expired response deadline',
    );

    $claimBlocked = $service->prepare(
        $identity,
        str_repeat('a', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $reservations->claimUnavailable = true;
    $readyBeforeClaimFailure = $storage->readyCalls;
    $putsBeforeClaimFailure = $storage->putCalls;
    $headsBeforeClaimFailure = $storage->inspectCalls;
    $deletesBeforeClaimFailure = count($storage->deletes);
    $claimBlockedRequest = new TestUploadRequest(
        new UploadFile($temporary, 'avatar.webp', 'image/webp', UPLOAD_ERR_OK),
        $claimBlocked['upload_id'],
    );
    $expectCode(503, static fn () => $service->upload(
        $identity,
        $claimBlockedRequest,
        $claimBlocked['upload_id'],
    ));
    $assert(
        $storage->readyCalls === $readyBeforeClaimFailure
        && $storage->putCalls === $putsBeforeClaimFailure
        && $storage->inspectCalls === $headsBeforeClaimFailure
        && count($storage->deletes) === $deletesBeforeClaimFailure
        && $claimBlockedRequest->fileCalls === 0,
        'claim authority failure reached multipart or object storage operations',
    );
    $reservations->claimUnavailable = false;

    $fencedUpload = $service->prepare(
        $identity,
        str_repeat('9', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $reservations->renewAllowed = false;
    $putsBeforeFence = $storage->putCalls;
    try {
        $service->upload(
            $identity,
            new TestUploadRequest(
                new UploadFile($temporary, 'avatar.webp', 'image/webp', UPLOAD_ERR_OK),
                $fencedUpload['upload_id'],
            ),
            $fencedUpload['upload_id'],
        );
        throw new RuntimeException('expected fenced upload failure');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'expected fenced upload failure') {
            throw $exception;
        }
    }
    $assert(
        $storage->putCalls === $putsBeforeFence + 1
        && $reservations->rows[$fencedUpload['upload_id']]['state'] === 'cleanup_pending',
        'late object write without a valid lease was not fenced into durable cleanup',
    );
    $reservations->renewAllowed = true;

    $extraPostUpload = $service->prepare(
        $identity,
        str_repeat('5', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $extraPostRequest = new TestUploadRequest(
        new UploadFile($temporary, 'avatar.webp', 'image/webp', UPLOAD_ERR_OK),
        $extraPostUpload['upload_id'],
        ['kind' => 'image'],
    );
    $expectCode(422, static fn () => $service->upload(
        $identity,
        $extraPostRequest,
        $extraPostUpload['upload_id'],
    ));
    $assert(
        $extraPostRequest->fileCalls === 0
        && $reservations->rows[$extraPostUpload['upload_id']]['state'] === 'reserved',
        'extra upload metadata was accepted or consumed a reservation',
    );

    $extraFileUpload = $service->prepare(
        $identity,
        str_repeat('6', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $extraFileRequest = new TestUploadRequest(
        new UploadFile($temporary, 'avatar.webp', 'image/webp', UPLOAD_ERR_OK),
        $extraFileUpload['upload_id'],
        [],
        true,
    );
    $expectCode(422, static fn () => $service->upload(
        $identity,
        $extraFileRequest,
        $extraFileUpload['upload_id'],
    ));
    $assert(
        $reservations->rows[$extraFileUpload['upload_id']]['state'] === 'released',
        'extra multipart file field was accepted',
    );

    $crashWindow = $service->prepare(
        $identity,
        str_repeat('7', 32),
        'image',
        'avatar.webp',
        12,
        'image/webp',
    );
    $reservations->rows[$crashWindow['upload_id']]['state'] = 'object_uploaded';
    $crashRetry = new TestUploadRequest(
        new UploadFile($temporary, 'avatar.webp', 'image/webp', UPLOAD_ERR_OK),
        $crashWindow['upload_id'],
    );
    $putsBeforeCrashRetry = $storage->putCalls;
    $recovered = $service->upload($identity, $crashRetry, $crashWindow['upload_id']);
    $assert(
        $recovered['file_id'] === $reservations->rows[$crashWindow['upload_id']]['file_id']
        && $crashRetry->fileCalls === 1
        && $storage->putCalls === $putsBeforeCrashRetry
        && $reservations->rows[$crashWindow['upload_id']]['state'] === 'confirmed',
        'object_uploaded crash window did not resume directly at confirm',
    );

    $reservations->cleanup = [[
        'id' => 99,
        'organization' => 901,
        'storage_path' => 'private/organizations/901/im/202607/' . str_repeat('c', 48) . '.webp',
        'cleanup_lease_token' => str_repeat('d', 64),
        'cleanup_claimed_version' => 7,
    ]];
    $cleanup = (new WebImUploadCleanupService($reservations, $storage))->run(10);
    $assert(
        $cleanup === [
            'scanned' => 1,
            'claimed' => 1,
            'released' => 1,
            'failed' => 0,
            'errors' => [],
        ]
        && count($storage->deletes) === 1,
        'durable cleanup did not delete exact object',
    );
    $reservations->cleanup = [[
        'id' => 102,
        'organization' => 902,
        'storage_path' => 'private/organizations/902/im/202607/' . str_repeat('3', 48) . '.webp',
        'cleanup_lease_token' => str_repeat('4', 64),
        'cleanup_claimed_version' => 13,
    ]];
    $reservations->cleanupClaimErrors = [[
        'reservation_id' => 98,
        'phase' => 'claim',
        'code' => 503,
        'message' => '上传孤儿清理候选权威事实不可用。',
    ]];
    $deletesBeforeMixedBatch = count($storage->deletes);
    $cleanup = (new WebImUploadCleanupService($reservations, $storage))->run(10);
    $assert(
        $cleanup['scanned'] === 2
        && $cleanup['claimed'] === 1
        && $cleanup['released'] === 1
        && $cleanup['failed'] === 1
        && $cleanup['errors'] === $reservations->cleanupClaimErrors
        && count($storage->deletes) === $deletesBeforeMixedBatch + 1,
        'claim authority error hid a later cleanup row or disappeared from the run result',
    );
    $reservations->cleanupClaimErrors = [];
    $reservations->cleanup = [[
        'id' => 100,
        'organization' => 901,
        'storage_path' => 'private/organizations/901/im/202607/' . str_repeat('f', 48) . '.webp',
        'cleanup_lease_token' => str_repeat('e', 64),
        'cleanup_claimed_version' => 9,
    ]];
    $reservations->cleanupCasHit = false;
    $cleanup = (new WebImUploadCleanupService($reservations, $storage))->run(10);
    $assert(
        $cleanup['claimed'] === 1
        && $cleanup['released'] === 0
        && $cleanup['failed'] === 1
        && ($cleanup['errors'][0]['phase'] ?? '') === 'complete',
        'stale cleanup worker reported a fenced CAS as released',
    );
    $reservations->cleanup = [[
        'id' => 101,
        'organization' => 901,
        'storage_path' => 'private/organizations/901/im/202607/' . str_repeat('1', 48) . '.webp',
        'cleanup_lease_token' => str_repeat('2', 64),
        'cleanup_claimed_version' => 11,
    ]];
    $reservations->cleanupCasHit = true;
    $reservations->cleanupDeleteAuthorized = false;
    $deletesBeforeRejectedFence = count($storage->deletes);
    $cleanup = (new WebImUploadCleanupService($reservations, $storage))->run(10);
    $assert(
        $cleanup['claimed'] === 1
        && $cleanup['released'] === 0
        && $cleanup['failed'] === 1
        && ($cleanup['errors'][0]['phase'] ?? '') === 'authorize'
        && count($storage->deletes) === $deletesBeforeRejectedFence,
        'cleanup deleted after its asset/lease/confirmed pre-delete fence failed',
    );

    $config = [
        'upload_mode' => '5', 's3_acl' => 'private', 's3_key' => 'key',
        's3_secret' => 'secret', 's3_bucket' => 'bucket', 's3_dirname' => 'private',
        's3_region' => 'auto', 's3_version' => 'latest',
        's3_use_path_style_endpoint' => '1', 's3_endpoint' => 'https://s3.test',
    ];
    $client = new TestS3Client();
    $s3 = new S3WebImUploadStorage(
        new WebImPrivateS3Config(static fn (): array => $config),
        static fn (array $options): TestS3Client => $client,
    );
    $path = $s3->reservePath(901, 'webp', str_repeat('e', 48));
    $s3->uploadExact(901, new SplFileInfo($temporary), $path, 'image/webp');
    $assert(
        $s3->inspect(901, $path)['size_byte'] === 12
        && $client->puts[0]['Key'] === $path,
        'S3 exact path or HeadObject contract failed',
    );
} finally {
    @unlink($temporary);
}

echo sprintf("WebImUploadServiceTest: %d assertions passed\n", $assertions);
