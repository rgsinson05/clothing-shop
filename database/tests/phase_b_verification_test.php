<?php

/*
 * Hopia Fits - Phase B DB-backed verification tests (DEVELOPER-RUN).
 *
 * Runs the REAL Phase B verification logic (includes/order_verify.php) against
 * the dedicated local test database clothing_shop_bundle_test ONLY. It:
 *   - refuses to run on the web (CLI only);
 *   - reuses the .env host/user/password but FORCES the database name to
 *     clothing_shop_bundle_test, and hard-aborts if the connected database is
 *     anything else;
 *   - requires migrations 007 AND 008 to already be applied;
 *   - creates clearly-labelled, self-contained fixtures (a test admin, a
 *     customer, a bundle, products, and several online orders in different
 *     states) and removes ONLY those fixtures again at the end, in a finally
 *     block, whether tests pass or fail.
 *
 * It never sells or mutates live/original products, never creates a sale, and
 * never changes product status, payments, or order lifecycle fields except the
 * single PACKED -> SHIPPED gate it exercises on its own fixture orders.
 *
 * Run it with:
 *   php database/tests/phase_b_verification_test.php
 *
 * NOTE: camera scanning, torch/zoom, and live HTTP auth/CSRF rejection are
 * browser/endpoint behaviours exercised by the thin API wrappers; the
 * underlying verification rules are tested here, and the endpoint guards are
 * additionally asserted structurally (tests 21 and 24).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/order_verify.php';

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
    fwrite(STDERR, "Create the test database and apply migrations 001-008 first.\n");
    fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
    exit(2);
}

/* Hard guard: never run against anything but the test database. */
$connectedDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($connectedDb !== TEST_DB_NAME) {
    fwrite(STDERR, "Refusing to run: connected to '{$connectedDb}', expected '" . TEST_DB_NAME . "'.\n");
    exit(2);
}

/* Require migrations 007 and 008 to be applied. */
try {
    $pdo->query('SELECT 1 FROM sales_transactions LIMIT 1');
    $pdo->query('SELECT 1 FROM sales_transaction_items LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Tables sales_transactions / sales_transaction_items are missing (migration 007).\n");
    exit(2);
}
try {
    $pdo->query('SELECT 1 FROM order_item_verifications LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Table order_item_verifications is missing.\n");
    fwrite(STDERR, "Apply database/migrations/008_add_order_item_verifications.sql to " . TEST_DB_NAME . " first.\n");
    exit(2);
}

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

/*
 * Mirror of admin/order-view.php's advance_status PACKED -> SHIPPED
 * transaction. It uses the SAME shared helper order_verify_progress() and the
 * SAME lock ordering (order row first, then shipment row) so this is a faithful
 * exercise of the single shipment gate. There is deliberately no second gate
 * anywhere else (asserted structurally in test 21).
 */
function simulateAdvancePackedToShipped(PDO $pdo, int $orderId): array
{
    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare('SELECT id, status, cancellation_status FROM orders WHERE id = :id LIMIT 1 FOR UPDATE');
        $lock->execute([':id' => $orderId]);
        $order = $lock->fetch();
        if ($order === false) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($order['cancellation_status'] === 'REQUESTED') {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'cancellation'];
        }
        if ($order['status'] !== 'PACKED') {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'bad_status'];
        }

        $sl = $pdo->prepare('SELECT tracking_number FROM shipments WHERE order_id = :id LIMIT 1 FOR UPDATE');
        $sl->execute([':id' => $orderId]);
        $shipment = $sl->fetch();
        if ($shipment === false || trim((string) ($shipment['tracking_number'] ?? '')) === '') {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'tracking'];
        }

        /* The single Phase B shipment gate, using the shared helper. */
        $progress = order_verify_progress($pdo, $orderId);
        if ($progress['expected'] === 0 || !$progress['complete']) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'unverified', 'progress' => $progress];
        }

        $adv = $pdo->prepare("UPDATE orders SET status = 'SHIPPED' WHERE id = :id AND status = 'PACKED' AND cancellation_status <> 'REQUESTED'");
        $adv->execute([':id' => $orderId]);
        if ($adv->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'update_failed'];
        }

        $su = $pdo->prepare("UPDATE shipments SET shipment_status = 'SHIPPED', shipped_at = NOW() WHERE order_id = :id");
        $su->execute([':id' => $orderId]);
        if ($su->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'shipment_update_failed'];
        }

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'reason' => 'error', 'error' => $e->getMessage()];
    }
}

$suffix = bin2hex(random_bytes(4));
$created = [
    'admin' => null,
    'customer' => null,
    'bundle' => null,
    'products' => [],
    'orders' => [],
];

$seq = 0;
function makeBundledProduct(PDO $pdo, int $bundleId, int &$seq, string $suffix, string $name, string $status): array
{
    $seq++;
    $barcode = 'HOPEVERIFY-' . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT) . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare(
        'INSERT INTO products (name, category, gender, condition_label, price, status, bundle_id, bundle_item_number, barcode_value)
         VALUES (:name, :cat, :gender, :cond, :price, :status, :bid, :seq, :barcode)'
    );
    $stmt->execute([
        ':name' => $name, ':cat' => 'SHIRTS', ':gender' => 'MEN', ':cond' => 'Test',
        ':price' => '150.00', ':status' => $status, ':bid' => $bundleId, ':seq' => $seq, ':barcode' => $barcode,
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'barcode' => $barcode];
}

function makeLegacyProduct(PDO $pdo, string $name, string $status): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO products (name, category, gender, condition_label, price, status)
         VALUES (:name, :cat, :gender, :cond, :price, :status)'
    );
    $stmt->execute([
        ':name' => $name, ':cat' => 'SHIRTS', ':gender' => 'MEN', ':cond' => 'Test', ':price' => '120.00', ':status' => $status,
    ]);
    return (int) $pdo->lastInsertId();
}

function makeOrder(PDO $pdo, int $customerId, array &$created, string $status, string $cancellation = 'NONE'): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO orders (customer_id, status, cancellation_status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address)
         VALUES (:cid, :st, :cx, :sub, :fee, :tot, :sn, :sp, :sa)'
    );
    $stmt->execute([
        ':cid' => $customerId, ':st' => $status, ':cx' => $cancellation,
        ':sub' => '150.00', ':fee' => '0.00', ':tot' => '150.00',
        ':sn' => 'Phase B Tester', ':sp' => '0900', ':sa' => 'Test address',
    ]);
    $id = (int) $pdo->lastInsertId();
    $created['orders'][] = $id;
    return $id;
}

function addOrderItem(PDO $pdo, int $orderId, ?int $productId, string $name): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, size, color, unit_price, quantity, subtotal)
         VALUES (:oid, :pid, :pn, :sz, :cl, :up, 1, :sub)'
    );
    $stmt->bindValue(':oid', $orderId, PDO::PARAM_INT);
    if ($productId === null) {
        $stmt->bindValue(':pid', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':pn', $name, PDO::PARAM_STR);
    $stmt->bindValue(':sz', 'M', PDO::PARAM_STR);
    $stmt->bindValue(':cl', 'Blue', PDO::PARAM_STR);
    $stmt->bindValue(':up', '150.00', PDO::PARAM_STR);
    $stmt->bindValue(':sub', '150.00', PDO::PARAM_STR);
    $stmt->execute();
    return (int) $pdo->lastInsertId();
}

function makeShipment(PDO $pdo, int $orderId, ?string $tracking, string $status = 'NOT_SHIPPED'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO shipments (order_id, shipment_status, delivery_method, tracking_number)
         VALUES (:oid, :st, :dm, :tn)'
    );
    $stmt->bindValue(':oid', $orderId, PDO::PARAM_INT);
    $stmt->bindValue(':st', $status, PDO::PARAM_STR);
    $stmt->bindValue(':dm', 'LOCAL_COURIER', PDO::PARAM_STR);
    if ($tracking === null) {
        $stmt->bindValue(':tn', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':tn', $tracking, PDO::PARAM_STR);
    }
    $stmt->execute();
}

function makePayment(PDO $pdo, int $orderId, string $status = 'PENDING'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO payments (order_id, payment_method, payment_status) VALUES (:oid, :pm, :ps)'
    );
    $stmt->execute([':oid' => $orderId, ':pm' => 'COD', ':ps' => $status]);
}

try {
    /* ---- Fixtures ---- */
    $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (:n, :e, :p)')
        ->execute([':n' => 'Phase B Tester', ':e' => "phaseb+{$suffix}@test.local", ':p' => 'x']);
    $created['admin'] = (int) $pdo->lastInsertId();
    $adminId = $created['admin'];

    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => 'Phase', ':l' => 'B', ':e' => "phasebcust+{$suffix}@test.local", ':p' => 'x']);
    $created['customer'] = (int) $pdo->lastInsertId();
    $customerId = $created['customer'];

    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'SHIRTS', ':cap' => '500.00', ':q' => 50]);
    $created['bundle'] = (int) $pdo->lastInsertId();
    $bundleId = $created['bundle'];

    /* Products. Ordered garments are SOLD (online hold) to prove Phase B does
       NOT require AVAILABLE and does NOT reuse the Phase A predicate. */
    $pA1 = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} A1", 'SOLD');
    $pA2 = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} A2", 'SOLD');
    $pA3 = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} A3 other-order", 'SOLD');
    $pA4 = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} A4 unexpected", 'AVAILABLE');
    $pLegacy = makeLegacyProduct($pdo, "PHASE_B {$suffix} legacy no-barcode", 'SOLD');
    $pPa1 = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} Pa1", 'SOLD');
    $pPa2 = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} Pa2", 'SOLD');
    $pPb1 = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} Pb1", 'SOLD');
    $pPb2 = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} Pb2", 'SOLD');
    $pPend = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} pending", 'SOLD');
    $pShip = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} shipped", 'SOLD');
    $pDel = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} delivered", 'SOLD');
    $pCanc = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} cancelled", 'AVAILABLE');
    $pCancReq = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} cancel-req", 'SOLD');
    $pCancApp = makeBundledProduct($pdo, $bundleId, $seq, $suffix, "PHASE_B {$suffix} cancel-app", 'SOLD');
    $created['products'] = [
        $pA1['id'], $pA2['id'], $pA3['id'], $pA4['id'], $pLegacy,
        $pPa1['id'], $pPa2['id'], $pPb1['id'], $pPb2['id'],
        $pPend['id'], $pShip['id'], $pDel['id'], $pCanc['id'], $pCancReq['id'], $pCancApp['id'],
    ];

    /* Orders. */
    $orderConfirmed = makeOrder($pdo, $customerId, $created, 'CONFIRMED');
    $oiA1 = addOrderItem($pdo, $orderConfirmed, $pA1['id'], "PHASE_B {$suffix} A1");
    $oiA2 = addOrderItem($pdo, $orderConfirmed, $pA2['id'], "PHASE_B {$suffix} A2");
    $oiNull = addOrderItem($pdo, $orderConfirmed, null, "PHASE_B {$suffix} null-product");
    $oiLegacy = addOrderItem($pdo, $orderConfirmed, $pLegacy, "PHASE_B {$suffix} legacy");

    $orderOther = makeOrder($pdo, $customerId, $created, 'CONFIRMED');
    $oiOther = addOrderItem($pdo, $orderOther, $pA3['id'], "PHASE_B {$suffix} A3");

    $orderPackedA = makeOrder($pdo, $customerId, $created, 'PACKED');
    $oiPa1 = addOrderItem($pdo, $orderPackedA, $pPa1['id'], "PHASE_B {$suffix} Pa1");
    $oiPa2 = addOrderItem($pdo, $orderPackedA, $pPa2['id'], "PHASE_B {$suffix} Pa2");
    $oiPaNull = addOrderItem($pdo, $orderPackedA, null, "PHASE_B {$suffix} Pa-null");
    makeShipment($pdo, $orderPackedA, 'TRACK-A-' . $suffix);
    makePayment($pdo, $orderPackedA);

    $orderPackedB = makeOrder($pdo, $customerId, $created, 'PACKED');
    $oiPb1 = addOrderItem($pdo, $orderPackedB, $pPb1['id'], "PHASE_B {$suffix} Pb1");
    $oiPb2 = addOrderItem($pdo, $orderPackedB, $pPb2['id'], "PHASE_B {$suffix} Pb2");
    $oiPbNull = addOrderItem($pdo, $orderPackedB, null, "PHASE_B {$suffix} Pb-null");
    makeShipment($pdo, $orderPackedB, null); // no tracking yet
    makePayment($pdo, $orderPackedB);

    $orderPackedEmpty = makeOrder($pdo, $customerId, $created, 'PACKED');
    makeShipment($pdo, $orderPackedEmpty, 'TRACK-E-' . $suffix);

    $orderPending = makeOrder($pdo, $customerId, $created, 'PENDING');
    $oiPend = addOrderItem($pdo, $orderPending, $pPend['id'], "PHASE_B {$suffix} pending");

    $orderShipped = makeOrder($pdo, $customerId, $created, 'SHIPPED');
    $oiShip = addOrderItem($pdo, $orderShipped, $pShip['id'], "PHASE_B {$suffix} shipped");

    $orderDelivered = makeOrder($pdo, $customerId, $created, 'DELIVERED');
    $oiDel = addOrderItem($pdo, $orderDelivered, $pDel['id'], "PHASE_B {$suffix} delivered");

    $orderCancelled = makeOrder($pdo, $customerId, $created, 'CANCELLED');
    $oiCanc = addOrderItem($pdo, $orderCancelled, $pCanc['id'], "PHASE_B {$suffix} cancelled");

    $orderCancelReq = makeOrder($pdo, $customerId, $created, 'CONFIRMED', 'REQUESTED');
    $oiCancReq = addOrderItem($pdo, $orderCancelReq, $pCancReq['id'], "PHASE_B {$suffix} cancel-req");

    $orderCancelApp = makeOrder($pdo, $customerId, $created, 'CONFIRMED', 'APPROVED');
    $oiCancApp = addOrderItem($pdo, $orderCancelApp, $pCancApp['id'], "PHASE_B {$suffix} cancel-app");

    /* Baselines for no-side-effect assertions. */
    $salesTxBefore = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    $salesItemsBefore = (int) $pdo->query('SELECT COUNT(*) FROM sales_transaction_items')->fetchColumn();
    $pA1StatusBefore = (string) $pdo->query("SELECT status FROM products WHERE id = {$pA1['id']}")->fetchColumn();
    $packedBPaymentBefore = (string) $pdo->query("SELECT payment_status FROM payments WHERE order_id = {$orderPackedB}")->fetchColumn();

    echo "== 1. Valid SCANNED verification records all fields ==\n";
    $r = order_verify_record($pdo, $adminId, $orderConfirmed, $pA1['barcode']);
    assertTrue('1 scan accepted', !empty($r['ok']) && ($r['method'] ?? '') === 'SCANNED');
    $row = $pdo->query("SELECT * FROM order_item_verifications WHERE order_item_id = {$oiA1}")->fetch();
    assertTrue('1 one row stored for the item', $row !== false);
    assertEq('1 method is SCANNED', $row['method'] ?? '', 'SCANNED');
    assertEq('1 verified_by is the admin (server-derived)', (int) ($row['verified_by'] ?? 0), $adminId);
    assertEq('1 product_id is the DB-resolved product', (int) ($row['product_id'] ?? 0), $pA1['id']);
    assertEq('1 barcode snapshot stored', $row['barcode_value'] ?? '', $pA1['barcode']);
    assertNull('1 reason is NULL for SCANNED', array_key_exists('reason', $row) ? $row['reason'] : 'missing-row');
    assertTrue('1 verified_at set by server/DB', !empty($row['verified_at']));

    echo "== 2. Barcode for an unexpected product is rejected ==\n";
    $before = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_id = {$orderConfirmed}")->fetchColumn();
    $r = order_verify_record($pdo, $adminId, $orderConfirmed, $pA4['barcode']);
    assertEq('2 reason unexpected_item', $r['reason'] ?? '', 'unexpected_item');
    $after = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_id = {$orderConfirmed}")->fetchColumn();
    assertEq('2 no verification row added', $after, $before);

    echo "== 3. Barcode belonging to another order is rejected ==\n";
    $r = order_verify_record($pdo, $adminId, $orderConfirmed, $pA3['barcode']);
    assertEq('3 reason unexpected_item', $r['reason'] ?? '', 'unexpected_item');

    echo "== 4. Unknown barcode is rejected ==\n";
    $r = order_verify_record($pdo, $adminId, $orderConfirmed, "NOPE-{$suffix}");
    assertEq('4 reason not_found', $r['reason'] ?? '', 'not_found');

    echo "== 5. product_id=NULL item stays expected and needs Manual Verify ==\n";
    $rows = order_verify_item_rows($pdo, $orderConfirmed);
    $byItem = [];
    foreach ($rows as $it) { $byItem[(int) $it['order_item_id']] = $it; }
    assertEq('5 order has 4 expected items', count($rows), 4);
    assertTrue('5 null-product item present in checklist', isset($byItem[$oiNull]));
    assertTrue('5 null-product item is NOT scannable', $byItem[$oiNull]['scannable'] === false);
    $prog = order_verify_progress($pdo, $orderConfirmed);
    assertEq('5 expected count includes the null-product item', $prog['expected'], 4);

    echo "== 6. Item whose product has no valid barcode stays expected, needs manual ==\n";
    assertTrue('6 legacy no-barcode item is NOT scannable', $byItem[$oiLegacy]['scannable'] === false);
    /* 6b: blank reason on the legacy item is rejected (test 7), then valid works below. */

    echo "== 7. Manual Verify with blank/whitespace reason is rejected ==\n";
    $r = order_verify_record_manual($pdo, $adminId, $orderConfirmed, $oiLegacy, "   ");
    assertEq('7 reason reason_required', $r['reason'] ?? '', 'reason_required');
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_item_id = {$oiLegacy}")->fetchColumn();
    assertEq('7 no row created by blank-reason manual', $cnt, 0);

    echo "== 8. Manual Verify with a valid reason records MANUAL + fields ==\n";
    $reasonText = "Legacy item, no barcode label; matched by name and photo.";
    $r = order_verify_record_manual($pdo, $adminId, $orderConfirmed, $oiNull, $reasonText);
    assertTrue('8 manual accepted', !empty($r['ok']) && ($r['method'] ?? '') === 'MANUAL');
    $row = $pdo->query("SELECT * FROM order_item_verifications WHERE order_item_id = {$oiNull}")->fetch();
    assertEq('8 method is MANUAL', $row['method'] ?? '', 'MANUAL');
    assertEq('8 verified_by is the admin', (int) ($row['verified_by'] ?? 0), $adminId);
    assertEq('8 reason stored exactly', $row['reason'] ?? '', $reasonText);
    assertTrue('8 verified_at set by server/DB', !empty($row['verified_at']));
    assertNull('8 barcode NULL for MANUAL', array_key_exists('barcode_value', $row) ? $row['barcode_value'] : 'missing-row');

    echo "== 9. A scannable item cannot use Manual Verify to bypass the scanner ==\n";
    $r = order_verify_record_manual($pdo, $adminId, $orderConfirmed, $oiA2, "trying to bypass the scan");
    assertEq('9 reason scannable_requires_scan', $r['reason'] ?? '', 'scannable_requires_scan');
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_item_id = {$oiA2}")->fetchColumn();
    assertEq('9 no manual row created for scannable item', $cnt, 0);

    echo "== 10. Duplicate SCANNED is rejected; only one row ==\n";
    $r = order_verify_record($pdo, $adminId, $orderConfirmed, $pA1['barcode']);
    assertEq('10 duplicate scan reason already_verified', $r['reason'] ?? '', 'already_verified');
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_item_id = {$oiA1}")->fetchColumn();
    assertEq('10 still exactly one row for the item', $cnt, 1);

    echo "== 11. Duplicate MANUAL is rejected; only one row ==\n";
    $r = order_verify_record_manual($pdo, $adminId, $orderConfirmed, $oiNull, "second attempt");
    assertEq('11 duplicate manual reason already_verified', $r['reason'] ?? '', 'already_verified');
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_item_id = {$oiNull}")->fetchColumn();
    assertEq('11 still exactly one row for the item', $cnt, 1);

    echo "== 12. A SOLD product held by the order verifies when its barcode matches ==\n";
    /* pA1 was SOLD and verified in test 1; confirm it stayed SOLD. Also scan a
       fresh SOLD packed item to show the SOLD hold never blocks verification. */
    $pA1StatusNow = (string) $pdo->query("SELECT status FROM products WHERE id = {$pA1['id']}")->fetchColumn();
    assertEq('12 SOLD product remained SOLD after verifying', $pA1StatusNow, 'SOLD');
    $r = order_verify_record($pdo, $adminId, $orderPackedA, $pPa1['barcode']);
    assertTrue('12 SOLD packed item verifies by scan', !empty($r['ok']) && ($r['method'] ?? '') === 'SCANNED');

    echo "== 13. A scan for an item from another order creates no verification record ==\n";
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_id = {$orderConfirmed} AND product_id = {$pA3['id']}")->fetchColumn();
    assertEq('13 no cross-order verification row for confirmed order', $cnt, 0);
    $cntOther = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_item_id = {$oiOther}")->fetchColumn();
    assertEq('13 the other order item was not verified', $cntOther, 0);

    echo "== 14. PENDING/SHIPPED/DELIVERED/CANCELLED cannot be verified ==\n";
    assertEq('14 PENDING scan rejected', order_verify_record($pdo, $adminId, $orderPending, $pPend['barcode'])['reason'] ?? '', 'order_not_verifiable');
    assertEq('14 SHIPPED scan rejected', order_verify_record($pdo, $adminId, $orderShipped, $pShip['barcode'])['reason'] ?? '', 'order_not_verifiable');
    assertEq('14 DELIVERED scan rejected', order_verify_record($pdo, $adminId, $orderDelivered, $pDel['barcode'])['reason'] ?? '', 'order_not_verifiable');
    assertEq('14 CANCELLED scan rejected', order_verify_record($pdo, $adminId, $orderCancelled, $pCanc['barcode'])['reason'] ?? '', 'order_not_verifiable');
    assertEq('14 PENDING manual rejected', order_verify_record_manual($pdo, $adminId, $orderPending, $oiPend, "reason")['reason'] ?? '', 'order_not_verifiable');
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_id IN ({$orderPending},{$orderShipped},{$orderDelivered},{$orderCancelled})")->fetchColumn();
    assertEq('14 no rows created for ineligible orders', $cnt, 0);

    echo "== 15. Pending/approved cancellation states are rejected ==\n";
    assertEq('15 REQUESTED cancellation scan rejected', order_verify_record($pdo, $adminId, $orderCancelReq, $pCancReq['barcode'])['reason'] ?? '', 'cancellation_locked');
    assertEq('15 APPROVED cancellation scan rejected', order_verify_record($pdo, $adminId, $orderCancelApp, $pCancApp['barcode'])['reason'] ?? '', 'cancellation_locked');
    assertEq('15 REQUESTED cancellation manual rejected', order_verify_record_manual($pdo, $adminId, $orderCancelReq, $oiCancReq, "reason")['reason'] ?? '', 'cancellation_locked');
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM order_item_verifications WHERE order_id IN ({$orderCancelReq},{$orderCancelApp})")->fetchColumn();
    assertEq('15 no rows created for cancellation-locked orders', $cnt, 0);

    echo "== 16. CONFIRMED and PACKED orders can be verified ==\n";
    assertTrue('16 CONFIRMED predicate true', order_is_verifiable('CONFIRMED', 'NONE'));
    assertTrue('16 PACKED predicate true', order_is_verifiable('PACKED', 'NONE'));
    assertTrue('16 CONFIRMED+REJECTED still verifiable', order_is_verifiable('CONFIRMED', 'REJECTED'));
    assertTrue('16 PENDING predicate false', !order_is_verifiable('PENDING', 'NONE'));
    /* Verify remaining confirmed-order items so it reaches completeness. */
    $r = order_verify_record($pdo, $adminId, $orderConfirmed, $pA2['barcode']);          // oiA2 scan
    assertTrue('16 confirmed order A2 scan accepted', !empty($r['ok']));
    $r = order_verify_record_manual($pdo, $adminId, $orderConfirmed, $oiLegacy, "Legacy, no barcode; matched by description.");
    assertTrue('16 confirmed order legacy manual accepted', !empty($r['ok']));
    $prog = order_verify_progress($pdo, $orderConfirmed);
    assertTrue('16 confirmed order now fully verified (4/4)', $prog['complete'] === true && $prog['verified'] === 4 && $prog['expected'] === 4);

    echo "== 17. Incomplete verification blocks PACKED->SHIPPED, no status change ==\n";
    /* orderPackedA: only pPa1 verified in test 12 (1 of 3). Tracking is set. */
    $progA = order_verify_progress($pdo, $orderPackedA);
    assertTrue('17 orderPackedA is incomplete (1/3)', $progA['complete'] === false && $progA['verified'] === 1 && $progA['expected'] === 3);
    $res = simulateAdvancePackedToShipped($pdo, $orderPackedA);
    assertEq('17 advance blocked with reason unverified', $res['reason'] ?? '', 'unverified');
    assertEq('17 order still PACKED', (string) $pdo->query("SELECT status FROM orders WHERE id = {$orderPackedA}")->fetchColumn(), 'PACKED');
    assertEq('17 shipment still NOT_SHIPPED', (string) $pdo->query("SELECT shipment_status FROM shipments WHERE order_id = {$orderPackedA}")->fetchColumn(), 'NOT_SHIPPED');

    echo "== 18. Mixed SCANNED + MANUAL verifications satisfy completeness ==\n";
    /* orderPackedB: scan pPb1, scan pPb2, manual the null item. */
    assertTrue('18 Pb1 scan', !empty(order_verify_record($pdo, $adminId, $orderPackedB, $pPb1['barcode'])['ok']));
    assertTrue('18 Pb2 scan', !empty(order_verify_record($pdo, $adminId, $orderPackedB, $pPb2['barcode'])['ok']));
    assertTrue('18 Pb-null manual', !empty(order_verify_record_manual($pdo, $adminId, $orderPackedB, $oiPbNull, "No barcode; checked against packing list.")['ok']));
    $progB = order_verify_progress($pdo, $orderPackedB);
    assertTrue('18 orderPackedB fully verified (3/3) via mix', $progB['complete'] === true && $progB['verified'] === 3);

    echo "== 20. Tracking requirement remains enforced (fully verified, no tracking) ==\n";
    $res = simulateAdvancePackedToShipped($pdo, $orderPackedB);
    assertEq('20 advance blocked for missing tracking', $res['reason'] ?? '', 'tracking');
    assertEq('20 order still PACKED (no tracking)', (string) $pdo->query("SELECT status FROM orders WHERE id = {$orderPackedB}")->fetchColumn(), 'PACKED');
    assertEq('20 shipment still NOT_SHIPPED (no tracking)', (string) $pdo->query("SELECT shipment_status FROM shipments WHERE order_id = {$orderPackedB}")->fetchColumn(), 'NOT_SHIPPED');

    echo "== 19. Fully verified + tracking present -> PACKED->SHIPPED works ==\n";
    $pdo->prepare('UPDATE shipments SET tracking_number = :tn WHERE order_id = :id')
        ->execute([':tn' => 'TRACK-B-' . $suffix, ':id' => $orderPackedB]);
    $res = simulateAdvancePackedToShipped($pdo, $orderPackedB);
    assertTrue('19 advance succeeds', !empty($res['ok']));
    assertEq('19 order now SHIPPED', (string) $pdo->query("SELECT status FROM orders WHERE id = {$orderPackedB}")->fetchColumn(), 'SHIPPED');
    assertEq('19 shipment now SHIPPED', (string) $pdo->query("SELECT shipment_status FROM shipments WHERE order_id = {$orderPackedB}")->fetchColumn(), 'SHIPPED');
    assertTrue('19 shipped_at set', !empty($pdo->query("SELECT shipped_at FROM shipments WHERE order_id = {$orderPackedB}")->fetchColumn()));

    echo "== 20b. Zero-expected-items order cannot ship ==\n";
    $res = simulateAdvancePackedToShipped($pdo, $orderPackedEmpty);
    assertEq('20b empty order blocked (unverified / zero expected)', $res['reason'] ?? '', 'unverified');
    assertEq('20b empty order still PACKED', (string) $pdo->query("SELECT status FROM orders WHERE id = {$orderPackedEmpty}")->fetchColumn(), 'PACKED');

    echo "== 21. Shipment blocking exists only at the order-view transition ==\n";
    $adminDir = __DIR__ . '/../../admin';
    $shipSetters = [];
    foreach (glob($adminDir . '/*.php') as $file) {
        $contents = (string) file_get_contents($file);
        /* Normalize PHP single-quote escaping (\' -> ') so the valid
           PHP-escaped SQL literal \'SHIPPED\' in admin/order-view.php is
           recognized, then match the assignment allowing any whitespace
           or line breaks around the = sign. */
        $normalized = str_replace("\\'", "'", $contents);
        if (preg_match("/shipment_status\s*=\s*'SHIPPED'/", $normalized) === 1) {
            $shipSetters[] = basename($file);
        }
    }
    sort($shipSetters);
    assertEq('21 only order-view.php sets shipment_status=SHIPPED', implode(',', $shipSetters), 'order-view.php');
    $orderViewSrc = (string) file_get_contents($adminDir . '/order-view.php');
    assertTrue('21 order-view.php gate calls order_verify_progress()', strpos($orderViewSrc, 'order_verify_progress(') !== false);

    echo "== 22. Verification created no sales records ==\n";
    $salesTxAfter = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    $salesItemsAfter = (int) $pdo->query('SELECT COUNT(*) FROM sales_transaction_items')->fetchColumn();
    assertEq('22 sales_transactions count unchanged', $salesTxAfter, $salesTxBefore);
    assertEq('22 sales_transaction_items count unchanged', $salesItemsAfter, $salesItemsBefore);
    $stiForFixtures = (int) $pdo->query('SELECT COUNT(*) FROM sales_transaction_items WHERE product_id IN (' . implode(',', array_map('intval', $created['products'])) . ')')->fetchColumn();
    assertEq('22 no sale items reference fixture products', $stiForFixtures, 0);

    echo "== 23. Verification changed no product status / payment / lifecycle fields ==\n";
    assertEq('23 pA1 status unchanged (SOLD)', (string) $pdo->query("SELECT status FROM products WHERE id = {$pA1['id']}")->fetchColumn(), $pA1StatusBefore);
    assertEq('23 orderPackedB payment unchanged', (string) $pdo->query("SELECT payment_status FROM payments WHERE order_id = {$orderPackedB}")->fetchColumn(), $packedBPaymentBefore);
    assertEq('23 fully-verified confirmed order NOT advanced by verification', (string) $pdo->query("SELECT status FROM orders WHERE id = {$orderConfirmed}")->fetchColumn(), 'CONFIRMED');

    echo "== 24. Endpoints enforce auth + CSRF (structural) ==\n";
    foreach (['order-verify-scan.php', 'order-verify-manual.php'] as $endpoint) {
        $src = (string) file_get_contents($adminDir . '/api/' . $endpoint);
        assertTrue("24 {$endpoint} checks admin session", strpos($src, "empty(\$_SESSION['admin_id'])") !== false);
        assertTrue("24 {$endpoint} validates CSRF with hash_equals", strpos($src, 'hash_equals(') !== false);
        assertTrue("24 {$endpoint} requires POST", strpos($src, "'POST'") !== false);
    }

    echo "== 25. Identity + state come from server/DB, not client ==\n";
    /* order_verify_record only accepts (pdo, adminId, orderId, barcode): there is
       no client product name/id/status/method/verified_by parameter to trust.
       The stored verified_by equals the server admin id and product_id is the
       DB-resolved product for the scanned barcode (asserted in test 1). Here we
       re-confirm the signature-level guarantee on the packed scan. */
    $scanRow = $pdo->query("SELECT verified_by, product_id, barcode_value, method FROM order_item_verifications WHERE order_item_id = {$oiPa1}")->fetch();
    assertEq('25 verified_by derived from server admin', (int) ($scanRow['verified_by'] ?? 0), $adminId);
    assertEq('25 product_id derived from DB barcode match', (int) ($scanRow['product_id'] ?? 0), $pPa1['id']);
    assertEq('25 method derived server-side (SCANNED)', $scanRow['method'] ?? '', 'SCANNED');

    echo "== 26. Extra: same-order concurrency increments progress correctly ==\n";
    /* orderPackedA still has pPa2 unverified (Pa1 verified, Pa-null unverified).
       Verify pPa2, then manual the null item; progress must reach 3/3. */
    assertTrue('26 Pa2 scan accepted', !empty(order_verify_record($pdo, $adminId, $orderPackedA, $pPa2['barcode'])['ok']));
    assertTrue('26 Pa-null manual accepted', !empty(order_verify_record_manual($pdo, $adminId, $orderPackedA, $oiPaNull, "No barcode; verified against invoice.")['ok']));
    $progA2 = order_verify_progress($pdo, $orderPackedA);
    assertTrue('26 orderPackedA now complete (3/3)', $progA2['complete'] === true && $progA2['verified'] === 3 && $progA2['expected'] === 3);

    echo "== 27. Extra: lookup resolves expected item and flags already-verified ==\n";
    $look = order_verify_lookup($pdo, $orderConfirmed, $pA1['barcode']);
    assertTrue('27 lookup finds expected item', !empty($look['ok']) && (int) $look['order_item_id'] === $oiA1);
    assertTrue('27 lookup flags already-verified item', ($look['already_verified'] ?? false) === true);
    $look2 = order_verify_lookup($pdo, $orderConfirmed, $pA4['barcode']);
    assertEq('27 lookup rejects unexpected product', $look2['reason'] ?? '', 'unexpected_item');
} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception: " . $e->getMessage() . "\n";
    echo "        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    /* ---- Cleanup fixtures (children first; respects FK actions). ---- */
    try {
        if (!empty($created['orders'])) {
            $oids = implode(',', array_map('intval', $created['orders']));
            $pdo->exec("DELETE FROM order_item_verifications WHERE order_id IN ({$oids})");
        }
        if (!empty($created['admin'])) {
            $pdo->exec("DELETE FROM order_item_verifications WHERE verified_by = " . (int) $created['admin']);
        }
        if (!empty($created['orders'])) {
            $oids = implode(',', array_map('intval', $created['orders']));
            $pdo->exec("DELETE FROM payments WHERE order_id IN ({$oids})");
            $pdo->exec("DELETE FROM shipments WHERE order_id IN ({$oids})");
            $pdo->exec("DELETE FROM order_items WHERE order_id IN ({$oids})");
            $pdo->exec("DELETE FROM orders WHERE id IN ({$oids})");
        }
        if (!empty($created['products'])) {
            $pids = implode(',', array_map('intval', $created['products']));
            $pdo->exec("DELETE FROM products WHERE id IN ({$pids})");
        }
        if (!empty($created['bundle'])) {
            $pdo->exec("DELETE FROM purchase_bundles WHERE id = " . (int) $created['bundle']);
        }
        if (!empty($created['customer'])) {
            $pdo->exec("DELETE FROM customers WHERE id = " . (int) $created['customer']);
        }
        if (!empty($created['admin'])) {
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
