<?php

declare(strict_types=1);

namespace plugin\saimulti\service\web;

use Closure;
use plugin\saimulti\app\logic\system\SystemConfigLogic;
use plugin\saimulti\exception\ApiException;

final class WebImPrivateS3Config
{
    private Closure $provider;

    public function __construct(?Closure $provider = null)
    {
        $this->provider = $provider
            ?? static fn (): array => (new SystemConfigLogic())->getGroup('upload_config');
    }

    /**
     * @return array{
     *   bucket: string,
     *   dirname: string,
     *   options: array<string, mixed>
     * }
     */
    public function requirePrivate(): array
    {
        $raw = ($this->provider)();
        if (!is_array($raw)) {
            throw new \RuntimeException('Web IM S3 configuration provider returned invalid data.');
        }
        if ((int) $this->value($raw, 'upload_mode') !== 5
            || strtolower(trim((string) $this->value($raw, 's3_acl'))) !== 'private') {
            throw new ApiException('Web IM 附件必须使用私有 S3 存储。', 503);
        }

        $accessKey = trim((string) $this->value($raw, 's3_key'));
        $secret = trim((string) $this->value($raw, 's3_secret'));
        $bucket = trim((string) $this->value($raw, 's3_bucket'));
        $region = trim((string) $this->value($raw, 's3_region'));
        $version = trim((string) $this->value($raw, 's3_version'));
        $dirname = trim((string) $this->value($raw, 's3_dirname'), '/');
        if ($accessKey === '' || $secret === '' || $bucket === '' || $region === '') {
            throw new ApiException('Web IM 私有 S3 配置不完整。', 503);
        }
        if (strlen($bucket) > 255 || preg_match('/[\x00-\x20\x7F]/', $bucket) === 1) {
            throw new ApiException('Web IM 私有 S3 bucket 配置无效。', 503);
        }
        if ($dirname !== '' && preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#', $dirname) !== 1) {
            throw new ApiException('S3 基础目录格式无效。', 503);
        }

        $options = [
            'version' => $version !== '' ? $version : 'latest',
            'region' => $region,
            'use_path_style_endpoint' => filter_var(
                $this->value($raw, 's3_use_path_style_endpoint'),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secret,
            ],
        ];
        $endpoint = rtrim(trim((string) $this->value($raw, 's3_endpoint')), '/');
        if ($endpoint !== '') {
            $this->assertHttpsEndpoint($endpoint);
            $options['endpoint'] = $endpoint;
        }

        return [
            'bucket' => $bucket,
            'dirname' => $dirname,
            'options' => $options,
        ];
    }

    /** @param array{dirname: string} $config */
    public function objectKey(array $config, int $organization, string $extension, string $hash): string
    {
        if ($organization <= 0
            || preg_match('/^[a-f0-9]{32,64}$/', $hash) !== 1
            || preg_match('/^[A-Za-z0-9]{1,32}$/', $extension) !== 1) {
            throw new \RuntimeException('Web IM S3 object identity is invalid.');
        }
        $prefix = $config['dirname'] !== '' ? $config['dirname'] . '/' : '';

        return $prefix . sprintf(
            'organizations/%d/im/%s/%s.%s',
            $organization,
            date('Ym'),
            $hash,
            strtolower($extension),
        );
    }

    /** @param array{dirname: string} $config */
    public function assertObjectKey(array $config, int $organization, string $storagePath): string
    {
        if ($organization <= 0
            || $storagePath === ''
            || strlen($storagePath) > 512
            || trim($storagePath, '/') !== $storagePath
            || str_contains($storagePath, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $storagePath) === 1) {
            throw new ApiException('附件存储路径无效。', 409);
        }
        $segments = explode('/', $storagePath);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new ApiException('附件存储路径无效。', 409);
        }
        $tenantRoot = ($config['dirname'] !== '' ? $config['dirname'] . '/' : '')
            . sprintf('organizations/%d/im/', $organization);
        if (!str_starts_with($storagePath, $tenantRoot)) {
            throw new ApiException('附件存储路径与当前机构不一致。', 409);
        }
        $suffix = substr($storagePath, strlen($tenantRoot));
        if (preg_match('#^[0-9]{6}/[a-f0-9]{32,64}\.[A-Za-z0-9]{1,32}$#', $suffix) !== 1) {
            throw new ApiException('附件存储路径不符合可信上传格式。', 409);
        }

        return $storagePath;
    }

    /** @param array<string|int, mixed> $config */
    private function value(array $config, string $key): mixed
    {
        if (array_key_exists($key, $config)) {
            return $config[$key];
        }
        foreach ($config as $item) {
            if (is_array($item) && ($item['key'] ?? null) === $key) {
                return $item['value'] ?? '';
            }
        }

        return '';
    }

    private function assertHttpsEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new ApiException('S3 endpoint 必须是 HTTPS 服务地址。', 503);
        }
    }
}
