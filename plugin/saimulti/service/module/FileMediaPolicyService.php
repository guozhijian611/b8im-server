<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class FileMediaPolicyService
{
    private const TABLE = 'sm_file_media_quota';

    /** @return array<string,mixed> */
    public function ensureDefault(int $organization): array
    {
        if ($organization <= 0) {
            throw new ApiException('机构编号无效。', 422);
        }

        return Db::transaction(function () use ($organization): array {
            $organizationRow = Db::table('sm_system_organization')
                ->where('id', $organization)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if (!is_array($organizationRow)) {
                throw new ApiException('机构不存在。', 404);
            }
            $policy = Db::table(self::TABLE)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if (is_array($policy)) {
                return $policy;
            }

            $now = date('Y-m-d H:i:s');
            Db::table(self::TABLE)->insert([
                'organization' => $organization,
                'max_file_bytes' => 2147483648,
                'preview_enabled' => 1,
                'large_file_enabled' => 1,
                'status' => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $policy = Db::table(self::TABLE)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->find();
            if (!is_array($policy)) {
                throw new \RuntimeException('Default file-media policy insert was not observable.');
            }
            return $policy;
        });
    }

    /**
     * @param array<string,mixed> $row
     * @return array{max_file_bytes:string,preview_enabled:int,large_file_enabled:int,status:int}
     */
    public function project(array $row): array
    {
        $maximum = $this->dbInteger($row['max_file_bytes'] ?? null, 'max_file_bytes');
        if ($maximum < 1 || $maximum > 2147483648) {
            throw new ApiException('文件媒体权威单文件上限非法。', 503);
        }
        $projection = ['max_file_bytes' => (string) $maximum];
        foreach (['preview_enabled', 'large_file_enabled', 'status'] as $field) {
            $value = $this->dbInteger($row[$field] ?? null, $field);
            if ($value !== 0 && $value !== 1) {
                throw new ApiException("文件媒体权威 {$field} 非法。", 503);
            }
            $projection[$field] = $value;
        }

        return $projection;
    }

    private function dbInteger(mixed $value, string $field): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9]\d*)$/', $value) === 1) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($maximum)
                || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0)) {
                return (int) $value;
            }
        }
        throw new ApiException("文件媒体权威 {$field} 非法。", 503);
    }
}
