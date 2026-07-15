<?php

declare(strict_types=1);

namespace plugin\saimulti\service\module;

use plugin\saimulti\exception\ApiException;
use support\think\Db;

final class I18nService
{
    private const LOCALE_TABLE = 'sm_i18n_locale';
    private const ENTRY_TABLE = 'sm_i18n_entry';
    private const CODE_PATTERN = '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,3}$/';
    private const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_.-]{0,190}$/';

    /**
     * @param array<string, mixed> $filters
     * @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int}
     */
    public function localeList(int $organization, array $filters): array
    {
        $this->assertOrganization($organization, true);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::LOCALE_TABLE)
            ->where('organization', $organization)
            ->whereNull('delete_time');

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
        $items = $query
            ->order('sort', 'desc')
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
    public function localeRead(int $organization, int $id): array
    {
        $this->assertOrganization($organization, true);

        return $this->localeRow($organization, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function localeCreate(int $organization, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        $code = $this->normalizeCode((string) ($input['code'] ?? ''));
        $name = $this->normalizeName((string) ($input['name'] ?? ''));
        $status = array_key_exists('status', $input) ? ($this->integer($input['status'], '状态') ? 1 : 0) : 1;
        $sort = array_key_exists('sort', $input) ? max(0, $this->integer($input['sort'], '排序')) : 0;
        $isDefault = !empty($input['is_default']) ? 1 : 0;

        if ($this->localeExists($organization, $code)) {
            throw new ApiException('语言代码已存在。', 422);
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::LOCALE_TABLE)->insertGetId([
            'organization' => $organization,
            'code' => $code,
            'name' => $name,
            'is_default' => $isDefault,
            'status' => $status,
            'sort' => $sort,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => null,
        ]);

        if ($isDefault === 1) {
            $this->setDefaultLocale($organization, $id, $actorId);
        }

        return $this->localeRow($organization, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function localeUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        $row = $this->localeRow($organization, $id);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];

        if (array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) $input['code']);
            if ($code !== $row['code'] && $this->localeExists($organization, $code, $id)) {
                throw new ApiException('语言代码已存在。', 422);
            }
            $data['code'] = $code;
        }
        if (array_key_exists('name', $input)) {
            $data['name'] = $this->normalizeName((string) $input['name']);
        }
        if (array_key_exists('status', $input)) {
            $data['status'] = $this->integer($input['status'], '状态') ? 1 : 0;
        }
        if (array_key_exists('sort', $input)) {
            $data['sort'] = max(0, $this->integer($input['sort'], '排序'));
        }
        $makeDefault = array_key_exists('is_default', $input) && !empty($input['is_default']);

        Db::table(self::LOCALE_TABLE)->where('id', $id)->where('organization', $organization)->update($data);
        if ($makeDefault) {
            $this->setDefaultLocale($organization, $id, $actorId);
        }

        return $this->localeRow($organization, $id);
    }

    /** @param list<int> $ids */
    public function localeDelete(int $organization, array $ids, int $actorId): int
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        if ($ids === []) {
            throw new ApiException('语言编号列表无效。', 422);
        }

        $now = date('Y-m-d H:i:s');
        $deleted = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $row = Db::table(self::LOCALE_TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->find();
            if ($row === null) {
                continue;
            }
            // 释放唯一键：软删除后改 code
            $suffix = '__del_' . $id;
            $code = substr((string) $row['code'], 0, max(1, 32 - strlen($suffix))) . $suffix;
            $n = Db::table(self::LOCALE_TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->update([
                    'code' => $code,
                    'is_default' => 0,
                    'status' => 0,
                    'delete_time' => $now,
                    'updated_by' => $actorId,
                    'update_time' => $now,
                ]);
            $deleted += (int) $n;
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{current_page: int, data: list<array<string, mixed>>, per_page: int, total: int}
     */
    public function entryList(int $organization, array $filters): array
    {
        $this->assertOrganization($organization, true);
        [$page, $limit] = $this->pagination($filters);
        $query = Db::table(self::ENTRY_TABLE)
            ->where('organization', $organization)
            ->whereNull('delete_time');

        $locale = trim((string) ($filters['locale_code'] ?? $filters['locale'] ?? ''));
        if ($locale !== '') {
            $query->where('locale_code', $this->normalizeCode($locale));
        }
        $key = trim((string) ($filters['msg_key'] ?? $filters['key'] ?? ''));
        if ($key !== '') {
            $query->whereLike('msg_key', '%' . addcslashes($key, '%_\\') . '%');
        }

        $total = (int) (clone $query)->count();
        $items = $query
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

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function entryCreate(int $organization, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        $locale = $this->normalizeCode((string) ($input['locale_code'] ?? $input['locale'] ?? ''));
        $key = $this->normalizeKey((string) ($input['msg_key'] ?? $input['key'] ?? ''));
        $value = $this->normalizeValue((string) ($input['msg_value'] ?? $input['value'] ?? ''));
        $this->assertLocaleAvailable($organization, $locale);

        if ($this->entryExists($organization, $locale, $key)) {
            throw new ApiException('词条键已存在。', 422);
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) Db::table(self::ENTRY_TABLE)->insertGetId([
            'organization' => $organization,
            'locale_code' => $locale,
            'msg_key' => $key,
            'msg_value' => $value,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => null,
        ]);

        return $this->entryRow($organization, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function entryUpdate(int $organization, int $id, array $input, int $actorId): array
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        $row = $this->entryRow($organization, $id);
        $data = ['updated_by' => $actorId, 'update_time' => date('Y-m-d H:i:s')];

        $locale = $row['locale_code'];
        $key = $row['msg_key'];
        if (array_key_exists('locale_code', $input) || array_key_exists('locale', $input)) {
            $locale = $this->normalizeCode((string) ($input['locale_code'] ?? $input['locale'] ?? ''));
            $this->assertLocaleAvailable($organization, $locale);
            $data['locale_code'] = $locale;
        }
        if (array_key_exists('msg_key', $input) || array_key_exists('key', $input)) {
            $key = $this->normalizeKey((string) ($input['msg_key'] ?? $input['key'] ?? ''));
            $data['msg_key'] = $key;
        }
        if (array_key_exists('msg_value', $input) || array_key_exists('value', $input)) {
            $data['msg_value'] = $this->normalizeValue((string) ($input['msg_value'] ?? $input['value'] ?? ''));
        }

        if (($locale !== $row['locale_code'] || $key !== $row['msg_key'])
            && $this->entryExists($organization, $locale, $key, $id)) {
            throw new ApiException('词条键已存在。', 422);
        }

        Db::table(self::ENTRY_TABLE)->where('id', $id)->where('organization', $organization)->update($data);

        return $this->entryRow($organization, $id);
    }

    /** @param list<int> $ids */
    public function entryDelete(int $organization, array $ids, int $actorId): int
    {
        $this->assertOrganization($organization, true);
        $this->assertActor($actorId);
        if ($ids === []) {
            throw new ApiException('词条编号列表无效。', 422);
        }
        $now = date('Y-m-d H:i:s');
        $deleted = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $row = Db::table(self::ENTRY_TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->find();
            if ($row === null) {
                continue;
            }
            $suffix = '__del_' . $id;
            $key = substr((string) $row['msg_key'], 0, max(1, 191 - strlen($suffix))) . $suffix;
            $n = Db::table(self::ENTRY_TABLE)
                ->where('id', $id)
                ->where('organization', $organization)
                ->whereNull('delete_time')
                ->update([
                    'msg_key' => $key,
                    'delete_time' => $now,
                    'updated_by' => $actorId,
                    'update_time' => $now,
                ]);
            $deleted += (int) $n;
        }

        return $deleted;
    }

    /** @return list<array{code: string, name: string, is_default: int}> */
    public function clientLocales(int $organization): array
    {
        $this->assertOrganization($organization, false);
        $tenant = Db::table(self::LOCALE_TABLE)
            ->where('organization', $organization)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if ($tenant !== []) {
            return array_map(static fn (array $row): array => [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'is_default' => (int) $row['is_default'],
            ], $tenant);
        }

        $platform = Db::table(self::LOCALE_TABLE)
            ->where('organization', 0)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'is_default' => (int) $row['is_default'],
        ], $platform);
    }

    /**
     * Merge platform defaults with tenant overrides for one locale.
     *
     * @return array{locale: string, messages: array<string, string>}
     */
    public function clientMessages(int $organization, string $locale): array
    {
        $this->assertOrganization($organization, false);
        $locale = $this->normalizeCode($locale);
        $available = array_column($this->clientLocales($organization), 'code');
        if ($available !== [] && !in_array($locale, $available, true)) {
            throw new ApiException('语言未启用。', 422);
        }

        $platform = Db::table(self::ENTRY_TABLE)
            ->where('organization', 0)
            ->where('locale_code', $locale)
            ->whereNull('delete_time')
            ->select()
            ->toArray();
        $messages = [];
        foreach ($platform as $row) {
            $messages[(string) $row['msg_key']] = (string) $row['msg_value'];
        }

        $tenant = Db::table(self::ENTRY_TABLE)
            ->where('organization', $organization)
            ->where('locale_code', $locale)
            ->whereNull('delete_time')
            ->select()
            ->toArray();
        foreach ($tenant as $row) {
            $messages[(string) $row['msg_key']] = (string) $row['msg_value'];
        }

        return [
            'locale' => $locale,
            'messages' => $messages,
        ];
    }

    private function setDefaultLocale(int $organization, int $id, int $actorId): void
    {
        $now = date('Y-m-d H:i:s');
        Db::table(self::LOCALE_TABLE)
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->update(['is_default' => 0, 'updated_by' => $actorId, 'update_time' => $now]);
        Db::table(self::LOCALE_TABLE)
            ->where('id', $id)
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->update(['is_default' => 1, 'status' => 1, 'updated_by' => $actorId, 'update_time' => $now]);
    }

    private function localeExists(int $organization, string $code, ?int $exceptId = null): bool
    {
        $q = Db::table(self::LOCALE_TABLE)
            ->where('organization', $organization)
            ->where('code', $code)
            ->whereNull('delete_time');
        if ($exceptId !== null) {
            $q->where('id', '<>', $exceptId);
        }

        return (int) $q->count() > 0;
    }

    private function entryExists(int $organization, string $locale, string $key, ?int $exceptId = null): bool
    {
        $q = Db::table(self::ENTRY_TABLE)
            ->where('organization', $organization)
            ->where('locale_code', $locale)
            ->where('msg_key', $key)
            ->whereNull('delete_time');
        if ($exceptId !== null) {
            $q->where('id', '<>', $exceptId);
        }

        return (int) $q->count() > 0;
    }

    private function assertLocaleAvailable(int $organization, string $locale): void
    {
        // Platform catalog always acceptable; tenant may also define its own.
        $exists = Db::table(self::LOCALE_TABLE)
            ->whereIn('organization', [0, $organization])
            ->where('code', $locale)
            ->whereNull('delete_time')
            ->count();
        if ((int) $exists <= 0) {
            throw new ApiException('语言不存在，请先创建语言。', 422);
        }
    }

    /** @return array<string, mixed> */
    private function localeRow(int $organization, int $id): array
    {
        $row = Db::table(self::LOCALE_TABLE)
            ->where('id', $id)
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->find();
        if ($row === null) {
            throw new ApiException('语言不存在。', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function entryRow(int $organization, int $id): array
    {
        $row = Db::table(self::ENTRY_TABLE)
            ->where('id', $id)
            ->where('organization', $organization)
            ->whereNull('delete_time')
            ->find();
        if ($row === null) {
            throw new ApiException('词条不存在。', 404);
        }

        return $row;
    }

    private function normalizeCode(string $code): string
    {
        $code = trim($code);
        if ($code === '' || !preg_match(self::CODE_PATTERN, $code)) {
            throw new ApiException('语言代码无效。', 422);
        }

        return $code;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ApiException('语言名称无效。', 422);
        }

        return $name;
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || !preg_match(self::KEY_PATTERN, $key)) {
            throw new ApiException('词条键无效。', 422);
        }

        return $key;
    }

    private function normalizeValue(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 5000) {
            throw new ApiException('词条值无效。', 422);
        }

        return $value;
    }

    private function assertOrganization(int $organization, bool $allowPlatform): void
    {
        if ($organization < 0 || (!$allowPlatform && $organization <= 0)) {
            throw new ApiException('机构编号无效。', 422);
        }
        if (!$allowPlatform && $organization <= 0) {
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
        if (is_bool($value)) {
            return $value ? 1 : 0;
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
