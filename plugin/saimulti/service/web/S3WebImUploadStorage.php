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

    public function upload(
        int $organization,
        SplFileInfo $file,
        string $extension,
        string $mimeType,
    ): array {
        $config = $this->config->requirePrivate();
        $pathname = $file->getPathname();
        if ($pathname === '' || !$file->isFile() || !$file->isReadable()) {
            throw new \RuntimeException('Web IM uploaded temporary file is not readable.');
        }
        $size = $file->getSize();
        if (!is_int($size) || $size <= 0) {
            throw new \RuntimeException('Web IM uploaded temporary file size is invalid.');
        }
        $storagePath = $this->config->objectKey(
            $config,
            $organization,
            $extension,
            bin2hex(random_bytes(24)),
        );
        $client = $this->client($config['options']);
        $client->putObject([
            'Bucket' => $config['bucket'],
            'Key' => $storagePath,
            'SourceFile' => $pathname,
            'ContentLength' => $size,
            'ContentType' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'ACL' => 'private',
        ]);

        return ['storage_path' => $storagePath, 'size_byte' => $size];
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
            || !is_callable([$client, 'deleteObject'])) {
            throw new \RuntimeException('Web IM S3 client factory returned an invalid upload client.');
        }

        return $client;
    }
}
