<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class MomentsService
{
    private const POST = 'sm_moments_post';
    private const COMMENT = 'sm_moments_comment';
    private const LIKE = 'sm_moments_like';
    private const PROFILE = 'sm_moments_profile';
    private const VIS = ['public', 'friends', 'private'];
    private const MAX_MEDIA = 9;
    private const MAX_CONTENT = 2000;
    private const DEFAULT_DAILY = 50;

    // ---------- management list ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function postList(int $organization, array $filters, bool $allowPlatform = false): array
    {
        $this->assertOrg($organization, $allowPlatform);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::POST)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        } elseif (isset($filters['organization']) && $filters['organization'] !== '' && $filters['organization'] !== null) {
            $query->where('organization', $this->intVal($filters['organization'], '机构编号'));
        }
        $userId = trim((string) ($filters['user_id'] ?? ''));
        if ($userId !== '') {
            $query->where('user_id', $userId);
        }
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', $this->boolStatus($filters['status']));
        }
        $visibility = trim((string) ($filters['visibility'] ?? ''));
        if ($visibility !== '') {
            $query->where('visibility', $this->normalizeVisibility($visibility));
        }
        $keyword = trim((string) ($filters['keyword'] ?? $filters['q'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->whereRaw('content LIKE ?', [$like]);
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map(fn (array $row): array => $this->formatPost($row), array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function postRead(int $organization, int $id, bool $allowPlatform = false, ?string $viewerUserId = null): array
    {
        $row = $this->postRow($organization, $id, $allowPlatform);
        if ($viewerUserId !== null && !$this->canView($row, $viewerUserId)) {
            throw new ApiException('动态不可见。', 404);
        }

        return $this->formatPost($row, $viewerUserId);
    }

    /**
     * Feed for end user: own private + public/friends of others in same org.
     *
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function feed(int $organization, string $viewerUserId, array $filters): array
    {
        $this->assertOrg($organization, false);
        $this->assertUserId($viewerUserId);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::POST)
            ->where('organization', $organization)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->whereRaw('(user_id = ? OR visibility IN (?, ?))', [$viewerUserId, 'public', 'friends']);
        $author = trim((string) ($filters['user_id'] ?? ''));
        if ($author !== '') {
            $query->where('user_id', $author);
        }
        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $data = [];
        foreach ($items as $row) {
            if ($this->canView($row, $viewerUserId)) {
                $data[] = $this->formatPost($row, $viewerUserId);
            }
        }

        return [
            'current_page' => $page,
            'data' => $data,
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function postCreate(int $organization, string $userId, array $input, int $actorId = 0, int $maxPerDay = self::DEFAULT_DAILY): array
    {
        $this->assertOrg($organization, false);
        $this->assertUserId($userId);
        if ($maxPerDay > 0) {
            $today = date('Y-m-d 00:00:00');
            $count = (int) Db::table(self::POST)
                ->where('organization', $organization)
                ->where('user_id', $userId)
                ->where('create_time', '>=', $today)
                ->whereNull('delete_time')
                ->count();
            if ($count >= $maxPerDay) {
                throw new ApiException('今日发布次数已达上限。', 422);
            }
        }
        $content = $this->normalizeContent((string) ($input['content'] ?? ''));
        $media = $this->normalizeMedia($input['media'] ?? $input['media_json'] ?? null);
        $visibility = $this->normalizeVisibility((string) ($input['visibility'] ?? 'friends'));
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::POST)->insertGetId([
            'organization' => $organization,
            'user_id' => $userId,
            'content' => $content,
            'media_json' => $media === [] ? null : json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'visibility' => $visibility,
            'like_count' => 0,
            'comment_count' => 0,
            'status' => 1,
            'created_by' => $actorId > 0 ? $actorId : null,
            'updated_by' => $actorId > 0 ? $actorId : null,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return $this->formatPost($this->postRow($organization, $id, false), $userId);
    }

    /** @param list<int> $ids */
    public function postDelete(int $organization, array $ids, int $actorId, ?string $ownerUserId = null): int
    {
        $this->assertOrg($organization, false);
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            throw new ApiException('编号列表无效。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $deleted = 0;
        foreach ($ids as $id) {
            $query = Db::table(self::POST)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time');
            if ($ownerUserId !== null) {
                $query->where('user_id', $ownerUserId);
            }
            $row = $query->find();
            if ($row === null) {
                continue;
            }
            $n = Db::table(self::POST)->where('id', $id)->whereNull('delete_time')->update([
                'delete_time' => $now,
                'updated_by' => $actorId > 0 ? $actorId : null,
                'update_time' => $now,
            ]);
            $deleted += (int) $n;
        }

        return $deleted;
    }

    // ---------- comments ----------
    /**
     * @param array<string, mixed> $filters
     * @return array{current_page:int,data:list<array<string,mixed>>,per_page:int,total:int}
     */
    public function commentList(int $organization, int $postId, array $filters, ?string $viewerUserId = null): array
    {
        $post = $this->postRow($organization, $postId, false);
        if ($viewerUserId !== null && !$this->canView($post, $viewerUserId)) {
            throw new ApiException('动态不可见。', 404);
        }
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::COMMENT)
            ->where('organization', $organization)
            ->where('post_id', $postId)
            ->where('status', 1)
            ->whereNull('delete_time');
        $total = (int) (clone $query)->count();
        $items = $query->order('id', 'asc')->page($page, $limit)->select()->toArray();

        return [
            'current_page' => $page,
            'data' => array_map([$this, 'formatComment'], array_values($items)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function commentCreate(int $organization, string $userId, array $input, int $actorId = 0): array
    {
        $this->assertOrg($organization, false);
        $this->assertUserId($userId);
        $postId = $this->intVal($input['post_id'] ?? 0, '动态编号');
        $post = $this->postRow($organization, $postId, false);
        if (!$this->canView($post, $userId)) {
            throw new ApiException('动态不可见。', 404);
        }
        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 1000) {
            throw new ApiException('评论内容无效。', 422);
        }
        $parentId = $this->nonNegInt($input['parent_id'] ?? 0, '父评论');
        if ($parentId > 0) {
            $parent = Db::table(self::COMMENT)
                ->where('id', $parentId)
                ->where('organization', $organization)
                ->where('post_id', $postId)
                ->whereNull('delete_time')
                ->find();
            if ($parent === null) {
                throw new ApiException('父评论不存在。', 404);
            }
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::COMMENT)->insertGetId([
            'organization' => $organization,
            'post_id' => $postId,
            'user_id' => $userId,
            'parent_id' => $parentId,
            'content' => $content,
            'status' => 1,
            'created_by' => $actorId > 0 ? $actorId : null,
            'updated_by' => $actorId > 0 ? $actorId : null,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::table(self::POST)->where('id', $postId)->update([
            'comment_count' => (int) $post['comment_count'] + 1,
            'update_time' => $now,
        ]);
        $row = Db::table(self::COMMENT)->where('id', $id)->find();

        return $this->formatComment(is_array($row) ? $row : []);
    }

    // ---------- likes ----------
    /** @return array{liked:bool,like_count:int} */
    public function likeToggle(int $organization, string $userId, int $postId): array
    {
        $this->assertOrg($organization, false);
        $this->assertUserId($userId);
        $post = $this->postRow($organization, $postId, false);
        if (!$this->canView($post, $userId)) {
            throw new ApiException('动态不可见。', 404);
        }
        $existing = Db::table(self::LIKE)
            ->where('organization', $organization)
            ->where('post_id', $postId)
            ->where('user_id', $userId)
            ->find();
        $now = date('Y-m-d H:i:s');
        if ($existing !== null) {
            Db::table(self::LIKE)->where('id', $existing['id'])->delete();
            Db::table(self::POST)->where('id', $postId)->update([
                'like_count' => max(0, (int) $post['like_count'] - 1),
                'update_time' => $now,
            ]);
            $liked = false;
        } else {
            Db::table(self::LIKE)->insert([
                'organization' => $organization,
                'post_id' => $postId,
                'user_id' => $userId,
                'create_time' => $now,
            ]);
            Db::table(self::POST)->where('id', $postId)->update([
                'like_count' => (int) $post['like_count'] + 1,
                'update_time' => $now,
            ]);
            $liked = true;
        }
        $row = $this->postRow($organization, $postId, false);

        return ['liked' => $liked, 'like_count' => (int) $row['like_count']];
    }

    // ---------- profile cover ----------
    /** @return array<string, mixed> */
    public function profileRead(int $organization, string $userId): array
    {
        $this->assertOrg($organization, false);
        $this->assertUserId($userId);
        $row = Db::table(self::PROFILE)
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->find();
        if ($row === null) {
            return [
                'organization' => $organization,
                'user_id' => $userId,
                'cover_url' => '',
            ];
        }

        return [
            'organization' => $organization,
            'user_id' => $userId,
            'cover_url' => (string) ($row['cover_url'] ?? ''),
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function profileUpdate(int $organization, string $userId, string $coverUrl): array
    {
        $this->assertOrg($organization, false);
        $this->assertUserId($userId);
        $coverUrl = $this->limitStr($coverUrl, 500);
        $now = date('Y-m-d H:i:s');
        $existing = Db::table(self::PROFILE)
            ->where('organization', $organization)
            ->where('user_id', $userId)
            ->find();
        if ($existing === null) {
            Db::table(self::PROFILE)->insert([
                'organization' => $organization,
                'user_id' => $userId,
                'cover_url' => $coverUrl,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } else {
            Db::table(self::PROFILE)->where('id', $existing['id'])->update([
                'cover_url' => $coverUrl,
                'update_time' => $now,
            ]);
        }

        return $this->profileRead($organization, $userId);
    }

    // ---------- internals ----------
    /** @param array<string, mixed> $row */
    private function canView(array $row, string $viewerUserId): bool
    {
        if ((int) ($row['status'] ?? 0) !== 1) {
            return false;
        }
        $owner = (string) ($row['user_id'] ?? '');
        if ($owner === $viewerUserId) {
            return true;
        }
        $visibility = (string) ($row['visibility'] ?? 'friends');
        // v0.1: friends == public within organization; private only owner.
        return in_array($visibility, ['public', 'friends'], true);
    }

    /** @return array<string, mixed> */
    private function postRow(int $organization, int $id, bool $allowPlatform): array
    {
        $this->assertOrg($organization, $allowPlatform);
        if ($id <= 0) {
            throw new ApiException('动态编号无效。', 422);
        }
        $query = Db::table(self::POST)->where('id', $id)->whereNull('delete_time');
        if ($organization > 0) {
            $query->where('organization', $organization);
        }
        $row = $query->find();
        if ($row === null) {
            throw new ApiException('动态不存在。', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPost(array $row, ?string $viewerUserId = null): array
    {
        $media = [];
        if (!empty($row['media_json'])) {
            $decoded = json_decode((string) $row['media_json'], true);
            if (is_array($decoded)) {
                $media = $decoded;
            }
        }
        $liked = false;
        if ($viewerUserId !== null && $viewerUserId !== '') {
            $liked = Db::table(self::LIKE)
                ->where('organization', $row['organization'])
                ->where('post_id', $row['id'])
                ->where('user_id', $viewerUserId)
                ->find() !== null;
        }

        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'user_id' => (string) $row['user_id'],
            'content' => (string) $row['content'],
            'media' => $media,
            'visibility' => (string) $row['visibility'],
            'like_count' => (int) $row['like_count'],
            'comment_count' => (int) $row['comment_count'],
            'liked' => $liked,
            'status' => (int) $row['status'],
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatComment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'organization' => (int) $row['organization'],
            'post_id' => (int) $row['post_id'],
            'user_id' => (string) $row['user_id'],
            'parent_id' => (int) $row['parent_id'],
            'content' => (string) $row['content'],
            'create_time' => $row['create_time'] ?? null,
        ];
    }

    private function assertOrg(int $organization, bool $allowPlatform): void
    {
        if ($organization < 0 || (!$allowPlatform && $organization <= 0)) {
            throw new ApiException('机构编号无效。', 422);
        }
    }

    private function assertUserId(string $userId): void
    {
        if ($userId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $userId)) {
            throw new ApiException('用户编号无效。', 422);
        }
    }

    private function normalizeContent(string $content): string
    {
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > self::MAX_CONTENT) {
            throw new ApiException('动态内容无效。', 422);
        }

        return $content;
    }

    private function normalizeVisibility(string $visibility): string
    {
        $visibility = strtolower(trim($visibility));
        if (!in_array($visibility, self::VIS, true)) {
            throw new ApiException('可见范围无效。', 422);
        }

        return $visibility;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeMedia(mixed $media): array
    {
        if ($media === null || $media === '') {
            return [];
        }
        if (is_string($media)) {
            $decoded = json_decode($media, true);
            $media = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($media)) {
            throw new ApiException('媒体列表无效。', 422);
        }
        if (count($media) > self::MAX_MEDIA) {
            throw new ApiException('媒体数量超限。', 422);
        }
        $out = [];
        foreach ($media as $item) {
            if (!is_array($item)) {
                throw new ApiException('媒体项无效。', 422);
            }
            $url = $this->limitStr((string) ($item['url'] ?? $item['file_id'] ?? ''), 500);
            if ($url === '') {
                throw new ApiException('媒体地址无效。', 422);
            }
            $out[] = [
                'url' => $url,
                'type' => $this->limitStr((string) ($item['type'] ?? 'image'), 20),
            ];
        }

        return $out;
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
