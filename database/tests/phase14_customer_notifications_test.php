<?php

/*
 * Developer-run, CLI-only Phase 14 checks.
 *   php database/tests/phase14_customer_notifications_test.php --unit
 *   php database/tests/phase14_customer_notifications_test.php
 * --unit loads no PDO connection or .env. Source contracts are NOT HTTP tests.
 * Full mode requires a separately prepared, explicitly authorized disposable
 * loopback clothing_shop_bundle_test instance through migration 015. Neither
 * loopback nor the database name establishes safety. No application database
 * bootstrap, DDL or migrations.
 * Persistent-read checks COMMIT fixtures and read-state changes. Other checks
 * roll back; finally performs committed, fixture-scoped cleanup.
 * Exit: 0 passed, 1 failed, 2 unavailable integration or invalid CLI arguments.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}
$unitOnly = $argc === 2 && $argv[1] === '--unit';
if ($argc > 1 && !$unitOnly) {
    fwrite(STDERR, "Usage: php database/tests/phase14_customer_notifications_test.php [--unit]\n");
    exit(2);
}

require_once __DIR__ . '/../../includes/customer_notifications.php';
const TEST_DB_NAME = 'clothing_shop_bundle_test';
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
        echo "  FAIL: {$label} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
    }
}

function sourceCode(string $path): string
{
    static $cache = [];
    if (!isset($cache[$path])) {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        if ($source === false) {
            throw new RuntimeException("Cannot inspect {$path}");
        }
        $cache[$path] = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $cache[$path] .= is_array($token) ? $token[1] : $token;
            }
        }
    }
    return $cache[$path];
}

try {
    echo "== Pure event, message, destination and presentation (no DB) ==\n";
    foreach ([
        'ORDER_SHIPPED' => true, 'ORDER_DELIVERED' => true, 'PICKUP_READY' => true,
        'PICKUP_HANDED_OVER' => true, 'ORDER_CANCELLED' => true, 'DELIVERY_CYCLE_AUTHORIZED' => false,
        'BOGUS' => false, '' => false,
    ] as $event => $supported) {
        assertEq("event {$event} supported = " . var_export($supported, true), customer_notifications_is_supported_event($event), $supported);
    }
    assertEq('nine supported event labels excluding removed redelivery authorization', count(customer_notifications_event_labels()), 9);

    assertEq('shipped with tracking', customer_notifications_message('ORDER_SHIPPED', 123, 'JTX1'), 'Your order #123 has been shipped. Your tracking number is JTX1.');
    assertEq('shipped without tracking', customer_notifications_message('ORDER_SHIPPED', 123, null), 'Your order #123 has been shipped.');
    assertEq('shipped blank tracking', customer_notifications_message('ORDER_SHIPPED', 123, '   '), 'Your order #123 has been shipped.');
    assertEq('delivered reminder', customer_notifications_message('ORDER_DELIVERED', 123), 'Your order #123 has been delivered. Please confirm receipt of your order.');
    assertEq('pickup ready', customer_notifications_message('PICKUP_READY', 125), 'Your order #125 is ready for pickup at the store.');
    assertEq('pickup handed over', customer_notifications_message('PICKUP_HANDED_OVER', 125), 'Your pickup order #125 has been handed over to you.');
    assertEq('cancelled', customer_notifications_message('ORDER_CANCELLED', 126), 'Your order #126 has been cancelled.');

    assertEq('destination is the customer order detail page', customer_notifications_destination(123), 'order-detail.php?id=123');
    assertEq('destination clamps non-positive id', customer_notifications_destination(-5), 'order-detail.php?id=1');
    assertEq('destination never exposes an external URL', strpos(customer_notifications_destination(123), 'http'), false);

    $row = ['id' => '7', 'order_id' => '42', 'notification_type' => 'ORDER_SHIPPED', 'message' => 'Your order #42 has been shipped.', 'created_at' => '2026-10-19 09:05:00', 'read_at' => null];
    $p = customer_notifications_present($row);
    assertEq('present: typed ids, label, unread, safe url', [$p['id'], $p['order_id'], $p['label'], $p['read'], $p['url']], [7, 42, 'Order Shipped', false, 'order-detail.php?id=42']);
    assertEq('present: Manila ATOM and display label', [$p['created_at'], $p['time_label']], ['2026-10-19T09:05:00+08:00', 'Oct 19, 2026, 9:05 AM PHT']);
    assertEq('present: message is customer-safe', $p['message'], 'Your order #42 has been shipped.');
    $read = customer_notifications_present(array_replace($row, ['read_at' => '2026-10-19 10:00:00']));
    assertEq('present: read flag reflects persisted read_at', $read['read'], true);
    assertEq('present: read row keeps url and label', [$read['url'], $read['label']], ['order-detail.php?id=42', 'Order Shipped']);

    $threw = false;
    try {
        customer_notifications_message('NOPE', 1);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assertEq('unsupported event message throws', $threw, true);

    echo "== Source wiring ONLY (not executed auth, HTTP or browser tests) ==\n";
    $helper = sourceCode('includes/customer_notifications.php');
    $api = sourceCode('customer/api/notifications.php');
    $orderView = sourceCode('admin/order-view.php');
    $pickup = sourceCode('includes/pickup.php');
    $orderCancel = sourceCode('includes/order_cancel.php');
    $payment = sourceCode('includes/payment.php');

    assertEq('create requires the order transaction', preg_match('/!\$pdo->inTransaction\(\) \|\| \$orderId <= 0.*throw new LogicException/s', $helper) === 1, true);
    assertEq('create idempotent duplicate protection', strpos($helper, 'ON DUPLICATE KEY UPDATE id = customer_order_notifications.id') !== false, true);
    assertEq('create resolves customer from the order row (never request input)', preg_match('/SELECT o\.customer_id, o\.id, :type, :message.*FROM orders o.*WHERE o\.id = :order_id/s', $helper) === 1, true);
    assertEq('every read/mark query is scoped by customer_id', substr_count($helper, 'customer_id = :customer_id') >= 6, true);
    assertEq('mark_read only touches unread (preserves original read_at)', preg_match('/WHERE customer_id = :customer_id.*AND id = :notification_id.*AND read_at IS NULL/s', $helper) === 1, true);
    assertEq('mark_read never deletes', stripos($helper, 'DELETE FROM'), false);

    // Courier SHIPPED and DELIVERED are integrated in order-view's advance_status
    // transaction, after the shipment update, before commit.
    assertEq('order-view requires customer notifications helper', strpos($orderView, 'customer_notifications.php') !== false, true);
    assertEq('order-view creates notifications', substr_count($orderView, 'customer_notifications_create(') >= 3, true);
    foreach (["'ORDER_SHIPPED'", "'ORDER_DELIVERED'", "'ORDER_CANCELLED'", 'order_cancel_pending_locked($pdo, $lockedOrder)'] as $needle) {
        assertEq('order-view advance/cancel wiring contains ' . $needle, strpos($orderView, $needle) !== false, true);
    }
    assertEq('order-view notifications are created inside the action transaction before the single commit', strpos($orderView, 'customer_notifications_create(') < strpos($orderView, '$pdo->commit();'), true);
    assertEq('order-view has only one commit in the status/approve path', substr_count($orderView, '$pdo->commit();'), 1);

    // Store Pickup READY and HANDED_OVER are integrated in pickup_advance_status
    // before commit; barcode scans never notify.
    assertEq('pickup advance notifies READY and HANDED_OVER', strpos($pickup, "'PICKUP_READY'") !== false && strpos($pickup, "'PICKUP_HANDED_OVER'") !== false && strpos($pickup, "'PICKUP_HANDED_OVER' : 'PICKUP_READY'") !== false, true);
    assertEq('pickup notification precedes commit', strpos($pickup, 'customer_notifications_create(') < strpos($pickup, '$pdo->commit();'), true);
    assertEq('pickup_advance_status is the only pickup notification creator', substr_count($pickup, 'customer_notifications_create('), 1);

    // Phase 9 / Phase 10 handover finalization paths notify HANDED_OVER before commit.
    foreach (['payment_confirm_pickup_and_collect_cash', 'payment_confirm_pickup_gcash_and_complete_sale'] as $fn) {
        $start = strpos($payment, 'function ' . $fn . '(');
        $open = strpos($payment, '{', $start);
        $depth = 0;
        $end = $open;
        $len = strlen($payment);
        for ($i = $open; $i < $len; $i++) {
            $c = $payment[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $frag = substr($payment, $start, $end - $start + 1);
        $handed = strpos($frag, 'PICKUP_HANDED_OVER');
        $picked = strpos($frag, 'PICKED_UP');
        $commit = strpos($frag, '$pdo->commit();');
        assertEq("{$fn} hands over then notifies", $picked !== false && $handed !== false && $commit !== false && $picked < $handed && $handed < $commit, true);
    }

    // GCash expired cancellation notifies before its own commit.
    assertEq('expired-gcash cancellation notifies before commit', strpos($orderCancel, 'customer_notifications_create($pdo, $orderId, \'ORDER_CANCELLED\')') < strpos($orderCancel, '$pdo->commit();'), true);

    // API: authenticated session, method + CSRF guard, ownership by customer.
    assertEq('API: customer session guard before database', strpos($api, "empty(\$_SESSION['customer_id'])") !== false && strpos($api, 'customer_notifications.php') !== false && strpos($api, "empty(\$_SESSION['customer_id'])") < strpos($api, 'customer_notifications.php'), true);
    assertEq('API: CSRF for POST state changes', strpos($api, 'hash_equals($stored, $token)') !== false && strpos($api, "\$_SESSION['csrf_token']") !== false, true);
    assertEq('API: bounded views and reject method/action', strpos($api, "['GET', 'POST']") !== false && strpos($api, ', 405)') !== false && strpos($api, ', 403)') !== false && strpos($api, "'history'") !== false, true);
    assertEq('API: ownership enforced in SQL not by hidden UI', strpos($api, 'customer_notifications_mark_read($pdo, $customerId,') !== false, true);
} catch (Throwable $e) {
    assertEq('no-DB exception: ' . $e->getMessage(), true, false);
}

$unitTests = $tests;
echo "\nNo-DB checks: {$tests} checks, {$failures} failure(s).\n";
if ($unitOnly || $failures > 0) {
    echo "Integration not run.\n";
    exit($failures === 0 ? 0 : 1);
}

/* Read-only schema and engine prerequisites before ANY fixture writes. */
try {
    require_once __DIR__ . '/../../vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Refusing non-loopback DB_HOST.');
    }
    $connect = static function () use ($host): PDO {
        $db = new PDO('mysql:host=' . $host . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4', $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        if ($db->query('SELECT DATABASE()')->fetchColumn() !== TEST_DB_NAME) {
            throw new RuntimeException('Refusing unexpected database.');
        }
        $db->exec("SET time_zone = '+08:00'");
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->exec('SET SESSION innodb_lock_wait_timeout = 5');
        return $db;
    };
    $pdo = $connect();
    $columns = [
        'customer_order_notifications' => 'id, customer_id, order_id, notification_type, message, created_at, read_at',
        'customers' => 'id, first_name, last_name, email, password_hash',
        'orders' => 'id, customer_id, status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address',
        'payments' => 'id, order_id, payment_method, payment_status',
        'shipments' => 'id, order_id, delivery_method, shipment_status',
        'order_items' => 'id, order_id',
        'sales_transactions' => 'id, order_id',
    ];
    $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    foreach ($columns as $table => $fields) {
        $pdo->query("SELECT {$fields} FROM {$table} LIMIT 0");
        $engine->execute([TEST_DB_NAME, $table]);
        if (strcasecmp((string) $engine->fetchColumn(), 'InnoDB') !== 0) {
            throw new RuntimeException("{$table} must use InnoDB for safe rollback.");
        }
    }
    $second = $connect();
} catch (Throwable $e) {
    fwrite(STDERR, "SKIP: Phase 14 integration not run; no fixtures written.\n" . $e->getMessage() . "\nRequires a separately prepared, explicitly authorized disposable local " . TEST_DB_NAME . " through migration 015. The database name alone is not a safety guarantee. No DDL performed.\n");
    exit(2);
}

$customerId = null;
$suffix = bin2hex(random_bytes(5));
$makeOrder = static function (PDO $db, int $custId, string $delivery = 'LOCAL_COURIER', string $payment = 'COD'): int {
    $db->prepare("INSERT INTO orders (customer_id, status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address) VALUES (?, 'PENDING', 150, 0, 150, ?, '0900', 'Phase14 fixture')")
        ->execute([$custId, 'Phase14 ' . bin2hex(random_bytes(2))]);
    $id = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO payments (order_id, payment_method, payment_status) VALUES (?, ?, 'PENDING')")->execute([$id, $payment]);
    $db->prepare("INSERT INTO shipments (order_id, delivery_method, shipment_status) VALUES (?, ?, 'NOT_SHIPPED')")->execute([$id, $delivery]);
    return $id;
};

try {
    echo "== Real DB: atomic creation, rollback, idempotency and ownership (committed fixtures cleaned in finally) ==\n";
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (?, ?, ?, ?)')->execute(['Phase14', $suffix, "phase14+{$suffix}@test.local", 'x']);
    $customerId = (int) $pdo->lastInsertId();
    $otherCustomer = $customerId + 999999; // never created; used only to prove ownership scoping

    $committed = $makeOrder($pdo, $customerId, 'LOCAL_COURIER', 'COD');
    $committedTwo = $makeOrder($pdo, $customerId, 'STORE_PICKUP', 'GCASH');
    customer_notifications_create($pdo, $committed, 'ORDER_SHIPPED', 'JTX1');
    customer_notifications_create($pdo, $committedTwo, 'PICKUP_READY');
    $pdo->commit();
    $fetch = static function (PDO $db, int $id): array {
        $stmt = $db->prepare('SELECT * FROM customer_order_notifications WHERE order_id = ? ORDER BY id');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    };
    $one = $fetch($second, $committed);
    assertEq('committed order has exactly one shipped notification on second connection', count($one), 1);
    assertEq('shipped snapshot carries type, message and unread', [$one[0]['notification_type'], $one[0]['message'], $one[0]['read_at']], ['ORDER_SHIPPED', 'Your order #' . $committed . ' has been shipped. Your tracking number is JTX1.', null]);
    assertEq('pickup-ready snapshot correct', $fetch($second, $committedTwo)[0]['notification_type'], 'PICKUP_READY');

    // Atomic rollback removes the notification with the order.
    $pdo->beginTransaction();
    $rolledBack = $makeOrder($pdo, $customerId);
    customer_notifications_create($pdo, $rolledBack, 'ORDER_CANCELLED');
    $pdo->rollBack();
    assertEq('rollback removes order and its notification together', $fetch($second, $rolledBack), []);

    // Duplicate protection: same event twice yields one row and no state change.
    $pdo->beginTransaction();
    customer_notifications_create($pdo, $committed, 'ORDER_SHIPPED', 'JTX1');
    customer_notifications_create($pdo, $committed, 'ORDER_SHIPPED', 'JTX1');
    assertEq('duplicate create keeps exactly one shipped notification', count($fetch($pdo, $committed)), 1);
    $pdo->rollBack();

    // Ownership: a customer can only read/mark their OWN notifications.
    assertEq('marking another customer id is a safe no-op (rowCount 0)', customer_notifications_mark_read($pdo, $otherCustomer, (int) $one[0]['id']), false);
    assertEq('marking own id succeeds', customer_notifications_mark_read($pdo, $customerId, (int) $one[0]['id']), true);
    assertEq('repeated mark is idempotent and preserves original read_at', customer_notifications_mark_read($pdo, $customerId, (int) $one[0]['id']), false);
    $after = $fetch($pdo, $committed)[0]['read_at'];
    assertEq('read state persisted and unread count reflects it', [$after !== null, customer_notifications_unread_count($pdo, $customerId) >= 1], [true, true]);
    $second->beginTransaction();
    $pdo->beginTransaction();
    // Close empty transactions; committed fixtures and read state are removed in finally.
    $pdo->rollBack();
    $second->rollBack();
} catch (Throwable $e) {
    assertEq('integration exception: ' . $e->getMessage(), true, false);
} finally {
    foreach ([$pdo, $second] as $db) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    if ($customerId !== null) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id FROM orders WHERE customer_id = ?');
            $stmt->execute([$customerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                foreach (['customer_order_notifications', 'sales_transactions', 'order_items', 'payments', 'shipments'] as $table) {
                    $pdo->prepare("DELETE FROM {$table} WHERE order_id = ?")->execute([$id]);
                }
                $pdo->prepare('DELETE FROM orders WHERE id = ? AND customer_id = ?')->execute([$id, $customerId]);
            }
            $pdo->prepare('DELETE FROM customers WHERE id = ? AND email = ?')->execute([$customerId, "phase14+{$suffix}@test.local"]);
            $pdo->commit();
            assertEq('fixture customer and orders cleaned', (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE id = ' . $customerId)->fetchColumn(), 0);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            assertEq('fixture cleanup failed: ' . $e->getMessage(), true, false);
        }
    }
}

$integrationTests = $tests - $unitTests;
echo "\n{$tests} checks ({$unitTests} no-DB, {$integrationTests} integration), {$failures} failure(s).\n";
echo "HTTP/browser behavior and live polling are not executed by this runner.\n";
exit($failures === 0 ? 0 : 1);
