<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Aws\S3\S3Client;
use Closure;
use DateTimeImmutable;

final class S3WebImAssetUrlSigner implements WebImAssetUrlSignerInterface
{
    private WebImPrivateS3Config $config;

    private Closure $clientFactory;

    public function __construct(?Closure $configProvider = null, ?Closure $clientFactory = null)
    {
        $this->config = new WebImPrivateS3Config($configProvider);
        $this->clientFactory = $clientFactory
            ?? static fn (array $options): S3Client => new S3Client($options);
    }

    public function sign(int $organization, string $storagePath, int $expiresAt): string
    {
        if ($organization <= 0 || $expiresAt <= time()) {
            throw new \RuntimeException('Web IM asset signing context is invalid.');
        }
        $config = $this->config->requirePrivate();
        $storagePath = $this->config->assertObjectKey($config, $organization, $storagePath);

        $client = ($this->clientFactory)($config['options']);
        if (!is_object($client)
            || !method_exists($client, 'getCommand')
            || !method_exists($client, 'createPresignedRequest')) {
            throw new \RuntimeException('Web IM S3 client factory returned an invalid client.');
        }
        $command = $client->getCommand('GetObject', [
            'Bucket' => $config['bucket'],
            'Key' => $storagePath,
        ]);
        $request = $client->createPresignedRequest(
            $command,
            new DateTimeImmutable('@' . $expiresAt),
        );
        $url = is_object($request) && method_exists($request, 'getUri')
            ? (string) $request->getUri()
            : '';
        $this->assertSignedUrl($url);

        return $url;
    }

    private function assertSignedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || trim((string) ($parts['query'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new \RuntimeException('S3 returned an invalid private asset URL.');
        }
    }
}
