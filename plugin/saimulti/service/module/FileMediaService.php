<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\quota\StorageQuotaService;
use plugin\saimulti\service\web\AuthoritativeWebImUploadPolicy;
use plugin\saimulti\utils\CanonicalInteger;
use support\think\Db;

final class FileMediaService
{
    private const QUOTA = 'sm_file_media_quota';
    private const FOLDER = 'sm_file_media_folder';
    private const ITEM = 'sm_file_media_item';
    private const KINDS = ['image', 'file', 'voice', 'video', 'other'];
    private const PREVIEW = ['none', 'ready', 'failed', 'pending'];
    // ---------- commercial policy ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function policyList(array $filters): array
    {
        [$page, $limit] = $this->pagination($filters);
        $organizationQuery = Db::table('sm_system_organization')
            ->field('id')
            ->where('status', 1)
            ->whereNull('delete_time');
        $organizationFilter = null;
        if (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $organizationFilter = CanonicalInteger::positive(
                $filters['organization'],
                '机构编号',
            );
            $organizationQuery->where('id', $organizationFilter);
        }
        $organizations = array_map(
            static fn (array $row): int => CanonicalInteger::positive(
                $row['id'] ?? null,
                '机构编号',
            ),
            $organizationQuery->order('id', 'asc')->select()->toArray(),
        );
        if ($organizationFilter !== null && $organizations === []) {
            throw new ApiException('机构不存在或未启用。', 404);
        }
        $policyService = new FileMediaPolicyService();
        foreach ($organizations as $organization) {
            $policyService->ensureDefault($organization);
        }
        if ($organizations === []) {
            return [
                'current_page' => $page,
                'data' => [],
                'per_page' => $limit,
                'total' => 0,
            ];
        }
        $query = Db::table(self::QUOTA)
            ->whereIn('organization', $organizations)
            ->whereNull('delete_time');
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', $this->policyFilterFlag($filters['status']));
        }
        $total = (int) (clone $query)->count();
        $rows = $query->order('organization', 'asc')->page($page, $limit)->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $organization = (int) $row['organization'];
            $items[] = ['organization' => $organization]
                + $this->policyRead($organization);
        }

        return [
            'current_page' => $page,
            'data' => $items,
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function policyRead(int $organization): array
    {
        $this->assertOrg($organization, false);
        $policy = (new FileMediaPolicyService())->ensureDefault($organization);

        return $this->formatPolicy($policy);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function policyUpdate(
        int $organization,
        array $input,
        int $actorId,
    ): array
    {
        $expected = ['max_file_bytes', 'preview_enabled', 'large_file_enabled', 'status'];
        if (count($input) !== count($expected)
            || array_diff(array_keys($input), $expected) !== []
            || array_diff($expected, array_keys($input)) !== []) {
            throw new ApiException('文件模块策略请求必须包含完整且唯一的策略字段。', 422);
        }
        $maxFile = $this->strictDecimal($input['max_file_bytes'], '单文件上限');
        if ($maxFile < 1 || $maxFile > 2147483648) {
            throw new ApiException('单文件上限必须在 1 字节到 2GiB 之间。', 422);
        }
        $normalized = ['max_file_bytes' => $maxFile];
        foreach (['preview_enabled', 'large_file_enabled', 'status'] as $field) {
            $normalized[$field] = $this->strictPolicyFlag($input[$field], $field);
        }
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        (new FileMediaPolicyService())->ensureDefault($organization);
        Db::transaction(function () use ($organization, $normalized, $actorId): void {
            $policy = Db::table(self::QUOTA)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if (!is_array($policy)) {
                throw new ApiException('文件模块策略不可用。', 503);
            }
            $data = [
                'max_file_bytes' => $normalized['max_file_bytes'],
                'preview_enabled' => $normalized['preview_enabled'],
                'large_file_enabled' => $normalized['large_file_enabled'],
                'status' => $normalized['status'],
                'updated_by' => $actorId,
                'update_time' => date('Y-m-d H:i:s'),
            ];
            Db::table(self::QUOTA)
                ->where('id', (int) $policy['id'])
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->update($data);
        });
        return $this->policyRead($organization);
    }

    /** @return array{storage:array<string,mixed>,policy:array<string,mixed>} */
    public function usage(int $organization): array
    {
        return [
            'storage' => (new StorageQuotaService())->read($organization),
            'policy' => $this->policyRead($organization),
        ];
    }

    /**
     * Pre-upload check against tenant quota policy.
     *
     * @return array{
     *   allowed:bool,reason:string,size_bytes:int,
     *   storage:array<string,mixed>,policy:array<string,mixed>
     * }
     */
    public function checkUpload(int $organization, int $sizeBytes): array
    {
        $this->assertOrg($organization, false);
        if ($sizeBytes <= 0 || $sizeBytes > 2147483648) {
            throw new ApiException('文件大小无效。', 422);
        }
        $storage = (new StorageQuotaService())->read($organization);
        $policy = $this->policyRead($organization);
        try {
            (new AuthoritativeWebImUploadPolicy())->assertAllowed($organization, $sizeBytes);
        } catch (ApiException $exception) {
            if ($exception->getCode() !== 422) {
                throw $exception;
            }

            return [
                'allowed' => false,
                'reason' => $exception->getMessage(),
                'size_bytes' => $sizeBytes,
                'storage' => $storage,
                'policy' => $policy,
            ];
        }
        $maximum = (int) $storage['quota_value'];
        $occupancy = (int) $storage['occupancy_value'];
        if ($maximum > 0 && $occupancy > $maximum - $sizeBytes) {
            return [
                'allowed' => false,
                'reason' => '存储配额不足。',
                'size_bytes' => $sizeBytes,
                'storage' => $storage,
                'policy' => $policy,
            ];
        }

        return [
            'allowed' => true,
            'reason' => '',
            'size_bytes' => $sizeBytes,
            'storage' => $storage,
            'policy' => $policy,
        ];
    }

    // ---------- folder ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function folderList(
        int $organization,
        array $filters,
        bool $allowPlatform = false,
        string $ownerUserId = '',
    ): array
    {
        $this->assertOrg($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::FOLDER)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', CanonicalInteger::positive($filters['organization'], '机构编号'));
        }
        if (isset($filters['parent_id']) && $filters['parent_id'] !== '' && $filters['parent_id'] !== null) {
            $query->where('parent_id', $this->nonNegInt($filters['parent_id'], '父目录'));
        }
        $owner = $ownerUserId !== ''
            ? $this->owner($ownerUserId)
            : trim((string) ($filters['owner_user_id'] ?? ''));
        if ($owner !== '') {
            $query->where('owner_user_id', $this->owner($owner));
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
        $owner = $ownerUserId !== ''
            ? $this->owner($ownerUserId)
            : trim((string) ($input['owner_user_id'] ?? ''));
        if ($owner !== '') {
            $owner = $this->owner($owner);
        }
        if ($parentId > 0) {
            $this->folderRow($organization, $parentId, false, $owner);
        }
        $name = $this->normalizeName((string) ($input['name'] ?? ''));
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

        return $this->formatFolder($this->folderRow($organization, $id, false, $owner));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function folderUpdate(
        int $organization,
        int $id,
        array $input,
        int $actorId,
        string $ownerUserId = '',
    ): array
    {
        $this->assertActor($actorId);
        $owner = $ownerUserId === '' ? '' : $this->owner($ownerUserId);
        return Db::transaction(function () use (
            $organization,
            $id,
            $input,
            $actorId,
            $owner,
        ): array {
            $this->folderRow($organization, $id, false, $owner, true);
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
                    $this->folderRow($organization, $parentId, false, $owner, true);
                }
                $data['parent_id'] = $parentId;
            }
            if (array_key_exists('sort', $input)) {
                $data['sort'] = max(0, $this->intVal($input['sort'], '排序'));
            }
            if (array_key_exists('status', $input)) {
                $data['status'] = $this->boolStatus($input['status']);
            }
            $query = Db::table(self::FOLDER)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time');
            if ($owner !== '') {
                $query->where('owner_user_id', $owner);
            }
            if ((int) $query->update($data) !== 1) {
                throw new ApiException('目录不存在。', 404);
            }

            return $this->formatFolder(
                $this->folderRow($organization, $id, false, $owner, true),
            );
        });
    }

    /** @param list<int> $ids */
    public function folderDelete(
        int $organization,
        array $ids,
        int $actorId,
        string $ownerUserId = '',
    ): int
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            throw new ApiException('编号列表无效。', 422);
        }
        $owner = $ownerUserId === '' ? '' : $this->owner($ownerUserId);
        return Db::transaction(function () use ($organization, $ids, $actorId, $owner): int {
            $now = date('Y-m-d H:i:s');
            $deleted = 0;
            foreach ($ids as $id) {
                $this->folderRow($organization, $id, false, $owner, true);
                $childQuery = Db::table(self::FOLDER)
                    ->where('organization', $organization)
                    ->where('parent_id', $id)
                    ->whereNull('delete_time')
                    ->lock(true);
                $itemQuery = Db::table(self::ITEM)
                    ->where('organization', $organization)
                    ->where('folder_id', $id)
                    ->whereNull('delete_time')
                    ->lock(true);
                if ($owner !== '') {
                    $childQuery->where('owner_user_id', $owner);
                    $itemQuery->where('owner_user_id', $owner);
                }
                if ($childQuery->find() !== null) {
                    throw new ApiException('目录下仍有子目录，无法删除。', 422);
                }
                if ($itemQuery->find() !== null) {
                    throw new ApiException('目录下仍有文件，无法删除。', 422);
                }
                $update = Db::table(self::FOLDER)
                    ->where('id', $id)
                    ->where('organization', $organization)
                    ->whereNull('delete_time');
                if ($owner !== '') {
                    $update->where('owner_user_id', $owner);
                }
                $deleted += (int) $update->update([
                    'delete_time' => $now,
                    'updated_by' => $actorId,
                    'update_time' => $now,
                ]);
            }

            return $deleted;
        });
    }

    // ---------- item ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function itemList(
        int $organization,
        array $filters,
        bool $allowPlatform = false,
        string $ownerUserId = '',
    ): array
    {
        $this->assertOrg($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::ITEM)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', CanonicalInteger::positive($filters['organization'], '机构编号'));
        }
        if (isset($filters['folder_id']) && $filters['folder_id'] !== '' && $filters['folder_id'] !== null) {
            $query->where('folder_id', $this->nonNegInt($filters['folder_id'], '目录编号'));
        }
        $owner = $ownerUserId !== ''
            ? $this->owner($ownerUserId)
            : trim((string) ($filters['owner_user_id'] ?? ''));
        if ($owner !== '') {
            $query->where('owner_user_id', $this->owner($owner));
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
        $owner = $ownerUserId !== ''
            ? $this->owner($ownerUserId)
            : trim((string) ($input['owner_user_id'] ?? ''));
        if ($owner !== '') {
            $owner = $this->owner($owner);
        }
        $folderId = $this->nonNegInt($input['folder_id'] ?? 0, '目录编号');
        if ($folderId > 0) {
            $this->folderRow($organization, $folderId, false, $owner);
        }
        $fileId = trim((string) ($input['file_id'] ?? ''));
        if (preg_match('/^[a-f0-9]{40}$/', $fileId) !== 1) {
            throw new ApiException('可信附件编号无效。', 422);
        }
        $asset = Db::table('im_upload_asset')
            ->field('file_id,user_id,kind,name,size_byte,mime_type')
            ->where('organization', $organization)
            ->where('file_id', $fileId)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->find();
        if (!is_array($asset)) {
            throw new ApiException('可信附件不存在。', 422);
        }
        if ($ownerUserId !== '' && (string) $asset['user_id'] !== $ownerUserId) {
            throw new ApiException('只能登记当前用户上传的可信附件。', 403);
        }
        $name = $this->normalizeFileName((string) ($input['name'] ?? $asset['name']));
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::ITEM)->insertGetId([
            'organization' => $organization,
            'folder_id' => $folderId,
            'owner_user_id' => $owner,
            'name' => $name,
            'file_id' => $fileId,
            'mime_type' => $this->limitStr((string) $asset['mime_type'], 128),
            'kind' => $this->normalizeKind((string) $asset['kind']),
            'size_bytes' => (int) $asset['size_byte'],
            'preview_status' => $this->normalizePreview((string) ($input['preview_status'] ?? 'none')),
            'status' => $this->boolStatus($input['status'] ?? 1),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        return $this->formatItem($this->itemRow($organization, $id, false, $owner));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function itemUpdate(
        int $organization,
        int $id,
        array $input,
        int $actorId,
        string $ownerUserId = '',
    ): array
    {
        $this->assertActor($actorId);
        $owner = $ownerUserId === '' ? '' : $this->owner($ownerUserId);
        return Db::transaction(function () use (
            $organization,
            $id,
            $input,
            $actorId,
            $owner,
        ): array {
            $this->itemRow($organization, $id, false, $owner, true);
            $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];
            if (array_key_exists('name', $input)) {
                $data['name'] = $this->normalizeFileName((string) $input['name']);
            }
            if (array_key_exists('folder_id', $input)) {
                $folderId = $this->nonNegInt($input['folder_id'], '目录编号');
                if ($folderId > 0) {
                    $this->folderRow($organization, $folderId, false, $owner, true);
                }
                $data['folder_id'] = $folderId;
            }
            if (array_key_exists('preview_status', $input)) {
                $data['preview_status'] = $this->normalizePreview((string) $input['preview_status']);
            }
            if (array_key_exists('status', $input)) {
                $data['status'] = $this->boolStatus($input['status']);
            }
            $query = Db::table(self::ITEM)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time');
            if ($owner !== '') {
                $query->where('owner_user_id', $owner);
            }
            if ((int) $query->update($data) !== 1) {
                throw new ApiException('文件不存在。', 404);
            }

            return $this->formatItem(
                $this->itemRow($organization, $id, false, $owner, true),
            );
        });
    }

    /** @param list<int> $ids */
    public function itemDelete(
        int $organization,
        array $ids,
        int $actorId,
        string $ownerUserId = '',
    ): int
    {
        $this->assertOrg($organization, false);
        $this->assertActor($actorId);
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            throw new ApiException('编号列表无效。', 422);
        }
        $owner = $ownerUserId === '' ? '' : $this->owner($ownerUserId);
        return Db::transaction(function () use ($organization, $ids, $actorId, $owner): int {
            $now = date('Y-m-d H:i:s');
            $deleted = 0;
            foreach ($ids as $id) {
                try {
                    $this->itemRow($organization, $id, false, $owner, true);
                } catch (ApiException $exception) {
                    if ($exception->getCode() === 404) {
                        continue;
                    }
                    throw $exception;
                }
                $query = Db::table(self::ITEM)
                    ->where('id', $id)
                    ->where('organization', $organization)
                    ->whereNull('delete_time');
                if ($owner !== '') {
                    $query->where('owner_user_id', $owner);
                }
                $deleted += (int) $query->update([
                    'delete_time' => $now,
                    'updated_by' => $actorId,
                    'update_time' => $now,
                ]);
            }

            return $deleted;
        });
    }

    // ---------- internals ----------
    /** @return array<string, mixed> */
    private function folderRow(
        int $organization,
        int $id,
        bool $allowPlatform,
        string $ownerUserId = '',
        bool $lock = false,
    ): array
    {
        $this->assertOrg($organization, $allowPlatform);
        if ($id <= 0) {
            throw new ApiException('目录编号无效。', 422);
        }
        $query = Db::table(self::FOLDER)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        if ($ownerUserId !== '') {
            $query->where('owner_user_id', $this->owner($ownerUserId));
        }
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('目录不存在。', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function itemRow(
        int $organization,
        int $id,
        bool $allowPlatform,
        string $ownerUserId = '',
        bool $lock = false,
    ): array
    {
        $this->assertOrg($organization, $allowPlatform);
        if ($id <= 0) {
            throw new ApiException('文件编号无效。', 422);
        }
        $query = Db::table(self::ITEM)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        if ($ownerUserId !== '') {
            $query->where('owner_user_id', $this->owner($ownerUserId));
        }
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('文件不存在。', 404);
        }

        return $row;
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function formatPolicy(array $policy): array
    {
        return (new FileMediaPolicyService())->project($policy);
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

    private function owner(string $ownerUserId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ownerUserId) !== 1) {
            throw new ApiException('owner_user_id 无效。', 422);
        }

        return $ownerUserId;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8')
            || mb_strlen($name, 'UTF-8') > 100) {
            throw new ApiException('目录名称无效。', 422);
        }

        return $name;
    }

    private function normalizeFileName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8')
            || mb_strlen($name, 'UTF-8') > 255) {
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

    private function strictPolicyFlag(mixed $value, string $field): int
    {
        if ($value !== 0 && $value !== 1) {
            throw new ApiException("{$field} 必须是 JSON 整数 0 或 1。", 422);
        }

        return $value;
    }

    private function policyFilterFlag(mixed $value): int
    {
        if ($value === 0 || $value === '0') {
            return 0;
        }
        if ($value === 1 || $value === '1') {
            return 1;
        }
        throw new ApiException('策略状态筛选必须是 0 或 1。', 422);
    }

    private function intVal(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/^(?:0|[1-9]\d*|-[1-9]\d*)$/', $value) === 1) {
            $negative = str_starts_with($value, '-');
            $magnitude = $negative ? substr($value, 1) : $value;
            $maximum = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;
            if (strlen($magnitude) < strlen($maximum)
                || (strlen($magnitude) === strlen($maximum)
                    && strcmp($magnitude, $maximum) <= 0)) {
                return (int) $value;
            }
        }
        throw new ApiException($label . '无效。', 422);
    }

    private function nonNegInt(mixed $value, string $label): int
    {
        return CanonicalInteger::nonNegative($value, $label);
    }

    private function strictDecimal(mixed $value, string $label): int
    {
        if (!is_string($value)
            || preg_match('/^[1-9]\d*$/', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX)
                && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            throw new ApiException($label . '必须是正十进制字符串。', 422);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $filters @return array{0:int,1:int} */
    private function pagination(array $filters): array
    {
        try {
            $page = CanonicalInteger::positive(
                $filters['page'] ?? $filters['current_page'] ?? 1,
                '页码',
            );
        } catch (ApiException) {
            $page = 1;
        }
        try {
            $limit = CanonicalInteger::positive(
                $filters['limit'] ?? $filters['per_page'] ?? 20,
                '每页数量',
            );
        } catch (ApiException) {
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
            $out[] = CanonicalInteger::positive($id, '编号');
        }

        return array_values(array_unique($out));
    }
}
