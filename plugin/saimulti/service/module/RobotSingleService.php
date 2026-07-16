<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class RobotSingleService
{
    private const ROBOT = 'sm_robot_single';
    private const RULE = 'sm_robot_single_rule';
    private const KB = 'sm_robot_single_kb';
    private const MATCH_MODES = ['exact', 'contains', 'prefix'];
    private const CODE_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';
    private const DEFAULT_MAX_ROBOTS = 10;
    private const DEFAULT_MAX_RULES = 100;

    // ---------- robot ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function robotList(int $organization, array $filters, bool $allowPlatform = false): array
    {
        $this->assertOrg($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::ROBOT)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            $query->where('status', $this->boolStatus($status));
        }
        $keyword = trim((string) ($filters['keyword'] ?? $filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->whereRaw('(code LIKE ? OR name LIKE ? OR description LIKE ?)', [$like, $like, $like]);
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatRobot'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function robotRead(int $organization, int $id, bool $allowPlatform = false): array
    {
        return $this->formatRobot($this->robotRow($organization, $id, $allowPlatform));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function robotCreate(int $organization, array $input, int $actorId, int $maxRobots = self::DEFAULT_MAX_ROBOTS): array
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        if ($maxRobots > 0) {
            $count = (int) Db::table(self::ROBOT)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->count();
            if ($count >= $maxRobots) {
                throw new ApiException('机器人数量已达上限。', 422);
            }
        }
        $code = $this->normalizeCode((string) ($input['code'] ?? ''));
        if ($this->exists(self::ROBOT, $organization, 'code', $code)) {
            throw new ApiException('机器人编码已存在。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::ROBOT)->insertGetId([
            'organization' => $organization,
            'code' => $code,
            'name' => $this->normalizeName((string) ($input['name'] ?? '')),
            'avatar_url' => $this->limitStr((string) ($input['avatar_url'] ?? ''), 500),
            'welcome_text' => $this->limitStr((string) ($input['welcome_text'] ?? ''), 1000),
            'fallback_text' => $this->limitStr((string) ($input['fallback_text'] ?? ''), 1000),
            'description' => $this->limitStr((string) ($input['description'] ?? ''), 500),
            'status' => $this->boolStatus($input['status'] ?? 1),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->robotRead($organization, $id, false);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function robotUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $row = $this->robotRow($organization, $id, false);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) $input['code']);
            if ($code !== (string) $row['code'] && $this->exists(self::ROBOT, $organization, 'code', $code, $id)) {
                throw new ApiException('机器人编码已存在。', 422);
            }
            $data['code'] = $code;
        }
        if (array_key_exists('name', $input)) {
            $data['name'] = $this->normalizeName((string) $input['name']);
        }
        foreach (['avatar_url' => 500, 'welcome_text' => 1000, 'fallback_text' => 1000, 'description' => 500] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $this->limitStr((string) $input[$field], $max);
            }
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->boolStatus($input['status']);
        }
        Db::table(self::ROBOT)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->robotRead($organization, $id, false);
    }

    /** @param list<int> $ids */
    public function robotDelete(int $organization, array $ids, int $actorId): int
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            throw new ApiException('编号列表无效。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $deleted = 0;
        foreach ($ids as $id) {
            $row = Db::table(self::ROBOT)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->find();
            if ($row === null) {
                continue;
            }
            $suffix = '__del_' . $id;
            $code = substr((string) $row['code'], 0, max(0, 64 - strlen($suffix))) . $suffix;
            $n = Db::table(self::ROBOT)->where('id', $id)->whereNull('delete_time')->update([
                'code' => $code,
                'delete_time' => $now,
                'updated_by' => $actorId,
                'update_time' => $now,
            ]);
            if ($n > 0) {
                Db::table(self::RULE)->where('robot_id', $id)->whereNull('delete_time')->update([
                    'delete_time' => $now,
                    'updated_by' => $actorId,
                    'update_time' => $now,
                ]);
                Db::table(self::KB)->where('robot_id', $id)->whereNull('delete_time')->update([
                    'delete_time' => $now,
                    'updated_by' => $actorId,
                    'update_time' => $now,
                ]);
                $deleted += (int) $n;
            }
        }

        return $deleted;
    }

    // ---------- rule ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function ruleList(int $organization, array $filters, bool $allowPlatform = false): array
    {
        $this->assertOrg($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::RULE)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        if (isset($filters['robot_id']) && $filters['robot_id'] !== '' && $filters['robot_id'] !== null) {
            $query->where('robot_id', $this->intVal($filters['robot_id'], '机器人编号'));
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->whereRaw('(keyword LIKE ? OR reply_text LIKE ?)', [$like, $like]);
        }
        $total = (int) (clone $query)->count();
        $items = $query->order(['priority' => 'desc', 'id' => 'desc'])->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatRule'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function ruleCreate(int $organization, array $input, int $actorId, int $maxRules = self::DEFAULT_MAX_RULES): array
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $robotId = $this->intVal($input['robot_id'] ?? 0, '机器人编号');
        $this->robotRow($organization, $robotId, false);
        if ($maxRules > 0) {
            $count = (int) Db::table(self::RULE)
                ->where('organization', $organization)
                ->where('robot_id', $robotId)
                ->whereNull('delete_time')
                ->count();
            if ($count >= $maxRules) {
                throw new ApiException('自动回复规则数量已达上限。', 422);
            }
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::RULE)->insertGetId([
            'organization' => $organization,
            'robot_id' => $robotId,
            'keyword' => $this->normalizeKeyword((string) ($input['keyword'] ?? '')),
            'match_mode' => $this->normalizeMatchMode((string) ($input['match_mode'] ?? 'contains')),
            'reply_text' => $this->normalizeReply((string) ($input['reply_text'] ?? '')),
            'priority' => max(0, $this->intVal($input['priority'] ?? 0, '优先级')),
            'status' => $this->boolStatus($input['status'] ?? 1),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->formatRule($this->ruleRow($organization, $id, false));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function ruleUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $row = $this->ruleRow($organization, $id, false);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('keyword', $input)) {
            $data['keyword'] = $this->normalizeKeyword((string) $input['keyword']);
        }
        if (array_key_exists('match_mode', $input)) {
            $data['match_mode'] = $this->normalizeMatchMode((string) $input['match_mode']);
        }
        if (array_key_exists('reply_text', $input)) {
            $data['reply_text'] = $this->normalizeReply((string) $input['reply_text']);
        }
        if (array_key_exists('priority', $input)) {
            $data['priority'] = max(0, $this->intVal($input['priority'], '优先级'));
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->boolStatus($input['status']);
        }
        Db::table(self::RULE)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->formatRule($this->ruleRow($organization, $id, false));
    }

    /** @param list<int> $ids */
    public function ruleDelete(int $organization, array $ids, int $actorId): int
    {
        return $this->softDelete(self::RULE, $organization, $ids, $actorId);
    }

    // ---------- knowledge base ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function kbList(int $organization, array $filters, bool $allowPlatform = false): array
    {
        $this->assertOrg($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::KB)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        if (isset($filters['robot_id']) && $filters['robot_id'] !== '' && $filters['robot_id'] !== null) {
            $query->where('robot_id', $this->intVal($filters['robot_id'], '机器人编号'));
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->whereRaw('(title LIKE ? OR content LIKE ? OR tags LIKE ?)', [$like, $like, $like]);
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatKb'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function kbCreate(int $organization, array $input, int $actorId): array
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $robotId = $this->intVal($input['robot_id'] ?? 0, '机器人编号');
        $this->robotRow($organization, $robotId, false);
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::KB)->insertGetId([
            'organization' => $organization,
            'robot_id' => $robotId,
            'title' => $this->normalizeTitle((string) ($input['title'] ?? '')),
            'content' => $this->normalizeContent((string) ($input['content'] ?? '')),
            'tags' => $this->limitStr((string) ($input['tags'] ?? ''), 500),
            'status' => $this->boolStatus($input['status'] ?? 1),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->formatKb($this->kbRow($organization, $id, false));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function kbUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $this->kbRow($organization, $id, false);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('title', $input)) {
            $data['title'] = $this->normalizeTitle((string) $input['title']);
        }
        if (array_key_exists('content', $input)) {
            $data['content'] = $this->normalizeContent((string) $input['content']);
        }
        if (array_key_exists('tags', $input)) {
            $data['tags'] = $this->limitStr((string) $input['tags'], 500);
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->boolStatus($input['status']);
        }
        Db::table(self::KB)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->formatKb($this->kbRow($organization, $id, false));
    }

    /** @param list<int> $ids */
    public function kbDelete(int $organization, array $ids, int $actorId): int
    {
        return $this->softDelete(self::KB, $organization, $ids, $actorId);
    }

    // ---------- web / match ----------
    /**
     * Enabled robots for end users.
     *
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function userRobotList(int $organization, array $filters): array
    {
        $filters['status'] = 1;

        return $this->robotList($organization, $filters, false);
    }

    /**
     * Match inbound text against robot rules; fallback_text when no rule hits.
     *
     * @return array{matched:bool,reply_text:string,robot:array<string,mixed>,rule:?array<string,mixed>}
     */
    public function matchReply(int $organization, int $robotId, string $text): array
    {
        $robot = $this->robotRow($organization, $robotId, false);
        if ((int) $robot['status'] !== 1) {
            throw new ApiException('机器人已停用。', 403);
        }
        $text = trim($text);
        if ($text === '') {
            throw new ApiException('消息内容必填。', 422);
        }
        if (mb_strlen($text) > 2000) {
            throw new ApiException('消息内容过长。', 422);
        }
        $rules = Db::table(self::RULE)
            ->where('organization', $organization)
            ->where('robot_id', $robotId)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order(['priority' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();
        foreach ($rules as $rule) {
            if ($this->ruleMatches((string) $rule['match_mode'], (string) $rule['keyword'], $text)) {
                return [
                    'matched' => true,
                    'reply_text' => (string) $rule['reply_text'],
                    'robot' => $this->formatRobot($robot),
                    'rule' => $this->formatRule($rule),
                ];
            }
        }
        $fallback = trim((string) $robot['fallback_text']);
        if ($fallback === '') {
            $fallback = '暂未匹配到自动回复，请稍后再试或联系人工客服。';
        }

        return [
            'matched' => false,
            'reply_text' => $fallback,
            'robot' => $this->formatRobot($robot),
            'rule' => null,
        ];
    }

    // ---------- internals ----------
    private function ruleMatches(string $mode, string $keyword, string $text): bool
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return false;
        }
        $kw = mb_strtolower($keyword);
        $tx = mb_strtolower($text);

        return match ($mode) {
            'exact' => $tx === $kw,
            'prefix' => str_starts_with($tx, $kw),
            default => str_contains($tx, $kw),
        };
    }

    /** @return array<string, mixed> */
    private function robotRow(int $organization, int $id, bool $allowPlatform): array
    {
        $this->assertOrg($organization, $allowPlatform);
        if ($id <= 0) {
            throw new ApiException('机器人编号无效。', 422);
        }
        $query = Db::table(self::ROBOT)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('机器人不存在。', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function ruleRow(int $organization, int $id, bool $allowPlatform): array
    {
        $this->assertOrg($organization, $allowPlatform);
        if ($id <= 0) {
            throw new ApiException('规则编号无效。', 422);
        }
        $query = Db::table(self::RULE)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('规则不存在。', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function kbRow(int $organization, int $id, bool $allowPlatform): array
    {
        $this->assertOrg($organization, $allowPlatform);
        if ($id <= 0) {
            throw new ApiException('知识库编号无效。', 422);
        }
        $query = Db::table(self::KB)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('知识库条目不存在。', 404);
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatRobot(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'avatar_url' => (string) ($row['avatar_url'] ?? ''),
            'welcome_text' => (string) ($row['welcome_text'] ?? ''),
            'fallback_text' => (string) ($row['fallback_text'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'status' => (int) $row['status'],
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatRule(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'robot_id' => (int) $row['robot_id'],
            'keyword' => (string) $row['keyword'],
            'match_mode' => (string) $row['match_mode'],
            'reply_text' => (string) $row['reply_text'],
            'priority' => (int) $row['priority'],
            'status' => (int) $row['status'],
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatKb(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'robot_id' => (int) $row['robot_id'],
            'title' => (string) $row['title'],
            'content' => (string) $row['content'],
            'tags' => (string) ($row['tags'] ?? ''),
            'status' => (int) $row['status'],
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    /** @param list<int> $ids */
    private function softDelete(string $table, int $organization, array $ids, int $actorId): int
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            throw new ApiException('编号列表无效。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $deleted = 0;
        foreach ($ids as $id) {
            $n = Db::table($table)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->update([
                    'delete_time' => $now,
                    'updated_by' => $actorId,
                    'update_time' => $now,
                ]);
            $deleted += (int) $n;
        }

        return $deleted;
    }

    private function exists(string $table, int $organization, string $field, string $value, ?int $exceptId = null): bool
    {
        $query = Db::table($table)
            ->where('organization', $organization)
            ->where($field, $value)
            ->whereNull('delete_time');
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        return $query->find() !== null;
    }

    private function assertOrg(int $organization, bool $allowPlatform): void
    {
        if ($organization < 0 || (!$allowPlatform && $organization <= 0)) {
            throw new ApiException('机构编号无效。', 422);
        }
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0) {
            throw new ApiException('操作人无效。', 422);
        }
    }

    private function normalizeCode(string $code): string
    {
        $code = trim($code);
        if ($code === '' || !preg_match(self::CODE_PATTERN, $code)) {
            throw new ApiException('编码格式无效。', 422);
        }

        return $code;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ApiException('名称无效。', 422);
        }

        return $name;
    }

    private function normalizeKeyword(string $keyword): string
    {
        $keyword = trim($keyword);
        if ($keyword === '' || mb_strlen($keyword) > 200) {
            throw new ApiException('关键词无效。', 422);
        }

        return $keyword;
    }

    private function normalizeMatchMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MATCH_MODES, true)) {
            throw new ApiException('匹配模式无效。', 422);
        }

        return $mode;
    }

    private function normalizeReply(string $reply): string
    {
        $reply = trim($reply);
        if ($reply === '' || mb_strlen($reply) > 2000) {
            throw new ApiException('回复内容无效。', 422);
        }

        return $reply;
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new ApiException('标题无效。', 422);
        }

        return $title;
    }

    private function normalizeContent(string $content): string
    {
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 20000) {
            throw new ApiException('内容无效。', 422);
        }

        return $content;
    }

    private function limitStr(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            return mb_substr($value, 0, $max);
        }

        return $value;
    }

    private function boolStatus(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === 1 || $value === '1' || $value === true || $value === 'true') {
            return 1;
        }
        if ($value === 0 || $value === '0' || $value === false || $value === 'false') {
            return 0;
        }
        throw new ApiException('状态无效。', 422);
    }

    private function intVal(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        throw new ApiException($label . '无效。', 422);
    }

    /** @param array<string, mixed> $filters @return array{0:int,1:int} */
    private function pagination(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? $filters['current_page'] ?? 1));
        $limit = (int) ($filters['limit'] ?? $filters['per_page'] ?? 20);
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        return [$page, $limit];
    }

    /** @param list<mixed> $ids @return list<int> */
    private function normalizeIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $out[] = $id;
            } elseif (is_string($id) && preg_match('/^\d+$/', $id) && (int) $id > 0) {
                $out[] = (int) $id;
            }
        }

        return array_values(array_unique($out));
    }
}
