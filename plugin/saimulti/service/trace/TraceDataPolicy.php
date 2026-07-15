<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\trace;

/** Shared defense-in-depth policy for both ingestion and query output. */
final class TraceDataPolicy
{
    public static function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/(?:
                (?:^|[._-])(?:authorization|cookie|set[._-]?cookie|password|passwd|pwd|secret|token|api[._-]?key)(?:$|[._-])
                |(?:^|[._-])(?:email|e[._-]?mail|phone|mobile)(?:$|[._-])
                |(?:request|response|message)[._-]?(?:body|content|payload)
                |(?:^|[._-])db[._-]?(?:statement|query|sql)(?:$|[._-])
                |(?:^|[._-])sql(?:$|[._-])
                |(?:^|[._-])signed[._-]?url(?:$|[._-])
                |(?:^|[._-])url[._-]?(?:query|full)(?:$|[._-])
                |(?:^|[._-])file[._-]?name(?:$|[._-])
                |(?:^|[._-])(?:exception|error)[._-]?(?:message|stacktrace|stack)(?:$|[._-])
            )/ix',
            $key,
        ) === 1;
    }

    public static function sanitizeDiagnosticText(string $value, int $maxLength = 2048): string
    {
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value));
        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer [REDACTED]',
            '/\b((?:access[_-]?|refresh[_-]?)?token|password|passwd|pwd|secret|authorization|cookie|api[_-]?key|private[_-]?key)\s*([:=])\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;&]+)/i' => '$1$2[REDACTED]',
            '/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\b/' => '[REDACTED_JWT]',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
    }
}
