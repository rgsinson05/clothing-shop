<?php

declare(strict_types=1);

// Reuse only pure metadata parsers, not migration 016's broader readiness gate.
require_once __DIR__ . '/delivery_schema.php';

function return_stock_schema_result(string $state, array $issues = []): array
{
    return ['state' => $state, 'issues' => array_values(array_unique($issues)), 'message' => $state === 'ready'
        ? 'The additive return-stock ledger structure is ready.'
        : 'Return-stock actions are unavailable until the complete additive ledger schema is independently installed and verified. No migration or repair is performed.'];
}

/** Read only; readiness proves structure, never data eligibility or rollout approval. */
function return_stock_schema_status(PDO $pdo): array
{
    try {
        $read = static function (string $sql) use ($pdo): array {
            $stmt = $pdo->query($sql);
            if ($stmt === false) throw new RuntimeException('Metadata unavailable.');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        $m['variables'] = $read("SHOW SESSION VARIABLES WHERE Variable_name IN ('version','version_comment','foreign_key_checks','check_constraint_checks')");
        $platform = delivery_schema_platform($m['variables']);
        if ($platform === null) return return_stock_schema_result('blocked', ['metadata.unsupported']);
        $m['grants'] = $read('SHOW GRANTS');
        $m['tables'] = $read("SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('return_stock_events','orders','order_items','products','admins','delivery_events','delivery_event_items')");
        $m['columns'] = $read("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION,
            CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'return_stock_events'");
        $visible = $platform === 'mysql' ? 'IS_VISIBLE' : "IF(IGNORED = 'NO', 'YES', 'NO')";
        $m['indexes'] = $read("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION,
            $visible AS IS_USABLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'return_stock_events'");
        $m['foreign_keys'] = $read("SELECT k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
            k.REFERENCED_COLUMN_NAME, k.POSITION_IN_UNIQUE_CONSTRAINT,
            (k.REFERENCED_TABLE_SCHEMA = DATABASE() AND r.UNIQUE_CONSTRAINT_SCHEMA = DATABASE()) AS IS_LOCAL, r.UPDATE_RULE, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
            ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'return_stock_events'");
        $enforced = $platform === 'mysql' ? 't.ENFORCED' : "'YES'";
        $sameTable = $platform === 'mariadb' ? ' AND c.TABLE_NAME = t.TABLE_NAME' : '';
        $m['checks'] = $read("SELECT t.CONSTRAINT_NAME, $enforced AS ENFORCED, c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t
            JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME = t.CONSTRAINT_NAME$sameTable
            WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = 'return_stock_events' AND t.CONSTRAINT_TYPE = 'CHECK'");
        $m['triggers'] = $read("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORIENTATION, ACTION_STATEMENT
            FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND (EVENT_OBJECT_TABLE = 'return_stock_events'
            OR TRIGGER_NAME IN ('return_stock_events_no_update','return_stock_events_no_delete'))");
        return return_stock_schema_evaluate($m);
    } catch (Throwable $error) {
        return return_stock_schema_result('blocked', ['metadata.query_failed']);
    }
}

/** Cache only for existing inventory membership reads; no connection is constructed. */
function return_stock_schema_cached_status(PDO $pdo): array
{
    static $cache = null;
    $cache ??= new WeakMap();
    return $cache[$pdo] ??= return_stock_schema_status($pdo);
}

function return_stock_schema_definition(): array
{
    return [
        'columns' => [
            'id' => ['bigint unsigned', 'auto_increment'], 'order_id' => ['int unsigned', ''],
            'delivery_cycle' => ['int unsigned', ''], 'delivery_revision' => ['int unsigned', ''],
            'order_item_id' => ['int unsigned', ''], 'product_id' => ['int unsigned', ''],
            'physical_return_event_id' => ['bigint unsigned', ''],
            'event_kind' => ["enum('INSPECTION','HOLD_RESOLVED','STOCK_RELEASED')", ''],
            'admin_id' => ['int unsigned', ''], 'reason' => ['varchar(255)', ''],
            'outcome' => ["enum('PASSED','MANUAL_REVIEW','RESOLVED','AVAILABLE')", ''],
            'previous_inventory_state' => ["enum('SOLD','AVAILABLE')", ''],
            'resulting_inventory_state' => ["enum('SOLD','AVAILABLE')", ''],
            'action_key' => ['varchar(64)', ''], 'request_hash' => ['char(64)', ''], 'created_at' => ['timestamp', ''],
        ],
        'indexes' => [
            'PRIMARY' => [0, ['id']], 'uq_return_stock_action' => [0, ['action_key']],
            'uq_return_stock_item_kind' => [0, ['order_id','delivery_cycle','order_item_id','event_kind']],
            'idx_return_stock_product' => [1, ['product_id','order_id','delivery_cycle']],
        ],
        'foreign_keys' => [
            'fk_return_stock_order' => [['order_id'], 'orders', ['id']],
            'fk_return_stock_item' => [['order_item_id'], 'order_items', ['id']],
            'fk_return_stock_product' => [['product_id'], 'products', ['id']],
            'fk_return_stock_admin' => [['admin_id'], 'admins', ['id']],
            'fk_return_stock_event' => [['physical_return_event_id','order_id','delivery_cycle'], 'delivery_events', ['id','order_id','delivery_cycle']],
            'fk_return_stock_custody' => [['physical_return_event_id','order_item_id'], 'delivery_event_items', ['event_id','order_item_id']],
        ],
        'checks' => [
            'chk_return_stock_cycle' => 'delivery_cycle >= 1 AND delivery_revision >= 1',
            'chk_return_stock_reason' => 'CHAR_LENGTH(TRIM(reason)) > 0',
            'chk_return_stock_action' => 'CHAR_LENGTH(action_key) BETWEEN 16 AND 64 AND CHAR_LENGTH(request_hash) = 64',
            'chk_return_stock_outcome' => "previous_inventory_state = 'SOLD' AND ((event_kind = 'INSPECTION' AND outcome IN ('PASSED','MANUAL_REVIEW') AND resulting_inventory_state = 'SOLD') OR (event_kind = 'HOLD_RESOLVED' AND outcome = 'RESOLVED' AND resulting_inventory_state = 'SOLD') OR (event_kind = 'STOCK_RELEASED' AND outcome = 'AVAILABLE' AND resulting_inventory_state = 'AVAILABLE'))",
        ],
    ];
}

/** Narrow, strict structural comparison. Unknown metadata never means absent. */
function return_stock_schema_evaluate(array $m): array
{
    $platform = delivery_schema_platform($m['variables'] ?? []);
    if ($platform === null) return return_stock_schema_result('blocked', ['metadata.unsupported']);
    foreach (['grants','tables','columns','indexes','foreign_keys','checks','triggers'] as $section) {
        if (!is_array($m[$section] ?? null)) return return_stock_schema_result('blocked', ['metadata.' . $section]);
    }
    $fields = [
        'tables' => ['TABLE_SCHEMA','TABLE_NAME','TABLE_TYPE','ENGINE'],
        'columns' => ['COLUMN_NAME','COLUMN_TYPE','IS_NULLABLE','COLUMN_DEFAULT','EXTRA','GENERATION_EXPRESSION','CHARACTER_SET_NAME','COLLATION_NAME'],
        'indexes' => ['INDEX_NAME','NON_UNIQUE','SEQ_IN_INDEX','COLUMN_NAME','SUB_PART','INDEX_TYPE','COLLATION','IS_USABLE'],
        'foreign_keys' => ['CONSTRAINT_NAME','ORDINAL_POSITION','COLUMN_NAME','REFERENCED_TABLE_NAME','REFERENCED_COLUMN_NAME','POSITION_IN_UNIQUE_CONSTRAINT','IS_LOCAL','UPDATE_RULE','DELETE_RULE'],
        'checks' => ['CONSTRAINT_NAME','ENFORCED','CHECK_CLAUSE'],
        'triggers' => ['TRIGGER_NAME','EVENT_OBJECT_TABLE','ACTION_TIMING','EVENT_MANIPULATION','ACTION_ORIENTATION','ACTION_STATEMENT'],
    ];
    foreach ($fields as $section => $required) foreach ($m[$section] as $row) {
        if (!is_array($row) || array_diff($required, array_keys($row)) !== []) return return_stock_schema_result('blocked', ['metadata.' . $section]);
        foreach ($row as $value) if ($value !== null && !is_string($value) && !is_int($value)) return return_stock_schema_result('blocked', ['metadata.' . $section]);
    }
    $tables = [];
    foreach ($m['tables'] as $row) {
        if (!is_string($row['TABLE_NAME'] ?? null) || isset($tables[$row['TABLE_NAME']])) return return_stock_schema_result('blocked', ['metadata.tables']);
        $tables[$row['TABLE_NAME']] = $row;
    }
    $schemas = array_unique(array_column($m['tables'], 'TABLE_SCHEMA'));
    $privileges = [];
    foreach ($m['grants'] as $row) {
        if (!is_array($row) || count($row) !== 1 || !is_string(reset($row))) return return_stock_schema_result('blocked', ['metadata.visibility']);
        $grant = reset($row);
        if (preg_match('/^REVOKE\b/i', $grant)) return return_stock_schema_result('blocked', ['metadata.visibility']);
        if (count($schemas) === 1 && preg_match('/^GRANT (.+?) ON (\*|`(?:``|[^`])+`)\.\* TO /i', $grant, $match)) {
            $scope = $match[2] === '*' ? '*' : str_replace(['``','\\_','\\%'], ['`','_','%'], substr($match[2], 1, -1));
            if ($scope === '*' || $scope === reset($schemas)) $privileges = array_merge($privileges, array_map('trim', explode(',', strtoupper($match[1]))));
        }
    }
    $visible = in_array('ALL PRIVILEGES', $privileges, true) || (in_array('SELECT', $privileges, true) && in_array('TRIGGER', $privileges, true));
    if (!$visible) return return_stock_schema_result('blocked', ['metadata.visibility']);
    $absent = !isset($tables['return_stock_events']);
    foreach (['columns','indexes','foreign_keys','checks','triggers'] as $section) $absent = $absent && $m[$section] === [];
    if ($absent) return return_stock_schema_result('legacy');
    $issues = [];
    foreach (['return_stock_events','orders','order_items','products','admins','delivery_events','delivery_event_items'] as $table) {
        if (($tables[$table]['TABLE_TYPE'] ?? '') !== 'BASE TABLE' || strcasecmp((string) ($tables[$table]['ENGINE'] ?? ''), 'InnoDB') !== 0) $issues[] = 'table.' . $table;
    }
    $spec = return_stock_schema_definition();
    $columns = [];
    foreach ($m['columns'] as $row) {
        $name = $row['COLUMN_NAME'] ?? '';
        if (!is_string($name) || isset($columns[$name])) return return_stock_schema_result('blocked', ['metadata.columns']);
        $columns[$name] = $row;
    }
    $type = static fn ($v) => is_string($v) ? delivery_schema_tokens(preg_replace('/\b(tinyint|int|bigint)\s*\([0-9]+\)/i', '$1', $v)) : null;
    foreach ($spec['columns'] as $name => [$expectedType, $extra]) {
        $r = $columns[$name] ?? [];
        $default = $r['COLUMN_DEFAULT'] ?? null;
        $actualExtra = strtolower(trim((string) ($r['EXTRA'] ?? '')));
        if ($name === 'created_at') $actualExtra = trim(str_replace('default_generated', '', $actualExtra));
        $validDefault = $name === 'created_at' ? is_string($default) && preg_match('/^current_timestamp(?:\(\))?$/iD', $default) === 1 : $default === null || strtoupper((string) $default) === 'NULL';
        $text = str_contains($expectedType, '(');
        $charset = $text ? is_string($r['CHARACTER_SET_NAME'] ?? null) && is_string($r['COLLATION_NAME'] ?? null) : ($r['CHARACTER_SET_NAME'] ?? null) === null && ($r['COLLATION_NAME'] ?? null) === null;
        if (in_array($name, ['action_key','request_hash'], true)) $charset = ($r['CHARACTER_SET_NAME'] ?? '') === 'ascii' && ($r['COLLATION_NAME'] ?? '') === 'ascii_bin';
        if (array_diff(['COLUMN_TYPE','IS_NULLABLE','COLUMN_DEFAULT','EXTRA','GENERATION_EXPRESSION','CHARACTER_SET_NAME','COLLATION_NAME'], array_keys($r)) !== []
            || $type($r['COLUMN_TYPE'] ?? null) !== $type($expectedType) || ($r['IS_NULLABLE'] ?? '') !== 'NO'
            || !$validDefault || $actualExtra !== $extra || !in_array($r['GENERATION_EXPRESSION'] ?? null, [null,''], true) || !$charset) $issues[] = 'column.' . $name;
    }
    if (array_diff(array_keys($columns), array_keys($spec['columns'])) !== []) $issues[] = 'column.unexpected';
    foreach (['indexes' => 'INDEX_NAME','foreign_keys' => 'CONSTRAINT_NAME'] as $section => $nameField) {
        $groups = [];
        $ordinalField = $section === 'indexes' ? 'SEQ_IN_INDEX' : 'ORDINAL_POSITION';
        foreach ($m[$section] as $row) {
            $name = $row[$nameField] ?? '';
            $ordinal = $row[$ordinalField] ?? 0;
            if (!is_string($name) || filter_var($ordinal, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || isset($groups[$name][$ordinal])) return return_stock_schema_result('blocked', ['metadata.' . $section]);
            $groups[$name][$ordinal] = $row;
        }
        foreach ($groups as &$rows) { ksort($rows); $rows = array_values($rows); }
        unset($rows);
        foreach ($spec[$section] as $name => $expected) {
            $rows = $groups[$name] ?? [];
            $local = $section === 'indexes' ? $expected[1] : $expected[0];
            $valid = array_column($rows, 'COLUMN_NAME') === $local;
            foreach ($rows as $i => $row) {
                $valid = $valid && (int) $row[$ordinalField] === $i + 1;
                if ($section === 'indexes') $valid = $valid && (string) ($row['NON_UNIQUE'] ?? '') === (string) $expected[0]
                    && array_key_exists('SUB_PART', $row) && $row['SUB_PART'] === null && ($row['INDEX_TYPE'] ?? '') === 'BTREE'
                    && ($row['COLLATION'] ?? '') === 'A' && ($row['IS_USABLE'] ?? '') === 'YES';
                else $valid = $valid && ($row['REFERENCED_TABLE_NAME'] ?? '') === $expected[1]
                    && ($row['REFERENCED_COLUMN_NAME'] ?? '') === $expected[2][$i] && (string) ($row['IS_LOCAL'] ?? '') === '1'
                    && (int) ($row['POSITION_IN_UNIQUE_CONSTRAINT'] ?? 0) === $i + 1
                    && in_array($row['UPDATE_RULE'] ?? '', ['RESTRICT','NO ACTION'], true) && in_array($row['DELETE_RULE'] ?? '', ['RESTRICT','NO ACTION'], true);
            }
            if (!$valid) $issues[] = $section . '.' . $name;
        }
        foreach ($groups as $name => $rows) {
            if (!isset($spec[$section][$name]) && ($section === 'foreign_keys' || (string) ($rows[0]['NON_UNIQUE'] ?? '') !== '1')) $issues[] = $section . '.unexpected';
        }
    }
    $checks = [];
    foreach ($m['checks'] as $row) {
        $name = $row['CONSTRAINT_NAME'] ?? '';
        if (!is_string($name) || isset($checks[$name])) return return_stock_schema_result('blocked', ['metadata.checks']);
        $checks[$name] = $row;
    }
    foreach ($spec['checks'] as $name => $sql) {
        $row = $checks[$name] ?? [];
        if (($row['ENFORCED'] ?? '') !== 'YES' || !is_string($row['CHECK_CLAUSE'] ?? null)
            || delivery_schema_expression($row['CHECK_CLAUSE'], $platform === 'mysql') !== delivery_schema_expression($sql)) $issues[] = 'check.' . $name;
    }
    if (array_diff(array_keys($checks), array_keys($spec['checks'])) !== []) $issues[] = 'check.unexpected';
    $triggers = [];
    foreach ($m['triggers'] as $row) {
        $name = $row['TRIGGER_NAME'] ?? '';
        if (!is_string($name) || isset($triggers[$name])) return return_stock_schema_result('blocked', ['metadata.triggers']);
        $triggers[$name] = $row;
    }
    foreach (['UPDATE','DELETE'] as $event) {
        $name = 'return_stock_events_no_' . strtolower($event);
        $row = $triggers[$name] ?? [];
        $body = is_string($row['ACTION_STATEMENT'] ?? null) ? delivery_schema_tokens($row['ACTION_STATEMENT']) : null;
        if ($body !== null && end($body) === ';') array_pop($body);
        if ($body !== null && ($body[0] ?? '') === 'begin' && array_slice($body, -2) === [';','end']) $body = array_slice($body, 1, -2);
        if (($row['EVENT_OBJECT_TABLE'] ?? '') !== 'return_stock_events' || ($row['ACTION_TIMING'] ?? '') !== 'BEFORE'
            || ($row['EVENT_MANIPULATION'] ?? '') !== $event || ($row['ACTION_ORIENTATION'] ?? '') !== 'ROW'
            || $body !== delivery_schema_tokens("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return stock history is append-only'")) $issues[] = 'trigger.' . $name;
    }
    if (array_diff(array_keys($triggers), ['return_stock_events_no_update','return_stock_events_no_delete']) !== []) $issues[] = 'trigger.unexpected';
    return return_stock_schema_result($issues === [] ? 'ready' : 'blocked', $issues);
}
