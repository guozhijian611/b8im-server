<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use plugin\saimulti\exception\ApiException;
use SplFileInfo;
use support\Request;

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

    private WebImUploadAssetStoreInterface $assets;

    public function __construct(
        ?WebImUploadStorageInterface $storage = null,
        ?WebImUploadAssetStoreInterface $assets = null,
    ) {
        $this->storage = $storage ?? new S3WebImUploadStorage();
        $this->assets = $assets ?? new ThinkOrmWebImUploadAssetStore();
    }

    /**
     * Web IM uses an authenticated proxy endpoint so no object-store URL or
     * credential is disclosed during preparation.
     *
     * @param array<string, mixed> $identity
     * @return array{mode: string, upload_path: string, method: string, filename: string, size: int, mime_type: string, extension: string}
     */
    public function prepare(
        array $identity,
        string $kind,
        string $filename,
        int $size,
        string $mimeType,
    ): array {
        $this->identity($identity);
        $this->storage->assertReady();
        $kind = $this->kind($kind);
        $filename = $this->filename($filename);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $this->assertFile($kind, $extension, $size);
        $mimeType = $this->mimeType($mimeType);

        return [
            'mode' => 'proxy',
            'upload_path' => '/saimulti/web/im/upload',
            'method' => 'POST',
            'filename' => $filename,
            'size' => $size,
            'mime_type' => $mimeType,
            'extension' => $extension,
        ];
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    public function upload(array $identity, Request $request, string $kind): array
    {
        $this->identity($identity);
        $organization = (int) $identity['organization'];
        $userId = trim((string) $identity['user_id']);
        $kind = $this->kind($kind);

        // Configuration must fail closed before Request::file() parses or any
        // temporary upload content is inspected.
        $this->storage->assertReady();
        $files = $request->file();
        if (!$files) {
            throw new ApiException('请选择上传文件。', 422);
        }
        $file = current($files);
        if (!$file instanceof SplFileInfo
            || (method_exists($file, 'isValid') && !$file->isValid())) {
            throw new ApiException('上传文件无效。', 422);
        }
        $name = $this->filename(method_exists($file, 'getUploadName')
            ? (string) $file->getUploadName()
            : $file->getFilename());
        $extension = strtolower((string) (
            (method_exists($file, 'getUploadExtension') ? $file->getUploadExtension() : '')
            ?: pathinfo($name, PATHINFO_EXTENSION)
        ));
        $size = (int) $file->getSize();
        $this->assertFile($kind, $extension, $size);
        $mimeType = $this->mimeType(method_exists($file, 'getUploadMimeType')
            ? (string) $file->getUploadMimeType()
            : '');

        $stored = $this->storage->upload(
            $organization,
            $file,
            $extension,
            $mimeType,
        );
        $storagePath = (string) ($stored['storage_path'] ?? '');
        $storedSize = (int) ($stored['size_byte'] ?? 0);
        if ($storagePath === '' || $storedSize !== $size) {
            $this->rollbackObject($organization, $storagePath, new \RuntimeException(
                'Web IM S3 upload result does not match the source file.',
            ));
        }

        $fileId = sha1($organization . ':' . $userId . ':' . bin2hex(random_bytes(32)));
        $now = date('Y-m-d H:i:s');
        try {
            $this->assets->create([
                'organization' => $organization,
                'file_id' => $fileId,
                'user_id' => $userId,
                'kind' => $kind,
                'name' => $name,
                // Private object access is always resolved and signed later.
                'url' => '',
                'storage_path' => $storagePath,
                'size_byte' => $size,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'status' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } catch (\Throwable $exception) {
            $this->rollbackObject($organization, $storagePath, $exception);
        }

        return [
            'file_id' => $fileId,
            'kind' => $kind,
            'name' => $name,
            'size' => $size,
            'mime_type' => $mimeType,
            'extension' => $extension,
        ];
    }

    /** @param array<string, mixed> $identity */
    public function confirm(array $identity): never
    {
        $this->identity($identity);

        throw new ApiException('当前存储服务未提供 Web IM 直传预签名和服务端对象校验。', 409);
    }

    /** @param array<string, mixed> $identity */
    private function identity(array $identity): void
    {
        if ((int) ($identity['organization'] ?? 0) <= 0
            || trim((string) ($identity['user_id'] ?? '')) === '') {
            throw new ApiException('Web 登录上下文无效。', 401);
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
            || mb_strlen($filename) > 255
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

        return $mimeType;
    }

    private function assertFile(string $kind, string $extension, int $size): void
    {
        if ($extension === '' || !in_array($extension, self::EXTENSIONS[$kind], true)) {
            throw new ApiException('不支持该文件格式。', 422);
        }
        if ($size <= 0 || $size > 50 * 1024 * 1024) {
            throw new ApiException('文件大小必须在 50MB 以内。', 422);
        }
    }

    private function rollbackObject(int $organization, string $storagePath, \Throwable $failure): never
    {
        if ($storagePath === '') {
            throw $failure;
        }
        $cleanupFailure = null;
        $cleaned = false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $this->storage->delete($organization, $storagePath);
                $cleaned = true;
                break;
            } catch (\Throwable $exception) {
                $cleanupFailure = $exception;
            }
        }
        if ($cleaned) {
            throw $failure;
        }

        throw new \RuntimeException(
            'Web IM upload failed and private object cleanup did not complete.',
            0,
            $cleanupFailure ?? $failure,
        );
    }
}
