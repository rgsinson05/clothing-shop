<?php

/*
 * Hopia Fits - Phase 1 fulfillment & shipping DB-backed tests
 * (DEVELOPER-RUN).
 *
 * Runs the REAL Phase 1 shipping logic (includes/shipping.php) and a
 * faithful replica of the checkout order-creation validation against
 * the dedicated local test database clothing_shop_bundle_test ONLY.
 * It:
 *   - refuses to run on the web (CLI only);
 *   - reuses the .env host/user/password but FORCES the database name
 *     to clothing_shop_bundle_test, and hard-aborts if the connected
 *     database is anything else;
 *   - requires migrations 001-009 AND 010 to already be applied
 *     (010 extends shipments.delivery_method with STORE_PICKUP and
 *     payments.payment_method with CASH_ON_PICKUP);
 *   - creates clearly-labelled, self-contained fixtures (a test
 *     customer, cart, products, and orders) and removes them again at
 *     the end, in a finally block, whether tests pass or fail.
 *
 * Covered behaviours:
 *   - default/available fulfillment methods and their labels;
 *   - region -> island mapping for every region in the location data;
 *   - shipping rates per island group (Luzon 80 / Visayas 100 /
 *     Mindanao 120) and zero for Store Pickup;
 *   - server-side rejection of invalid fulfillment methods, unknown
 *     regions, and unsupported shipping amounts;
 *   - full courier and pickup order creation (orders + order_items +
 *     payments + shipments rows) with correct persisted subtotal,
 *     shipping_fee, and total_amount, including the PHP 500 + 80 = 580
 *     example and pickup 500 + 0 = 500;
 *   - "never trust the client": fees/totals are derived from the
 *     server-side resolver, never accepted from POST data;
 *   - inventory holds: purchased products flip AVAILABLE -> SOLD and
 *     cart items are removed (existing checkout behaviour preserved);
 *   - shipping never enters sales/merchandise figures: an ONLINE
 *     completed-sale header created for a delivered+received courier
 *     order totals merchandise only.
 *
 * It never mutates live/original products. Run it with:
 *
 *   php database/tests/phase1_fulfillment_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/shipping.php';
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
    fwrite(STDERR, "Refusing to run: connected to '{$connectedDb}', expected '" . TEST_DB_NAME . "'.\n");
    exit(2);
}

/* Require migration 010 capabilities. */
try {
    $shipCol = $pdo->query("SHOW COLUMNS FROM shipments LIKE 'delivery_method'")->fetch();
    $payCol = $pdo->query("SHOW COLUMNS FROM payments LIKE 'payment_method'")->fetch();
    $shipType = is_array($shipCol) ? (string) ($shipCol['Type'] ?? '') : '';
    $payType = is_array($payCol) ? (string) ($payCol['Type'] ?? '') : '';
    if (strpos($shipType, 'STORE_PICKUP') === false || strpos($payType, 'CASH_ON_PICKUP') === false) {
        fwrite(STDERR, "Migration 010 is not applied (ENUM values missing).\n");
        fwrite(STDERR, "Apply database/migrations/010_fulfillment_shipping.sql to " . TEST_DB_NAME . " first.\n");
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Tables shipments / payments are missing or unreadable.\n");
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
    'customer' => null,
    'cart' => null,
    'products' => [],
    'orders' => [],
];

/**
 * Register one fixture product owned by this test run.
 */
function makeProduct(PDO $pdo, string $suffix, int $seq, string $price): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO products (name, category, condition_label, price, status)
         VALUES (:name, :cat, :cond, :price, :status)'
    );
    $stmt->execute([
        ':name' => "PHASE1 {$suffix} item {$seq}",
        ':cat' => 'SHIRTS',
        ':cond' => 'Test',
        ':price' => $price,
        ':status' => 'AVAILABLE',
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Faithful replica of customer/checkout.php order creation for the
 * given submitted form values. Returns the same shaped result the
 * page would produce: either ['ok'=>true, 'order_id'=>int] or
 * ['ok'=>false, 'errors'=>string[]]. Client POST fields for prices
 * are deliberately NOT accepted, matching the page.
 */
function placeOrder(PDO $pdo, array $form): array
{
    $errors = [];

    $isPickupOrder = $form['delivery_method'] === 'STORE_PICKUP';

    if ($form['shipping_name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if ($form['shipping_phone'] === '') {
        $errors[] = 'Phone number is required.';
    }

    $requiredAddressFields = [
        'region', 'province', 'city_municipality', 'barangay',
        'house_unit_building', 'street'
    ];

    if (!$isPickupOrder) {
        foreach ($requiredAddressFields as $field) {
            if ($form[$field] === '') {
                $errors[] = $field . ' is required.';
            }
        }
    }

    $fulfillment = shipping_resolve_fulfillment(
        $form['delivery_method'],
        $form['region']
    );
    if (!$fulfillment['ok']) {
        $errors[] = $fulfillment['message'];
    }

    $expectedPayment = $isPickupOrder ? 'CASH_ON_PICKUP' : 'COD';
    if ($form['payment_method'] !== $expectedPayment) {
        $errors[] = 'Invalid payment method for the selected fulfillment.';
    }

    if (count($errors) > 0) {
        return ['ok' => false, 'errors' => $errors];
    }

    try {
        $pdo->beginTransaction();

        $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE customer_id = :cid LIMIT 1');
        $cartStmt->execute([':cid' => $form['customer_id']]);
        $cart = $cartStmt->fetch();
        if ($cart === false) {
            $pdo->rollBack();
            return ['ok' => false, 'errors' => ['Your cart is empty.']];
        }

        $lockedStmt = $pdo->prepare(
            'SELECT p.id AS product_id, p.name, p.size, p.color, p.price, p.status, p.retired_at
             FROM cart_items ci
             INNER JOIN products p ON p.id = ci.product_id
             WHERE ci.cart_id = :cart_id
             ORDER BY ci.created_at ASC, ci.id ASC
             FOR UPDATE'
        );
        $lockedStmt->execute([':cart_id' => (int) $cart['id']]);
        $lockedItems = $lockedStmt->fetchAll();

        if (count($lockedItems) === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'errors' => ['Your cart is empty.']];
        }

        $subtotalCents = 0;
        foreach ($lockedItems as $item) {
            $subtotalCents += (int) round((float) $item['price'] * 100);
        }

        $shippingFeeCents = $fulfillment['ok'] ? (int) $fulfillment['rate_cents'] : 0;
        $totalCents = $subtotalCents + $shippingFeeCents;

        $shippingAddress = $isPickupOrder
            ? 'Store Pickup - Hopia\'s Ukay-Ukay, Plaridel Street, Roxas City'
            : implode(', ', [
                $form['region'], $form['province'], $form['city_municipality'],
                $form['barangay'], $form['house_unit_building'], $form['street']
            ]);

        $orderStmt = $pdo->prepare(
            'INSERT INTO orders (customer_id, status, cancellation_status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address)
             VALUES (:cid, :st, :cs, :sub, :fee, :tot, :sn, :sp, :sa)'
        );
        $orderStmt->execute([
            ':cid' => $form['customer_id'],
            ':st' => 'PENDING',
            ':cs' => 'NONE',
            ':sub' => number_format($subtotalCents / 100, 2, '.', ''),
            ':fee' => number_format($shippingFeeCents / 100, 2, '.', ''),
            ':tot' => number_format($totalCents / 100, 2, '.', ''),
            ':sn' => $form['shipping_name'],
            ':sp' => $form['shipping_phone'],
            ':sa' => $shippingAddress,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, size, color, unit_price, quantity, subtotal)
             VALUES (:oid, :pid, :pn, :sz, :co, :up, 1, :sub)'
        );
        foreach ($lockedItems as $item) {
            $itemStmt->execute([
                ':oid' => $orderId,
                ':pid' => (int) $item['product_id'],
                ':pn' => $item['name'],
                ':sz' => $item['size'],
                ':co' => $item['color'],
                ':up' => number_format((float) $item['price'], 2, '.', ''),
                ':sub' => number_format((float) $item['price'], 2, '.', ''),
            ]);
        }

        $paymentStmt = $pdo->prepare(
            'INSERT INTO payments (order_id, payment_method, payment_status)
             VALUES (:oid, :pm, :ps)'
        );
        $paymentStmt->execute([
            ':oid' => $orderId,
            ':pm' => $isPickupOrder ? 'CASH_ON_PICKUP' : 'COD',
            ':ps' => 'PENDING',
        ]);

        $shipmentStmt = $pdo->prepare(
            'INSERT INTO shipments (order_id, shipment_status, delivery_method)
             VALUES (:oid, :ss, :dm)'
        );
        $shipmentStmt->execute([
            ':oid' => $orderId,
            ':ss' => 'NOT_SHIPPED',
            ':dm' => $form['delivery_method'],
        ]);

        $productIds = array_map(static function (array $item): int {
            return (int) $item['product_id'];
        }, $lockedItems);
        $in = implode(',', array_fill(0, count($productIds), '?'));
        $soldStmt = $pdo->prepare("UPDATE products SET status = 'SOLD' WHERE status = 'AVAILABLE' AND id IN ({$in})");
        $soldStmt->execute($productIds);
        if ($soldStmt->rowCount() !== count($productIds)) {
            throw new RuntimeException('products update failed');
        }

        $delStmt = $pdo->prepare("DELETE FROM cart_items WHERE cart_id = ? AND product_id IN ({$in})");
        $delStmt->execute(array_merge([$cart['id']], $productIds));

        $pdo->commit();
        return ['ok' => true, 'order_id' => $orderId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'errors' => ['transaction failed: ' . $e->getMessage()]];
    }
}

try {
    /* ---- Fixtures ---- */
    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => 'Phase', ':l' => 'One', ':e' => "phase1+{$suffix}@test.local", ':p' => 'x']);
    $created['customer'] = (int) $pdo->lastInsertId();
    $customerId = $created['customer'];

    $pdo->prepare('INSERT INTO carts (customer_id) VALUES (:cid)')
        ->execute([':cid' => $customerId]);
    $created['cart'] = (int) $pdo->lastInsertId();
    $cartId = $created['cart'];

    function addToCart(PDO $pdo, int $cartId, int $productId): void
    {
        $pdo->prepare('INSERT INTO cart_items (cart_id, product_id) VALUES (:c, :p)')
            ->execute([':c' => $cartId, ':p' => $productId]);
    }

    echo "== 1. Fulfillment methods & defaults ==\n";
    $methods = shipping_fulfillment_methods();
    assertEq('1.1 exactly two methods offered', count($methods), 2);
    assertTrue('1.2 LOCAL_COURIER labeled', isset($methods['LOCAL_COURIER']) && $methods['LOCAL_COURIER'] === 'Local Courier');
    assertTrue('1.3 STORE_PICKUP labeled', isset($methods['STORE_PICKUP']) && $methods['STORE_PICKUP'] === 'Store Pickup');
    assertEq('1.4 LOCAL_COURIER is the first (default) method', array_key_first($methods), 'LOCAL_COURIER');

    echo "== 2. Region -> island mapping (all 17 regions) ==\n";
    $regionNames = [
        'National Capital Region', 'Cordillera Administrative Region', 'Ilocos Region',
        'Cagayan Valley', 'Central Luzon', 'Calabarzon', 'Mimaropa', 'Bicol Region',
        'Western Visayas', 'Central Visayas', 'Eastern Visayas',
        'Zamboanga Peninsula', 'Northern Mindanao', 'Davao Region', 'Soccsksargen',
        'Caraga', 'Bangsamoro Autonomous Region'
    ];
    $luzon = ['National Capital Region', 'Cordillera Administrative Region', 'Ilocos Region',
        'Cagayan Valley', 'Central Luzon', 'Calabarzon', 'Mimaropa', 'Bicol Region'];
    $visayas = ['Western Visayas', 'Central Visayas', 'Eastern Visayas'];
    $mindanao = ['Zamboanga Peninsula', 'Northern Mindanao', 'Davao Region', 'Soccsksargen',
        'Caraga', 'Bangsamoro Autonomous Region'];
    $mapOk = true;
    foreach ($regionNames as $region) {
        $expected = in_array($region, $luzon, true) ? 'LUZON'
            : (in_array($region, $visayas, true) ? 'VISAYAS' : 'MINDANAO');
        if (shipping_island_for_region($region) !== $expected) {
            $mapOk = false;
            echo "  wrong mapping for {$region}\n";
        }
    }
    assertTrue('2.1 every region maps to the expected island group', $mapOk);
    assertEq('2.2 unknown region returns null', shipping_island_for_region('Atlantis'), null);
    assertEq('2.3 blank region returns null', shipping_island_for_region('  '), null);

    echo "== 3. Shipping rates ==\n";
    assertEq('3.1 Luzon rate is 80.00', shipping_rate_cents_for_region('Calabarzon'), 8000);
    assertEq('3.2 Visayas rate is 100.00', shipping_rate_cents_for_region('Western Visayas'), 10000);
    assertEq('3.3 Mindanao rate is 120.00', shipping_rate_cents_for_region('Davao Region'), 12000);
    assertEq('3.4 NCR (Luzon) rate is 80.00', shipping_rate_cents_for_region('National Capital Region'), 8000);
    assertEq('3.5 unknown region rate is null (never a guess)', shipping_rate_cents_for_region('Nowhere'), null);
    assertEq('3.6 unsupported island rate is null', shipping_rate_cents_for_island('VISAYAS_EXTRA'), null);

    echo "== 4. Fulfillment resolution & server-side validation ==\n";
    $r = shipping_resolve_fulfillment('LOCAL_COURIER', 'Ilocos Region');
    assertTrue('4.1 courier + valid region resolves', $r['ok'] && $r['rate_cents'] === 8000);
    $r = shipping_resolve_fulfillment('STORE_PICKUP', '');
    assertTrue('4.2 pickup needs no region and is always 0', $r['ok'] && $r['rate_cents'] === 0);
    $r = shipping_resolve_fulfillment('STORE_PICKUP', 'Davao Region');
    assertTrue('4.3 pickup stays 0 even with a region present', $r['ok'] && $r['rate_cents'] === 0);
    $r = shipping_resolve_fulfillment('TELEPORT', 'Ilocos Region');
    assertTrue('4.4 invalid method rejected', !$r['ok'] && $r['reason'] === 'invalid_method');
    $r = shipping_resolve_fulfillment('LOCAL_COURIER', '');
    assertTrue('4.5 courier without region rejected', !$r['ok'] && $r['reason'] === 'missing_region');
    $r = shipping_resolve_fulfillment('LOCAL_COURIER', 'Mars Region');
    assertTrue('4.6 courier with unknown region rejected', !$r['ok'] && $r['reason'] === 'unknown_region');

    echo "== 5. Courier order: Luzon 500 + 80 = 580 ==\n";
    $p1 = makeProduct($pdo, $suffix, 1, '250.00');
    $p2 = makeProduct($pdo, $suffix, 2, '250.00');
    $created['products'] = [$p1, $p2];
    addToCart($pdo, $cartId, $p1);
    addToCart($pdo, $cartId, $p2);

    $result = placeOrder($pdo, [
        'customer_id' => $customerId,
        'shipping_name' => 'Phase One Tester',
        'shipping_phone' => '0900',
        'delivery_method' => 'LOCAL_COURIER',
        'region' => 'Central Luzon',
        'province' => 'Pampanga',
        'city_municipality' => 'San Fernando',
        'barangay' => 'San Jose',
        'house_unit_building' => 'Unit 5',
        'street' => 'Rizal St',
        'payment_method' => 'COD',
    ]);
    assertTrue('5.1 Luzon courier order placed', $result['ok']);
    if ($result['ok']) {
        $created['orders'][] = (int) $result['order_id'];
        $order = $pdo->query('SELECT subtotal, shipping_fee, total_amount FROM orders WHERE id = ' . (int) $result['order_id'])->fetch();
        assertEq('5.2 stored subtotal is 500.00', $order['subtotal'], '500.00');
        assertEq('5.3 stored shipping_fee is 80.00', $order['shipping_fee'], '80.00');
        assertEq('5.4 stored total_amount is 580.00', $order['total_amount'], '580.00');

        $shipment = $pdo->query('SELECT delivery_method FROM shipments WHERE order_id = ' . (int) $result['order_id'])->fetch();
        assertEq('5.5 shipment delivery_method is LOCAL_COURIER', $shipment['delivery_method'], 'LOCAL_COURIER');

        $payment = $pdo->query('SELECT payment_method FROM payments WHERE order_id = ' . (int) $result['order_id'])->fetch();
        assertEq('5.6 payment method is COD', $payment['payment_method'], 'COD');

        $statuses = $pdo->query("SELECT status FROM products WHERE id IN ({$p1},{$p2}) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        assertEq('5.7 purchased products flipped to SOLD (inventory hold preserved)', implode(',', $statuses), 'SOLD,SOLD');
        $cartLeft = (int) $pdo->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = {$cartId}")->fetchColumn();
        assertEq('5.8 cart emptied after order', $cartLeft, 0);
    }

    echo "== 6. Courier orders: Visayas 100 / Mindanao 120 ==\n";
    $p3 = makeProduct($pdo, $suffix, 3, '500.00');
    $created['products'][] = $p3;
    addToCart($pdo, $cartId, $p3);
    $result = placeOrder($pdo, [
        'customer_id' => $customerId,
        'shipping_name' => 'Phase One Tester',
        'shipping_phone' => '0900',
        'delivery_method' => 'LOCAL_COURIER',
        'region' => 'Central Visayas',
        'province' => 'Cebu',
        'city_municipality' => 'Cebu City',
        'barangay' => 'Lahug',
        'house_unit_building' => 'B1',
        'street' => 'Gorordo Ave',
        'payment_method' => 'COD',
    ]);
    assertTrue('6.1 Visayas order placed', $result['ok']);
    if ($result['ok']) {
        $created['orders'][] = (int) $result['order_id'];
        $order = $pdo->query('SELECT shipping_fee, total_amount FROM orders WHERE id = ' . (int) $result['order_id'])->fetch();
        assertEq('6.2 Visayas fee 100.00', $order['shipping_fee'], '100.00');
        assertEq('6.3 Visayas total 600.00', $order['total_amount'], '600.00');
    }

    $p4 = makeProduct($pdo, $suffix, 4, '500.00');
    $created['products'][] = $p4;
    addToCart($pdo, $cartId, $p4);
    $result = placeOrder($pdo, [
        'customer_id' => $customerId,
        'shipping_name' => 'Phase One Tester',
        'shipping_phone' => '0900',
        'delivery_method' => 'LOCAL_COURIER',
        'region' => 'Davao Region',
        'province' => 'Davao del Sur',
        'city_municipality' => 'Davao City',
        'barangay' => 'Buhangin',
        'house_unit_building' => 'Lot 9',
        'street' => 'McArthur Hwy',
        'payment_method' => 'COD',
    ]);
    assertTrue('6.4 Mindanao order placed', $result['ok']);
    if ($result['ok']) {
        $created['orders'][] = (int) $result['order_id'];
        $order = $pdo->query('SELECT shipping_fee, total_amount FROM orders WHERE id = ' . (int) $result['order_id'])->fetch();
        assertEq('6.5 Mindanao fee 120.00', $order['shipping_fee'], '120.00');
        assertEq('6.6 Mindanao total 620.00', $order['total_amount'], '620.00');
    }

    echo "== 7. Store Pickup order: 500 + 0 = 500 ==\n";
    $p5 = makeProduct($pdo, $suffix, 5, '500.00');
    $created['products'][] = $p5;
    addToCart($pdo, $cartId, $p5);
    $result = placeOrder($pdo, [
        'customer_id' => $customerId,
        'shipping_name' => 'Phase One Tester',
        'shipping_phone' => '0900',
        'delivery_method' => 'STORE_PICKUP',
        'region' => '',
        'province' => '',
        'city_municipality' => '',
        'barangay' => '',
        'house_unit_building' => '',
        'street' => '',
        'payment_method' => 'CASH_ON_PICKUP',
    ]);
    assertTrue('7.1 pickup order placed with no address fields', $result['ok']);
    if ($result['ok']) {
        $created['orders'][] = (int) $result['order_id'];
        $order = $pdo->query('SELECT subtotal, shipping_fee, total_amount, shipping_address FROM orders WHERE id = ' . (int) $result['order_id'])->fetch();
        assertEq('7.2 pickup subtotal 500.00', $order['subtotal'], '500.00');
        assertEq('7.3 pickup shipping_fee is 0.00', $order['shipping_fee'], '0.00');
        assertEq('7.4 pickup total_amount 500.00', $order['total_amount'], '500.00');
        assertTrue('7.5 pickup address records the shop', strpos($order['shipping_address'], "Hopia's Ukay-Ukay") !== false);

        $shipment = $pdo->query('SELECT delivery_method FROM shipments WHERE order_id = ' . (int) $result['order_id'])->fetch();
        assertEq('7.6 pickup shipment delivery_method is STORE_PICKUP', $shipment['delivery_method'], 'STORE_PICKUP');

        $payment = $pdo->query('SELECT payment_method FROM payments WHERE order_id = ' . (int) $result['order_id'])->fetch();
        assertEq('7.7 pickup payment method is CASH_ON_PICKUP (not courier COD)', $payment['payment_method'], 'CASH_ON_PICKUP');

        $statuses = $pdo->query("SELECT status FROM products WHERE id = {$p5}")->fetchAll(PDO::FETCH_COLUMN);
        assertEq('7.8 pickup still holds inventory (SOLD)', $statuses[0], 'SOLD');
    }

    echo "== 8. Validation rejections (stale/hidden values) ==\n";
    $p6 = makeProduct($pdo, $suffix, 6, '150.00');
    $created['products'][] = $p6;
    addToCart($pdo, $cartId, $p6);

    $result = placeOrder($pdo, [
        'customer_id' => $customerId,
        'shipping_name' => 'T', 'shipping_phone' => '0900',
        'delivery_method' => 'STORE_PICKUP',
        'region' => 'Davao Region', 'province' => 'x', 'city_municipality' => 'x',
        'barangay' => 'x', 'house_unit_building' => 'x', 'street' => 'x',
        'payment_method' => 'COD',
    ]);
    assertTrue('8.1 pickup with courier COD payment rejected', !$result['ok']);

    $result = placeOrder($pdo, [
        'customer_id' => $customerId,
        'shipping_name' => 'T', 'shipping_phone' => '0900',
        'delivery_method' => 'LOCAL_COURIER',
        'region' => '', 'province' => '', 'city_municipality' => '',
        'barangay' => '', 'house_unit_building' => '', 'street' => '',
        'payment_method' => 'COD',
    ]);
    assertTrue('8.2 courier with no address rejected (address still required)', !$result['ok']);

    $result = placeOrder($pdo, [
        'customer_id' => $customerId,
        'shipping_name' => 'T', 'shipping_phone' => '0900',
        'delivery_method' => 'LOCAL_COURIER',
        'region' => 'Fake Region', 'province' => 'x', 'city_municipality' => 'x',
        'barangay' => 'x', 'house_unit_building' => 'x', 'street' => 'x',
        'payment_method' => 'COD',
    ]);
    assertTrue('8.3 courier with unknown region rejected', !$result['ok']);
    $status = (string) $pdo->query("SELECT status FROM products WHERE id = {$p6}")->fetchColumn();
    assertEq('8.4 rejected order did not flip product to SOLD', $status, 'AVAILABLE');
    $orderCount = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE shipping_phone = '0900' AND subtotal = '150.00'")->fetchColumn();
    assertEq('8.5 rejected submissions created no order rows', $orderCount, 0);

    echo "== 9. Never trust the client: manipulated fees/totals ==\n";
    /* The replica (like the real page) accepts NO fee/total fields.
       Verify a hostile POST carrying fee/total fields cannot influence
       the stored amounts: place a Mindanao order "claiming" fee 0. */
    $result = placeOrder($pdo, [
        'customer_id' => $customerId,
        'shipping_name' => 'T', 'shipping_phone' => '0900',
        'delivery_method' => 'LOCAL_COURIER',
        'region' => 'Davao Region', 'province' => 'x', 'city_municipality' => 'x',
        'barangay' => 'x', 'house_unit_building' => 'x', 'street' => 'x',
        'payment_method' => 'COD',
        'shipping_fee' => '0.00',
        'total_amount' => '150.00',
    ]);
    assertTrue('9.1 hostile order still placed (server recomputes)', $result['ok']);
    if ($result['ok']) {
        $created['orders'][] = (int) $result['order_id'];
        $order = $pdo->query('SELECT shipping_fee, total_amount FROM orders WHERE id = ' . (int) $result['order_id'])->fetch();
        assertEq('9.2 server-stored fee is 120.00 regardless of POST', $order['shipping_fee'], '120.00');
        assertEq('9.3 server-stored total is 270.00 regardless of POST', $order['total_amount'], '270.00');
    }

    echo "== 10. Shipping never enters completed-sale revenue ==\n";
    /* Simulate the Phase C flow for the Luzon order (580 = 500 + 80):
       mark DELIVERED + received, create the ONLINE header via the real
       sales_confirm_receipt_online(), and confirm merchandise-only. */
    $luzonOrderId = (int) $created['orders'][0];
    $pdo->prepare("UPDATE orders SET status = 'DELIVERED' WHERE id = :oid")->execute([':oid' => $luzonOrderId]);
    $confirm = sales_confirm_receipt_online($pdo, $customerId, $luzonOrderId);
    assertTrue('10.1 receipt confirmation ok', !empty($confirm['ok']));
    if (!empty($confirm['sale']['id'])) {
        $saleTotal = (string) $pdo->query('SELECT total_amount FROM sales_transactions WHERE id = ' . (int) $confirm['sale']['id'])->fetchColumn();
        assertEq('10.2 ONLINE sale total is merchandise-only 500.00 (shipping 80 excluded)', $saleTotal, '500.00');
    }
} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception: " . $e->getMessage() . "\n";
} finally {
    /* ---- Cleanup fixtures (children first; FKs are RESTRICT). ---- */
    try {
        if (!empty($created['orders'])) {
            $oids = implode(',', array_map('intval', $created['orders']));
            $pdo->exec("DELETE FROM sales_transaction_items WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id IN ({$oids}))");
            $pdo->exec("DELETE FROM sales_transactions WHERE order_id IN ({$oids})");
            $pdo->exec("DELETE FROM payments WHERE order_id IN ({$oids})");
            $pdo->exec("DELETE FROM shipments WHERE order_id IN ({$oids})");
            $pdo->exec("DELETE FROM order_items WHERE order_id IN ({$oids})");
            $pdo->exec("DELETE FROM orders WHERE id IN ({$oids})");
        }
        if (!empty($created['products'])) {
            $ids = implode(',', array_map('intval', $created['products']));
            $pdo->exec("DELETE FROM sales_transaction_items WHERE product_id IN ({$ids})");
            $pdo->exec("DELETE FROM products WHERE id IN ({$ids})");
        }
        if (!empty($created['cart'])) {
            $pdo->exec('DELETE FROM cart_items WHERE cart_id = ' . (int) $created['cart']);
            $pdo->exec('DELETE FROM carts WHERE id = ' . (int) $created['cart']);
        }
        if (!empty($created['customer'])) {
            $pdo->exec('DELETE FROM customers WHERE id = ' . (int) $created['customer']);
        }
        echo "\nFixtures cleaned up.\n";
    } catch (Throwable $e) {
        echo "\nWARNING: fixture cleanup failed: " . $e->getMessage() . "\n";
        echo "Fixture suffix for manual cleanup: {$suffix}\n";
    }
}

echo "\nRan {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
