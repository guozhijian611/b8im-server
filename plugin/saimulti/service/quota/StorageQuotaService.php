<?php

declare(strict_types=1);

namespace plugin\saimulti\service\quota;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\utils\CanonicalInteger;
use support\think\Db;
use Throwable;

final class StorageQuotaService
{
    public function __construct(private readonly StorageQuotaAuthority $authority = new StorageQuotaAuthority())
    {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function index(array $filters): array
    {
        [$page, $limit] = $this->pagination($filters);
        $organizationFilter = null;
        if (array_key_exists('organization', $filters)
            && $filters['organization'] !== ''
            && $filters['organization'] !== null) {
            $organizationFilter = $this->strictOrganization($filters['organization']);
        }

        return $this->guardAuthority(fn (): array => Db::transaction(function () use (
            $page,
            $limit,
            $organizationFilter,
        ): array {
            $this->assertAuthorityCompleteness();
            $query = Db::table('sm_system_organization')
                ->where('status', 1)
                ->whereNull('delete_time');
            if ($organizationFilter !== null) {
                $query->where('id', $organizationFilter);
            }
            $total = (int) (clone $query)->count();
            if ($organizationFilter !== null && $total !== 1) {
                throw new ApiException('机构不存在或未启用。', 404);
            }
            $rows = $query
                ->field('id')
                ->order('id', 'asc')
                ->page($page, $limit)
                ->select()
                ->toArray();
            $data = [];
            foreach ($rows as $row) {
                $organization = $this->strictOrganization($row['id'] ?? null);
                $data[] = $this->authority->format(
                    $this->authority->lock($organization),
                );
            }

            return [
                'current_page' => $page,
                'data' => $data,
                'per_page' => $limit,
                'total' => $total,
            ];
        }));
    }

    /** @return array<string,mixed> */
    public function read(int $organization): array
    {
        return $this->guardAuthority(fn (): array => Db::transaction(function () use ($organization): array {
            $this->assertActiveOrganization($organization);

            return $this->authority->format($this->authority->lock($organization));
        }));
    }

    /** @return array<string,mixed> */
    public function update(
        int $organization,
        mixed $quotaValue,
        mixed $expectedVersion,
        int $actorId,
    ): array {
        if ($actorId <= 0) {
            throw new ApiException('操作人无效。', 422);
        }
        $maximum = $this->authority->requestUnsignedDecimal($quotaValue, 'quota_value');
        $version = $this->authority->requestPositiveInteger($expectedVersion, 'version');

        return $this->guardAuthority(fn (): array => Db::transaction(function () use (
            $organization,
            $maximum,
            $version,
            $actorId,
        ): array {
            $this->assertActiveOrganization($organization);
            $snapshot = $this->authority->lock($organization);
            $row = $snapshot['row'];
            if ((int) $row['version'] !== $version) {
                throw new ApiException('存储配额版本冲突，请刷新后重试。', 409);
            }
            if ($maximum > 0 && $maximum < (int) $snapshot['occupancy_value']) {
                throw new ApiException('存储配额不能低于当前物理与预留占用。', 422);
            }
            $updated = Db::table('sm_tenant_quota')
                ->where('id', (int) $row['id'])
                ->where('version', $version)
                ->update([
                    'quota_value' => $maximum,
                    'version' => $version + 1,
                    'updated_by' => $actorId,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ((int) $updated !== 1) {
                throw new ApiException('存储配额版本冲突，请刷新后重试。', 409);
            }

            return $this->authority->format($this->authority->lock($organization));
        }));
    }

    private function strictOrganization(mixed $value): int
    {
        return CanonicalInteger::positive($value, '机构编号');
    }

    private function assertActiveOrganization(int $organization): void
    {
        $this->strictOrganization($organization);
        $row = Db::table('sm_system_organization')
            ->field('id')
            ->where('id', $organization)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->find();
        if (!is_array($row)) {
            throw new ApiException('机构不存在或未启用。', 404);
        }
    }

    private function assertAuthorityCompleteness(): void
    {
        $missingOrDuplicate = Db::query(
            "SELECT o.id
               FROM sm_system_organization o
               LEFT JOIN sm_tenant_quota q
                 ON q.organization=o.id AND q.quota_key='storage_bytes'
              WHERE o.status=1 AND o.delete_time IS NULL
              GROUP BY o.id HAVING COUNT(q.id)<>1 LIMIT 1",
        );
        $duplicate = Db::query(
            "SELECT organization
               FROM sm_tenant_quota
              WHERE quota_key='storage_bytes'
              GROUP BY organization HAVING COUNT(*)<>1 LIMIT 1",
        );
        $orphan = Db::query(
            "SELECT q.id
               FROM sm_tenant_quota q
               LEFT JOIN sm_system_organization o ON o.id=q.organization
              WHERE q.quota_key='storage_bytes'
                AND (q.organization IS NULL OR o.id IS NULL)
              LIMIT 1",
        );
        if ($missingOrDuplicate !== [] || $duplicate !== [] || $orphan !== []) {
            throw new ApiException('机构存储配额权威事实不完整。', 503);
        }
    }

    /** @template T @param callable():T $callback @return T */
    private function guardAuthority(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Storage quota service unavailable [%s:%d]',
                $exception::class,
                $exception->getCode(),
            ));
            throw new ApiException('机构存储配额服务暂时不可用。', 503);
        }
    }

    /** @param array<string,mixed> $filters @return array{0:int,1:int} */
    private function pagination(array $filters): array
    {
        $page = $this->pageValue($filters['page'] ?? $filters['current_page'] ?? 1, 1);
        $limit = $this->pageValue($filters['limit'] ?? $filters['per_page'] ?? 20, 20);

        return [$page, min($limit, 100)];
    }

    private function pageValue(mixed $value, int $default): int
    {
        try {
            return CanonicalInteger::positive($value, '分页参数');
        } catch (ApiException) {
            return $default;
        }
    }
}
