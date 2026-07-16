<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class FileMediaService
{
    private const QUOTA = 'sm_file_media_quota';
    private const FOLDER = 'sm_file_media_folder';
    private const ITEM = 'sm_file_media_item';
    private const KINDS = ['image', 'file', 'voice', 'video', 'other'];
    private const PREVIEW = ['none', 'ready', 'failed', 'pending'];
    private const DEFAULT_MAX_STORAGE = 10737418240; // 10GiB
    private const DEFAULT_MAX_FILE = 2147483648; // 2GiB

    // ---------- quota ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function quotaList(array $filters): array
    {
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::QUOTA)->whereNull('delete_time');
        if (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', $this->boolStatus($filters['status']));
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('organization', 'asc')->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatQuota'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function quotaRead(int $organization, bool $createIfMissing = true): array
    {
        $this->assertOrg($organization, false);
        $row = Db::table(self::QUOTA)
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->find();
        if ($row === null) {
            if (!$createIfMissing) {
                throw new ApiException('配额不存在。', 404);
            }
            $row = $this->createDefaultQuota($organization, 0);
        }

        return $this->formatQuota($row);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function quotaUpdate(int $organization, array $input, int $actorId): array
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $this->quotaRead($organization, true);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('max_storage_bytes', $input)) {
            $data['max_storage_bytes'] = $this->nonNegInt($input['max_storage_bytes'], '存储配额');
        }
        if (array_key_exists('max_file_bytes', $input)) {
            $maxFile = $this->nonNegInt($input['max_file_bytes'], '单文件上限');
            if ($maxFile === 0) {
                throw new ApiException('单文件上限必须大于 0。', 422);
            }
            $data['max_file_bytes'] = $maxFile;
        }
        if (array_key_exists('preview_enabled', $input)) {
            $data['preview_enabled'] = $this->boolStatus($input['preview_enabled']);
        }
        if (array_key_exists('large_file_enabled', $input)) {
            $data['large_file_enabled'] = $this->boolStatus($input['large_file_enabled']);
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->boolStatus($input['status']);
        }
        Db::table(self::QUOTA)->where('organization', $organization)->whereNull('delete_time')->update($data);

        return $this->quotaRead($organization, false);
    }

    /**
     * Pre-upload check against tenant quota policy.
     *
     * @return array{allowed:bool,reason:string,quota:array<string,mixed>,size_bytes:int}
     */
    public function checkUpload(int $organization, int $sizeBytes): array
    {
        $this->assertOrg($organization, false);
        if ($sizeBytes <= 0) {
            throw new ApiException('文件大小无效。', 422);
        }
        $quota = $this->quotaRead($organization, true);
        if ((int) $quota['status'] !== 1) {
            return ['allowed' => false, 'reason' => '文件媒体策略已停用。', 'quota' => $quota, 'size_bytes' => $sizeBytes];
        }
        $maxFile = (int) $quota['max_file_bytes'];
        if ($sizeBytes > $maxFile) {
            return ['allowed' => false, 'reason' => '超过单文件大小上限。', 'quota' => $quota, 'size_bytes' => $sizeBytes];
        }
        // large files (>100MiB) require large_file_enabled
        if ($sizeBytes > 100 * 1024 * 1024 && (int) $quota['large_file_enabled'] !== 1) {
            return ['allowed' => false, 'reason' => '未开启大文件上传。', 'quota' => $quota, 'size_bytes' => $sizeBytes];
        }
        $maxStorage = (int) $quota['max_storage_bytes'];
        $used = (int) $quota['used_storage_bytes'];
        if ($maxStorage > 0 && ($used + $sizeBytes) > $maxStorage) {
            return ['allowed' => false, 'reason' => '存储配额不足。', 'quota' => $quota, 'size_bytes' => $sizeBytes];
        }

        return ['allowed' => true, 'reason' => '', 'quota' => $quota, 'size_bytes' => $sizeBytes];
    }

    // ---------- folder ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function folderList(int $organization, array $filters, bool $allowPlatform = false): array
    {
        $this->assertOrg($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::FOLDER)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        if (isset($filters['parent_id']) && $filters['parent_id'] !== '' && $filters['parent_id'] !== null) {
            $query->where('parent_id', $this->nonNegInt($filters['parent_id'], '父目录'));
        }
        $owner = trim((string) ($filters['owner_user_id'] ?? ''));
        if ($owner !== '') {
            $query->where('owner_user_id', $owner);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->whereRaw('name LIKE ?', [$like]);
        }
        $total = (int) (clone $query)->count();
        $items = $query->order(['sort' => 'asc', 'id' => 'desc'])->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatFolder'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function folderCreate(int $organization, array $input, int $actorId, string $ownerUserId = ''): array
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $parentId = $this->nonNegInt($input['parent_id'] ?? 0, '父目录');
        if ($parentId > 0) {
            $this->folderRow($organization, $parentId, false);
        }
        $name = $this->normalizeName((string) ($input['name'] ?? ''));
        $owner = $ownerUserId !== '' ? $ownerUserId : trim((string) ($input['owner_user_id'] ?? ''));
        if ($owner !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $owner)) {
            throw new ApiException('owner_user_id 无效。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::FOLDER)->insertGetId([
            'organization' => $organization,
            'parent_id' => $parentId,
            'owner_user_id' => $owner,
            'name' => $name,
            'sort' => max(0, $this->intVal($input['sort'] ?? 0, '排序')),
            'status' => $this->boolStatus($input['status'] ?? 1),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->formatFolder($this->folderRow($organization, $id, false));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function folderUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $this->folderRow($organization, $id, false);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('name', $input)) {
            $data['name'] = $this->normalizeName((string) $input['name']);
        }
        if (array_key_exists('parent_id', $input)) {
            $parentId = $this->nonNegInt($input['parent_id'], '父目录');
            if ($parentId === $id) {
                throw new ApiException('父目录不能是自身。', 422);
            }
            if ($parentId > 0) {
                $this->folderRow($organization, $parentId, false);
            }
            $data['parent_id'] = $parentId;
        }
        if (array_key_exists('sort', $input)) {
            $data['sort'] = max(0, $this->intVal($input['sort'], '排序'));
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->boolStatus($input['status']);
        }
        Db::table(self::FOLDER)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->formatFolder($this->folderRow($organization, $id, false));
    }

    /** @param list<int> $ids */
    public function folderDelete(int $organization, array $ids, int $actorId): int
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
            $child = Db::table(self::FOLDER)
                ->where('organization', $organization)
                ->where('parent_id', $id)
                ->whereNull('delete_time')
                ->find();
            if ($child !== null) {
                throw new ApiException('目录下仍有子目录，无法删除。', 422);
            }
            $item = Db::table(self::ITEM)
                ->where('organization', $organization)
                ->where('folder_id', $id)
                ->whereNull('delete_time')
                ->find();
            if ($item !== null) {
                throw new ApiException('目录下仍有文件，无法删除。', 422);
            }
            $n = Db::table(self::FOLDER)
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

    // ---------- item ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function itemList(int $organization, array $filters, bool $allowPlatform = false): array
    {
        $this->assertOrg($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::ITEM)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        if (isset($filters['folder_id']) && $filters['folder_id'] !== '' && $filters['folder_id'] !== null) {
            $query->where('folder_id', $this->nonNegInt($filters['folder_id'], '目录编号'));
        }
        $owner = trim((string) ($filters['owner_user_id'] ?? ''));
        if ($owner !== '') {
            $query->where('owner_user_id', $owner);
        }
        $kind = trim((string) ($filters['kind'] ?? ''));
        if ($kind !== '') {
            $query->where('kind', $this->normalizeKind($kind));
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->whereRaw('(name LIKE ? OR file_id LIKE ?)', [$like, $like]);
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatItem'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function itemCreate(int $organization, array $input, int $actorId, string $ownerUserId = ''): array
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $folderId = $this->nonNegInt($input['folder_id'] ?? 0, '目录编号');
        if ($folderId > 0) {
            $this->folderRow($organization, $folderId, false);
        }
        $size = $this->nonNegInt($input['size_bytes'] ?? 0, '文件大小');
        if ($size <= 0) {
            throw new ApiException('文件大小无效。', 422);
        }
        $check = $this->checkUpload($organization, $size);
        if (!$check['allowed']) {
            throw new ApiException($check['reason'] !== '' ? $check['reason'] : '上传不被允许。', 422);
        }
        $owner = $ownerUserId !== '' ? $ownerUserId : trim((string) ($input['owner_user_id'] ?? ''));
        if ($owner !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $owner)) {
            throw new ApiException('owner_user_id 无效。', 422);
        }
        $name = $this->normalizeFileName((string) ($input['name'] ?? ''));
        $fileId = $this->limitStr((string) ($input['file_id'] ?? ''), 128);
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::ITEM)->insertGetId([
            'organization' => $organization,
            'folder_id' => $folderId,
            'owner_user_id' => $owner,
            'name' => $name,
            'file_id' => $fileId,
            'mime_type' => $this->limitStr((string) ($input['mime_type'] ?? ''), 128),
            'kind' => $this->normalizeKind((string) ($input['kind'] ?? 'file')),
            'size_bytes' => $size,
            'preview_status' => $this->normalizePreview((string) ($input['preview_status'] ?? 'none')),
            'status' => $this->boolStatus($input['status'] ?? 1),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $this->adjustUsage($organization, $size, 1);

        return $this->formatItem($this->itemRow($organization, $id, false));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function itemUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $row = $this->itemRow($organization, $id, false);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
        if (array_key_exists('name', $input)) {
            $data['name'] = $this->normalizeFileName((string) $input['name']);
        }
        if (array_key_exists('folder_id', $input)) {
            $folderId = $this->nonNegInt($input['folder_id'], '目录编号');
            if ($folderId > 0) {
                $this->folderRow($organization, $folderId, false);
            }
            $data['folder_id'] = $folderId;
        }
        if (array_key_exists('preview_status', $input)) {
            $data['preview_status'] = $this->normalizePreview((string) $input['preview_status']);
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->boolStatus($input['status']);
        }
        Db::table(self::ITEM)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->formatItem($this->itemRow($organization, $id, false));
    }

    /** @param list<int> $ids */
    public function itemDelete(int $organization, array $ids, int $actorId): int
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
            $row = Db::table(self::ITEM)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->find();
            if ($row === null) {
                continue;
            }
            $n = Db::table(self::ITEM)->where('id', $id)->whereNull('delete_time')->update([
                'delete_time' => $now,
                'updated_by' => $actorId,
                'update_time' => $now,
            ]);
            if ($n > 0) {
                $this->adjustUsage($organization, -((int) $row['size_bytes']), -1);
                $deleted += (int) $n;
            }
        }

        return $deleted;
    }

    // ---------- internals ----------
    /** @return array<string, mixed> */
    private function createDefaultQuota(int $organization, int $actorId): array
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::QUOTA)->insertGetId([
            'organization' => $organization,
            'max_storage_bytes' => self::DEFAULT_MAX_STORAGE,
            'max_file_bytes' => self::DEFAULT_MAX_FILE,
            'used_storage_bytes' => 0,
            'used_file_count' => 0,
            'preview_enabled' => 1,
            'large_file_enabled' => 1,
            'status' => 1,
            'created_by' => $actorId > 0 ? $actorId : null,
            'updated_by' => $actorId > 0 ? $actorId : null,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $row = Db::table(self::QUOTA)->where('id', $id)->find();

        return is_array($row) ? $row : [];
    }

    private function adjustUsage(int $organization, int $deltaBytes, int $deltaCount): void
    {
        $this->quotaRead($organization, true);
        $row = Db::table(self::QUOTA)->where('organization', $organization)->whereNull('delete_time')->find();
        if ($row === null) {
            return;
        }
        $usedBytes = max(0, (int) $row['used_storage_bytes'] + $deltaBytes);
        $usedCount = max(0, (int) $row['used_file_count'] + $deltaCount);
        Db::table(self::QUOTA)->where('id', $row['id'])->update([
            'used_storage_bytes' => $usedBytes,
            'used_file_count' => $usedCount,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed> */
    private function folderRow(int $organization, int $id, bool $allowPlatform): array
    {
        $this->assertOrg($organization, $allowPlatform);
        if ($id <= 0) {
            throw new ApiException('目录编号无效。', 422);
        }
        $query = Db::table(self::FOLDER)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('目录不存在。', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function itemRow(int $organization, int $id, bool $allowPlatform): array
    {
        $this->assertOrg($organization, $allowPlatform);
        if ($id <= 0) {
            throw new ApiException('文件编号无效。', 422);
        }
        $query = Db::table(self::ITEM)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('文件不存在。', 404);
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatQuota(array $row): array
    {
        $maxStorage = (int) $row['max_storage_bytes'];
        $used = (int) $row['used_storage_bytes'];

        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'max_storage_bytes' => $maxStorage,
            'max_file_bytes' => (int) $row['max_file_bytes'],
            'used_storage_bytes' => $used,
            'used_file_count' => (int) $row['used_file_count'],
            'usage_ratio' => $maxStorage > 0 ? round(min(1, $used / $maxStorage), 4) : 0,
            'preview_enabled' => (int) $row['preview_enabled'],
            'large_file_enabled' => (int) $row['large_file_enabled'],
            'status' => (int) $row['status'],
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatFolder(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'parent_id' => (int) $row['parent_id'],
            'owner_user_id' => (string) ($row['owner_user_id'] ?? ''),
            'name' => (string) $row['name'],
            'sort' => (int) $row['sort'],
            'status' => (int) $row['status'],
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'folder_id' => (int) $row['folder_id'],
            'owner_user_id' => (string) ($row['owner_user_id'] ?? ''),
            'name' => (string) $row['name'],
            'file_id' => (string) ($row['file_id'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'kind' => (string) $row['kind'],
            'size_bytes' => (int) $row['size_bytes'],
            'preview_status' => (string) $row['preview_status'],
            'status' => (int) $row['status'],
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
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

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ApiException('目录名称无效。', 422);
        }

        return $name;
    }

    private function normalizeFileName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new ApiException('文件名无效。', 422);
        }

        return $name;
    }

    private function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::KINDS, true)) {
            throw new ApiException('文件类型无效。', 422);
        }

        return $kind;
    }

    private function normalizePreview(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::PREVIEW, true)) {
            throw new ApiException('预览状态无效。', 422);
        }

        return $status;
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

    private function nonNegInt(mixed $value, string $label): int
    {
        $n = $this->intVal($value, $label);
        if ($n < 0) {
            throw new ApiException($label . '无效。', 422);
        }

        return $n;
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
