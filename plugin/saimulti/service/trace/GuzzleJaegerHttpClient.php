<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\trace;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use plugin\saimulti\exception\ApiException;

final class GuzzleJaegerHttpClient implements JaegerHttpClientInterface
{
    private ClientInterface $client;

    public function __construct(?string $baseUrl = null, ?ClientInterface $client = null)
    {
        $baseUrl = rtrim($baseUrl ?? $this->environment('JAEGER_QUERY_URL', 'http://jaeger:16686'), '/');
        $parts = parse_url($baseUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new ApiException('Jaeger 查询服务地址配置无效。', 503);
        }

        $this->client = $client ?? new Client([
            'base_uri' => $baseUrl . '/',
            'connect_timeout' => 1.0,
            'timeout' => 3.0,
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    public function get(string $path, array $query = []): array
    {
        try {
            $response = $this->client->get(ltrim($path, '/'), ['query' => $query]);
        } catch (ConnectException $exception) {
            if ($this->isTimeout($exception)) {
                throw new ApiException('Jaeger 查询超时。', 504);
            }
            throw new ApiException('Jaeger 查询服务不可用。', 503);
        } catch (RequestException $exception) {
            if ($this->isTimeout($exception)) {
                throw new ApiException('Jaeger 查询超时。', 504);
            }
            throw new ApiException('Jaeger 查询服务不可用。', 503);
        } catch (GuzzleException) {
            throw new ApiException('Jaeger 查询服务不可用。', 503);
        }

        if ($response->getStatusCode() === 404 && str_starts_with($path, '/api/traces/')) {
            throw new JaegerTraceNotFoundException('Jaeger trace not found.');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new ApiException('Jaeger 查询服务返回异常。', 502);
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('Jaeger 查询响应格式无效。', 502);
        }

        if (!is_array($decoded)) {
            throw new ApiException('Jaeger 查询响应格式无效。', 502);
        }

        return $decoded;
    }

    private function isTimeout(ConnectException|RequestException $exception): bool
    {
        $context = $exception->getHandlerContext();
        return ($context['errno'] ?? null) === 28
            || str_contains(strtolower($exception->getMessage()), 'timed out');
    }

    private function environment(string $key, string $default): string
    {
        $value = getenv($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
