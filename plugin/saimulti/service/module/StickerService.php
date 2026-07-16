<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class StickerService
{
    private const PACK = 'sm_sticker_pack';
    private const ITEM = 'sm_sticker_item';
    private const CODE_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    /**
     * @param array<string, mixed> $filters
     * @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int}
     */
    public function packList(int $organization, array $filters): array
    {
        $this->assertOrganization($organization, true);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::PACK)->where('organization', $organization)->whereNull('delete_time');
        $code = trim((string) ($filters['code'] ?? ''));
        if ($code !== '') {
            $query->whereLike('code', '%' . addcslashes($code, '%_\\') . '%');
        }
        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $query->whereLike('name', '%' . addcslashes($name, '%_\\') . '%');
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', $this->integer($filters['status'], '状态') ? 1 : 0);
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('sort', 'desc')->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return ['current_page' => $page, 'data' => array_values($items), 'per_page' => $limit, 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function packRead(int $organization, int $id): array
    {
        return $this->packRow($organization, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function packCreate(int $organization, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        $code = $this->normalizeCode((string) ($input['code'] ?? ''));
        $name = $this->normalizeName((string) ($input['name'] ?? ''));
        $description = $this->normalizeDescription((string) ($input['description'] ?? ''));
        $cover = $this->normalizeFileId((string) ($input['cover_file_id'] ?? ''));
        $status = array_key_exists('status', $input) ? ($this->integer($input['status'], '状态') ? 1 : 0) : 1;
        $sort = array_key_exists('sort', $input) ? max(0, $this->integer($input['sort'], '排序')) : 0;
        if ($this->packCodeExists($organization, $code)) {
            throw new ApiException('表情包编码已存在。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::PACK)->insertGetId([
            'organization' => $organization,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'cover_file_id' => $cover,
            'status' => $status,
            'sort' => $sort,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => null,
        ]);

        return $this->packRow($organization, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function packUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        $row = $this->packRow($organization, $id);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) $input['code']);
            if ($code !== $row['code'] && $this->packCodeExists($organization, $code, $id)) {
                throw new ApiException('表情包编码已存在。', 422);
            }
            $data['code'] = $code;
        }
        if (array_key_exists('name', $input)) {
            $data['name'] = $this->normalizeName((string) $input['name']);
        }
        if (array_key_exists('description', $input)) {
            $data['description'] = $this->normalizeDescription((string) $input['description']);
        }
        if (array_key_exists('cover_file_id', $input)) {
            $data['cover_file_id'] = $this->normalizeFileId((string) $input['cover_file_id']);
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->integer($input['status'], '状态') ? 1 : 0;
        }
        if (array_key_exists('sort', $input)) {
            $data['sort'] = max(0, $this->integer($input['sort'], '排序'));
        }
        Db::table(self::PACK)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->packRow($organization, $id);
    }

    /** @param list<int> $ids */
    public function packDelete(int $organization, array $ids, int $actorId): int
    {
        $this->assertOrganization($organization, true);
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
            $row = Db::table(self::PACK)->where('id', $id)->where('organization', $organization)->whereNull('delete_time')->find();
            if ($row === null) {
                continue;
            }
            $suffix = '__del_' . $id;
            $code = substr((string) $row['code'], 0, max(1, 64 - strlen($suffix))) . $suffix;
            $n = Db::table(self::PACK)->where('id', $id)->whereNull('delete_time')->update([
                'code' => $code,
                'status' => 0,
                'delete_time' => $now,
                'updated_by' => $actorId,
                'update_time' => $now,
            ]);
            if ($n) {
                // soft-delete items of this pack
                $items = Db::table(self::ITEM)->where('pack_id', $id)->where('organization', $organization)->whereNull('delete_time')->select()->toArray();
                foreach ($items as $item) {
                    $is = '__del_' . $item['id'];
                    $ic = substr((string) $item['code'], 0, max(1, 64 - strlen($is))) . $is;
                    Db::table(self::ITEM)->where('id', $item['id'])->update([
                        'code' => $ic,
                        'status' => 0,
                        'delete_time' => $now,
                        'updated_by' => $actorId,
                        'update_time' => $now,
                    ]);
                }
            }
            $deleted += (int) $n;
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int}
     */
    public function itemList(int $organization, array $filters): array
    {
        $this->assertOrganization($organization, true);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::ITEM)->where('organization', $organization)->whereNull('delete_time');
        if (isset($filters['pack_id']) && $filters['pack_id'] !== '' && $filters['pack_id'] !== null) {
            $query->where('pack_id', $this->integer($filters['pack_id'], '表情包编号'));
        }
        $code = trim((string) ($filters['code'] ?? ''));
        if ($code !== '') {
            $query->whereLike('code', '%' . addcslashes($code, '%_\\') . '%');
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('sort', 'desc')->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return ['current_page' => $page, 'data' => array_values($items), 'per_page' => $limit, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function itemCreate(int $organization, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        $packId = $this->integer($input['pack_id'] ?? 0, '表情包编号');
        $this->packRow($organization, $packId);
        $code = $this->normalizeCode((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) > 100) {
            throw new ApiException('表情名称过长。', 422);
        }
        $fileId = $this->normalizeFileId((string) ($input['file_id'] ?? ''));
        $status = array_key_exists('status', $input) ? ($this->integer($input['status'], '状态') ? 1 : 0) : 1;
        $sort = array_key_exists('sort', $input) ? max(0, $this->integer($input['sort'], '排序')) : 0;
        if ($this->itemCodeExists($organization, $packId, $code)) {
            throw new ApiException('表情编码已存在。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::ITEM)->insertGetId([
            'organization' => $organization,
            'pack_id' => $packId,
            'code' => $code,
            'name' => $name,
            'file_id' => $fileId,
            'status' => $status,
            'sort' => $sort,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => null,
        ]);

        return $this->itemRow($organization, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function itemUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        $row = $this->itemRow($organization, $id);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        $packId = (int) $row['pack_id'];
        $code = (string) $row['code'];
        if (array_key_exists('pack_id', $input)) {
            $packId = $this->integer($input['pack_id'], '表情包编号');
            $this->packRow($organization, $packId);
            $data['pack_id'] = $packId;
        }
        if (array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) $input['code']);
            $data['code'] = $code;
        }
        if (($packId !== (int) $row['pack_id'] || $code !== $row['code'])
            && $this->itemCodeExists($organization, $packId, $code, $id)) {
            throw new ApiException('表情编码已存在。', 422);
        }
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if (mb_strlen($name) > 100) {
                throw new ApiException('表情名称过长。', 422);
            }
            $data['name'] = $name;
        }
        if (array_key_exists('file_id', $input)) {
            $data['file_id'] = $this->normalizeFileId((string) $input['file_id']);
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->integer($input['status'], '状态') ? 1 : 0;
        }
        if (array_key_exists('sort', $input)) {
            $data['sort'] = max(0, $this->integer($input['sort'], '排序'));
        }
        Db::table(self::ITEM)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->itemRow($organization, $id);
    }

    /** @param list<int> $ids */
    public function itemDelete(int $organization, array $ids, int $actorId): int
    {
        $this->assertOrganization($organization, true);
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
            $row = Db::table(self::ITEM)->where('id', $id)->where('organization', $organization)->whereNull('delete_time')->find();
            if ($row === null) {
                continue;
            }
            $suffix = '__del_' . $id;
            $code = substr((string) $row['code'], 0, max(1, 64 - strlen($suffix))) . $suffix;
            $n = Db::table(self::ITEM)->where('id', $id)->whereNull('delete_time')->update([
                'code' => $code,
                'status' => 0,
                'delete_time' => $now,
                'updated_by' => $actorId,
                'update_time' => $now,
            ]);
            $deleted += (int) $n;
        }

        return $deleted;
    }

    /** @return list<array<string, mixed>> */
    public function clientPacks(int $organization): array
    {
        $this->assertOrganization($organization, false);
        $platform = Db::table(self::PACK)
            ->where('organization', 0)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $tenant = Db::table(self::PACK)
            ->where('organization', $organization)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return array_values(array_merge($platform, $tenant));
    }

    /** @return list<array<string, mixed>> */
    public function clientItems(int $organization, ?int $packId = null): array
    {
        $this->assertOrganization($organization, false);
        $packs = $this->clientPacks($organization);
        $packIds = array_map(static fn (array $p): int => (int) $p['id'], $packs);
        if ($packId !== null) {
            if (!in_array($packId, $packIds, true)) {
                throw new ApiException('表情包不可用。', 422);
            }
            $packIds = [$packId];
        }
        if ($packIds === []) {
            return [];
        }
        $items = Db::table(self::ITEM)
            ->whereIn('pack_id', $packIds)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // only include items from allowed org (0 or current)
        return array_values(array_filter(
            $items,
            static fn (array $row): bool => in_array((int) $row['organization'], [0, $organization], true),
        ));
    }

    /** @return array<string, mixed> */
    private function packRow(int $organization, int $id): array
    {
        $row = Db::table(self::PACK)->where('id', $id)->where('organization', $organization)->whereNull('delete_time')->find();
        if ($row === null) {
            throw new ApiException('表情包不存在。', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function itemRow(int $organization, int $id): array
    {
        $row = Db::table(self::ITEM)->where('id', $id)->where('organization', $organization)->whereNull('delete_time')->find();
        if ($row === null) {
            throw new ApiException('表情不存在。', 404);
        }

        return $row;
    }

    private function packCodeExists(int $organization, string $code, ?int $exceptId = null): bool
    {
        $q = Db::table(self::PACK)->where('organization', $organization)->where('code', $code)->whereNull('delete_time');
        if ($exceptId !== null) {
            $q->where('id', '<>', $exceptId);
        }

        return (int) $q->count() > 0;
    }

    private function itemCodeExists(int $organization, int $packId, string $code, ?int $exceptId = null): bool
    {
        $q = Db::table(self::ITEM)
            ->where('organization', $organization)
            ->where('pack_id', $packId)
            ->where('code', $code)
            ->whereNull('delete_time');
        if ($exceptId !== null) {
            $q->where('id', '<>', $exceptId);
        }

        return (int) $q->count() > 0;
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

    private function normalizeDescription(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) > 500) {
            throw new ApiException('描述过长。', 422);
        }

        return $description;
    }

    private function normalizeFileId(string $fileId): string
    {
        $fileId = trim($fileId);
        if (strlen($fileId) > 64) {
            throw new ApiException('file_id 过长。', 422);
        }

        return $fileId;
    }

    private function assertOrganization(int $organization, bool $allowPlatform): void
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
