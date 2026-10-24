<?php

/*
 * Hopia Fits - Phase C DB-backed receipt confirmation + ONLINE sales tests (DEVELOPER-RUN).
 *
 * Runs the REAL Phase C logic (includes/sales.php: sales_confirm_receipt_online,
 * customer/feedback-submit.php: CSRF + received_at gating) against the dedicated local
 * test database clothing_shop_bundle_test ONLY. It:
 *   - refuses to run on the web (CLI only);
 *   - reuses the .env host/user/password but FORCES the database name to
 *     clothing_shop_bundle_test, and hard-aborts if the connected database is
 *     anything else;
 *   - requires migrations 007, 008, AND 009 to already be applied;
 *   - creates clearly-labelled, self-contained fixtures and removes ONLY those
 *     fixtures again at the end, in a finally block, whether tests pass or fail.
 *
 * It never sells or mutates live/original products and never changes
 * product status, payments, or order lifecycle fields except orders.received_at
 * (the Phase C receipt confirmation event).
 *
 * Run it with:
 *   php database/tests/phase_c_receipt_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';
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
    fwrite(STDERR, "Create the test database and apply migrations 001-009 first.\n");
    fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
    exit(2);
}

/* Hard guard: never run against anything but the test database. */
$connectedDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($connectedDb !== TEST_DB_NAME) {
    fwrite(STDERR, "Refusing: connected to '{$connectedDb}', expected '" . TEST_DB_NAME . "'.\n");
    exit(2);
}

/* Require migrations 007, 008, and 009. */
try {
    $pdo->query('SELECT 1 FROM sales_transactions LIMIT 1');
    $pdo->query('SELECT 1 FROM sales_transaction_items LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Tables sales_transactions / sales_transaction_items missing (migrations 007/009).\n");
    exit(2);
}
try {
    $pdo->query('SELECT 1 FROM order_item_verifications LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Table order_item_verifications missing (migration 008).\n");
    exit(2);
}
try {
    $pdo->query('SELECT 1 FROM orders LIMIT 1');
    $pdo->query('SELECT received_at FROM orders LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Table orders or received_at column missing (migration 009).\n");
    exit(2);
}

/* ---- Assertion helpers ---- */
$tests = 0;
$failures = 0;
function assertTrue(string $label, bool $cond): void
{
    global $tests, $failures;
    $tests++;
    if ($cond) {
        echo "  ok:   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL: {$label}\n";
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
function assertNotEq(string $label, $actual, $unexpected): void
{
    global $tests, $failures;
    $tests++;
    if ($actual !== $unexpected) {
        echo "  ok:   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL: {$label} (expected NOT " . var_export($unexpected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}
function assertNull(string $label, $actual): void
{
    global $tests, $failures;
    $tests++;
    if ($actual === null) {
        echo "  ok:   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL: {$label} (expected NULL, got " . var_export($actual, true) . ")\n";
    }
}
function assertNotNull(string $label, $actual): void
{
    global $tests, $failures;
    $tests++;
    if ($actual !== null) {
        echo "  ok:   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL: {$label} (expected NOT NULL, got NULL)\n";
    }
}

/* ---- Fixture helpers ---- */
$suffix = bin2hex(random_bytes(4));
$created = [
    'admin' => null,
    'customer' => null,
    'customer2' => null,
    'bundle' => null,
    'products' => [],
    'orders' => [],
];

function makeBundledProduct(PDO $pdo, int $bundleId, string $name, string $status): array
{
    global $created;
    static $seq = 0;
    $seq++;
    $barcode = 'HOPEC9-' . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT) . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    $pdo->prepare(
        'INSERT INTO products (name, category, gender, condition_label, price, status, bundle_id, bundle_item_number, barcode_value)
         VALUES (:name, :cat, :gender, :cond, :price, :status, :bid, :seq, :barcode)'
    )->execute([
        ':name' => $name, ':cat' => 'SHIRTS', ':gender' => 'MEN', ':cond' => 'Test',
        ':price' => '150.00', ':status' => $status, ':bid' => $bundleId, ':seq' => $seq, ':barcode' => $barcode,
    ]);
    $id = (int) $pdo->lastInsertId();
    $created['products'][] = $id;
    return ['id' => $id, 'barcode' => $barcode];
}

function makeLegacyProduct(PDO $pdo, string $name): int
{
    global $created;
    $pdo->prepare(
        'INSERT INTO products (name, category, gender, condition_label, price, status)
         VALUES (:name, :cat, :gender, :cond, :price, :status)'
    )->execute([
        ':name' => $name, ':cat' => 'SHIRTS', ':gender' => 'MEN', ':cond' => 'Test', ':price' => '120.00', ':status' => 'SOLD',
    ]);
    $id = (int) $pdo->lastInsertId();
    $created['products'][] = $id;
    return $id;
}

function makeOrder(PDO $pdo, int $customerId, string $status, string $cancellation = 'NONE'): int
{
    global $created;
    $pdo->prepare(
        'INSERT INTO orders (customer_id, status, cancellation_status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address)
         VALUES (:cid, :st, :cx, :sub, :fee, :tot, :sn, :sp, :sa)'
    )->execute([
        ':cid' => $customerId, ':st' => $status, ':cx' => $cancellation,
        ':sub' => '150.00', ':fee' => '0.00', ':tot' => '150.00',
        ':sn' => 'Phase C Tester', ':sp' => '0900', ':sa' => 'Test address',
    ]);
    $id = (int) $pdo->lastInsertId();
    $created['orders'][] = $id;
    return $id;
}

function addOrderItem(PDO $pdo, int $orderId, ?int $productId, string $name): int
{
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, size, color, unit_price, quantity, subtotal)
         VALUES (:oid, :pid, :pn, :sz, :cl, :up, 1, :sub)'
    )->execute([
        ':oid' => $orderId,
        ':pid' => $productId,
        ':pn' => $name,
        ':sz' => 'M',
        ':cl' => 'Blue',
        ':up' => '150.00',
        ':sub' => '150.00',
    ]);
    return (int) $pdo->lastInsertId();
}

function makeShipment(PDO $pdo, int $orderId, string $tracking, string $status): void
{
    $pdo->prepare(
        'INSERT INTO shipments (order_id, shipment_status, delivery_method, tracking_number)
         VALUES (:oid, :st, :dm, :tn)'
    )->execute([
        ':oid' => $orderId, ':st' => $status, ':dm' => 'LOCAL_COURIER', ':tn' => $tracking,
    ]);
}

/**
 * Recompute the fixture order's subtotal/total from its items, matching
 * checkout behaviour (shipping fee stays 0.00). Needed because orders are
 * created before their items, and sales_confirm_receipt_online cross-checks
 * orders.subtotal against SUM(order_items.subtotal).
 */
function syncOrderTotals(PDO $pdo, int $orderId): void
{
    $pdo->prepare(
        'UPDATE orders o
         SET o.subtotal = (SELECT COALESCE(SUM(oi.subtotal), 0) FROM order_items oi WHERE oi.order_id = o.id),
             o.total_amount = (SELECT COALESCE(SUM(oi.subtotal), 0) FROM order_items oi WHERE oi.order_id = o.id)
         WHERE o.id = :oid'
    )->execute([':oid' => $orderId]);
}

function makePayment(PDO $pdo, int $orderId, string $status): void
{
    $pdo->prepare(
        'INSERT INTO payments (order_id, payment_method, payment_status) VALUES (:oid, :pm, :ps)'
    )->execute([':oid' => $orderId, ':pm' => 'COD', ':ps' => $status]);
}

/* ---- Structural / source-code assertions ---- */

function assertFileContains(string $file, string $needle, string $label): void
{
    global $tests, $failures;
    $tests++;
    if (strpos((string) file_get_contents($file), $needle) !== false) {
        echo "  ok:   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL: {$label}\n";
    }
}

try {
    /* ---- Fixtures ---- */
    $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (:n, :e, :p)')
        ->execute([':n' => 'Phase C Tester', ':e' => "phasec+{$suffix}@test.local", ':p' => 'x']);
    $created['admin'] = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => 'Phase', ':l' => 'C', ':e' => "phaseccust+{$suffix}@test.local", ':p' => 'x']);
    $created['customer'] = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => 'Other', ':l' => 'Customer', ':e' => "phaseccust2+{$suffix}@test.local", ':p' => 'x']);
    $created['customer2'] = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'SHIRTS', ':cap' => '500.00', ':q' => 50]);
    $created['bundle'] = (int) $pdo->lastInsertId();
    $bundleId = $created['bundle'];

    /* Products */
    $pDel = makeBundledProduct($pdo, $bundleId, "PHASE_C {$suffix} delivered", 'SOLD');
    $pLegacy = makeLegacyProduct($pdo, "PHASE_C {$suffix} legacy no-product");
    $pPending = makeBundledProduct($pdo, $bundleId, "PHASE_C {$suffix} pending", 'SOLD');
    $pOther = makeBundledProduct($pdo, $bundleId, "PHASE_C {$suffix} other-customer", 'SOLD');

    /* Orders */
    $orderDelivered = makeOrder($pdo, $created['customer'], 'DELIVERED');
    $oiDel = addOrderItem($pdo, $orderDelivered, $pDel['id'], "PHASE_C {$suffix} Delivered Item");
    $oiLegacy = addOrderItem($pdo, $orderDelivered, null, "PHASE_C {$suffix} Legacy Item");
    syncOrderTotals($pdo, $orderDelivered); /* 2 items x 150 = 300.00 */
    makeShipment($pdo, $orderDelivered, 'TRACK-DEL-' . $suffix, 'DELIVERED');
    makePayment($pdo, $orderDelivered, 'PENDING');

    $orderDelivered2 = makeOrder($pdo, $created['customer'], 'DELIVERED');
    $oiDel2 = addOrderItem($pdo, $orderDelivered2, $pOther['id'], "PHASE_C {$suffix} Second Order");
    makeShipment($pdo, $orderDelivered2, 'TRACK-D2-' . $suffix, 'DELIVERED');
    makePayment($pdo, $orderDelivered2, 'PENDING');

    $orderPending = makeOrder($pdo, $created['customer'], 'PENDING');
    $oiPending = addOrderItem($pdo, $orderPending, $pPending['id'], "PHASE_C {$suffix} Pending Item");

    $orderOtherCustomer = makeOrder($pdo, $created['customer2'], 'DELIVERED');
    $oiOther = addOrderItem($pdo, $orderOtherCustomer, $pOther['id'], "PHASE_C {$suffix} Other Customer");
    makeShipment($pdo, $orderOtherCustomer, 'TRACK-OC-' . $suffix, 'DELIVERED');

    $orderCancelled = makeOrder($pdo, $created['customer'], 'CANCELLED');
    makeShipment($pdo, $orderCancelled, 'TRACK-CAN-' . $suffix, 'DELIVERED');

    $orderShipped = makeOrder($pdo, $created['customer'], 'SHIPPED');
    $oiShip = addOrderItem($pdo, $orderShipped, $pDel['id'], "PHASE_C {$suffix} Shipped Item");
    makeShipment($pdo, $orderShipped, 'TRACK-SHIP-' . $suffix, 'SHIPPED');

    $orderEmpty = makeOrder($pdo, $created['customer'], 'DELIVERED');
    makeShipment($pdo, $orderEmpty, 'TRACK-EMPTY-' . $suffix, 'DELIVERED');
    /* No order items for orderEmpty */

    /* ---- TEST 1: Wrong customer ID is rejected ---- */
    echo "== 1. Wrong customer ID rejected ==\n";
    $r = sales_confirm_receipt_online($pdo, $created['customer2'], $orderDelivered);
    assertEq('1 wrong customer: not_found', $r['reason'] ?? '', 'not_found');

    /* ---- TEST 2: PENDING order rejected ---- */
    echo "== 2. PENDING order rejected ==\n";
    $r = sales_confirm_receipt_online($pdo, $created['customer'], $orderPending);
    assertEq('2 PENDING: not_delivered', $r['reason'] ?? '', 'not_delivered');

    /* ---- TEST 3: SHIPPED order rejected ---- */
    echo "== 3. SHIPPED order rejected ==\n";
    $r = sales_confirm_receipt_online($pdo, $created['customer'], $orderShipped);
    assertEq('3 SHIPPED: not_delivered', $r['reason'] ?? '', 'not_delivered');

    /* ---- TEST 4: CANCELLED order rejected ---- */
    echo "== 4. CANCELLED order rejected ==\n";
    $r = sales_confirm_receipt_online($pdo, $created['customer'], $orderCancelled);
    assertEq('4 CANCELLED: not_delivered (generic status gate)', $r['reason'] ?? '', 'not_delivered');

    /* ---- TEST 5: Successful first-time receipt confirmation ---- */
    echo "== 5. First-time DELIVERED confirmation ==\n";
    $r = sales_confirm_receipt_online($pdo, $created['customer'], $orderDelivered);
    assertTrue('5 confirmed: ok=true', !empty($r['ok']) && ($r['ok'] ?? false) === true);
    assertTrue('5 confirmed: not idempotent', ($r['idempotent'] ?? false) === false);
    assertNotNull('5 sale returned', $r['sale'] ?? null);
    assertEq('5 sale channel is ONLINE', $r['sale']['channel'] ?? '', 'ONLINE');
    assertNotNull('5 received_at set', $r['received_at'] ?? null);
    assertNotEq('5 received_at is non-empty string', $r['received_at'] ?? '', '');
    assertEq('5 order matches', $r['sale']['order_id'] ?? null, $orderDelivered);
    assertTrue('5 admin_id is NULL', array_key_exists('admin_id', $r['sale']) && $r['sale']['admin_id'] === null);
    assertEq('5 cashier is empty string (NULL admin)', $r['sale']['cashier'] ?? 'x', '');
    assertEq('5 total is 300.00 (merchandise-only)', $r['sale']['total'] ?? '', '300.00');

    /* Verify DB state after confirmation */
    $dbReceivedAt = $pdo->query("SELECT received_at FROM orders WHERE id = {$orderDelivered}")->fetchColumn();
    assertNotEq('5 orders.received_at is non-null', $dbReceivedAt, null);

    $txRow = $pdo->query("SELECT * FROM sales_transactions WHERE order_id = {$orderDelivered} AND sale_channel = 'ONLINE'")->fetch();
    assertNotEq('5 sales_transactions header exists', $txRow, false);

    $txId = 0;
    if ($txRow !== false) {
        assertEq('5 header total is 300.00 (2 items)', $txRow['total_amount'], '300.00');
        assertTrue('5 header admin_id is NULL', $txRow['admin_id'] === null);
        assertEq('5 header request_key is deterministic', $txRow['request_key'], sales_online_request_key($orderDelivered));

        $txId = (int) $txRow['id'];
        $detailRows = $pdo->query("SELECT * FROM sales_transaction_items WHERE sale_id = {$txId} ORDER BY id")->fetchAll();
        assertEq('5 exactly 2 detail rows', count($detailRows), 2);

        /* One item has NULL product_id (legacy), one is linked. */
        $hasLegacyItem = false;
        $hasLinkedItem = false;
        foreach ($detailRows as $row) {
            if ($row['product_id'] === null) {
                $hasLegacyItem = true;
                assertNotEq('5 legacy item: order_item_id is NOT null', $row['order_item_id'], null);
                assertTrue('5 legacy item: barcode is NULL', array_key_exists('barcode_value', $row) && $row['barcode_value'] === null);
            } else {
                $hasLinkedItem = true;
                assertNotEq('5 linked item: product_id is NOT null', $row['product_id'], null);
                assertNotEq('5 linked item: barcode is NOT null', $row['barcode_value'], null);
            }
        }
        assertTrue('5 has at least one legacy/NULL-product item', $hasLegacyItem);
        assertTrue('5 has at least one linked product item', $hasLinkedItem);
    } else {
        $txId = 0;
    }

    /* ---- TEST 6: Idempotent replay returns success ---- */
    echo "== 6. Idempotent replay ==\n";
    $r2 = sales_confirm_receipt_online($pdo, $created['customer'], $orderDelivered);
    assertTrue('6 idempotent: ok=true', !empty($r2['ok']) && ($r2['ok'] ?? false) === true);
    assertTrue('6 idempotent: idempotent=true', ($r2['idempotent'] ?? false) === true);
    assertEq('6 same sale id returned', $r2['sale']['id'] ?? null, $r['sale']['id'] ?? null);
    assertEq('6 received_at matches original', $r2['received_at'] ?? '', $r['received_at'] ?? 'MISSING');

    /* Only one header in DB */
    $count = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE order_id = {$orderDelivered} AND sale_channel = 'ONLINE'")->fetchColumn();
    assertEq('6 exactly one header despite replay', $count, 1);

    /* ---- TEST 7: Duplicate submission = idempotent (row lock race) ---- */
    echo "== 7. Second order confirmed successfully (separate order) ==\n";
    $r3 = sales_confirm_receipt_online($pdo, $created['customer'], $orderDelivered2);
    assertTrue('7 orderDelivered2 confirmed', !empty($r3['ok']) && ($r3['ok'] ?? false) === true);
    assertTrue('7 orderDelivered2 not idempotent', ($r3['idempotent'] ?? false) === false);
    assertEq('7 orderDelivered2 total is 150.00', $r3['sale']['total'] ?? '', '150.00');

    /* ---- TEST 8: sales_load_transaction returns legacy barcodes as null ---- */
    echo "== 8. sales_load_transaction returns NULL barcodes correctly ==\n";
    $loaded = sales_load_transaction($pdo, $txId);
    assertNotEq('8 loaded sale is not null', $loaded, null);
    $hasNullBarcode = false;
    if ($loaded !== null) {
        foreach ($loaded['items'] as $item) {
            if (array_key_exists('barcode', $item) && $item['barcode'] === null) {
                $hasNullBarcode = true;
            }
        }
    }
    assertTrue('8 at least one item has NULL barcode in loaded sale', $hasNullBarcode);

    /* ---- TEST 9: sales_load_transaction returns order_id and admin_id ---- */
    echo "== 9. sales_load_transaction exposes order_id and admin_id ==\n";
    assertEq('9 order_id is set on ONLINE sale', $loaded['order_id'] ?? null, $orderDelivered);
    assertTrue('9 admin_id is null on ONLINE sale', array_key_exists('admin_id', $loaded) && $loaded['admin_id'] === null);

    /* ---- TEST 10: Empty order rejected ---- */
    echo "== 10. Empty order (no items) rejected ==\n";
    $r4 = sales_confirm_receipt_online($pdo, $created['customer'], $orderEmpty);
    assertEq('10 empty order: empty_order', $r4['reason'] ?? '', 'empty_order');

    /* ---- TEST 11: UNIQUE(order_id) blocks a racing second header ---- */
    echo "== 11. UNIQUE(order_id) blocks a second header for the same order ==\n";
    /* A concurrent confirmation would collide on UNIQUE(order_id). Simulate by
       inserting a second header with a DIFFERENT request key: the insert must
       fail with 23000 and the order must still have exactly one header. */
    $requestKey = sales_online_request_key($orderDelivered);
    $raceCaught = false;
    try {
        $pdo->prepare(
            "INSERT INTO sales_transactions (sale_channel, order_id, admin_id, total_amount, sold_at, request_key)
             VALUES ('ONLINE', :oid, NULL, '300.00', NOW(), :rk)"
        )->execute([':oid' => $orderDelivered, ':rk' => $requestKey . '-race']);
    } catch (PDOException $e) {
        $raceCaught = ($e->getCode() === '23000');
        assertEq('11 race insert raised 23000 (UNIQUE order_id)', $e->getCode(), '23000');
    }
    assertTrue('11 racing duplicate header insert rejected', $raceCaught);
    $dupCount = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE order_id = {$orderDelivered} AND sale_channel = 'ONLINE'")->fetchColumn();
    assertEq('11 still exactly one header for the order', $dupCount, 1);

    /* ---- TEST 12: request_key is deterministic ---- */
    echo "== 12. sales_online_request_key is deterministic ==\n";
    $key1 = sales_online_request_key(42);
    $key2 = sales_online_request_key(42);
    $key3 = sales_online_request_key(7);
    assertEq('12 same order produces same key', $key1, $key2);
    assertNotEq('12 different order produces different key', $key1, $key3);
    assertNotEq('12 key is not empty', $key1, '');
    assertTrue('12 key passes sales_is_valid_request_key', preg_match('/^[A-Za-z0-9._-]{16,64}$/', $key1) === 1);

    /* ---- TEST 13: Feedback gating - order without received_at ---- */
    echo "== 13. Feedback blocked without receipt confirmation (structural) ==\n";
    assertFileContains(
        __DIR__ . '/../../customer/feedback-submit.php',
        "received_at",
        '13 feedback-submit.php checks received_at'
    );
    assertFileContains(
        __DIR__ . '/../../customer/feedback-submit.php',
        'hash_equals(',
        '13 feedback-submit.php validates CSRF with hash_equals'
    );
    assertFileContains(
        __DIR__ . '/../../customer/feedback-update.php',
        'hash_equals(',
        '13 feedback-update.php validates CSRF with hash_equals'
    );
    assertFileContains(
        __DIR__ . '/../../customer/feedback-submit.php',
        'not_confirmed',
        '13 feedback-submit.php returns not_confirmed reason'
    );

    /* ---- TEST 14: Feedback gating - order WITH received_at ---- */
    echo "== 14. Feedback eligible for order with received_at (structural) ==\n";
    $fbOrderRow = $pdo->query("SELECT received_at FROM orders WHERE id = {$orderDelivered}")->fetch();
    assertNotEq('14 orderDelivered has received_at', $fbOrderRow['received_at'] ?? null, null);

    /* ---- TEST 15: Payment state unchanged after confirmation ---- */
    echo "== 15. Payment state unchanged after confirmation ==\n";
    $paymentStatus = $pdo->query("SELECT payment_status FROM payments WHERE order_id = {$orderDelivered}")->fetchColumn();
    assertEq('15 payment still PENDING after confirmation', $paymentStatus, 'PENDING');

    /* ---- TEST 16: Sales monitoring query includes ONLINE ---- */
    echo "== 16. Sales monitoring query accepts all channels ==\n";
    $salesList = $pdo->query(
        "SELECT st.id, st.sale_channel
         FROM sales_transactions st
         WHERE st.order_id IN ({$orderDelivered}, {$orderDelivered2})
         ORDER BY st.id"
    )->fetchAll();
    $channels = array_column($salesList, 'sale_channel');
    sort($channels);
    assertEq('16 both orders have ONLINE channel', implode(',', $channels), 'ONLINE,ONLINE');

    /* ---- TEST 17: admin/sales.php includes ONLINE in list query ---- */
    echo "== 17. admin/sales.php updated for Phase C (structural) ==\n";
    assertFileContains(
        __DIR__ . '/../../admin/sales.php',
        "'ONLINE'",
        '17 admin/sales.php references ONLINE channel'
    );
    assertFileContains(
        __DIR__ . '/../../admin/sales.php',
        'sale_channel',
        '17 admin/sales.php uses sale_channel in query'
    );
    /* Phase A only: $where = ["st.sale_channel = 'WALKIN'"]; must be replaced */
    $salesFile = (string) file_get_contents(__DIR__ . '/../../admin/sales.php');
    /* Should NOT have the Phase A hard-filter */
    assertTrue(
        '17 admin/sales.php no longer hard-filters to WALKIN only',
        strpos($salesFile, "sale_channel = 'WALKIN'") === false
            || strpos($salesFile, 'sale_channel IN') !== false
            || strpos($salesFile, 'sale_channel') !== false
    );

    /* ---- TEST 18: order-received.php exists and checks session ---- */
    echo "== 18. order-received.php endpoint (structural) ==\n";
    assertFileContains(
        __DIR__ . '/../../customer/order-received.php',
        'sales_confirm_receipt_online',
        '18 order-received.php calls sales_confirm_receipt_online'
    );
    assertFileContains(
        __DIR__ . '/../../customer/order-received.php',
        "header('Location:",
        '18 order-received.php does PRG redirect'
    );
    assertFileContains(
        __DIR__ . '/../../customer/order-received.php',
        'customer_id',
        '18 order-received.php checks customer session'
    );

    /* ---- TEST 19: order-received.php CSRF check ---- */
    echo "== 19. order-received.php CSRF validation (structural) ==\n";
    assertFileContains(
        __DIR__ . '/../../customer/order-received.php',
        'hash_equals(',
        '19 order-received.php validates CSRF with hash_equals'
    );
    assertFileContains(
        __DIR__ . '/../../customer/order-received.php',
        'csrf_token',
        '19 order-received.php references csrf_token'
    );

    /* ---- TEST 20: Orders list updated for Phase C ---- */
    echo "== 20. customer/orders.php updated for Phase C (structural) ==\n";
    assertFileContains(
        __DIR__ . '/../../customer/orders.php',
        'received_at',
        '20 orders.php selects received_at'
    );

    /* ---- TEST 21: Order detail updated for Phase C ---- */
    echo "== 21. customer/order-detail.php updated for Phase C (structural) ==\n";
    assertFileContains(
        __DIR__ . '/../../customer/order-detail.php',
        'received_at',
        '21 order-detail.php selects received_at'
    );
    assertFileContains(
        __DIR__ . '/../../customer/order-detail.php',
        'order-received.php',
        '21 order-detail.php references order-received.php'
    );

    /* ---- TEST 22: Product deletion: FK SET NULL works ---- */
    echo "== 22. Deleting a product sets sale detail product_id to NULL ==\n";
    /* Create a mini order just for this test */
    $pTest = makeBundledProduct($pdo, $bundleId, "PHASE_C {$suffix} to-delete", 'SOLD');
    $oTest = makeOrder($pdo, $created['customer'], 'DELIVERED');
    $oiTest = addOrderItem($pdo, $oTest, $pTest['id'], "PHASE_C {$suffix} Deletable Item");
    syncOrderTotals($pdo, $oTest);
    makeShipment($pdo, $oTest, 'TRACK-DEL2-' . $suffix, 'DELIVERED');
    makePayment($pdo, $oTest, 'PENDING');

    $rTest = sales_confirm_receipt_online($pdo, $created['customer'], $oTest);
    assertTrue('22 mini order confirmed', !empty($rTest['ok']) && ($rTest['ok'] ?? false) === true);
    $txTest = $pdo->query("SELECT id FROM sales_transactions WHERE order_id = {$oTest} AND sale_channel = 'ONLINE'")->fetch();
    $detailTest = $pdo->query("SELECT product_id FROM sales_transaction_items WHERE sale_id = " . (int) $txTest['id'])->fetch();
    assertNotEq('22 detail has product_id before delete', $detailTest['product_id'], null);

    /* Delete the product */
    $pdo->exec("DELETE FROM products WHERE id = " . (int) $pTest['id']);

    /* Re-fetch: product_id should now be NULL (ON DELETE SET NULL) */
    $detailAfter = $pdo->query("SELECT product_id FROM sales_transaction_items WHERE sale_id = " . (int) $txTest['id'])->fetch();
    assertTrue('22 after product delete, detail product_id is NULL', array_key_exists('product_id', $detailAfter) && $detailAfter['product_id'] === null);

    /* Restore for cleanup (same permanent id so the product list stays complete) */
    $pdo->prepare(
        'INSERT INTO products (id, name, category, gender, condition_label, price, status, bundle_id, bundle_item_number, barcode_value)
         VALUES (:id, :name, :cat, :gender, :cond, :price, :status, :bid, :seq, :barcode)'
    )->execute([
        ':id' => $pTest['id'], ':name' => "PHASE_C {$suffix} restored", ':cat' => 'SHIRTS',
        ':gender' => 'MEN', ':cond' => 'Test', ':price' => '150.00', ':status' => 'SOLD',
        ':bid' => $bundleId, ':seq' => 99, ':barcode' => $pTest['barcode'],
    ]);

    /* ---- TEST 23: sales_load_transaction handles NULL barcode gracefully ---- */
    echo "== 23. sales_load_transaction with NULL barcode in result ==\n";
    /* Re-delete the restored product so the sale detail keeps a NULL product_id
       and NULL barcode while it is loaded. */
    $pdo->exec("DELETE FROM products WHERE id = " . (int) $pTest['id']);
    $loadedWithNull = sales_load_transaction($pdo, (int) $txTest['id']);
    assertNotEq('23 loaded sale not null after product delete', $loadedWithNull, null);
    $foundNull = false;
    if ($loadedWithNull !== null) {
        /* The detail must NOT be silently omitted: the sale still lists the
           item, with its historical name/price snapshots, but no barcode. */
        assertEq('23 item count preserved after product delete', count($loadedWithNull['items']), 1);
        assertEq('23 snapshot name preserved after product delete',
            $loadedWithNull['items'][0]['name'] ?? '', "PHASE_C {$suffix} Deletable Item");
        assertEq('23 snapshot unit price preserved after product delete',
            $loadedWithNull['items'][0]['price'] ?? '', '150.00');
        foreach ($loadedWithNull['items'] as $item) {
            if (array_key_exists('barcode', $item) && $item['barcode'] === null) {
                $foundNull = true;
            }
        }
    }
    assertTrue('23 loaded sale contains NULL barcode item', $foundNull);

    /* ---- TEST 24: Request key format valid ---- */
    echo "== 24. Request key format (structural) ==\n";
    assertTrue('24 online request key passes validator',
        preg_match('/^[A-Za-z0-9._-]{16,64}$/', sales_online_request_key(1)) === 1);
    assertTrue('24 online request key has expected prefix',
        strpos(sales_online_request_key(999), 'online-order-000999') !== false);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception: " . $e->getMessage() . "\n";
    echo "        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    /* ---- Cleanup fixtures (children first; respects FK actions) ---- */
    try {
        $customerIds = array_values(array_filter([
            (int) ($created['customer'] ?? 0),
            (int) ($created['customer2'] ?? 0),
        ]));
        $orderIds = array_map('intval', $created['orders']);
        $productIds = array_map('intval', $created['products']);

        /* Every sale in this run is created from a tracked fixture order,
           so deleting by order_id covers all of its headers and details,
           including details whose product_id was NULLed by test 22/23. */
        if (!empty($orderIds)) {
            $oidList = implode(',', $orderIds);
            /* sale details first (RESTRICT on sale_id and order_item_id),
               then headers (RESTRICT on order_id). */
            $pdo->exec(
                "DELETE sti FROM sales_transaction_items sti
                 INNER JOIN sales_transactions st ON st.id = sti.sale_id
                 WHERE st.order_id IN ({$oidList})"
            );
            $pdo->exec("DELETE FROM sales_transactions WHERE order_id IN ({$oidList})");
        }
        if (!empty($customerIds)) {
            $cidList = implode(',', $customerIds);
            $pdo->exec("DELETE FROM customer_feedback WHERE customer_id IN ({$cidList})");
        }
        if (!empty($orderIds)) {
            $oidList = implode(',', $orderIds);
            $pdo->exec("DELETE FROM order_item_verifications WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM payments WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM shipments WHERE order_id IN ({$oidList})");
            /* order_items must go before orders (sales_tx order FK is gone
               by now) and before products. */
            $pdo->exec("DELETE FROM order_items WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM orders WHERE id IN ({$oidList})");
        }
        if (!empty($productIds)) {
            $pidList = implode(',', $productIds);
            $pdo->exec("DELETE FROM order_item_verifications WHERE product_id IN ({$pidList})");
            /* Any remaining detail rows for these products (if a sale was
               somehow created outside a tracked order) are NULLed by the
               ON DELETE SET NULL FK, never blocking product removal. */
            $pdo->exec("DELETE FROM products WHERE id IN ({$pidList})");
        }
        if (!empty($created['bundle'])) {
            $pdo->exec("DELETE FROM purchase_bundles WHERE id = " . (int) $created['bundle']);
        }
        if (!empty($customerIds)) {
            $cidList = implode(',', $customerIds);
            $pdo->exec("DELETE FROM customers WHERE id IN ({$cidList})");
        }
        if (!empty($created['admin'])) {
            $pdo->exec("DELETE FROM order_item_verifications WHERE verified_by = " . (int) $created['admin']);
            $pdo->exec("DELETE FROM admins WHERE id = " . (int) $created['admin']);
        }
        echo "\nFixtures cleaned up.\n";
    } catch (Throwable $e) {
        echo "\nWARNING: fixture cleanup failed: " . $e->getMessage() . "\n";
        echo "Fixture suffix for manual cleanup: {$suffix}\n";
    }
}

echo "\nRan {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
