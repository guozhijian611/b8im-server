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
}
