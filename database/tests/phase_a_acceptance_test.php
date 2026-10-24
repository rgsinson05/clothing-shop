<?php

/*
 * Hopia Fits - Phase A DB-backed acceptance tests (DEVELOPER-RUN).
 *
 * Runs the REAL Phase A sale logic (includes/sales.php) against the dedicated
 * local test database clothing_shop_bundle_test ONLY. It:
 *   - refuses to run on the web (CLI only);
 *   - reuses the .env host/user/password but FORCES the database name to
 *     clothing_shop_bundle_test, and hard-aborts if the connected database is
 *     anything else;
 *   - requires migration 007 to already be applied;
 *   - creates clearly-labelled, self-contained fixtures (a test admin, bundle,
 *     products, and a simulated pending online order) and removes them again
 *     at the end, in a finally block, whether tests pass or fail.
 *
 * Each sale call to sales_complete_walkin() carries a previewPricesCents
 * array (one integer-cent value per barcode, index-aligned) so the per-item
 * staleness check can be exercised. Tests use real DB prices for correct
 * submissions and intentionally wrong prices for stale-price regression tests.
 *
 * It never sells or mutates live/original products. Run it with:
 *
 *   php database/tests/phase_a_acceptance_test.php
 *
 * NOTE: camera scanning (test B&D #6/#7) and label scanning are browser/device
 * behaviours and are not exercised here; they are listed as manual checks in
 * the final report.
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
    fwrite(STDERR, "Create the test database and apply migrations 001-007 first.\n");
    fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
    exit(2);
}

/* Hard guard: never run against anything but the test database. */
$connectedDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($connectedDb !== TEST_DB_NAME) {
    fwrite(STDERR, "Refusing to run: connected to '{$connectedDb}', expected '" . TEST_DB_NAME . "'.\n");
    exit(2);
}

/* Require migration 007 to be applied. */
try {
    $pdo->query('SELECT 1 FROM sales_transactions LIMIT 1');
    $pdo->query('SELECT 1 FROM sales_transaction_items LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Tables sales_transactions / sales_transaction_items are missing.\n");
    fwrite(STDERR, "Apply database/migrations/007_add_sales_transactions.sql to " . TEST_DB_NAME . " first.\n");
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

$suffix = bin2hex(random_bytes(4));
$created = [
    'admin' => null,
    'customer' => null,
    'bundle' => null,
    'products' => [],
    'order' => null,
    'sale_ids' => [],
    'request_keys' => [],
];

/* Register a bundled product fixture with a generated barcode. */
function makeProduct(PDO $pdo, int $bundleId, int $seq, string $name, string $price): array
{
    $barcode = 'HOPEBUNDLE-' . str_pad((string) $bundleId, 6, '0', STR_PAD_LEFT) . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare(
        'INSERT INTO products (name, category, gender, condition_label, price, status, bundle_id, bundle_item_number, barcode_value)
         VALUES (:name, :cat, :gender, :cond, :price, :status, :bid, :seq, :barcode)'
    );
    $stmt->execute([
        ':name' => $name, ':cat' => 'SHIRTS', ':gender' => 'MEN', ':cond' => 'Test',
        ':price' => $price, ':status' => 'AVAILABLE', ':bid' => $bundleId, ':seq' => $seq, ':barcode' => $barcode,
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'barcode' => $barcode, 'price' => $price];
}

try {
    /* ---- Fixtures ---- */
    $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (:n, :e, :p)')
        ->execute([':n' => 'Phase A Tester', ':e' => "phasea+{$suffix}@test.local", ':p' => 'x']);
    $created['admin'] = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => 'Phase', ':l' => 'Tester', ':e' => "phaseacust+{$suffix}@test.local", ':p' => 'x']);
    $created['customer'] = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO purchase_bundles (category, capital_total, original_quantity) VALUES (:c, :cap, :q)')
        ->execute([':c' => 'SHIRTS', ':cap' => '100.00', ':q' => 10]);
    $created['bundle'] = (int) $pdo->lastInsertId();
    $bundleId = $created['bundle'];

    $p1 = makeProduct($pdo, $bundleId, 1, "PHASE_A {$suffix} shirt A", '250.00');
    $p2 = makeProduct($pdo, $bundleId, 2, "PHASE_A {$suffix} shirt B", '250.00');
    $p3 = makeProduct($pdo, $bundleId, 3, "PHASE_A {$suffix} shorts C", '300.00');
    $p4 = makeProduct($pdo, $bundleId, 4, "PHASE_A {$suffix} extra D", '150.00');
    $p5 = makeProduct($pdo, $bundleId, 5, "PHASE_A {$suffix} retired E", '199.00');
    $p6 = makeProduct($pdo, $bundleId, 6, "PHASE_A {$suffix} online F", '250.00');
    $p7 = makeProduct($pdo, $bundleId, 7, "PHASE_A {$suffix} double G", '120.00');
    /* p7p/p7q/p7r: fresh AVAILABLE products used for J1 stale-price tests so
       they are guaranteed not to have been sold by any prior test. */
    $p7p = makeProduct($pdo, $bundleId, 10, "PHASE_A {$suffix} price Jp", '250.00');
    $p7q = makeProduct($pdo, $bundleId, 11, "PHASE_A {$suffix} price Jq", '250.00');
    $p7r = makeProduct($pdo, $bundleId, 12, "PHASE_A {$suffix} price Jr", '300.00');
    /* p8: fresh AVAILABLE product for the L single-price-change test. */
    $p8 = makeProduct($pdo, $bundleId, 13, "PHASE_A {$suffix} price L", '150.00');
    $created['products'] = [$p1['id'], $p2['id'], $p3['id'], $p4['id'], $p5['id'], $p6['id'], $p7['id'], $p7p['id'], $p7q['id'], $p7r['id'], $p8['id']];

    /* p5 retired. */
    $pdo->prepare('UPDATE products SET retired_at = NOW() WHERE id = :id')->execute([':id' => $p5['id']]);

    /* p6 simulates an ONLINE hold: a PENDING order marks the product SOLD. */
    $pdo->prepare(
        'INSERT INTO orders (customer_id, status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address)
         VALUES (:cid, :st, :sub, :fee, :tot, :sn, :sp, :sa)'
    )->execute([
        ':cid' => $created['customer'], ':st' => 'PENDING', ':sub' => '250.00', ':fee' => '0.00', ':tot' => '250.00',
        ':sn' => 'Phase Tester', ':sp' => '0900', ':sa' => 'Test address',
    ]);
    $created['order'] = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, subtotal)
         VALUES (:oid, :pid, :pn, :up, 1, :sub)'
    )->execute([
        ':oid' => $created['order'], ':pid' => $p6['id'], ':pn' => $p6['barcode'], ':up' => '250.00', ':sub' => '250.00',
    ]);
    $pdo->prepare("UPDATE products SET status = 'SOLD' WHERE id = :id")->execute([':id' => $p6['id']]);

    $adminId = $created['admin'];

    echo "== A. Barcode & draft (preview) ==\n";
    $salesBefore = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();

    $r = sales_lookup_barcode($pdo, $p1['barcode']);
    assertTrue('A1 valid AVAILABLE barcode returns product', !empty($r['ok']) && $r['product']['price'] === '250.00');
    assertEq('A2 unknown barcode rejected', sales_lookup_barcode($pdo, "NOPE-{$suffix}")['reason'] ?? '', 'not_found');
    assertEq('A3 SOLD (online hold) product rejected', sales_lookup_barcode($pdo, $p6['barcode'])['reason'] ?? '', 'not_available');
    assertEq('A4 retired product rejected', sales_lookup_barcode($pdo, $p5['barcode'])['reason'] ?? '', 'retired');
    assertEq('A5 duplicate-in-draft rejected', sales_lookup_barcode($pdo, $p1['barcode'], [$p1['barcode']])['reason'] ?? '', 'duplicate');

    $salesAfterLookups = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    assertEq('A6 lookups create no sale rows', $salesAfterLookups, $salesBefore);

    echo "== B. Successful multi-item sale ==\n";
    $keyMain = 'phaseA-' . $suffix . '-main-0001';
    $created['request_keys'][] = $keyMain;
    $sale = sales_complete_walkin($pdo, $adminId, [$p1['barcode'], $p2['barcode'], $p3['barcode']], 80000, [25000, 25000, 30000], $keyMain);
    assertTrue('B1 sale ok and not idempotent', !empty($sale['ok']) && $sale['idempotent'] === false);
    if (!empty($sale['sale']['id'])) {
        $saleId = (int) $sale['sale']['id'];
        $created['sale_ids'][] = $saleId;

        $hdrCount = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE id = {$saleId}")->fetchColumn();
        $itemCount = (int) $pdo->query("SELECT COUNT(*) FROM sales_transaction_items WHERE sale_id = {$saleId}")->fetchColumn();
        assertEq('B2 exactly one header', $hdrCount, 1);
        assertEq('B3 exactly three detail rows', $itemCount, 3);

        $soldCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM products WHERE id IN ({$p1['id']},{$p2['id']},{$p3['id']}) AND status = 'SOLD'"
        )->fetchColumn();
        assertEq('B4 all three products now SOLD', $soldCount, 3);

        $total = (string) $pdo->query("SELECT total_amount FROM sales_transactions WHERE id = {$saleId}")->fetchColumn();
        assertEq('B5 saved total is 800.00', $total, '800.00');

        $snapSum = (string) $pdo->query("SELECT COALESCE(SUM(unit_price),0) FROM sales_transaction_items WHERE sale_id = {$saleId}")->fetchColumn();
        assertEq('B6 total equals sum of item snapshots', number_format((float) $snapSum, 2, '.', ''), '800.00');

        $loaded = sales_load_transaction($pdo, $saleId);
        assertEq('B7 monitoring shows one transaction with 3 items', count($loaded['items']), 3);

        assertTrue('B8 bundle is now locked by predicate', sales_bundle_is_locked($pdo, $bundleId) === true);
    } else {
        assertTrue('B* sale produced an id', false);
    }

    echo "== C. Atomicity & partial prevention ==\n";
    $keyAlready = 'phaseA-' . $suffix . '-already-01';
    $created['request_keys'][] = $keyAlready;
    $r = sales_complete_walkin($pdo, $adminId, [$p1['barcode']], 25000, [25000], $keyAlready);
    assertTrue('C1 selling an already-sold product is rejected', empty($r['ok']));

    $keyPartial = 'phaseA-' . $suffix . '-partial-01';
    $created['request_keys'][] = $keyPartial;
    $r = sales_complete_walkin($pdo, $adminId, [$p4['barcode'], $p1['barcode']], 40000, [15000, 25000], $keyPartial);
    assertTrue('C2 available+unavailable request is rejected', empty($r['ok']));
    $p4status = (string) $pdo->query("SELECT status FROM products WHERE id = {$p4['id']}")->fetchColumn();
    assertEq('C2 the available product was NOT sold (no partial)', $p4status, 'AVAILABLE');
    $p4items = (int) $pdo->query("SELECT COUNT(*) FROM sales_transaction_items WHERE product_id = {$p4['id']}")->fetchColumn();
    assertEq('C2 no detail row for the available product', $p4items, 0);
    $partialHeader = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE request_key = " . $pdo->quote($keyPartial))->fetchColumn();
    assertEq('C2 no header created for the failed request', $partialHeader, 0);

    echo "== D. Idempotency ==\n";
    $again = sales_complete_walkin($pdo, $adminId, [$p1['barcode'], $p2['barcode'], $p3['barcode']], 80000, [25000, 25000, 30000], $keyMain);
    assertTrue('D1 same key returns the existing sale (idempotent)', !empty($again['ok']) && $again['idempotent'] === true);
    assertEq('D1 same key returns the SAME sale id', (int) ($again['sale']['id'] ?? 0), (int) $created['sale_ids'][0]);
    $hdrForKey = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE request_key = " . $pdo->quote($keyMain))->fetchColumn();
    assertEq('D2 still exactly one header for the key', $hdrForKey, 1);

    /* key_conflict: same key with a DIFFERENT barcode set creates a new sale
       (not a conflict), because the request key now implies the item set. */
    $keyDiff = 'phaseA-' . $suffix . '-diff-items';
    $created['request_keys'][] = $keyDiff;
    $diff = sales_complete_walkin($pdo, $adminId, [$p4['barcode']], 15000, [15000], $keyDiff);
    assertTrue('D3 new key + different items creates new sale', !empty($diff['ok']) && ($diff['idempotent'] ?? false) === false);
    if (!empty($diff['sale']['id'])) { $created['sale_ids'][] = (int) $diff['sale']['id']; }
    $p4statusDiff = (string) $pdo->query("SELECT status FROM products WHERE id = {$p4['id']}")->fetchColumn();
    assertEq('D3 different-item sale completed successfully (p4 SOLD)', $p4statusDiff, 'SOLD');
    $p4itemsDiff = (int) $pdo->query("SELECT COUNT(*) FROM sales_transaction_items WHERE product_id = {$p4['id']}")->fetchColumn();
    assertEq('D3 exactly one detail row for p4', $p4itemsDiff, 1);
    $origSale2 = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE id = " . (int) $created['sale_ids'][0])->fetchColumn();
    assertEq('D3 original sale header still exists', $origSale2, 1);
    $p4statusAfter = (string) $pdo->query("SELECT status FROM products WHERE id = {$p4['id']}")->fetchColumn();
    assertEq('D3 p4 remains SOLD after new-key sale', $p4statusAfter, 'SOLD');

    echo "== E. Rapid double-submit (same new key) ==\n";
    $keyDouble = 'phaseA-' . $suffix . '-double-01';
    $created['request_keys'][] = $keyDouble;
    $first = sales_complete_walkin($pdo, $adminId, [$p7['barcode']], 12000, [12000], $keyDouble);
    $second = sales_complete_walkin($pdo, $adminId, [$p7['barcode']], 12000, [12000], $keyDouble);
    assertTrue('E1 first submit completes', !empty($first['ok']) && $first['idempotent'] === false);
    assertTrue('E2 second submit is idempotent', !empty($second['ok']) && $second['idempotent'] === true);
    if (!empty($first['sale']['id'])) {
        $created['sale_ids'][] = (int) $first['sale']['id'];
    }
    $doubleHeaders = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE request_key = " . $pdo->quote($keyDouble))->fetchColumn();
    assertEq('E3 exactly one completed transaction', $doubleHeaders, 1);
    $p7itemsNow = (int) $pdo->query("SELECT COUNT(*) FROM sales_transaction_items WHERE product_id = {$p7['id']}")->fetchColumn();
    assertEq('E4 the product has exactly one detail row', $p7itemsNow, 1);

    echo "== F. Competing re-sale cannot duplicate ==\n";
    $keyCompete = 'phaseA-' . $suffix . '-compete-1';
    $created['request_keys'][] = $keyCompete;
    $r = sales_complete_walkin($pdo, $adminId, [$p7['barcode']], 12000, [12000], $keyCompete);
    assertTrue('F1 re-selling the same garment (new key) is rejected', empty($r['ok']));
    $p7itemsFinal = (int) $pdo->query("SELECT COUNT(*) FROM sales_transaction_items WHERE product_id = {$p7['id']}")->fetchColumn();
    assertEq('F2 still exactly one detail row for that garment', $p7itemsFinal, 1);

    echo "== G. Reporting ==\n";
    $saleId = (int) $created['sale_ids'][0];
    assertEq('G1 multi-item sale counts as one transaction', (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE id = {$saleId}")->fetchColumn(), 1);
    $hdrTotal = (string) $pdo->query("SELECT total_amount FROM sales_transactions WHERE id = {$saleId}")->fetchColumn();
    $sumSnap = (string) $pdo->query("SELECT COALESCE(SUM(unit_price),0) FROM sales_transaction_items WHERE sale_id = {$saleId}")->fetchColumn();
    assertEq('G2 total equals sum of saved item snapshots', number_format((float) $hdrTotal, 2, '.', ''), number_format((float) $sumSnap, 2, '.', ''));

    $onlineLinkedHeaders = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE order_id = " . (int) $created['order'])->fetchColumn();
    assertEq('G3 the pending online order created no completed-sale header', $onlineLinkedHeaders, 0);

    /* Walk-in revenue from our fixtures (by our keys) excludes the online order total of 250.
       keyMain=800 (p1+p2+p3) + keyDiff=150 (p4) + keyDouble=120 (p7) = 1070 */
    $keyList = implode(',', array_map([$pdo, 'quote'], $created['request_keys']));
    $walkinRevenue = (string) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales_transactions WHERE sale_channel='WALKIN' AND request_key IN ({$keyList})")->fetchColumn();
    assertEq('G3 walk-in revenue is 800+150+120=1070 (online 250 excluded)', number_format((float) $walkinRevenue, 2, '.', ''), '1070.00');

    $onlineRows = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE sale_channel='ONLINE' AND request_key IN ({$keyList})")->fetchColumn();
    assertEq('G5 no ONLINE sales rows created by the walk-in feature', $onlineRows, 0);

    echo "== H. Confirmation-layer SOLD (online-hold) rejection ==\n";
    $keySold = 'phaseA-' . $suffix . '-sold-confirm';
    $created['request_keys'][] = $keySold;
    $r = sales_complete_walkin($pdo, $adminId, [$p6['barcode']], 25000, [25000], $keySold);
    assertTrue('H1 SOLD product rejected at confirmation', empty($r['ok']));
    assertEq('H1 reason is not_available', $r['reason'] ?? '', 'not_available');
    $p6status = (string) $pdo->query("SELECT status FROM products WHERE id = {$p6['id']}")->fetchColumn();
    assertEq('H1 SOLD product status is unchanged', $p6status, 'SOLD');
    $p6items = (int) $pdo->query("SELECT COUNT(*) FROM sales_transaction_items WHERE product_id = {$p6['id']}")->fetchColumn();
    assertEq('H1 no sales_transaction_items detail row for SOLD product', $p6items, 0);
    $soldHeaders = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE request_key = " . $pdo->quote($keySold))->fetchColumn();
    assertEq('H1 no sale header created', $soldHeaders, 0);

    echo "== I. Confirmation-layer retired-product rejection ==\n";
    $keyRetired = 'phaseA-' . $suffix . '-retired-confirm';
    $created['request_keys'][] = $keyRetired;
    $r = sales_complete_walkin($pdo, $adminId, [$p5['barcode']], 19900, [19900], $keyRetired);
    assertTrue('I1 retired product rejected at confirmation', empty($r['ok']));
    assertEq('I1 reason is retired', $r['reason'] ?? '', 'retired');
    $p5status = (string) $pdo->query("SELECT status FROM products WHERE id = {$p5['id']}")->fetchColumn();
    assertEq('I1 retired product status is unchanged', $p5status, 'AVAILABLE'); // was never sold, just retired
    $retiredHeaders = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE request_key = " . $pdo->quote($keyRetired))->fetchColumn();
    assertEq('I1 no sale header created for retired product', $retiredHeaders, 0);

    echo "== J. Per-item stale-price: compensating changes ==\n";
    /* p7p=250, p7q=250, p7r=300 in DB (fresh, never sold).
       Submit as p7p=260, p7q=240, p7r=300. Preview total = 260+240+300 = 800.
       DB total = 250+250+300 = 800. Same aggregate, different per-item.
       Must be rejected with a 409 and fresh item prices. */
    $keyComp = 'phaseA-' . $suffix . '-price-comp';
    $created['request_keys'][] = $keyComp;
    $r = sales_complete_walkin($pdo, $adminId, [$p7p['barcode'], $p7q['barcode'], $p7r['barcode']], 80000, [26000, 24000, 30000], $keyComp);
    assertTrue('J1 compensating per-item price changes rejected', empty($r['ok']));
    assertEq('J1 reason is price_changed', $r['reason'] ?? '', 'price_changed');
    assertEq('J1 status is 409', ($r['status'] ?? 200), 409);
    assertTrue('J1 fresh items returned', !empty($r['items']) && count($r['items']) === 3);
    $priceByBarcode = array_column($r['items'], 'price', 'barcode');
    assertEq('J1 p7p fresh price is 250.00 (DB value)', $priceByBarcode[$p7p['barcode']] ?? '', '250.00');
    assertEq('J1 p7q fresh price is 250.00 (DB value)', $priceByBarcode[$p7q['barcode']] ?? '', '250.00');
    assertEq('J1 p7r fresh price is 300.00 (DB value)', $priceByBarcode[$p7r['barcode']] ?? '', '300.00');
    assertEq('J1 fresh total is 800.00', ($r['total'] ?? ''), '800.00');
    /* p7p/p7q/p7r must still be AVAILABLE (never marked SOLD) */
    $statuses = $pdo->query("SELECT status FROM products WHERE id IN ({$p7p['id']},{$p7q['id']},{$p7r['id']}) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    assertEq('J1 no product status changed', implode(',', $statuses), 'AVAILABLE,AVAILABLE,AVAILABLE');
    $compHeaders = (int) $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE request_key = " . $pdo->quote($keyComp))->fetchColumn();
    assertEq('J1 no sale header created', $compHeaders, 0);

    echo "== K. Per-item stale-price: corrected prices after refresh ==\n";
    /* Resubmit the same items with correct DB prices (from the J1 response). */
    $r2 = sales_complete_walkin($pdo, $adminId, [$p7p['barcode'], $p7q['barcode'], $p7r['barcode']], 80000, [25000, 25000, 30000], $keyComp);
    assertTrue('K1 corrected prices accepted', !empty($r2['ok']) && ($r2['idempotent'] ?? false) === false);
    if (!empty($r2['sale']['id'])) {
        $created['sale_ids'][] = (int) $r2['sale']['id'];
        $snapPrices = $pdo->query("SELECT barcode_value, unit_price FROM sales_transaction_items WHERE sale_id = " . (int) $r2['sale']['id'] . " ORDER BY barcode_value")->fetchAll(PDO::FETCH_KEY_PAIR);
        assertEq('K1 stored unit_price for p7p matches DB (250.00)', $snapPrices[$p7p['barcode']] ?? '', '250.00');
        assertEq('K1 stored unit_price for p7q matches DB (250.00)', $snapPrices[$p7q['barcode']] ?? '', '250.00');
        assertEq('K1 stored unit_price for p7r matches DB (300.00)', $snapPrices[$p7r['barcode']] ?? '', '300.00');
    }

    echo "== L. Per-item stale-price: single changed price (also changes total) ==\n";
    /* p8 = 150 in DB. Submit as 175 (price changed AND total changed). */
    $keySingle = 'phaseA-' . $suffix . '-price-single';
    $created['request_keys'][] = $keySingle;
    $r = sales_complete_walkin($pdo, $adminId, [$p8['barcode']], 17500, [17500], $keySingle);
    assertTrue('L1 single changed price rejected', empty($r['ok']));
    assertEq('L1 reason is price_changed', $r['reason'] ?? '', 'price_changed');
    assertEq('L1 fresh price for p8 is 150.00', ($r['items'][0]['price'] ?? '') ?? '', '150.00');
    $p8statusAfter = (string) $pdo->query("SELECT status FROM products WHERE id = {$p8['id']}")->fetchColumn();
    assertEq('L1 p8 status unchanged', $p8statusAfter, 'AVAILABLE');

    echo "== M. Idempotency: same key, different barcode set rejected as conflict ==\n";
    /* keyMain already sold p1+p2+p3. Try to reuse it for p4 alone. */
    $keyReuse = 'phaseA-' . $suffix . '-reuse-conflict';
    $created['request_keys'][] = $keyReuse;
    $r = sales_complete_walkin($pdo, $adminId, [$p4['barcode']], 15000, [15000], $keyMain);
    assertTrue('M1 same key + different barcodes rejected', empty($r['ok']));
    assertEq('M1 reason is key_conflict', $r['reason'] ?? '', 'key_conflict');
    assertEq('M1 status is 409', ($r['status'] ?? 200), 409);
    /* Original sale still intact */
    $origSale = $pdo->query("SELECT COUNT(*) FROM sales_transactions WHERE id = " . (int) $created['sale_ids'][0])->fetchColumn();
    assertEq('M1 original sale header still exists', $origSale, 1);
    $origItems = (int) $pdo->query("SELECT COUNT(*) FROM sales_transaction_items WHERE sale_id = " . (int) $created['sale_ids'][0])->fetchColumn();
    assertEq('M1 original sale still has 3 items', $origItems, 3);
    $p4statusReuse = (string) $pdo->query("SELECT status FROM products WHERE id = {$p4['id']}")->fetchColumn();
    assertEq('M1 p4 status unchanged (sold by D3, not by this rejected call)', $p4statusReuse, 'SOLD');
} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception: " . $e->getMessage() . "\n";
} finally {
    /* ---- Cleanup fixtures (children first; FKs are RESTRICT). ---- */
    try {
        if (!empty($created['products'])) {
            $ids = implode(',', array_map('intval', $created['products']));
            $pdo->exec("DELETE FROM sales_transaction_items WHERE product_id IN ({$ids})");
        }
        if (!empty($created['sale_ids'])) {
            $sids = implode(',', array_map('intval', $created['sale_ids']));
            $pdo->exec("DELETE FROM sales_transactions WHERE id IN ({$sids})");
        }
        if (!empty($created['request_keys'])) {
            $keyList = implode(',', array_map([$pdo, 'quote'], $created['request_keys']));
            $pdo->exec("DELETE FROM sales_transactions WHERE request_key IN ({$keyList})");
        }
        if (!empty($created['order'])) {
            $oid = (int) $created['order'];
            $pdo->exec("DELETE FROM order_items WHERE order_id = {$oid}");
            $pdo->exec("DELETE FROM orders WHERE id = {$oid}");
        }
        if (!empty($created['products'])) {
            $ids = implode(',', array_map('intval', $created['products']));
            $pdo->exec("DELETE FROM products WHERE id IN ({$ids})");
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
