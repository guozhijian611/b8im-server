<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedTenantAccountPolicyPermissions extends AbstractMigration
{
    private const PAGE_CODE = 'system/account-policy';
    private const READ_SLUG = 'saimulti:tenant:account:policy:read';
    private const UPDATE_SLUG = 'saimulti:tenant:account:policy:update';

    public function up(): void
    {
        $connection = $this->getAdapter()->getConnection();
        $started = !$connection->inTransaction();
        if ($started) {
            $connection->beginTransaction();
        }
        try {
        $roots = $this->fetchAll(
            "SELECT id FROM sm_tenant_menu WHERE organization=0 AND code='system'"
            . ' AND type=1 AND status=1 AND delete_time IS NULL ORDER BY id',
        );
        if (count($roots) !== 1) {
            throw new RuntimeException('Active organization-0 system root must exist exactly once.');
        }
        $pageId = $this->upsertPage((int) $roots[0]['id']);
        $ids = [
            $pageId,
            $this->upsertButton($pageId, hex2bin('e8afbbe58f96e8b4a6e58fb7e6b3a8e5868ce7ad96e795a5'), self::READ_SLUG),
            $this->upsertButton($pageId, hex2bin('e69bb4e696b0e8b4a6e58fb7e6b3a8e5868ce7ad96e795a5'), self::UPDATE_SLUG),
        ];
        foreach ($ids as $menuId) {
            $this->execute(sprintf(
                'INSERT INTO sm_tenant_group_menu (group_id,menu_id) '
                . 'SELECT g.id,%d FROM sm_tenant_group g WHERE g.status=1 AND g.delete_time IS NULL '
                . 'AND NOT EXISTS (SELECT 1 FROM sm_tenant_group_menu gm WHERE gm.group_id=g.id AND gm.menu_id=%d)',
                $menuId, $menuId,
            ));
        }
        $this->assertSeed($pageId);
            if ($started) {
                $connection->commit();
            }
        } catch (Throwable $throwable) {
            if ($started && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
    }

    public function down(): void
    {
        $connection = $this->getAdapter()->getConnection();
        $started = !$connection->inTransaction();
        if ($started) {
            $connection->beginTransaction();
        }
        try {
        $pages = $this->activeRows('code', self::PAGE_CODE);
        if (count($pages) > 1) {
            throw new RuntimeException('Account policy page is duplicated.');
        }
        if ($pages === []) {
            if ($started) {
                $connection->commit();
            }
            return;
        }
        $pageId = (int) $pages[0]['id'];
        $children = $this->fetchAll(sprintf(
            'SELECT id,slug FROM sm_tenant_menu WHERE organization=0 AND parent_id=%d'
            . ' AND delete_time IS NULL ORDER BY id',
            $pageId,
        ));
        foreach ($children as $child) {
            if (!in_array((string) $child['slug'], [self::READ_SLUG, self::UPDATE_SLUG], true)) {
                throw new RuntimeException('Account policy page has an unowned child.');
            }
        }
        $ids = array_merge([$pageId], array_map(
            static fn (array $row): int => (int) $row['id'], $children,
        ));
        $list = implode(',', $ids);
        $this->execute("DELETE FROM sm_tenant_role_menu WHERE menu_id IN ({$list})");
        $this->execute("DELETE FROM sm_tenant_group_menu WHERE menu_id IN ({$list})");
        if ($children !== []) {
            $this->execute('DELETE FROM sm_tenant_menu WHERE id IN (' . implode(',', array_slice($ids, 1)) . ')');
        }
        $this->execute("DELETE FROM sm_tenant_menu WHERE id={$pageId}");
            if ($started) {
                $connection->commit();
            }
        } catch (Throwable $throwable) {
            if ($started && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $throwable;
        }
    }

    /** @return list<array<string,mixed>> */
    private function activeRows(string $field, string $value): array
    {
        if (!in_array($field, ['code', 'slug'], true)) {
            throw new RuntimeException('Unsupported tenant menu lookup field.');
        }
        return $this->fetchAll(sprintf(
            'SELECT * FROM sm_tenant_menu WHERE organization=0 AND `%s`=%s AND delete_time IS NULL ORDER BY id',
            $field, $this->quote($value),
        ));
    }

    private function upsertPage(int $rootId): int
    {
        $rows = $this->activeRows('code', self::PAGE_CODE);
        if (count($rows) > 1) {
            throw new RuntimeException('Account policy page is duplicated.');
        }
        $payload = [
            'parent_id' => $rootId,
            'name' => hex2bin('e8b4a6e58fb7e6b3a8e5868ce7ad96e795a5'),
            'code' => self::PAGE_CODE,
            'slug' => null, 'module_key' => null, 'type' => 2,
            'path' => 'account-policy',
            'component' => '/system/account-policy/index',
            'method' => null, 'icon' => 'ri:user-settings-line', 'sort' => 70,
            'link_url' => null, 'is_iframe' => 2, 'is_keep_alive' => 2,
            'is_hidden' => 2, 'is_fixed_tab' => 2, 'is_full_page' => 2,
            'generate_id' => 0, 'generate_key' => null,
            'status' => 1, 'remark' => null, 'delete_time' => null,
        ];
        return $this->saveMenu($rows, $payload);
    }

    private function upsertButton(int $pageId, string $name, string $slug): int
    {
        $rows = $this->activeRows('slug', $slug);
        if (count($rows) > 1) {
            throw new RuntimeException("Permission {$slug} is duplicated.");
        }
        return $this->saveMenu($rows, [
            'parent_id' => $pageId, 'name' => $name,
            'code' => null, 'slug' => $slug, 'module_key' => null,
            'type' => 3, 'path' => null, 'component' => null, 'method' => null,
            'icon' => null, 'sort' => 0, 'link_url' => null,
            'is_iframe' => 2, 'is_keep_alive' => 2, 'is_hidden' => 1,
            'is_fixed_tab' => 2, 'is_full_page' => 2,
            'generate_id' => 0, 'generate_key' => null,
            'status' => 1, 'remark' => null, 'delete_time' => null,
        ]);
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $payload */
    private function saveMenu(array $rows, array $payload): int
    {
        $payload['organization'] = 0;
        $payload['update_time'] = date('Y-m-d H:i:s');
        if ($rows !== []) {
            $id = (int) $rows[0]['id'];
            $sets = [];
            foreach ($payload as $field => $value) {
                $sets[] = "`{$field}`=" . $this->sqlValue($value);
            }
            $this->execute('UPDATE sm_tenant_menu SET ' . implode(',', $sets) . " WHERE id={$id}");
            return $id;
        }
        $payload['create_time'] = $payload['update_time'];
        $this->table('sm_tenant_menu')->insert($payload)->saveData();
        $field = $payload['code'] === null ? 'slug' : 'code';
        $value = (string) $payload[$field];
        $created = $this->activeRows($field, $value);
        if (count($created) !== 1) {
            throw new RuntimeException('Account policy menu creation failed.');
        }
        return (int) $created[0]['id'];
    }

    private function assertSeed(int $pageId): void
    {
        $page = $this->fetchRow("SELECT * FROM sm_tenant_menu WHERE id={$pageId}");
        if (!$page || (int) $page['organization'] !== 0 || (int) $page['type'] !== 2
            || (string) $page['name'] !== hex2bin('e8b4a6e58fb7e6b3a8e5868ce7ad96e795a5')
            || (string) $page['code'] !== self::PAGE_CODE || $page['slug'] !== null
            || (string) $page['path'] !== 'account-policy'
            || (string) $page['component'] !== '/system/account-policy/index'
            || (int) $page['is_hidden'] !== 2 || (int) $page['status'] !== 1) {
            throw new RuntimeException('Account policy page postcondition failed.');
        }
        $root = $this->fetchRow(
            "SELECT id FROM sm_tenant_menu WHERE organization=0 AND code='system'"
            . ' AND type=1 AND status=1 AND delete_time IS NULL',
        );
        if (!$root) {
            throw new RuntimeException('Account policy root postcondition failed.');
        }
        $this->assertMenuFields($page, [
            'organization' => 0, 'parent_id' => (int) $root['id'],
            'name' => hex2bin('e8b4a6e58fb7e6b3a8e5868ce7ad96e795a5'),
            'code' => self::PAGE_CODE, 'slug' => null, 'module_key' => null,
            'type' => 2, 'path' => 'account-policy',
            'component' => '/system/account-policy/index', 'method' => null,
            'icon' => 'ri:user-settings-line', 'sort' => 70, 'link_url' => null,
            'is_iframe' => 2, 'is_keep_alive' => 2, 'is_hidden' => 2,
            'is_fixed_tab' => 2, 'is_full_page' => 2,
            'generate_id' => 0, 'generate_key' => null, 'status' => 1,
            'remark' => null, 'delete_time' => null,
        ], 'Account policy page');
        $groupCount = (int) $this->fetchRow(
            'SELECT COUNT(*) AS c FROM sm_tenant_group WHERE status=1 AND delete_time IS NULL',
        )['c'];
        $this->assertGroupMappings($pageId, $groupCount);
        $permissions = [
            self::READ_SLUG => hex2bin('e8afbbe58f96e8b4a6e58fb7e6b3a8e5868ce7ad96e795a5'),
            self::UPDATE_SLUG => hex2bin('e69bb4e696b0e8b4a6e58fb7e6b3a8e5868ce7ad96e795a5'),
        ];
        foreach ($permissions as $slug => $name) {
            $rows = $this->activeRows('slug', $slug);
            if (count($rows) !== 1 || (int) $rows[0]['parent_id'] !== $pageId
                || (int) $rows[0]['type'] !== 3 || (int) $rows[0]['is_hidden'] !== 1
                || (int) $rows[0]['status'] !== 1) {
                throw new RuntimeException("Permission {$slug} postcondition failed.");
            }
            $this->assertMenuFields($rows[0], [
                'organization' => 0, 'parent_id' => $pageId, 'name' => $name,
                'code' => null, 'slug' => $slug, 'module_key' => null,
                'type' => 3, 'path' => null, 'component' => null, 'method' => null,
                'icon' => null, 'sort' => 0, 'link_url' => null,
                'is_iframe' => 2, 'is_keep_alive' => 2, 'is_hidden' => 1,
                'is_fixed_tab' => 2, 'is_full_page' => 2,
                'generate_id' => 0, 'generate_key' => null, 'status' => 1,
                'remark' => null, 'delete_time' => null,
            ], "Permission {$slug}");
            $this->assertGroupMappings((int) $rows[0]['id'], $groupCount);
        }
    }

    private function assertGroupMappings(int $menuId, int $expected): void
    {
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS c,COUNT(DISTINCT gm.group_id) AS distinct_c'
            . ' FROM sm_tenant_group_menu gm JOIN sm_tenant_group g ON g.id=gm.group_id'
            . " WHERE gm.menu_id={$menuId} AND g.status=1 AND g.delete_time IS NULL",
        );
        if ((int) ($row['c'] ?? -1) !== $expected || (int) ($row['distinct_c'] ?? -1) !== $expected) {
            throw new RuntimeException("Menu {$menuId} group mapping postcondition failed.");
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $expected */
    private function assertMenuFields(array $row, array $expected, string $label): void
    {
        foreach ($expected as $field => $value) {
            $actual = $row[$field] ?? null;
            if (is_int($value)) {
                $actual = (int) $actual;
            }
            if ($actual !== $value) {
                throw new RuntimeException("{$label} field {$field} postcondition failed.");
            }
        }
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        return $this->quote((string) $value);
    }
}
