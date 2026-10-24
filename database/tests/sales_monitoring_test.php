<?php

/*
 * Hopia Fits - Sales Monitoring regression tests (DEVELOPER-RUN).
 *
 * Runs the REAL Sales Monitoring queries (includes/sales.php:
 * sales_monitor_list, sales_monitor_today_cents, sales_monitor_detail,
 * sales_monitor_like_param) against the dedicated local test database
 * clothing_shop_bundle_test ONLY. It:
 *   - refuses to run on the web (CLI only);
 *   - reuses the .env host/user/password but FORCES the database name to
 *     clothing_shop_bundle_test, and hard-aborts if the connected database
 *     is anything else;
 *   - requires migrations 007, 008, 009, AND 010 to already be applied;
 *   - creates clearly-labelled, self-contained fixtures through the REAL
 *     sale paths (sales_complete_walkin for walk-in, an order + shipment +
 *     payment + sales_confirm_receipt_online for online) and removes ONLY
 *     those fixtures again at the end, in a finally block, whether tests
 *     pass or fail.
 *
 * It never sells or mutates live/original products and never changes
 * product status, payments, or order lifecycle fields on non-fixtures.
 *
 * Run it with:
 *   php database/tests/sales_monitoring_test.php
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
    fwrite(STDERR, "Create the test database and apply migrations 001-010 first.\n");
    fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
    exit(2);
}

/* Hard guard: never run against anything but the test database. */
$connectedDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($connectedDb !== TEST_DB_NAME) {
    fwrite(STDERR, "Refusing: connected to '{$connectedDb}', expected '" . TEST_DB_NAME . "'.\n");
    exit(2);
}

/* Require the columns the monitoring queries read. */
try {
    $pdo->query('SELECT order_id, payment_method, payment_status FROM payments LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "payments table missing/incompatible (migrations 001/010).\n");
    exit(2);
}
try {
    $pdo->query('SELECT sale_channel FROM sales_transactions LIMIT 1');
    $pdo->query('SELECT sale_id, unit_price FROM sales_transaction_items LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "sales_transactions / sales_transaction_items missing (migrations 007/009).\n");
    exit(2);
}
try {
    $pdo->query('SELECT received_at FROM orders LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "orders.received_at missing (migration 009).\n");
    exit(2);
}

/* ---- Assertion helpers (mirrors the house test style) ---- */
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

/* ---- Fixture helpers ---- */
$suffix = bin2hex(random_bytes(4));
$token = 'SMON' . $suffix; /* unique token embedded in every fixture name */
$created = [
    'admin' => null,
    'customer' => null,
    'bundle' => null,
    'products' => [],
    'orders' => [],
    'sales' => [],
];

function sale_pad(int $id): string
{
    return 'SALE-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function makeBundledProduct(PDO $pdo, int $bundleId, string $name, string $status, string $price): array
{
    global $created;
    static $seq = 0;
    $seq++;
    $barcode = 'HOPESM-' . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT) . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    $pdo->prepare(
        'INSERT INTO products (name, category, gender, condition_label, price, status, bundle_id, bundle_item_number, barcode_value)
         VALUES (:name, :cat, :gender, :cond, :price, :status, :bid, :seq, :barcode)'
    )->execute([
        ':name' => $name, ':cat' => 'SHIRTS', ':gender' => 'MEN', ':cond' => 'Test',
        ':price' => $price, ':status' => $status, ':bid' => $bundleId, ':seq' => $seq, ':barcode' => $barcode,
    ]);
    $id = (int) $pdo->lastInsertId();
    $created['products'][] = $id;
    return ['id' => $id, 'barcode' => $barcode];
}

function makeOrder(PDO $pdo, int $customerId, string $status, string $fee): int
{
    global $created;
    $pdo->prepare(
        'INSERT INTO orders (customer_id, status, cancellation_status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address)
         VALUES (:cid, :st, :cx, :sub, :fee, :tot, :sn, :sp, :sa)'
    )->execute([
        ':cid' => $customerId, ':st' => $status, ':cx' => 'NONE',
        ':sub' => '0.00', ':fee' => $fee, ':tot' => '0.00',
        ':sn' => 'SMON Tester', ':sp' => '0900', ':sa' => 'Test address',
    ]);
    $id = (int) $pdo->lastInsertId();
    $created['orders'][] = $id;
    return $id;
}

function addOrderItem(PDO $pdo, int $orderId, int $productId, string $name, string $price): int
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
        ':up' => $price,
        ':sub' => $price,
    ]);
    return (int) $pdo->lastInsertId();
}

function makePayment(PDO $pdo, int $orderId, string $method, string $status): void
{
    $pdo->prepare(
        'INSERT INTO payments (order_id, payment_method, payment_status) VALUES (:oid, :pm, :ps)'
    )->execute([':oid' => $orderId, ':pm' => $method, ':ps' => $status]);
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

function syncOrderTotals(PDO $pdo, int $orderId, string $fee): void
{
    $pdo->prepare(
        'UPDATE orders o
         SET o.subtotal = (SELECT COALESCE(SUM(oi.subtotal), 0) FROM order_items oi WHERE oi.order_id = o.id),
             o.total_amount = (SELECT COALESCE(SUM(oi.subtotal), 0) FROM order_items oi WHERE oi.order_id = o.id) + :fee
         WHERE o.id = :oid'
    )->execute([':oid' => $orderId, ':fee' => $fee]);
}

try {
    /* Baseline "today" state must be captured BEFORE the fixtures below are
       created, so the KPI deltas measure only this run's fixtures even though
       the shared test DB may hold unrelated completed-sale rows. */
    $beforeToday = sales_monitor_today_cents($pdo);

    /* ---- Fixtures ---- */
    $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (:n, :e, :p)')
        ->execute([':n' => $token . ' Cashier', ':e' => "smon+{$suffix}@test.local", ':p' => 'x']);
    $created['admin'] = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => $token, ':l' => 'Juan', ':e' => "smoncust+{$suffix}@test.local", ':p' => 'x']);
    $created['customer'] = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'SHIRTS', ':cap' => '500.00', ':q' => 100]);
    $created['bundle'] = (int) $pdo->lastInsertId();
    $bundleId = $created['bundle'];

    /* Products for online orders (SOLD hold, like the checkout flow leaves them). */
    $pOnline1 = makeBundledProduct($pdo, $bundleId, $token . ' Online Shirt', 'SOLD', '150.00');
    $pOnline2 = makeBundledProduct($pdo, $bundleId, $token . ' Online Pants', 'SOLD', '250.00');
    $pOnline3 = makeBundledProduct($pdo, $bundleId, $token . ' Online Hoodie', 'SOLD', '650.00');

    /* Products for walk-in sales (AVAILABLE so the real sale path accepts them). */
    $pWalkin1 = makeBundledProduct($pdo, $bundleId, $token . ' Walkin Top', 'AVAILABLE', '120.00');
    $pWalkin2 = makeBundledProduct($pdo, $bundleId, $token . ' Walkin Shorts', 'AVAILABLE', '180.00');
    $pWalkin3 = makeBundledProduct($pdo, $bundleId, $token . ' Walkin Old', 'AVAILABLE', '90.00');

    /* ---- ONLINE completed sale #1: two items, COD PENDING, shipping 80 ---- */
    $orderOnline1 = makeOrder($pdo, $created['customer'], 'DELIVERED', '80.00');
    addOrderItem($pdo, $orderOnline1, $pOnline1['id'], $token . ' Online Shirt', '150.00');
    addOrderItem($pdo, $orderOnline1, $pOnline2['id'], $token . ' Online Pants', '250.00');
    syncOrderTotals($pdo, $orderOnline1, '80.00'); /* subtotal 400.00, total 480.00 */
    makeShipment($pdo, $orderOnline1, 'TRACK-SMON-1-' . $suffix, 'DELIVERED');
    makePayment($pdo, $orderOnline1, 'COD', 'PENDING');
    $rOnline1 = sales_confirm_receipt_online($pdo, $created['customer'], $orderOnline1);
    if (empty($rOnline1['ok']) || empty($rOnline1['sale'])) {
        throw new RuntimeException('Online fixture 1 not confirmed: ' . ($rOnline1['message'] ?? '?'));
    }
    $online1SaleId = (int) $rOnline1['sale']['id'];
    $created['sales'][] = $online1SaleId;

    /* ---- ONLINE completed sale #2: one item, GCASH PAID, shipping 120 ---- */
    $orderOnline2 = makeOrder($pdo, $created['customer'], 'DELIVERED', '120.00');
    addOrderItem($pdo, $orderOnline2, $pOnline3['id'], $token . ' Online Hoodie', '650.00');
    syncOrderTotals($pdo, $orderOnline2, '120.00'); /* subtotal 650.00, total 770.00 */
    makeShipment($pdo, $orderOnline2, 'TRACK-SMON-2-' . $suffix, 'DELIVERED');
    makePayment($pdo, $orderOnline2, 'GCASH', 'PAID');
    $rOnline2 = sales_confirm_receipt_online($pdo, $created['customer'], $orderOnline2);
    if (empty($rOnline2['ok']) || empty($rOnline2['sale'])) {
        throw new RuntimeException('Online fixture 2 not confirmed: ' . ($rOnline2['message'] ?? '?'));
    }
    $online2SaleId = (int) $rOnline2['sale']['id'];
    $created['sales'][] = $online2SaleId;

    /* ---- ONLINE order that is NOT a completed sale (delivered, no receipt) ---- */
    $orderPending = makeOrder($pdo, $created['customer'], 'DELIVERED', '80.00');
    addOrderItem($pdo, $orderPending, $pOnline2['id'], $token . ' No Receipt', '250.00');
    syncOrderTotals($pdo, $orderPending, '80.00');
    makeShipment($pdo, $orderPending, 'TRACK-SMON-NR-' . $suffix, 'DELIVERED');
    makePayment($pdo, $orderPending, 'GCASH', 'PENDING'); /* never verified, never received */

    /* ---- Walk-in sales #1 and #2 via the real confirm path ---- */
    $walkin1Key = 'kw-in-1-' . $suffix;
    $rWalkin1 = sales_complete_walkin(
        $pdo,
        $created['admin'],
        [$pWalkin1['barcode'], $pWalkin2['barcode']],
        30000,
        [12000, 18000],
        $walkin1Key
    );
    if (empty($rWalkin1['ok'])) {
        throw new RuntimeException('Walk-in fixture 1 failed: ' . ($rWalkin1['message'] ?? '?'));
    }
    $walkin1SaleId = (int) $rWalkin1['sale']['id'];
    $created['sales'][] = $walkin1SaleId;

    $rWalkin2 = sales_complete_walkin(
        $pdo,
        $created['admin'],
        [$pWalkin3['barcode']],
        9000,
        [9000],
        'kw-in-2-' . $suffix
    );
    if (empty($rWalkin2['ok'])) {
        throw new RuntimeException('Walk-in fixture 2 failed: ' . ($rWalkin2['message'] ?? '?'));
    }
    $walkin2SaleId = (int) $rWalkin2['sale']['id'];
    $created['sales'][] = $walkin2SaleId;

    /* Move #2 to a past day so date-range filtering is exercised. */
    $pdo->prepare('UPDATE sales_transactions SET sold_at = :at WHERE id = :id')
        ->execute([':at' => '2020-01-15 10:30:00', ':id' => $walkin2SaleId]);

    /* ============================================================
       1. TODAY'S KPI QUERY
       ============================================================ */
    echo "== 1. sales_monitor_today_cents (four KPI cards) ==\n";

    /* The baseline was captured before fixture creation above. */
    $afterToday = sales_monitor_today_cents($pdo);

    /* Two walk-in sales today, but #2 was moved to 2020 -> only #1 counts.
       #1 = 120 + 180 = 300.00 cash-equivalent merchandise. */
    assertEq('1 walk-in count delta is 1', $afterToday['WALKIN']['count'] - $beforeToday['WALKIN']['count'], 1);
    assertEq('1 walk-in revenue delta is 30000 cents', $afterToday['WALKIN']['cents'] - $beforeToday['WALKIN']['cents'], 30000);

    /* Two online completions today -> counts 2; revenue 400 + 650 = 1050.00,
       shipping 80 + 120 = 200 must NOT inflate revenue. */
    assertEq('1 online count delta is 2', $afterToday['ONLINE']['count'] - $beforeToday['ONLINE']['count'], 2);
    assertEq('1 online revenue delta is 105000 cents', $afterToday['ONLINE']['cents'] - $beforeToday['ONLINE']['cents'], 105000);

    /* The delivered order without receipt confirmation adds nothing. */
    $noReceiptToday = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders o
         LEFT JOIN sales_transactions st ON st.order_id = o.id
         WHERE o.id = " . (int) $orderPending . " AND st.id IS NULL"
    )->fetchColumn();
    assertEq('1 delivered-but-unreceived order is not a completed sale', $noReceiptToday, 1);

    /* ============================================================
       2. SEARCH
       ============================================================ */
    echo "== 2. sales_monitor_list search ==\n";

    $total = 0;
    $bySaleCode = sales_monitor_list($pdo, ['search' => sale_pad($walkin1SaleId)], $total, 100, 0);
    assertTrue('2 search by SALE- code finds the row', count($bySaleCode) >= 1);
    $saleCodeIds = array_map('intval', array_column($bySaleCode, 'id'));
    assertTrue('2 SALE- search includes the walk-in sale', in_array($walkin1SaleId, $saleCodeIds, true));

    $byOrderNum = sales_monitor_list($pdo, ['search' => (string) $orderOnline1], $total, 100, 0);
    assertTrue('2 order-number search finds the completed sale', count($byOrderNum) >= 1);
    $orderNumIds = array_map('intval', array_column($byOrderNum, 'id'));
    assertTrue('2 order-number search includes online#1', in_array($online1SaleId, $orderNumIds, true));

    $byCustomer = sales_monitor_list($pdo, ['search' => $token], $total, 100, 0);
    assertEq('2 search by customer name token finds 4 rows', count($byCustomer), 4);

    $byCashier = sales_monitor_list($pdo, ['search' => $token . ' Cashier'], $total, 100, 0);
    assertEq('2 search by cashier name finds 2 rows', count($byCashier), 2);

    $byRawId = sales_monitor_list($pdo, ['search' => (string) $online2SaleId], $total, 100, 0);
    assertTrue('2 raw-id search finds the sale', count($byRawId) >= 1);
    $rawIdIds = array_map('intval', array_column($byRawId, 'id'));
    assertTrue('2 raw-id search includes online#2', in_array($online2SaleId, $rawIdIds, true));

    $noMatch = sales_monitor_list($pdo, ['search' => 'ZZZ-NO-SUCH-' . $suffix], $total, 100, 0);
    assertEq('2 no-match search returns empty', count($noMatch), 0);
    assertEq('2 no-match total is 0', $total, 0);

    /* LIKE wildcards are escaped: a bare % or _ must not match everything. */
    $wildCard = sales_monitor_list($pdo, ['search' => '%'], $total, 100, 0);
    assertEq('2 bare %% matches no literal records (escaped)', count($wildCard), 0);

    /* ============================================================
       3. CHANNEL + DATE + COMBINED FILTERS
       ============================================================ */
    echo "== 3. channel/date/combined filters ==\n";

    $walkinOnly = sales_monitor_list($pdo, ['channel' => 'WALKIN', 'search' => $token], $total, 100, 0);
    foreach ($walkinOnly as $row) {
        assertEq('3 walk-in filter only returns WALKIN rows', (string) $row['sale_channel'], 'WALKIN');
    }
    assertEq('3 walk-in channel rows', count($walkinOnly), 2);

    $onlineOnly = sales_monitor_list($pdo, ['channel' => 'ONLINE', 'search' => $token], $total, 100, 0);
    foreach ($onlineOnly as $row) {
        assertEq('3 online filter only returns ONLINE rows', (string) $row['sale_channel'], 'ONLINE');
    }
    assertEq('3 online channel rows', count($onlineOnly), 2);

    $pastDate = sales_monitor_list($pdo, [
        'search' => $token,
        'date_from' => '2020-01-01',
        'date_to' => '2020-01-31',
    ], $total, 100, 0);
    assertTrue('3 date range finds the 2020 sale', count($pastDate) >= 1);
    $pastDateIds = array_map('intval', array_column($pastDate, 'id'));
    assertTrue('3 date range includes the 2020 walk-in', in_array($walkin2SaleId, $pastDateIds, true));

    $combined = sales_monitor_list($pdo, [
        'search' => $token,
        'channel' => 'ONLINE',
        'date_from' => date('Y-m-d'),
        'date_to' => date('Y-m-d'),
    ], $total, 100, 0);
    assertEq('3 combined search+channel+today returns 2', count($combined), 2);
    foreach ($combined as $row) {
        assertEq('3 combined channel is ONLINE', (string) $row['sale_channel'], 'ONLINE');
    }

    $combinedEmpty = sales_monitor_list($pdo, [
        'search' => $token,
        'channel' => 'WALKIN',
        'date_from' => date('Y-m-d'),
        'date_to' => date('Y-m-d'),
    ], $total, 100, 0);
    assertEq('3 walk-in today is 1 (other sale is 2020)', count($combinedEmpty), 1);

    /* ============================================================
       4. PAGINATION ACROSS THE FILTERED SET
       ============================================================ */
    echo "== 4. limit/offset stay correct under filters ==\n";

    $pageTotal = 0;
    $page1 = sales_monitor_list($pdo, ['search' => $token], $pageTotal, 2, 0);
    assertEq('4 first page has 2 rows', count($page1), 2);
    assertEq('4 total across pages is 4', $pageTotal, 4);
    $page2 = sales_monitor_list($pdo, ['search' => $token], $pageTotal, 2, 2);
    assertEq('4 second page has 2 rows', count($page2), 2);
    assertEq('4 total across pages still 4', $pageTotal, 4);
    $seen = array_merge($page1, $page2);
    $ids = array_map('intval', array_column($seen, 'id'));
    $unique = array_unique($ids);
    assertEq('4 no duplicate transactions across pages', count($unique), count($ids));

    /* ============================================================
       5. LIST PAYMENT / STATUS + TOTALS
       ============================================================ */
    echo "== 5. payment, status, totals in the list ==\n";

    $online1Row = [];
    foreach (sales_monitor_list($pdo, ['search' => (string) $orderOnline1], $total, 100, 0) as $row) {
        if ((int) $row['id'] === $online1SaleId) {
            $online1Row = $row;
            break;
        }
    }
    assertEq('5 online#1 payment_method is COD', $online1Row['payment_method'] ?? '', 'COD');
    assertEq('5 online#1 payment_status is PENDING', $online1Row['payment_status'] ?? '', 'PENDING');
    assertEq('5 online#1 total is 400.00 (merchandise only)', (string) $online1Row['total_amount'], '400.00');
    assertEq('5 online#1 item_count is 2', (int) $online1Row['item_count'], 2);
    assertEq('5 online#1 customer name present', trim((string) ($online1Row['customer_name'] ?? '')), $token . ' Juan');

    $online2Row = [];
    foreach (sales_monitor_list($pdo, ['search' => (string) $orderOnline2], $total, 100, 0) as $row) {
        if ((int) $row['id'] === $online2SaleId) {
            $online2Row = $row;
            break;
        }
    }
    assertEq('5 online#2 payment_method is GCASH', $online2Row['payment_method'] ?? '', 'GCASH');
    assertEq('5 online#2 payment_status is PAID', $online2Row['payment_status'] ?? '', 'PAID');
    assertEq('5 online#2 total is 650.00 (merchandise only)', (string) $online2Row['total_amount'], '650.00');

    $walkin1Row = [];
    foreach (sales_monitor_list($pdo, ['search' => sale_pad($walkin1SaleId)], $total, 100, 0) as $row) {
        if ((int) $row['id'] === $walkin1SaleId) {
            $walkin1Row = $row;
            break;
        }
    }
    assertEq('5 walk-in payment_method is NULL (not fabricated)', $walkin1Row['payment_method'] ?? 'x', 'x');
    assertEq('5 walk-in payment_status is NULL (not fabricated)', $walkin1Row['payment_status'] ?? 'y', 'y');
    assertEq('5 walk-in cashier name present', (string) ($walkin1Row['cashier'] ?? ''), $token . ' Cashier');
    assertEq('5 walk-in total is 300.00', (string) $walkin1Row['total_amount'], '300.00');

    /* ============================================================
       6. DETAIL / RECEIPT VIEW
       ============================================================ */
    echo "== 6. sales_monitor_detail snapshots ==\n";

    $detailOnline = sales_monitor_detail($pdo, $online1SaleId);
    assertTrue('6 online detail loads', $detailOnline !== null);
    assertEq('6 online channel', $detailOnline['channel'], 'ONLINE');
    assertEq('6 online total_cents is 40000 (shipping excluded)', $detailOnline['total_cents'], 40000);
    assertEq('6 online order_number', $detailOnline['order_number'], $orderOnline1);
    assertEq('6 online customer', $detailOnline['customer_name'], $token . ' Juan');
    assertEq('6 online payment_method', $detailOnline['payment_method'], 'COD');
    assertEq('6 online payment_status', $detailOnline['payment_status'], 'PENDING');
    assertEq('6 online item count', count($detailOnline['items']), 2);
    assertEq('6 online first item price 15000', $detailOnline['items'][0]['unit_price_cents'], 15000);
    assertEq('6 online second item price 25000', $detailOnline['items'][1]['unit_price_cents'], 25000);

    $detailWalkin = sales_monitor_detail($pdo, $walkin1SaleId);
    assertTrue('6 walk-in detail loads', $detailWalkin !== null);
    assertEq('6 walk-in channel', $detailWalkin['channel'], 'WALKIN');
    assertEq('6 walk-in total_cents', $detailWalkin['total_cents'], 30000);
    assertEq('6 walk-in cashier', $detailWalkin['cashier'], $token . ' Cashier');
    assertNull('6 walk-in order_number', $detailWalkin['order_number']);
    assertNull('6 walk-in payment_method', $detailWalkin['payment_method']);
    assertNull('6 walk-in payment_status', $detailWalkin['payment_status']);
    assertEq('6 walk-in item count', count($detailWalkin['items']), 2);
    assertEq('6 walk-in first item price 12000', $detailWalkin['items'][0]['unit_price_cents'], 12000);
    assertEq('6 walk-in second item price 18000', $detailWalkin['items'][1]['unit_price_cents'], 18000);

    /* Historical price snapshot survives later product reprice. */
    $pdo->prepare('UPDATE products SET price = :p WHERE id = :id')
        ->execute([':p' => '9999.00', ':id' => $pWalkin1['id']]);
    $detailRepriced = sales_monitor_detail($pdo, $walkin1SaleId);
    assertEq('6 reprice does not change the stored snapshot price', $detailRepriced['items'][0]['unit_price_cents'], 12000);

    /* Unknown id -> null. */
    assertNull('6 unknown sale id returns null', sales_monitor_detail($pdo, 2147483647));

    /* ============================================================
       7. LIKE PARAM ESCAPING
       ============================================================ */
    echo "== 7. sales_monitor_like_param ==\n";

    assertEq('7 plain term wrapped', sales_monitor_like_param('abc'), '%abc%');
    assertEq('7 percent is escaped', sales_monitor_like_param('50%'), '%50\\%%');
    assertEq('7 underscore is escaped', sales_monitor_like_param('_x_'), '%\\_x\\_%');
    assertEq('7 backslash is escaped', sales_monitor_like_param('a\\b'), '%a\\\\b%');
    assertEq('7 whitespace trimmed', sales_monitor_like_param('  hi  '), '%hi%');

    echo "\nFixtures cleaned up (list below if cleanup ran).\n";
} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception: " . $e->getMessage() . "\n";
    echo "        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    /* ---- Cleanup fixtures (children first; respects FK actions) ---- */
    try {
        $orderIds = array_map('intval', $created['orders']);
        $customerIds = $created['customer'] !== null ? [(int) $created['customer']] : [];
        $productIds = array_map('intval', $created['products']);

        if (!empty($orderIds)) {
            $oidList = implode(',', $orderIds);

            /* Completed sales first (sales_transactions.order_id FK). */
            $saleIds = array_map('intval', $created['sales']);
            if (!empty($saleIds)) {
                $sidList = implode(',', $saleIds);
                $pdo->exec("DELETE FROM sales_transaction_items WHERE sale_id IN ({$sidList})");
                $pdo->exec("DELETE FROM sales_transactions WHERE id IN ({$sidList})");
            }
            /* Any sale not in $created['sales'] linked to these orders. */
            $pdo->exec(
                "DELETE sti FROM sales_transaction_items sti
                 INNER JOIN sales_transactions st ON st.id = sti.sale_id
                 WHERE st.order_id IN ({$oidList})"
            );
            $pdo->exec("DELETE FROM sales_transactions WHERE order_id IN ({$oidList})");

            $pdo->exec("DELETE FROM order_item_verifications WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM payments WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM shipments WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM order_items WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM orders WHERE id IN ({$oidList})");
        }

        /* Walk-in fixtures referenced products by product_id — the sale
           details are already gone; products may have been set SOLD. */
        if (!empty($productIds)) {
            $pidList = implode(',', $productIds);
            $pdo->exec("DELETE FROM order_item_verifications WHERE product_id IN ({$pidList})");
            $pdo->exec("DELETE FROM products WHERE id IN ({$pidList})");
        }

        if (!empty($created['bundle'])) {
            $pdo->exec("DELETE FROM purchase_bundles WHERE id = " . (int) $created['bundle']);
        }

        if (!empty($customerIds)) {
            $cidList = implode(',', $customerIds);
            $pdo->exec("DELETE FROM customer_feedback WHERE customer_id IN ({$cidList})");
            $pdo->exec("DELETE FROM customers WHERE id IN ({$cidList})");
        }

        if (!empty($created['admin'])) {
            $pdo->exec("DELETE FROM order_item_verifications WHERE verified_by = " . (int) $created['admin']);
            $pdo->exec("DELETE FROM admins WHERE id = " . (int) $created['admin']);
        }

        echo "Fixtures cleaned up.\n";
    } catch (Throwable $e) {
        echo "\nWARNING: fixture cleanup failed: " . $e->getMessage() . "\n";
        echo "Fixture suffix for manual cleanup: {$suffix}\n";
    }
}

echo "\nRan {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);