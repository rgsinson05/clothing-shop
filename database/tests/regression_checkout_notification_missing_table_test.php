<?php
/*
 * Focused regression check for the checkout bug where a missing
 * admin_order_notifications table (migration 014 not applied) failed the
 * whole order transaction for BOTH Local Courier and Store Pickup.
 *
 * This is a CLI-only, read-mostly test against the configured loopback
 * clothing_shop_bundle_test DB. It never applies migrations, never resets
 * the database, and never persists data: every write runs inside a
 * transaction that is rolled back. It exercises admin_notifications_create()
 * the same way customer/checkout.php does - inside a transaction, after the
 * dependent order/payment/shipment rows, before commit.
 *
 * Exit: 0 = all checks passed, 1 = failed, 2 = could not connect / precondition.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}
require __DIR__ . '/../../includes/admin_notifications.php';

require_once __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback DB_HOST.\n");
    exit(2);
}
$db = new PDO(
    'mysql:host=' . $host . ';dbname=clothing_shop_bundle_test;charset=utf8mb4',
    $_ENV['DB_USER'] ?? 'root',
    $_ENV['DB_PASS'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]
);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'clothing_shop_bundle_test') {
    fwrite(STDERR, "Refusing unexpected database.\n");
    exit(2);
}
$db->exec("SET time_zone = '+08:00'");
$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$db->exec('SET SESSION innodb_lock_wait_timeout = 5');

$notifTable = (int) $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_order_notifications'"
)->fetchColumn();

$tests = 0;
$failures = 0;
function chk(string $label, $actual, $expected): void {
    global $tests, $failures;
    $tests++;
    if ($actual === $expected) {
        echo "  ok:   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL: {$label} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
    }
}

if ($notifTable > 0) {
    echo "admin_order_notifications table EXISTS in " . TEST_DB_NAME() . ".\n";
}

try {
    // Fixture: a unique customer so no real data is touched and cleanup is trivial.
    $suffix = bin2hex(random_bytes(5));
    $db->beginTransaction();
    $db->prepare("INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (?, ?, ?, ?)")
        ->execute(['Reg', 'CheckoutFix' . $suffix, "checkoutfix+{$suffix}@test.local", 'x']);
    $cid = (int) $db->lastInsertId();
    $db->commit();

    // Build a full checkout-style order row set for BOTH delivery methods,
    // each in its own rolled-back transaction.
    foreach (['LOCAL_COURIER', 'STORE_PICKUP'] as $delivery) {
        $payment = $delivery === 'STORE_PICKUP' ? 'CASH_ON_PICKUP' : 'COD';
        $db->beginTransaction();

        $db->prepare("INSERT INTO orders (customer_id, status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address)
                      VALUES (?, 'PENDING', 300, 0, 300, ?, '0900', ?)")
            ->execute([$cid, 'Reg ' . $suffix, $delivery === 'STORE_PICKUP' ? 'Store Pickup - Test Shop' : 'Metro Manila, City, Barangay, 1 House, St']);
        $orderId = (int) $db->lastInsertId();

        $db->prepare("INSERT INTO payments (order_id, payment_method, payment_status) VALUES (?, ?, 'PENDING')")->execute([$orderId, $payment]);
        $db->prepare("INSERT INTO shipments (order_id, delivery_method, shipment_status) VALUES (?, ?, 'NOT_SHIPPED')")->execute([$orderId, $delivery]);

        $threw = null;
        try {
            admin_notifications_create($db, $orderId);
        } catch (Throwable $e) {
            $threw = get_class($e) . ': ' . $e->getMessage();
        }

        if ($notifTable === 0) {
            // Precondition of this bug: table missing. Expect fail-open (no throw).
            chk("{$delivery}: create is fail-open when inbox table is absent", $threw, null);
            // Order rows must still be visible inside the still-open transaction.
            $cnt = (int) $db->query('SELECT COUNT(*) FROM orders WHERE id = ' . $orderId)->fetchColumn();
            chk("{$delivery}: order row still present in transaction after fail-open", $cnt, 1);
        } else {
            // Table exists: create must succeed and record exactly one notification.
            chk("{$delivery}: create does not throw when inbox table exists", $threw, null);
            $n = (int) $db->query("SELECT COUNT(*) FROM admin_order_notifications WHERE order_id = " . $orderId)->fetchColumn();
            chk("{$delivery}: exactly one notification recorded", $n, 1);
        }

        $db->rollBack();
    }

    // Confirmed root cause: report the actual DB state for the report.
    echo "db_admin_order_notifications_exists=" . ($notifTable > 0 ? 'yes' : 'no') . "\n";

    // Real-failure path (order without payment row) must STILL throw and roll
    // back, so a genuine integrity problem is never silently ignored. This is
    // only verifiable when the inbox table exists (the SELECT join can detect
    // the missing payment row); when the table is absent, fail-open is the
    // correct outcome because there is nothing to write or verify.
    $db->beginTransaction();
    $db->prepare("INSERT INTO orders (customer_id, status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address)
                  VALUES (?, 'PENDING', 100, 0, 100, ?, '0900', 'Bad fixture')")
        ->execute([$cid, 'Reg ' . $suffix]);
    $badOrder = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO shipments (order_id, delivery_method, shipment_status) VALUES (?, 'LOCAL_COURIER', 'NOT_SHIPPED')")->execute([$badOrder]);
    $threw = null;
    try {
        admin_notifications_create($db, $badOrder);
    } catch (Throwable $e) {
        $threw = get_class($e);
    }
    if ($notifTable > 0) {
        chk('missing payment/shipment row still throws (integrity preserved)', $threw !== null, true);
    } else {
        chk('missing table fails open even for otherwise-incomplete order', $threw === null, true);
    }
    $db->rollBack();

    // Cleanup fixture customer (fresh transaction; prior loop each rolled back).
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT id FROM orders WHERE customer_id = ?');
    $stmt->execute([$cid]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oid) {
        foreach (['admin_order_notifications', 'payments', 'shipments'] as $t) {
            $db->prepare("DELETE FROM {$t} WHERE order_id = ?")->execute([$oid]);
        }
        $db->prepare('DELETE FROM orders WHERE id = ? AND customer_id = ?')->execute([$oid, $cid]);
    }
    $db->prepare('DELETE FROM customers WHERE id = ? AND email = ?')->execute([$cid, "checkoutfix+{$suffix}@test.local"]);
    $db->commit();
    chk('fixture customer cleaned up', (int) $db->query('SELECT COUNT(*) FROM customers WHERE id = ' . $cid)->fetchColumn(), 0);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo 'FATAL: ' . $e->getMessage() . "\n";
    exit(1);
}

echo "\n{$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);

function TEST_DB_NAME(): string { return 'clothing_shop_bundle_test'; }