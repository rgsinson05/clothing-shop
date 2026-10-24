<?php

/*
 * Developer-run, CLI-only Phase 13 checks.
 *   php database/tests/phase13_admin_notifications_test.php --unit
 *   php database/tests/phase13_admin_notifications_test.php
 * --unit loads no PDO connection or .env. Source contracts are NOT HTTP tests.
 * Full mode requires a separately prepared loopback clothing_shop_bundle_test
 * through migration 014. No application database bootstrap, DDL or migrations.
 * Global mark-read calls are rolled back; committed cleanup is fixture-scoped.
 * The committing global open helper is source-checked, not invoked on old rows.
 * Exit: 0 passed, 1 failed, 2 unavailable integration or invalid CLI arguments.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}
$unitOnly = $argc === 2 && $argv[1] === '--unit';
if ($argc > 1 && !$unitOnly) {
    fwrite(STDERR, "Usage: php database/tests/phase13_admin_notifications_test.php [--unit]\n");
    exit(2);
}

require_once __DIR__ . '/../../includes/admin_notifications.php';
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
    echo "== Pure labels and badges (no DB) ==\n";
    foreach ([-1 => '0', 0 => '0', 1 => '1', 9 => '9', 10 => '9+', 123 => '9+'] as $count => $badge) {
        assertEq("badge {$count}", admin_notifications_badge($count), $badge);
    }
    $row = ['id' => '7', 'order_id' => '42', 'created_at' => '2026-10-19 09:05:00', 'read_at' => null];
    foreach ([
        ['LOCAL_COURIER', 'COD', 'Local Courier / COD'],
        ['LOCAL_COURIER', 'GCASH', 'Local Courier / GCash'],
        ['STORE_PICKUP', 'GCASH', 'Store Pickup / GCash'],
        ['STORE_PICKUP', 'CASH_ON_PICKUP', 'Store Pickup / Cash on Pickup'],
        ['UNKNOWN', 'UNKNOWN', 'Unknown fulfillment / Unknown payment'],
    ] as [$delivery, $payment, $label]) {
        $presented = admin_notifications_present($row + ['delivery_method' => $delivery, 'payment_method' => $payment]);
        assertEq("placement label {$delivery}/{$payment}", $presented['message'], 'New Order #42 - ' . $label);
    }
    assertEq('typed IDs, order link and unread flag', [$presented['id'], $presented['order_id'], $presented['url'], $presented['unread']], [7, 42, 'order-view.php?id=42', true]);
    assertEq('explicit Manila timestamp and display', [$presented['created_at'], $presented['time_label']], ['2026-10-19T09:05:00+08:00', 'Oct 19, 2026, 9:05 AM PHT']);
    $read = admin_notifications_present(array_replace($row, ['read_at' => '2026-10-19 10:00:00', 'delivery_method' => 'STORE_PICKUP', 'payment_method' => 'CASH_ON_PICKUP']));
    assertEq('read placement remains available', [$read['unread'], $read['url']], [false, 'order-view.php?id=42']);

    echo "== Source wiring ONLY (not executed auth, HTTP or browser tests) ==\n";
    $api = sourceCode('admin/api/notifications.php');
    $history = sourceCode('admin/notifications.php');
    $helper = sourceCode('includes/admin_notifications.php');
    $checkout = sourceCode('customer/checkout.php');
    foreach (['API' => $api, 'history' => $history] as $name => $code) {
        $auth = strpos($code, "empty(\$_SESSION['admin_id'])");
        $database = strpos($code, '/includes/database.php');
        assertEq("{$name}: admin session guard before database", $auth !== false && $database !== false && $auth < $database, true);
    }
    $csrf = strpos($api, 'hash_equals($stored, $token)');
    $open = strpos($api, 'admin_notifications_open($pdo)');
    assertEq('API: POST-only open after CSRF validation', $csrf !== false && $open !== false && $csrf < $open && preg_match('/if\s*\(\$method === \'POST\'\)\s*\{\s*admin_notifications_open\(\$pdo\)/', $api) === 1 && strpos($api, "\$_SESSION['admin_csrf_token']") !== false, true);
    assertEq('API: rejects unsupported methods, token and action', strpos($api, "['GET', 'POST']") !== false && strpos($api, ', 405)') !== false && strpos($api, ', 403)') !== false && strpos($api, "(\$_POST['action'] ?? '') !== 'open'") !== false, true);
    assertEq('API: latest uses fixed default; count follows open', strpos($api, 'admin_notifications_latest($pdo)') !== false && strpos($api, 'admin_notifications_count($pdo)') > $open, true);
    assertEq('latest: default five, hard cap 21 and ordered offset', strpos($helper, 'int $limit = 5, int $offset = 0') !== false && strpos($helper, 'min(21, $limit)') !== false && strpos($helper, 'max(0, $offset)') !== false && strpos($helper, 'ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset') !== false, true);
    assertEq('history: validated page, 21-row sentinel, 20-row offset/display', strpos($history, 'FILTER_VALIDATE_INT') !== false && strpos($history, 'admin_notifications_latest($pdo, 21, ($page - 1) * 20)') !== false && strpos($history, 'array_slice($notifications, 0, 20)') !== false, true);
    assertEq('history never marks notifications read', preg_match('/admin_notifications_(open|mark_read)\s*\(/', $history), 0);
    $openSource = substr($helper, strpos($helper, 'function admin_notifications_open('));
    assertEq('open: server snapshot, mark, commit and failure rollback; no removal', preg_match('/beginTransaction\(\).*admin_notifications_unread_ids\(\$pdo\).*admin_notifications_mark_read\(\$pdo, \$ids\).*commit\(\).*catch.*rollBack\(\)/s', $openSource) === 1 && stripos($helper, 'DELETE FROM') === false, true);

    $create = strpos($checkout, 'admin_notifications_create($pdo, $orderId)');
    foreach (['$pdo->beginTransaction()', '$orderStmt->execute(', '$orderItemStmt->execute(', '$paymentStmt->execute(', '$shipmentStmt->execute(', '$soldProductsStmt->execute(', '$removeCartItemsStmt->execute('] as $dependency) {
        $position = strpos($checkout, $dependency);
        assertEq('checkout: create after ' . $dependency, $position !== false && $create !== false && $position < $create, true);
    }
    assertEq('checkout: create immediately before commit', preg_match('/admin_notifications_create\(\$pdo, \$orderId\);\s*\$pdo->commit\(\);/', $checkout), 1);
    $creators = [];
    $inserters = [];
    foreach (['admin', 'customer', 'includes'] as $directory) {
        $base = __DIR__ . '/../../' . $directory;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $directory . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            $code = sourceCode($path);
            if ($path !== 'includes/admin_notifications.php' && preg_match('/\badmin_notifications_create\s*\(/', $code)) {
                $creators[] = $path;
            }
            if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+admin_order_notifications/i', $code)) {
                $inserters[] = $path;
            }
        }
    }
    assertEq('only checkout calls creation, not status/payment/shipment updates', $creators, ['customer/checkout.php']);
    assertEq('only notification helper inserts notifications', $inserters, ['includes/admin_notifications.php']);
    $partial = sourceCode('includes/ui.admin-notifications.php');
    assertEq('bell: guarded admin, escaped CSRF, accessible toggle and history link', strpos($partial, "empty(\$_SESSION['admin_id'])") !== false && strpos($partial, "hopia_e(\$_SESSION['admin_csrf_token'])") !== false && strpos($partial, 'aria-expanded="false"') !== false && strpos($partial, 'href="notifications.php"') !== false, true);
    assertEq('shared admin header includes bell', strpos(sourceCode('includes/ui.header.php'), "'/ui.admin-notifications.php'") !== false, true);
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
        'admin_order_notifications' => 'id, order_id, notification_type, delivery_method, payment_method, created_at, read_at',
        'customers' => 'id, first_name, last_name, email, password_hash',
        'orders' => 'id, customer_id, status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address',
        'payments' => 'id, order_id, payment_method, payment_status',
        'shipments' => 'id, order_id, delivery_method, shipment_status',
        'order_items' => 'id, order_id',
        'sales_transactions' => 'id, order_id',
        'sales_transaction_items' => 'id, sale_id',
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
    fwrite(STDERR, "SKIP: Phase 13 integration not run; no fixtures written.\n" . $e->getMessage() . "\nRequires separately prepared local " . TEST_DB_NAME . " through migration 014. No DDL performed.\n");
    exit(2);
}

$customerId = null;
$suffix = bin2hex(random_bytes(5));
$makeOrder = static function (PDO $db, string $delivery = 'LOCAL_COURIER', string $payment = 'COD') use (&$customerId, $suffix): int {
    $db->prepare("INSERT INTO orders (customer_id, status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address) VALUES (?, 'PENDING', 150, 0, 150, ?, '0900', 'Phase13 fixture')")
        ->execute([$customerId, 'Phase13 ' . $suffix]);
    $id = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO payments (order_id, payment_method, payment_status) VALUES (?, ?, 'PENDING')")->execute([$id, $payment]);
    $db->prepare("INSERT INTO shipments (order_id, delivery_method, shipment_status) VALUES (?, ?, 'NOT_SHIPPED')")->execute([$id, $delivery]);
    return $id;
};
$notification = static function (PDO $db, int $id): array {
    $stmt = $db->prepare('SELECT * FROM admin_order_notifications WHERE order_id = ? ORDER BY id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
};
$snapshot = static function (PDO $db, int $id): array {
    $state = [];
    foreach (['orders' => 'id', 'payments' => 'order_id', 'shipments' => 'order_id', 'order_items' => 'order_id', 'sales_transactions' => 'order_id'] as $table => $key) {
        $stmt = $db->prepare("SELECT * FROM {$table} WHERE {$key} = ? ORDER BY id");
        $stmt->execute([$id]);
        $state[$table] = $stmt->fetchAll();
    }
    return $state;
};

try {
    echo "== Real DB: atomic creation, snapshot and idempotency ==\n";
    $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (?, ?, ?, ?)')->execute(['Phase13', $suffix, "phase13+{$suffix}@test.local", 'x']);
    $customerId = (int) $pdo->lastInsertId();
    $pdo->beginTransaction();
    $committed = $makeOrder($pdo, 'STORE_PICKUP', 'GCASH');
    $before = $snapshot($pdo, $committed);
    admin_notifications_create($pdo, $committed);
    $pdo->commit();
    $original = $notification($second, $committed);
    assertEq('committed order has exactly one notification visible on second connection', count($original), 1);
    assertEq('actual initial shipment/payment snapshot, unread NEW_ORDER', [$original[0]['delivery_method'], $original[0]['payment_method'], $original[0]['notification_type'], $original[0]['read_at']], ['STORE_PICKUP', 'GCASH', 'NEW_ORDER', null]);
    assertEq('creation leaves order/payment/shipment/items/sales unchanged', $snapshot($pdo, $committed), $before);
    $pdo->beginTransaction();
    $rolledBack = $makeOrder($pdo);
    admin_notifications_create($pdo, $rolledBack);
    $pdo->rollBack();
    assertEq('rollback removes order and notification together', [$snapshot($second, $rolledBack)['orders'], $notification($second, $rolledBack)], [[], []]);
    $rejected = false;
    try {
        admin_notifications_create($pdo, $committed);
    } catch (LogicException $e) {
        $rejected = true;
    }
    assertEq('creation requires caller transaction', $rejected, true);

    $pdo->beginTransaction();
    admin_notifications_mark_read($pdo, [(int) $original[0]['id']]);
    $readOriginal = $notification($pdo, $committed);
    $pdo->prepare("UPDATE payments SET payment_method = 'CASH_ON_PICKUP', payment_status = 'PAID' WHERE order_id = ?")->execute([$committed]);
    $pdo->prepare("UPDATE shipments SET delivery_method = 'LOCAL_COURIER', shipment_status = 'READY_TO_SHIP' WHERE order_id = ?")->execute([$committed]);
    $pdo->prepare("UPDATE orders SET status = 'CONFIRMED' WHERE id = ?")->execute([$committed]);
    $changed = $snapshot($pdo, $committed);
    admin_notifications_create($pdo, $committed);
    assertEq('duplicate create preserves entire original snapshot and read state', $notification($pdo, $committed), $readOriginal);
    assertEq('duplicate create changes no business state', $snapshot($pdo, $committed), $changed);
    $pdo->rollBack();

    echo "== Real DB: count, deterministic five and bounded history (rolled back) ==\n";
    $pdo->beginTransaction();
    $baseline = admin_notifications_count($pdo);
    $fixtureIds = [];
    for ($i = 0; $i < 22; $i++) {
        $id = $makeOrder($pdo);
        admin_notifications_create($pdo, $id);
        $fixtureIds[] = (int) $notification($pdo, $id)[0]['id'];
    }
    // Same timestamp exercises the ID tiebreaker; only this run's rows change.
    $marks = implode(',', array_fill(0, count($fixtureIds), '?'));
    $pdo->prepare("UPDATE admin_order_notifications SET created_at = '2037-01-01 00:00:00' WHERE id IN ({$marks})")->execute($fixtureIds);
    assertEq('DB count includes all 22 new unread rows, not only dropdown', admin_notifications_count($pdo), $baseline + 22);
    $ordered = array_map('intval', $pdo->query('SELECT id FROM admin_order_notifications ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN));
    assertEq('latest returns exactly five in created_at/id descending order', array_column(admin_notifications_latest($pdo), 'id'), array_slice($ordered, 0, 5));
    $expectedFixtures = array_reverse($fixtureIds);
    assertEq('same-time fixture ordering uses descending ID', array_values(array_intersect($ordered, $fixtureIds)), $expectedFixtures);
    assertEq('history caps oversized limit at 21', array_column(admin_notifications_latest($pdo, 999), 'id'), array_slice($ordered, 0, 21));
    assertEq('history second page starts at offset 20', array_column(admin_notifications_latest($pdo, 21, 20), 'id'), array_slice($ordered, 20, 21));
    assertEq('negative limit/offset clamp to one/zero', array_column(admin_notifications_latest($pdo, -1, -1), 'id'), array_slice($ordered, 0, 1));
    $pdo->rollBack();

    echo "== Real DB: exact unread membership, new arrival and late lower ID ==\n";
    $second->beginTransaction();
    $lateOrder = $makeOrder($second);
    admin_notifications_create($second, $lateOrder);
    $lateId = (int) $notification($second, $lateOrder)[0]['id'];
    $pdo->beginTransaction();
    $visibleOrder = $makeOrder($pdo);
    admin_notifications_create($pdo, $visibleOrder);
    $visibleId = (int) $notification($pdo, $visibleOrder)[0]['id'];
    $pdo->commit();
    $pdo->beginTransaction();
    $ids = array_map('intval', admin_notifications_unread_ids($pdo));
    assertEq('snapshot includes visible higher ID, excludes uncommitted lower ID', [$lateId < $visibleId, in_array($visibleId, $ids, true), in_array($lateId, $ids, true)], [true, true, false]);
    $second->commit();
    $second->beginTransaction();
    $newOrder = $makeOrder($second);
    admin_notifications_create($second, $newOrder);
    $second->commit();
    $states = [$snapshot($pdo, $committed), $snapshot($pdo, $lateOrder), $snapshot($pdo, $visibleOrder), $snapshot($pdo, $newOrder)];
    $rowsBefore = $pdo->query('SELECT * FROM admin_order_notifications ORDER BY id')->fetchAll();
    admin_notifications_mark_read($pdo, $ids);
    $rowsAfter = $pdo->query('SELECT * FROM admin_order_notifications ORDER BY id')->fetchAll();
    $expected = $rowsBefore;
    foreach ($expected as &$entry) {
        if (in_array((int) $entry['id'], $ids, true)) {
            $entry['read_at'] = 'READ';
        }
    }
    unset($entry);
    $actual = $rowsAfter;
    foreach ($actual as &$entry) {
        if (in_array((int) $entry['id'], $ids, true) && $entry['read_at'] !== null) {
            $entry['read_at'] = 'READ';
        }
    }
    unset($entry);
    assertEq('mark changes only read_at of exact snapshot IDs; removes no rows', $actual, $expected);
    assertEq('late lower ID and post-snapshot arrival stay unread', [$notification($pdo, $lateOrder)[0]['read_at'], $notification($pdo, $newOrder)[0]['read_at']], [null, null]);
    assertEq('DB unread count reflects only IDs actually marked', admin_notifications_count($pdo), count(array_filter($rowsBefore, static fn(array $row): bool => $row['read_at'] === null)) - count($ids));
    admin_notifications_mark_read($pdo, []);
    admin_notifications_mark_read($pdo, $ids);
    assertEq('empty/repeated mark preserves rows and first read timestamps', $pdo->query('SELECT * FROM admin_order_notifications ORDER BY id')->fetchAll(), $rowsAfter);
    assertEq('read helpers leave all fixture business state unchanged', [$snapshot($pdo, $committed), $snapshot($pdo, $lateOrder), $snapshot($pdo, $visibleOrder), $snapshot($pdo, $newOrder)], $states);
    $pdo->rollBack();
    assertEq('rollback restores all pre-existing and fixture notification read states', $pdo->query('SELECT * FROM admin_order_notifications ORDER BY id')->fetchAll(), $rowsBefore);
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
                $pdo->prepare('DELETE sti FROM sales_transaction_items sti INNER JOIN sales_transactions st ON st.id = sti.sale_id WHERE st.order_id = ?')->execute([$id]);
                foreach (['admin_order_notifications', 'sales_transactions', 'order_items', 'payments', 'shipments'] as $table) {
                    $pdo->prepare("DELETE FROM {$table} WHERE order_id = ?")->execute([$id]);
                }
                $pdo->prepare('DELETE FROM orders WHERE id = ? AND customer_id = ?')->execute([$id, $customerId]);
            }
            $pdo->prepare('DELETE FROM customers WHERE id = ? AND email = ?')->execute([$customerId, "phase13+{$suffix}@test.local"]);
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
echo "Global open commit and HTTP/browser behavior are not executed by this runner.\n";
exit($failures === 0 ? 0 : 1);
