<?php

declare(strict_types=1);

// DB-free only. No PDO driver construction, application bootstrap or HTTP writes.
if (PHP_SAPI !== 'cli' || $argc !== 1) exit(2);
require_once __DIR__ . '/../../includes/return_stock.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = $failures = 0;
function stockCheck(string $label, mixed $actual, mixed $expected): void
{
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) { $failures++; echo 'FAIL ' . $label . ': ' . var_export($actual, true) . PHP_EOL; }
}

function stockMetadata(): array
{
    $spec = return_stock_schema_definition();
    $m = ['variables' => [
        ['Variable_name' => 'version', 'Value' => '8.4.11'], ['Variable_name' => 'version_comment', 'Value' => 'MySQL Community Server'],
        ['Variable_name' => 'foreign_key_checks', 'Value' => 'ON'],
    ], 'grants' => [['grant' => 'GRANT SELECT, TRIGGER ON `synthetic_only`.* TO `memory`@`localhost`']],
        'tables' => [], 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => [], 'triggers' => []];
    foreach (['return_stock_events','orders','order_items','products','admins','delivery_events','delivery_event_items'] as $table) {
        $m['tables'][] = ['TABLE_SCHEMA' => 'synthetic_only', 'TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB'];
    }
    foreach ($spec['columns'] as $name => [$type,$extra]) {
        $text = str_contains($type, '(');
        $ascii = in_array($name, ['action_key','request_hash'], true);
        $m['columns'][] = ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => 'NO',
            'COLUMN_DEFAULT' => $name === 'created_at' ? 'CURRENT_TIMESTAMP' : null, 'EXTRA' => $extra, 'GENERATION_EXPRESSION' => '',
            'CHARACTER_SET_NAME' => $text ? ($ascii ? 'ascii' : 'utf8mb4') : null,
            'COLLATION_NAME' => $text ? ($ascii ? 'ascii_bin' : 'utf8mb4_general_ci') : null];
    }
    foreach ($spec['indexes'] as $name => [$unique,$cols]) foreach ($cols as $i => $col) {
        $m['indexes'][] = ['INDEX_NAME' => $name, 'NON_UNIQUE' => $unique, 'SEQ_IN_INDEX' => $i + 1, 'COLUMN_NAME' => $col,
            'SUB_PART' => null, 'INDEX_TYPE' => 'BTREE', 'COLLATION' => 'A', 'IS_USABLE' => 'YES'];
    }
    foreach ($spec['foreign_keys'] as $name => [$cols,$target,$remote]) foreach ($cols as $i => $col) {
        $m['foreign_keys'][] = ['CONSTRAINT_NAME' => $name, 'ORDINAL_POSITION' => $i + 1, 'COLUMN_NAME' => $col,
            'REFERENCED_TABLE_NAME' => $target, 'REFERENCED_COLUMN_NAME' => $remote[$i], 'POSITION_IN_UNIQUE_CONSTRAINT' => $i + 1,
            'IS_LOCAL' => 1, 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT'];
    }
    foreach ($spec['checks'] as $name => $sql) $m['checks'][] = ['CONSTRAINT_NAME' => $name, 'ENFORCED' => 'YES', 'CHECK_CLAUSE' => $sql];
    foreach (['UPDATE','DELETE'] as $event) $m['triggers'][] = ['TRIGGER_NAME' => 'return_stock_events_no_' . strtolower($event),
        'EVENT_OBJECT_TABLE' => 'return_stock_events', 'ACTION_TIMING' => 'BEFORE', 'EVENT_MANIPULATION' => $event,
        'ACTION_ORIENTATION' => 'ROW', 'ACTION_STATEMENT' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return stock history is append-only'"];
    return $m;
}

final class StockMemoryPDO extends PDO
{
    public array $metadata;
    public array $state;
    public array $sql = [];
    public array $transactions = [];
    public int $mutations = 0;
    public bool $metadataFailure = false;
    public bool $ledgerFailure = false;
    public bool $insertFailure = false;
    public bool $updateConflict = false;
    public bool $otherHold = false;
    public bool $historicalHoldMode = false;
    private bool $active = false;
    private array $snapshot = [];
    public function __construct()
    {
        $this->metadata = stockMetadata();
        $order = ['id' => 81, 'status' => 'SHIPPED', 'cancellation_status' => 'NONE', 'received_at' => null, 'picked_up_at' => null, 'pickup_status' => null];
        $shipment = ['delivery_method' => 'LOCAL_COURIER', 'shipment_status' => 'RETURNED_TO_SHOP', 'delivery_cycle' => 1,
            'delivery_revision' => 8, 'failed_attempts' => 3, 'shipped_at' => '2026-10-20 10:00:00', 'delivered_at' => null, 'tracking_number' => 'MEMORY-ONLY'];
        $events = [];
        for ($i = 1; $i <= 3; $i++) $events[] = ['id' => $i * 2, 'order_id' => 81, 'delivery_cycle' => 1,
            'delivery_revision' => $i * 2, 'event_type' => 'DELIVERY_ATTEMPT_FAILED', 'attempt_number' => $i,
            'previous_state' => 'OUT_FOR_DELIVERY', 'resulting_state' => 'DELIVERY_ATTEMPT_FAILED', 'admin_id' => 7,
            'reason' => 'Synthetic failure', 'created_at' => '2026-10-20 12:00:00'];
        $returned = ['id' => 8, 'order_id' => 81, 'delivery_cycle' => 1, 'delivery_revision' => 8,
            'event_type' => 'RETURNED_TO_SHOP', 'previous_state' => 'RETURN_TO_SELLER', 'resulting_state' => 'RETURNED_TO_SHOP',
            'admin_id' => 7, 'reason' => 'Synthetic garment physically returned', 'created_at' => '2026-10-20 13:00:00'];
        $events[] = array_replace($returned, ['id' => 7, 'delivery_revision' => 7, 'event_type' => 'RETURN_TO_SELLER',
            'previous_state' => 'DELIVERY_ATTEMPT_FAILED', 'resulting_state' => 'RETURN_TO_SELLER', 'reason' => 'Synthetic return dispatch']);
        $events[] = $returned;
        $custody = array_merge($returned, ['event_id' => 8, 'event_revision' => 8, 'order_item_id' => 91, 'product_id' => 101, 'record_type' => 'RETURNED', 'outcome' => 'RETURNED']);
        $this->state = ['order' => $order, 'shipment' => $shipment,
            'payment' => ['payment_method' => 'COD', 'payment_status' => 'PENDING', 'verified_by' => null, 'verified_at' => null],
            'items' => [['id' => 91, 'order_id' => 81, 'product_id' => 101, 'quantity' => 1]],
            'products' => [['id' => 101, 'status' => 'SOLD', 'retired_at' => null]], 'headers' => $events,
            'custody' => [$custody], 'ledger' => [], 'sales' => [], 'sale_items' => []];
    }
    public function inTransaction(): bool { return $this->active; }
    public function exec(string $statement): int|false
    {
        if ($this->active || $statement !== 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') throw new LogicException('Unexpected exec.');
        $this->transactions[] = 'READ COMMITTED'; return 0;
    }
    public function beginTransaction(): bool { $this->active = true; $this->snapshot = $this->state; $this->transactions[] = 'begin'; return true; }
    public function commit(): bool { $this->active = false; $this->transactions[] = 'commit'; return true; }
    public function rollBack(): bool { $this->state = $this->snapshot; $this->active = false; $this->transactions[] = 'rollback'; return true; }
    public function lastInsertId(?string $name = null): string|false { return (string) count($this->state['ledger']); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->metadataFailure) throw new RuntimeException('Synthetic metadata failure.');
        foreach (['SHOW SESSION' => 'variables','SHOW GRANTS' => 'grants','information_schema.TABLES' => 'tables',
            'information_schema.COLUMNS' => 'columns','information_schema.STATISTICS' => 'indexes',
            'information_schema.KEY_COLUMN_USAGE' => 'foreign_keys','information_schema.TABLE_CONSTRAINTS' => 'checks',
            'information_schema.TRIGGERS' => 'triggers'] as $needle => $section) {
            if (str_contains($query, $needle)) return new StockMemoryStatement($this, $query, $this->metadata[$section]);
        }
        throw new LogicException('Unexpected metadata SQL.');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new StockMemoryStatement($this, preg_replace('/\s+/', ' ', trim($query)));
    }
}

final class StockMemoryStatement extends PDOStatement
{
    private int $affected = 0;
    public function __construct(private StockMemoryPDO $pdo, private string $sql, private array $rows = []) {}
    public function execute(?array $params = null): bool
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Unlocked SQL.');
        $q = $this->sql; $s =& $this->pdo->state; $this->pdo->sql[] = [$q,$params];
        if (str_contains($q, 'FROM admins')) $this->rows = $params === [7] ? [['id' => 7]] : [];
        elseif (str_contains($q, 'FROM orders o')) $this->rows = [$s['order']];
        elseif (str_contains($q, 'FROM payments WHERE')) $this->rows = $s['payment'] === null ? [] : [$s['payment']];
        elseif (str_contains($q, 'FROM shipments')) $this->rows = [$s['shipment']];
        elseif (str_contains($q, 'FROM sales_transactions')) $this->rows = $s['sales'];
        elseif (str_contains($q, 'FROM return_stock_events WHERE action_key')) $this->rows = array_values(array_filter($s['ledger'], static fn ($e) => $e['action_key'] === $params[0]));
        elseif (str_contains($q, 'FROM delivery_events WHERE')) $this->rows = $s['headers'];
        elseif (str_contains($q, 'FROM return_stock_events WHERE') && str_contains($q, 'event_kind = ?')) $this->rows = array_values(array_filter($s['ledger'], static fn ($e) => $e['order_id'] === $params[0] && $e['delivery_cycle'] === $params[1] && $e['order_item_id'] === $params[2] && $e['event_kind'] === $params[3]));
        elseif (str_contains($q, 'FROM return_stock_events WHERE')) $this->rows = $s['ledger'];
        elseif (str_contains($q, 'FROM order_items WHERE')) $this->rows = $s['items'];
        elseif (str_contains($q, 'FROM products WHERE')) {
            $sorted = $params; sort($sorted);
            if ($params !== $sorted || !str_contains($q, 'ORDER BY id FOR UPDATE')) throw new LogicException('Products not ordered/locked.');
            $this->rows = $s['products'];
        } elseif (str_contains($q, 'FROM delivery_event_items i')) $this->rows = $s['custody'];
        elseif (str_contains($q, 'FROM sales_transaction_items WHERE')) $this->rows = $s['sale_items'];
        elseif (str_starts_with($q, 'SELECT oi.id FROM order_items oi')) {
            if ($this->pdo->ledgerFailure && str_contains($q, 'return_stock_events')) throw new RuntimeException('Synthetic unreadable ledger.');
            $held = $this->pdo->historicalHoldMode
                ? !str_contains($q, 'return_stock_events') || !stockHistoricalDisposed($s)
                : $this->pdo->otherHold;
            $this->rows = $held ? [['id' => 201]] : [];
        } elseif (str_starts_with($q, 'UPDATE products SET')) {
            $this->pdo->mutations++;
            if (!$this->pdo->updateConflict) foreach ($s['products'] as &$product) {
                if ($product['id'] === (int) $params[0]) { $product['status'] = 'AVAILABLE'; $this->affected = 1; }
            }
            unset($product);
        } elseif (str_starts_with($q, 'INSERT INTO return_stock_events')) {
            $this->pdo->mutations++;
            if ($this->pdo->insertFailure) throw new RuntimeException('Synthetic insert failure.');
            $cols = ['order_id','delivery_cycle','delivery_revision','order_item_id','product_id','physical_return_event_id','event_kind','admin_id','reason','outcome','previous_inventory_state','resulting_inventory_state','action_key','request_hash'];
            $s['ledger'][] = array_combine($cols, $params) + ['id' => count($s['ledger']) + 1, 'created_at' => '2026-10-20 14:00:00'];
            $this->affected = 1;
        } else throw new LogicException('Unexpected SQL: ' . $q);
        return true;
    }
    public function rowCount(): int { return $this->affected; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchColumn(int $column = 0): mixed { $r = $this->fetch(); return $r === false ? false : array_values($r)[$column]; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { $r = $this->rows; $this->rows = []; return $r; }
}

function stockCall(StockMemoryPDO $pdo, string $action, string $key, ?string $outcome = null, bool $preview = false, int $item = 91): array
{
    return return_stock_action($pdo, 7, 81, $action, 1, 8, 'RETURNED_TO_SHOP', str_pad($key, 32, 'x'), $item, 'Synthetic independent decision', $outcome, $preview);
}
function stockPassedHold(StockMemoryPDO $pdo): void
{
    stockCheck('seed independent PASSED', stockCall($pdo, 'inspect_stock', 'inspect', 'PASSED')['ok'], true);
    stockCheck('seed explicit hold abandonment', stockCall($pdo, 'resolve_hold', 'hold')['ok'], true);
}

/** Independent declared fixture model, not a SQL interpreter or DB proof. */
function stockHistoricalDisposed(array $s): bool
{
    $o = $s['order']; $pm = $s['payment']; $sh = $s['shipment']; $item = $s['items'][0];
    if ($o['status'] !== 'SHIPPED' || !in_array($o['cancellation_status'], ['NONE','REJECTED'], true)
        || $o['received_at'] !== null || $o['picked_up_at'] !== null || $o['pickup_status'] !== null
        || $pm === null || $pm['payment_method'] !== 'COD' || $pm['payment_status'] !== 'PENDING'
        || $pm['verified_by'] !== null || $pm['verified_at'] !== null || $sh['delivery_method'] !== 'LOCAL_COURIER'
        || $sh['shipment_status'] !== 'RETURNED_TO_SHOP' || $sh['failed_attempts'] !== 3 || $sh['delivered_at'] !== null
        || !return_stock_valid_timestamp($sh['shipped_at']) || trim($sh['tracking_number']) === '' || $item['quantity'] !== 1) return false;
    foreach ($s['sales'] as $sale) if (($sale['order_id'] ?? null) === $o['id']) return false;
    foreach ($s['sale_items'] as $sale) if (($sale['order_item_id'] ?? null) === $item['id']) return false;
    $last = end($s['headers']);
    if ($last['delivery_cycle'] !== $sh['delivery_cycle'] || $last['delivery_revision'] !== $sh['delivery_revision'] || $last['resulting_state'] !== 'RETURNED_TO_SHOP') return false;
    $seller = array_values(array_filter($s['headers'], static fn ($e) => $e['delivery_cycle'] === $sh['delivery_cycle'] && $e['event_type'] === 'RETURN_TO_SELLER'));
    if (count($seller) !== 1 || $seller[0]['previous_state'] !== 'DELIVERY_ATTEMPT_FAILED'
        || $seller[0]['resulting_state'] !== 'RETURN_TO_SELLER' || trim($seller[0]['reason']) === '') return false;
    $records = [];
    foreach ($s['ledger'] as $row) if ($row['order_id'] === $o['id'] && $row['order_item_id'] === $item['id']
        && $row['product_id'] === $item['product_id'] && $row['delivery_cycle'] === $sh['delivery_cycle']) $records[$row['event_kind']] = $row;
    if (count($records) !== 3 || $records['INSPECTION']['outcome'] !== 'PASSED' || $records['HOLD_RESOLVED']['outcome'] !== 'RESOLVED'
        || $records['STOCK_RELEASED']['outcome'] !== 'AVAILABLE') return false;
    $rs = $records['STOCK_RELEASED']; $physical = null;
    foreach ($s['custody'] as $row) {
        if ($row['order_item_id'] !== $item['id'] || $row['delivery_cycle'] !== $sh['delivery_cycle']) continue;
        if ($row['outcome'] === 'MANUAL_REVIEW') return false;
        if ($row['event_id'] === $rs['physical_return_event_id'] && $row['product_id'] === $item['product_id']
            && $row['record_type'] === 'RETURNED' && $row['outcome'] === 'RETURNED' && $row['event_type'] === 'RETURNED_TO_SHOP'
            && $row['previous_state'] === 'RETURN_TO_SELLER' && $row['resulting_state'] === 'RETURNED_TO_SHOP') $physical = $row;
    }
    if ($physical === null) return false;
    foreach ($records as $row) if ($row['physical_return_event_id'] !== $rs['physical_return_event_id']
        || $row['previous_inventory_state'] !== 'SOLD' || $row['delivery_revision'] < $physical['event_revision']
        || $row['delivery_revision'] > $sh['delivery_revision'] || $row['admin_id'] < 1 || trim($row['reason']) === '') return false;
    return $records['INSPECTION']['id'] < $rs['id'] && $records['HOLD_RESOLVED']['id'] < $rs['id'];
}

$m = stockMetadata();
stockCheck('complete strict structural fixture', return_stock_schema_evaluate($m)['state'], 'ready');
foreach (['tables','columns','indexes','foreign_keys','checks','triggers'] as $section) {
    $bad = $m; array_pop($bad[$section]);
    stockCheck('missing ' . $section . ' blocks readiness', return_stock_schema_evaluate($bad)['state'], 'blocked');
}
foreach ([['columns',0,'COLUMN_TYPE','bigint'], ['columns',13,'COLLATION_NAME','ascii_general_ci'],
    ['indexes',1,'IS_USABLE','NO'], ['indexes',1,'SUB_PART',8], ['foreign_keys',0,'DELETE_RULE','CASCADE'],
    ['foreign_keys',0,'IS_LOCAL',0], ['checks',0,'ENFORCED','NO'], ['checks',3,'CHECK_CLAUSE',"previous_inventory_state = 'SOLD'"],
    ['triggers',0,'ACTION_STATEMENT',"SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wrong'"]] as [$section,$i,$field,$value]) {
    $bad = $m; $bad[$section][$i][$field] = $value;
    stockCheck('schema altered ' . $section . '.' . $field, return_stock_schema_evaluate($bad)['state'], 'blocked');
}
$bad = $m; $bad['grants'] = [['grant' => 'GRANT SELECT ON `synthetic_only`.* TO `memory`@`localhost`']];
stockCheck('hidden triggers cannot establish absent/ready', return_stock_schema_evaluate($bad)['state'], 'blocked');
$legacy = $m; array_shift($legacy['tables']); foreach (['columns','indexes','foreign_keys','checks','triggers'] as $section) $legacy[$section] = [];
stockCheck('known absent ledger legacy', return_stock_schema_evaluate($legacy)['state'], 'legacy');
$pdo = new StockMemoryPDO(); $pdo->metadata = $legacy;
stockCheck('absent ledger action unavailable', stockCall($pdo, 'inspect_stock', 'absent', 'PASSED')['reason'], 'schema_unavailable');
stockCheck('schema absent never begins transaction', [$pdo->transactions,$pdo->mutations], [[],0]);
$pdo = new StockMemoryPDO(); $pdo->metadataFailure = true;
stockCheck('unreadable metadata unavailable', return_stock_schema_status($pdo)['state'], 'blocked');

$pdo = new StockMemoryPDO(); $before = $pdo->state;
$inspect = stockCall($pdo, 'inspect_stock', 'inspect', 'PASSED');
stockCheck('inspection independently succeeds SOLD->SOLD', [$inspect['ok'],$inspect['event_kind'],$inspect['outcome'],$inspect['state']], [true,'INSPECTION','PASSED','SOLD']);
stockCheck('inspection does not release stock', $pdo->state['products'], $before['products']);
$hold = stockCall($pdo, 'resolve_hold', 'hold');
stockCheck('hold independently succeeds SOLD->SOLD', [$hold['ok'],$hold['outcome'],$hold['state']], [true,'RESOLVED','SOLD']);
stockCheck('hold alone keeps garment SOLD', $pdo->state['products'][0]['status'], 'SOLD');
$release = stockCall($pdo, 'make_available', 'release');
stockCheck('positive uncollected COD stock sequence', [$release['ok'],$release['event_kind'],$release['outcome'],$release['state']], [true,'STOCK_RELEASED','AVAILABLE','AVAILABLE']);
stockCheck('one release mutation and three immutable ledger records', [$pdo->state['products'][0]['status'],count($pdo->state['ledger']),$pdo->mutations], ['AVAILABLE',3,4]);
foreach (['order','payment','shipment','items','headers','custody','sales','sale_items'] as $section) stockCheck('sequence leaves ' . $section . ' untouched', $pdo->state[$section], $before[$section]);
$mutationCount = $pdo->mutations;
$pdo->state['shipment']['delivery_cycle'] = 9; $pdo->state['shipment']['delivery_revision'] = 99; $pdo->state['products'][0]['status'] = 'SOLD';
$replay = stockCall($pdo, 'make_available', 'release');
stockCheck('exact historical replay returns original event/state/tokens', [$replay['idempotent'],$replay['event_id'],$replay['state'],$replay['cycle'],$replay['revision']], [true,$release['event_id'],'AVAILABLE',1,8]);
stockCheck('historical replay never releases a later SOLD product', [$pdo->mutations,$pdo->state['products'][0]['status']], [$mutationCount,'SOLD']);
$previewReplay = stockCall($pdo, 'make_available', 'release', null, true);
stockCheck('exact replay preview rolls back', [$previewReplay['ok'],end($pdo->transactions),$pdo->mutations], [true,'rollback',$mutationCount]);
stockCheck('same key changed payload rejected', stockCall($pdo, 'resolve_hold', 'release')['reason'], 'key_conflict');

foreach (['inspect_stock','resolve_hold','make_available'] as $action) {
    $pdo = new StockMemoryPDO(); if ($action === 'make_available') stockPassedHold($pdo);
    $before = $pdo->state; $writes = $pdo->mutations;
    $result = stockCall($pdo, $action, 'preview', $action === 'inspect_stock' ? 'PASSED' : null, true);
    stockCheck($action . ' eligible preview success and rollback BEFORE all writes', [$result['ok'],$result['validation_only'],end($pdo->transactions),$pdo->state,$pdo->mutations], [true,true,'rollback',$before,$writes]);
    $first = stockCall($pdo, $action, 'first', $action === 'inspect_stock' ? 'PASSED' : null);
    stockCheck($action . ' first operation succeeds', $first['ok'], true);
    $second = stockCall($pdo, $action, 'different', $action === 'inspect_stock' ? 'PASSED' : null);
    stockCheck($action . ' different key same immutable operation rejected', $second['reason'], 'already_recorded');
}

foreach ([
    ['GCash paid','payment',['payment_method' => 'GCASH','payment_status' => 'PAID'],'financial_resolution_required'],
    ['GCash pending','payment',['payment_method' => 'GCASH'],'financial_resolution_required'],
    ['COD collected','payment',['payment_status' => 'PAID','verified_by' => 7,'verified_at' => '2026-10-20 11:00:00'],'financial_resolution_required'],
    ['COD verifier set','payment',['verified_by' => 7],'financial_resolution_required'],
    ['COD failed','payment',['payment_status' => 'FAILED'],'financial_resolution_required'],
    ['cancelled','order',['status' => 'CANCELLED','cancellation_status' => 'APPROVED'],'invalid_context'],
    ['requested cancel','order',['cancellation_status' => 'REQUESTED'],'invalid_context'],
    ['received','order',['received_at' => '2026-10-20 14:00:00'],'invalid_context'],
    ['pickup','order',['pickup_status' => 'PICKED_UP'],'invalid_context'],
    ['not physically returned','shipment',['shipment_status' => 'RETURN_TO_SELLER'],'conflict'],
    ['stale cycle','shipment',['delivery_cycle' => 2],'conflict'],
    ['stale revision','shipment',['delivery_revision' => 9],'conflict'],
    ['courier mismatch','shipment',['delivery_method' => 'STORE_PICKUP'],'invalid_context'],
    ['third failure missing','shipment',['failed_attempts' => 2],'physical_return_required'],
    ['retired','product',['retired_at' => '2026-10-20 14:00:00'],'inventory_state'],
    ['not SOLD','product',['status' => 'AVAILABLE'],'inventory_state'],
    ['identity mismatch','custody',['product_id' => 999],'invalid_evidence'],
    ['custody timestamp invalid','custody',['created_at' => '2026-02-30 13:00:00'],'invalid_evidence'],
    ['custody actor missing','custody',['admin_id' => 0],'invalid_evidence'],
    ['wrong return event','custody',['event_id' => 999],'physical_return_required'],
    ['quantity mismatch','item',['quantity' => 2],'legacy_item_review'],
] as [$label,$section,$change,$expected]) {
    foreach (['inspect_stock','resolve_hold','make_available'] as $action) {
        $pdo = new StockMemoryPDO();
        $at = ['product' => 'products','custody' => 'custody','item' => 'items'][$section] ?? null;
        if ($at !== null) $pdo->state[$at][0] = array_replace($pdo->state[$at][0], $change);
        else $pdo->state[$section] = array_replace($pdo->state[$section], $change);
        $before = $pdo->state;
        $result = stockCall($pdo, $action, 'blocked', $action === 'inspect_stock' ? 'PASSED' : null);
        stockCheck($label . ' blocks ' . $action, [$result['ok'],$result['reason']], [false,$expected]);
        stockCheck($label . ' rolls back with no writes', [$pdo->state,$pdo->mutations,end($pdo->transactions)], [$before,0,'rollback']);
    }
}
foreach (['sales','sale_items','other_hold','missing_custody','missing_failure','return_before_dispatch','missing_product','missing_payment','missing_verifier'] as $case) {
    $pdo = new StockMemoryPDO();
    $expected = match ($case) { 'sales','sale_items' => 'committed_sale', 'other_hold' => 'other_hold', 'missing_product' => 'legacy_item_review', 'missing_payment','missing_verifier' => 'financial_resolution_required', default => 'physical_return_required' };
    if ($case === 'other_hold') $pdo->otherHold = true;
    elseif ($case === 'missing_custody') $pdo->state['custody'] = [];
    elseif ($case === 'missing_failure') array_shift($pdo->state['headers']);
    elseif ($case === 'return_before_dispatch') $pdo->state['headers'][4]['created_at'] = '2026-10-19 13:00:00';
    elseif ($case === 'missing_product') $pdo->state['products'] = [];
    elseif ($case === 'missing_payment') $pdo->state['payment'] = null;
    elseif ($case === 'missing_verifier') unset($pdo->state['payment']['verified_at']);
    else $pdo->state[$case] = [['id' => 1]];
    $before = $pdo->state;
    stockCheck($case . ' fails closed', stockCall($pdo, 'make_available', 'blocked')['reason'], $expected);
    stockCheck($case . ' preserves all memory state', [$pdo->state,$pdo->mutations], [$before,0]);
}
foreach (['none','hold_only','inspection_only','stock_manual','old_manual','legacy_approved_only','legacy_approved_passed','ledger_mismatch'] as $case) {
    $pdo = new StockMemoryPDO();
    if (!in_array($case, ['none','hold_only','legacy_approved_only'], true)) stockCall($pdo, 'inspect_stock', 'inspect', $case === 'stock_manual' ? 'MANUAL_REVIEW' : 'PASSED');
    if ($case !== 'none' && $case !== 'inspection_only') stockCall($pdo, 'resolve_hold', 'hold');
    // Legacy inspection necessarily has a later revision than physical return.
    if (in_array($case, ['old_manual','legacy_approved_only','legacy_approved_passed'], true)) {
        $pdo->state['custody'][] = array_replace($pdo->state['custody'][0], ['event_id' => 9, 'event_revision' => 8,
            'record_type' => 'INSPECTED','event_type' => 'RETURN_INSPECTED','previous_state' => 'RETURNED_TO_SHOP',
            'outcome' => $case === 'old_manual' ? 'MANUAL_REVIEW' : 'RETRY_APPROVED']);
        $pdo->state['headers'][3]['delivery_revision'] = 6;
        $pdo->state['headers'][4]['delivery_revision'] = 7;
        $pdo->state['custody'][0]['event_revision'] = 7;
        $pdo->state['headers'][] = array_replace($pdo->state['headers'][4], ['id' => 9, 'delivery_revision' => 8,
            'event_type' => 'RETURN_INSPECTED', 'previous_state' => 'RETURNED_TO_SHOP']);
    }
    if ($case === 'ledger_mismatch') $pdo->state['ledger'][0]['physical_return_event_id'] = 999;
    $expected = match ($case) { 'inspection_only' => 'hold_resolution_required', 'stock_manual','old_manual' => 'manual_review', 'ledger_mismatch' => 'invalid_evidence', default => 'inspection_required' };
    $before = $pdo->state; $writes = $pdo->mutations;
    if ($case === 'legacy_approved_passed') {
        stockCheck('legacy approval remains readable with separate PASSED stock inspection', stockCall($pdo, 'make_available', 'release')['ok'], true);
        stockCheck('legacy approval and custody remain immutable during stock release', [$pdo->state['headers'],$pdo->state['custody']], [$before['headers'],$before['custody']]);
        continue;
    }
    stockCheck($case . ' cannot release', stockCall($pdo, 'make_available', 'release')['reason'], $expected);
    stockCheck($case . ' stock and ledger unchanged', [$pdo->state,$pdo->mutations], [$before,$writes]);
}
$pdo = new StockMemoryPDO();
stockCheck('hold may precede inspection', stockCall($pdo, 'resolve_hold', 'hold-first')['ok'], true);
stockCheck('hold-first still permits independent inspection', stockCall($pdo, 'inspect_stock', 'inspect-second', 'PASSED')['ok'], true);
stockCheck('hold-first valid independent sequence releases', stockCall($pdo, 'make_available', 'release-third')['ok'], true);

$pdo = new StockMemoryPDO();
stockCheck('independent stock MANUAL_REVIEW inspection can be recorded', stockCall($pdo, 'inspect_stock', 'manual-independent', 'MANUAL_REVIEW')['ok'], true);
stockCheck('stock MANUAL_REVIEW creates only immutable inspection and leaves SOLD', [array_column($pdo->state['ledger'], 'event_kind'),$pdo->state['products'][0]['status']], [['INSPECTION'],'SOLD']);

foreach (['missing','blank','wrong_predecessor','wrong_result','duplicate','before_failure','future_revision','automatic_batch'] as $case) {
    $pdo = new StockMemoryPDO();
    switch ($case) {
        case 'missing': array_splice($pdo->state['headers'], 3, 1); break;
        case 'blank': $pdo->state['headers'][3]['reason'] = ' '; break;
        case 'wrong_predecessor': $pdo->state['headers'][3]['previous_state'] = 'SHIPPED'; break;
        case 'wrong_result': $pdo->state['headers'][3]['resulting_state'] = 'SHIPPED'; break;
        case 'duplicate': array_splice($pdo->state['headers'], 4, 0, [$pdo->state['headers'][3]]); break;
        case 'before_failure': $pdo->state['headers'][3]['id'] = 5; break;
        case 'future_revision': $pdo->state['headers'][3]['delivery_revision'] = 8; break;
        case 'automatic_batch': $pdo->state['headers'][3]['delivery_revision'] = 6; break;
    }
    $before = $pdo->state;
    $r = stockCall($pdo, 'inspect_stock', 'seller-evidence', 'PASSED', true);
    stockCheck('return seller ' . $case . ' valid custody decision', [$r['ok'],$r['reason'] ?? null], $case === 'automatic_batch' ? [true,null] : [false,'physical_return_required']);
    stockCheck('return seller ' . $case . ' never rewrites original history', [$pdo->state,$pdo->mutations], [$before,0]);
}

foreach (['valid','missing','prior_cycle','invalid_actor','invalid_timestamp','future_revision'] as $case) {
    foreach (['inspect_stock','resolve_hold','make_available'] as $action) {
        $pdo = new StockMemoryPDO();
        $pdo->state['shipment']['delivery_cycle'] = 2;
        foreach (['headers','custody'] as $section) {
            foreach ($pdo->state[$section] as &$row) $row['delivery_cycle'] = 2;
            unset($row);
        }
        if ($case !== 'missing') {
            $legacyEvidence = array_replace($pdo->state['headers'][0], ['id' => 1, 'delivery_revision' => 1,
                'event_type' => 'DELIVERY_CYCLE_AUTHORIZED', 'previous_state' => 'RETURNED_TO_SHOP', 'resulting_state' => 'IN_TRANSIT']);
            if ($case === 'prior_cycle') $legacyEvidence['delivery_cycle'] = 1;
            if ($case === 'invalid_actor') $legacyEvidence['admin_id'] = 0;
            if ($case === 'invalid_timestamp') $legacyEvidence['created_at'] = '2026-02-30 12:00:00';
            if ($case === 'future_revision') $legacyEvidence['delivery_revision'] = 9;
            array_unshift($pdo->state['headers'], $legacyEvidence);
        }
        if ($case === 'valid' && $action === 'make_available') {
            foreach (['inspect_stock','resolve_hold'] as $seed) {
                $r = return_stock_action($pdo, 7, 81, $seed, 2, 8, 'RETURNED_TO_SHOP', str_pad($seed, 32, 'x'), 91,
                    'Synthetic preserved-cycle stock decision', $seed === 'inspect_stock' ? 'PASSED' : null);
                stockCheck('preserved cycle stock prerequisite ' . $seed, $r['ok'], true);
            }
        }
        $before = $pdo->state; $writes = $pdo->mutations;
        $r = return_stock_action($pdo, 7, 81, $action, 2, 8, 'RETURNED_TO_SHOP', 'historical-cycle-action-key-12345', 91,
            'Synthetic preserved-cycle stock decision', $action === 'inspect_stock' ? 'PASSED' : null, true);
        stockCheck('preserved cycle ' . $case . ' provenance gates ' . $action, [$r['ok'],$r['reason'] ?? null],
            $case === 'valid' ? [true,null] : [false,'physical_return_required']);
        stockCheck('preserved cycle ' . $case . ' readonly preview ' . $action,
            [$pdo->state,$pdo->mutations,end($pdo->transactions)], [$before,$writes,'rollback']);
        if ($case === 'valid' && $action === 'make_available') {
            $r = return_stock_action($pdo, 7, 81, $action, 2, 8, 'RETURNED_TO_SHOP', 'historical-cycle-action-key-12345', 91,
                'Synthetic preserved-cycle stock decision');
            stockCheck('preserved cycle actual stock release with historical provenance', [$r['ok'],$r['cycle'],$r['state']], [true,2,'AVAILABLE']);
            stockCheck('preserved cycle stock release never rewrites delivery evidence', [$pdo->state['headers'],$pdo->state['custody']], [$before['headers'],$before['custody']]);
        }
    }
}

$pdo = new StockMemoryPDO();
$pdo->state['items'][] = ['id' => 92, 'order_id' => 81, 'product_id' => 102, 'quantity' => 1];
$pdo->state['products'][] = ['id' => 102, 'status' => 'SOLD', 'retired_at' => null];
$pdo->state['custody'][] = array_replace($pdo->state['custody'][0], ['order_item_id' => 92,'product_id' => 102]);
stockPassedHold($pdo);
stockCheck('partial hold abandonment records only the selected item reservation', array_column($pdo->state['ledger'], 'order_item_id'), [91,91]);
stockCheck('partial first garment release', stockCall($pdo, 'make_available', 'partial-release-first')['ok'], true);
stockCheck('other unresolved garment stays SOLD', $pdo->state['products'][1]['status'], 'SOLD');
stockCheck('second garment independent inspection after partial release', stockCall($pdo, 'inspect_stock', 'second-inspect', 'PASSED', false, 92)['ok'], true);
stockCheck('second garment hold resolution after partial release', stockCall($pdo, 'resolve_hold', 'second-hold', null, false, 92)['ok'], true);
stockCheck('second garment can release without resurrecting first reservation', stockCall($pdo, 'make_available', 'second-release', null, false, 92)['ok'], true);
stockCheck('both unique garments released exactly once', [array_column($pdo->state['products'], 'status'),count($pdo->state['ledger'])], [['AVAILABLE','AVAILABLE'],6]);

$noDatabase = new class extends PDO {
    public function __construct() {}
    public function inTransaction(): bool { throw new LogicException('Unsupported actions must not access PDO.'); }
};
$rejected = return_stock_action($noDatabase, 7, 81, 'authorize_cycle', 1, 8, 'RETURNED_TO_SHOP',
    'unsupported-action-key-123456789', 91, 'Synthetic unsupported action');
stockCheck('authorize_cycle rejected 422 without PDO access', [$rejected['ok'],$rejected['reason'],$rejected['status']], [false,'bad_request',422]);

foreach ([
    ['bad stock outcome','inspect_stock',1,8,'RETURNED_TO_SHOP','valid-key-1234567',91,'note','RETRY_APPROVED','bad_request'],
    ['missing inspection outcome','inspect_stock',1,8,'RETURNED_TO_SHOP','valid-key-1234567',91,'note',null,'bad_request'],
    ['outcome on hold','resolve_hold',1,8,'RETURNED_TO_SHOP','valid-key-1234567',91,'note','PASSED','bad_request'],
    ['missing cycle token','inspect_stock',0,8,'RETURNED_TO_SHOP','valid-key-1234567',91,'note','PASSED','bad_request'],
    ['missing revision token','inspect_stock',1,0,'RETURNED_TO_SHOP','valid-key-1234567',91,'note','PASSED','bad_request'],
    ['wrong stage token','inspect_stock',1,8,'SHIPPED','valid-key-1234567',91,'note','PASSED','bad_request'],
    ['bad identity','inspect_stock',1,8,'RETURNED_TO_SHOP','short',91,'note','PASSED','bad_request'],
    ['missing item','inspect_stock',1,8,'RETURNED_TO_SHOP','valid-key-1234567',0,'note','PASSED','bad_request'],
    ['blank reason','inspect_stock',1,8,'RETURNED_TO_SHOP','valid-key-1234567',91,"\u{00a0}\u{2003}",'PASSED','reason_required'],
] as [$label,$action,$cycle,$revision,$stage,$key,$item,$reason,$outcome,$expected]) {
    $pdo = new StockMemoryPDO();
    $r = return_stock_action($pdo, 7, 81, $action, $cycle, $revision, $stage, $key, $item, $reason, $outcome);
    stockCheck($label . ' rejected before any transaction or writes', [$r['reason'],$pdo->transactions,$pdo->mutations], [$expected,[],0]);
}

foreach (['insert_failure','update_conflict'] as $case) {
    $pdo = new StockMemoryPDO(); stockPassedHold($pdo); $before = $pdo->state;
    if ($case === 'insert_failure') $pdo->insertFailure = true; else $pdo->updateConflict = true;
    stockCheck($case . ' safe failure', stockCall($pdo, 'make_available', 'release')['reason'], $case === 'insert_failure' ? 'error' : 'conflict');
    stockCheck($case . ' restores SOLD even after attempted mutation', [$pdo->state,end($pdo->transactions)], [$before,'rollback']);
}
$pdo = new StockMemoryPDO(); $pdo->metadata = $legacy; $pdo->otherHold = true; $pdo->beginTransaction();
stockCheck('legacy inventory holds use safe original SQL', inventory_has_other_hold_locked($pdo, 101, 81), true);
stockCheck('legacy inventory SQL never references new table', str_contains($pdo->sql[0][0], 'return_stock_events'), false); $pdo->rollBack();
$pdo = new StockMemoryPDO(); $pdo->ledgerFailure = true; $pdo->otherHold = true; $pdo->beginTransaction();
stockCheck('unreadable disposition SQL falls back to original holds', inventory_has_other_hold_locked($pdo, 101, 81), true);
stockCheck('fallback original SQL omits ledger', [count($pdo->sql),str_contains($pdo->sql[1][0], 'return_stock_events')], [2,false]); $pdo->rollBack();

$base = new StockMemoryPDO(); stockPassedHold($base); stockCall($base, 'make_available', 'released-historical');
foreach (['valid','only_hold','missing_inspection','review','wrong_cycle','physical_mismatch','paid','collected','cancelled','receipt','pickup','missing_seller','blank_seller',
    'not_returned','latest_revision_mismatch','missing_custody','old_manual','old_sale','old_item_sale','later_purchase_sale'] as $case) {
    $pdo = new StockMemoryPDO(); $pdo->state = $base->state; $pdo->historicalHoldMode = true;
    switch ($case) {
        case 'only_hold': $pdo->state['ledger'] = [$pdo->state['ledger'][1]]; break;
        case 'missing_inspection': array_shift($pdo->state['ledger']); break;
        case 'review': $pdo->state['ledger'][0]['outcome'] = 'MANUAL_REVIEW'; break;
        case 'wrong_cycle': $pdo->state['shipment']['delivery_cycle'] = 2; break;
        case 'physical_mismatch': $pdo->state['ledger'][0]['physical_return_event_id'] = 999; break;
        case 'paid': $pdo->state['payment']['payment_status'] = 'PAID'; break;
        case 'collected': $pdo->state['payment']['verified_by'] = 7; break;
        case 'cancelled': $pdo->state['order']['cancellation_status'] = 'APPROVED'; break;
        case 'receipt': $pdo->state['order']['received_at'] = '2026-10-20 15:00:00'; break;
        case 'pickup': $pdo->state['order']['pickup_status'] = 'PICKED_UP'; break;
        case 'not_returned': $pdo->state['shipment']['shipment_status'] = 'OUT_FOR_DELIVERY'; break;
        case 'missing_seller': array_splice($pdo->state['headers'], 3, 1); break;
        case 'blank_seller': $pdo->state['headers'][3]['reason'] = ' '; break;
        case 'latest_revision_mismatch': $pdo->state['shipment']['delivery_revision'] = 10; break;
        case 'missing_custody': $pdo->state['custody'] = []; break;
        case 'old_manual': $pdo->state['custody'][] = array_replace($pdo->state['custody'][0], ['record_type' => 'INSPECTED','outcome' => 'MANUAL_REVIEW']); break;
        case 'old_sale': $pdo->state['sales'][] = ['id' => 1,'order_id' => 81]; break;
        case 'old_item_sale': $pdo->state['sale_items'][] = ['id' => 1,'order_item_id' => 91]; break;
        case 'later_purchase_sale':
            $pdo->state['products'][0]['status'] = 'SOLD';
            $pdo->state['sales'][] = ['id' => 2,'order_id' => 82];
            $pdo->state['sale_items'][] = ['id' => 2,'order_item_id' => 92,'product_id' => 101];
            break;
    }
    $before = $pdo->state; $pdo->beginTransaction();
    stockCheck('declared historical ' . $case . ' hold membership', inventory_has_other_hold_locked($pdo, 101, 82), !in_array($case, ['valid','later_purchase_sale'], true));
    stockCheck('historical membership check readonly ' . $case, [$pdo->mutations,$pdo->state], [0,$before]);
    $pdo->rollBack();
}

// SOURCE-only boundary checks, not SQL-engine/FK/trigger/concurrency execution.
$guard = file_get_contents(__DIR__ . '/../../includes/inventory_guard.php');
foreach (["rs.event_kind = 'STOCK_RELEASED'","ins.outcome = 'PASSED'","hr.event_kind = 'HOLD_RESOLVED'",'AND NOT COALESCE((',
    "pm.payment_method = 'COD'","sh.shipment_status = 'RETURNED_TO_SHOP'",'ins.physical_return_event_id = rs.physical_return_event_id',"mi.outcome = 'MANUAL_REVIEW'"] as $token) stockCheck('source historical guard requires ' . $token, str_contains($guard, $token), true);
$endpoint = file_get_contents(__DIR__ . '/../../admin/api/return-stock-action.php');
foreach (['hash_equals($stored, $token)',"['REQUEST_METHOD']",'$_SESSION[\'admin_id\']','session_write_close()'] as $token) stockCheck('source endpoint guard ' . $token, str_contains($endpoint, $token), true);
stockCheck('source endpoint never accepts validationOnly', preg_match('/\$_POST\s*\[\s*[\'"](?:validateOnly|validationOnly|validate_only)[\'"]/', $endpoint), 0);
stockCheck('source endpoint rejects unsupported actions BEFORE database bootstrap',
    str_contains($endpoint, "if (!in_array(\$_POST['action'], ['inspect_stock','resolve_hold','make_available'], true)) return_stock_respond(return_stock_failure('bad_request', 422));")
        && strpos($endpoint, "if (!in_array(\$_POST['action'], ['inspect_stock','resolve_hold','make_available'], true))")
        < strpos($endpoint, "require_once __DIR__ . '/../../includes/database.php'"), true);
stockCheck('source endpoint preserves existing courier schema gate BEFORE canonical writer', strpos($endpoint, 'delivery_schema_status($pdo)') < strpos($endpoint, '$result = return_stock_action(')
    && str_contains($endpoint, "$" . "courierSchema['state'] !== 'ready'"), true);
$writer = file_get_contents(__DIR__ . '/../../includes/return_stock.php');
stockCheck('source stock writer has no order retry blocker', str_contains($writer, 'return_stock_order_retry_blocked_locked'), false);
stockCheck('source writer never updates order/payment/shipment/sales', preg_match('/(?:UPDATE|INSERT INTO|DELETE FROM)\s+(?:orders|payments|shipments|sales_transactions|sales_transaction_items)\b/i', $writer), 0);
stockCheck('source historical guard latest tokens must reconcile immutable delivery history', str_contains($guard, 'latest.delivery_revision = sh.delivery_revision') && str_contains($guard, 'latest.id = (SELECT MAX(newest.id)'), true);
stockCheck('source historical guard validates return seller chronology and auto batch revision', str_contains($guard, "seller.previous_state = 'DELIVERY_ATTEMPT_FAILED'")
    && str_contains($guard, 'seller.delivery_revision >= (SELECT MAX(failed_revision.delivery_revision)') && str_contains($guard, "seller_count.event_type = 'RETURN_TO_SELLER') = 1"), true);
$migration = file_get_contents(__DIR__ . '/../migrations/017_return_stock_disposition.sql');
stockCheck('migration only adds its table/triggers', preg_match('/\b(?:ALTER TABLE|DROP TABLE|UPDATE|INSERT INTO|DELETE FROM)\s+(?:orders|payments|shipments|products|delivery_events|delivery_event_items)\b/i', $migration), 0);
echo $checks . ' checks, ' . $failures . ' failures. DB-free memory contracts only; no real SQL, database connection, migration, HTTP write or concurrency proof.' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
