<?php

/*
 * Hopia Fits - Phase 2 manual GCash payment + verification DB-backed tests
 * (DEVELOPER-RUN).
 *
 * Runs the REAL Phase 2 logic (includes/gcash.php, includes/payment.php) and
 * the shared shipping/sales helpers against the dedicated local test database
 * clothing_shop_bundle_test ONLY. It:
 *   - refuses to run on the web (CLI only);
 *   - reuses the .env host/user/password but FORCES the database name to
 *     clothing_shop_bundle_test, and hard-aborts if the connected database is
 *     anything else;
 *   - requires the payment/pickup/recovery schema through migration 013;
 *   - creates clearly-labelled, self-contained fixtures and removes ONLY
 *     those fixtures again at the end, in a finally block.
 *
 * It never mutates live/original products and only ever touches its own
 * fixture rows.
 *
 * Run it with:
 *   php database/tests/phase2_gcash_payment_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/gcash.php';
require_once __DIR__ . '/../../includes/payment.php';
require_once __DIR__ . '/../../includes/shipping.php';
require_once __DIR__ . '/../../includes/sales.php';

const TEST_DB_NAME = 'clothing_shop_bundle_test';

date_default_timezone_set('Asia/Manila');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "Refusing to run payment fixtures on a non-loopback database host.\n");
    exit(2);
}

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
    fwrite(STDERR, "The local test database must have the schema through migration 013.\n");
    fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
    exit(2);
}

/* Hard guard: never run against anything but the test database. */
$connectedDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($connectedDb !== TEST_DB_NAME) {
    fwrite(STDERR, "Refusing to run: connected to '{$connectedDb}', expected '" . TEST_DB_NAME . "'.\n");
    exit(2);
}

/* Read-only prerequisites; this runner never applies migrations. */
try {
    $payCol = $pdo->query("SHOW COLUMNS FROM payments LIKE 'payment_method'")->fetch();
    $payType = is_array($payCol) ? (string) ($payCol['Type'] ?? '') : '';
    if (strpos($payType, 'CASH_ON_PICKUP') === false) {
        fwrite(STDERR, "Migration 010 not applied (CASH_ON_PICKUP missing).\n");
        exit(2);
    }
    foreach (['gcash_reference', 'proof_path', 'submitted_at', 'verified_by', 'verified_at', 'rejection_reason', 'rejected_at', 'revision'] as $c) {
        $col = $pdo->query("SHOW COLUMNS FROM payments LIKE " . $pdo->quote($c))->fetch();
        if ($col === false) {
            fwrite(STDERR, "Payment schema through migration 013 is required (payments.{$c} missing). No fixtures written.\n");
            exit(2);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "payments table missing or unreadable: " . $e->getMessage() . "\n");
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
    'admin' => null,
    'customerA' => null,
    'customerB' => null,
    'orders' => [],
    'products' => [],
    'tmpfiles' => [],
];

/* ---- Fixture helpers ---- */
function makeOrder(PDO $pdo, int $customerId, string $status, string $subtotal, string $fee, string $total): int
{
    global $created;
    $pdo->prepare(
        'INSERT INTO orders (customer_id, status, cancellation_status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address)
         VALUES (:cid, :st, :cs, :sub, :fee, :tot, :sn, :sp, :sa)'
    )->execute([
        ':cid' => $customerId, ':st' => $status, ':cs' => 'NONE',
        ':sub' => $subtotal, ':fee' => $fee, ':tot' => $total,
        ':sn' => 'Phase2 Tester', ':sp' => '0900', ':sa' => 'Test address',
    ]);
    $id = (int) $pdo->lastInsertId();
    $created['orders'][] = $id;
    $pdo->prepare("INSERT INTO shipments (order_id, delivery_method, shipment_status) VALUES (:id, 'LOCAL_COURIER', 'NOT_SHIPPED')")
        ->execute([':id' => $id]);
    return $id;
}

function addOrderItem(PDO $pdo, int $orderId, ?int $productId, string $name, string $price): int
{
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, size, color, unit_price, quantity, subtotal)
         VALUES (:oid, :pid, :pn, :sz, :cl, :up, 1, :sub)'
    )->execute([
        ':oid' => $orderId, ':pid' => $productId, ':pn' => $name, ':sz' => 'M', ':cl' => 'Blue',
        ':up' => $price, ':sub' => $price,
    ]);
    return (int) $pdo->lastInsertId();
}

function makePayment(PDO $pdo, int $orderId, string $method, string $status): void
{
    $pdo->prepare(
        'INSERT INTO payments (order_id, payment_method, payment_status) VALUES (:oid, :pm, :ps)'
    )->execute([':oid' => $orderId, ':pm' => $method, ':ps' => $status]);
    if ($method === 'CASH_ON_PICKUP') {
        $pdo->prepare("UPDATE shipments SET delivery_method = 'STORE_PICKUP' WHERE order_id = :oid")
            ->execute([':oid' => $orderId]);
    }
}

function paymentRow(PDO $pdo, int $orderId): array
{
    return $pdo->query('SELECT * FROM payments WHERE order_id = ' . (int) $orderId)->fetch() ?: [];
}

try {
    /* ---- Fixtures ---- */
    $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (:n, :e, :p)')
        ->execute([':n' => 'Phase2 Admin', ':e' => "phase2admin+{$suffix}@test.local", ':p' => 'x']);
    $created['admin'] = (int) $pdo->lastInsertId();
    $adminId = $created['admin'];

    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => 'Pay', ':l' => 'Two', ':e' => "phase2a+{$suffix}@test.local", ':p' => 'x']);
    $created['customerA'] = (int) $pdo->lastInsertId();
    $custA = $created['customerA'];

    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:f, :l, :e, :p)')
        ->execute([':f' => 'Other', ':l' => 'Buyer', ':e' => "phase2b+{$suffix}@test.local", ':p' => 'x']);
    $created['customerB'] = (int) $pdo->lastInsertId();
    $custB = $created['customerB'];

    /* ===========================================================
     * 1. GCash configuration missing / incomplete
     * =========================================================== */
    echo "== 1. GCash configuration guard ==\n";
    $envBackup = [
        'GCASH_ACCOUNT_NAME' => $_ENV['GCASH_ACCOUNT_NAME'] ?? null,
        'GCASH_ACCOUNT_NUMBER' => $_ENV['GCASH_ACCOUNT_NUMBER'] ?? null,
        'GCASH_QR_IMAGE' => $_ENV['GCASH_QR_IMAGE'] ?? null,
    ];

    $_ENV['GCASH_ACCOUNT_NAME'] = '';
    $_ENV['GCASH_ACCOUNT_NUMBER'] = '';
    $_ENV['GCASH_QR_IMAGE'] = '';
    assertTrue('1.1 unconfigured -> gcash_is_configured() false', gcash_is_configured() === false);
    $cfg = gcash_config();
    assertTrue('1.2 both required keys reported missing', in_array('GCASH_ACCOUNT_NAME', $cfg['missing'], true) && in_array('GCASH_ACCOUNT_NUMBER', $cfg['missing'], true));

    $_ENV['GCASH_ACCOUNT_NAME'] = 'Test Receiver';
    assertTrue('1.3 name only -> still not configured (number missing)', gcash_is_configured() === false);

    $_ENV['GCASH_ACCOUNT_NUMBER'] = '09171234567';
    assertTrue('1.4 name + number -> configured', gcash_is_configured() === true);

    $_ENV['GCASH_QR_IMAGE'] = 'images/does-not-exist-' . $suffix . '.png';
    assertTrue('1.5 missing QR file -> has_qr false (never a broken/placeholder image)', gcash_config()['has_qr'] === false);
    $_ENV['GCASH_QR_IMAGE'] = 'images/../.env';
    assertTrue('1.6 traversal QR path rejected', gcash_safe_qr_path('images/../.env') === '');

    /* Checkout allowed-payment rule mirrors customer/checkout.php. */
    $allowed = function (bool $isPickup): array {
        $cash = $isPickup ? 'CASH_ON_PICKUP' : 'COD';
        $set = [$cash];
        if (gcash_is_configured()) {
            $set[] = 'GCASH';
        }
        return $set;
    };
    $_ENV['GCASH_ACCOUNT_NAME'] = '';
    $_ENV['GCASH_ACCOUNT_NUMBER'] = '';
    assertTrue('1.7 unconfigured -> GCASH not offered for courier', !in_array('GCASH', $allowed(false), true));
    assertTrue('1.8 unconfigured -> GCASH not offered for pickup', !in_array('GCASH', $allowed(true), true));
    $_ENV['GCASH_ACCOUNT_NAME'] = 'Test Receiver';
    $_ENV['GCASH_ACCOUNT_NUMBER'] = '09171234567';
    assertTrue('1.9 configured -> GCASH offered for courier (with COD)', in_array('GCASH', $allowed(false), true) && in_array('COD', $allowed(false), true));
    assertTrue('1.10 configured -> GCASH offered for pickup (with CASH_ON_PICKUP)', in_array('GCASH', $allowed(true), true) && in_array('CASH_ON_PICKUP', $allowed(true), true));

    /* ===========================================================
     * 2. Reference validation
     * =========================================================== */
    echo "== 2. GCash reference validation ==\n";
    assertTrue('2.1 empty invalid', !payment_is_valid_reference(payment_normalize_reference('')));
    assertTrue('2.2 too short invalid', !payment_is_valid_reference(payment_normalize_reference('123')));
    assertTrue('2.3 13-digit valid', payment_is_valid_reference(payment_normalize_reference('1234567890123')));
    assertEq('2.4 whitespace collapsed', payment_normalize_reference("  12 34\t5678  "), '12 34 5678');
    assertTrue('2.5 collapsed spaces still valid', payment_is_valid_reference(payment_normalize_reference('  12 34 5678  ')));
    assertTrue('2.6 illegal char rejected', !payment_is_valid_reference(payment_normalize_reference('1234@567890')));
    assertTrue('2.7 only dashes rejected', !payment_is_valid_reference('------'));
    assertTrue('2.8 over 64 chars rejected', !payment_is_valid_reference(str_repeat('9', 65)));

    /* ===========================================================
     * 3. Private proof validation (type / size / error)
     * =========================================================== */
    echo "== 3. Payment proof validation ==\n";
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $pngPath = sys_get_temp_dir() . '/hopia_p2_' . $suffix . '.png';
    file_put_contents($pngPath, $pngBytes);
    $created['tmpfiles'][] = $pngPath;

    $txtPath = sys_get_temp_dir() . '/hopia_p2_' . $suffix . '.txt';
    file_put_contents($txtPath, "this is not an image, it is plain text content");
    $created['tmpfiles'][] = $txtPath;

    $v = payment_proof_validate($pngPath, filesize($pngPath), UPLOAD_ERR_OK);
    assertTrue('3.1 real PNG accepted as png', !empty($v['ok']) && ($v['ext'] ?? '') === 'png');
    $v = payment_proof_validate($txtPath, filesize($txtPath), UPLOAD_ERR_OK);
    assertEq('3.2 text file rejected by content (not extension)', $v['reason'] ?? '', 'proof_invalid_type');
    $v = payment_proof_validate($pngPath, PAYMENT_PROOF_MAX_BYTES + 1, UPLOAD_ERR_OK);
    assertEq('3.3 oversize rejected', $v['reason'] ?? '', 'proof_too_large');
    $v = payment_proof_validate($pngPath, filesize($pngPath), UPLOAD_ERR_INI_SIZE);
    assertEq('3.4 upload error rejected', $v['reason'] ?? '', 'proof_failed');
    assertEq('3.5 proof_abspath rejects traversal', payment_proof_abspath('../../.env'), null);
    assertEq('3.6 proof_abspath rejects empty', payment_proof_abspath(''), null);

    /* ===========================================================
     * 4. Amount calc: courier includes shipping, pickup excludes
     * =========================================================== */
    echo "== 4. GCash amount = subtotal + applicable shipping ==\n";
    $courierFx = shipping_resolve_fulfillment('LOCAL_COURIER', 'Central Luzon');
    $courierFeeCents = $courierFx['ok'] ? (int) $courierFx['rate_cents'] : -1;
    assertEq('4.1 Luzon courier fee resolves to 8000c', $courierFeeCents, 8000);

    $subtotalCents = 50000; // 500.00
    $courierTotal = number_format(($subtotalCents + $courierFeeCents) / 100, 2, '.', '');
    $courierOrder = makeOrder($pdo, $custA, 'PENDING', '500.00', number_format($courierFeeCents / 100, 2, '.', ''), $courierTotal);
    addOrderItem($pdo, $courierOrder, null, "P2 {$suffix} courier item", '500.00');
    makePayment($pdo, $courierOrder, 'GCASH', 'PENDING');
    $row = $pdo->query('SELECT subtotal, shipping_fee, total_amount FROM orders WHERE id = ' . $courierOrder)->fetch();
    assertEq('4.2 courier GCash subtotal 500.00', $row['subtotal'], '500.00');
    assertEq('4.3 courier GCash shipping 80.00', $row['shipping_fee'], '80.00');
    assertEq('4.4 courier GCash amount due incl shipping 580.00', $row['total_amount'], '580.00');

    $pickupFx = shipping_resolve_fulfillment('STORE_PICKUP', '');
    assertEq('4.5 pickup fee resolves to 0c', $pickupFx['ok'] ? (int) $pickupFx['rate_cents'] : -1, 0);
    $pickupOrder = makeOrder($pdo, $custA, 'PENDING', '500.00', '0.00', '500.00');
    addOrderItem($pdo, $pickupOrder, null, "P2 {$suffix} pickup item", '500.00');
    makePayment($pdo, $pickupOrder, 'GCASH', 'PENDING');
    $row = $pdo->query('SELECT shipping_fee, total_amount FROM orders WHERE id = ' . $pickupOrder)->fetch();
    assertEq('4.6 pickup GCash shipping stays 0.00', $row['shipping_fee'], '0.00');
    assertEq('4.7 pickup GCash amount due excludes shipping 500.00', $row['total_amount'], '500.00');

    /* ===========================================================
     * 5. Submission: ownership + validation + stays PENDING
     * =========================================================== */
    echo "== 5. Customer submission ==\n";
    $r = payment_gcash_submit($pdo, $custB, $courierOrder, '1234567890123', null);
    assertEq('5.1 wrong customer rejected (not_found)', $r['reason'] ?? '', 'not_found');
    $r = payment_gcash_submit($pdo, $custA, $courierOrder, '', null);
    assertEq('5.2 empty reference rejected', $r['reason'] ?? '', 'reference_required');
    $r = payment_gcash_submit($pdo, $custA, $courierOrder, '12@@34', null);
    assertEq('5.3 invalid reference rejected', $r['reason'] ?? '', 'reference_invalid');
    $r = payment_gcash_submit($pdo, $custA, $courierOrder, '  9900 1122 3344 ', null);
    assertTrue('5.4 valid submission ok, state AWAITING', !empty($r['ok']) && ($r['state'] ?? '') === 'AWAITING');

    $p = paymentRow($pdo, $courierOrder);
    assertEq('5.5 reference stored (normalized)', $p['gcash_reference'], '9900 1122 3344');
    assertTrue('5.6 submitted_at set', $p['submitted_at'] !== null);
    assertEq('5.7 submission did NOT mark PAID (still PENDING)', $p['payment_status'], 'PENDING');
    assertEq('5.8 derived state is AWAITING', payment_gcash_state($p), 'AWAITING');

    /* ===========================================================
     * 6. Admin verify -> PAID (and no double verification)
     * =========================================================== */
    echo "== 6. Admin verification (confirm) ==\n";
    $r = payment_gcash_verify($pdo, $adminId, $courierOrder);
    assertTrue('6.1 verify ok, state PAID', !empty($r['ok']) && ($r['state'] ?? '') === 'PAID');
    $p = paymentRow($pdo, $courierOrder);
    assertEq('6.2 status PAID', $p['payment_status'], 'PAID');
    assertEq('6.3 verified_by = admin', (int) $p['verified_by'], $adminId);
    assertTrue('6.4 verified_at set', $p['verified_at'] !== null);
    $r = payment_gcash_verify($pdo, $adminId, $courierOrder);
    assertEq('6.5 repeat verify rejected (already_paid)', $r['reason'] ?? '', 'already_paid');
    $r = payment_gcash_submit($pdo, $custA, $courierOrder, '5555666677778', null);
    assertEq('6.6 submit after paid rejected', $r['reason'] ?? '', 'already_paid');
    $r = payment_gcash_reject($pdo, $adminId, $courierOrder, 'changed mind');
    assertEq('6.7 reject after paid rejected', $r['reason'] ?? '', 'already_paid');

    /* ===========================================================
     * 7. Rejection / correction loop
     * =========================================================== */
    echo "== 7. Rejection and correction ==\n";
    $rejOrder = makeOrder($pdo, $custA, 'PENDING', '150.00', '80.00', '230.00');
    addOrderItem($pdo, $rejOrder, null, "P2 {$suffix} reject item", '150.00');
    makePayment($pdo, $rejOrder, 'GCASH', 'PENDING');

    $r = payment_gcash_verify($pdo, $adminId, $rejOrder);
    assertEq('7.1 verify before submission rejected (not_submitted)', $r['reason'] ?? '', 'not_submitted');
    $r = payment_gcash_reject($pdo, $adminId, $rejOrder, 'no ref yet');
    assertEq('7.2 reject before submission rejected (not_submitted)', $r['reason'] ?? '', 'not_submitted');

    payment_gcash_submit($pdo, $custA, $rejOrder, '1111222233334', null);
    $r = payment_gcash_reject($pdo, $adminId, $rejOrder, '');
    assertEq('7.3 empty reason rejected', $r['reason'] ?? '', 'reason_required');
    $r = payment_gcash_reject($pdo, $adminId, $rejOrder, 'Reference not found in GCash account');
    assertTrue('7.4 reject ok, state REJECTED', !empty($r['ok']) && ($r['state'] ?? '') === 'REJECTED');
    $p = paymentRow($pdo, $rejOrder);
    assertEq('7.5 status FAILED after reject', $p['payment_status'], 'FAILED');
    assertEq('7.6 rejection_reason stored', $p['rejection_reason'], 'Reference not found in GCash account');
    assertEq('7.7 derived state REJECTED', payment_gcash_state($p), 'REJECTED');

    $r = payment_gcash_submit($pdo, $custA, $rejOrder, '9999888877776', null);
    assertTrue('7.8 customer may correct (resubmit) -> AWAITING', !empty($r['ok']) && ($r['state'] ?? '') === 'AWAITING');
    $p = paymentRow($pdo, $rejOrder);
    assertEq('7.9 status back to PENDING after resubmit', $p['payment_status'], 'PENDING');
    assertEq('7.10 rejection_reason cleared on resubmit', $p['rejection_reason'], null);
    $r = payment_gcash_verify($pdo, $adminId, $rejOrder);
    assertTrue('7.11 verify corrected payment -> PAID', !empty($r['ok']) && ($r['state'] ?? '') === 'PAID');

    /* ===========================================================
     * 8. Cancelled order blocks all payment mutations
     * =========================================================== */
    echo "== 8. Cancelled order blocks payment changes ==\n";
    $cancOrder = makeOrder($pdo, $custA, 'CANCELLED', '150.00', '0.00', '150.00');
    addOrderItem($pdo, $cancOrder, null, "P2 {$suffix} cancelled", '150.00');
    makePayment($pdo, $cancOrder, 'GCASH', 'PENDING');
    $r = payment_gcash_submit($pdo, $custA, $cancOrder, '1234567890123', null);
    assertEq('8.1 submit on cancelled rejected', $r['reason'] ?? '', 'order_cancelled');
    $r = payment_gcash_verify($pdo, $adminId, $cancOrder);
    assertEq('8.2 verify on cancelled rejected', $r['reason'] ?? '', 'order_cancelled');
    $r = payment_gcash_reject($pdo, $adminId, $cancOrder, 'n/a');
    assertEq('8.3 reject on cancelled rejected', $r['reason'] ?? '', 'order_cancelled');

    /* ===========================================================
     * 9. COD unaffected; Cash on Pickup distinct and unpaid
     * =========================================================== */
    echo "== 9. COD and Cash on Pickup preserved ==\n";
    $codOrder = makeOrder($pdo, $custA, 'PENDING', '150.00', '80.00', '230.00');
    addOrderItem($pdo, $codOrder, null, "P2 {$suffix} cod", '150.00');
    makePayment($pdo, $codOrder, 'COD', 'PENDING');
    $r = payment_gcash_submit($pdo, $custA, $codOrder, '1234567890123', null);
    assertEq('9.1 GCash submit on a COD order rejected (not_gcash)', $r['reason'] ?? '', 'not_gcash');
    $r = payment_gcash_verify($pdo, $adminId, $codOrder);
    assertEq('9.2 GCash verify on a COD order rejected (not_gcash)', $r['reason'] ?? '', 'not_gcash');
    $p = paymentRow($pdo, $codOrder);
    assertEq('9.3 COD still COD', $p['payment_method'], 'COD');
    assertEq('9.4 COD still PENDING (unchanged)', $p['payment_status'], 'PENDING');
    assertEq('9.5 COD derived GCash state is NA', payment_gcash_state($p), 'NA');

    $copOrder = makeOrder($pdo, $custA, 'PENDING', '150.00', '0.00', '150.00');
    addOrderItem($pdo, $copOrder, null, "P2 {$suffix} cop", '150.00');
    makePayment($pdo, $copOrder, 'CASH_ON_PICKUP', 'PENDING');
    $r = payment_gcash_verify($pdo, $adminId, $copOrder);
    assertEq('9.6 GCash verify on Cash-on-Pickup rejected (not_gcash)', $r['reason'] ?? '', 'not_gcash');
    $p = paymentRow($pdo, $copOrder);
    assertEq('9.7 Cash on Pickup stays distinct from COD', $p['payment_method'], 'CASH_ON_PICKUP');
    assertEq('9.8 Cash on Pickup remains unpaid (PENDING)', $p['payment_status'], 'PENDING');

    /* ===========================================================
     * 10. Submission/verification create NO sales rows
     * =========================================================== */
    echo "== 10. Payment flow never creates a sale ==\n";
    $noSaleOrder = makeOrder($pdo, $custA, 'PENDING', '150.00', '80.00', '230.00');
    addOrderItem($pdo, $noSaleOrder, null, "P2 {$suffix} nosale", '150.00');
    makePayment($pdo, $noSaleOrder, 'GCASH', 'PENDING');
    $before = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    payment_gcash_submit($pdo, $custA, $noSaleOrder, '1212343456567', null);
    payment_gcash_verify($pdo, $adminId, $noSaleOrder);
    $after = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    assertEq('10.1 no sales_transactions row created by submit+verify', $after, $before);
    $saleForOrder = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions WHERE order_id = ' . $noSaleOrder)->fetchColumn();
    assertEq('10.2 verified-but-not-received order has no completed sale', $saleForOrder, 0);

    /* ===========================================================
     * 11. Sales Monitoring reflects real method/status (merchandise only)
     * =========================================================== */
    echo "== 11. Sales Monitoring honesty ==\n";
    /* 11a. GCash order verified PAID, then customer confirms receipt. */
    $paidRecvOrder = makeOrder($pdo, $custA, 'PENDING', '300.00', '80.00', '380.00');
    addOrderItem($pdo, $paidRecvOrder, null, "P2 {$suffix} recv item 1", '150.00');
    addOrderItem($pdo, $paidRecvOrder, null, "P2 {$suffix} recv item 2", '150.00');
    makePayment($pdo, $paidRecvOrder, 'GCASH', 'PENDING');
    payment_gcash_submit($pdo, $custA, $paidRecvOrder, '7777666655554', null);
    payment_gcash_verify($pdo, $adminId, $paidRecvOrder);
    $pdo->prepare("UPDATE orders SET status = 'DELIVERED' WHERE id = :id")->execute([':id' => $paidRecvOrder]);
    $recv = sales_confirm_receipt_online($pdo, $custA, $paidRecvOrder);
    assertTrue('11.1 receipt confirmation ok for paid GCash order', !empty($recv['ok']));
    $saleId = (int) ($recv['sale']['id'] ?? 0);
    $detail = $saleId > 0 ? sales_monitor_detail($pdo, $saleId) : null;
    assertTrue('11.2 monitor detail shows GCASH method', $detail !== null && $detail['payment_method'] === 'GCASH');
    assertTrue('11.3 monitor detail shows PAID status', $detail !== null && $detail['payment_status'] === 'PAID');
    assertEq('11.4 completed-sale total is merchandise-only (shipping 80 excluded)', $detail['total_cents'] ?? -1, 30000);

    /* 11b. Phase 5: a GCash order only SUBMITTED (not verified) must not
       create a completed sale. */
    $unpaidRecvOrder = makeOrder($pdo, $custA, 'PENDING', '150.00', '100.00', '250.00');
    addOrderItem($pdo, $unpaidRecvOrder, null, "P2 {$suffix} unpaid recv", '150.00');
    makePayment($pdo, $unpaidRecvOrder, 'GCASH', 'PENDING');
    payment_gcash_submit($pdo, $custA, $unpaidRecvOrder, '3333222211110', null);
    // Simulate legacy delivery only after submission; new submissions at a final state are forbidden.
    $pdo->prepare("UPDATE orders SET status = 'DELIVERED' WHERE id = :id")->execute([':id' => $unpaidRecvOrder]);
    $recv2 = sales_confirm_receipt_online($pdo, $custA, $unpaidRecvOrder);
    assertTrue('11.5 unverified GCash receipt rejected', empty($recv2['ok']));
    assertEq('11.6 rejection reason is awaiting verification', $recv2['reason'] ?? '', 'gcash_awaiting_verification');
    assertEq(
        '11.7 no completed sale created for unverified GCash',
        (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions WHERE order_id = ' . $unpaidRecvOrder)->fetchColumn(),
        0
    );

    /* 11c. Legacy/inconsistent data simulation: a completed sale whose GCash
       payment is still PENDING must remain visible as uncollected, never as
       collected revenue. The receipt path itself now refuses this state, so
       create the sale as PAID and then restore the stored PENDING status. */
    $pdo->prepare("UPDATE payments SET payment_status = 'PAID' WHERE order_id = :oid")
        ->execute([':oid' => $unpaidRecvOrder]);
    $recv2b = sales_confirm_receipt_online($pdo, $custA, $unpaidRecvOrder);
    assertTrue('11.8 legacy simulation receipt confirmation ok', !empty($recv2b['ok']));
    $pdo->prepare("UPDATE payments SET payment_status = 'PENDING' WHERE order_id = :oid")
        ->execute([':oid' => $unpaidRecvOrder]);
    $saleId2 = (int) ($recv2b['sale']['id'] ?? 0);
    $detail2 = $saleId2 > 0 ? sales_monitor_detail($pdo, $saleId2) : null;
    assertTrue('11.9 submitted-but-unverified shows PENDING (not counted as collected)', $detail2 !== null && $detail2['payment_status'] === 'PENDING');
    assertEq('11.10 its completed-sale total is merchandise-only 150.00', $detail2['total_cents'] ?? -1, 15000);

    /* restore env */
    foreach ($envBackup as $k => $v) {
        if ($v === null) {
            unset($_ENV[$k]);
        } else {
            $_ENV[$k] = $v;
        }
    }
} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception: " . $e->getMessage() . "\n";
    echo "        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    /* ---- Cleanup fixtures (children first; FKs vary by table). ---- */
    try {
        $orderIds = array_map('intval', $created['orders']);
        if (!empty($orderIds)) {
            $oidList = implode(',', $orderIds);
            $pdo->exec("DELETE sti FROM sales_transaction_items sti INNER JOIN sales_transactions st ON st.id = sti.sale_id WHERE st.order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM sales_transactions WHERE order_id IN ({$oidList})");
            /* payments cascade from orders, but delete explicitly first so the
               verified_by RESTRICT FK to admins is cleared before admin delete. */
            $pdo->exec("DELETE FROM payments WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM shipments WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM order_items WHERE order_id IN ({$oidList})");
            $pdo->exec("DELETE FROM orders WHERE id IN ({$oidList})");
        }
        if (!empty($created['products'])) {
            $pidList = implode(',', array_map('intval', $created['products']));
            $pdo->exec("DELETE FROM products WHERE id IN ({$pidList})");
        }
        foreach ([(int) ($created['customerA'] ?? 0), (int) ($created['customerB'] ?? 0)] as $cid) {
            if ($cid > 0) {
                $pdo->exec("DELETE FROM customers WHERE id = {$cid}");
            }
        }
        if (!empty($created['admin'])) {
            $pdo->exec("DELETE FROM admins WHERE id = " . (int) $created['admin']);
        }
        foreach ($created['tmpfiles'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        echo "\nFixtures cleaned up.\n";
    } catch (Throwable $e) {
        echo "\nWARNING: fixture cleanup failed: " . $e->getMessage() . "\n";
        echo "Fixture suffix for manual cleanup: {$suffix}\n";
    }
}

echo "\nRan {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
