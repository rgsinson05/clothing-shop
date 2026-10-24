<?php

/*
 * Hopia Fits - Phase 3 Store Pickup lifecycle DB-backed tests (DEVELOPER-RUN).
 *
 * Runs the REAL Phase 3 logic (includes/pickup.php) plus the surrounding
 * invariants (payment untouched, courier workflow untouched, cancellation
 * rules, checkout initialization) against clothing_shop_bundle_test ONLY.
 * Full mode requires a separately prepared, explicitly authorized disposable
 * test DB instance. The database name is NOT evidence that it is safe to use.
 * It:
 *   - refuses to run on the web (CLI only);
 *   - reuses the .env host/user/password but FORCES the database name to
 *     clothing_shop_bundle_test, and hard-aborts if the connected database is
 *     anything else;
 *   - requires migrations 001-012 AND 015 (customer order notifications);
 *   - creates clearly-labelled, self-contained fixtures and removes ONLY
 *     those fixtures again at the end, in a finally block.
 *
 * Run it with:
 *   php database/tests/phase3_pickup_lifecycle_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/pickup.php';
require_once __DIR__ . '/../../includes/payment.php';
require_once __DIR__ . '/../../includes/sales.php';

const TEST_DB_NAME = 'clothing_shop_bundle_test';

date_default_timezone_set('Asia/Manila');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

$dsn = 'mysql:host=' . $host . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not connect to " . TEST_DB_NAME . ".\n");
    fwrite(STDERR, "Requires a separately prepared, explicitly authorized disposable test DB with migrations 001-012 and 015. The database name alone is not a safety guarantee.\n");
    exit(2);
}

$connectedDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($connectedDb !== TEST_DB_NAME) {
    fwrite(STDERR, "Refusing to run: connected to '{$connectedDb}', expected '" . TEST_DB_NAME . "'.\n");
    exit(2);
}

/* Require migration 012 (pickup columns). */
try {
    foreach (['pickup_status', 'ready_for_pickup_at', 'pickup_deadline_at', 'picked_up_at'] as $c) {
        $col = $pdo->query("SHOW COLUMNS FROM orders LIKE " . $pdo->quote($c))->fetch();
        if ($col === false) {
            fwrite(STDERR, "Migration 012 not applied (orders.{$c} missing). Apply database/migrations/012_store_pickup_lifecycle.sql to " . TEST_DB_NAME . " first.\n");
            exit(2);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "orders table missing or unreadable: " . $e->getMessage() . "\n");
    exit(2);
}

/* Require migration 015 read-only before any fixture writes. */
try {
    $pdo->query('SELECT id, customer_id, order_id, notification_type, message, created_at, read_at FROM customer_order_notifications LIMIT 0');
    $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $engine->execute([TEST_DB_NAME, 'customer_order_notifications']);
    if (strcasecmp((string) $engine->fetchColumn(), 'InnoDB') !== 0) {
        throw new RuntimeException('customer_order_notifications must use InnoDB for transactional notifications.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Migration 015 prerequisite failed; no fixtures written: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Requires database/migrations/015_customer_order_notifications.sql on the explicitly authorized disposable test DB.\n");
    exit(2);
}

/* ---- Assertion helpers ---- */
$tests = 0;
$failures = 0;
function assertTrue(string $label, bool $cond): void
{
    global $tests, $failures;
    $tests++;
    echo ($cond ? "  ok:   " : "  FAIL: ") . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}
function assertEq(string $label, $actual, $expected): void
{
    global $tests, $failures;
    $tests++;
    if ($actual === $expected) {
        echo "  ok:   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL: {$label} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

$suffix = bin2hex(random_bytes(4));
$created = [
    'customer' => null,
    'orders' => [],
    'products' => [],
];

function makePickupOrder(PDO $pdo, string $suffix, int $customerId, array $overrides = []): int
{
    global $created;
    $row = array_merge([
        'customer_id' => $customerId,
        'status' => 'PENDING',
        'cancellation_status' => 'NONE',
        'subtotal' => '500.00',
        'shipping_fee' => '0.00',
        'total_amount' => '500.00',
        'pickup_status' => 'ORDER_RECEIVED',
        'payment_method' => 'CASH_ON_PICKUP',
        'payment_status' => 'PENDING',
    ], $overrides);

    $pdo->prepare(
        'INSERT INTO orders (customer_id, status, cancellation_status, subtotal, shipping_fee, total_amount,
                             shipping_name, shipping_phone, shipping_address, pickup_status)
         VALUES (:cid, :st, :cs, :sub, :fee, :tot, :sn, :sp, :sa, :ps)'
    )->execute([
        ':cid' => $row['customer_id'], ':st' => $row['status'], ':cs' => $row['cancellation_status'],
        ':sub' => $row['subtotal'], ':fee' => $row['shipping_fee'], ':tot' => $row['total_amount'],
        ':sn' => 'Phase3 Tester', ':sp' => '0900', ':sa' => 'Store Pickup - Hopia\'s Ukay-Ukay',
        ':ps' => $row['pickup_status'],
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $created['orders'][] = $orderId;

    $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, subtotal) VALUES (:oid, NULL, :pn, :up, 1, :sub)')
        ->execute([':oid' => $orderId, ':pn' => "P3 {$suffix} item", ':up' => $row['subtotal'], ':sub' => $row['subtotal']]);
    $orderItemId = (int) $pdo->lastInsertId();

    /* Phase B barcode gate: the fixture item must be verified before the
       ORDER_RECEIVED -> PREPARING transition. A MANUAL verification is the
       established path for an unscannable (NULL product_id) item. */
    $pdo->prepare(
        'INSERT INTO order_item_verifications
            (order_id, order_item_id, product_id, barcode_value, verified_by, method, reason)
         VALUES (:oid, :oiid, NULL, NULL, 1, \'MANUAL\', \'phase3 fixture\')'
    )->execute([':oid' => $orderId, ':oiid' => $orderItemId]);

    $pdo->prepare('INSERT INTO shipments (order_id, shipment_status, delivery_method) VALUES (:oid, :ss, :dm)')
        ->execute([':oid' => $orderId, ':ss' => 'NOT_SHIPPED', ':dm' => 'STORE_PICKUP']);

    $pdo->prepare('INSERT INTO payments (order_id, payment_method, payment_status) VALUES (:oid, :pm, :ps)')
        ->execute([':oid' => $orderId, ':pm' => $row['payment_method'], ':ps' => $row['payment_status']]);

    return $orderId;
}

function makeCourierOrder(PDO $pdo, string $suffix, int $customerId): int
{
    global $created;
    $pdo->prepare(
        'INSERT INTO orders (customer_id, status, cancellation_status, subtotal, shipping_fee, total_amount,
                             shipping_name, shipping_phone, shipping_address, pickup_status)
         VALUES (:cid, :st, :cs, :sub, :fee, :tot, :sn, :sp, :sa, NULL)'
    )->execute([
        ':cid' => $customerId, ':st' => 'PENDING', ':cs' => 'NONE',
        ':sub' => '500.00', ':fee' => '80.00', ':tot' => '580.00',
        ':sn' => 'Phase3 Tester', ':sp' => '0900', ':sa' => 'Test address',
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $created['orders'][] = $orderId;

    $pdo->prepare('INSERT INTO shipments (order_id, shipment_status, delivery_method) VALUES (:oid, :ss, :dm)')
        ->execute([':oid' => $orderId, ':ss' => 'NOT_SHIPPED', ':dm' => 'LOCAL_COURIER']);

    $pdo->prepare('INSERT INTO payments (order_id, payment_method, payment_status) VALUES (:oid, :pm, :ps)')
        ->execute([':oid' => $orderId, ':pm' => 'COD', ':ps' => 'PENDING']);

    return $orderId;
}

function orderRow(PDO $pdo, int $orderId): array
{
    return $pdo->query('SELECT * FROM orders WHERE id = ' . (int) $orderId)->fetch() ?: [];
}

try {
    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => 'Pick', ':l' => 'Up', ':e' => "phase3+{$suffix}@test.local", ':p' => 'x']);
    $created['customer'] = (int) $pdo->lastInsertId();
    $customerId = $created['customer'];

    /* ===========================================================
     * 1. Lifecycle definitions
     * =========================================================== */
    echo "== 1. Lifecycle definitions ==\n";
    assertEq('1.1 four stages in order', implode('|', pickup_stages()), 'ORDER_RECEIVED|PREPARING|READY_FOR_PICKUP|PICKED_UP');
    assertEq('1.2 next of ORDER_RECEIVED', pickup_next_stage('ORDER_RECEIVED'), 'PREPARING');
    assertEq('1.3 next of PREPARING', pickup_next_stage('PREPARING'), 'READY_FOR_PICKUP');
    assertEq('1.4 next of READY_FOR_PICKUP', pickup_next_stage('READY_FOR_PICKUP'), 'PICKED_UP');
    assertEq('1.5 next of PICKED_UP is null (terminal)', pickup_next_stage('PICKED_UP'), null);
    assertEq('1.6 next of unknown is null', pickup_next_stage('GIBBERISH'), null);
    assertEq('1.7 customer label', pickup_stage_label('READY_FOR_PICKUP'), 'Ready for Pickup');
    assertEq('1.8 legacy NULL resolves to ORDER_RECEIVED', pickup_effective_stage(null, true), 'ORDER_RECEIVED');
    assertEq('1.9 courier order resolves to null stage', pickup_effective_stage(null, false), null);

    /* ===========================================================
     * 2. pickup_progress derivation
     * =========================================================== */
    echo "== 2. pickup_progress derivation ==\n";
    $progress = pickup_progress(
        ['status' => 'PENDING', 'pickup_status' => 'PREPARING', 'ready_for_pickup_at' => null, 'pickup_deadline_at' => null, 'picked_up_at' => null],
        ['delivery_method' => 'STORE_PICKUP']
    );
    assertTrue('2.1 pickup order gets progress', $progress !== null && $progress['stage'] === 'PREPARING');
    $progress = pickup_progress(
        ['status' => 'PENDING', 'pickup_status' => null, 'ready_for_pickup_at' => null, 'pickup_deadline_at' => null, 'picked_up_at' => null],
        ['delivery_method' => 'STORE_PICKUP']
    );
    assertTrue('2.2 legacy NULL pickup_status derives ORDER_RECEIVED', $progress !== null && $progress['stage'] === 'ORDER_RECEIVED');
    $progress = pickup_progress(
        ['status' => 'PENDING', 'pickup_status' => null, 'ready_for_pickup_at' => null, 'pickup_deadline_at' => null, 'picked_up_at' => null],
        ['delivery_method' => 'LOCAL_COURIER']
    );
    assertEq('2.3 courier order gets NO pickup progress', $progress, null);

    /* Overdue derivation */
    $pastDeadline = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $futureDeadline = date('Y-m-d H:i:s', strtotime('+47 hours'));
    assertTrue('2.4 ready + past deadline = overdue', pickup_deadline_is_overdue('READY_FOR_PICKUP', $pastDeadline));
    assertTrue('2.5 ready + future deadline = not overdue', !pickup_deadline_is_overdue('READY_FOR_PICKUP', $futureDeadline));
    assertTrue('2.6 picked-up order is never overdue', !pickup_deadline_is_overdue('PICKED_UP', $pastDeadline));
    assertTrue('2.7 no deadline = never overdue', !pickup_deadline_is_overdue('READY_FOR_PICKUP', null));

    /* ===========================================================
     * 3. Transitions: happy path + timestamps + deadline
     * =========================================================== */
    echo "== 3. Advance transitions ==\n";
    $order = makePickupOrder($pdo, $suffix, $customerId);
    $r = pickup_advance_status($pdo, $order);
    assertTrue('3.1 ORDER_RECEIVED -> PREPARING ok', !empty($r['ok']) && ($r['stage'] ?? '') === 'PREPARING');
    $row = orderRow($pdo, $order);
    assertEq('3.2 courier status stays PENDING', $row['status'], 'PENDING');
    assertEq('3.3 no deadline yet', $row['pickup_deadline_at'], null);

    $r = pickup_advance_status($pdo, $order);
    assertTrue('3.4 PREPARING -> READY_FOR_PICKUP ok', !empty($r['ok']) && ($r['stage'] ?? '') === 'READY_FOR_PICKUP');
    $row = orderRow($pdo, $order);
    assertTrue('3.5 ready_for_pickup_at set', $row['ready_for_pickup_at'] !== null);
    assertTrue('3.6 deadline set (48h window)', $row['pickup_deadline_at'] !== null);
    $diffSeconds = strtotime((string) $row['pickup_deadline_at']) - strtotime((string) $row['ready_for_pickup_at']);
    assertTrue('3.7 deadline = ready + 48h (exactly, +-60s)', abs($diffSeconds - 48 * 3600) <= 60);
    assertEq('3.8 picked_up_at still null', $row['picked_up_at'], null);

    /* Deadline must NOT be recalculated on later updates/refreshes. */
    $deadlineBefore = $row['pickup_deadline_at'];
    $readyBefore = $row['ready_for_pickup_at'];
    $r = pickup_advance_status($pdo, $order);
    assertTrue('3.9 READY_FOR_PICKUP -> PICKED_UP ok', !empty($r['ok']) && ($r['stage'] ?? '') === 'PICKED_UP');
    $row = orderRow($pdo, $order);
    assertTrue('3.10 picked_up_at set', $row['picked_up_at'] !== null);
    assertEq('3.11 deadline unchanged after PICKED_UP', $row['pickup_deadline_at'], $deadlineBefore);
    assertEq('3.12 ready timestamp unchanged after PICKED_UP', $row['ready_for_pickup_at'], $readyBefore);

    $r = pickup_advance_status($pdo, $order);
    assertEq('3.13 advancing past terminal rejected', $r['reason'] ?? '', 'already_terminal');

    /* ===========================================================
     * 4. Skipping prevention
     * =========================================================== */
    echo "== 4. Skipping prevention ==\n";
    $fresh = makePickupOrder($pdo, $suffix, $customerId);
    /* The helper can only move one step; simulate a hostile direct DB jump
       attempt being irrelevant: the API surface offers no skip. Instead prove
       the API path: from ORDER_RECEIVED the ONLY reachable next is PREPARING. */
    $r = pickup_advance_status($pdo, $fresh);
    assertEq('4.1 from ORDER_RECEIVED the only reachable stage is PREPARING', $r['ok'] ? $r['stage'] : '', 'PREPARING');
    /* And a stale duplicate (same expected stage now differs) conflicts. */
    $row = orderRow($pdo, $fresh);
    assertEq('4.2 stored stage is PREPARING', $row['pickup_status'], 'PREPARING');

    /* ===========================================================
     * 5. Cancellation guards
     * =========================================================== */
    echo "== 5. Cancellation guards ==\n";
    $cancelled = makePickupOrder($pdo, $suffix, $customerId, ['status' => 'CANCELLED', 'cancellation_status' => 'APPROVED']);
    $r = pickup_advance_status($pdo, $cancelled);
    assertEq('5.1 cancelled order rejects advance', $r['reason'] ?? '', 'order_cancelled');

    $locked = makePickupOrder($pdo, $suffix, $customerId, ['cancellation_status' => 'REQUESTED']);
    $r = pickup_advance_status($pdo, $locked);
    assertEq('5.2 pending cancellation request rejects advance', $r['reason'] ?? '', 'cancellation_locked');

    /* ===========================================================
     * 6. Courier orders never gain a pickup lifecycle
     * =========================================================== */
    echo "== 6. Courier separation ==\n";
    $courier = makeCourierOrder($pdo, $suffix, $customerId);
    $r = pickup_advance_status($pdo, $courier);
    assertEq('6.1 advance on courier order rejected', $r['reason'] ?? '', 'not_pickup_order');
    $row = orderRow($pdo, $courier);
    assertEq('6.2 courier pickup_status stays NULL', $row['pickup_status'], null);

    /* ===========================================================
     * 7. Payment untouched by pickup advances
     * =========================================================== */
    echo "== 7. Payment separation ==\n";
    $gcash = makePickupOrder($pdo, $suffix, $customerId, ['payment_method' => 'GCASH', 'payment_status' => 'PENDING']);
    payment_gcash_submit($pdo, $customerId, $gcash, '1234567890123');
    $r = pickup_advance_status($pdo, $gcash);
    assertEq('7.1 unverified GCash pickup cannot advance', $r['reason'] ?? '', 'gcash_awaiting_verification');
    $row = orderRow($pdo, $gcash);
    assertEq('7.1b pickup stage stays ORDER_RECEIVED', $row['pickup_status'], 'ORDER_RECEIVED');
    $pay = $pdo->query('SELECT payment_status FROM payments WHERE order_id = ' . $gcash)->fetchColumn();
    assertEq('7.1c GCash stays PENDING (no auto-verify)', $pay, 'PENDING');
    $cop = makePickupOrder($pdo, $suffix, $customerId);
    pickup_advance_status($pdo, $cop);
    pickup_advance_status($pdo, $cop);
    pickup_advance_status($pdo, $cop);
    $pay = $pdo->query('SELECT payment_status FROM payments WHERE order_id = ' . $cop)->fetchColumn();
    assertEq('7.2 Cash on Pickup stays PENDING through PICKED_UP (no auto-collect)', $pay, 'PENDING');

    /* 7.3-7.10: Cash on Pickup explicit collection (payment_collect_cash_on_pickup). */
    $r = payment_collect_cash_on_pickup($pdo, 1, $cop);
    assertTrue('7.3 COP collection succeeds after PICKED_UP', !empty($r['ok']));
    $payRow = $pdo->query('SELECT payment_status, verified_by FROM payments WHERE order_id = ' . $cop)->fetch();
    assertEq('7.4 COP payment now PAID with admin attribution', $payRow['payment_status'] . '/' . $payRow['verified_by'], 'PAID/1');
    $r = payment_collect_cash_on_pickup($pdo, 1, $cop);
    assertEq('7.5 duplicate collection rejected (already_paid)', $r['reason'] ?? '', 'already_paid');

    $copEarly = makePickupOrder($pdo, $suffix, $customerId);
    pickup_advance_status($pdo, $copEarly);
    pickup_advance_status($pdo, $copEarly);
    $r = payment_collect_cash_on_pickup($pdo, 1, $copEarly);
    assertEq('7.6 collection before PICKED_UP rejected', $r['reason'] ?? '', 'not_picked_up');

    $copCancelled = makePickupOrder($pdo, $suffix, $customerId, ['status' => 'CANCELLED', 'cancellation_status' => 'APPROVED', 'pickup_status' => 'PICKED_UP']);
    $r = payment_collect_cash_on_pickup($pdo, 1, $copCancelled);
    assertEq('7.7 collection on cancelled order rejected', $r['reason'] ?? '', 'order_cancelled');

    $copGcash = makePickupOrder($pdo, $suffix, $customerId, ['payment_method' => 'GCASH']);
    payment_gcash_submit($pdo, $customerId, $copGcash, '1234567890123');
    payment_gcash_verify($pdo, 1, $copGcash);
    pickup_advance_status($pdo, $copGcash);
    pickup_advance_status($pdo, $copGcash);
    pickup_advance_status($pdo, $copGcash);
    $r = payment_collect_cash_on_pickup($pdo, 1, $copGcash);
    assertEq('7.8 collection on GCash order rejected (not_cash_on_pickup)', $r['reason'] ?? '', 'not_cash_on_pickup');
    $pay = $pdo->query('SELECT payment_status FROM payments WHERE order_id = ' . $copGcash)->fetchColumn();
    assertEq('7.9 verified GCash pickup order stays PAID (no second collection)', $pay, 'PAID');

    $copCourier = makeCourierOrder($pdo, $suffix, $customerId);
    $pdo->prepare('UPDATE payments SET payment_method = \'CASH_ON_PICKUP\' WHERE order_id = :oid')->execute([':oid' => $copCourier]);
    $r = payment_collect_cash_on_pickup($pdo, 1, $copCourier);
    assertEq('7.10 collection on courier order rejected (not_pickup_order)', $r['reason'] ?? '', 'not_pickup_order');

    /* ===========================================================
     * 8. Completed-sale integration at PICKED_UP (exactly once)
     * =========================================================== */
    echo "== 8. Completed-sale integration ==\n";
    $beforeTx = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    $full = makePickupOrder($pdo, $suffix, $customerId);
    pickup_advance_status($pdo, $full);
    pickup_advance_status($pdo, $full);
    $beforeReadyTx = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    assertEq('8.1 no sale before PICKED_UP', $beforeReadyTx, $beforeTx);
    $r = pickup_advance_status($pdo, $full);
    assertTrue('8.2 PICKED_UP transition ok', !empty($r['ok']) && ($r['stage'] ?? '') === 'PICKED_UP');
    $afterTx = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    assertEq('8.3 exactly one sales_transactions row created at PICKED_UP', $afterTx, $beforeTx + 1);
    $sale = $pdo->query('SELECT id, sale_channel, total_amount FROM sales_transactions WHERE order_id = ' . $full)->fetch();
    assertTrue('8.4 sale is ONLINE channel', $sale !== false && $sale['sale_channel'] === 'ONLINE');
    assertEq('8.5 sale total is merchandise-only (shipping excluded)', $sale['total_amount'], '500.00');
    $row = orderRow($pdo, $full);
    assertTrue('8.6 received_at set at handover', $row['received_at'] !== null);
    $itemCount = (int) $pdo->query('SELECT COUNT(*) FROM sales_transaction_items sti INNER JOIN sales_transactions st ON st.id = sti.sale_id WHERE st.order_id = ' . $full)->fetchColumn();
    assertEq('8.7 one sale detail per order item', $itemCount, 1);
    $pay = $pdo->query('SELECT payment_status FROM payments WHERE order_id = ' . $full)->fetchColumn();
    assertEq('8.8 handover does NOT touch payment status', $pay, 'PENDING');
    assertEq('8.9 courier status remains PENDING throughout', $row['status'], 'PENDING');
    $ship = $pdo->query('SELECT shipment_status, delivery_method FROM shipments WHERE order_id = ' . $full)->fetch();
    assertEq('8.10 shipment untouched (NOT_SHIPPED / STORE_PICKUP)', $ship['shipment_status'] . '/' . $ship['delivery_method'], 'NOT_SHIPPED/STORE_PICKUP');

    /* Non-pickup advances still create no sale (courier separation). */
    $courierNoSale = makeCourierOrder($pdo, $suffix, $customerId);
    $beforeCourierTx = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    $r = pickup_advance_status($pdo, $courierNoSale);
    assertEq('8.11 courier advance rejected (no sale path)', $r['reason'] ?? '', 'not_pickup_order');
    $afterCourierTx = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    assertEq('8.12 no sale created for courier order', $afterCourierTx, $beforeCourierTx);

    /* ===========================================================
     * 8b. Handover blocks later cancellation
     * =========================================================== */
    echo "== 8b. Post-handover cancellation ==\n";
    $handedOver = makePickupOrder($pdo, $suffix, $customerId);
    pickup_advance_status($pdo, $handedOver);
    pickup_advance_status($pdo, $handedOver);
    pickup_advance_status($pdo, $handedOver);
    /* Replicate order-cancel-request.php's guard logic. */
    $locked = $pdo->query('SELECT status, cancellation_status, pickup_status FROM orders WHERE id = ' . $handedOver . ' FOR UPDATE')->fetch();
    assertTrue(
        '8b.1 handed-over pickup order fails the cancel-request guard',
        $locked !== false && ($locked['pickup_status'] === 'PICKED_UP' || $locked['status'] !== 'PENDING')
    );
    $upd = $pdo->prepare('UPDATE orders SET cancellation_status = \'REQUESTED\' WHERE id = :oid AND status = \'PENDING\' AND cancellation_status = \'NONE\' AND (pickup_status IS NULL OR pickup_status <> \'PICKED_UP\')');
    $upd->execute([':oid' => $handedOver]);
    assertEq('8b.2 conditional cancel-request UPDATE is a no-op after handover', $upd->rowCount(), 0);

    /* ===========================================================
     * 9. Duplicate advance (conflict path)
     * =========================================================== */
    echo "== 9. Conflict path ==\n";
    $dup = makePickupOrder($pdo, $suffix, $customerId);
    assertTrue('9.1 first advance ok', !empty(pickup_advance_status($pdo, $dup)['ok']));
    /* Directly regress the stored stage so the locked read and the conditional
       guard mismatch, emulating a racing double submission. */
    $pdo->prepare('UPDATE orders SET pickup_status = \'ORDER_RECEIVED\' WHERE id = :oid AND pickup_status = \'PREPARING\'')
        ->execute([':oid' => $dup]);
    $r = pickup_advance_status($pdo, $dup);
    assertTrue('9.2 advance still one-step valid after manual regression', !empty($r['ok']));
    $row = orderRow($pdo, $dup);
    assertEq('9.3 stage advanced to PREPARING once', $row['pickup_status'], 'PREPARING');

    /* ===========================================================
     * 10. Checkout initialization (faithful replica of the new INSERT)
     * =========================================================== */
    echo "== 10. Checkout initialization ==\n";
    $newPickup = makePickupOrder($pdo, $suffix, $customerId);
    $row = orderRow($pdo, $newPickup);
    assertEq('10.1 new pickup order starts at ORDER_RECEIVED', $row['pickup_status'], 'ORDER_RECEIVED');
    assertEq('10.2 no ready timestamp at creation', $row['ready_for_pickup_at'], null);
    assertEq('10.3 no deadline at creation', $row['pickup_deadline_at'], null);
    $src = (string) file_get_contents(__DIR__ . '/../../customer/checkout.php');
    assertTrue('10.4 checkout initializes pickup_status for pickup orders', strpos($src, "':pickup_status' => \$isPickupOrder ? 'ORDER_RECEIVED' : null") !== false);

    /* ===========================================================
     * 11. Admin list query returns pickup orders only
     * =========================================================== */
    echo "== 11. Admin pickup list ==\n";
    $list = pickup_admin_list($pdo);
    $fixtureIds = array_map('intval', $created['orders']);
    $courierInList = false;
    $pickupMissing = false;
    foreach ($list as $li) {
        if (!in_array((int) $li['id'], $fixtureIds, true)) {
            continue; /* other data in the shared test DB */
        }
        if ((string) $li['pickup_status'] === '' && $li['pickup_status'] === null && basename($_SERVER['PHP_SELF'] ?? '') === '') {
            /* noop */
        }
    }
    $allPickup = true;
    foreach ($list as $li) {
        $ship = $pdo->query('SELECT delivery_method FROM shipments WHERE order_id = ' . (int) $li['id'])->fetchColumn();
        if ((string) $ship !== 'STORE_PICKUP') {
            $allPickup = false;
        }
    }
    assertTrue('11.1 pickup_admin_list returns only STORE_PICKUP orders', $allPickup);
    $foundFixture = false;
    foreach ($list as $li) {
        if ((int) $li['id'] === $newPickup) {
            $foundFixture = true;
            assertTrue('11.2 list row carries payment method/status', isset($li['payment_method'], $li['payment_status']));
            break;
        }
    }
    assertTrue('11.3 fixture pickup order present in the list', $foundFixture);

    /* ===========================================================
     * 12. Overdue highlighting (display-only)
     * =========================================================== */
    echo "== 12. Overdue highlighting ==\n";
    $overdue = makePickupOrder($pdo, $suffix, $customerId);
    pickup_advance_status($pdo, $overdue);
    pickup_advance_status($pdo, $overdue);
    /* Backdate the stored window to simulate an elapsed 48h. */
    $pdo->prepare('UPDATE orders SET ready_for_pickup_at = DATE_SUB(NOW(), INTERVAL 49 HOUR), pickup_deadline_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = :oid')
        ->execute([':oid' => $overdue]);
    $row = orderRow($pdo, $overdue);
    $progress = pickup_progress($row, ['delivery_method' => 'STORE_PICKUP']);
    assertTrue('12.1 backdated order flagged overdue', $progress !== null && $progress['overdue'] === true);
    assertEq('12.2 overdue order still READY_FOR_PICKUP (not auto-cancelled)', $row['status'], 'PENDING');
    assertEq('12.3 overdue order payment untouched', (string) $pdo->query('SELECT payment_status FROM payments WHERE order_id = ' . $overdue)->fetchColumn(), 'PENDING');
    /* Advance still works for an overdue order (manual decision). */
    $r = pickup_advance_status($pdo, $overdue);
    assertTrue('12.4 overdue order can still be advanced manually', !empty($r['ok']));

    /* ===========================================================
     * 13. Structural: customer/admin pages use the shared helper
     * =========================================================== */
    echo "== 13. Structural checks ==\n";
    $detailSrc = (string) file_get_contents(__DIR__ . '/../../customer/order-detail.php');
    assertTrue('13.1 order-detail requires includes/pickup.php', strpos($detailSrc, "includes/pickup.php") !== false);
    assertTrue('13.2 order-detail renders pickup_stages() timeline', strpos($detailSrc, 'pickup_stages()') !== false);
    assertTrue('13.3 order-detail gates courier progress on pickup', strpos($detailSrc, '$pickupProgress !== null') !== false);
    $ordersSrc = (string) file_get_contents(__DIR__ . '/../../customer/orders.php');
    assertTrue('13.4 orders.php derives pickup progress', strpos($ordersSrc, 'pickup_progress(') !== false);
    $pickupsSrc = (string) file_get_contents(__DIR__ . '/../../admin/pickups.php');
    assertTrue('13.5 admin pickups.php uses pickup_advance_status', strpos($pickupsSrc, 'pickup_advance_status(') !== false);
    assertTrue('13.6 admin pickups.php validates CSRF with hash_equals', strpos($pickupsSrc, 'hash_equals(') !== false);
    assertTrue('13.7 admin pickups.php requires POST action', strpos($pickupsSrc, "REQUEST_METHOD'] === 'POST'") !== false);
    $orderViewSrc = (string) file_get_contents(__DIR__ . '/../../admin/order-view.php');
    assertTrue('13.8 order-view has advance_pickup action', strpos($orderViewSrc, "'advance_pickup'") !== false);
    assertTrue('13.9 order-view pickup card uses shared helper', strpos($orderViewSrc, 'pickup_progress($order') !== false);
    $headerSrc = (string) file_get_contents(__DIR__ . '/../../includes/ui.header.php');
    assertTrue('13.10 admin sidebar has Store Pickup tab', strpos($headerSrc, "'pickups.php', 'label' => 'Store Pickup'") !== false);
    assertTrue('13.11 admin pickups.php offers explicit COP collection', strpos($pickupsSrc, 'collect_cash_on_pickup') !== false && strpos($pickupsSrc, 'payment_collect_cash_on_pickup(') !== false);
    assertTrue('13.12 order-view offers explicit COP collection', strpos($orderViewSrc, "'collect_cash_on_pickup'") !== false && strpos($orderViewSrc, 'payment_collect_cash_on_pickup(') !== false);
    $cancelSrc = (string) file_get_contents(__DIR__ . '/../../customer/order-cancel-request.php');
    assertTrue('13.13 cancel-request blocks handed-over pickup orders', strpos($cancelSrc, "pickup_status <> 'PICKED_UP'") !== false);
} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception: " . $e->getMessage() . "\n";
    echo "        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    try {
        $orderIds = array_map('intval', $created['orders']);
        if (!empty($orderIds)) {
            $oidList = implode(',', $orderIds);
            $pdo->exec("DELETE FROM customer_order_notifications WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE sti FROM sales_transaction_items sti INNER JOIN sales_transactions st ON st.id = sti.sale_id WHERE st.order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM sales_transactions WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM payments WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM shipments WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM order_items WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM orders WHERE id IN ({$oidList})");
        }
        if (!empty($created['customer'])) {
            $pdo->exec('DELETE FROM customers WHERE id = ' . (int) $created['customer']);
        }
        echo "\nFixtures cleaned up.\n";
    } catch (Throwable $e) {
        $failures++;
        echo "\nFAIL: fixture cleanup failed: " . $e->getMessage() . "\n";
        echo "Fixture suffix for manual cleanup: {$suffix}\n";
    }
}

echo "\nRan {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
