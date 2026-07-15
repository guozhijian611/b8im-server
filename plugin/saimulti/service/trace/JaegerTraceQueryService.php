<?php
// +----------------------------------------------------------------------
// | b8im [ 即时通讯系统 ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace plugin\saimulti\service\trace;

use JsonException;
use plugin\saimulti\exception\ApiException;

final class JaegerTraceQueryService
{
    public function __construct(private readonly JaegerHttpClientInterface $client = new GuzzleJaegerHttpClient())
    {
    }

    /** @return array{items: list<array{name: string}>, total: int} */
    public function services(): array
    {
        $payload = $this->client->get('/api/services');
        $services = $payload['data'] ?? null;
        if (!is_array($services)) {
            throw new ApiException('Jaeger 服务列表响应格式无效。', 502);
        }

        $names = [];
        foreach ($services as $service) {
            if (is_string($service) && trim($service) !== '') {
                $names[] = trim($service);
            }
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $items = array_map(static fn (string $name): array => ['name' => $name], $names);

        return ['items' => $items, 'total' => count($items)];
    }

    /** @param array<string, mixed> $input */
    public function search(array $input): array
    {
        $filters = $this->filters($input);
        if ($filters['trace_id'] !== null) {
            try {
                $payload = $this->client->get('/api/traces/' . $filters['trace_id']);
            } catch (JaegerTraceNotFoundException) {
                throw new ApiException('未查询到该链路。', 404);
            }
            $traces = $this->traces($payload);
            if ($traces === []) {
                throw new ApiException('未查询到该链路。', 404);
            }
            $items = [$this->summary($traces[0])];
            return ['items' => $items, 'total' => 1, 'limit' => $filters['limit']];
        }

        $query = [
            'service' => $filters['service'],
            'start' => $filters['start_time'] * 1000,
            'end' => $filters['end_time'] * 1000,
            'limit' => $filters['limit'],
        ];
        if ($filters['operation'] !== null) {
            $query['operation'] = $filters['operation'];
        }
        if ($filters['min_duration_ms'] !== null) {
            $query['minDuration'] = $filters['min_duration_ms'] . 'ms';
        }

        $tags = [];
        if ($filters['organization'] !== null) {
            $tags['b8im.organization'] = $filters['organization'];
        }
        if ($filters['message_id'] !== null) {
            $tags['b8im.message_id'] = $filters['message_id'];
        }
        if ($filters['error_only']) {
            $tags['error'] = 'true';
        }
        if ($tags !== []) {
            try {
                $query['tags'] = json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (JsonException) {
                throw new ApiException('链路查询标签无效。', 422);
            }
        }

        $items = array_map(fn (array $trace): array => $this->summary($trace), $this->traces(
            $this->client->get('/api/traces', $query),
        ));

        return ['items' => $items, 'total' => count($items), 'limit' => $filters['limit']];
    }

    public function read(mixed $traceId): array
    {
        $traceId = $this->traceId($traceId, true);
        try {
            $payload = $this->client->get('/api/traces/' . $traceId);
        } catch (JaegerTraceNotFoundException) {
            throw new ApiException('未查询到该链路。', 404);
        }
        $traces = $this->traces($payload);
        if ($traces === []) {
            throw new ApiException('未查询到该链路。', 404);
        }

        $trace = $traces[0];
        $result = $this->summary($trace);
        $processes = is_array($trace['processes'] ?? null) ? $trace['processes'] : [];
        $spans = [];
        foreach ($trace['spans'] as $span) {
            if (!is_array($span)) {
                continue;
            }
            $tags = $this->tagMap($span['tags'] ?? []);
            $process = is_array($processes[$span['processID'] ?? ''] ?? null)
                ? $processes[$span['processID']]
                : [];
            $parentSpanId = $this->parentSpanId($span['references'] ?? []);
            $logs = [];
            foreach (($span['logs'] ?? []) as $log) {
                if (!is_array($log)) {
                    continue;
                }
                $logs[] = [
                    'time_ms' => $this->microsecondsToMilliseconds($log['timestamp'] ?? 0),
                    'fields' => $this->tagMap($log['fields'] ?? []),
                ];
            }
            $error = $this->isError($tags, $logs);
            $spans[] = [
                'span_id' => (string) ($span['spanID'] ?? ''),
                'parent_span_id' => $parentSpanId,
                'service' => (string) ($process['serviceName'] ?? '未知服务'),
                'operation' => (string) ($span['operationName'] ?? '未知操作'),
                'start_time_ms' => $this->microsecondsToMilliseconds($span['startTime'] ?? 0),
                'duration_ms' => $this->microsecondsToMilliseconds($span['duration'] ?? 0),
                'error' => $error,
                'status' => $this->status($tags, $error),
                'tags' => $tags,
                'logs' => $logs,
            ];
        }
        usort($spans, static fn (array $left, array $right): int => $left['start_time_ms'] <=> $right['start_time_ms']);
        $result['spans'] = $spans;

        return $result;
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function traces(array $payload): array
    {
        $traces = $payload['data'] ?? null;
        if (!is_array($traces)) {
            throw new ApiException('Jaeger 链路响应格式无效。', 502);
        }
        $result = [];
        foreach ($traces as $trace) {
            if (!is_array($trace) || !is_array($trace['spans'] ?? null)) {
                throw new ApiException('Jaeger 链路响应格式无效。', 502);
            }
            $result[] = $trace;
        }
        return $result;
    }

    /** @param array<string, mixed> $trace */
    private function summary(array $trace): array
    {
        $processes = is_array($trace['processes'] ?? null) ? $trace['processes'] : [];
        $spanRows = [];
        $services = [];
        $organization = null;
        $messageId = null;
        $errorCount = 0;
        foreach ($trace['spans'] as $span) {
            if (!is_array($span)) {
                continue;
            }
            $tags = $this->tagMap($span['tags'] ?? []);
            $process = is_array($processes[$span['processID'] ?? ''] ?? null)
                ? $processes[$span['processID']]
                : [];
            $service = (string) ($process['serviceName'] ?? '未知服务');
            $services[] = $service;
            $start = $this->microsecondsToMilliseconds($span['startTime'] ?? 0);
            $duration = $this->microsecondsToMilliseconds($span['duration'] ?? 0);
            $parent = $this->parentSpanId($span['references'] ?? []);
            $spanRows[] = compact('span', 'service', 'start', 'duration', 'parent');
            if ($this->isError($tags, [])) {
                ++$errorCount;
            }
            $organization ??= $this->firstTag($tags, ['b8im.organization', 'organization']);
            $messageId ??= $this->firstTag($tags, ['b8im.message_id', 'message_id', 'message.id']);
        }

        if ($spanRows === []) {
            throw new ApiException('Jaeger 链路不包含有效 Span。', 502);
        }
        usort($spanRows, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $roots = array_values(array_filter($spanRows, static fn (array $row): bool => $row['parent'] === null));
        $root = $roots[0] ?? $spanRows[0];
        $startTime = min(array_column($spanRows, 'start'));
        $endTime = max(array_map(static fn (array $row): float => $row['start'] + $row['duration'], $spanRows));
        $services = array_values(array_unique(array_filter(
            $services,
            static fn (string $name): bool => $name !== '',
        )));
        sort($services, SORT_NATURAL | SORT_FLAG_CASE);
        $traceId = (string) ($trace['traceID'] ?? $root['span']['traceID'] ?? '');
        if ($traceId === '') {
            throw new ApiException('Jaeger 链路缺少 Trace ID。', 502);
        }

        return [
            'trace_id' => $traceId,
            'root_service' => $root['service'] !== '' ? $root['service'] : '未知服务',
            'root_operation' => (string) ($root['span']['operationName'] ?? '未知操作'),
            'start_time_ms' => $startTime,
            'end_time_ms' => $endTime,
            'duration_ms' => max(0, $endTime - $startTime),
            'span_count' => count($spanRows),
            'error_count' => $errorCount,
            'services' => $services,
            'organization' => $organization,
            'message_id' => $messageId,
        ];
    }

    /** @param mixed $references */
    private function parentSpanId(mixed $references): ?string
    {
        if (!is_array($references)) {
            return null;
        }
        $fallback = null;
        foreach ($references as $reference) {
            if (!is_array($reference) || !is_string($reference['spanID'] ?? null) || $reference['spanID'] === '') {
                continue;
            }
            if (($reference['refType'] ?? '') === 'CHILD_OF') {
                return $reference['spanID'];
            }
            $fallback ??= $reference['spanID'];
        }
        return $fallback;
    }

    /** @param array<string, mixed> $input */
    private function filters(array $input): array
    {
        $traceId = $this->traceId($input['trace_id'] ?? null, false);
        $service = $this->text($input['service'] ?? null, '服务名称', 200, false);
        if ($traceId === null && $service === null) {
            throw new ApiException('服务名称必须填写。', 422);
        }
        $operation = $this->text($input['operation'] ?? null, '操作名称', 300, false);
        if ($operation !== null && $service === null && $traceId === null) {
            throw new ApiException('按操作查询时必须指定服务。', 422);
        }
        $now = (int) floor(microtime(true) * 1000);
        $end = $this->integer($input['end_time'] ?? null, '结束时间', 1, PHP_INT_MAX, $now);
        $start = $this->integer($input['start_time'] ?? null, '开始时间', 1, PHP_INT_MAX, $end - 3600000);
        if ($start > $end) {
            throw new ApiException('开始时间不能晚于结束时间。', 422);
        }
        if ($end - $start > 7 * 86400000) {
            throw new ApiException('单次链路查询时间范围不能超过 7 天。', 422);
        }

        return [
            'trace_id' => $traceId,
            'service' => $service,
            'operation' => $operation,
            'organization' => $this->pattern($input['organization'] ?? null, '机构编号', '/^\d{1,20}$/'),
            'message_id' => $this->pattern($input['message_id'] ?? null, '消息编号', '/^[A-Za-z0-9._:-]{1,128}$/'),
            'min_duration_ms' => $this->integer($input['min_duration_ms'] ?? null, '最小耗时', 0, 86400000, null),
            'error_only' => $this->boolean($input['error_only'] ?? false, '仅看异常'),
            'start_time' => $start,
            'end_time' => $end,
            'limit' => $this->integer($input['limit'] ?? null, '返回数量', 1, 100, 20),
        ];
    }

    private function traceId(mixed $value, bool $required): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ApiException('Trace ID 必须填写。', 422);
            }
            return null;
        }
        if (!is_string($value) || preg_match('/^[0-9a-fA-F]{16,32}$/', $value) !== 1) {
            throw new ApiException('Trace ID 格式无效。', 422);
        }
        return strtolower($value);
    }

    private function text(mixed $value, string $label, int $maxLength, bool $required): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ApiException($label . '必须填写。', 422);
            }
            return null;
        }
        if (!is_string($value)) {
            throw new ApiException($label . '格式无效。', 422);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ApiException($label . '格式无效。', 422);
        }
        return $value;
    }

    private function pattern(mixed $value, string $label, string $pattern): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_string($value) && !is_int($value)) || preg_match($pattern, (string) $value) !== 1) {
            throw new ApiException($label . '格式无效。', 422);
        }
        return (string) $value;
    }

    private function integer(mixed $value, string $label, int $min, int $max, ?int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $parsed = (int) $value;
        } else {
            throw new ApiException($label . '格式无效。', 422);
        }
        if ($parsed < $min || $parsed > $max) {
            throw new ApiException($label . '超出允许范围。', 422);
        }
        return $parsed;
    }

    private function boolean(mixed $value, string $label): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }
        if (is_string($value) && in_array(strtolower($value), ['0', '1', 'false', 'true'], true)) {
            return in_array(strtolower($value), ['1', 'true'], true);
        }
        throw new ApiException($label . '格式无效。', 422);
    }

    /** @return array<string, mixed> */
    private function tagMap(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }
        $result = [];
        foreach ($tags as $tag) {
            if (!is_array($tag) || !is_string($tag['key'] ?? null)) {
                continue;
            }
            $key = $tag['key'];
            $result[$key] = TraceDataPolicy::isSensitiveKey($key) ? '[REDACTED]' : ($tag['value'] ?? null);
        }
        return $result;
    }

    private function firstTag(array $tags, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($tags[$key]) && (is_scalar($tags[$key]) || $tags[$key] === null)) {
                return $tags[$key] === null ? null : (string) $tags[$key];
            }
        }
        return null;
    }

    private function isError(array $tags, array $logs): bool
    {
        foreach (['error', 'otel.status_code', 'status.code'] as $key) {
            $value = strtolower((string) ($tags[$key] ?? ''));
            if (in_array($value, ['true', '1', 'error'], true)) {
                return true;
            }
        }
        $httpStatus = (int) ($tags['http.status_code'] ?? $tags['http.response.status_code'] ?? 0);
        if ($httpStatus >= 500) {
            return true;
        }
        foreach ($logs as $log) {
            $fields = is_array($log['fields'] ?? null) ? $log['fields'] : [];
            if (in_array(strtolower((string) ($fields['level'] ?? $fields['event'] ?? '')), ['error', 'exception'], true)) {
                return true;
            }
        }
        return false;
    }

    private function status(array $tags, bool $error): string
    {
        if ($error) {
            return 'error';
        }
        $value = strtolower((string) ($tags['otel.status_code'] ?? $tags['status.code'] ?? ''));
        return in_array($value, ['ok', 'unset'], true) ? $value : 'unset';
    }

    private function microsecondsToMilliseconds(mixed $value): float
    {
        return round(max(0.0, (float) $value) / 1000, 3);
    }
}
