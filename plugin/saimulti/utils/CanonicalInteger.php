<?php

declare(strict_types=1);

namespace plugin\saimulti\utils;

use plugin\saimulti\exception\ApiException;

final class CanonicalInteger
{
    public static function positive(mixed $value, string $label): int
    {
        $integer = self::parse($value, $label, false);
        if ($integer <= 0) {
            throw new ApiException($label . '必须是正规范正整数。', 422);
        }

        return $integer;
    }

    public static function nonNegative(mixed $value, string $label): int
    {
        return self::parse($value, $label, true);
    }

    private static function parse(mixed $value, string $label, bool $allowZero): int
    {
        if (is_int($value)) {
            if ($value >= ($allowZero ? 0 : 1)) {
                return $value;
            }
            throw new ApiException($label . '无效。', 422);
        }
        $pattern = $allowZero ? '/^(?:0|[1-9]\d*)$/' : '/^[1-9]\d*$/';
        if (!is_string($value)
            || preg_match($pattern, $value) !== 1
            || !self::fitsPhpInt($value)) {
            throw new ApiException($label . '无效。', 422);
        }

        return (int) $value;
    }

    private static function fitsPhpInt(string $value): bool
    {
        $maximum = (string) PHP_INT_MAX;

        return strlen($value) < strlen($maximum)
            || (strlen($value) === strlen($maximum)
                && strcmp($value, $maximum) <= 0);
    }
}
