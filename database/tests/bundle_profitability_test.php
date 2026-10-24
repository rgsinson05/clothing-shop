<?php

/*
 * Hopia Fits - Bundle profitability metrics tests (DEVELOPER-RUN).
 *
 * Exercises the REAL metrics code (includes/bundle_metrics.php) against the
 * dedicated local test database clothing_shop_bundle_test ONLY. It:
 *   - refuses to run on the web (CLI only);
 *   - reuses the .env host/user/password but FORCES the database name to
 *     clothing_shop_bundle_test and hard-aborts if the connected database is
 *     anything else;
 *   - requires migrations 005-009 to already be applied;
 *   - creates clearly-labelled, self-contained fixtures (test admin, customer,
 *     two bundles, products, a multi-bundle walk-in sale, an online completed
 *     sale, an online hold, a delivered-but-not-received order, legacy
 *     products) and removes them again in a finally block, pass or fail;
 *   - NEVER resets the DB and never deletes pre-existing data.
 *
 * It uses the real sale paths sales_complete_walkin() and
 * sales_confirm_receipt_online() so the completed-sale records it asserts on
 * are produced exactly as production produces them.
 *
 *   php database/tests/bundle_profitability_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/sales.php';
require_once __DIR__ . '/../../includes/payment.php';
require_once __DIR__ . '/../../includes/bundle_metrics.php';

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
    fwrite(STDERR, "Refusing to run: connected to '{$connectedDb}', expected '" . TEST_DB_NAME . "'.\n");
    exit(2);
}

/* Require the tables this feature reads. */
try {
    $pdo->query('SELECT 1 FROM purchase_bundles LIMIT 1');
    $pdo->query('SELECT 1 FROM sales_transactions LIMIT 1');
    $pdo->query('SELECT 1 FROM sales_transaction_items LIMIT 1');
    $pdo->query('SELECT received_at FROM orders LIMIT 1');
    $pdo->query('SELECT retired_at FROM products LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Required tables/columns missing (apply migrations 005-009).\n");
    fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
    exit(2);
}

$tests = 0;
$failures = 0;
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
    'admin' => null,
    'customer' => null,
    'bundles' => [],
    'products' => [],
    'orders' => [],
    'sale_ids' => [],
    'request_keys' => [],
];

/* Find metrics row for a bundle id from bundle_metrics_rows()+derive(). */
function metricsFor(PDO $pdo, int $bundleId): ?array
{
    foreach (bundle_metrics_rows($pdo) as $raw) {
        if ((int) $raw['bundle_id'] === $bundleId) {
            return bundle_metrics_derive($raw);
        }
    }
    return null;
}

/* Insert a bundled product with a unique, test-scoped barcode. */
function bpMakeProduct(PDO $pdo, string $suffix, int $bundleId, int $seq, string $category, string $price, string $status = 'AVAILABLE'): array
{
    global $created;
    $barcode = 'BPT-' . $suffix . '-' . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT) . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    $pdo->prepare(
        'INSERT INTO products (name, category, gender, condition_label, price, status, bundle_id, bundle_item_number, barcode_value)
         VALUES (:name, :cat, :gender, :cond, :price, :status, :bid, :seq, :barcode)'
    )->execute([
        ':name' => "BPT {$suffix} item {$seq}",
        ':cat' => $category, ':gender' => 'MEN', ':cond' => 'Test',
        ':price' => $price, ':status' => $status, ':bid' => $bundleId, ':seq' => $seq, ':barcode' => $barcode,
    ]);
    $id = (int) $pdo->lastInsertId();
    $created['products'][] = $id;
    return ['id' => $id, 'barcode' => $barcode, 'price' => $price];
}

/* Insert a legacy (unassigned) product. */
function bpMakeLegacy(PDO $pdo, string $suffix, string $category, string $price, string $status = 'AVAILABLE'): int
{
    global $created;
    $pdo->prepare(
        'INSERT INTO products (name, category, gender, condition_label, price, status)
         VALUES (:name, :cat, :gender, :cond, :price, :status)'
    )->execute([
        ':name' => "BPT {$suffix} legacy",
        ':cat' => $category, ':gender' => 'MEN', ':cond' => 'Test', ':price' => $price, ':status' => $status,
    ]);
    $id = (int) $pdo->lastInsertId();
    $created['products'][] = $id;
    return $id;
}

/* Insert an order with one order_item for one product, mark product SOLD. */
function bpMakeOnlineOrder(PDO $pdo, int $customerId, int $productId, string $productName, string $price, string $status, ?string $receivedAt): int
{
    global $created;
    $pdo->prepare(
        'INSERT INTO orders (customer_id, status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address, received_at)
         VALUES (:cid, :st, :sub, :fee, :tot, :sn, :sp, :sa, :rc)'
    )->execute([
        ':cid' => $customerId, ':st' => $status,
        ':sub' => $price, ':fee' => '0.00', ':tot' => $price,
        ':sn' => 'BPT Tester', ':sp' => '0900', ':sa' => 'Test address',
        ':rc' => $receivedAt,
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $created['orders'][] = $orderId;

    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, subtotal)
         VALUES (:oid, :pid, :pn, :up, 1, :sub)'
    )->execute([
        ':oid' => $orderId, ':pid' => $productId, ':pn' => $productName, ':up' => $price, ':sub' => $price,
    ]);
    $pdo->prepare("UPDATE products SET status = 'SOLD' WHERE id = :id")->execute([':id' => $productId]);

    return $orderId;
}

/* Insert a payments row for an order. */
function bpMakePayment(PDO $pdo, int $orderId, string $method, string $status): void
{
    $pdo->prepare(
        'INSERT INTO payments (order_id, payment_method, payment_status) VALUES (:oid, :m, :s)'
    )->execute([':oid' => $orderId, ':m' => $method, ':s' => $status]);
}

try {
    /* Baselines (the test DB already contains unrelated data; assert on
       deltas and on our own bundle ids so results are deterministic). */
    $legacyBefore   = bundle_metrics_legacy_summary($pdo);
    $overviewBefore = bundle_metrics_overview($pdo);

    /* ---- Fixtures ---- */
    $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (:n, :e, :p)')
        ->execute([':n' => 'BPT Tester', ':e' => "bpt+{$suffix}@test.local", ':p' => 'x']);
    $created['admin'] = (int) $pdo->lastInsertId();
    $adminId = $created['admin'];

    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => 'BPT', ':l' => 'Cust', ':e' => "bptcust+{$suffix}@test.local", ':p' => 'x']);
    $created['customer'] = (int) $pdo->lastInsertId();
    $customerId = $created['customer'];

    /* B1: SHIRTS, capital 250.00, qty 7 -> unit cost round(25000/7)=3571. */
    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'SHIRTS', ':cap' => '250.00', ':q' => 7]);
    $b1 = (int) $pdo->lastInsertId();
    $created['bundles'][] = $b1;

    /* B2: PANTS, capital 100.00, qty 2 -> unit cost 5000. */
    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'PANTS', ':cap' => '100.00', ':q' => 2]);
    $b2 = (int) $pdo->lastInsertId();
    $created['bundles'][] = $b2;

    /* B1 products */
    $p1 = bpMakeProduct($pdo, $suffix, $b1, 1, 'SHIRTS', '150.00'); // available + remaining
    $p2 = bpMakeProduct($pdo, $suffix, $b1, 2, 'SHIRTS', '150.00'); // retired
    $p3 = bpMakeProduct($pdo, $suffix, $b1, 3, 'SHIRTS', '140.00'); // walk-in completed
    $p4 = bpMakeProduct($pdo, $suffix, $b1, 4, 'SHIRTS', '150.00'); // online hold (PENDING)
    $p5 = bpMakeProduct($pdo, $suffix, $b1, 5, 'SHIRTS', '120.00'); // online completed
    $p6 = bpMakeProduct($pdo, $suffix, $b1, 6, 'SHIRTS', '150.00'); // delivered, NOT received -> hold

    /* B2 products */
    $p7 = bpMakeProduct($pdo, $suffix, $b2, 1, 'PANTS', '60.00');   // walk-in completed (same tx as p3)
    $p8 = bpMakeProduct($pdo, $suffix, $b2, 2, 'PANTS', '55.00');   // available + remaining

    /* Legacy (unassigned) products */
    $l1 = bpMakeLegacy($pdo, $suffix, 'SHIRTS', '99.00', 'AVAILABLE');          // legacy available
    $l2 = bpMakeLegacy($pdo, $suffix, 'SHIRTS', '99.00', 'SOLD');               // legacy online-held
    $l3 = bpMakeLegacy($pdo, $suffix, 'SHIRTS', '99.00', 'AVAILABLE');          // legacy retired

    /* p2 and l3 retired. */
    $pdo->prepare('UPDATE products SET retired_at = NOW() WHERE id = :id')->execute([':id' => $p2['id']]);
    $pdo->prepare('UPDATE products SET retired_at = NOW() WHERE id = :id')->execute([':id' => $l3]);

    echo "== Multi-bundle walk-in sale (p3 B1 + p7 B2) ==\n";
    $walkinKey = 'bpt-walkin-' . $suffix . '-01';
    $created['request_keys'][] = $walkinKey;
    $res = sales_complete_walkin(
        $pdo,
        $adminId,
        [$p3['barcode'], $p7['barcode']],
        20000,                 // 140.00 + 60.00
        [14000, 6000],         // preview prices, index-aligned
        $walkinKey
    );
    assertEq('walk-in sale ok', $res['ok'] ?? false, true);
    if (!empty($res['sale']['id'])) {
        $created['sale_ids'][] = (int) $res['sale']['id'];
    }

    /* Change p3 current price AFTER the sale to prove the revenue uses the
       stored snapshot (140.00), not the current price (160.00). */
    $pdo->prepare('UPDATE products SET price = :pr WHERE id = :id')->execute([':pr' => '160.00', ':id' => $p3['id']]);

    echo "== Online completed sale (p5, received) ==\n";
    $o5 = bpMakeOnlineOrder($pdo, $customerId, $p5['id'], 'BPT online p5', '120.00', 'DELIVERED', null);
    bpMakePayment($pdo, $o5, 'COD', 'PAID');
    $recv = sales_confirm_receipt_online($pdo, $customerId, $o5);
    assertEq('online receipt confirm ok', $recv['ok'] ?? false, true);
    if (!empty($recv['sale']['id'])) {
        $created['sale_ids'][] = (int) $recv['sale']['id'];
    }

    echo "== Online hold (p4 PENDING, GCASH PENDING) ==\n";
    $o4 = bpMakeOnlineOrder($pdo, $customerId, $p4['id'], 'BPT online p4', '150.00', 'PENDING', null);
    bpMakePayment($pdo, $o4, 'GCASH', 'PENDING');

    echo "== Delivered but NOT received (p6) -> still a hold ==\n";
    $o6 = bpMakeOnlineOrder($pdo, $customerId, $p6['id'], 'BPT online p6', '150.00', 'DELIVERED', null);

    /* ======================================================
       ASSERTIONS
       ====================================================== */
    $m1 = metricsFor($pdo, $b1);
    $m2 = metricsFor($pdo, $b2);
    assertEq('B1 row present', $m1 !== null, true);
    assertEq('B2 row present', $m2 !== null, true);

    echo "== B1 unit cost & registration ==\n";
    assertEq('B1 unit_cost_cents = round(25000/7)=3571', $m1['unit_cost_cents'], 3571);
    assertEq('B1 registered_count = 6', $m1['registered_count'], 6);
    assertEq('B1 unregistered_qty = 1', $m1['unregistered_qty'], 1);

    echo "== B1 inventory status counts ==\n";
    assertEq('B1 available_count = 1 (p1)', $m1['available_count'], 1);
    assertEq('B1 online_held_count = 2 (p4,p6)', $m1['online_held_count'], 2);
    assertEq('B1 completed_sold_count = 2 (p3,p5)', $m1['completed_sold_count'], 2);
    assertEq('B1 retired_count = 1 (p2)', $m1['retired_count'], 1);

    echo "== B1 revenue (snapshot, not current price) ==\n";
    assertEq('B1 walkin_revenue_cents = 14000 (snapshot 140, not 160)', $m1['walkin_revenue_cents'], 14000);
    assertEq('B1 walkin_items = 1', $m1['walkin_items'], 1);
    assertEq('B1 online_revenue_cents = 12000', $m1['online_revenue_cents'], 12000);
    assertEq('B1 online_items = 1', $m1['online_items'], 1);
    assertEq('B1 completed_revenue_cents = 26000', $m1['completed_revenue_cents'], 26000);

    echo "== B1 gross profit (COGS allocated at unit cost) ==\n";
    assertEq('B1 completed_cogs_cents = 3571*2 = 7142', $m1['completed_cogs_cents'], 7142);
    assertEq('B1 gross_profit_cents = 26000-7142 = 18858', $m1['gross_profit_cents'], 18858);

    echo "== B1 remaining projection (current prices, available only) ==\n";
    assertEq('B1 remaining_revenue_cents = 15000 (p1 only)', $m1['remaining_revenue_cents'], 15000);
    assertEq('B1 remaining_items = 1', $m1['remaining_items'], 1);
    assertEq('B1 remaining_cogs_cents = 3571', $m1['remaining_cogs_cents'], 3571);
    assertEq('B1 remaining_profit_cents = 15000-3571 = 11429', $m1['remaining_profit_cents'], 11429);
    assertEq('B1 potential_revenue_cents = 41000', $m1['potential_revenue_cents'], 41000);
    assertEq('B1 potential_profit_cents = 30287', $m1['potential_profit_cents'], 30287);

    echo "== B1 payment breakdown (PAID only; held not counted) ==\n";
    assertEq('B1 gcash_paid_revenue_cents = 0 (p4 is a hold, GCASH PENDING)', $m1['gcash_paid_revenue_cents'], 0);
    assertEq('B1 cod_paid_revenue_cents = 12000 (p5 COD PAID)', $m1['cod_paid_revenue_cents'], 12000);
    assertEq('B1 cod_paid_items = 1', $m1['cod_paid_items'], 1);
    assertEq('B1 online_unrecorded_items = 0', $m1['online_unrecorded_items'], 0);
    assertEq('B1 online_uncollected_items = 0', $m1['online_uncollected_items'], 0);

    echo "== B2 (second bundle, multi-bundle attribution) ==\n";
    assertEq('B2 unit_cost_cents = 5000', $m2['unit_cost_cents'], 5000);
    assertEq('B2 walkin_revenue_cents = 6000 (NOT the 20000 tx total)', $m2['walkin_revenue_cents'], 6000);
    assertEq('B2 walkin_items = 1', $m2['walkin_items'], 1);
    assertEq('B2 completed_sold_count = 1 (p7)', $m2['completed_sold_count'], 1);
    assertEq('B2 available_count = 1 (p8)', $m2['available_count'], 1);
    assertEq('B2 gross_profit_cents = 6000-5000 = 1000', $m2['gross_profit_cents'], 1000);
    assertEq('B2 remaining_revenue_cents = 5500 (p8)', $m2['remaining_revenue_cents'], 5500);
    assertEq('B2 remaining_profit_cents = 5500-5000 = 500', $m2['remaining_profit_cents'], 500);

    echo "== B3 uncollected vs unrecorded payment breakdown (regression) ==\n";
    /* One completed online sale whose GCash payment is recorded but still
       PENDING (submitted, awaiting admin verification), and one completed
       online sale with NO payments row. The metrics must keep them apart:
       recorded-but-unpaid is "uncollected", never "unrecorded", and never
       counted as collected revenue. */
    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'SHORTS', ':cap' => '90.00', ':q' => 3]);
    $b3 = (int) $pdo->lastInsertId();
    $created['bundles'][] = $b3;

    $p9  = bpMakeProduct($pdo, $suffix, $b3, 1, 'SHORTS', '70.00'); // online completed, GCASH PENDING
    $p10 = bpMakeProduct($pdo, $suffix, $b3, 2, 'SHORTS', '65.00'); // online completed, no payments row

    $o9 = bpMakeOnlineOrder($pdo, $customerId, $p9['id'], 'BPT online p9', '70.00', 'DELIVERED', null);
    bpMakePayment($pdo, $o9, 'GCASH', 'PENDING');

    /* Phase 5: the real receipt path refuses an unverified GCash payment. */
    $blocked9 = sales_confirm_receipt_online($pdo, $customerId, $o9);
    assertEq('B3 p9 unverified GCash receipt rejected', $blocked9['ok'] ?? true, false);

    /* Simulate legacy/inconsistent data: complete the sale while PAID, then
       restore the stored PENDING status so the metrics regression still sees
       a completed sale whose GCash payment is uncollected. */
    $pdo->prepare("UPDATE payments SET payment_status = 'PAID' WHERE order_id = :oid")
        ->execute([':oid' => $o9]);
    $recv9 = sales_confirm_receipt_online($pdo, $customerId, $o9);
    assertEq('B3 p9 legacy simulation receipt confirm ok', $recv9['ok'] ?? false, true);
    $pdo->prepare("UPDATE payments SET payment_status = 'PENDING' WHERE order_id = :oid")
        ->execute([':oid' => $o9]);
    if (!empty($recv9['sale']['id'])) {
        $created['sale_ids'][] = (int) $recv9['sale']['id'];
    }

    $o10 = bpMakeOnlineOrder($pdo, $customerId, $p10['id'], 'BPT online p10', '65.00', 'DELIVERED', null);
    $recv10 = sales_confirm_receipt_online($pdo, $customerId, $o10);
    assertEq('B3 p10 receipt confirm ok', $recv10['ok'] ?? false, true);
    if (!empty($recv10['sale']['id'])) {
        $created['sale_ids'][] = (int) $recv10['sale']['id'];
    }

    $m3 = metricsFor($pdo, $b3);
    assertEq('B3 online_items = 2', $m3['online_items'], 2);
    assertEq('B3 online_revenue_cents = 13500 (both completed)', $m3['online_revenue_cents'], 13500);
    assertEq('B3 gcash_paid_revenue_cents = 0 (PENDING is NOT collected)', $m3['gcash_paid_revenue_cents'], 0);
    assertEq('B3 gcash_paid_items = 0', $m3['gcash_paid_items'], 0);
    assertEq('B3 cod_paid_revenue_cents = 0', $m3['cod_paid_revenue_cents'], 0);
    assertEq('B3 online_uncollected_items = 1 (p9 recorded, PENDING)', $m3['online_uncollected_items'], 1);
    assertEq('B3 online_unrecorded_items = 1 (p10 no payments row)', $m3['online_unrecorded_items'], 1);

    echo "== Category filter ==\n";
    $pantsIds = array_map(static fn($r) => (int) $r['bundle_id'], bundle_metrics_rows($pdo, 'PANTS'));
    assertEq('PANTS filter includes B2', in_array($b2, $pantsIds, true), true);
    assertEq('PANTS filter excludes B1', in_array($b1, $pantsIds, true), false);

    echo "== Legacy unassigned (delta vs baseline) ==\n";
    $legacyAfter = bundle_metrics_legacy_summary($pdo);
    assertEq('legacy total +3', $legacyAfter['total'] - $legacyBefore['total'], 3);
    assertEq('legacy available +1 (l1)', $legacyAfter['available'] - $legacyBefore['available'], 1);
    assertEq('legacy online_held +1 (l2)', $legacyAfter['online_held'] - $legacyBefore['online_held'], 1);
    assertEq('legacy retired +1 (l3)', $legacyAfter['retired'] - $legacyBefore['retired'], 1);
    assertEq('legacy completed_sold +0', $legacyAfter['completed_sold'] - $legacyBefore['completed_sold'], 0);

    echo "== Overview aggregate (delta vs baseline; includes B3 fixtures) ==\n";
    $overviewAfter = bundle_metrics_overview($pdo);
    assertEq('overview bundle_count +3', $overviewAfter['bundle_count'] - $overviewBefore['bundle_count'], 3);
    assertEq('overview capital +44000c', $overviewAfter['capital_cents'] - $overviewBefore['capital_cents'], 44000);
    assertEq('overview registered +10', $overviewAfter['registered_count'] - $overviewBefore['registered_count'], 10);
    assertEq('overview available +2', $overviewAfter['available_count'] - $overviewBefore['available_count'], 2);
    assertEq('overview online_held +2', $overviewAfter['online_held_count'] - $overviewBefore['online_held_count'], 2);
    assertEq('overview completed_sold +5', $overviewAfter['completed_sold_count'] - $overviewBefore['completed_sold_count'], 5);
    assertEq('overview walkin_revenue +20000c', $overviewAfter['walkin_revenue_cents'] - $overviewBefore['walkin_revenue_cents'], 20000);
    assertEq('overview online_revenue +25500c', $overviewAfter['online_revenue_cents'] - $overviewBefore['online_revenue_cents'], 25500);
    assertEq('overview gross_profit +27358c', $overviewAfter['gross_profit_cents'] - $overviewBefore['gross_profit_cents'], 27358);
    assertEq('overview remaining_revenue +20500c', $overviewAfter['remaining_revenue_cents'] - $overviewBefore['remaining_revenue_cents'], 20500);

    /* ======================================================
       FINANCIAL DETAILS BREAKDOWN REGRESSIONS
       (read-only helpers used by admin/bundles.php)
       ====================================================== */

    echo "== A. Completed-sales breakdown reconciles with summary ==\n";
    $c1 = bundle_metrics_completed_items($pdo, $b1);
    assertEq('B1 completed items count = 2', count($c1), 2);
    $sum1 = 0;
    foreach ($c1 as $ci) {
        $sum1 += $ci['price_cents'];
    }
    assertEq('B1 completed breakdown sum = summary completed_revenue (26000c)', $sum1, $m1['completed_revenue_cents']);
    $channels = [];
    foreach ($c1 as $ci) {
        $channels[$ci['channel']] = ($channels[$ci['channel']] ?? 0) + 1;
    }
    assertEq('B1 breakdown channels WALKIN=1', ($channels['WALKIN'] ?? 0), 1);
    assertEq('B1 breakdown channels ONLINE=1', ($channels['ONLINE'] ?? 0), 1);
    foreach ($c1 as $ci) {
        if ($ci['channel'] === 'WALKIN') {
            assertEq('B1 walkin snapshot price is 14000c (not current 160)', $ci['price_cents'], 14000);
            assertEq('B1 walkin sale ref present', $ci['sale_id'] > 0, true);
        }
        if ($ci['channel'] === 'ONLINE') {
            assertEq('B1 online order ref present', $ci['order_id'] !== null, true);
        }
    }

    echo "== B. Active-order breakdown: courier vs pickup vs cancelled ==\n";
    /* p4: PENDING courier order (no shipment row) -> Local Courier via fallback.
       p6: DELIVERED courier order (no shipment row, no pickup_status) -> 'Online order'.
       The counts and amounts must reconcile with online_held_count = 2 and
       never leak into completed sales or the available projection. */
    $a1 = bundle_metrics_active_order_items($pdo, $b1);
    assertEq('B1 active items count = online_held_count (2)', count($a1['items']), $m1['online_held_count']);
    assertEq('B1 active orphaned = 0', $a1['orphaned_count'], 0);
    $activeSum1 = 0;
    $activeProductIds = [];
    foreach ($a1['items'] as $ai) {
        $activeSum1 += $ai['price_cents'];
        $activeProductIds[] = $ai['product_id'];
    }
    assertEq('B1 Sales in Active Orders = 15000+15000 = 30000c', $activeSum1, 30000);
    assertEq('B1 active items exclude completed p3', !in_array($p3['id'], $activeProductIds, true), true);
    assertEq('B1 active items exclude completed p5', !in_array($p5['id'], $activeProductIds, true), true);
    assertEq('B1 active items exclude available p1', !in_array($p1['id'], $activeProductIds, true), true);
    foreach ($a1['items'] as $ai) {
        assertEq('B1 active order status not CANCELLED', $ai['order_status'] !== 'CANCELLED', true);
    }

    echo "== C. Available-items breakdown reconciles with Potential Sales ==\n";
    $v1 = bundle_metrics_available_items($pdo, $b1);
    assertEq('B1 available breakdown count = 1', count($v1), 1);
    $availSum1 = 0;
    foreach ($v1 as $vi) {
        $availSum1 += $vi['price_cents'];
    }
    assertEq('B1 available breakdown sum = remaining_revenue (15000c)', $availSum1, $m1['remaining_revenue_cents']);
    assertEq('B1 available breakdown current price used (15000c)', $v1[0]['price_cents'], 15000);
    /* B1 also proves the current-price rule: p3 repriced AFTER its sale appears
       with snapshot 14000c in A, while p1 (never sold) appears with its current
       price in C. */

    echo "== C2. Completed-exactly-once regression (B1 p5) ==\n";
    /* sales_transaction_items.product_id is UNIQUE, and the completed helper
       reads committed rows only, so p5 appears exactly once in A's list. */
    $p5Count = 0;
    foreach ($c1 as $ci) {
        if ($ci['product_id'] === $p5['id']) {
            $p5Count++;
        }
    }
    assertEq('B1 p5 appears exactly once in completed breakdown', $p5Count, 1);

    echo "== E. Payment breakdown detail (B3: pending GCash + missing record) ==\n";
    $pay3 = bundle_metrics_payment_items($pdo, $b3);
    assertEq('B3 payment rows = 2', count($pay3), 2);
    $byMethod = [];
    foreach ($pay3 as $pi) {
        $key = ($pi['payment_method'] ?? 'NONE') . '/' . ($pi['payment_status'] ?? 'NONE');
        $byMethod[$key] = ($byMethod[$key] ?? 0) + 1;
    }
    assertEq('B3 GCASH/PENDING rows = 1', ($byMethod['GCASH/PENDING'] ?? 0), 1);
    assertEq('B3 null-payment rows = 1', ($byMethod['NONE/NONE'] ?? 0), 1);
    foreach ($pay3 as $pi) {
        if ($pi['payment_method'] === 'GCASH') {
            /* A PENDING GCash row with no submitted reference derives as
               NOT_SUBMITTED (still awaiting, never verified). */
            $state = payment_gcash_state($pi);
            assertEq('B3 pending GCash state NOT_SUBMITTED', $state, 'NOT_SUBMITTED');
        }
    }

    echo "== Active orders: cancelled excluded / pickup hold represented ==\n";
    /* B4 fixtures: one Store-Pickup-held garment, one cancelled order whose
       garment was restored to AVAILABLE (it must re-enter the available
       projection, proving cancelled orders never stay in active amounts). */
    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'SHIRTS', ':cap' => '80.00', ':q' => 4]);
    $b4 = (int) $pdo->lastInsertId();
    $created['bundles'][] = $b4;

    $p11 = bpMakeProduct($pdo, $suffix, $b4, 1, 'SHIRTS', '100.00'); // pickup hold
    $p12 = bpMakeProduct($pdo, $suffix, $b4, 2, 'SHIRTS', '90.00');  // cancelled -> restored available
    $p13 = bpMakeProduct($pdo, $suffix, $b4, 3, 'SHIRTS', '85.00');  // stays available

    /* Store Pickup hold: shipment row with STORE_PICKUP, pickup lifecycle
       mid-progress (READY_FOR_PICKUP), product SOLD. */
    $o11 = bpMakeOnlineOrder($pdo, $customerId, $p11['id'], 'BPT pickup p11', '100.00', 'PENDING', null);
    $pdo->prepare(
        "INSERT INTO shipments (order_id, shipment_status, delivery_method) VALUES (:oid, 'NOT_SHIPPED', 'STORE_PICKUP')"
    )->execute([':oid' => $o11]);
    $pdo->prepare(
        "UPDATE orders SET pickup_status = 'READY_FOR_PICKUP' WHERE id = :oid"
    )->execute([':oid' => $o11]);

    /* Cancelled order: order + order_items + product SOLD, then the real
       cancellation outcome: order CANCELLED and product restored AVAILABLE. */
    $o12 = bpMakeOnlineOrder($pdo, $customerId, $p12['id'], 'BPT cancelled p12', '90.00', 'PENDING', null);
    $pdo->prepare("UPDATE orders SET status = 'CANCELLED', cancellation_status = 'APPROVED' WHERE id = :oid")
        ->execute([':oid' => $o12]);
    $pdo->prepare("UPDATE products SET status = 'AVAILABLE' WHERE id = :id")->execute([':id' => $p12['id']]);

    $m4 = metricsFor($pdo, $b4);
    $a4 = bundle_metrics_active_order_items($pdo, $b4);
    assertEq('B4 online_held_count = 1 (pickup hold only)', $m4['online_held_count'], 1);
    assertEq('B4 active items = 1', count($a4['items']), 1);
    assertEq('B4 active item is the pickup hold p11', $a4['items'][0]['product_id'], $p11['id']);
    assertEq('B4 active hold delivery_method STORE_PICKUP', $a4['items'][0]['delivery_method'], 'STORE_PICKUP');
    assertEq('B4 cancelled order not in active items', $a4['items'][0]['order_id'], $o11);
    $v4 = bundle_metrics_available_items($pdo, $b4);
    $v4Ids = array_map(static fn($r) => $r['product_id'], $v4);
    assertEq('B4 restored p12 back in available projection', in_array($p12['id'], $v4Ids, true), true);
    assertEq('B4 available count = 2 (p12 restored + p13)', count($v4), 2);
    assertEq('B4 remaining_revenue = 9000+8500 = 17500c', $m4['remaining_revenue_cents'], 17500);
    assertEq('B4 completed_revenue = 0 (nothing completed)', $m4['completed_revenue_cents'], 0);

    echo "== B2. Shipping fee never inflates merchandise figures ==\n";
    /* A courier order with a NON-ZERO shipping fee completing through the real
       receipt path: the completed sale total must be the merchandise snapshot
       only (order_items.unit_price), never subtotal + shipping. */
    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'PANTS', ':cap' => '150.00', ':q' => 3]);
    $b5 = (int) $pdo->lastInsertId();
    $created['bundles'][] = $b5;

    $p14 = bpMakeProduct($pdo, $suffix, $b5, 1, 'PANTS', '180.00');
    $o14 = bpMakeOnlineOrder($pdo, $customerId, $p14['id'], 'BPT shipped p14', '180.00', 'DELIVERED', null);
    /* Realistic courier order: nonzero shipping fee stored on the order,
       total_amount = subtotal + shipping (matches checkout behaviour). */
    $pdo->prepare(
        "UPDATE orders SET shipping_fee = '80.00', total_amount = '260.00' WHERE id = :oid"
    )->execute([':oid' => $o14]);
    $recv14 = sales_confirm_receipt_online($pdo, $customerId, $o14);
    assertEq('B5 receipt confirm ok', $recv14['ok'] ?? false, true);
    if (!empty($recv14['sale']['id'])) {
        $created['sale_ids'][] = (int) $recv14['sale']['id'];
    }

    $m5 = metricsFor($pdo, $b5);
    assertEq('B5 online_revenue = 18000c merchandise only (not 26000)', $m5['online_revenue_cents'], 18000);
    assertEq('B5 gross_profit = 18000-5000 = 13000c', $m5['gross_profit_cents'], 13000);
    $c5 = bundle_metrics_completed_items($pdo, $b5);
    assertEq('B5 completed breakdown item price = 18000c snapshot', $c5[0]['price_cents'], 18000);

    echo "== F. Retired/unregistered excluded from eligible projections ==\n";
    assertEq('B1 retired excluded from remaining (already asserted: remaining=1 item 15000c)', $m1['remaining_revenue_cents'], 15000);
    assertEq('B1 unregistered_qty = 1 contributes no projection', $m1['unregistered_qty'], 1);
    assertEq('B1 available breakdown excludes retired p2', !in_array($p2['id'], array_map(static fn($r) => $r['product_id'], $v1), true), true);
    assertEq('B4 retired/unregistered zero-projection bundle counts ok', $m4['retired_count'], 0);

    echo "== Empty breakdowns render correctly ==\n";
    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'SHORTS', ':cap' => '60.00', ':q' => 2]);
    $b6 = (int) $pdo->lastInsertId();
    $created['bundles'][] = $b6;
    /* No products registered at all: every breakdown is empty but callable. */
    assertEq('B6 completed breakdown empty', count(bundle_metrics_completed_items($pdo, $b6)), 0);
    $a6 = bundle_metrics_active_order_items($pdo, $b6);
    assertEq('B6 active breakdown empty', count($a6['items']), 0);
    assertEq('B6 active orphaned empty', $a6['orphaned_count'], 0);
    assertEq('B6 available breakdown empty', count(bundle_metrics_available_items($pdo, $b6)), 0);
    assertEq('B6 payment breakdown empty', count(bundle_metrics_payment_items($pdo, $b6)), 0);
    $m6 = metricsFor($pdo, $b6);
    assertEq('B6 summary all zero', [$m6['completed_revenue_cents'], $m6['remaining_revenue_cents'], $m6['gross_profit_cents']], [0, 0, 0]);

    /* B4/B5/B6 fixtures must also be reflected in the overview deltas below,
       so extend the expected deltas computed above. */
    echo "== Overview aggregate extended (B4/B5/B6) ==\n";
    $overviewFinal = bundle_metrics_overview($pdo);
    assertEq('overview bundle_count +6', $overviewFinal['bundle_count'] - $overviewBefore['bundle_count'], 6);
    assertEq('overview capital +73000c', $overviewFinal['capital_cents'] - $overviewBefore['capital_cents'], 73000);
    assertEq('overview registered +14', $overviewFinal['registered_count'] - $overviewBefore['registered_count'], 14);
    assertEq('overview available +4 (B1:1, B2:1, B4:2)', $overviewFinal['available_count'] - $overviewBefore['available_count'], 4);
    assertEq('overview online_held +3', $overviewFinal['online_held_count'] - $overviewBefore['online_held_count'], 3);
    assertEq('overview completed_sold +6', $overviewFinal['completed_sold_count'] - $overviewBefore['completed_sold_count'], 6);
    assertEq('overview online_revenue +43500c', $overviewFinal['online_revenue_cents'] - $overviewBefore['online_revenue_cents'], 43500);
    assertEq('overview remaining_revenue +38000c', $overviewFinal['remaining_revenue_cents'] - $overviewBefore['remaining_revenue_cents'], 38000);

    echo "== Registration/sales workflow still intact (bundle lock + walk-in) ==\n";
    assertEq('bundle lock predicate true for B1 (has completed sale)', sales_bundle_is_locked($pdo, $b1), true);
    assertEq('bundle lock predicate false for B6 (no sales)', sales_bundle_is_locked($pdo, $b6), false);
    $p15 = bpMakeProduct($pdo, $suffix, $b6, 1, 'SHORTS', '40.00');
    $walkinKey2 = 'bpt-walkin-' . $suffix . '-02';
    $created['request_keys'][] = $walkinKey2;
    $res2 = sales_complete_walkin($pdo, $adminId, [$p15['barcode']], 4000, [4000], $walkinKey2);
    assertEq('fresh walk-in sale on B6 ok', $res2['ok'] ?? false, true);
    if (!empty($res2['sale']['id'])) {
        $created['sale_ids'][] = (int) $res2['sale']['id'];
    }
    $m6b = metricsFor($pdo, $b6);
    assertEq('B6 walkin revenue after sale = 4000c', $m6b['walkin_revenue_cents'], 4000);
    assertEq('B6 gross profit = 4000-3000 = 1000c', $m6b['gross_profit_cents'], 1000);
    assertEq('B6 bundle now locked', sales_bundle_is_locked($pdo, $b6), true);
    /* Idempotent replay of the same key must not double-count. */
    $res2b = sales_complete_walkin($pdo, $adminId, [$p15['barcode']], 4000, [4000], $walkinKey2);
    assertEq('idempotent replay ok', ($res2b['ok'] ?? false) && !empty($res2b['idempotent']), true);
    $m6c = metricsFor($pdo, $b6);
    assertEq('B6 revenue unchanged after idempotent replay', $m6c['walkin_revenue_cents'], 4000);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    /* ---- Cleanup: remove ONLY our fixtures, in FK-safe order. ---- */
    try {
        $prodIds  = array_map('intval', $created['products']);
        $orderIds = array_map('intval', $created['orders']);
        $saleIds  = array_map('intval', $created['sale_ids']);
        $bundleIds = array_map('intval', $created['bundles']);

        if ($prodIds) {
            $in = implode(',', $prodIds);
            $pdo->exec("DELETE FROM sales_transaction_items WHERE product_id IN ({$in})");
        }
        if ($saleIds) {
            $in = implode(',', $saleIds);
            $pdo->exec("DELETE FROM sales_transactions WHERE id IN ({$in})");
        }
        if ($orderIds) {
            $in = implode(',', $orderIds);
            /* Any ONLINE sale header still referencing these orders. */
            $pdo->exec("DELETE FROM sales_transactions WHERE order_id IN ({$in})");
            $pdo->exec("DELETE FROM order_items WHERE order_id IN ({$in})");
            $pdo->exec("DELETE FROM payments WHERE order_id IN ({$in})");
            $pdo->exec("DELETE FROM orders WHERE id IN ({$in})");
        }
        if ($prodIds) {
            $in = implode(',', $prodIds);
            $pdo->exec("DELETE FROM products WHERE id IN ({$in})");
        }
        if ($bundleIds) {
            $in = implode(',', $bundleIds);
            $pdo->exec("DELETE FROM purchase_bundles WHERE id IN ({$in})");
        }
        if ($created['customer']) {
            $pdo->prepare('DELETE FROM customers WHERE id = :id')->execute([':id' => (int) $created['customer']]);
        }
        if ($created['admin']) {
            $pdo->prepare('DELETE FROM admins WHERE id = :id')->execute([':id' => (int) $created['admin']]);
        }
        echo "cleanup: done\n";
    } catch (Throwable $e) {
        echo "cleanup: WARNING - " . $e->getMessage() . "\n";
    }
}

echo "\n";
echo "Ran {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
