<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class CreateModuleExpiryHookTask extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('sm_module_expiry_hook_task')) {
            $this->assertExactShape();
            return;
        }

        $this->table('sm_module_expiry_hook_task', [
            'id' => false,
            'primary_key' => ['id'],
            'comment' => '授权到期 hook stable credential 与 durable receipt 任务',
            'collation' => 'utf8mb4_general_ci',
        ])
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('license_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('organization', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('module_key', 'string', [
                'limit' => 64,
                'null' => false,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('expired_version', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('from_status', 'string', [
                'limit' => 32,
                'null' => false,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('expire_at', 'datetime', ['null' => false])
            ->addColumn('hook_kind', 'string', [
                'limit' => 16,
                'null' => false,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('hook_module_version', 'string', [
                'limit' => 64,
                'null' => false,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('hook_handler', 'string', [
                'limit' => 300,
                'null' => false,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('hook_scope', 'string', [
                'limit' => 16,
                'null' => false,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('hook_transactional', 'boolean', ['null' => false])
            ->addColumn('hook_contract_json', 'text', [
                'limit' => MysqlAdapter::TEXT_MEDIUM,
                'null' => false,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('idempotency_key', 'char', [
                'limit' => 64,
                'null' => false,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('request_digest', 'char', [
                'limit' => 64,
                'null' => false,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('status', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'pending',
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('attempt_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('worker_token', 'char', [
                'limit' => 40,
                'null' => true,
                'collation' => 'utf8mb4_bin',
            ])
            ->addColumn('locked_until', 'datetime', ['null' => true])
            ->addColumn('next_retry_at', 'datetime', ['null' => true])
            ->addColumn('last_error', 'text', ['null' => true])
            ->addColumn('receipt_json', 'text', ['null' => true])
            ->addColumn('receipt_recorded_at', 'datetime', ['null' => true])
            ->addColumn('outcome_audit_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('create_time', 'datetime', ['null' => false])
            ->addColumn('update_time', 'datetime', ['null' => false])
            ->addColumn('finished_at', 'datetime', ['null' => true])
            ->addIndex(['license_id', 'expired_version'], [
                'unique' => true,
                'name' => 'uk_expiry_license_version',
            ])
            ->addIndex(['idempotency_key'], [
                'unique' => true,
                'name' => 'uk_expiry_idempotency_key',
            ])
            ->addIndex(['status', 'next_retry_at', 'locked_until', 'id'], [
                'name' => 'idx_expiry_task_claim',
            ])
            ->addIndex(['organization', 'module_key', 'id'], [
                'name' => 'idx_expiry_task_scope',
            ])
            ->create();
        $this->execute(<<<'SQL'
ALTER TABLE sm_module_expiry_hook_task
  ADD CONSTRAINT chk_expiry_task_identity CHECK (
    license_id > 0 AND organization > 0 AND expired_version > 0
    AND module_key REGEXP '^[a-z][a-z0-9_]{1,63}$'
    AND from_status = 'ENABLED'
    AND hook_kind IN ('transactional','external')
    AND CHAR_LENGTH(hook_module_version) >= 1
    AND CHAR_LENGTH(hook_module_version) <= 64
    AND hook_module_version REGEXP '^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\\.[0-9A-Za-z-]+)*)?(\\+[0-9A-Za-z-]+(\\.[0-9A-Za-z-]+)*)?$'
    AND CHAR_LENGTH(hook_handler) >= 3
    AND CHAR_LENGTH(hook_handler) <= 300
    AND hook_handler REGEXP '^[A-Za-z_][A-Za-z0-9_\\\\]*::[a-z][a-zA-Z0-9_]*$'
    AND hook_scope IN ('tenant','system','both')
    AND hook_transactional IN (0,1)
    AND JSON_VALID(hook_contract_json)
    AND idempotency_key REGEXP '^[0-9a-f]{64}$'
    AND request_digest REGEXP '^[0-9a-f]{64}$'
  ),
  ADD CONSTRAINT chk_expiry_task_status CHECK (
    status IN ('pending','processing','retry','succeeded','superseded','contract_failed')
  ),
  ADD CONSTRAINT chk_expiry_task_state CHECK (
    (
      status = 'pending' AND attempt_count = 0
      AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
      AND last_error IS NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
      AND outcome_audit_id IS NULL AND finished_at IS NULL
    ) OR (
      status = 'processing' AND attempt_count > 0
      AND worker_token IS NOT NULL
      AND worker_token REGEXP '^[0-9a-f]{40}$'
      AND locked_until IS NOT NULL AND next_retry_at IS NULL
      AND receipt_json IS NULL AND receipt_recorded_at IS NULL
      AND outcome_audit_id IS NULL AND finished_at IS NULL
    ) OR (
      status = 'retry' AND attempt_count > 0
      AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NOT NULL
      AND last_error IS NOT NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
      AND outcome_audit_id IS NULL AND finished_at IS NULL
    ) OR (
      status = 'succeeded' AND attempt_count > 0
      AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
      AND last_error IS NULL AND receipt_json IS NOT NULL AND receipt_recorded_at IS NOT NULL
      AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL
    ) OR (
      status = 'superseded' AND attempt_count > 0
      AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
      AND last_error IS NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
      AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL
    ) OR (
      status = 'contract_failed' AND attempt_count > 0
      AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
      AND last_error IS NOT NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
      AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL
    )
  )
SQL);
        $this->assertExactShape();
    }

    public function down(): void
    {
        if ($this->hasTable('sm_module_expiry_hook_task')) {
            $this->assertExactShape();
            $this->table('sm_module_expiry_hook_task')->drop()->save();
        }
    }

    private function assertExactShape(): void
    {
        $tableRows = $this->fetchAll(
            "SELECT ENGINE,TABLE_COLLATION,TABLE_COMMENT
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_module_expiry_hook_task'",
        );
        if (count($tableRows) !== 1
            || strtoupper((string) ($tableRows[0]['ENGINE'] ?? '')) !== 'INNODB'
            || strtolower((string) ($tableRows[0]['TABLE_COLLATION'] ?? '')) !== 'utf8mb4_general_ci'
            || (string) ($tableRows[0]['TABLE_COMMENT'] ?? '')
                !== '授权到期 hook stable credential 与 durable receipt 任务') {
            throw new RuntimeException('sm_module_expiry_hook_task table shape drift detected.');
        }
        $columns = $this->fetchAll(
            "SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_module_expiry_hook_task'
           ORDER BY ORDINAL_POSITION",
        );
        $actualColumns = [];
        foreach ($columns as $column) {
            $actualColumns[(string) $column['COLUMN_NAME']] = [
                strtolower((string) $column['COLUMN_TYPE']),
                (string) $column['IS_NULLABLE'],
                $column['COLUMN_DEFAULT'],
                strtolower((string) $column['EXTRA']),
                $column['COLLATION_NAME'] === null ? null : strtolower((string) $column['COLLATION_NAME']),
            ];
        }
        $expectedColumns = [
            'id' => ['bigint unsigned', 'NO', null, 'auto_increment', null],
            'license_id' => ['int unsigned', 'NO', null, '', null],
            'organization' => ['int unsigned', 'NO', null, '', null],
            'module_key' => ['varchar(64)', 'NO', null, '', 'utf8mb4_bin'],
            'expired_version' => ['int unsigned', 'NO', null, '', null],
            'from_status' => ['varchar(32)', 'NO', null, '', 'utf8mb4_bin'],
            'expire_at' => ['datetime', 'NO', null, '', null],
            'hook_kind' => ['varchar(16)', 'NO', null, '', 'utf8mb4_bin'],
            'hook_module_version' => ['varchar(64)', 'NO', null, '', 'utf8mb4_bin'],
            'hook_handler' => ['varchar(300)', 'NO', null, '', 'utf8mb4_bin'],
            'hook_scope' => ['varchar(16)', 'NO', null, '', 'utf8mb4_bin'],
            'hook_transactional' => ['tinyint(1)', 'NO', null, '', null],
            'hook_contract_json' => ['mediumtext', 'NO', null, '', 'utf8mb4_bin'],
            'idempotency_key' => ['char(64)', 'NO', null, '', 'utf8mb4_bin'],
            'request_digest' => ['char(64)', 'NO', null, '', 'utf8mb4_bin'],
            'status' => ['varchar(20)', 'NO', 'pending', '', 'utf8mb4_bin'],
            'attempt_count' => ['int unsigned', 'NO', '0', '', null],
            'worker_token' => ['char(40)', 'YES', null, '', 'utf8mb4_bin'],
            'locked_until' => ['datetime', 'YES', null, '', null],
            'next_retry_at' => ['datetime', 'YES', null, '', null],
            'last_error' => ['text', 'YES', null, '', 'utf8mb4_general_ci'],
            'receipt_json' => ['text', 'YES', null, '', 'utf8mb4_general_ci'],
            'receipt_recorded_at' => ['datetime', 'YES', null, '', null],
            'outcome_audit_id' => ['bigint unsigned', 'YES', null, '', null],
            'create_time' => ['datetime', 'NO', null, '', null],
            'update_time' => ['datetime', 'NO', null, '', null],
            'finished_at' => ['datetime', 'YES', null, '', null],
        ];
        if ($actualColumns !== $expectedColumns) {
            throw new RuntimeException('sm_module_expiry_hook_task column shape drift detected.');
        }

        $indexRows = $this->fetchAll(
            "SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sm_module_expiry_hook_task'
           GROUP BY INDEX_NAME,NON_UNIQUE ORDER BY INDEX_NAME",
        );
        $actualIndexes = [];
        foreach ($indexRows as $index) {
            $actualIndexes[(string) $index['INDEX_NAME']] = [
                (int) $index['NON_UNIQUE'],
                (string) $index['columns_list'],
            ];
        }
        ksort($actualIndexes);
        $expectedIndexes = [
            'PRIMARY' => [0, 'id'],
            'idx_expiry_task_claim' => [1, 'status,next_retry_at,locked_until,id'],
            'idx_expiry_task_scope' => [1, 'organization,module_key,id'],
            'uk_expiry_idempotency_key' => [0, 'idempotency_key'],
            'uk_expiry_license_version' => [0, 'license_id,expired_version'],
        ];
        ksort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) {
            throw new RuntimeException('sm_module_expiry_hook_task index shape drift detected.');
        }

        $checks = $this->fetchAll(
            "SELECT tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE,tc.ENFORCED
               FROM information_schema.TABLE_CONSTRAINTS tc
               JOIN information_schema.CHECK_CONSTRAINTS cc
                 ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA
                AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME
              WHERE tc.CONSTRAINT_SCHEMA=DATABASE()
                AND tc.TABLE_NAME='sm_module_expiry_hook_task'
                AND tc.CONSTRAINT_TYPE='CHECK'
           ORDER BY tc.CONSTRAINT_NAME",
        );
        $expectedChecks = [
            'chk_expiry_task_identity' => <<<'SQL'
license_id > 0 AND organization > 0 AND expired_version > 0
AND module_key REGEXP '^[a-z][a-z0-9_]{1,63}$'
AND from_status = 'ENABLED'
AND hook_kind IN ('transactional','external')
AND CHAR_LENGTH(hook_module_version) >= 1
AND CHAR_LENGTH(hook_module_version) <= 64
AND hook_module_version REGEXP '^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\\.[0-9A-Za-z-]+)*)?(\\+[0-9A-Za-z-]+(\\.[0-9A-Za-z-]+)*)?$'
AND CHAR_LENGTH(hook_handler) >= 3
AND CHAR_LENGTH(hook_handler) <= 300
AND hook_handler REGEXP '^[A-Za-z_][A-Za-z0-9_\\\\]*::[a-z][a-zA-Z0-9_]*$'
AND hook_scope IN ('tenant','system','both')
AND hook_transactional IN (0,1)
AND JSON_VALID(hook_contract_json)
AND idempotency_key REGEXP '^[0-9a-f]{64}$'
AND request_digest REGEXP '^[0-9a-f]{64}$'
SQL,
            'chk_expiry_task_state' => <<<'SQL'
(status = 'pending' AND attempt_count = 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NULL AND finished_at IS NULL)
OR (status = 'processing' AND attempt_count > 0
 AND worker_token IS NOT NULL AND worker_token REGEXP '^[0-9a-f]{40}$'
 AND locked_until IS NOT NULL AND next_retry_at IS NULL
 AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NULL AND finished_at IS NULL)
OR (status = 'retry' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NOT NULL
 AND last_error IS NOT NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NULL AND finished_at IS NULL)
OR (status = 'succeeded' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NULL AND receipt_json IS NOT NULL AND receipt_recorded_at IS NOT NULL
 AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL)
OR (status = 'superseded' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL)
OR (status = 'contract_failed' AND attempt_count > 0
 AND worker_token IS NULL AND locked_until IS NULL AND next_retry_at IS NULL
 AND last_error IS NOT NULL AND receipt_json IS NULL AND receipt_recorded_at IS NULL
 AND outcome_audit_id IS NOT NULL AND outcome_audit_id > 0 AND finished_at IS NOT NULL)
SQL,
            'chk_expiry_task_status' => <<<'SQL'
status IN ('pending','processing','retry','succeeded','superseded','contract_failed')
SQL,
        ];
        if (array_column($checks, 'CONSTRAINT_NAME') !== array_keys($expectedChecks)) {
            throw new RuntimeException('sm_module_expiry_hook_task CHECK shape drift detected.');
        }
        foreach ($checks as $check) {
            $name = (string) $check['CONSTRAINT_NAME'];
            $actualClause = $this->normalizeCheck((string) ($check['CHECK_CLAUSE'] ?? ''));
            $expectedClause = $this->normalizeCheck($expectedChecks[$name]);
            if ((string) ($check['ENFORCED'] ?? '') !== 'YES' || $actualClause !== $expectedClause) {
                throw new RuntimeException(sprintf(
                    'sm_module_expiry_hook_task CHECK definition drift detected: %s actual=%s expected=%s',
                    $name,
                    $actualClause,
                    $expectedClause,
                ));
            }
        }
    }

    private function normalizeCheck(string $clause): string
    {
        $clause = $this->replaceCheckStringLiterals($clause);
        $literalPattern = "\x1fl[0-9a-f]*\x1f";
        $clause = preg_replace_callback(
            "/regexp_like\s*\(\s*[\x60]?([a-z0-9_]+)[\x60]?\s*,\s*($literalPattern)"
            . "\s*(?:,\s*\x1fl63\x1f)?\s*\)/i",
            static fn (array $match): string => $match[1] . ' regexp ' . $match[2],
            $clause,
        ) ?? $clause;
        return $this->serializeCheckNode($this->parseCheckExpression($clause));
    }

    private function replaceCheckStringLiterals(string $expression): string
    {
        if (str_contains($expression, "\x1f")) {
            throw new RuntimeException('CHECK expression contains a reserved normalization byte.');
        }

        $normalized = '';
        $length = strlen($expression);
        for ($offset = 0; $offset < $length;) {
            $openingOffset = null;
            $metadata = false;
            $introducer = null;
            if ($expression[$offset] === '_'
                && preg_match('/\A_[a-z0-9]+/i', substr($expression, $offset), $match) === 1) {
                $candidate = $offset + strlen($match[0]);
                if (($expression[$candidate] ?? '') === "'") {
                    $openingOffset = $candidate;
                    $introducer = strtolower($match[0]);
                } elseif (substr($expression, $candidate, 2) === "\\'") {
                    $openingOffset = $candidate;
                    $metadata = true;
                    $introducer = strtolower($match[0]);
                }
            } elseif ($expression[$offset] === "'") {
                $openingOffset = $offset;
            } elseif (substr($expression, $offset, 2) === "\\'") {
                $openingOffset = $offset;
                $metadata = true;
            }

            if ($openingOffset === null) {
                $normalized .= $expression[$offset++];
                continue;
            }
            if ($introducer !== null && $introducer !== '_utf8mb4') {
                throw new RuntimeException(sprintf(
                    'Unsupported character set introducer in CHECK expression: %s',
                    $introducer,
                ));
            }

            [$literal, $offset] = $metadata
                ? $this->readMetadataCheckLiteral($expression, $openingOffset)
                : $this->readSqlCheckLiteral($expression, $openingOffset);
            $normalized .= "\x1fl" . bin2hex($literal) . "\x1f";
        }

        return $normalized;
    }

    /**
     * @return array{string, int}
     */
    private function readSqlCheckLiteral(string $expression, int $openingOffset): array
    {
        $literal = '';
        $length = strlen($expression);
        for ($offset = $openingOffset + 1; $offset < $length;) {
            $character = $expression[$offset];
            if ($character === "'") {
                if (($expression[$offset + 1] ?? '') === "'") {
                    $literal .= "'";
                    $offset += 2;
                    continue;
                }

                return [$literal, $offset + 1];
            }
            if ($character !== '\\') {
                $literal .= $character;
                ++$offset;
                continue;
            }
            if ($offset + 1 >= $length) {
                break;
            }

            $escaped = $expression[$offset + 1];
            $literal .= match ($escaped) {
                '0' => "\0",
                'b' => "\x08",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'Z' => "\x1a",
                '%', '_' => '\\' . $escaped,
                default => $escaped,
            };
            $offset += 2;
        }

        throw new RuntimeException('Unterminated SQL literal in CHECK expression.');
    }

    /**
     * MySQL 8 serializes CHECK literals as _charset\'...\'. Within that
     * metadata form one semantic backslash uses four backslashes and an
     * embedded quote uses three backslashes before the quote.
     *
     * @return array{string, int}
     */
    private function readMetadataCheckLiteral(string $expression, int $openingOffset): array
    {
        if (substr($expression, $openingOffset, 2) !== "\\'") {
            throw new RuntimeException('Malformed MySQL metadata CHECK literal.');
        }

        $literal = '';
        $length = strlen($expression);
        for ($offset = $openingOffset + 2; $offset < $length;) {
            if ($expression[$offset] === "'") {
                throw new RuntimeException('Unescaped quote in MySQL metadata CHECK literal.');
            }
            if ($expression[$offset] !== '\\') {
                $literal .= $expression[$offset++];
                continue;
            }

            $runStart = $offset;
            while ($offset < $length && $expression[$offset] === '\\') {
                ++$offset;
            }
            $backslashes = $offset - $runStart;
            if (($expression[$offset] ?? '') !== "'") {
                if ($backslashes % 4 !== 0) {
                    throw new RuntimeException('Malformed backslash run in MySQL metadata CHECK literal.');
                }
                $literal .= str_repeat('\\', intdiv($backslashes, 4));
                continue;
            }

            $remainder = $backslashes % 4;
            if ($remainder === 1) {
                $literal .= str_repeat('\\', intdiv($backslashes - 1, 4));
                return [$literal, $offset + 1];
            }
            if ($remainder === 3) {
                $literal .= str_repeat('\\', intdiv($backslashes - 3, 4)) . "'";
                ++$offset;
                continue;
            }

            throw new RuntimeException('Malformed quoted byte in MySQL metadata CHECK literal.');
        }

        throw new RuntimeException('Unterminated MySQL metadata CHECK literal.');
    }

    /**
     * @return array{operator: 'and'|'or', children: list<array>}|array{atom: string}
     */
    private function parseCheckExpression(string $expression): array
    {
        $expression = $this->stripCheckWrappingParentheses(trim($expression));
        if ($expression === '') {
            throw new RuntimeException('Empty CHECK expression cannot be normalized.');
        }

        foreach (['or', 'and'] as $operator) {
            $parts = $this->splitCheckExpression($expression, $operator);
            if (count($parts) < 2) {
                continue;
            }

            $children = [];
            foreach ($parts as $part) {
                $child = $this->parseCheckExpression($part);
                if (($child['operator'] ?? null) === $operator) {
                    array_push($children, ...$child['children']);
                } else {
                    $children[] = $child;
                }
            }

            return ['operator' => $operator, 'children' => $children];
        }

        return [
            'atom' => $this->normalizeCheckAtom($expression),
        ];
    }

    private function normalizeCheckAtom(string $atom): string
    {
        $normalized = '';
        $quote = null;
        $length = strlen($atom);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $atom[$offset];
            if ($quote !== null) {
                $normalized .= $character;
                if ($character === '\\' && $offset + 1 < $length) {
                    $normalized .= $atom[++$offset];
                    continue;
                }
                if ($character !== $quote) {
                    continue;
                }
                if ($offset + 1 < $length && $atom[$offset + 1] === $quote) {
                    $normalized .= $atom[++$offset];
                    continue;
                }
                $quote = null;
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $normalized .= $character;
                continue;
            }
            if ($character === '`' || ctype_space($character)) {
                continue;
            }
            $normalized .= strtolower($character);
        }
        if ($quote !== null) {
            throw new RuntimeException('Unbalanced CHECK atom quoting.');
        }

        return $normalized;
    }

    private function stripCheckWrappingParentheses(string $expression): string
    {
        while ($expression !== ''
            && $expression[0] === '('
            && $this->checkClosingParenthesis($expression, 0) === strlen($expression) - 1) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    /**
     * @return list<string>
     */
    private function splitCheckExpression(string $expression, string $operator): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $length = strlen($expression);
        $operatorLength = strlen($operator);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $expression[$offset];
            if ($quote !== null) {
                if ($character === '\\') {
                    ++$offset;
                    continue;
                }
                if ($character !== $quote) {
                    continue;
                }
                if ($offset + 1 < $length && $expression[$offset + 1] === $quote) {
                    ++$offset;
                    continue;
                }
                $quote = null;
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }
            if ($character === '(') {
                ++$depth;
                continue;
            }
            if ($character === ')') {
                --$depth;
                if ($depth < 0) {
                    throw new RuntimeException('Unbalanced CHECK expression parentheses.');
                }
                continue;
            }
            if ($depth !== 0
                || strncasecmp(substr($expression, $offset, $operatorLength), $operator, $operatorLength) !== 0) {
                continue;
            }

            $before = $offset === 0 ? '' : $expression[$offset - 1];
            $afterOffset = $offset + $operatorLength;
            $after = $afterOffset >= $length ? '' : $expression[$afterOffset];
            if (($before !== '' && preg_match('/[a-z0-9_]/i', $before) === 1)
                || ($after !== '' && preg_match('/[a-z0-9_]/i', $after) === 1)) {
                continue;
            }

            $part = trim(substr($expression, $start, $offset - $start));
            if ($part === '') {
                throw new RuntimeException('Malformed CHECK boolean expression.');
            }
            $parts[] = $part;
            $offset += $operatorLength - 1;
            $start = $offset + 1;
        }
        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException('Unbalanced CHECK expression quoting or parentheses.');
        }
        if ($parts === []) {
            return [$expression];
        }

        $part = trim(substr($expression, $start));
        if ($part === '') {
            throw new RuntimeException('Malformed CHECK boolean expression.');
        }
        $parts[] = $part;

        return $parts;
    }

    private function checkClosingParenthesis(string $expression, int $openingOffset): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($expression);
        for ($offset = $openingOffset; $offset < $length; ++$offset) {
            $character = $expression[$offset];
            if ($quote !== null) {
                if ($character === '\\') {
                    ++$offset;
                    continue;
                }
                if ($character !== $quote) {
                    continue;
                }
                if ($offset + 1 < $length && $expression[$offset + 1] === $quote) {
                    ++$offset;
                    continue;
                }
                $quote = null;
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }
            if ($character === '(') {
                ++$depth;
            } elseif ($character === ')' && --$depth === 0) {
                return $offset;
            }
        }

        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException('Unbalanced CHECK expression quoting or parentheses.');
        }

        return null;
    }

    /**
     * @param array{operator?: 'and'|'or', children?: list<array>, atom?: string} $node
     */
    private function serializeCheckNode(array $node): string
    {
        if (isset($node['atom'])) {
            return 'atom(' . $node['atom'] . ')';
        }
        if (!isset($node['operator'], $node['children']) || $node['children'] === []) {
            throw new RuntimeException('Invalid CHECK boolean AST.');
        }

        $children = array_map(
            fn (array $child): string => $this->serializeCheckNode($child),
            $node['children'],
        );
        sort($children, SORT_STRING);

        return $node['operator'] . '(' . implode(',', $children) . ')';
    }
}
