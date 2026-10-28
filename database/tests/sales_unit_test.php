<?php

/*
 * Hopia Fits - DB-free sales and Phase 7 COD regression tests.
 *
 * Exercises pure helpers and transaction control using queued, in-memory
 * PDO/PDOStatement doubles. Their constructors NEVER call parent constructors
 * or open connections. Only sales.php/payment.php declarations are included.
 *
 *   php database/tests/sales_unit_test.php
 *
 * No database.php, .env, database driver or integration suite is used here.
 * clothing_shop_bundle_test contains active data, NOT disposable test data;
 * this suite must never be replaced with active-database integration tests.
 */

if (PHP_SAPI !== 'cli' || $argc !== 1) exit(2);
require_once __DIR__ . '/../../includes/sales.php';
require_once __DIR__ . '/../../includes/payment.php';

$tests = 0;
$failures = 0;

function check(string $label, $actual, $expected): void
{
    global $tests, $failures;
    $tests++;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
        echo "  FAIL: {$label}\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
    } else {
        echo "  ok:   {$label}\n";
    }
}

echo "== money / cents ==\n";
check('money "250.00" -> 25000', sales_money_to_cents('250.00'), 25000);
check('money "250" -> 25000', sales_money_to_cents('250'), 25000);
check('money 300.0 (float) -> 30000', sales_money_to_cents(300.0), 30000);
check('money "250.50" -> 25050', sales_money_to_cents('250.50'), 25050);
check('money "0" -> 0', sales_money_to_cents('0'), 0);
check('money "" -> null', sales_money_to_cents(''), null);
check('money "abc" -> null', sales_money_to_cents('abc'), null);
check('money "-5" -> null (negative rejected)', sales_money_to_cents('-5'), null);
check('money null -> null', sales_money_to_cents(null), null);

echo "== sum / format ==\n";
check('sum [250,250,300] -> 80000 cents', sales_sum_price_cents(['250.00', '250.00', '300.00']), 80000);
check('sum with bad element -> null', sales_sum_price_cents(['250.00', 'oops']), null);
check('cents 80000 -> "800.00"', sales_cents_to_string(80000), '800.00');
check('cents 25000 -> "250.00"', sales_cents_to_string(25000), '250.00');

echo "== barcode normalize / plausibility ==\n";
check('normalize trims CR/LF/space', sales_normalize_barcode("  HOPEBUNDLE-000005-01 \r\n"), 'HOPEBUNDLE-000005-01');
check('plausible valid', sales_is_plausible_barcode('HOPEBUNDLE-000005-01'), true);
check('plausible empty -> false', sales_is_plausible_barcode(''), false);
check('plausible 65 chars -> false', sales_is_plausible_barcode(str_repeat('A', 65)), false);
check('plausible 64 chars -> true', sales_is_plausible_barcode(str_repeat('A', 64)), true);
check('plausible with control char -> false', sales_is_plausible_barcode("ABC\x01DEF"), false);

echo "== request key ==\n";
check('key uuid v4-ish -> true', sales_is_valid_request_key('3f1a2b4c-5d6e-7f80-9a1b-2c3d4e5f6071'), true);
check('key 32 hex -> true', sales_is_valid_request_key(str_repeat('a1b2', 8)), true);
check('key too short -> false', sales_is_valid_request_key('short'), false);
check('key 65 chars -> false', sales_is_valid_request_key(str_repeat('a', 65)), false);
check('key with space -> false', sales_is_valid_request_key('abc def ghi jklmno'), false);
check('key non-string -> false', sales_is_valid_request_key(12345), false);

echo "== structural eligibility ==\n";
$available = ['status' => 'AVAILABLE', 'retired_at' => null, 'bundle_id' => 5, 'barcode_value' => 'HOPEBUNDLE-000005-01'];
check('AVAILABLE bundled barcoded -> null (eligible)', sales_product_eligibility_error($available), null);

$retired = ['status' => 'AVAILABLE', 'retired_at' => '2026-01-01 00:00:00', 'bundle_id' => 5, 'barcode_value' => 'HOPEBUNDLE-000005-01'];
check('retired (even if AVAILABLE) -> retired', sales_product_eligibility_error($retired), 'retired');

$sold = ['status' => 'SOLD', 'retired_at' => null, 'bundle_id' => 5, 'barcode_value' => 'HOPEBUNDLE-000005-01'];
check('SOLD -> not_available', sales_product_eligibility_error($sold), 'not_available');

$noBundle = ['status' => 'AVAILABLE', 'retired_at' => null, 'bundle_id' => null, 'barcode_value' => null];
check('legacy unassigned -> unassigned', sales_product_eligibility_error($noBundle), 'unassigned');

$noBarcode = ['status' => 'AVAILABLE', 'retired_at' => null, 'bundle_id' => 5, 'barcode_value' => ''];
check('bundled but no barcode -> unassigned', sales_product_eligibility_error($noBarcode), 'unassigned');

echo "== messages ==\n";
foreach (['not_found', 'not_available', 'retired', 'unassigned', 'already_sold', 'duplicate'] as $reason) {
    check("message for {$reason} is non-empty", sales_eligibility_message($reason) !== '', true);
}

// No parent constructors or real drivers: writes below are only a transaction journal.
final class SalesUnitPDO extends PDO
{
    public array $events = [];
    public array $writes = [];
    public array $staged = [];
    public array $committed = [];
    public array $discarded = [];
    public array $isolation = [];
    public array $reads = [];
    private bool $active = false;

    public function __construct(public array $queue = [], public bool $readOnly = false) {}
    public function exec(string $statement): int|false
    {
        check('isolation set before transaction', $this->active, false);
        check('only READ COMMITTED session command is allowed', $statement, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->isolation[] = $statement;
        return 0;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new SalesUnitStatement($this, preg_replace('/\s+/', ' ', trim($query)));
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== 'SELECT NOW()') throw new LogicException('Undeclared direct query in the memory fixture.');
        $statement = $this->prepare($query);
        $statement->execute();
        return $statement;
    }
    public function inTransaction(): bool { return $this->active; }
    public function lastInsertId(?string $name = null): string|false { return '901'; }
    public function beginTransaction(): bool
    {
        check('transaction is not nested', $this->active, false);
        $this->active = true;
        $this->events[] = 'begin';
        return true;
    }
    public function commit(): bool
    {
        check('commit has active transaction', $this->active, true);
        $this->committed = array_merge($this->committed, $this->staged);
        $this->staged = [];
        $this->active = false;
        $this->events[] = 'commit';
        return true;
    }
    public function rollBack(): bool
    {
        check('rollback has active transaction', $this->active, true);
        $this->discarded = $this->staged;
        $this->staged = [];
        $this->active = false;
        $this->events[] = 'rollback';
        return true;
    }
}

final class SalesUnitStatement extends PDOStatement
{
    private array $rows = [];
    private int $affected = 1;
    public function __construct(private SalesUnitPDO $pdo, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $isWrite = preg_match('/^(UPDATE|INSERT|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/', $this->sql) === 1;
        if ($isWrite) {
            check('read-only preview never attempts a write', $this->pdo->readOnly, false);
            if ($this->pdo->readOnly) throw new LogicException('Write attempted during a read-only preview.');
            $this->pdo->writes[] = [$this->sql, $params];
        } else {
            $this->pdo->reads[] = [$this->sql, $params];
        }
        if (str_contains($this->sql, 'FOR UPDATE') || str_contains($this->sql, 'LOCK IN SHARE MODE')) {
            check('locking read is transactional', $this->pdo->inTransaction(), true);
        }
        // Queue entries are [SQL regex, exact bindings (or predicate), rows or exception].
        $step = array_shift($this->pdo->queue);
        if ($step === null) {
            check('unexpected SQL', $this->sql, '(queue exhausted)');
            throw new LogicException('Unexpected SQL in DB-free test.');
        }
        check('queued SQL contract', preg_match('~' . $step[0] . '~', $this->sql) === 1 ? true : $this->sql, true);
        check('queued parameter contract', is_callable($step[1]) ? ($step[1])($params) : $params,
            is_callable($step[1]) ? true : $step[1]);
        if ($step[2] instanceof Throwable) {
            throw $step[2];
        }
        $this->rows = $step[2];
        $this->affected = $step[3] ?? 1;
        if ($isWrite) {
            check('write is transactional', $this->pdo->inTransaction(), true);
            $this->pdo->staged[] = [$this->sql, $params];
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : array_values($row)[$column];
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, ...$args): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $mode === PDO::FETCH_COLUMN ? array_map(static fn (array $row) => array_values($row)[0], $rows) : $rows;
    }
    public function rowCount(): int { return $this->affected; }
}

echo "== Phase 7 COD (DB-free) ==\n";
$orderParams = [':order_id' => 19];
$clock = '2026-01-15 04:05:06';
$codOrder = ['id' => 19, 'status' => 'DELIVERED', 'cancellation_status' => 'NONE', 'received_at' => '2026-01-14 03:00:00',
    'shipping_fee' => '50.00', 'total_amount' => '80.30'];
$codPayment = ['payment_method' => 'COD', 'payment_status' => 'PENDING', 'verified_at' => null,
    'verified_by' => null, 'revision' => 7, 'checked_at' => $clock];
$codShipment = ['delivery_method' => 'LOCAL_COURIER', 'shipment_status' => 'DELIVERED'];
$lockedReads = [
    ['FROM orders o WHERE o\.id = :order_id LIMIT 1 FOR UPDATE$', $orderParams, [$codOrder]],
    ['revision, NOW\(\) AS checked_at FROM payments WHERE order_id = :order_id LIMIT 1 FOR UPDATE$', $orderParams, [$codPayment]],
    ['FROM shipments WHERE order_id = :order_id LIMIT 1 FOR UPDATE$', $orderParams, [$codShipment]],
    ['^SELECT id FROM sales_transactions WHERE order_id = :order_id LIMIT 1 FOR UPDATE$', $orderParams, []],
];
$adminRead = ['^SELECT id FROM admins WHERE id = \? LOCK IN SHARE MODE$', [3], [['id' => 3]]];
$finalizerReads = array_merge([$adminRead], $lockedReads);
$linkSql = 'FROM order_items oi '
    . 'LEFT JOIN sales_transaction_items item_link ON item_link\.order_item_id = oi\.id '
    . 'LEFT JOIN sales_transaction_items product_link ON product_link\.product_id = oi\.product_id '
    . 'LEFT JOIN sales_transactions st ON st\.id = COALESCE\(item_link\.sale_id, product_link\.sale_id\) '
    . 'WHERE oi\.order_id = :order_id AND \(item_link\.id IS NOT NULL OR product_link\.id IS NOT NULL\) ORDER BY oi\.id LIMIT 1';
$inventoryReads = [
    ['^SELECT id, product_id, quantity, unit_price FROM order_items WHERE order_id = \? ORDER BY id FOR UPDATE$', [19], [
        ['id' => 19, 'product_id' => 2, 'quantity' => 1, 'unit_price' => '10.10'],
        ['id' => 20, 'product_id' => 3, 'quantity' => 1, 'unit_price' => '20.20']]],
    ['^SELECT id, status, retired_at FROM products WHERE id IN \(\?,\?\) ORDER BY id FOR UPDATE$', [2, 3], [
        ['id' => 2, 'status' => 'SOLD', 'retired_at' => null], ['id' => 3, 'status' => 'SOLD', 'retired_at' => null]]],
    ['^SELECT oi\.id FROM order_items oi .* WHERE oi\.product_id = \? AND oi\.order_id <> \? AND \( .* \) LIMIT 1$', [2, 19], []],
    ['^SELECT oi\.id FROM order_items oi .* WHERE oi\.product_id = \? AND oi\.order_id <> \? AND \( .* \) LIMIT 1$', [3, 19], []],
    ['^SELECT id FROM sales_transaction_items WHERE product_id = \? FOR UPDATE$', [2], []],
    ['^SELECT id FROM sales_transaction_items WHERE order_item_id = \? FOR UPDATE$', [19], []],
    ['^SELECT id FROM sales_transaction_items WHERE product_id = \? FOR UPDATE$', [3], []],
    ['^SELECT id FROM sales_transaction_items WHERE order_item_id = \? FOR UPDATE$', [20], []],
    ['^SELECT subtotal FROM orders WHERE id = \?$', [19], [['subtotal' => '30.30']]],
];
$items = [
    ['order_item_id' => 19, 'product_id' => 2, 'product_name' => 'Historical shirt', 'unit_price' => '10.10', 'quantity' => 1, 'product_barcode' => 'SHIRT-2'],
    ['order_item_id' => 20, 'product_id' => 3, 'product_name' => 'Historical skirt', 'unit_price' => '20.20', 'quantity' => 1, 'product_barcode' => 'SKIRT-3'],
];
$itemSql = '^INSERT INTO sales_transaction_items \(sale_id, product_id, product_name, barcode_value, unit_price, order_item_id\) '
    . 'VALUES \(:sale_id, :product_id, :product_name, :barcode_value, :unit_price, :order_item_id\)$';
$writeSteps = [
    ["^UPDATE payments SET payment_status = 'PAID', verified_by = :admin_id, verified_at = :collected_at, revision = revision \+ 1 "
        . "WHERE order_id = :order_id AND payment_method = 'COD' AND payment_status = 'PENDING' "
        . 'AND verified_at IS NULL AND verified_by IS NULL AND revision = :revision$',
        [':admin_id' => 3, ':collected_at' => $clock, ':order_id' => 19, ':revision' => 7], []],
    ['^SELECT oi\.id AS order_item_id, oi\.product_id, oi\.product_name, oi\.unit_price, oi\.quantity, p\.barcode_value AS product_barcode '
        . 'FROM order_items oi LEFT JOIN products p ON p\.id = oi\.product_id WHERE oi\.order_id = :order_id ORDER BY oi\.id ASC FOR UPDATE$',
        $orderParams, $items],
    ['^SELECT subtotal FROM orders WHERE id = :order_id LIMIT 1$', $orderParams, [['subtotal' => '30.30']]],
    ["^INSERT INTO sales_transactions \(sale_channel, order_id, admin_id, total_amount, sold_at, request_key\) "
        . "VALUES \('ONLINE', :order_id, :admin_id, :total, :sold_at, :request_key\)$",
        [':order_id' => 19, ':admin_id' => 3, ':total' => '30.30', ':sold_at' => $clock, ':request_key' => 'online-order-000019'], []],
    [$itemSql, [':sale_id' => 901, ':product_id' => 2, ':product_name' => 'Historical shirt', ':barcode_value' => 'SHIRT-2', ':unit_price' => '10.10', ':order_item_id' => 19], []],
    [$itemSql, [':sale_id' => 901, ':product_id' => 3, ':product_name' => 'Historical skirt', ':barcode_value' => 'SKIRT-3', ':unit_price' => '20.20', ':order_item_id' => 20], []],
];

$collision = ['order_item_id' => 19, 'product_id' => 2, 'sale_item_id' => 113, 'linked_order_item_id' => 111,
    'sale_id' => 70, 'sale_channel' => 'ONLINE', 'sale_order_id' => 85];
$blocked = ' Sale finalization is blocked. Manual review is required; no records were changed.';
foreach ([
    ['no link', [], null],
    ['product-only collision', [$collision], 'Product #2 on order item #19 is already recorded in sale #70 for order #85 (sale item #113, order item #111).'],
    ['order-item collision', [array_replace($collision, ['linked_order_item_id' => 19])], 'Order item #19 is already recorded in sale #70 for order #85 (sale item #113, order item #19).'],
    ['walk-in collision', [array_replace($collision, ['linked_order_item_id' => null, 'sale_channel' => 'WALKIN', 'sale_order_id' => null])],
        'Product #2 on order item #19 is already recorded in walk-in sale #70 (sale item #113).'],
] as [$label, $rows, $description]) {
    $expected = $description === null ? null : ['ok' => false, 'reason' => 'cod_sale_linkage_conflict', 'message' => $description . $blocked, 'status' => 409];
    $pdo = new SalesUnitPDO([[$linkSql . '$', $orderParams, $rows]]);
    check("admin advisory: {$label}", payment_cod_sale_linkage_error($pdo, 19), $expected);
    check('advisory is read-only and consumes its query', [$pdo->events, $pdo->writes, $pdo->queue], [[], [], []]);
    if ($rows !== []) {
        $conflictAt = $label === 'order-item collision' ? 5 : 4;
        $guard = array_slice($inventoryReads, 0, $conflictAt + 1);
        $guard[$conflictAt][2] = [['id' => 113]];
        $pdo = new SalesUnitPDO(array_merge($finalizerReads, $guard));
        check("locked finalizer: {$label}", payment_collect_cod_and_complete_sale($pdo, 3, 19, 7),
            ['ok' => false, 'reason' => 'sale_inventory_blocked', 'message' => 'An item or product already belongs to a committed sale. Sale finalization is blocked; no records were changed.', 'status' => 409]);
        check('known conflict aborts before any UPDATE/INSERT', [$pdo->events, $pdo->writes, $pdo->committed, $pdo->queue], [['begin', 'rollback'], [], [], []]);
    }
}
$pdo = new SalesUnitPDO();
$lockError = null;
try { payment_cod_sale_linkage_error($pdo, 19, true); } catch (LogicException $e) { $lockError = $e->getMessage(); }
check('locked linkage requires a transaction', $lockError, 'COD linkage validation requires an active transaction and order lock.');
check('missing transaction does not start one or write', [$pdo->events, $pdo->writes], [[], []]);

$pdo = new SalesUnitPDO(array_merge($finalizerReads, $inventoryReads, $writeSteps));
check('canonical COD success', payment_collect_cod_and_complete_sale($pdo, 3, 19, 7), ['ok' => true, 'state' => 'PAID', 'sale_id' => 901, 'status' => 200]);
$canonicalWrites = [$writeSteps[0][1], $writeSteps[3][1], $writeSteps[4][1], $writeSteps[5][1]];
check('historical item cents, DB timestamp and admin are committed once',
    [$pdo->events, array_column($pdo->committed, 1), $pdo->staged, $pdo->queue], [['begin', 'commit'], $canonicalWrites, [], []]);
$committed = $pdo->committed;
$pdo->queue = $finalizerReads;
$pdo->queue[0][1] = [9];
$pdo->queue[0][2] = [['id' => 9]];
$pdo->queue[2][2] = [array_replace($codPayment, ['payment_status' => 'PAID', 'verified_by' => 3, 'verified_at' => $clock, 'revision' => 8])];
$pdo->queue[4][2] = [['id' => 901]];
check('same-order replay by another admin is rejected', payment_collect_cod_and_complete_sale($pdo, 9, 19, 8)['reason'], 'cod_already_completed');
check('replay never overwrites collection or creates a second sale',
    [$pdo->events, $pdo->committed, $pdo->writes, $pdo->queue], [['begin', 'commit', 'begin', 'rollback'], $committed, $committed, []]);

$duplicateMessages = [
    3 => 'A completed-sale record already exists or conflicts with this order. Reload the order; existing sales will not be changed.',
    5 => 'An order item or product is already recorded in a completed sale. Manual review is required.',
];
foreach ([3 => 'sale_conflict', 5 => 'item_conflict'] as $failureAt => $reason) {
    foreach ([1062, 1452] as $nativeCode) {
        $error = new class($nativeCode) extends PDOException {
            public function __construct(int $nativeCode)
            {
                $this->code = '23000';
                $this->message = 'Private SQL detail: sale #70, sale item #113, order item #111, order #85, product #2.';
                $this->errorInfo = ['23000', $nativeCode, $this->message];
            }
        };
        $tail = array_slice($writeSteps, 0, $failureAt + 1);
        $tail[$failureAt][2] = $error;
        $pdo = new SalesUnitPDO(array_merge($finalizerReads, $inventoryReads, $tail));
        $expected = ['ok' => false, 'reason' => $nativeCode === 1062 ? $reason : 'cod_error',
            'message' => $nativeCode === 1062 ? $duplicateMessages[$failureAt]
                : 'COD collection and sale completion could not be confirmed. Reload the order to check its current state before retrying.',
            'status' => $nativeCode === 1062 ? 409 : 500];
        check("native {$nativeCode} at {$reason}: generic error exposes no other-order IDs", payment_collect_cod_and_complete_sale($pdo, 3, 19, 7), $expected);
        $discarded = $failureAt === 3 ? [$writeSteps[0][1]] : array_slice($canonicalWrites, 0, 3);
        check('failure rolls back staged collection, header and any prior detail',
            [$pdo->events, array_column($pdo->discarded, 1), $pdo->staged, $pdo->committed, $pdo->queue], [['begin', 'rollback'], $discarded, [], [], []]);
        if ($nativeCode === 1452) {
            $pdo = new SalesUnitPDO(array_slice($tail, 1));
            $pdo->beginTransaction();
            $caught = null;
            try { sales_create_online_locked($pdo, 19, $clock, 3); } catch (PDOException $e) { $caught = $e; }
            check("shared writer rethrows original 23000/1452 at {$reason}", $caught === $error, true);
            check('shared writer leaves transaction ownership to caller', [$pdo->events, $pdo->queue], [['begin'], []]);
            $pdo->rollBack();
        }
    }
}

foreach ([
    ['cod_receipt_pending', ['received_at' => null], [], []],
    ['cod_cancellation_locked', ['cancellation_status' => 'REQUESTED'], [], []],
    ['cod_not_delivered', [], [], ['shipment_status' => 'IN_TRANSIT']],
    ['cod_not_courier', [], [], ['delivery_method' => 'STORE_PICKUP']],
    ['not_cod', [], ['payment_method' => 'GCASH'], []],
    ['conflict', [], [], []],
] as [$reason, $orderChanges, $paymentChanges, $shipmentChanges]) {
    $steps = $finalizerReads;
    $steps[1][2] = [array_replace($codOrder, $orderChanges)];
    $steps[2][2] = [array_replace($codPayment, $paymentChanges)];
    $steps[3][2] = [array_replace($codShipment, $shipmentChanges)];
    $pdo = new SalesUnitPDO($steps);
    $result = payment_collect_cod_and_complete_sale($pdo, 3, 19, $reason === 'conflict' ? 6 : 7);
    check("eligibility/stale revision: {$reason}", [$result['ok'], $result['reason'], $result['status']], [false, $reason, 409]);
    check('eligibility/revision blocks before linkage or collection', [$pdo->events, $pdo->writes, $pdo->queue], [['begin', 'rollback'], [], []]);
}
check('legacy NULL courier method remains eligible',
    payment_cod_collection_reason($codOrder, $codPayment, array_replace($codShipment, ['delivery_method' => null])), null);

foreach ([null, $codOrder['received_at']] as $receivedAt) {
    $steps = $lockedReads;
    $steps[0] = ['FROM orders WHERE id = :order_id AND customer_id = :customer_id LIMIT 1 FOR UPDATE$',
        [':order_id' => 19, ':customer_id' => 52], [array_replace($codOrder, ['received_at' => $receivedAt])]];
    if ($receivedAt === null) {
        $steps[] = ['^SELECT quantity FROM order_items WHERE order_id = :order_id ORDER BY id FOR UPDATE$', $orderParams, [[1], [1]]];
        $steps[] = ['^UPDATE orders SET received_at = :received_at WHERE id = :order_id AND received_at IS NULL$',
            static fn (array $params): bool => is_string($params[':received_at'] ?? null) && ($params[':order_id'] ?? null) === 19, []];
    }
    $pdo = new SalesUnitPDO($steps);
    $result = sales_confirm_receipt_online($pdo, 52, 19);
    check('COD first receipt/replay creates no sale', [$result['ok'], $result['idempotent'], $result['sale']], [true, $receivedAt !== null, null]);
    check('receipt only writes its timestamp, never payment, stock or a sale',
        [$pdo->events, array_column($pdo->committed, 1), $pdo->queue], [['begin', 'commit'],
            $receivedAt === null ? [[':received_at' => $result['received_at'], ':order_id' => 19]] : [], []]);
    if ($receivedAt !== null) {
        check('receipt replay preserves original timestamp', $result['received_at'], $receivedAt);
    }
}

echo "== courier sale previews / inventory guards (DB-free) ==\n";
$gcashAt = '2026-01-13 02:03:04';
$gcashPayment = array_replace($codPayment, ['payment_method' => 'GCASH', 'payment_status' => 'PAID',
    'verified_by' => 8, 'verified_at' => $gcashAt]);
$gcashReads = $finalizerReads;
$gcashReads[2][2] = [$gcashPayment];
$saleFunctions = ['COD' => 'payment_collect_cod_and_complete_sale', 'GCASH' => 'payment_finalize_gcash_sale'];
foreach ($saleFunctions as $method => $finalize) {
    $reads = $method === 'COD' ? $finalizerReads : $gcashReads;
    $pdo = new SalesUnitPDO(array_merge($reads, $inventoryReads), readOnly: true);
    check($method . ' eligible preview succeeds without the canonical writer', $finalize($pdo, 3, 19, 7, validateOnly: true), ['ok' => true, 'status' => 200]);
    check($method . ' preview rolls back and has no attempted/staged/committed writes',
        [$pdo->events, $pdo->inTransaction(), $pdo->writes, $pdo->staged, $pdo->committed, $pdo->queue],
        [['begin', 'rollback'], false, [], [], [], []]);
    check($method . ' preview explicitly uses READ COMMITTED', $pdo->isolation, ['SET TRANSACTION ISOLATION LEVEL READ COMMITTED']);

    $pdo = new SalesUnitPDO(array_merge($reads, $inventoryReads));
    $pdo->queue[5][2] = array_reverse($pdo->queue[5][2]);
    $pdo->queue[9] = $inventoryReads[6];
    $pdo->queue[10] = $inventoryReads[7];
    $pdo->queue[11] = $inventoryReads[4];
    $pdo->queue[12] = $inventoryReads[5];
    check($method . ' products are locked sorted even when item product IDs are reversed',
        $finalize($pdo, 3, 19, 7, validateOnly: true), ['ok' => true, 'status' => 200]);
    check($method . ' sorted-product preview consumes every independent sale-key check', [$pdo->queue, $pdo->writes, $pdo->events], [[], [], ['begin', 'rollback']]);

    foreach ([
        ['no items', 0, []],
        ['quantity two', 0, [array_replace($inventoryReads[0][2][0], ['quantity' => 2])]],
        ['missing product identity', 0, [array_replace($inventoryReads[0][2][0], ['product_id' => null])]],
        ['duplicate product identity', 0, [$inventoryReads[0][2][0], array_replace($inventoryReads[0][2][1], ['product_id' => 2])]],
        ['missing current product', 1, [$inventoryReads[1][2][0]]],
        ['AVAILABLE is not held', 1, [array_replace($inventoryReads[1][2][0], ['status' => 'AVAILABLE']), $inventoryReads[1][2][1]]],
        ['retired second garment', 1, [$inventoryReads[1][2][0], array_replace($inventoryReads[1][2][1], ['retired_at' => $clock])]],
        ['other active hold', 2, [['id' => 88]]],
        ['independent product sale key', 6, [['id' => 113]]],
        ['independent order-item sale key', 7, [['id' => 113]]],
    ] as [$label, $failureAt, $rows]) {
        foreach ([false, true] as $preview) {
            $guard = array_slice($inventoryReads, 0, $failureAt + 1);
            $guard[$failureAt][2] = $rows;
            // The second product is checked only after the first product's hold.
            if ($label === 'retired second garment') $guard[] = $inventoryReads[2];
            $pdo = new SalesUnitPDO(array_merge($reads, $guard), readOnly: $preview);
            $result = $finalize($pdo, 3, 19, 7, validateOnly: $preview);
            check($method . ' ' . ($preview ? 'preview' : 'action') . ' rejects ' . $label,
                [$result['ok'], $result['reason'], $result['status']], [false, 'sale_inventory_blocked', 409]);
            check($method . ' inventory blocker cannot reach collection or any sale writer',
                [$pdo->events, $pdo->writes, $pdo->committed, $pdo->queue, $pdo->inTransaction()], [['begin', 'rollback'], [], [], [], false]);
        }
    }

    foreach ([false, true] as $preview) {
        $pdo = new SalesUnitPDO($reads, readOnly: $preview);
        check($method . ' stale revision is rejected before inventory even in preview', $finalize($pdo, 3, 19, 6, $preview)['reason'], 'conflict');
        check($method . ' stale revision rolls back without any write', [$pdo->events, $pdo->queue, $pdo->writes], [['begin', 'rollback'], [], []]);
        $missingAdmin = $reads;
        $missingAdmin[0][2] = [];
        $pdo = new SalesUnitPDO([$missingAdmin[0]], readOnly: $preview);
        check($method . ' nonexistent admin is rejected before reading order', $finalize($pdo, 3, 19, 7, $preview)['reason'], strtolower($method) . '_admin_required');
        check($method . ' nonexistent admin leaves no transaction or write', [$pdo->events, $pdo->inTransaction(), $pdo->queue, $pdo->writes], [['begin', 'rollback'], false, [], []]);
        $completedReads = $reads;
        $completedReads[4][2] = [['id' => 901]];
        if ($method === 'COD') $completedReads[2][2] = [array_replace($codPayment, ['payment_status' => 'PAID', 'verified_by' => 3, 'verified_at' => $clock])];
        $pdo = new SalesUnitPDO($completedReads, readOnly: $preview);
        check($method . ' completed-sale replay is not fresh eligibility', $finalize($pdo, 3, 19, 7, $preview)['reason'], strtolower($method) . '_already_completed');
        check($method . ' completed sale is never rewritten', [$pdo->events, $pdo->queue, $pdo->writes], [['begin', 'rollback'], [], []]);
    }
    foreach ([
        ['subtotal wrongly includes shipping', '80.30', null],
        ['missing order subtotal', null, null],
        ['malformed order subtotal', 'invalid', null],
        ['invalid historical item price', '30.30', 'invalid'],
        ['negative historical item price', '30.30', '-10.10'],
    ] as [$label, $subtotal, $price]) {
        foreach ([false, true] as $preview) {
            $guard = $inventoryReads;
            $guard[8][2] = $subtotal === null ? [] : [['subtotal' => $subtotal]];
            if ($price !== null) $guard[0][2][0]['unit_price'] = $price;
            $pdo = new SalesUnitPDO(array_merge($reads, $guard), readOnly: $preview);
            $result = $finalize($pdo, 3, 19, 7, $preview);
            check($method . ' ' . $label . ' rejected before canonical writer',
                [$result['ok'], $result['reason'], $result['status'], str_contains($result['message'], 'Shipping cannot be included')],
                [false, 'sale_inventory_blocked', 409, true]);
            check($method . ' bad price/subtotal cannot collect payment or write sale', [$pdo->events, $pdo->writes, $pdo->queue], [['begin', 'rollback'], [], []]);
        }
    }
    foreach (['UNKNOWN', 'REQUESTED', 'APPROVED'] as $cancellation) {
        $guardedReads = $reads;
        $guardedReads[1][2] = [array_replace($codOrder, ['cancellation_status' => $cancellation])];
        $pdo = new SalesUnitPDO($guardedReads, readOnly: true);
        check($method . ' cancellation ' . $cancellation . ' fails closed', $finalize($pdo, 3, 19, 7, true)['reason'], strtolower($method) . '_cancellation_locked');
        check($method . ' cancellation blocks before stock or sale reads', [$pdo->queue, $pdo->writes, $pdo->events], [[], [], ['begin', 'rollback']]);
    }
    foreach (['pickup_status' => 'READY_FOR_PICKUP', 'picked_up_at' => $clock] as $field => $value) {
        $guardedReads = $reads;
        $guardedReads[1][2] = [array_replace($codOrder, [$field => $value])];
        $pdo = new SalesUnitPDO($guardedReads, readOnly: true);
        check($method . ' contradictory ' . $field . ' is not courier finalization', $finalize($pdo, 3, 19, 7, true)['reason'], strtolower($method) . '_not_courier');
        check($method . ' contradictory pickup changes no records', [$pdo->queue, $pdo->writes, $pdo->events], [[], [], ['begin', 'rollback']]);
    }
    $pdo = new SalesUnitPDO();
    check($method . ' anonymous action does not need a DB', $finalize($pdo, 0, 19, 7)['status'], 403);
    check($method . ' invalid order does not need a DB', $finalize($pdo, 3, 0, 7)['reason'], 'bad_request');
    check($method . ' invalid revision does not need a DB', $finalize($pdo, 3, 19, -1)['reason'], 'bad_request');
    check($method . ' invalid requests never begin or read', [$pdo->events, $pdo->reads, $pdo->writes, $pdo->isolation], [[], [], [], []]);
    $pdo->beginTransaction();
    $error = null;
    try { $finalize($pdo, 3, 19, 7, true); } catch (LogicException $e) { $error = $e->getMessage(); }
    check($method . ' finalizer rejects caller-owned transaction', $error !== null && str_contains($error, 'must own its transaction'), true);
    check($method . ' nested-call rejection leaves caller transaction intact', [$pdo->inTransaction(), $pdo->reads, $pdo->writes], [true, [], []]);
    $pdo->rollBack();
}

$gcashWrites = array_slice($writeSteps, 1);
$gcashWrites[2][1][':sold_at'] = $gcashAt;
$pdo = new SalesUnitPDO(array_merge($gcashReads, $inventoryReads, $gcashWrites));
check('canonical GCash finalization succeeds', payment_finalize_gcash_sale($pdo, 3, 19, 7), ['ok' => true, 'sale_id' => 901, 'status' => 200]);
check('GCash writes only one canonical header and exact historical item snapshots',
    [$pdo->events, array_column($pdo->committed, 1), $pdo->queue, $pdo->inTransaction()],
    [['begin', 'commit'], [$gcashWrites[2][1], $gcashWrites[3][1], $gcashWrites[4][1]], [], false]);
check('GCash payment, receipt, shipment and inventory are never updated',
    count(array_filter($pdo->writes, static fn ($write) => !str_starts_with($write[0], 'INSERT INTO sales_'))), 0);
check('merchandise sale excludes shipping and payment/order grand totals', $pdo->committed[0][1][':total'] ?? null, '30.30');
check('GCash canonical sale keeps actual reconciliation timestamp, not preview clock', $pdo->committed[0][1][':sold_at'] ?? null, $gcashAt);

foreach ([2 => 'sale_conflict', 4 => 'item_conflict'] as $failureAt => $reason) {
    $error = new class extends PDOException {
        public function __construct() { $this->code = '23000'; $this->errorInfo = ['23000', 1062, 'Private duplicate detail']; }
    };
    $tail = array_slice($gcashWrites, 0, $failureAt + 1);
    $tail[$failureAt][2] = $error;
    $pdo = new SalesUnitPDO(array_merge($gcashReads, $inventoryReads, $tail));
    check('GCash duplicate ' . $reason . ' is rejected by the canonical writer', payment_finalize_gcash_sale($pdo, 3, 19, 7)['reason'], $reason);
    check('GCash duplicate rolls back the header and any earlier details only',
        [$pdo->events, array_column($pdo->discarded, 1), $pdo->committed, $pdo->queue, $pdo->inTransaction()],
        [['begin', 'rollback'], $failureAt === 2 ? [] : [$gcashWrites[2][1], $gcashWrites[3][1]], [], [], false]);
}

$pdo = new SalesUnitPDO();
$error = null;
try { payment_courier_sale_inventory_error($pdo, 19); } catch (LogicException $e) { $error = $e->getMessage(); }
check('inventory helper requires caller transaction', $error, 'Sale inventory checks require the order transaction.');
check('inventory helper without transaction does not read or write', [$pdo->events, $pdo->reads, $pdo->writes], [[], [], []]);

echo "== Customer courier receipt (DB-free locked transaction) ==\n";
require_once __DIR__ . '/../../includes/delivery.php';
$receiptOwner = [':order_id' => 19, ':customer_id' => 8];
$receiptOrder = array_replace($codOrder, ['status' => 'SHIPPED', 'received_at' => null,
    'pickup_status' => null, 'picked_up_at' => null]);
$receiptShipment = array_replace($codShipment, ['shipment_status' => 'OUT_FOR_DELIVERY',
    'tracking_number' => 'SYNTHETIC-19', 'shipped_at' => $clock, 'delivered_at' => null,
    'delivery_cycle' => 1, 'delivery_revision' => 3, 'failed_attempts' => 0]);
$receiptProbe = [
    ['^SELECT id, status, cancellation_status, received_at FROM orders WHERE id = :order_id AND customer_id = :customer_id LIMIT 1 FOR UPDATE$', $receiptOwner, [$receiptOrder]],
    [$lockedReads[1][0], $orderParams, [$codPayment]],
    [$lockedReads[2][0], $orderParams, [$receiptShipment]],
    $lockedReads[3],
    ['^SELECT \* FROM shipments WHERE order_id = \? FOR UPDATE$', [19], [$receiptShipment]],
];
$receiptGuards = [
    ['FROM orders o WHERE o\.id = :order_id AND o\.customer_id = :customer_id LIMIT 1 FOR UPDATE$', $receiptOwner, [$receiptOrder]],
    $receiptProbe[1], $receiptProbe[4],
    ['^SELECT id FROM sales_transactions WHERE order_id = \? FOR UPDATE$', [19], []],
    ['^SELECT \* FROM delivery_events WHERE action_key = \? ORDER BY event_index DESC FOR UPDATE$',
        static fn ($params): bool => count($params) === 1 && sales_is_valid_request_key($params[0]), []],
    ['^SELECT delivery_cycle, delivery_revision, resulting_state FROM delivery_events WHERE order_id = \? ORDER BY id DESC LIMIT 1 FOR UPDATE$', [19],
        [['delivery_cycle' => 1, 'delivery_revision' => 3, 'resulting_state' => 'OUT_FOR_DELIVERY']]],
    ["^SELECT attempt_number FROM delivery_events WHERE order_id = \\? AND delivery_cycle = \\? AND event_type = 'DELIVERY_ATTEMPT_FAILED' ORDER BY attempt_number FOR UPDATE$", [19, 1], []],
    ['^SELECT \* FROM order_items WHERE order_id = \? ORDER BY id FOR UPDATE$', [19], [['id' => 19, 'product_id' => 2, 'quantity' => 1]]],
    ['^SELECT id, status, retired_at, barcode_value FROM products WHERE id IN \(\?\) ORDER BY id FOR UPDATE$', [2],
        [['id' => 2, 'status' => 'SOLD', 'retired_at' => null, 'barcode_value' => 'SYNTHETIC-2']]],
    ['^SELECT id FROM sales_transaction_items WHERE product_id IN \(\?\) FOR UPDATE$', [2], []],
    ['^SELECT id FROM sales_transaction_items WHERE order_item_id = \? FOR UPDATE$', [19], []],
    $inventoryReads[2],
    ['^SELECT \* FROM order_item_verifications WHERE order_id = \? ORDER BY order_item_id FOR UPDATE$', [19],
        [['order_item_id' => 19, 'product_id' => 2, 'verified_by' => 3, 'verified_at' => $clock, 'method' => 'MANUAL', 'reason' => 'Identified garment']]],
    ['^SELECT i\.\*, e\.order_id AS event_order_id, .* WHERE i\.order_id = \? AND i\.delivery_cycle = \? FOR UPDATE$', [19, 1], []],
    ['^SELECT NOW\(\)$', null, [[$clock]]],
];
$receiptWrites = [
    ["^UPDATE shipments SET shipment_status = \\?, delivery_revision = \\?, failed_attempts = \\?, delivered_at = CASE WHEN \\? = 'DELIVERED' THEN COALESCE\\(delivered_at, \\?\\) ELSE delivered_at END WHERE order_id = \\? AND delivery_cycle = \\? AND delivery_revision = \\? AND shipment_status = \\?$",
        ['DELIVERED', 4, 0, 'DELIVERED', $clock, 19, 1, 3, 'OUT_FOR_DELIVERY'], []],
    ["^UPDATE orders SET status = 'DELIVERED', received_at = \\? WHERE id = \\? AND customer_id = \\? AND status = 'SHIPPED' AND received_at IS NULL$", [$clock, 19, 8], []],
    ['^SELECT received_at FROM orders WHERE id = \?$', [19], [[$clock]]],
    ['^INSERT INTO customer_order_notifications .* ON DUPLICATE KEY UPDATE id = customer_order_notifications\.id$',
        [':type' => 'ORDER_DELIVERED', ':message' => 'Your order #19 has been delivered and your receipt confirmation is recorded. Thank you!', ':order_id' => 19], []],
];
foreach (['COD' => $codPayment, 'GCASH' => array_replace($gcashPayment,
    ['submitted_at' => $gcashAt, 'gcash_reference' => '1234567890123', 'rejection_reason' => null, 'rejected_at' => null])]
    as $method => $receiptPayment) {
    $probe = $receiptProbe; $guards = $receiptGuards;
    $probe[1][2] = $guards[1][2] = [$receiptPayment];
    if ($method === 'GCASH') array_splice($guards, 6, 0,
        [['^SELECT id FROM admins WHERE id = \? LOCK IN SHARE MODE$', [8], [['id' => 8]]]]);
    $pdo = new SalesUnitPDO(array_merge($probe, $guards, $receiptWrites));
    $result = sales_confirm_receipt_online($pdo, 8, 19);
    check($method . ' receipt atomically delivers the order with the DB receipt timestamp',
        [$result['ok'], $result['idempotent'] ?? null, $result['state'] ?? null, $result['received_at'] ?? null, $result['sale'] ?? null],
        [true, false, 'DELIVERED', $clock, null]);
    check($method . ' receipt commits only shipment, order and one existing notification',
        [$pdo->events, count($pdo->committed), $pdo->queue, $pdo->inTransaction()],
        [['begin', 'rollback', 'begin', 'commit'], 3, [], false]);
    check($method . ' receipt never writes payment, sales, profit, stock or admin-attributed ledger entries',
        count(array_filter($pdo->committed, static fn ($write): bool => !preg_match('/^(UPDATE shipments |UPDATE orders |INSERT INTO customer_order_notifications )/', $write[0]))), 0);

    $retry = array_slice($probe, 0, 4);
    $retry[0][2] = [array_replace($receiptOrder, ['status' => 'DELIVERED', 'received_at' => $clock])];
    $retry[2][2] = [array_replace($receiptShipment, ['shipment_status' => 'DELIVERED', 'delivered_at' => $clock])];
    $pdo = new SalesUnitPDO($retry, readOnly: true);
    check($method . ' receipt retry succeeds from persisted receipt only',
        sales_confirm_receipt_online($pdo, 8, 19)['idempotent'], true);
    check($method . ' retry creates no duplicated sale, ledger or notification',
        [$pdo->events, $pdo->writes, $pdo->queue], [['begin', 'commit'], [], []]);
}
$pdo = new SalesUnitPDO([[$receiptProbe[0][0], $receiptOwner, []]], readOnly: true);
check('foreign receipt order has the same generic not-found rejection', sales_confirm_receipt_online($pdo, 8, 19)['reason'], 'not_found');
check('foreign receipt changes no rows', [$pdo->events, $pdo->queue, $pdo->writes], [['begin', 'rollback'], [], []]);
foreach (['PENDING', 'CONFIRMED', 'PACKED', 'CANCELLED'] as $status) {
    $probe = [$receiptProbe[0]]; $probe[0][2] = [array_replace($receiptOrder, ['status' => $status])];
    $pdo = new SalesUnitPDO($probe, readOnly: true);
    check($status . ' cannot confirm receipt', sales_confirm_receipt_online($pdo, 8, 19)['reason'], 'not_delivered');
    check($status . ' rejection never writes', [$pdo->queue, $pdo->writes], [[], []]);
}
foreach (['STORE_PICKUP', 'IN_TRANSIT', 'DELIVERY_ATTEMPT_FAILED', 'RETURN_TO_SELLER', 'RETURNED_TO_SHOP'] as $state) {
    $probe = array_slice($receiptProbe, 0, 4);
    $probe[2][2] = [array_replace($receiptShipment, $state === 'STORE_PICKUP'
        ? ['delivery_method' => 'STORE_PICKUP'] : ['shipment_status' => $state])];
    $pdo = new SalesUnitPDO($probe, readOnly: true);
    check($state . ' receipt is ineligible', sales_confirm_receipt_online($pdo, 8, 19)['reason'], $state === 'STORE_PICKUP' ? 'not_courier_order' : 'not_delivered');
    check($state . ' receipt rejection never writes', [$pdo->queue, $pdo->writes], [[], []]);
}
foreach ([['notification error', 3, new RuntimeException('Synthetic notification failure'), 'error'],
    ['conditional order conflict', 1, null, 'conflict']] as [$label, $at, $error, $reason]) {
    $tail = array_slice($receiptWrites, 0, $at + 1);
    if ($error !== null) $tail[$at][2] = $error; else $tail[$at][3] = 0;
    $pdo = new SalesUnitPDO(array_merge($receiptProbe, $receiptGuards, $tail));
    check($label . ' rejects receipt instead of reporting success', sales_confirm_receipt_online($pdo, 8, 19)['reason'], $reason);
    check($label . ' rolls back both order and shipment and commits no notification',
        [$pdo->events, $pdo->committed, $pdo->queue, $pdo->inTransaction()], [['begin', 'rollback', 'begin', 'rollback'], [], [], false]);
}

echo "\n";
echo "Ran {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
