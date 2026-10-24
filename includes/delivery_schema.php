<?php

declare(strict_types=1);

/**
 * Read-only migration-016 structural gate. No bootstrap, writes, cache or repair.
 * A ready result is NOT verification of data, persistent DDL/trigger execution,
 * deployment safety or concurrent actions. Run separately approved rollout checks.
 * Only the inspected MySQL 8.4 / MariaDB 11.4 metadata dialects are supported.
 *
 * @return array{state: 'legacy'|'ready'|'blocked', message: string, issues: array}
 */
function delivery_schema_status(PDO $pdo): array
{
    try {
        $read = static function (string $sql) use ($pdo): array {
            $statement = $pdo->query($sql);
            if ($statement === false) {
                throw new RuntimeException('Metadata unavailable.');
            }
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        };
        $metadata = ['variables' => $read("SHOW SESSION VARIABLES WHERE Variable_name IN
            ('version','version_comment','foreign_key_checks','check_constraint_checks')")];
        $platform = delivery_schema_platform($metadata['variables']);
        if ($platform === null) {
            return delivery_schema_result('blocked', ['metadata.unsupported']);
        }
        $tables = "('shipments','delivery_events','delivery_event_items',
            'customer_order_notifications','orders','order_items','products','admins','customers')";
        $changed = "('shipments','delivery_events','delivery_event_items','customer_order_notifications')";
        $metadata['grants'] = $read('SHOW GRANTS');
        $metadata['tables'] = $read("SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, ENGINE
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN $tables");
        $metadata['columns'] = $read("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
            COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, EXTRA, GENERATION_EXPRESSION
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN $tables");
        $usable = $platform === 'mysql' ? 'IS_VISIBLE' : "IF(IGNORED = 'NO', 'YES', 'NO')";
        $metadata['indexes'] = $read("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX,
            COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION, $usable AS IS_USABLE
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN $tables");
        $metadata['foreign_keys'] = $read("SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION,
            k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
            k.POSITION_IN_UNIQUE_CONSTRAINT,
            (k.REFERENCED_TABLE_SCHEMA = DATABASE() AND r.UNIQUE_CONSTRAINT_SCHEMA = DATABASE()) AS IS_LOCAL,
            r.UPDATE_RULE, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME
             AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN $changed");
        // MariaDB 11.4 has no per-check NOT ENFORCED mode or ENFORCED column.
        // platform() requires check_constraint_checks=ON; the joined check must exist.
        $enforced = $platform === 'mysql' ? 't.ENFORCED' : "'YES'";
        $checkTable = $platform === 'mariadb' ? ' AND c.TABLE_NAME = t.TABLE_NAME' : '';
        $metadata['checks'] = $read("SELECT t.TABLE_NAME, t.CONSTRAINT_NAME, $enforced AS ENFORCED,
            c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t
            JOIN information_schema.CHECK_CONSTRAINTS c
              ON c.CONSTRAINT_SCHEMA = t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME = t.CONSTRAINT_NAME$checkTable
            WHERE t.TABLE_SCHEMA = DATABASE() AND t.CONSTRAINT_TYPE = 'CHECK' AND t.TABLE_NAME IN $changed");
        $metadata['triggers'] = $read("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING,
            EVENT_MANIPULATION, ACTION_ORIENTATION, ACTION_STATEMENT
            FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
              AND (EVENT_OBJECT_TABLE IN $changed OR TRIGGER_NAME IN
                ('delivery_events_no_update','delivery_events_no_delete',
                 'delivery_event_items_no_update','delivery_event_items_no_delete'))");
        return delivery_schema_evaluate($metadata);
    } catch (Throwable $error) {
        // Never expose server errors, schema names, SQL, credentials or paths.
        return delivery_schema_result('blocked', ['metadata.query_failed']);
    }
}

/** Internal helpers are pure so tests do not need a database connection. */
function delivery_schema_result(string $state, array $issues = []): array
{
    $messages = [
        'legacy' => 'The existing delivery workflow is available. Courier delivery controls are not installed.',
        'ready' => 'The courier delivery structure is ready.',
        'blocked' => 'The complete courier schema or metadata visibility could not be confirmed. Keep the coordinated rollout paused and request a schema review. No migration or legacy fallback is performed.',
    ];
    return ['state' => $state, 'message' => $messages[$state], 'issues' => array_values(array_unique($issues))];
}

function delivery_schema_platform(array $rows): ?string
{
    $variables = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['Variable_name'] ?? null)
            || !is_string($row['Value'] ?? null) || isset($variables[strtolower($row['Variable_name'])])) {
            return null;
        }
        $variables[strtolower($row['Variable_name'])] = $row['Value'];
    }
    if (!in_array(strtoupper($variables['foreign_key_checks'] ?? ''), ['ON', '1'], true)) {
        return null;
    }
    $version = $variables['version'] ?? '';
    if (preg_match('/^(?:5\.5\.5-)?11\.4\.\d+-MariaDB(?:[-+].*)?$/iD', $version) === 1
        && in_array(strtoupper($variables['check_constraint_checks'] ?? ''), ['ON', '1'], true)) {
        return 'mariadb';
    }
    if (preg_match('/^8\.4\.\d+(?:[-+][a-z0-9._+-]+)?$/iD', $version) === 1
        && stripos($variables['version_comment'] ?? '', 'mysql') !== false) {
        return 'mysql';
    }
    return null;
}

/** SQL tokens retain string case/content; only harmless metadata spelling varies. */
function delivery_schema_tokens(string $sql, bool $mysqlCheckMetadata = false): ?array
{
    $sql = rtrim($sql);
    $tokens = [];
    $offset = 0;
    $literal = "'(?:[^'\\\\]|'')*'";
    if ($mysqlCheckMetadata) {
        // MySQL 8.4 CHECK_CLAUSE escapes delimiters, unlike SHOW CREATE TABLE.
        // Match paired delimiters only; unknown escapes/content still fail closed.
        $delimiter = preg_quote("\\'", '/');
        $literal .= '|' . $delimiter . "[^'\\\\]*" . $delimiter;
    }
    $pattern = "/\\G\\s*(?:(?:_(?:utf8mb4|utf8mb3|utf8|ascii|latin1)\\s*)?($literal)|`([a-z_][a-z0-9_]*)`|([a-z_][a-z0-9_]*)|([0-9]+)|(>=|<=|<>|!=|[(),=><;]))/i";
    while ($offset < strlen($sql)) {
        if (preg_match($pattern, $sql, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
            return null;
        }
        $offset += strlen($match[0]);
        $token = $match[1] ?? strtolower($match[2] ?? $match[3] ?? $match[4] ?? $match[5]);
        if ($match[1] !== null && str_starts_with($token, "\\'")) $token = "'" . substr($token, 2, -2) . "'";
        $tokens[] = $token === '!=' ? '<>' : $token;
    }
    return $tokens;
}

/**
 * Parse just the check grammar used by 016. Parentheses must retain meaning:
 * stripping them as text would accept changed AND/OR grouping. Unknown SQL fails.
 */
function delivery_schema_expression(string $sql, bool $mysqlCheckMetadata = false): ?array
{
    $tokens = delivery_schema_tokens($sql, $mysqlCheckMetadata);
    if ($tokens === null || $tokens === []) {
        return null;
    }
    $position = 0;
    $parse = null;
    $take = static function (string $token) use (&$position, $tokens): void {
        if (($tokens[$position++] ?? null) !== $token) {
            throw new LogicException('Unsupported expression.');
        }
    };
    $atom = static function () use (&$atom, &$parse, &$position, $tokens, $take): array {
        $token = $tokens[$position++] ?? '';
        if ($token === '(') {
            $value = $parse(1);
            $take(')');
            return $value;
        }
        if (in_array($token, ['char_length', 'trim'], true) && ($tokens[$position] ?? '') === '(') {
            $take('(');
            $value = $atom();
            $take(')');
            return [$token, $value];
        }
        if (preg_match("/^(?:[a-z_][a-z0-9_]*|[0-9]+|'(?:[^']|'')*')$/D", $token) !== 1) {
            throw new LogicException('Unsupported operand.');
        }
        return ['value', $token];
    };
    $predicate = static function () use (&$position, $tokens, $take, $atom): array {
        $left = $atom();
        $operator = $tokens[$position] ?? '';
        if (in_array($operator, ['=', '<>', '>', '>=', '<', '<='], true)) {
            $position++;
            return [$operator, $left, $atom()];
        }
        if ($operator === 'is') {
            $position++;
            $not = ($tokens[$position] ?? '') === 'not';
            if ($not) $position++;
            $take('null');
            return [$not ? 'is not null' : 'is null', $left];
        }
        if ($operator === 'between') {
            $position++;
            $low = $atom();
            $take('and');
            return ['between', $left, $low, $atom()];
        }
        if ($operator === 'in') {
            $position++;
            $take('(');
            $values = [$atom()];
            while (($tokens[$position] ?? '') === ',') {
                $position++;
                $values[] = $atom();
            }
            $take(')');
            return ['in', $left, $values];
        }
        return $left;
    };
    $parse = static function (int $minimum) use (&$parse, &$position, $tokens, $predicate): array {
        $left = $predicate();
        while (true) {
            $operator = $tokens[$position] ?? '';
            $priority = ['or' => 1, 'and' => 2][$operator] ?? 0;
            if ($priority < $minimum) return $left;
            $position++;
            $right = $parse($priority + 1);
            $left = array_merge([$operator], $left[0] === $operator ? array_slice($left, 1) : [$left],
                $right[0] === $operator ? array_slice($right, 1) : [$right]);
        }
    };
    try {
        $tree = $parse(1);
        return $position === count($tokens) ? $tree : null;
    } catch (Throwable $error) {
        return null;
    }
}

/**
 * Evaluate raw metadata rows from status(). Missing collections/fields are unknown,
 * never evidence of absence. Issues contain only hard-coded structural identifiers.
 */
function delivery_schema_evaluate(array $metadata): array
{
    if (!is_array($metadata['variables'] ?? null) || delivery_schema_platform($metadata['variables']) === null) {
        return delivery_schema_result('blocked', ['metadata.unsupported']);
    }
    $requiredFields = [
        'tables' => ['TABLE_SCHEMA', 'TABLE_NAME', 'TABLE_TYPE', 'ENGINE'],
        'columns' => ['TABLE_NAME', 'COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT',
            'CHARACTER_SET_NAME', 'COLLATION_NAME', 'EXTRA', 'GENERATION_EXPRESSION'],
        'indexes' => ['TABLE_NAME', 'INDEX_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'COLUMN_NAME',
            'SUB_PART', 'INDEX_TYPE', 'COLLATION', 'IS_USABLE'],
        'foreign_keys' => ['TABLE_NAME', 'CONSTRAINT_NAME', 'ORDINAL_POSITION', 'COLUMN_NAME',
            'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME', 'POSITION_IN_UNIQUE_CONSTRAINT',
            'IS_LOCAL', 'UPDATE_RULE', 'DELETE_RULE'],
        'checks' => ['TABLE_NAME', 'CONSTRAINT_NAME', 'ENFORCED', 'CHECK_CLAUSE'],
        'triggers' => ['TRIGGER_NAME', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'EVENT_MANIPULATION',
            'ACTION_ORIENTATION', 'ACTION_STATEMENT'],
    ];
    foreach ($requiredFields as $section => $fields) {
        if (!is_array($metadata[$section] ?? null)) {
            return delivery_schema_result('blocked', ['metadata.' . $section]);
        }
        foreach ($metadata[$section] as $row) {
            if (!is_array($row) || array_diff($fields, array_keys($row)) !== []) {
                return delivery_schema_result('blocked', ['metadata.' . $section]);
            }
            foreach ($row as $value) {
                if ($value !== null && !is_string($value) && !is_int($value)) {
                    return delivery_schema_result('blocked', ['metadata.' . $section]);
                }
            }
            foreach (['TABLE_SCHEMA', 'TABLE_NAME', 'COLUMN_NAME', 'INDEX_NAME', 'CONSTRAINT_NAME', 'TRIGGER_NAME', 'EVENT_OBJECT_TABLE'] as $field) {
                if (array_key_exists($field, $row) && (!is_string($row[$field]) || $row[$field] === '')) {
                    return delivery_schema_result('blocked', ['metadata.' . $section]);
                }
            }
        }
    }
    // Without broad metadata visibility, absent I_S rows can mean hidden objects.
    // Only direct grants are proved here; roles and partial revokes fail closed.
    $schemas = array_values(array_unique(array_column($metadata['tables'], 'TABLE_SCHEMA')));
    if (count($schemas) !== 1 || !is_array($metadata['grants'] ?? null)) {
        return delivery_schema_result('blocked', ['metadata.visibility']);
    }
    $privileges = [];
    foreach ($metadata['grants'] as $row) {
        if (!is_array($row) || count($row) !== 1 || !is_string(reset($row))) {
            return delivery_schema_result('blocked', ['metadata.visibility']);
        }
        $grant = reset($row);
        if (preg_match('/^REVOKE\b/i', $grant) === 1) return delivery_schema_result('blocked', ['metadata.visibility']);
        if (preg_match('/^GRANT (.+?) ON (\*|`(?:``|[^`])+`)\.\* TO /i', $grant, $match) !== 1) continue;
        $scope = $match[2] === '*' ? '*' : str_replace(['``', '\\_', '\\%'], ['`', '_', '%'], substr($match[2], 1, -1));
        if ($scope !== '*' && $scope !== $schemas[0]) continue;
        $privileges = array_merge($privileges, array_map('trim', explode(',', strtoupper($match[1]))));
    }
    if (!in_array('ALL PRIVILEGES', $privileges, true)
        && (!in_array('SELECT', $privileges, true) || !in_array('TRIGGER', $privileges, true))) {
        return delivery_schema_result('blocked', ['metadata.visibility']);
    }
    $tables = $columns = $indexes = $foreignKeys = $checks = $triggers = [];
    foreach ($metadata['tables'] as $row) {
        if (isset($tables[$row['TABLE_NAME']])) return delivery_schema_result('blocked', ['metadata.tables']);
        $tables[$row['TABLE_NAME']] = $row;
    }
    foreach ($metadata['columns'] as $row) {
        if (isset($columns[$row['TABLE_NAME']][$row['COLUMN_NAME']])) return delivery_schema_result('blocked', ['metadata.columns']);
        $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
    }
    foreach (['indexes' => ['INDEX_NAME', 'SEQ_IN_INDEX'], 'foreign_keys' => ['CONSTRAINT_NAME', 'ORDINAL_POSITION']] as $section => $fields) {
        $groups = [];
        foreach ($metadata[$section] as $row) {
            $ordinal = (string) $row[$fields[1]];
            if (preg_match('/^[1-9][0-9]*$/D', $ordinal) !== 1
                || isset($groups[$row['TABLE_NAME']][$row[$fields[0]]][$ordinal])) {
                return delivery_schema_result('blocked', ['metadata.' . $section]);
            }
            $groups[$row['TABLE_NAME']][$row[$fields[0]]][$ordinal] = $row;
        }
        foreach ($groups as &$group) {
            foreach ($group as &$rows) {
                ksort($rows);
                if (array_keys($rows) !== range(1, count($rows))) return delivery_schema_result('blocked', ['metadata.' . $section]);
                $rows = array_values($rows);
            }
            unset($rows);
        }
        unset($group);
        if ($section === 'indexes') $indexes = $groups;
        else $foreignKeys = $groups;
    }
    foreach ($metadata['checks'] as $row) {
        if (isset($checks[$row['TABLE_NAME']][$row['CONSTRAINT_NAME']])) return delivery_schema_result('blocked', ['metadata.checks']);
        $checks[$row['TABLE_NAME']][$row['CONSTRAINT_NAME']] = $row;
    }
    foreach ($metadata['triggers'] as $row) {
        if (isset($triggers[$row['TRIGGER_NAME']])) return delivery_schema_result('blocked', ['metadata.triggers']);
        $triggers[$row['TRIGGER_NAME']] = $row;
    }

    $oldShipment = "enum('NOT_SHIPPED','READY_TO_SHIP','SHIPPED','IN_TRANSIT','OUT_FOR_DELIVERY','DELIVERED')";
    $newShipment = substr($oldShipment, 0, -1) . ",'DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP')";
    $oldNotification = "enum('ORDER_SHIPPED','ORDER_DELIVERED','PICKUP_READY','PICKUP_HANDED_OVER','ORDER_CANCELLED')";
    $newNotification = substr($oldNotification, 0, -1)
        . ",'OUT_FOR_DELIVERY','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP','DELIVERY_CYCLE_AUTHORIZED')";
    $type = static function ($value): ?array {
        if (!is_string($value)) return null;
        return delivery_schema_tokens(preg_replace('/\b(tinyint|int|bigint)\s*\([0-9]+\)/i', '$1', $value));
    };
    $legacy = !isset($columns['shipments']['delivery_cycle']) && !isset($columns['shipments']['delivery_revision'])
        && !isset($columns['shipments']['failed_attempts']) && !isset($columns['customer_order_notifications']['occurrence_key'])
        && !isset($indexes['customer_order_notifications']['uq_customer_notification_occurrence'])
        && $type($columns['shipments']['shipment_status']['COLUMN_TYPE'] ?? null) === $type($oldShipment)
        && $type($columns['customer_order_notifications']['notification_type']['COLUMN_TYPE'] ?? null) === $type($oldNotification)
        && $checks === [] && $triggers === [];
    foreach (['delivery_events', 'delivery_event_items'] as $table) {
        foreach ([$tables, $columns, $indexes, $foreignKeys] as $collection) {
            if (isset($collection[$table])) $legacy = false;
        }
    }

    // Tuple: type, nullability (null only for implicit old TIMESTAMPs), default,
    // EXTRA, and optional explicitly declared character set/collation.
    $id = ['int unsigned', 'NO', null, 'auto_increment'];
    $uint = ['int unsigned', 'NO', null, ''];
    $date = ['datetime', 'YES', null, ''];
    $spec = [
        'shipments' => [
            'id' => $id, 'order_id' => $uint,
            'shipment_status' => [$legacy ? $oldShipment : $newShipment, 'NO', 'NOT_SHIPPED', ''],
            'delivery_method' => ["enum('LOCAL_COURIER','STORE_PICKUP')", 'YES', null, ''],
            'tracking_number' => ['varchar(100)', 'YES', null, ''],
            'shipped_at' => $date, 'delivered_at' => $date,
            'created_at' => ['timestamp', null, 'CURRENT_TIMESTAMP', ''],
            'updated_at' => ['timestamp', null, 'CURRENT_TIMESTAMP', 'on update current_timestamp'],
        ],
        'customer_order_notifications' => [
            'id' => $id, 'customer_id' => $uint, 'order_id' => $uint,
            'notification_type' => [$legacy ? $oldNotification : $newNotification, 'NO', null, ''],
            'message' => ['varchar(255)', 'NO', null, ''],
            'created_at' => ['timestamp', 'NO', 'CURRENT_TIMESTAMP', ''], 'read_at' => $date,
        ],
        'orders' => ['id' => $id], 'order_items' => ['id' => $id],
        'products' => ['id' => $id], 'admins' => ['id' => $id], 'customers' => ['id' => $id],
    ];
    if (!$legacy) {
        $spec['shipments'] += [
            'delivery_cycle' => ['int unsigned', 'NO', '1', ''],
            'delivery_revision' => ['int unsigned', 'NO', '0', ''],
            'failed_attempts' => ['tinyint unsigned', 'NO', '0', ''],
        ];
        $spec['customer_order_notifications']['occurrence_key'] = ['varchar(64)', 'NO', 'legacy', '', 'ascii', 'ascii_bin'];
        $spec['delivery_events'] = [
            'id' => ['bigint unsigned', 'NO', null, 'auto_increment'], 'order_id' => $uint,
            'delivery_cycle' => $uint, 'delivery_revision' => $uint,
            'event_type' => ["enum('OUT_FOR_DELIVERY','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP','RETURN_INSPECTED','DELIVERY_CYCLE_AUTHORIZED','DELIVERED')", 'NO', null, ''],
            'admin_id' => $uint, 'attempt_number' => ['tinyint unsigned', 'YES', null, ''],
            'reason' => ['varchar(255)', 'YES', null, ''],
            'previous_state' => ['varchar(32)', 'NO', null, ''], 'resulting_state' => ['varchar(32)', 'NO', null, ''],
            'action_key' => ['varchar(64)', 'NO', null, '', 'ascii', 'ascii_bin'],
            'event_index' => ['tinyint unsigned', 'NO', '0', ''],
            'request_hash' => ['char(64)', 'NO', null, '', 'ascii', 'ascii_bin'],
            'created_at' => ['timestamp', 'NO', 'CURRENT_TIMESTAMP', ''],
        ];
        $spec['delivery_event_items'] = [
            'event_id' => ['bigint unsigned', 'NO', null, ''], 'order_id' => $uint, 'delivery_cycle' => $uint,
            'order_item_id' => $uint, 'product_id' => $uint,
            'record_type' => ["enum('RETURNED','INSPECTED')", 'NO', null, ''],
            'outcome' => ["enum('RETURNED','RETRY_APPROVED','MANUAL_REVIEW')", 'NO', null, ''],
        ];
    }
    $default = static function ($value, $expected): bool {
        if ($expected === null) return $value === null || (is_string($value) && strtoupper($value) === 'NULL');
        if (!is_string($value) && !is_int($value)) return false;
        $value = trim((string) $value);
        if ($expected === 'CURRENT_TIMESTAMP') return preg_match('/^current_timestamp(?:\(\))?$/iD', $value) === 1;
        if (preg_match("/^'((?:[^'\\\\]|'')*)'$/D", $value, $match) === 1) $value = str_replace("''", "'", $match[1]);
        return $value === $expected;
    };
    $issues = [];
    foreach ($spec as $table => $fields) {
        if (($tables[$table]['TABLE_TYPE'] ?? '') !== 'BASE TABLE' || strcasecmp((string) ($tables[$table]['ENGINE'] ?? ''), 'InnoDB') !== 0) {
            $issues[] = 'table.' . $table;
        }
        foreach ($fields as $name => $expected) {
            $row = $columns[$table][$name] ?? [];
            $extra = strtolower(trim((string) ($row['EXTRA'] ?? '')));
            $extra = str_replace('current_timestamp()', 'current_timestamp', $extra);
            if ($expected[2] === 'CURRENT_TIMESTAMP') $extra = trim(str_replace('default_generated', '', $extra));
            $text = preg_match('/^(?:enum|varchar|char)\(/', $expected[0]) === 1;
            $charset = $row['CHARACTER_SET_NAME'] ?? null;
            $collation = $row['COLLATION_NAME'] ?? null;
            $characterMatch = $text
                ? is_string($charset) && $charset !== '' && is_string($collation) && $collation !== ''
                : $charset === null && $collation === null;
            if (isset($expected[4])) $characterMatch = $charset === $expected[4] && $collation === $expected[5];
            if ($row === [] || $type($row['COLUMN_TYPE']) !== $type($expected[0])
                || !in_array($row['IS_NULLABLE'], $expected[1] === null ? ['YES', 'NO'] : [$expected[1]], true)
                || !$default($row['COLUMN_DEFAULT'], $expected[2]) || $extra !== $expected[3]
                || !in_array($row['GENERATION_EXPRESSION'], [null, ''], true) || !$characterMatch) {
                $issues[] = 'column.' . $table . '.' . $name;
            }
        }
        if (in_array($table, ['shipments', 'customer_order_notifications', 'delivery_events', 'delivery_event_items'], true)
            && array_diff(array_keys($columns[$table] ?? []), array_keys($fields)) !== []) {
            $issues[] = 'column.' . $table . '.unexpected';
        }
    }

    // Required key names are fixed; FK-generated index names are not.
    $keys = [];
    foreach ($spec as $table => $fields) $keys[$table]['PRIMARY'] = [0, ['id']];
    $keys['shipments']['*order_id'] = [0, ['order_id']];
    $keys['customer_order_notifications'] += [
        'idx_customer_notification_newest' => [1, ['customer_id', 'created_at', 'id']],
        'idx_customer_notification_unread' => [1, ['customer_id', 'read_at']],
        $legacy ? 'uq_customer_notification_order_type' : 'uq_customer_notification_occurrence' =>
            [0, $legacy ? ['customer_id', 'order_id', 'notification_type'] : ['customer_id', 'order_id', 'notification_type', 'occurrence_key']],
    ];
    if (!$legacy) {
        $keys['delivery_events'] += [
            'uq_delivery_action' => [0, ['action_key', 'event_index']],
            'uq_delivery_event_identity' => [0, ['id', 'order_id', 'delivery_cycle']],
            'uq_delivery_failure' => [0, ['order_id', 'delivery_cycle', 'attempt_number']],
            'idx_delivery_history' => [1, ['order_id', 'delivery_cycle', 'id']],
        ];
        $keys['delivery_event_items'] = [
            'PRIMARY' => [0, ['event_id', 'order_item_id']],
            'uq_delivery_item_record' => [0, ['order_id', 'delivery_cycle', 'order_item_id', 'record_type']],
        ];
        if (isset($indexes['customer_order_notifications']['uq_customer_notification_order_type'])) {
            $issues[] = 'index.customer_order_notifications.uq_customer_notification_order_type';
        }
    }
    $indexMatches = static function (array $rows, array $names, ?int $nonUnique, bool $prefix = false): bool {
        if ($rows === [] || ($prefix ? count($rows) < count($names) : count($rows) !== count($names))) return false;
        foreach ($rows as $row) {
            if (!in_array((string) $row['NON_UNIQUE'], ['0', '1'], true)
                || ($nonUnique !== null && (string) $row['NON_UNIQUE'] !== (string) $nonUnique)
                || $row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE'
                || $row['COLLATION'] !== 'A' || $row['IS_USABLE'] !== 'YES') return false;
        }
        return array_slice(array_column($rows, 'COLUMN_NAME'), 0, count($names)) === $names;
    };
    foreach ($keys as $table => $expectedKeys) {
        $acceptedUnique = [];
        foreach ($expectedKeys as $name => [$nonUnique, $names]) {
            $candidates = $name[0] === '*' ? ($indexes[$table] ?? []) : [$name => $indexes[$table][$name] ?? []];
            $found = false;
            foreach ($candidates as $candidate => $rows) {
                if ($indexMatches($rows, $names, $nonUnique)) {
                    $found = true;
                    if ($nonUnique === 0) $acceptedUnique[] = $candidate;
                }
            }
            if (!$found) $issues[] = 'index.' . $table . '.' . strtolower(ltrim($name, '*'));
        }
        // Extra unique keys can silently suppress repeated occurrences or events.
        if (in_array($table, ['shipments', 'customer_order_notifications', 'delivery_events', 'delivery_event_items'], true)) {
            foreach ($indexes[$table] ?? [] as $name => $rows) {
                if ((string) $rows[0]['NON_UNIQUE'] === '0' && !in_array($name, $acceptedUnique, true)) {
                    $issues[] = 'index.' . $table . '.unexpected_unique';
                }
            }
        }
    }

    // Local columns, referenced table/columns, UPDATE rule, DELETE rule.
    $fkSpec = [
        'shipments' => [[['order_id'], 'orders', ['id'], 'CASCADE', 'CASCADE']],
        'customer_order_notifications' => [
            [['customer_id'], 'customers', ['id'], 'CASCADE', 'RESTRICT'],
            [['order_id'], 'orders', ['id'], 'CASCADE', 'RESTRICT'],
        ],
    ];
    if (!$legacy) {
        $fkSpec['delivery_events'] = [
            [['order_id'], 'orders', ['id'], 'RESTRICT', 'RESTRICT'],
            [['admin_id'], 'admins', ['id'], 'RESTRICT', 'RESTRICT'],
        ];
        $fkSpec['delivery_event_items'] = [
            [['event_id', 'order_id', 'delivery_cycle'], 'delivery_events', ['id', 'order_id', 'delivery_cycle'], 'RESTRICT', 'RESTRICT'],
            [['order_id'], 'orders', ['id'], 'RESTRICT', 'RESTRICT'],
            [['order_item_id'], 'order_items', ['id'], 'RESTRICT', 'RESTRICT'],
            [['product_id'], 'products', ['id'], 'RESTRICT', 'RESTRICT'],
        ];
    }
    foreach ($fkSpec as $table => $expectedFks) {
        $matched = [];
        foreach ($expectedFks as [$local, $target, $remote, $update, $delete]) {
            $found = false;
            foreach ($foreignKeys[$table] ?? [] as $name => $rows) {
                if (array_column($rows, 'COLUMN_NAME') !== $local || array_column($rows, 'REFERENCED_COLUMN_NAME') !== $remote) continue;
                $valid = true;
                foreach ($rows as $i => $row) {
                    if ($row['REFERENCED_TABLE_NAME'] !== $target || (string) $row['IS_LOCAL'] !== '1'
                        || (string) $row['POSITION_IN_UNIQUE_CONSTRAINT'] !== (string) ($i + 1)
                        || str_replace('NO ACTION', 'RESTRICT', (string) $row['UPDATE_RULE']) !== $update
                        || str_replace('NO ACTION', 'RESTRICT', (string) $row['DELETE_RULE']) !== $delete) $valid = false;
                }
                if ($valid) { $found = true; $matched[] = $name; }
            }
            $identifier = 'foreign_key.' . $table . '.' . implode('_', $local);
            if (!$found) $issues[] = $identifier;
            $supported = false;
            foreach ($indexes[$table] ?? [] as $rows) {
                if ($indexMatches($rows, $local, null, true)) $supported = true;
            }
            if (!$supported) $issues[] = $identifier . '.index';
        }
        if (array_diff(array_keys($foreignKeys[$table] ?? []), $matched) !== []) $issues[] = 'foreign_key.' . $table . '.unexpected';
    }

    $checkSpec = $legacy ? [] : [
        'shipments' => ['chk_delivery_cycle' => 'delivery_cycle >= 1', 'chk_delivery_attempts' => 'failed_attempts BETWEEN 0 AND 3'],
        'delivery_events' => [
            'chk_event_cycle' => 'delivery_cycle >= 1',
            'chk_event_failure' => "(event_type = 'DELIVERY_ATTEMPT_FAILED' AND attempt_number IS NOT NULL AND attempt_number BETWEEN 1 AND 3 AND reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0) OR (event_type <> 'DELIVERY_ATTEMPT_FAILED' AND attempt_number IS NULL)",
        ],
        'delivery_event_items' => [
            'chk_delivery_item_cycle' => 'delivery_cycle >= 1',
            'chk_delivery_item_outcome' => "(record_type = 'RETURNED' AND outcome = 'RETURNED') OR (record_type = 'INSPECTED' AND outcome IN ('RETRY_APPROVED','MANUAL_REVIEW'))",
        ],
    ];
    $mysqlCheckMetadata = delivery_schema_platform($metadata['variables']) === 'mysql';
    foreach ($checkSpec as $table => $expectedChecks) {
        foreach ($expectedChecks as $name => $expression) {
            $row = $checks[$table][$name] ?? [];
            if (($row['ENFORCED'] ?? '') !== 'YES' || !is_string($row['CHECK_CLAUSE'] ?? null)
                || delivery_schema_expression($row['CHECK_CLAUSE'], $mysqlCheckMetadata) !== delivery_schema_expression($expression)) {
                $issues[] = 'check.' . $table . '.' . $name;
            }
        }
    }
    foreach ($checks as $table => $rows) {
        if (array_diff(array_keys($rows), array_keys($checkSpec[$table] ?? [])) !== []) $issues[] = 'check.unexpected';
    }
    $expectedTriggers = [];
    if (!$legacy) {
        foreach (['delivery_events' => 'Delivery history is append-only', 'delivery_event_items' => 'Delivery item history is append-only'] as $table => $message) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = $table . '_no_' . strtolower($event);
                $expectedTriggers[] = $name;
                $row = $triggers[$name] ?? [];
                $body = is_string($row['ACTION_STATEMENT'] ?? null) ? delivery_schema_tokens($row['ACTION_STATEMENT']) : null;
                if ($body !== null && end($body) === ';') array_pop($body);
                if ($body !== null && ($body[0] ?? '') === 'begin' && array_slice($body, -2) === [';', 'end']) {
                    $body = array_slice($body, 1, -2);
                }
                if (($row['EVENT_OBJECT_TABLE'] ?? '') !== $table || ($row['ACTION_TIMING'] ?? '') !== 'BEFORE'
                    || ($row['EVENT_MANIPULATION'] ?? '') !== $event || ($row['ACTION_ORIENTATION'] ?? '') !== 'ROW'
                    || $body !== delivery_schema_tokens("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'")) {
                    $issues[] = 'trigger.' . $name;
                }
            }
        }
    }
    if (array_diff(array_keys($triggers), $expectedTriggers) !== []) $issues[] = 'trigger.unexpected';
    return delivery_schema_result($issues === [] ? ($legacy ? 'legacy' : 'ready') : 'blocked', $issues);
}
