<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Aws\S3\S3Client;
use Closure;
use SplFileInfo;

final class S3WebImUploadStorage implements WebImUploadStorageInterface
{
    private WebImPrivateS3Config $config;

    private Closure $clientFactory;

    public function __construct(
        ?WebImPrivateS3Config $config = null,
        ?Closure $clientFactory = null,
    ) {
        $this->config = $config ?? new WebImPrivateS3Config();
        $this->clientFactory = $clientFactory
            ?? static fn (array $options): S3Client => new S3Client($options);
    }

    public function assertReady(): void
    {
        $this->config->requirePrivate();
    }

    public function reservePath(int $organization, string $extension, string $objectId): string
    {
        $config = $this->config->requirePrivate();
        return $this->config->objectKey($config, $organization, $extension, $objectId);
    }

    public function uploadExact(
        int $organization,
        SplFileInfo $file,
        string $storagePath,
        string $mimeType,
        ?callable $heartbeat = null,
    ): void {
        $config = $this->config->requirePrivate();
        $storagePath = $this->config->assertObjectKey($config, $organization, $storagePath);
        $pathname = $file->getPathname();
        if ($pathname === '' || !$file->isFile() || !$file->isReadable()) {
            throw new \RuntimeException('Web IM uploaded temporary file is not readable.');
        }
        $size = $file->getSize();
        if (!is_int($size) || $size <= 0) {
            throw new \RuntimeException('Web IM uploaded temporary file size is invalid.');
        }
        $client = $this->client($config['options']);
        $lastHeartbeat = 0.0;
        $beat = static function () use ($heartbeat, &$lastHeartbeat): void {
            if ($heartbeat === null) {
                return;
            }
            $now = microtime(true);
            if ($lastHeartbeat === 0.0 || ($now - $lastHeartbeat) >= 15.0) {
                $heartbeat();
                $lastHeartbeat = $now;
            }
        };
        $beat();
        $client->putObject([
            'Bucket' => $config['bucket'],
            'Key' => $storagePath,
            'SourceFile' => $pathname,
            'ContentLength' => $size,
            'ContentType' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'ACL' => 'private',
            '@http' => [
                'progress' => static function (
                    int|float $downloadTotal,
                    int|float $downloadedBytes,
                    int|float $uploadTotal,
                    int|float $uploadedBytes,
                ) use ($beat): void {
                    $beat();
                },
            ],
        ]);
        if ($heartbeat !== null) {
            $heartbeat();
        }
    }

    public function inspect(int $organization, string $storagePath): array
    {
        $config = $this->config->requirePrivate();
        $storagePath = $this->config->assertObjectKey($config, $organization, $storagePath);
        $result = $this->client($config['options'])->headObject([
            'Bucket' => $config['bucket'],
            'Key' => $storagePath,
        ]);
        $size = is_array($result)
            ? ($result['ContentLength'] ?? null)
            : (is_object($result) && isset($result['ContentLength']) ? $result['ContentLength'] : null);
        $mime = is_array($result)
            ? ($result['ContentType'] ?? '')
            : (is_object($result) && isset($result['ContentType']) ? $result['ContentType'] : '');
        if (!is_numeric($size) || (int) $size <= 0) {
            throw new \RuntimeException('Web IM S3 HeadObject returned an invalid size.');
        }
        return [
            'storage_path' => $storagePath,
            'size_byte' => (int) $size,
            'mime_type' => trim((string) $mime),
        ];
    }

    public function delete(int $organization, string $storagePath): void
    {
        $config = $this->config->requirePrivate();
        $storagePath = $this->config->assertObjectKey($config, $organization, $storagePath);
        $this->client($config['options'])->deleteObject([
            'Bucket' => $config['bucket'],
            'Key' => $storagePath,
        ]);
    }

    /** @param array<string, mixed> $options */
    private function client(array $options): object
    {
        $client = ($this->clientFactory)($options);
        if (!is_object($client)
            || !is_callable([$client, 'putObject'])
            || !is_callable([$client, 'headObject'])
            || !is_callable([$client, 'deleteObject'])) {
            throw new \RuntimeException('Web IM S3 client factory returned an invalid upload client.');
        }

        return $client;
    }
}
