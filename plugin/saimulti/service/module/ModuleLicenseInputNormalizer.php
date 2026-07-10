<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use DateTimeImmutable;
use plugin\saimulti\exception\ApiException;

final class ModuleLicenseInputNormalizer
{
    public static function remark(?string $remark): ?string
    {
        if ($remark === null) {
            return null;
        }

        $remark = trim($remark);
        if ($remark === '') {
            return null;
        }
        if (mb_strlen($remark) > 255) {
            throw new ApiException('remark 最长 255 个字符。', 422);
        }

        return $remark;
    }

    public static function futureExpiry(?string $expireAt, ?int $now = null): ?string
    {
        if ($expireAt === null || trim($expireAt) === '') {
            return null;
        }

        $expireAt = trim($expireAt);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expireAt);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $expireAt) {
            throw new ApiException('expire_at 必须严格使用 Y-m-d H:i:s 格式。', 422);
        }
        if ($date->getTimestamp() <= ($now ?? time())) {
            throw new ApiException('expire_at 必须晚于当前时间。', 422);
        }

        return $expireAt;
    }
}
