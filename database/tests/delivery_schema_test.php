<?php

declare(strict_types=1);

/*
 * DB-free migration-016 schema gate tests. Only declarations are included.
 * PDO doubles never call parent constructors, open connections, read .env,
 * create databases/tables, or touch business records (including temporary ones).
 * Run: php database/tests/delivery_schema_test.php
 */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../../includes/delivery_schema.php';

$tests = 0;
$failures = 0;

function schema_check(string $label, $actual, $expected): void
{
    global $tests, $failures;
    $tests++;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL: $label\n  expected: " . var_export($expected, true) . "\n  actual: " . var_export($actual, true) . "\n";
    }
}

function schema_result_check(string $label, array $result, string $state): void
{
    $safe = array_keys($result) === ['state', 'message', 'issues']
        && $result['state'] === $state && is_string($result['message']) && $result['message'] !== ''
        && is_array($result['issues']) && ($state === 'blocked' ? $result['issues'] !== [] : $result['issues'] === []);
    foreach ($result['issues'] ?? [] as $issue) {
        $safe = $safe && is_string($issue) && preg_match('/^[a-z][a-z0-9_.]*$/D', $issue) === 1;
    }
    schema_check($label, $safe ? $state : $result, $state);
}

function schema_expect(string $label, array $metadata, string $state = 'blocked'): void
{
    schema_result_check($label, delivery_schema_evaluate($metadata), $state);
}

/** Independently transcribed metadata from schema.sql and migrations 010/015/016. */
function schema_fixture(bool $ready = true, string $platform = 'mysql'): array
{
    $metadata = [
        'variables' => [
            ['Variable_name' => 'version', 'Value' => '8.4.7'],
            ['Variable_name' => 'version_comment', 'Value' => 'MySQL Community Server - GPL'],
            ['Variable_name' => 'foreign_key_checks', 'Value' => 'ON'],
        ],
        'grants' => [['Grants for fixture' => 'GRANT SELECT, TRIGGER ON `delivery_fixture`.* TO `fixture`@`localhost`']],
        'tables' => [], 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => [], 'triggers' => [],
    ];
    $definitions = [
        'shipments' => [
            'id' => ['int unsigned', 'NO', null, 'auto_increment'],
            'order_id' => ['int unsigned', 'NO', null],
            'shipment_status' => ["enum('NOT_SHIPPED','READY_TO_SHIP','SHIPPED','IN_TRANSIT','OUT_FOR_DELIVERY','DELIVERED')", 'NO', 'NOT_SHIPPED'],
            'delivery_method' => ["enum('LOCAL_COURIER','STORE_PICKUP')", 'YES', null],
            'tracking_number' => ['varchar(100)', 'YES', null],
            'shipped_at' => ['datetime', 'YES', null], 'delivered_at' => ['datetime', 'YES', null],
            'created_at' => ['timestamp', 'YES', 'CURRENT_TIMESTAMP', 'DEFAULT_GENERATED'],
            'updated_at' => ['timestamp', 'YES', 'CURRENT_TIMESTAMP', 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP'],
        ],
        'customer_order_notifications' => [
            'id' => ['int unsigned', 'NO', null, 'auto_increment'],
            'customer_id' => ['int unsigned', 'NO', null], 'order_id' => ['int unsigned', 'NO', null],
            'notification_type' => ["enum('ORDER_SHIPPED','ORDER_DELIVERED','PICKUP_READY','PICKUP_HANDED_OVER','ORDER_CANCELLED')", 'NO', null],
            'message' => ['varchar(255)', 'NO', null],
            'created_at' => ['timestamp', 'NO', 'CURRENT_TIMESTAMP', 'DEFAULT_GENERATED'],
            'read_at' => ['datetime', 'YES', null],
        ],
    ];
    foreach (['orders', 'order_items', 'products', 'admins', 'customers'] as $table) {
        $definitions[$table] = ['id' => ['int unsigned', 'NO', null, 'auto_increment']];
    }
    $keys = [
        'shipments' => ['PRIMARY' => [0, ['id']], 'order_id' => [0, ['order_id']]],
        'customer_order_notifications' => [
            'PRIMARY' => [0, ['id']],
            'uq_customer_notification_order_type' => [0, ['customer_id', 'order_id', 'notification_type']],
            'idx_customer_notification_newest' => [1, ['customer_id', 'created_at', 'id']],
            'idx_customer_notification_unread' => [1, ['customer_id', 'read_at']],
            'fk_customer_notification_order' => [1, ['order_id']],
        ],
    ];
    foreach (['orders', 'order_items', 'products', 'admins', 'customers'] as $table) $keys[$table] = ['PRIMARY' => [0, ['id']]];
    $fks = [
        ['shipments', 'fk_shipments_order', ['order_id'], 'orders', ['id'], 'CASCADE', 'CASCADE'],
        ['customer_order_notifications', 'fk_customer_notification_customer', ['customer_id'], 'customers', ['id'], 'CASCADE', 'RESTRICT'],
        ['customer_order_notifications', 'fk_customer_notification_order', ['order_id'], 'orders', ['id'], 'CASCADE', 'RESTRICT'],
    ];
    if ($ready) {
        $definitions['shipments']['shipment_status'][0] = "enum('NOT_SHIPPED','READY_TO_SHIP','SHIPPED','IN_TRANSIT','OUT_FOR_DELIVERY','DELIVERED','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP')";
        $definitions['shipments'] += [
            'delivery_cycle' => ['int unsigned', 'NO', '1'],
            'delivery_revision' => ['int unsigned', 'NO', '0'],
            'failed_attempts' => ['tinyint unsigned', 'NO', '0'],
        ];
        $definitions['customer_order_notifications']['notification_type'][0] = "enum('ORDER_SHIPPED','ORDER_DELIVERED','PICKUP_READY','PICKUP_HANDED_OVER','ORDER_CANCELLED','OUT_FOR_DELIVERY','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP','DELIVERY_CYCLE_AUTHORIZED')";
        $definitions['customer_order_notifications']['occurrence_key'] = ['varchar(64)', 'NO', 'legacy', '', 'ascii'];
        $definitions['delivery_events'] = [
            'id' => ['bigint unsigned', 'NO', null, 'auto_increment'],
            'order_id' => ['int unsigned', 'NO', null], 'delivery_cycle' => ['int unsigned', 'NO', null],
            'delivery_revision' => ['int unsigned', 'NO', null],
            'event_type' => ["enum('OUT_FOR_DELIVERY','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP','RETURN_INSPECTED','DELIVERY_CYCLE_AUTHORIZED','DELIVERED')", 'NO', null],
            'admin_id' => ['int unsigned', 'NO', null], 'attempt_number' => ['tinyint unsigned', 'YES', null],
            'reason' => ['varchar(255)', 'YES', null],
            'previous_state' => ['varchar(32)', 'NO', null], 'resulting_state' => ['varchar(32)', 'NO', null],
            'action_key' => ['varchar(64)', 'NO', null, '', 'ascii'], 'event_index' => ['tinyint unsigned', 'NO', '0'],
            'request_hash' => ['char(64)', 'NO', null, '', 'ascii'],
            'created_at' => ['timestamp', 'NO', 'CURRENT_TIMESTAMP', 'DEFAULT_GENERATED'],
        ];
        $definitions['delivery_event_items'] = [
            'event_id' => ['bigint unsigned', 'NO', null], 'order_id' => ['int unsigned', 'NO', null],
            'delivery_cycle' => ['int unsigned', 'NO', null], 'order_item_id' => ['int unsigned', 'NO', null],
            'product_id' => ['int unsigned', 'NO', null],
            'record_type' => ["enum('RETURNED','INSPECTED')", 'NO', null],
            'outcome' => ["enum('RETURNED','RETRY_APPROVED','MANUAL_REVIEW')", 'NO', null],
        ];
        unset($keys['customer_order_notifications']['uq_customer_notification_order_type']);
        $keys['customer_order_notifications']['uq_customer_notification_occurrence'] = [0, ['customer_id', 'order_id', 'notification_type', 'occurrence_key']];
        $keys['delivery_events'] = [
            'PRIMARY' => [0, ['id']], 'uq_delivery_action' => [0, ['action_key', 'event_index']],
            'uq_delivery_event_identity' => [0, ['id', 'order_id', 'delivery_cycle']],
            'uq_delivery_failure' => [0, ['order_id', 'delivery_cycle', 'attempt_number']],
            'idx_delivery_history' => [1, ['order_id', 'delivery_cycle', 'id']], 'admin_id' => [1, ['admin_id']],
        ];
        $keys['delivery_event_items'] = [
            'PRIMARY' => [0, ['event_id', 'order_item_id']],
            'uq_delivery_item_record' => [0, ['order_id', 'delivery_cycle', 'order_item_id', 'record_type']],
            'event_id' => [1, ['event_id', 'order_id', 'delivery_cycle']],
            'order_item_id' => [1, ['order_item_id']], 'product_id' => [1, ['product_id']],
        ];
        $fks = array_merge($fks, [
            ['delivery_events', 'delivery_events_ibfk_1', ['order_id'], 'orders', ['id'], 'NO ACTION', 'NO ACTION'],
            ['delivery_events', 'delivery_events_ibfk_2', ['admin_id'], 'admins', ['id'], 'NO ACTION', 'NO ACTION'],
            ['delivery_event_items', 'delivery_event_items_ibfk_1', ['event_id', 'order_id', 'delivery_cycle'], 'delivery_events', ['id', 'order_id', 'delivery_cycle'], 'NO ACTION', 'NO ACTION'],
            ['delivery_event_items', 'delivery_event_items_ibfk_2', ['order_id'], 'orders', ['id'], 'NO ACTION', 'NO ACTION'],
            ['delivery_event_items', 'delivery_event_items_ibfk_3', ['order_item_id'], 'order_items', ['id'], 'NO ACTION', 'NO ACTION'],
            ['delivery_event_items', 'delivery_event_items_ibfk_4', ['product_id'], 'products', ['id'], 'NO ACTION', 'NO ACTION'],
        ]);
        $checks = [
            ['shipments', 'chk_delivery_cycle', '(`delivery_cycle` >= 1)'],
            ['shipments', 'chk_delivery_attempts', '(`failed_attempts` between 0 and 3)'],
            ['delivery_events', 'chk_event_cycle', '(`delivery_cycle` >= 1)'],
            ['delivery_events', 'chk_event_failure', "(((`event_type` = _utf8mb4'DELIVERY_ATTEMPT_FAILED') and (`attempt_number` is not null) and (`attempt_number` between 1 and 3) and (`reason` is not null) and (char_length(trim(`reason`)) > 0)) or ((`event_type` <> _utf8mb4'DELIVERY_ATTEMPT_FAILED') and (`attempt_number` is null)))"],
            ['delivery_event_items', 'chk_delivery_item_cycle', '(`delivery_cycle` >= 1)'],
            ['delivery_event_items', 'chk_delivery_item_outcome', "(((`record_type` = _utf8mb4'RETURNED') and (`outcome` = _utf8mb4'RETURNED')) or ((`record_type` = _utf8mb4'INSPECTED') and (`outcome` in (_utf8mb4'RETRY_APPROVED',_utf8mb4'MANUAL_REVIEW'))))"],
        ];
        foreach ($checks as [$table, $name, $clause]) {
            $metadata['checks'][] = ['TABLE_NAME' => $table, 'CONSTRAINT_NAME' => $name, 'ENFORCED' => 'YES', 'CHECK_CLAUSE' => $clause];
        }
        foreach (['delivery_events' => 'Delivery history is append-only', 'delivery_event_items' => 'Delivery item history is append-only'] as $table => $message) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $metadata['triggers'][] = ['TRIGGER_NAME' => $table . '_no_' . strtolower($event), 'EVENT_OBJECT_TABLE' => $table,
                    'ACTION_TIMING' => 'BEFORE', 'EVENT_MANIPULATION' => $event, 'ACTION_ORIENTATION' => 'ROW',
                    'ACTION_STATEMENT' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'"];
            }
        }
    }
    foreach ($definitions as $table => $columns) {
        $metadata['tables'][] = ['TABLE_SCHEMA' => 'delivery_fixture', 'TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB'];
        foreach ($columns as $column => $definition) {
            $text = preg_match('/^(enum|char|varchar)\(/', $definition[0]) === 1;
            $charset = $text ? ($definition[4] ?? 'utf8mb4') : null;
            $metadata['columns'][] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $column,
                'COLUMN_TYPE' => $definition[0], 'IS_NULLABLE' => $definition[1], 'COLUMN_DEFAULT' => $definition[2],
                'EXTRA' => $definition[3] ?? '', 'CHARACTER_SET_NAME' => $charset,
                'COLLATION_NAME' => $charset === 'ascii' ? 'ascii_bin' : ($text ? 'utf8mb4_0900_ai_ci' : null),
                'GENERATION_EXPRESSION' => ''];
        }
    }
    foreach ($keys as $table => $indexes) {
        foreach ($indexes as $name => [$nonUnique, $columns]) {
            foreach ($columns as $i => $column) {
                $metadata['indexes'][] = ['TABLE_NAME' => $table, 'INDEX_NAME' => $name, 'NON_UNIQUE' => $nonUnique,
                    'SEQ_IN_INDEX' => $i + 1, 'COLUMN_NAME' => $column, 'SUB_PART' => null, 'INDEX_TYPE' => 'BTREE',
                    'COLLATION' => 'A', 'IS_USABLE' => 'YES'];
            }
        }
    }
    foreach ($fks as [$table, $name, $local, $target, $remote, $update, $delete]) {
        foreach ($local as $i => $column) {
            $metadata['foreign_keys'][] = ['TABLE_NAME' => $table, 'CONSTRAINT_NAME' => $name, 'ORDINAL_POSITION' => $i + 1,
                'COLUMN_NAME' => $column, 'REFERENCED_TABLE_NAME' => $target, 'REFERENCED_COLUMN_NAME' => $remote[$i],
                'POSITION_IN_UNIQUE_CONSTRAINT' => $i + 1, 'IS_LOCAL' => 1, 'UPDATE_RULE' => $update, 'DELETE_RULE' => $delete];
        }
    }
    if ($platform === 'mariadb') {
        $metadata['variables'][0]['Value'] = '11.4.8-MariaDB-ubu2404';
        $metadata['variables'][1]['Value'] = 'mariadb.org binary distribution';
        $metadata['variables'][] = ['Variable_name' => 'check_constraint_checks', 'Value' => 'ON'];
        foreach ($metadata['columns'] as &$row) {
            $row['COLUMN_TYPE'] = str_replace(['tinyint unsigned', 'bigint unsigned', 'int unsigned'], ['tinyint(3) unsigned', 'bigint(20) unsigned', 'int(10) unsigned'], $row['COLUMN_TYPE']);
            $row['EXTRA'] = trim(str_replace('DEFAULT_GENERATED', '', $row['EXTRA']));
            if ($row['COLUMN_DEFAULT'] === 'CURRENT_TIMESTAMP') $row['COLUMN_DEFAULT'] = 'current_timestamp()';
            elseif ($row['COLUMN_DEFAULT'] === null) $row['COLUMN_DEFAULT'] = 'NULL';
            else $row['COLUMN_DEFAULT'] = "'" . $row['COLUMN_DEFAULT'] . "'";
            if ($row['COLLATION_NAME'] === 'utf8mb4_0900_ai_ci') $row['COLLATION_NAME'] = 'utf8mb4_unicode_ci';
        }
        unset($row);
        foreach ($metadata['checks'] as &$row) $row['CHECK_CLAUSE'] = str_replace('_utf8mb4', '', $row['CHECK_CLAUSE']);
        unset($row);
        foreach ($metadata['foreign_keys'] as &$row) {
            $row['UPDATE_RULE'] = str_replace('NO ACTION', 'RESTRICT', $row['UPDATE_RULE']);
            $row['DELETE_RULE'] = str_replace('NO ACTION', 'RESTRICT', $row['DELETE_RULE']);
        }
        unset($row);
    }
    return $metadata;
}

function schema_groups(array $rows, string $name): array
{
    $groups = [];
    foreach ($rows as $i => $row) $groups[$row['TABLE_NAME'] . '.' . $row[$name]][] = $i;
    return $groups;
}

function schema_copy_table(array &$to, array $from, string $table): void
{
    foreach (['tables', 'columns', 'indexes', 'foreign_keys', 'checks'] as $section) {
        $to[$section] = array_values(array_filter($to[$section], static fn (array $row): bool => $row['TABLE_NAME'] !== $table));
        foreach ($from[$section] as $row) if ($row['TABLE_NAME'] === $table) $to[$section][] = $row;
    }
}

$ready = schema_fixture();
$legacy = schema_fixture(false);
$maria = schema_fixture(true, 'mariadb');

// Raw PDO CHECK_CLAUSE values captured on disposable MySQL 8.4.11. Each \'
// contains bytes 5C27; SHOW CREATE TABLE emits ordinary quote delimiters instead.
$mysql8411 = $ready;
$mysql8411['variables'][0]['Value'] = '8.4.11';
$mysql8411Clauses = [
    'chk_event_failure' => <<<'SQL'
(((`event_type` = _utf8mb4\'DELIVERY_ATTEMPT_FAILED\') and (`attempt_number` is not null) and (`attempt_number` between 1 and 3) and (`reason` is not null) and (char_length(trim(`reason`)) > 0)) or ((`event_type` <> _utf8mb4\'DELIVERY_ATTEMPT_FAILED\') and (`attempt_number` is null)))
SQL,
    'chk_delivery_item_outcome' => <<<'SQL'
(((`record_type` = _utf8mb4\'RETURNED\') and (`outcome` = _utf8mb4\'RETURNED\')) or ((`record_type` = _utf8mb4\'INSPECTED\') and (`outcome` in (_utf8mb4\'RETRY_APPROVED\',_utf8mb4\'MANUAL_REVIEW\'))))
SQL,
];
foreach ($mysql8411['checks'] as &$row) {
    if (isset($mysql8411Clauses[$row['CONSTRAINT_NAME']])) $row['CHECK_CLAUSE'] = $mysql8411Clauses[$row['CONSTRAINT_NAME']];
}
unset($row);

echo "== Complete snapshots and normalization ==\n";
schema_expect('MySQL ready', $ready, 'ready');
schema_expect('exact MySQL 8.4.11 CHECK metadata ready', $mysql8411, 'ready');
schema_expect('MariaDB ready', $maria, 'ready');
schema_expect('MySQL pre-016', $legacy, 'legacy');
schema_expect('MariaDB pre-016', schema_fixture(false, 'mariadb'), 'legacy');
$before = $ready;
delivery_schema_evaluate($ready);
schema_check('pure evaluator leaves snapshot unchanged', $ready, $before);
$fixture = $maria;
$fixture['variables'][0]['Value'] = '5.5.5-11.4.8-MariaDB';
schema_expect('MariaDB compatibility version prefix', $fixture, 'ready');
$fixture = $ready;
foreach ($fixture['columns'] as &$row) {
    if (in_array($row['COLUMN_NAME'], ['created_at', 'updated_at'], true) && $row['TABLE_NAME'] === 'shipments') $row['IS_NULLABLE'] = 'NO';
}
unset($row);
schema_expect('implicit pre-016 TIMESTAMP nullability variant', $fixture, 'ready');
$fixture = $ready;
foreach (['tables', 'columns', 'indexes', 'foreign_keys', 'checks', 'triggers'] as $section) $fixture[$section] = array_reverse($fixture[$section]);
schema_expect('unordered metadata rows', $fixture, 'ready');
$fixture = $ready;
foreach ($fixture['indexes'] as &$row) {
    if (in_array($row['INDEX_NAME'], ['order_id', 'admin_id', 'event_id', 'order_item_id', 'product_id', 'fk_customer_notification_order'], true)) $row['INDEX_NAME'] = 'generated_' . $row['INDEX_NAME'];
    $row['SEQ_IN_INDEX'] = (string) $row['SEQ_IN_INDEX'];
    $row['NON_UNIQUE'] = (string) $row['NON_UNIQUE'];
}
unset($row);
foreach ($fixture['foreign_keys'] as &$row) $row['CONSTRAINT_NAME'] = 'generated_' . $row['CONSTRAINT_NAME'];
unset($row);
schema_expect('generated FK/index names and numeric metadata strings', $fixture, 'ready');
foreach (['utf8mb4', 'utf8mb3', 'utf8', 'ascii', 'latin1'] as $charset) {
    $fixture = $ready;
    foreach ($fixture['checks'] as &$row) $row['CHECK_CLAUSE'] = '((' . str_replace('_utf8mb4', '_' . $charset, $row['CHECK_CLAUSE']) . '))';
    unset($row);
    schema_expect("check introducer $charset and redundant parentheses", $fixture, 'ready');
}
$fixture = $ready;
foreach ($fixture['checks'] as &$row) $row['CHECK_CLAUSE'] = str_replace('<>', '!=', $row['CHECK_CLAUSE']);
unset($row);
foreach ($fixture['triggers'] as &$row) $row['ACTION_STATEMENT'] = "begin\n" . $row['ACTION_STATEMENT'] . ";\nend;";
unset($row);
schema_expect('equivalent inequality and single SIGNAL block', $fixture, 'ready');

echo "== MySQL 8.4.11 escaped CHECK delimiters fail closed ==\n";
foreach ($mysql8411['checks'] as $i => $row) {
    $name = $row['CONSTRAINT_NAME'];
    if (!isset($mysql8411Clauses[$name])) continue;
    $clause = $row['CHECK_CLAUSE'];
    schema_check("$name actual escaped delimiter bytes", str_contains(bin2hex($clause), '5c27'), true);
    schema_check("$name exact metadata expression", delivery_schema_expression($clause, true),
        delivery_schema_expression($ready['checks'][$i]['CHECK_CLAUSE']));
    schema_check("$name escaped delimiters are not ordinary SQL", delivery_schema_tokens($clause), null);
    $fixture = $maria;
    $fixture['checks'][$i]['CHECK_CLAUSE'] = $clause;
    schema_expect("$name MySQL escaping not accepted as MariaDB metadata", $fixture);
    $fixture = $mysql8411;
    unset($fixture['checks'][$i]);
    schema_expect("$name missing with real MySQL metadata", $fixture);
    $fixture = $mysql8411;
    $fixture['checks'][$i]['ENFORCED'] = 'NO';
    schema_expect("$name unenforced with real MySQL metadata", $fixture);
    $drifts = [
        'empty' => '', 'tautology' => '1 = 1', 'weakened OR' => "($clause) OR 1 = 1",
        'changed boolean operator' => str_replace(' and ', ' or ', $clause),
        'lowercase literal' => str_replace($name === 'chk_event_failure' ? 'DELIVERY_ATTEMPT_FAILED' : 'RETURNED',
            $name === 'chk_event_failure' ? 'delivery_attempt_failed' : 'returned', $clause),
        'unexpected literal' => str_replace($name === 'chk_event_failure' ? 'DELIVERY_ATTEMPT_FAILED' : 'RETRY_APPROVED', 'UNKNOWN', $clause),
        'missing parentheses' => substr($clause, 1),
        'mixed quote delimiters' => preg_replace("/\\\\'/", "'", $clause, 1),
        'doubled backslashes' => str_replace("\\'", "\\\\'", $clause),
        'unterminated strings' => str_replace("\\')", ')', $clause),
        'embedded escaped quote' => str_replace($name === 'chk_event_failure' ? 'DELIVERY_ATTEMPT_FAILED' : 'RETURNED', "UN\\'EXPECTED", $clause),
        'unknown escape in content' => str_replace($name === 'chk_event_failure' ? 'DELIVERY_ATTEMPT_FAILED' : 'RETURNED', 'UN\\nEXPECTED', $clause),
        'comment suffix' => $clause . ' /* unexpected */', 'statement suffix' => $clause . ';',
    ];
    if ($name === 'chk_event_failure') {
        $drifts += [
            'blank reason permitted' => str_replace('> 0', '>= 0', $clause),
            'fourth attempt permitted' => str_replace('between 1 and 3', 'between 1 and 4', $clause),
            'null attempt permitted' => str_replace('`attempt_number` is not null', '`attempt_number` is null', $clause),
            'missing reason guard' => str_replace(' and (`reason` is not null)', '', $clause),
        ];
    } else {
        $drifts += [
            'wrong return association' => str_replace("`outcome` = _utf8mb4\\'RETURNED\\'", "`outcome` = _utf8mb4\\'RETRY_APPROVED\\'", $clause),
            'missing manual review outcome' => str_replace(",_utf8mb4\\'MANUAL_REVIEW\\'", '', $clause),
            'unexpected inspection outcome' => str_replace("_utf8mb4\\'MANUAL_REVIEW\\'", "_utf8mb4\\'MANUAL_REVIEW\\',_utf8mb4\\'RETURNED\\'", $clause),
        ];
    }
    foreach ($drifts as $label => $drift) {
        $fixture = $mysql8411;
        $fixture['checks'][$i]['CHECK_CLAUSE'] = $drift;
        $result = delivery_schema_evaluate($fixture);
        schema_result_check("$name $label", $result, 'blocked');
        schema_check("$name $label reports affected check", in_array('check.' . $row['TABLE_NAME'] . '.' . $name, $result['issues'], true), true);
    }
}
$fixture = $mysql8411;
$fixture['checks'][] = array_replace($fixture['checks'][3], ['CONSTRAINT_NAME' => 'unexpected_check']);
schema_expect('unexpected check with MySQL 8.4.11 metadata', $fixture);
$fixture = $mysql8411;
$fixture['triggers'][0]['ACTION_STATEMENT'] = str_replace("'", "\\'", $fixture['triggers'][0]['ACTION_STATEMENT']);
schema_expect('CHECK escaping does not relax trigger SQL', $fixture);
$fixture = $mysql8411;
$fixture['columns'][2]['COLUMN_TYPE'] = str_replace("'", "\\'", $fixture['columns'][2]['COLUMN_TYPE']);
schema_expect('CHECK escaping does not relax column type SQL', $fixture);

echo "== Every required table and column ==\n";
foreach (['ready' => $ready, 'legacy' => $legacy] as $kind => $snapshot) {
    foreach ($snapshot['tables'] as $i => $row) {
        $fixture = $snapshot;
        unset($fixture['tables'][$i]);
        schema_expect("$kind missing table {$row['TABLE_NAME']}", $fixture);
    }
    foreach ($snapshot['columns'] as $i => $row) {
        $fixture = $snapshot;
        unset($fixture['columns'][$i]);
        schema_expect("$kind missing column {$row['TABLE_NAME']}.{$row['COLUMN_NAME']}", $fixture);
    }
}
foreach ($ready['tables'] as $i => $row) {
    foreach (['ENGINE' => 'MyISAM', 'TABLE_TYPE' => 'VIEW'] as $field => $value) {
        $fixture = $ready;
        $fixture['tables'][$i][$field] = $value;
        schema_expect("{$row['TABLE_NAME']} drift $field", $fixture);
    }
}
foreach ($ready['columns'] as $i => $row) {
    $label = $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'];
    $drifts = ['COLUMN_TYPE' => 'varchar(17)', 'IS_NULLABLE' => 'UNKNOWN', 'COLUMN_DEFAULT' => 'unexpected',
        'EXTRA' => 'VIRTUAL GENERATED', 'GENERATION_EXPRESSION' => '1'];
    if ($row['CHARACTER_SET_NAME'] !== null) $drifts['CHARACTER_SET_NAME'] = null;
    else $drifts['COLLATION_NAME'] = 'ascii_bin';
    foreach ($drifts as $field => $value) {
        $fixture = $ready;
        $fixture['columns'][$i][$field] = $value;
        schema_expect("$label drift $field", $fixture);
    }
    if (!($row['TABLE_NAME'] === 'shipments' && in_array($row['COLUMN_NAME'], ['created_at', 'updated_at'], true))) {
        $fixture = $ready;
        $fixture['columns'][$i]['IS_NULLABLE'] = $row['IS_NULLABLE'] === 'YES' ? 'NO' : 'YES';
        schema_expect("$label changed nullability", $fixture);
    }
    if ($row['CHARACTER_SET_NAME'] === 'ascii') {
        foreach (['CHARACTER_SET_NAME' => 'utf8mb4', 'COLLATION_NAME' => 'ascii_general_ci'] as $field => $value) {
            $fixture = $ready;
            $fixture['columns'][$i][$field] = $value;
            schema_expect("$label explicit $field changed", $fixture);
        }
    }
    if (str_contains($row['COLUMN_TYPE'], ' unsigned')) {
        foreach ([str_replace(' unsigned', '', $row['COLUMN_TYPE']), $row['COLUMN_TYPE'] . ' zerofill'] as $type) {
            $fixture = $ready;
            $fixture['columns'][$i]['COLUMN_TYPE'] = $type;
            schema_expect("$label rejects $type", $fixture);
        }
    }
    if (str_starts_with($row['COLUMN_TYPE'], 'enum(')) {
        preg_match_all("/'([^']*)'/", $row['COLUMN_TYPE'], $matches);
        $members = $matches[1];
        $swapped = $members;
        [$swapped[0], $swapped[1]] = [$swapped[1], $swapped[0]];
        foreach ([$swapped, array_slice($members, 0, -1), array_merge($members, ['UNKNOWN']), array_map('strtolower', $members)] as $values) {
            $fixture = $ready;
            $fixture['columns'][$i]['COLUMN_TYPE'] = "enum('" . implode("','", $values) . "')";
            schema_expect("$label exact enum members and ordinals " . implode(',', $values), $fixture);
        }
    }
}

echo "== Every key and foreign key ==\n";
foreach (schema_groups($ready['indexes'], 'INDEX_NAME') as $label => $positions) {
    $fixture = $ready;
    foreach ($positions as $i) unset($fixture['indexes'][$i]);
    schema_expect("missing index $label", $fixture);
    foreach (['SUB_PART' => 1, 'INDEX_TYPE' => 'HASH', 'COLLATION' => 'D', 'IS_USABLE' => 'NO'] as $field => $value) {
        $fixture = $ready;
        $fixture['indexes'][$positions[0]][$field] = $value;
        schema_expect("$label drift $field", $fixture);
    }
    $fixture = $ready;
    foreach ($positions as $i) $fixture['indexes'][$i]['NON_UNIQUE'] = 1 - $fixture['indexes'][$i]['NON_UNIQUE'];
    schema_expect("$label changed uniqueness", $fixture);
    $fixture = $ready;
    if (count($positions) > 1) {
        $columns = array_reverse(array_map(static fn (int $i): string => $ready['indexes'][$i]['COLUMN_NAME'], $positions));
        foreach ($positions as $j => $i) $fixture['indexes'][$i]['COLUMN_NAME'] = $columns[$j];
    } else $fixture['indexes'][$positions[0]]['COLUMN_NAME'] = 'wrong_column';
    schema_expect("$label ordered full columns", $fixture);
}
foreach (schema_groups($legacy['indexes'], 'INDEX_NAME') as $label => $positions) {
    $fixture = $legacy;
    foreach ($positions as $i) unset($fixture['indexes'][$i]);
    schema_expect("legacy missing index $label", $fixture);
}
foreach (schema_groups($ready['foreign_keys'], 'CONSTRAINT_NAME') as $label => $positions) {
    $fixture = $ready;
    foreach ($positions as $i) unset($fixture['foreign_keys'][$i]);
    schema_expect("missing foreign key $label", $fixture);
    $row = $ready['foreign_keys'][$positions[0]];
    foreach (['COLUMN_NAME' => 'wrong_column', 'REFERENCED_TABLE_NAME' => 'wrong_table', 'REFERENCED_COLUMN_NAME' => 'wrong_column',
        'POSITION_IN_UNIQUE_CONSTRAINT' => 2, 'IS_LOCAL' => 0,
        'UPDATE_RULE' => $row['UPDATE_RULE'] === 'CASCADE' ? 'RESTRICT' : 'CASCADE',
        'DELETE_RULE' => $row['DELETE_RULE'] === 'CASCADE' ? 'RESTRICT' : 'CASCADE'] as $field => $value) {
        $fixture = $ready;
        $fixture['foreign_keys'][$positions[0]][$field] = $value;
        schema_expect("$label drift $field", $fixture);
    }
    if (count($positions) > 1) {
        $fixture = $ready;
        [$fixture['foreign_keys'][$positions[0]]['REFERENCED_COLUMN_NAME'], $fixture['foreign_keys'][$positions[1]]['REFERENCED_COLUMN_NAME']]
            = [$fixture['foreign_keys'][$positions[1]]['REFERENCED_COLUMN_NAME'], $fixture['foreign_keys'][$positions[0]]['REFERENCED_COLUMN_NAME']];
        schema_expect("$label target component ordering", $fixture);
    }
}
foreach (['uq_customer_notification_order_type', 'renamed_old_unique'] as $name) {
    $fixture = $ready;
    foreach ($legacy['indexes'] as $row) {
        if ($row['INDEX_NAME'] !== 'uq_customer_notification_order_type') continue;
        $row['INDEX_NAME'] = $name;
        $fixture['indexes'][] = $row;
    }
    schema_expect("old notification uniqueness remains as $name", $fixture);
}
$fixture = $ready;
$fixture['indexes'][] = ['TABLE_NAME' => 'shipments', 'INDEX_NAME' => 'optional_lookup', 'NON_UNIQUE' => 1,
    'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'shipment_status', 'SUB_PART' => null, 'INDEX_TYPE' => 'BTREE', 'COLLATION' => 'A', 'IS_USABLE' => 'YES'];
schema_expect('harmless extra nonunique lookup', $fixture, 'ready');

echo "== All six checks and four append-only triggers ==\n";
foreach (['mysql' => $ready, 'mariadb' => $maria] as $platform => $snapshot) {
    foreach ($snapshot['checks'] as $i => $row) {
        $fixture = $snapshot;
        unset($fixture['checks'][$i]);
        schema_expect("$platform missing {$row['CONSTRAINT_NAME']}", $fixture);
        foreach (['CONSTRAINT_NAME' => 'renamed', 'ENFORCED' => 'NO', 'CHECK_CLAUSE' => '1 = 1'] as $field => $value) {
            $fixture = $snapshot;
            $fixture['checks'][$i][$field] = $value;
            schema_expect("$platform {$row['CONSTRAINT_NAME']} drift $field", $fixture);
        }
    }
}
$fixture = $ready;
$fixture['checks'][3]['CHECK_CLAUSE'] = "event_type = 'DELIVERY_ATTEMPT_FAILED' AND attempt_number IS NOT NULL AND attempt_number BETWEEN 1 AND 3 AND reason IS NOT NULL AND (CHAR_LENGTH(TRIM(reason)) > 0 OR event_type <> 'DELIVERY_ATTEMPT_FAILED') AND attempt_number IS NULL";
schema_expect('same flat tokens with unsafe boolean grouping', $fixture);
foreach (['>= 0', '> -1', '> 0 OR 1 = 1'] as $replacement) {
    $fixture = $ready;
    $fixture['checks'][3]['CHECK_CLAUSE'] = str_replace('> 0', $replacement, $fixture['checks'][3]['CHECK_CLAUSE']);
    schema_expect("failure reason guard changed to $replacement", $fixture);
}
foreach ($ready['triggers'] as $i => $row) {
    $fixture = $ready;
    unset($fixture['triggers'][$i]);
    schema_expect("missing {$row['TRIGGER_NAME']}", $fixture);
    foreach (['TRIGGER_NAME' => 'renamed', 'EVENT_OBJECT_TABLE' => 'orders', 'ACTION_TIMING' => 'AFTER',
        'EVENT_MANIPULATION' => 'INSERT', 'ACTION_ORIENTATION' => 'STATEMENT',
        'ACTION_STATEMENT' => 'SET @ignored = 1'] as $field => $value) {
        $fixture = $ready;
        $fixture['triggers'][$i][$field] = $value;
        schema_expect("{$row['TRIGGER_NAME']} drift $field", $fixture);
    }
    foreach ([str_replace('45000', '01000', $row['ACTION_STATEMENT']), str_replace('append-only', 'changed', $row['ACTION_STATEMENT']),
        'BEGIN IF 0 THEN ' . $row['ACTION_STATEMENT'] . '; END IF; END',
        $row['ACTION_STATEMENT'] . '; SET @ignored = 1'] as $body) {
        $fixture = $ready;
        $fixture['triggers'][$i]['ACTION_STATEMENT'] = $body;
        schema_expect("{$row['TRIGGER_NAME']} rejects changed/conditional SIGNAL", $fixture);
    }
}
$fixture = $ready;
$fixture['triggers'][] = array_replace($ready['triggers'][0], ['TRIGGER_NAME' => 'unexpected_insert', 'EVENT_MANIPULATION' => 'INSERT']);
schema_expect('unexpected delivery trigger', $fixture);

echo "== Partial rollout and unknown metadata ==\n";
$stage = $legacy;
schema_copy_table($stage, $ready, 'shipments');
schema_expect('S1 shipments only', $stage);
schema_copy_table($stage, $ready, 'delivery_events');
schema_expect('S2 events without items', $stage);
schema_copy_table($stage, $ready, 'delivery_event_items');
schema_expect('S3 no triggers/occurrences', $stage);
foreach ($ready['triggers'] as $i => $row) {
    $stage['triggers'][] = $row;
    schema_expect('S4 trigger stage ' . ($i + 1), $stage);
}
schema_copy_table($stage, $ready, 'customer_order_notifications');
schema_expect('S5 all stages complete', $stage, 'ready');
foreach (['delivery_events', 'delivery_event_items'] as $table) {
    $fixture = $legacy;
    foreach ($ready['tables'] as $row) if ($row['TABLE_NAME'] === $table) $fixture['tables'][] = $row;
    schema_expect("legacy plus empty $table", $fixture);
}
foreach ($ready['columns'] as $row) {
    if (!in_array($row['TABLE_NAME'], ['shipments', 'customer_order_notifications'], true)) continue;
    if (!in_array($row['COLUMN_NAME'], ['delivery_cycle', 'delivery_revision', 'failed_attempts', 'occurrence_key', 'shipment_status', 'notification_type'], true)) continue;
    $fixture = $legacy;
    $fixture['columns'] = array_values(array_filter($fixture['columns'], static fn (array $old): bool => !($old['TABLE_NAME'] === $row['TABLE_NAME'] && $old['COLUMN_NAME'] === $row['COLUMN_NAME'])));
    $fixture['columns'][] = $row;
    schema_expect("legacy plus only {$row['TABLE_NAME']}.{$row['COLUMN_NAME']}", $fixture);
}
foreach (['checks', 'triggers'] as $section) {
    foreach ($ready[$section] as $row) {
        $fixture = $legacy;
        $fixture[$section][] = $row;
        schema_expect("legacy plus one $section artifact", $fixture);
    }
}
foreach (array_keys($ready) as $section) {
    $fixture = $ready;
    unset($fixture[$section]);
    schema_expect("missing metadata section $section", $fixture);
    $fixture = $ready;
    $fixture[$section] = [];
    schema_expect("empty metadata section $section", $fixture);
}
foreach (['tables', 'columns', 'indexes', 'foreign_keys', 'checks', 'triggers'] as $section) {
    foreach (array_keys($ready[$section][0]) as $field) {
        $fixture = $ready;
        unset($fixture[$section][0][$field]);
        schema_expect("unknown metadata $section missing $field", $fixture);
    }
    $fixture = $ready;
    $fixture[$section][] = $fixture[$section][0];
    schema_expect("duplicate $section metadata", $fixture);
}
schema_expect('entire metadata absent', []);
foreach (['8.0.15', '8.0.40', '9.0.1', '11.3.9-MariaDB', 'garbage'] as $version) {
    $fixture = $ready;
    $fixture['variables'][0]['Value'] = $version;
    schema_expect("unsupported version $version", $fixture);
}
foreach ([$ready, $legacy, $maria] as $snapshot) {
    $snapshot['variables'][2]['Value'] = 'OFF';
    schema_expect('disabled foreign key enforcement', $snapshot);
}
foreach (['OFF', 'unknown'] as $value) {
    $fixture = $maria;
    $fixture['variables'][3]['Value'] = $value;
    schema_expect("MariaDB check enforcement $value", $fixture);
}
$fixture = $maria;
unset($fixture['variables'][3]);
schema_expect('MariaDB unknown check enforcement', $fixture);
foreach ([
    'GRANT SELECT ON `delivery_fixture`.* TO `fixture`@`localhost`',
    'GRANT TRIGGER ON `delivery_fixture`.* TO `fixture`@`localhost`',
    'GRANT SELECT, TRIGGER ON `other_schema`.* TO `fixture`@`localhost`',
    'GRANT SELECT, TRIGGER ON `delivery_fixture`.`shipments` TO `fixture`@`localhost`',
    'GRANT `role_with_unknown_effective_privileges` TO `fixture`@`localhost`',
] as $grant) {
    foreach ([$ready, $legacy] as $fixture) {
        $fixture['grants'] = [['grant' => $grant]];
        schema_expect('hidden metadata cannot imply ready or legacy', $fixture);
    }
}
$fixture = $ready;
$fixture['grants'][] = ['grant' => 'REVOKE SELECT ON `delivery_fixture`.* FROM `fixture`@`localhost`'];
schema_expect('partial revoke unknown visibility', $fixture);
$fixture = $ready;
$fixture['grants'] = [['grant' => 'GRANT ALL PRIVILEGES ON *.* TO `fixture`@`localhost`']];
schema_expect('direct global metadata privileges', $fixture, 'ready');
$fixture = $ready;
$fixture['checks'][0]['CONSTRAINT_NAME'] = '<script>private-db/password</script>';
$result = delivery_schema_evaluate($fixture);
schema_result_check('untrusted structural identifiers are not reflected', $result, 'blocked');
schema_check('no untrusted text in result', str_contains(json_encode($result), 'private-db'), false);

/** A strict in-memory transport; constructors NEVER invoke PDO constructors. */
final class SchemaTestPDO extends PDO
{
    public array $queries = [];
    public array $forbidden = [];

    public function __construct(private array $metadata, private ?int $failAt = null, private string $failure = 'throw') {}

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = preg_replace('/\s+/', ' ', trim($query));
        $position = count($this->queries);
        $this->queries[] = $query;
        $sections = ['variables', 'grants', 'tables', 'columns', 'indexes', 'foreign_keys', 'checks', 'triggers'];
        $section = $sections[$position] ?? '';
        $sources = ['tables' => 'TABLES', 'columns' => 'COLUMNS', 'indexes' => 'STATISTICS',
            'foreign_keys' => 'KEY_COLUMN_USAGE', 'checks' => 'TABLE_CONSTRAINTS', 'triggers' => 'TRIGGERS'];
        $safe = $section === 'variables'
            ? $query === "SHOW SESSION VARIABLES WHERE Variable_name IN ('version','version_comment','foreign_key_checks','check_constraint_checks')"
            : ($section === 'grants' ? $query === 'SHOW GRANTS'
                : isset($sources[$section]) && str_starts_with($query, 'SELECT ')
                    && str_contains($query, 'FROM information_schema.' . $sources[$section] . ' ')
                    && str_contains($query, 'DATABASE()'));
        $safe = $safe && preg_match('/;|\b(?:INSERT|DELETE|UPDATE|ALTER|CREATE|DROP|TRUNCATE|CALL|INTO|SET|LOCK|UNLOCK)\b/i', $query) !== 1;
        preg_match_all('/\b(?:FROM|JOIN)\s+([^\s]+)/i', $query, $matches);
        foreach ($matches[1] as $source) $safe = $safe && str_starts_with($source, 'information_schema.');
        if (!$safe) {
            $this->forbidden[] = $query;
            throw new LogicException('Non-metadata SQL or unexpected query sequence.');
        }
        if ($position === $this->failAt) {
            if ($this->failure === 'false') return false;
            if ($this->failure === 'throw') throw new PDOException('private-db.example password=secret /srv/private.sql SELECT hidden');
        }
        return new SchemaTestStatement($this->metadata[$section], $position === $this->failAt && $this->failure === 'fetch');
    }

    public function exec(string $statement): int|false { $this->forbidden[] = 'exec'; return false; }
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->forbidden[] = 'prepare'; return false; }
    public function beginTransaction(): bool { $this->forbidden[] = 'begin'; return false; }
    public function commit(): bool { $this->forbidden[] = 'commit'; return false; }
    public function rollBack(): bool { $this->forbidden[] = 'rollback'; return false; }
    public function setAttribute(int $attribute, mixed $value): bool { $this->forbidden[] = 'setAttribute'; return false; }
}

final class SchemaTestStatement extends PDOStatement
{
    public function __construct(private array $rows, private bool $fail) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($this->fail) throw new PDOException('private metadata fetch failure');
        if ($mode !== PDO::FETCH_ASSOC) throw new LogicException('Expected associative metadata.');
        return $this->rows;
    }
}

echo "== Read-only PDO transport and every query failure ==\n";
foreach (['ready' => $ready, 'legacy' => $legacy, 'mariadb' => $maria, 'mysql8411' => $mysql8411] as $kind => $snapshot) {
    $pdo = new SchemaTestPDO($snapshot);
    schema_result_check("PDO $kind", delivery_schema_status($pdo), $kind === 'legacy' ? 'legacy' : 'ready');
    schema_check("PDO $kind eight metadata queries", count($pdo->queries), 8);
    schema_check("PDO $kind no writes/settings/transactions", $pdo->forbidden, []);
    schema_check("PDO $kind check enforcement dialect", str_contains($pdo->queries[6], 't.ENFORCED AS ENFORCED'), $kind !== 'mariadb');
    schema_check("PDO $kind index visibility dialect", str_contains($pdo->queries[4], 'IS_VISIBLE AS IS_USABLE'), $kind !== 'mariadb');
}
$fixture = $mysql8411;
$fixture['checks'][3]['CHECK_CLAUSE'] .= ' OR 1 = 1';
$pdo = new SchemaTestPDO($fixture);
schema_result_check('PDO MySQL 8.4.11 weakened failure check blocked', delivery_schema_status($pdo), 'blocked');
$fixture = $mysql8411;
$fixture['checks'][5]['CHECK_CLAUSE'] = '';
$pdo = new SchemaTestPDO($fixture);
schema_result_check('PDO MySQL 8.4.11 malformed outcome check blocked', delivery_schema_status($pdo), 'blocked');
foreach ([$ready, $legacy, $maria] as $snapshot) {
    foreach (['throw', 'false', 'fetch'] as $failure) {
        for ($i = 0; $i < 8; $i++) {
            $pdo = new SchemaTestPDO($snapshot, $i, $failure);
            $result = delivery_schema_status($pdo);
            schema_result_check("query $i $failure fails closed", $result, 'blocked');
            schema_check("query $i $failure fixed safe issue", $result['issues'], ['metadata.query_failed']);
            schema_check("query $i $failure no follow-up or writes", [count($pdo->queries), $pdo->forbidden], [$i + 1, []]);
            schema_check("query $i $failure no server detail leak", str_contains(json_encode($result), 'private'), false);
        }
    }
}
$pdo = new SchemaTestPDO($ready);
schema_result_check('first uncached read', delivery_schema_status($pdo), 'ready');
schema_result_check('second call rereads rather than caching prior success', delivery_schema_status($pdo), 'blocked');
schema_check('second call attempted fresh metadata', count($pdo->queries), 9);

echo "\n$tests tests, $failures failures. DB-free; no connections or database writes.\n";
exit($failures === 0 ? 0 : 1);
