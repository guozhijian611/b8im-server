<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use SplFileInfo;
use support\Request;
use Throwable;

final class WebImUploadService
{
    private const KINDS = ['image', 'file', 'voice', 'video'];
    private const EXTENSIONS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'],
        'voice' => ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'webm', 'amr', 'flac'],
        'video' => ['mp4', 'm4v', 'mov', 'avi', 'mkv', 'wmv', 'webm', '3gp', 'mpeg', 'mpg'],
        'file' => [
            'txt', 'md', 'csv', 'json', 'xml', 'log', 'sql', 'rtf', 'pdf', 'epub',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'wps',
            'zip', 'rar', '7z', 'gz', 'tar', 'bz2', 'xz',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif',
            'mp3', 'm4a', 'aac', 'wav', 'ogg', 'webm', 'amr', 'flac',
            'mp4', 'm4v', 'mov', 'avi', 'mkv', 'wmv', '3gp', 'mpeg', 'mpg',
            'apk', 'ipa', 'dmg', 'exe', 'msi', 'pkg', 'deb', 'rpm',
        ],
    ];

    private WebImUploadStorageInterface $storage;
    private WebImUploadPolicyInterface $policy;
    private WebImUploadReservationServiceInterface $reservations;

    public function __construct(
        ?WebImUploadStorageInterface $storage = null,
        ?WebImUploadReservationServiceInterface $reservations = null,
        ?WebImUploadPolicyInterface $policy = null,
    ) {
        $this->storage = $storage ?? new S3WebImUploadStorage();
        $this->policy = $policy ?? new AuthoritativeWebImUploadPolicy();
        $this->reservations = $reservations
            ?? new ThinkOrmWebImUploadReservationService($this->policy);
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    public function prepare(
        array $identity,
        string $idempotencyKey,
        string $kind,
        string $filename,
        int $size,
        string $mimeType,
    ): array {
        $this->identity($identity);
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw new ApiException('idempotency_key 必须是 32 位小写十六进制。', 422);
        }
        $kind = $this->kind($kind);
        $filename = $this->filename($filename);
        if ($size <= 0) {
            throw new ApiException('文件大小无效。', 422);
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $this->assertExtension($kind, $extension);
        $mimeType = $this->mimeType($mimeType);

        $intentHash = hash('sha256', implode("\0", [
            (string) $identity['organization'],
            (string) $identity['user_id'],
            (string) $identity['client_family'],
            $kind,
            $filename,
            (string) $size,
            $mimeType,
            $extension,
        ]));
        $intent = [
            'organization' => (int) $identity['organization'],
            'idempotency_key' => $idempotencyKey,
            'intent_hash' => $intentHash,
            'user_id' => (string) $identity['user_id'],
            'client_family' => (string) $identity['client_family'],
        ];
        $existing = $this->reservations->findPrepare($intent);
        if (is_array($existing)
            && in_array((string) $existing['state'], ['confirmed', 'object_uploaded'], true)) {
            return $this->prepareResponse($identity, $existing);
        }

        $this->storage->assertReady();
        $this->policy->assertAllowed((int) $identity['organization'], $size);
        if (is_array($existing)) {
            return $this->prepareResponse(
                $identity,
                $this->reservations->refreshPrepare($intent),
            );
        }
        $uploadId = bin2hex(random_bytes(32));
        $fileId = sha1(bin2hex(random_bytes(32)));
        $storagePath = $this->storage->reservePath(
            (int) $identity['organization'],
            $extension,
            bin2hex(random_bytes(24)),
        );
        $now = date('Y-m-d H:i:s');
        $row = $this->reservations->prepare([
            'organization' => (int) $identity['organization'],
            'upload_id' => $uploadId,
            'idempotency_key' => $idempotencyKey,
            'intent_hash' => $intentHash,
            'file_id' => $fileId,
            'storage_path' => $storagePath,
            'user_id' => (string) $identity['user_id'],
            'client_family' => (string) $identity['client_family'],
            'kind' => $kind,
            'filename' => $filename,
            'size_bytes' => $size,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'state' => 'reserved',
            'expires_at' => date('Y-m-d H:i:s', time() + 900),
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->prepareResponse($identity, $row);
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    public function upload(array $identity, Request $request, string $uploadId): array
    {
        $this->identity($identity);
        $uploadId = $this->uploadId($uploadId);
        $this->assertUploadPost($request, $uploadId);
        $reservation = $this->reservations->claim($identity, $uploadId);
        try {
            $file = $this->requestFile($request);
            $this->assertExactFile($reservation, $file);
        } catch (Throwable $failure) {
            if ((string) $reservation['state'] === 'uploading') {
                $this->reservations->releaseBeforeObject(
                    (int) $reservation['id'],
                    (string) $reservation['upload_lease_token'],
                    'invalid_multipart',
                );
            }
            throw $failure;
        }
        if ((string) $reservation['state'] === 'confirmed') {
            return $this->metadata($reservation);
        }
        if ((string) $reservation['state'] === 'object_uploaded') {
            return $this->reservations->confirm($identity, $uploadId);
        }
        $reservationId = (int) $reservation['id'];
        $leaseToken = (string) $reservation['upload_lease_token'];

        try {
            $this->storage->assertReady();
            $this->policy->assertAllowed(
                (int) $identity['organization'],
                (int) $reservation['size_bytes'],
            );
        } catch (Throwable $failure) {
            $this->reservations->releaseBeforeObject(
                $reservationId,
                $leaseToken,
                'pre_object_failure',
            );
            throw $failure;
        }

        $heartbeat = function () use ($reservationId, $leaseToken): void {
            if (!$this->reservations->renewUploadLease($reservationId, $leaseToken)) {
                throw new \RuntimeException('Upload writer lease was fenced.');
            }
        };
        try {
            $this->storage->uploadExact(
                (int) $identity['organization'],
                $file,
                (string) $reservation['storage_path'],
                (string) $reservation['mime_type'],
                $heartbeat,
            );
            $heartbeat();
            $inspected = $this->storage->inspect(
                (int) $identity['organization'],
                (string) $reservation['storage_path'],
            );
            if ((string) ($inspected['storage_path'] ?? '') !== (string) $reservation['storage_path']
                || (int) ($inspected['size_byte'] ?? 0) !== (int) $reservation['size_bytes']
                || trim((string) ($inspected['mime_type'] ?? '')) !== (string) $reservation['mime_type']) {
                throw new \RuntimeException('Stored object metadata differs from the reservation.');
            }
            $heartbeat();
            $this->reservations->markObjectUploaded($reservationId, $leaseToken);
        } catch (Throwable $failure) {
            $this->reservations->registerObjectCleanup(
                $reservationId,
                $failure::class,
            );
            throw $failure;
        }

        // markObjectUploaded is the durable object commit point. A subsequent
        // database failure must preserve object_uploaded so a retry can confirm
        // without touching object storage again.
        return $this->reservations->confirm($identity, $uploadId);
    }

    /** @param array<string,mixed> $identity @return array{released:bool,state:string} */
    public function release(array $identity, string $uploadId): array
    {
        $this->identity($identity);
        return $this->reservations->release($identity, $this->uploadId($uploadId));
    }

    /** @param array<string,mixed> $identity */
    private function identity(array $identity): void
    {
        if ((int) ($identity['organization'] ?? 0) <= 0
            || trim((string) ($identity['user_id'] ?? '')) === ''
            || !in_array((string) ($identity['client_family'] ?? ''), ['web', 'app'], true)) {
            throw new ApiException('客户端登录上下文无效。', 401);
        }
    }

    /** @param array<string,mixed> $identity */
    private function uploadPath(array $identity): string
    {
        return (string) $identity['client_family'] === 'web'
            ? '/saimulti/web/im/upload'
            : '/saimulti/app/im/upload';
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $row */
    private function prepareResponse(array $identity, array $row): array
    {
        $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt <= time()) {
            throw new ApiException('上传预留有效期不可信。', 503);
        }

        return [
            'mode' => 'proxy',
            'upload_path' => $this->uploadPath($identity),
            'method' => 'POST',
            'upload_id' => (string) $row['upload_id'],
            'expires_at' => $expiresAt,
            'filename' => (string) $row['filename'],
            'size' => (int) $row['size_bytes'],
            'mime_type' => (string) $row['mime_type'],
            'extension' => (string) $row['extension'],
        ];
    }

    private function uploadId(string $uploadId): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new ApiException('upload_id 无效。', 422);
        }
        return $uploadId;
    }

    private function requestFile(Request $request): SplFileInfo
    {
        $files = $request->file();
        if (!$files) {
            throw new ApiException('请选择上传文件。', 422);
        }
        if (!is_array($files) || count($files) !== 1 || !array_key_exists('file', $files)) {
            throw new ApiException('上传请求必须且只能包含 file 文件字段。', 422);
        }
        $file = $files['file'];
        if (!$file instanceof SplFileInfo
            || (method_exists($file, 'isValid') && !$file->isValid())) {
            throw new ApiException('上传文件无效。', 422);
        }
        return $file;
    }

    private function assertUploadPost(Request $request, string $uploadId): void
    {
        $post = $request->post();
        if (!is_array($post) || count($post) !== 1
            || !array_key_exists('upload_id', $post)
            || $post['upload_id'] !== $uploadId) {
            throw new ApiException('请求体必须且只能包含匹配的 upload_id。', 422);
        }
    }

    /** @param array<string,mixed> $reservation */
    private function assertExactFile(array $reservation, SplFileInfo $file): void
    {
        $name = $this->filename(method_exists($file, 'getUploadName')
            ? (string) $file->getUploadName()
            : $file->getFilename());
        $extension = strtolower((string) (
            (method_exists($file, 'getUploadExtension') ? $file->getUploadExtension() : '')
            ?: pathinfo($name, PATHINFO_EXTENSION)
        ));
        $mime = $this->mimeType(method_exists($file, 'getUploadMimeType')
            ? (string) $file->getUploadMimeType()
            : '');
        if ($name !== (string) $reservation['filename']
            || $extension !== (string) $reservation['extension']
            || $mime !== (string) $reservation['mime_type']
            || (int) $file->getSize() !== (int) $reservation['size_bytes']) {
            throw new ApiException('上传文件与预留元数据不一致。', 422);
        }
    }

    private function kind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::KINDS, true)) {
            throw new ApiException('上传类型无效。', 422);
        }
        return $kind;
    }

    private function filename(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === ''
            || !mb_check_encoding($filename, 'UTF-8')
            || mb_strlen($filename, 'UTF-8') > 255
            || preg_match('/[\x00-\x1F\x7F]/u', $filename) === 1) {
            throw new ApiException('文件名无效。', 422);
        }
        return $filename;
    }

    private function mimeType(string $mimeType): string
    {
        $mimeType = trim($mimeType);
        if (strlen($mimeType) > 255 || preg_match('/[\x00-\x1F\x7F]/', $mimeType) === 1) {
            throw new ApiException('mime_type 无效。', 422);
        }
        return $mimeType === '' ? 'application/octet-stream' : $mimeType;
    }

    private function assertExtension(string $kind, string $extension): void
    {
        if ($extension === '' || !in_array($extension, self::EXTENSIONS[$kind], true)) {
            throw new ApiException('不支持该文件格式。', 422);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function metadata(array $row): array
    {
        return [
            'file_id' => (string) $row['file_id'],
            'kind' => (string) $row['kind'],
            'name' => (string) $row['filename'],
            'size' => (int) $row['size_bytes'],
            'mime_type' => (string) $row['mime_type'],
            'extension' => (string) $row['extension'],
        ];
    }
}
