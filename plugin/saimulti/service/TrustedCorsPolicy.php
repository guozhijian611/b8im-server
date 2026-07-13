<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

use InvalidArgumentException;

final class TrustedCorsPolicy
{
    public const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    public const ALLOWED_HEADERS = [
        'authorization',
        'content-type',
        'app-id',
        'x-device-id',
        'x-requested-with',
        'traceparent',
        'tracestate',
    ];

    /** @var array<string, true> */
    private array $origins = [];

    public function __construct(?string $configuredOrigins = null)
    {
        $configuredOrigins ??= (string) env('SAIMULTI_CORS_ALLOWED_ORIGINS', '');
        foreach (explode(',', $configuredOrigins) as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }
            $normalized = self::normalizeOrigin($candidate);
            if ($normalized === null) {
                throw new InvalidArgumentException('SAIMULTI_CORS_ALLOWED_ORIGINS contains an invalid origin.');
            }
            $this->origins[$normalized] = true;
        }
    }

    public function allowedOrigin(string $origin): ?string
    {
        $normalized = self::normalizeOrigin($origin);
        return $normalized !== null && isset($this->origins[$normalized]) ? $normalized : null;
    }

    public function allowsMethod(string $method): bool
    {
        return in_array(strtoupper(trim($method)), self::ALLOWED_METHODS, true);
    }

    public function allowsRequestedHeaders(string $headerLine): bool
    {
        if (trim($headerLine) === '') {
            return true;
        }
        foreach (explode(',', strtolower($headerLine)) as $header) {
            if (!in_array(trim($header), self::ALLOWED_HEADERS, true)) {
                return false;
            }
        }
        return true;
    }

    public static function normalizeOrigin(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '' || $origin === '*' || strlen($origin) > 255) {
            return null;
        }
        $parts = parse_url($origin);
        if (
            !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }
}
