<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class FavoriteService
{
    private const TABLE = 'sm_favorite';
    private const TYPES = ['message', 'file', 'link', 'text'];
    private const DEFAULT_MAX = 500;

    /**
     * Admin/tenant management list (org-scoped, optional user filter).
     *
     * @param array<string, mixed> $filters
     * @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int}
     */
    public function managementList(int $organization, array $filters, bool $allowPlatform = false): array
    {
        $this->assertOrganization($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::TABLE)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->integer($filters['organization'], '机构编号'));
        }

        $userId = trim((string) ($filters['user_id'] ?? ''));
        if ($userId !== '') {
            $query->where('user_id', $userId);
        }
        $type = trim((string) ($filters['target_type'] ?? ''));
        if ($type !== '') {
            $this->assertType($type);
            $query->where('target_type', $type);
        }
        $keyword = trim((string) ($filters['keyword'] ?? $filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->whereRaw('(title LIKE ? OR summary LIKE ? OR target_id LIKE ?)', [$like, $like, $like]);
        }

        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatRow'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function managementRead(int $organization, int $id, bool $allowPlatform = false): array
    {
        $this->assertOrganization($organization, $allowPlatform);
        $query = Db::table(self::TABLE)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('收藏不存在。', 404);
        }

        return $this->formatRow($row);
    }

    /** @param list<int> $ids */
    public function managementDelete(int $organization, array $ids, int $actorId, bool $allowPlatform = false): int
    {
        $this->assertOrganization($organization, $allowPlatform);
        if ($actorId <= 0) {
            throw new ApiException('操作人无效。', 422);
        }
        if ($ids === []) {
            throw new ApiException('编号列表无效。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $deleted = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $query = Db::table(self::TABLE)->where('id', $id)->whereNull('delete_time');
            if ($organization > 0) {
                $query->where('organization', $organization);
            }
            $row = $query->find();
            if ($row === null) {
                continue;
            }
            // free unique key on soft delete
            $suffix = '__del_' . $id;
            $targetId = substr((string) $row['target_id'], 0, max(0, 64 - strlen($suffix))) . $suffix;
            $n = Db::table(self::TABLE)
                ->where('id', $id)
                ->whereNull('delete_time')
                ->update([
                    'target_id' => $targetId,
                    'delete_time' => $now,
                    'updated_by' => $actorId,
                    'update_time' => $now,
                ]);
            $deleted += (int) $n;
        }

        return $deleted;
    }

    /**
     * User-owned list.
     *
     * @param array<string, mixed> $filters
     * @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int}
     */
    public function userList(int $organization, string $userId, array $filters): array
    {
        $this->assertOrganization($organization, false);
        $this->assertUserId($userId);
        $filters['user_id'] = $userId;

        return $this->managementList($organization, $filters, false);
    }

    /** @return array<string, mixed> */
    public function userRead(int $organization, string $userId, int $id): array
    {
        $this->assertOrganization($organization, false);
        $this->assertUserId($userId);
        $row = Db::table(self::TABLE)
            ->where('id', $id)
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($row === null) {
            throw new ApiException('收藏不存在。', 404);
        }

        return $this->formatRow($row);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function userCreate(int $organization, string $userId, array $input, int $maxFavorites = self::DEFAULT_MAX): array
    {
        $this->assertOrganization($organization, false);
        $this->assertUserId($userId);
        $type = $this->normalizeType((string) ($input['target_type'] ?? $input['type'] ?? ''));
        $targetId = $this->normalizeTargetId((string) ($input['target_id'] ?? ''), $type);
        $title = $this->normalizeTitle((string) ($input['title'] ?? ''));
        $summary = $this->normalizeSummary((string) ($input['summary'] ?? ''));
        $payload = $this->normalizePayload($input['payload'] ?? null);

        if ($maxFavorites > 0) {
            $count = (int) Db::table(self::TABLE)
                ->where('organization', $organization)
                ->where('user_id', $userId)
                ->whereNull('delete_time')
                ->count();
            if ($count >= $maxFavorites) {
                throw new ApiException('收藏数量已达上限。', 422);
            }
        }

        $exists = Db::table(self::TABLE)
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->where('target_type', $type)
            ->where('target_id', $targetId)
            ->whereNull('delete_time')
            ->find();
        if ($exists !== null) {
            // idempotent return
            return $this->formatRow($exists);
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::TABLE)->insertGetId([
            'organization' => $organization,
            'user_id' => $userId,
            'target_type' => $type,
            'target_id' => $targetId,
            'title' => $title,
            'summary' => $summary,
            'payload' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => null,
            'updated_by' => null,
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => null,
        ]);

        return $this->userRead($organization, $userId, $id);
    }

    /** @param list<int> $ids */
    public function userDelete(int $organization, string $userId, array $ids): int
    {
        $this->assertOrganization($organization, false);
        $this->assertUserId($userId);
        if ($ids === []) {
            throw new ApiException('编号列表无效。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $deleted = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $row = Db::table(self::TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->where('user_id', $userId)
                ->whereNull('delete_time')
                ->find();
            if ($row === null) {
                continue;
            }
            $suffix = '__del_' . $id;
            $targetId = substr((string) $row['target_id'], 0, max(0, 64 - strlen($suffix))) . $suffix;
            $n = Db::table(self::TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->where('user_id', $userId)
                ->whereNull('delete_time')
                ->update([
                    'target_id' => $targetId,
                    'delete_time' => $now,
                    'update_time' => $now,
                ]);
            $deleted += (int) $n;
        }

        return $deleted;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatRow(array $row): array
    {
        $payload = $row['payload'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        $row['payload'] = $payload;

        return $row;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $this->assertType($type);

        return $type;
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ApiException('收藏类型无效。', 422);
        }
    }

    private function normalizeTargetId(string $targetId, string $type): string
    {
        $targetId = trim($targetId);
        if ($type === 'text') {
            // text favorites may not have external id; generate stable empty-safe key from content hash later if empty
            if ($targetId === '') {
                $targetId = 'text:' . bin2hex(random_bytes(8));
            }
        } elseif ($targetId === '') {
            throw new ApiException('target_id 必填。', 422);
        }
        if (strlen($targetId) > 64) {
            throw new ApiException('target_id 过长。', 422);
        }

        return $targetId;
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new ApiException('标题无效。', 422);
        }

        return $title;
    }

    private function normalizeSummary(string $summary): string
    {
        $summary = trim($summary);
        if (mb_strlen($summary) > 500) {
            throw new ApiException('摘要过长。', 422);
        }

        return $summary;
    }

    /** @return array<string, mixed>|null */
    private function normalizePayload(mixed $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                throw new ApiException('payload 必须是对象。', 422);
            }
            $payload = $decoded;
        }
        if (!is_array($payload)) {
            throw new ApiException('payload 必须是对象。', 422);
        }

        return $payload;
    }

    private function assertOrganization(int $organization, bool $allowPlatform): void
    {
        if ($organization < 0) {
            throw new ApiException('机构编号无效。', 422);
        }
        if (!$allowPlatform && $organization <= 0) {
            throw new ApiException('机构编号无效。', 422);
        }
    }

    private function assertUserId(string $userId): void
    {
        $userId = trim($userId);
        if ($userId === '' || strlen($userId) > 64) {
            throw new ApiException('用户编号无效。', 422);
        }
    }

    private function integer(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        throw new ApiException($label . '无效。', 422);
    }

    /** @param array<string, mixed> $filters @return array{0: int, 1: int} */
    private function pagination(array $filters): array
    {
        $page = max(1, $this->integer($filters['page'] ?? 1, '页码'));
        $limit = max(1, min(100, $this->integer($filters['limit'] ?? 20, '每页数量')));

        return [$page, $limit];
    }
}
