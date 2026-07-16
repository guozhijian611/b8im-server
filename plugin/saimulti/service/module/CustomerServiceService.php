<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class CustomerServiceService
{
    private const QUEUE = 'sm_customer_service_queue';
    private const ENTRY = 'sm_customer_service_entry';
    private const AGENT = 'sm_customer_service_agent';
    private const VISITOR = 'sm_customer_service_visitor';
    private const CONV = 'sm_customer_service_conversation';
    private const SUBJECT_TYPES = ['im_user', 'external_visitor'];
    private const CONV_STATUS = ['queued', 'assigned', 'active', 'closed'];
    private const CODE_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    // ---------- queue ----------
    /** @param array<string, mixed> $filters @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int} */
    public function queueList(int $organization, array $filters): array
    {
        return $this->simpleList(self::QUEUE, $organization, $filters, ['code', 'name']);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function queueCreate(int $organization, array $input, int $actorId): array
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $code = $this->normalizeCode((string) ($input['code'] ?? ''));
        $name = $this->normalizeName((string) ($input['name'] ?? ''));
        if ($this->exists(self::QUEUE, $organization, 'code', $code)) {
            throw new ApiException('队列编码已存在。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::QUEUE)->insertGetId([
            'organization' => $organization,
            'code' => $code,
            'name' => $name,
            'description' => $this->limitStr((string) ($input['description'] ?? ''), 500),
            'priority' => max(0, $this->intVal($input['priority'] ?? 0, '优先级')),
            'status' => $this->boolStatus($input['status'] ?? 1),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->row(self::QUEUE, $organization, $id, '队列');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function queueUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $row = $this->row(self::QUEUE, $organization, $id, '队列');
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) $input['code']);
            if ($code !== $row['code'] && $this->exists(self::QUEUE, $organization, 'code', $code, $id)) {
                throw new ApiException('队列编码已存在。', 422);
            }
            $data['code'] = $code;
        }
        if (array_key_exists('name', $input)) {
            $data['name'] = $this->normalizeName((string) $input['name']);
        }
        if (array_key_exists('description', $input)) {
            $data['description'] = $this->limitStr((string) $input['description'], 500);
        }
        if (array_key_exists('priority', $input)) {
            $data['priority'] = max(0, $this->intVal($input['priority'], '优先级'));
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->boolStatus($input['status']);
        }
        Db::table(self::QUEUE)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->row(self::QUEUE, $organization, $id, '队列');
    }

    /** @param list<int> $ids */
    public function queueDelete(int $organization, array $ids, int $actorId): int
    {
        return $this->softDeleteByCode(self::QUEUE, $organization, $ids, $actorId);
    }

    // ---------- entry ----------
    /** @param array<string, mixed> $filters @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int} */
    public function entryList(int $organization, array $filters): array
    {
        return $this->simpleList(self::ENTRY, $organization, $filters, ['public_entry_code', 'name']);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function entryCreate(int $organization, array $input, int $actorId): array
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $code = $this->normalizeCode((string) ($input['public_entry_code'] ?? $input['code'] ?? ''));
        $name = $this->normalizeName((string) ($input['name'] ?? ''));
        $queueId = $this->intVal($input['queue_id'] ?? 0, '队列编号');
        $this->row(self::QUEUE, $organization, $queueId, '队列');
        if (Db::table(self::ENTRY)->where('public_entry_code', $code)->whereNull('delete_time')->count() > 0) {
            throw new ApiException('入口编码已存在。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::ENTRY)->insertGetId([
            'organization' => $organization,
            'public_entry_code' => $code,
            'name' => $name,
            'queue_id' => $queueId,
            'channel' => $this->limitStr((string) ($input['channel'] ?? 'web'), 32) ?: 'web',
            'allowed_origins' => $this->limitStr((string) ($input['allowed_origins'] ?? ''), 2000),
            'status' => $this->boolStatus($input['status'] ?? 1),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->row(self::ENTRY, $organization, $id, '入口');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function entryUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $row = $this->row(self::ENTRY, $organization, $id, '入口');
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('public_entry_code', $input) || array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) ($input['public_entry_code'] ?? $input['code'] ?? ''));
            $exists = Db::table(self::ENTRY)->where('public_entry_code', $code)->whereNull('delete_time')->where('id', '<>', $id)->count();
            if ($exists > 0) {
                throw new ApiException('入口编码已存在。', 422);
            }
            $data['public_entry_code'] = $code;
        }
        if (array_key_exists('name', $input)) {
            $data['name'] = $this->normalizeName((string) $input['name']);
        }
        if (array_key_exists('queue_id', $input)) {
            $queueId = $this->intVal($input['queue_id'], '队列编号');
            $this->row(self::QUEUE, $organization, $queueId, '队列');
            $data['queue_id'] = $queueId;
        }
        if (array_key_exists('channel', $input)) {
            $data['channel'] = $this->limitStr((string) $input['channel'], 32) ?: 'web';
        }
        if (array_key_exists('allowed_origins', $input)) {
            $data['allowed_origins'] = $this->limitStr((string) $input['allowed_origins'], 2000);
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->boolStatus($input['status']);
        }
        Db::table(self::ENTRY)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->row(self::ENTRY, $organization, $id, '入口');
    }

    /** @param list<int> $ids */
    public function entryDelete(int $organization, array $ids, int $actorId): int
    {
        return $this->softDeletePublicCode(self::ENTRY, $organization, $ids, $actorId, 'public_entry_code');
    }

    // ---------- agent ----------
    /** @param array<string, mixed> $filters @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int} */
    public function agentList(int $organization, array $filters): array
    {
        return $this->simpleList(self::AGENT, $organization, $filters, ['user_id', 'display_name']);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function agentCreate(int $organization, array $input, int $actorId): array
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $userId = $this->normalizeUserId((string) ($input['user_id'] ?? ''));
        if ($this->exists(self::AGENT, $organization, 'user_id', $userId)) {
            throw new ApiException('该用户已是坐席。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::AGENT)->insertGetId([
            'organization' => $organization,
            'user_id' => $userId,
            'display_name' => $this->limitStr((string) ($input['display_name'] ?? $userId), 100),
            'status' => $this->boolStatus($input['status'] ?? 1),
            'online_status' => $this->normalizeOnline((string) ($input['online_status'] ?? 'offline')),
            'max_concurrent' => max(1, $this->intVal($input['max_concurrent'] ?? 5, '并发上限')),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->row(self::AGENT, $organization, $id, '坐席');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function agentUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $this->row(self::AGENT, $organization, $id, '坐席');
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('display_name', $input)) {
            $data['display_name'] = $this->limitStr((string) $input['display_name'], 100);
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->boolStatus($input['status']);
        }
        if (array_key_exists('online_status', $input)) {
            $data['online_status'] = $this->normalizeOnline((string) $input['online_status']);
        }
        if (array_key_exists('max_concurrent', $input)) {
            $data['max_concurrent'] = max(1, $this->intVal($input['max_concurrent'], '并发上限'));
        }
        Db::table(self::AGENT)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->row(self::AGENT, $organization, $id, '坐席');
    }

    /** @param list<int> $ids */
    public function agentDelete(int $organization, array $ids, int $actorId): int
    {
        return $this->softDeleteByCode(self::AGENT, $organization, $ids, $actorId, 'user_id');
    }

    // ---------- conversation ----------
    /** @param array<string, mixed> $filters @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int} */
    public function conversationList(int $organization, array $filters, bool $allowPlatform = false): array
    {
        $this->assertOrg($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::CONV)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, self::CONV_STATUS, true)) {
                throw new ApiException('会话状态无效。', 422);
            }
            $query->where('status', $status);
        }
        $subjectType = trim((string) ($filters['customer_subject_type'] ?? ''));
        if ($subjectType !== '') {
            if (!in_array($subjectType, self::SUBJECT_TYPES, true)) {
                throw new ApiException('客户主体类型无效。', 422);
            }
            $query->where('customer_subject_type', $subjectType);
        }
        $subjectId = trim((string) ($filters['customer_subject_id'] ?? ''));
        if ($subjectId !== '') {
            $query->where('customer_subject_id', $subjectId);
        }
        if (isset($filters['agent_id']) && $filters['agent_id'] !== '' && $filters['agent_id'] !== null) {
            $query->where('agent_id', $this->intVal($filters['agent_id'], '坐席编号'));
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return ['current_page' => $page, 'data' => array_values($items), 'per_page' => $limit, 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function conversationRead(int $organization, int $id, bool $allowPlatform = false): array
    {
        $this->assertOrg($organization, $allowPlatform);
        $query = Db::table(self::CONV)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('会话不存在。', 404);
        }

        return $row;
    }

    /**
     * System IM user creates a conversation.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function conversationCreateByUser(int $organization, string $userId, array $input): array
    {
        $this->assertOrg($organization, false);
        $userId = $this->normalizeUserId($userId);
        $maxOpen = max(1, $this->intVal($input['max_open'] ?? 3, '上限'));
        $open = (int) Db::table(self::CONV)
            ->where('organization', $organization)
            ->where('customer_subject_type', 'im_user')
            ->where('customer_subject_id', $userId)
            ->whereIn('status', ['queued', 'assigned', 'active'])
            ->whereNull('delete_time')
            ->count();
        if ($open >= $maxOpen) {
            throw new ApiException('未关闭客服会话已达上限。', 422);
        }

        $queueId = null;
        $entryId = null;
        $channel = $this->limitStr((string) ($input['channel'] ?? 'web'), 32) ?: 'web';
        if (isset($input['entry_id']) && $input['entry_id'] !== '' && $input['entry_id'] !== null) {
            $entryId = $this->intVal($input['entry_id'], '入口编号');
            $entry = $this->row(self::ENTRY, $organization, $entryId, '入口');
            if ((int) $entry['status'] !== 1) {
                throw new ApiException('客服入口已停用。', 422);
            }
            $queueId = (int) $entry['queue_id'];
            $channel = (string) $entry['channel'];
        } elseif (isset($input['queue_id']) && $input['queue_id'] !== '' && $input['queue_id'] !== null) {
            $queueId = $this->intVal($input['queue_id'], '队列编号');
            $this->row(self::QUEUE, $organization, $queueId, '队列');
        } else {
            $code = trim((string) ($input['queue_code'] ?? 'default'));
            $queue = Db::table(self::QUEUE)
                ->where('organization', $organization)
                ->where('code', $code)
                ->where('status', 1)
                ->whereNull('delete_time')
                ->find();
            if ($queue === null) {
                // auto create default queue
                $now = date('Y-m-d H:i:s');
                $queueId = (int) Db::table(self::QUEUE)->insertGetId([
                    'organization' => $organization,
                    'code' => 'default',
                    'name' => '默认队列',
                    'description' => '系统自动创建',
                    'priority' => 0,
                    'status' => 1,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            } else {
                $queueId = (int) $queue['id'];
            }
        }

        $now = date('Y-m-d H:i:s');
        $no = 'CS' . date('YmdHis') . bin2hex(random_bytes(3));
        $id = (int) Db::table(self::CONV)->insertGetId([
            'organization' => $organization,
            'conversation_no' => $no,
            'entry_id' => $entryId,
            'queue_id' => $queueId,
            'agent_id' => null,
            'customer_subject_type' => 'im_user',
            'customer_subject_id' => $userId,
            'status' => 'queued',
            'channel' => $channel,
            'subject' => $this->limitStr((string) ($input['subject'] ?? ''), 200),
            'close_reason' => '',
            'queued_at' => $now,
            'assigned_at' => null,
            'closed_at' => null,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->conversationRead($organization, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function conversationUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $row = $this->conversationRead($organization, $id);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('status', $input)) {
            $status = trim((string) $input['status']);
            if (!in_array($status, self::CONV_STATUS, true)) {
                throw new ApiException('会话状态无效。', 422);
            }
            $data['status'] = $status;
            if ($status === 'closed') {
                $data['closed_at'] = date('Y-m-d H:i:s');
            }
            if (in_array($status, ['assigned', 'active'], true) && empty($row['assigned_at'])) {
                $data['assigned_at'] = date('Y-m-d H:i:s');
            }
        }
        if (array_key_exists('agent_id', $input)) {
            if ($input['agent_id'] === null || $input['agent_id'] === '') {
                $data['agent_id'] = null;
            } else {
                $agentId = $this->intVal($input['agent_id'], '坐席编号');
                $this->row(self::AGENT, $organization, $agentId, '坐席');
                $data['agent_id'] = $agentId;
                if (empty($row['assigned_at'])) {
                    $data['assigned_at'] = date('Y-m-d H:i:s');
                }
                if (in_array($row['status'], ['queued'], true) && !isset($data['status'])) {
                    $data['status'] = 'assigned';
                }
            }
        }
        if (array_key_exists('close_reason', $input)) {
            $data['close_reason'] = $this->limitStr((string) $input['close_reason'], 200);
        }
        if (array_key_exists('subject', $input)) {
            $data['subject'] = $this->limitStr((string) $input['subject'], 200);
        }
        Db::table(self::CONV)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->conversationRead($organization, $id);
    }

    /** @return array<string, mixed> */
    public function resolvePublicEntry(string $publicEntryCode): array
    {
        $code = $this->normalizeCode($publicEntryCode);
        $entry = Db::table(self::ENTRY)
            ->where('public_entry_code', $code)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->find();
        if ($entry === null) {
            throw new ApiException('客服入口不存在或已停用。', 404);
        }
        $queue = Db::table(self::QUEUE)
            ->where('id', $entry['queue_id'])
            ->where('organization', $entry['organization'])
            ->whereNull('delete_time')
            ->find();

        return [
            'entry' => [
                'id' => (int) $entry['id'],
                'organization' => (int) $entry['organization'],
                'public_entry_code' => (string) $entry['public_entry_code'],
                'name' => (string) $entry['name'],
                'channel' => (string) $entry['channel'],
                'queue_id' => (int) $entry['queue_id'],
            ],
            'queue' => $queue ? [
                'id' => (int) $queue['id'],
                'code' => (string) $queue['code'],
                'name' => (string) $queue['name'],
            ] : null,
        ];
    }

    // ---------- helpers ----------
    /**
     * @param list<string> $searchFields
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    private function simpleList(string $table, int $organization, array $filters, array $searchFields): array
    {
        $this->assertOrg($organization, false);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table($table)->where('organization', $organization)->whereNull('delete_time');
        $keyword = trim((string) ($filters['keyword'] ?? $filters['q'] ?? ''));
        if ($keyword !== '' && $searchFields !== []) {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $parts = [];
            $binds = [];
            foreach ($searchFields as $field) {
                $parts[] = $field . ' LIKE ?';
                $binds[] = $like;
            }
            $query->whereRaw('(' . implode(' OR ', $parts) . ')', $binds);
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', $this->boolStatus($filters['status']));
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return ['current_page' => $page, 'data' => array_values($items), 'per_page' => $limit, 'total' => $total];
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $organization, int $id, string $label): array
    {
        $row = Db::table($table)->where('id', $id)->where('organization', $organization)->whereNull('delete_time')->find();
        if ($row === null) {
            throw new ApiException($label . '不存在。', 404);
        }

        return $row;
    }

    private function exists(string $table, int $organization, string $field, string $value, ?int $exceptId = null): bool
    {
        $q = Db::table($table)->where('organization', $organization)->where($field, $value)->whereNull('delete_time');
        if ($exceptId !== null) {
            $q->where('id', '<>', $exceptId);
        }

        return (int) $q->count() > 0;
    }

    /** @param list<int> $ids */
    private function softDeleteByCode(string $table, int $organization, array $ids, int $actorId, string $codeField = 'code'): int
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
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
            $row = Db::table($table)->where('id', $id)->where('organization', $organization)->whereNull('delete_time')->find();
            if ($row === null) {
                continue;
            }
            $suffix = '__del_' . $id;
            $raw = (string) ($row[$codeField] ?? (string) $id);
            $code = substr($raw, 0, max(1, 64 - strlen($suffix))) . $suffix;
            $data = [
                $codeField => $code,
                'status' => 0,
                'delete_time' => $now,
                'updated_by' => $actorId,
                'update_time' => $now,
            ];
            if ($table === self::AGENT) {
                unset($data['status']);
                $data['status'] = 0;
            }
            $n = Db::table($table)->where('id', $id)->whereNull('delete_time')->update($data);
            $deleted += (int) $n;
        }

        return $deleted;
    }

    /** @param list<int> $ids */
    private function softDeletePublicCode(string $table, int $organization, array $ids, int $actorId, string $codeField): int
    {
        return $this->softDeleteByCode($table, $organization, $ids, $actorId, $codeField);
    }

    private function normalizeCode(string $code): string
    {
        $code = trim($code);
        if ($code === '' || !preg_match(self::CODE_PATTERN, $code)) {
            throw new ApiException('编码无效。', 422);
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

    private function normalizeUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '' || strlen($userId) > 64) {
            throw new ApiException('用户编号无效。', 422);
        }

        return $userId;
    }

    private function normalizeOnline(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['online', 'busy', 'offline'], true)) {
            throw new ApiException('在线状态无效。', 422);
        }

        return $status;
    }

    private function limitStr(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new ApiException('文本过长。', 422);
        }

        return $value;
    }

    private function boolStatus(mixed $value): int
    {
        return $this->intVal($value, '状态') ? 1 : 0;
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

    private function intVal(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        throw new ApiException($label . '无效。', 422);
    }

    /** @param array<string, mixed> $filters @return array{0:int,1:int} */
    private function pagination(array $filters): array
    {
        $page = max(1, $this->intVal($filters['page'] ?? 1, '页码'));
        $limit = max(1, min(100, $this->intVal($filters['limit'] ?? 20, '每页数量')));

        return [$page, $limit];
    }
}
