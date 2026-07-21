<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CloseTenantRegistrationByDefault extends AbstractMigration
{
    // Exact-shape migration: validate before every DDL statement.
    private const TABLE = 'sm_tenant_account_policy';
    private const MAX_SAFE_VERSION = 9007199254740991;

    public function up(): void
    {
        $this->closeAndAssert();
    }

    public function down(): void
    {
        $this->closeAndAssert();
    }

    private function closeAndAssert(): void
    {
        $this->assertExactShape(true);
        $before = $this->policyVersions();
        foreach ($before as $id => $row) {
            if ($row['version'] < 1 || $row['version'] > self::MAX_SAFE_VERSION) {
                throw new RuntimeException("Policy {$id} has an invalid version.");
            }
            if ($row['register_enabled'] !== 0 && $row['version'] === self::MAX_SAFE_VERSION) {
                throw new RuntimeException("Policy {$id} version cannot be incremented.");
            }
        }
        $comment = $this->getAdapter()->getConnection()->quote(
            hex2bin('e698afe590a6e5bc80e694bee6b3a8e5868c'),
        );
        $this->execute(
            'ALTER TABLE `' . self::TABLE . '` MODIFY COLUMN `register_enabled`'
            . " tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT {$comment}",
        );
        $this->execute(
            'UPDATE `' . self::TABLE . '` SET `register_enabled`=0,'
            . ' `version`=`version`+1,`update_time`=CURRENT_TIMESTAMP'
            . ' WHERE `register_enabled`<>0',
        );
        $this->assertExactShape(false);
        $after = $this->policyVersions();
        if (array_keys($after) !== array_keys($before)) {
            throw new RuntimeException('Policy rows changed during migration.');
        }
        foreach ($before as $id => $row) {
            $version = $row['version'] + ($row['register_enabled'] === 0 ? 0 : 1);
            if ($after[$id]['register_enabled'] !== 0 || $after[$id]['version'] !== $version) {
                throw new RuntimeException("Policy {$id} failed the postcondition.");
            }
        }
    }

    /** @return array<int, array{register_enabled:int,version:int}> */
    private function policyVersions(): array
    {
        $rows = $this->fetchAll(
            'SELECT `id`,`register_enabled`,CAST(`version` AS CHAR) AS version_text'
            . ' FROM `' . self::TABLE . '` ORDER BY `id`',
        );
        $result = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $version = (string) ($row['version_text'] ?? '');
            if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1
                || self::compareUnsignedDecimal($id, (string) PHP_INT_MAX) > 0
                || preg_match('/^[0-9]+$/D', $version) !== 1
                || self::compareUnsignedDecimal($version, (string) self::MAX_SAFE_VERSION) > 0) {
                throw new RuntimeException('Policy has an unsupported numeric value.');
            }
            $intId = (int) $id;
            if (isset($result[$intId])) {
                throw new RuntimeException('Policy row identifiers are not unique.');
            }
            $result[$intId] = [
                'register_enabled' => (int) ($row['register_enabled'] ?? 0),
                'version' => (int) $version,
            ];
        }
        return $result;
    }

    private static function compareUnsignedDecimal(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function assertExactShape(bool $allowLegacyRegisterDefault): void
    {
        $this->assertTableShape();
        $this->assertColumnShape($allowLegacyRegisterDefault);
        $this->assertIndexShape();
        $this->assertConstraintShape();
        $count = $this->fetchRow(
            "SELECT COUNT(*) AS c FROM information_schema.TRIGGERS"
            . " WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='" . self::TABLE . "'",
        );
        if ((int) ($count['c'] ?? -1) !== 0) {
            throw new RuntimeException('Policy table must not have triggers.');
        }
    }

    private function assertTableShape(): void
    {
        $rows = $this->fetchAll(
            "SELECT ENGINE,TABLE_COLLATION,ROW_FORMAT,HEX(TABLE_COMMENT) AS comment_hex"
            . " FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='" . self::TABLE . "'",
        );
        $row = $rows[0] ?? [];
        if (count($rows) !== 1 || strtoupper((string) ($row['ENGINE'] ?? '')) !== 'INNODB'
            || strtolower((string) ($row['TABLE_COLLATION'] ?? '')) !== 'utf8mb4_general_ci'
            || strtoupper((string) ($row['ROW_FORMAT'] ?? '')) !== 'DYNAMIC'
            || strtolower((string) ($row['comment_hex'] ?? '')) !== 'e7a79fe688b7e8b4a6e58fb7e58786e585a5e7ad96e795a5') {
            throw new RuntimeException('Policy table shape drift detected.');
        }
    }

    private function assertColumnShape(bool $allowLegacyRegisterDefault): void
    {
        $rows = $this->fetchAll(
            "SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,"
            . "COLLATION_NAME,HEX(COLUMN_COMMENT) AS comment_hex FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . self::TABLE . "' ORDER BY ORDINAL_POSITION",
        );
        $actual = [];
        foreach ($rows as $row) {
            $actual[] = implode('|', [
                $row['COLUMN_NAME'], strtolower((string) $row['COLUMN_TYPE']), $row['IS_NULLABLE'],
                $row['COLUMN_DEFAULT'] === null ? '<NULL>' : $row['COLUMN_DEFAULT'],
                strtolower((string) $row['EXTRA']),
                $row['COLLATION_NAME'] === null ? '<NULL>' : strtolower((string) $row['COLLATION_NAME']),
                strtolower((string) $row['comment_hex']),
            ]);
        }
        $expected = [
            'id|bigint unsigned|NO|<NULL>|auto_increment|<NULL>|e4b8bbe994ae',
            'organization|int unsigned|NO|<NULL>||<NULL>|e69cbae69e84e7bc96e58fb7',
            'register_enabled|tinyint unsigned|NO|0||<NULL>|e698afe590a6e5bc80e694bee6b3a8e5868c',
            'invite_required|tinyint unsigned|NO|0||<NULL>|e698afe590a6e8a681e6b182e98280e8afb7e7a081',
            'tenant_invite_enabled|tinyint unsigned|NO|0||<NULL>|e698afe590a6e590afe794a8e69cbae69e84e98280e8afb7e7a081',
            'user_invite_enabled|tinyint unsigned|NO|0||<NULL>|e698afe590a6e590afe794a8e794a8e688b7e98280e8afb7e7a081',
            'email_verify_enabled|tinyint unsigned|NO|0||<NULL>|e698afe590a6e8a681e6b182e982aee7aeb1e9aa8ce8af81',
            'mobile_verify_enabled|tinyint unsigned|NO|0||<NULL>|e698afe590a6e8a681e6b182e6898be69cbae9aa8ce8af81',
            'email_provider_config_id|bigint unsigned|YES|<NULL>||<NULL>|e982aee4bbb6e69c8de58aa1e9858de7bdaee7bc96e58fb7',
            'sms_provider_config_id|bigint unsigned|YES|<NULL>||<NULL>|e79fade4bfa1e69c8de58aa1e9858de7bdaee7bc96e58fb7',
            'realname_required|tinyint unsigned|NO|0||<NULL>|e698afe590a6e8a681e6b182e5ae9ee5908d',
            'invite_code_mode|varchar(24)|NO|tenant_single||utf8mb4_general_ci|e98280e8afb7e7a081e6a8a1e5bc8f',
            'invite_auto_friend|tinyint unsigned|NO|0||<NULL>|e98280e8afb7e5908ee698afe590a6e887aae58aa8e58aa0e5a5bde58f8b',
            'invite_bind_customer_service|tinyint unsigned|NO|0||<NULL>|e98280e8afb7e5908ee698afe590a6e7bb91e5ae9ae5aea2e69c8d',
            'status|varchar(16)|NO|ENABLED||utf8mb4_general_ci|e7ad96e795a5e78ab6e68081',
            'version|bigint unsigned|NO|1||<NULL>|e4b990e8a782e99481e78988e69cac',
            'create_time|datetime|NO|<NULL>||<NULL>|e5889be5bbbae697b6e997b4',
            'update_time|datetime|NO|<NULL>||<NULL>|e69bb4e696b0e697b6e997b4',
        ];
        if ($allowLegacyRegisterDefault && isset($actual[2])) {
            $expected[2] = preg_replace('/\\|0\\|/', '|' . explode('|', $actual[2])[3] . '|', $expected[2], 1);
        }
        if ($actual !== $expected || !in_array(explode('|', $actual[2] ?? '')[3] ?? '', ['0', '1'], true)) {
            throw new RuntimeException('Policy column shape drift detected.');
        }
    }

    private function assertIndexShape(): void
    {
        $rows = $this->fetchAll(
            "SELECT INDEX_NAME,NON_UNIQUE,INDEX_TYPE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols"
            . " FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='" . self::TABLE . "' GROUP BY INDEX_NAME,NON_UNIQUE,INDEX_TYPE ORDER BY INDEX_NAME",
        );
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row['INDEX_NAME']] = implode('|', [
                $row['NON_UNIQUE'], $row['INDEX_TYPE'], $row['cols'],
            ]);
        }
        $expected = [
            'PRIMARY' => '0|BTREE|id',
            'idx_tenant_account_policy_status' => '1|BTREE|status',
            'uk_tenant_account_policy_organization' => '0|BTREE|organization',
        ];
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Policy index shape drift detected.');
        }
    }

    private function assertConstraintShape(): void
    {
        $rows = $this->fetchAll(
            "SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS"
            . " WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='" . self::TABLE . "' ORDER BY CONSTRAINT_NAME",
        );
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row['CONSTRAINT_NAME']] = (string) $row['CONSTRAINT_TYPE'];
        }
        $expected = [
            'PRIMARY' => 'PRIMARY KEY',
            'uk_tenant_account_policy_organization' => 'UNIQUE',
        ];
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Policy constraint shape drift detected.');
        }
    }
}
