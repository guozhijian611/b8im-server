<?php

declare(strict_types=1);

namespace plugin\saimulti\service;

final class RealtimeControlEventEnvelope
{
    /** @param array<string, mixed> $data */
    public static function encode(string $type, int $organization, array $data, bool $includeTime = false): string
    {
        $type = trim($type);
        if ($type === '' || $organization <= 0 || array_is_list($data)) {
            throw new \InvalidArgumentException('Realtime control event envelope is invalid.');
        }

        $identity = [
            'type' => $type,
            'organization' => $organization,
            'data' => $data,
        ];
        $canonical = json_encode(
            self::canonicalize($identity),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $envelope = [
            'event_id' => hash('sha256', $canonical),
            ...$identity,
        ];
        if ($includeTime) {
            $envelope['time'] = time();
        }

        return json_encode(
            $envelope,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
