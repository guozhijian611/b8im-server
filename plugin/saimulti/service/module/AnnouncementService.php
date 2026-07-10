<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use DateTimeImmutable;
use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class AnnouncementService
{
    public const STATUS_DRAFT = 1;
    public const STATUS_PUBLISHED = 2;
    public const STATUS_OFFLINE = 3;

    private const DISPLAY_MODES = ['list', 'popup', 'both'];

    /**
     * @param array<string, mixed> $filters
     * @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int}
     */
    public function managementList(int $organization, array $filters): array
    {
        $this->assertOrganization($organization, true);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table('sm_announcement')
            ->where('organization', $organization)
            ->whereNull('delete_time');

        $title = trim((string) ($filters['title'] ?? ''));
        if ($title !== '') {
            $query->whereLike('title', '%' . addcslashes($title, '%_\\') . '%');
        }
        $displayMode = trim((string) ($filters['display_mode'] ?? ''));
        if ($displayMode !== '') {
            if (!in_array($displayMode, self::DISPLAY_MODES, true)) {
                throw new ApiException('展示方式无效。', 422);
            }
            $query->where('display_mode', $displayMode);
        }
        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            $status = $this->integer($status, '状态');
            $this->assertStatus($status);
            $query->where('status', $status);
        }

        $total = (int) (clone $query)->count();
        $items = $query
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return [
            'current_page' => $page,
            'data' => array_values($items),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function managementRead(int $organization, int $id): array
    {
        $this->assertOrganization($organization, true);

        return $this->row($organization, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $organization, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        if ($actorId <= 0) {
            throw new ApiException('操作人无效。', 401);
        }
        $data = self::normalizePayload($input);
        if ($data['status'] === self::STATUS_OFFLINE) {
            throw new ApiException('新公告不能直接设为已下线。', 422);
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table('sm_announcement')->insertGetId($data + [
            'organization' => $organization,
            'published_at' => $data['status'] === self::STATUS_PUBLISHED ? $now : null,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->row($organization, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $organization, int $id, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        if ($actorId <= 0) {
            throw new ApiException('操作人无效。', 401);
        }
        $existing = $this->row($organization, $id);
        $data = self::normalizePayload($input, $existing);
        $this->assertStatusTransition((int) $existing['status'], $data['status']);

        $now = date('Y-m-d H:i:s');
        $changes = $data + [
            'updated_by' => $actorId,
            'update_time' => $now,
        ];
        if ((int) $existing['status'] !== self::STATUS_PUBLISHED && $data['status'] === self::STATUS_PUBLISHED) {
            $changes['published_at'] = $now;
        }

        $affected = Db::table('sm_announcement')
            ->where('id', $id)
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->update($changes);
        if ($affected < 0) {
            throw new ApiException('公告更新失败。', 500);
        }

        return $this->row($organization, $id);
    }

    /** @param list<int> $ids */
    public function delete(int $organization, array $ids, int $actorId): int
    {
        $this->assertOrganization($organization, true);
        if ($actorId <= 0) {
            throw new ApiException('操作人无效。', 401);
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new ApiException('请选择要删除的公告。', 422);
        }

        $now = date('Y-m-d H:i:s');

        return (int) Db::table('sm_announcement')
            ->where('organization', $organization)
            ->whereIn('id', $ids)
            ->whereNull('delete_time')
            ->update([
                'updated_by' => $actorId,
                'update_time' => $now,
                'delete_time' => $now,
            ]);
    }

    /** @return array{list: list<array<string, mixed>>, total: int, config: array{display_mode: string, require_read_ack: bool}} */
    public function publishedList(int $organization, string $userId, int $page = 1, int $limit = 50): array
    {
        $this->assertOrganization($organization);
        $userId = $this->userId($userId);
        [$page, $limit] = $this->pagination(['page' => $page, 'limit' => $limit]);
        $query = $this->publishedQuery($organization);
        $total = (int) (clone $query)->count();
        $items = $query
            ->field(['id', 'title', 'summary', 'display_mode', 'published_at'])
            ->order('priority', 'desc')
            ->order('published_at', 'desc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $items = $this->withReadState($organization, $userId, array_values($items));

        return [
            'list' => $items,
            'total' => $total,
            'config' => $this->tenantConfig($organization),
        ];
    }

    /**
     * @return array<string, mixed>&{
     *     config: array{display_mode: string, require_read_ack: bool},
     *     is_read: bool,
     *     read_ack_required: bool
     * }
     */
    public function publishedRead(int $organization, string $userId, int $id): array
    {
        $this->assertOrganization($organization);
        $userId = $this->userId($userId);
        if ($id <= 0) {
            throw new ApiException('公告编号无效。', 422);
        }
        $row = $this->publishedQuery($organization)
            ->where('id', $id)
            ->field(['id', 'title', 'summary', 'content', 'display_mode', 'published_at'])
            ->find();
        if (!$row) {
            throw new ApiException('公告不存在或当前不可见。', 404);
        }

        $row = $this->withReadState($organization, $userId, [$row])[0];
        $config = $this->tenantConfig($organization);
        $row['config'] = $config;
        $row['read_ack_required'] = $config['require_read_ack'];

        return $row;
    }

    /** @return array{required: bool, recorded: bool, announcement_id: int, read_time: ?string} */
    public function acknowledge(int $organization, string $userId, int $id): array
    {
        $this->assertOrganization($organization);
        $userId = $this->userId($userId);
        if ($id <= 0) {
            throw new ApiException('公告编号无效。', 422);
        }
        if (!$this->publishedQuery($organization)->where('id', $id)->find()) {
            throw new ApiException('公告不存在或当前不可见。', 404);
        }
        if (!$this->tenantConfig($organization)['require_read_ack']) {
            return [
                'required' => false,
                'recorded' => false,
                'announcement_id' => $id,
                'read_time' => null,
            ];
        }

        $now = date('Y-m-d H:i:s');
        Db::execute(
            'INSERT INTO sm_announcement_read
                (organization, announcement_id, user_id, read_time, create_time, update_time)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE announcement_id = announcement_id',
            [$organization, $id, $userId, $now, $now, $now],
        );
        $record = Db::table('sm_announcement_read')
            ->where('organization', $organization)
            ->where('announcement_id', $id)
            ->where('user_id', $userId)
            ->find();
        if (!$record) {
            throw new ApiException('公告已读确认保存失败。', 500);
        }

        return [
            'required' => true,
            'recorded' => true,
            'announcement_id' => $id,
            'read_time' => (string) $record['read_time'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array{title: string, summary: string, content: string, display_mode: string, priority: int, status: int, start_time: ?string, end_time: ?string}
     */
    public static function normalizePayload(array $input, ?array $existing = null): array
    {
        $allowed = ['title', 'summary', 'content', 'display_mode', 'priority', 'status', 'start_time', 'end_time'];
        $input = array_intersect_key($input, array_flip($allowed));
        $merged = array_merge($existing ?? [], $input);

        $title = trim((string) ($merged['title'] ?? ''));
        $summary = trim((string) ($merged['summary'] ?? ''));
        $content = trim((string) ($merged['content'] ?? ''));
        $displayMode = trim((string) ($merged['display_mode'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw new ApiException('公告标题必须为 1 到 200 个字符。', 422);
        }
        if (mb_strlen($summary) > 500) {
            throw new ApiException('公告摘要不能超过 500 个字符。', 422);
        }
        if ($content === '') {
            throw new ApiException('公告正文不能为空。', 422);
        }
        if (!in_array($displayMode, self::DISPLAY_MODES, true)) {
            throw new ApiException('展示方式无效。', 422);
        }

        $priority = self::strictInteger($merged['priority'] ?? 0, '优先级');
        if ($priority < 0 || $priority > 1000000) {
            throw new ApiException('优先级必须在 0 到 1000000 之间。', 422);
        }
        $status = self::strictInteger($merged['status'] ?? self::STATUS_DRAFT, '状态');
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_OFFLINE], true)) {
            throw new ApiException('公告状态无效。', 422);
        }

        $startTime = self::nullableDateTime($merged['start_time'] ?? null, '生效时间');
        $endTime = self::nullableDateTime($merged['end_time'] ?? null, '结束时间');
        if ($startTime !== null && $endTime !== null && $endTime <= $startTime) {
            throw new ApiException('结束时间必须晚于生效时间。', 422);
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'content' => $content,
            'display_mode' => $displayMode,
            'priority' => $priority,
            'status' => $status,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
    }

    private function publishedQuery(int $organization)
    {
        $now = date('Y-m-d H:i:s');

        return Db::table('sm_announcement')
            ->whereIn('organization', [0, $organization])
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->whereNull('delete_time')
            ->whereRaw('(`start_time` IS NULL OR `start_time` <= ?)', [$now])
            ->whereRaw('(`end_time` IS NULL OR `end_time` > ?)', [$now]);
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function withReadState(int $organization, string $userId, array $items): array
    {
        $ids = array_values(array_map(static fn (array $item): int => (int) $item['id'], $items));
        if ($ids === []) {
            return [];
        }
        $readIds = Db::table('sm_announcement_read')
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->whereIn('announcement_id', $ids)
            ->column('announcement_id');
        $read = array_fill_keys(array_map('intval', $readIds), true);

        return array_values(array_map(static function (array $item) use ($read): array {
            $item['is_read'] = isset($read[(int) $item['id']]);
            return $item;
        }, $items));
    }

    /** @return array{display_mode: string, require_read_ack: bool} */
    private function tenantConfig(int $organization): array
    {
        $row = Db::table('sm_tenant_module_config')
            ->where('organization', $organization)
            ->where('module_key', 'announcement')
            ->whereNull('delete_time')
            ->find();
        if (!$row) {
            return ['display_mode' => 'list', 'require_read_ack' => false];
        }
        $config = json_decode((string) $row['config_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($config) || array_is_list($config)) {
            throw new ApiException('公告模块配置格式无效。', 500);
        }
        $displayMode = $config['display_mode'] ?? null;
        $requireReadAck = $config['require_read_ack'] ?? null;
        if (!is_string($displayMode)
            || !in_array($displayMode, self::DISPLAY_MODES, true)
            || !is_bool($requireReadAck)) {
            throw new ApiException('公告模块配置内容无效。', 500);
        }

        return ['display_mode' => $displayMode, 'require_read_ack' => $requireReadAck];
    }

    private function userId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '' || strlen($userId) > 64) {
            throw new ApiException('Web 用户身份无效。', 401);
        }

        return $userId;
    }

    /** @return array<string, mixed> */
    private function row(int $organization, int $id): array
    {
        if ($id <= 0) {
            throw new ApiException('公告编号无效。', 422);
        }
        $row = Db::table('sm_announcement')
            ->where('id', $id)
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->find();
        if (!$row) {
            throw new ApiException('公告不存在。', 404);
        }

        return $row;
    }

    private function assertStatusTransition(int $from, int $to): void
    {
        $allowed = match ($from) {
            self::STATUS_DRAFT => [self::STATUS_DRAFT, self::STATUS_PUBLISHED],
            self::STATUS_PUBLISHED => [self::STATUS_PUBLISHED, self::STATUS_OFFLINE],
            self::STATUS_OFFLINE => [self::STATUS_OFFLINE, self::STATUS_PUBLISHED],
            default => [],
        };
        if (!in_array($to, $allowed, true)) {
            throw new ApiException('公告状态转换无效。', 409);
        }
    }

    private function assertStatus(int $status): void
    {
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_OFFLINE], true)) {
            throw new ApiException('公告状态无效。', 422);
        }
    }

    /** @param array<string, mixed> $input @return array{int, int} */
    private function pagination(array $input): array
    {
        $page = $this->integer($input['page'] ?? 1, '页码');
        $limit = $this->integer($input['limit'] ?? 10, '每页数量');
        if ($page < 1 || $limit < 1 || $limit > 100) {
            throw new ApiException('分页参数无效。', 422);
        }

        return [$page, $limit];
    }

    private function integer(mixed $value, string $name): int
    {
        return self::strictInteger($value, $name);
    }

    private static function strictInteger(mixed $value, string $name): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || !preg_match('/^-?\d+$/', $value)) {
            throw new ApiException($name . '必须为整数。', 422);
        }

        return (int) $value;
    }

    private static function nullableDateTime(mixed $value, string $name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiException($name . '格式无效。', 422);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new ApiException($name . '格式无效。', 422);
        }

        return $value;
    }

    private function assertOrganization(int $organization, bool $allowPlatform = false): void
    {
        if ($organization < 0 || (!$allowPlatform && $organization === 0)) {
            throw new ApiException('机构上下文无效。', 401);
        }
    }
}
