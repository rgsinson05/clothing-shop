<?php

declare(strict_types=1);

// Test-only bootstrap. Never load application database.php or migration files.
// Every helper write uses the supplied, proven temporary namespace.
if (PHP_SAPI !== 'cli') exit(1);

function delivery_test_connection(): PDO
{
    require_once __DIR__ . '/../../vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->load();
    if (($_ENV['DB_NAME'] ?? '') !== 'clothing_shop_bundle_test'
        || !in_array($_ENV['DB_HOST'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Temporary tests require the inspected loopback test configuration.');
    }
    $db = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
        $_ENV['DB_USER'], $_ENV['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false]);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'clothing_shop_bundle_test') {
        throw new RuntimeException('Wrong database; no DDL/DML performed.');
    }
    echo 'Server: ' . $db->query('SELECT VERSION()')->fetchColumn() . PHP_EOL;
    $db->exec("SET time_zone = '+08:00'");
    $tables = ['admins', 'customers', 'orders', 'payments', 'shipments', 'order_items', 'products',
        'order_item_verifications', 'sales_transactions', 'sales_transaction_items', 'customer_order_notifications'];
    // LIKE copies definitions only, never records, foreign keys or triggers.
    foreach ($tables as $table) {
        $db->exec('CREATE TEMPORARY TABLE delivery_test_shadow LIKE ' . $table);
        $create = $db->query('SHOW CREATE TABLE delivery_test_shadow')->fetch(PDO::FETCH_NUM)[1];
        if (!str_starts_with($create, 'CREATE TEMPORARY TABLE') || !str_contains($create, 'ENGINE=InnoDB')) {
            throw new RuntimeException('Temporary source not proven before rename for ' . $table);
        }
        $db->exec('ALTER TABLE delivery_test_shadow RENAME TO ' . $table);
    }
    $db->exec("CREATE TEMPORARY TABLE delivery_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NOT NULL,
        delivery_cycle INT UNSIGNED NOT NULL, delivery_revision INT UNSIGNED NOT NULL,
        event_type VARCHAR(32) NOT NULL, admin_id INT UNSIGNED NOT NULL, attempt_number TINYINT UNSIGNED NULL,
        reason VARCHAR(255) NULL, previous_state VARCHAR(32) NOT NULL, resulting_state VARCHAR(32) NOT NULL,
        action_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, event_index TINYINT NOT NULL,
        request_hash CHAR(64) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (action_key, event_index), UNIQUE (order_id, delivery_cycle, attempt_number)) ENGINE=InnoDB");
    $db->exec("CREATE TEMPORARY TABLE delivery_event_items (event_id BIGINT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL, delivery_cycle INT UNSIGNED NOT NULL, order_item_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL, record_type VARCHAR(16) NOT NULL, outcome VARCHAR(32) NOT NULL,
        PRIMARY KEY(event_id, order_item_id), UNIQUE(order_id, delivery_cycle, order_item_id, record_type)) ENGINE=InnoDB");
    foreach (array_merge($tables, ['delivery_events', 'delivery_event_items']) as $table) {
        $create = $db->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
        if (!str_starts_with($create, 'CREATE TEMPORARY TABLE') || !str_contains($create, 'ENGINE=InnoDB')) {
            throw new RuntimeException('Isolation not proven for ' . $table);
        }
    }
    echo "Isolation proven: all 13 dependency tables are connection-local InnoDB TEMPORARY tables.\n";
    if ($db->query("SHOW COLUMNS FROM shipments LIKE 'delivery_revision'")->fetch() !== false
        || $db->query("SHOW COLUMNS FROM customer_order_notifications LIKE 'occurrence_key'")->fetch() !== false) {
        throw new RuntimeException('Expected pre-016 definitions; do not reconcile persistent schema for tests.');
    }
    echo "Schema detection before temporary extension: delivery_revision absent, legacy cutoff inactive (not an HTTP test).\n";
    // Test-only schema extension AFTER isolation proof, outside transactions.
    $db->exec("ALTER TABLE shipments MODIFY shipment_status ENUM('NOT_SHIPPED','READY_TO_SHIP','SHIPPED',
        'IN_TRANSIT','OUT_FOR_DELIVERY','DELIVERED','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP')
        NOT NULL DEFAULT 'NOT_SHIPPED', ADD delivery_cycle INT UNSIGNED NOT NULL DEFAULT 1,
        ADD delivery_revision INT UNSIGNED NOT NULL DEFAULT 0, ADD failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE customer_order_notifications ADD occurrence_key VARCHAR(64) NOT NULL DEFAULT 'legacy',
        MODIFY notification_type ENUM('ORDER_SHIPPED','ORDER_DELIVERED','PICKUP_READY','PICKUP_HANDED_OVER','ORDER_CANCELLED',
        'OUT_FOR_DELIVERY','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP','DELIVERY_CYCLE_AUTHORIZED') NOT NULL,
        DROP INDEX uq_customer_notification_order_type, ADD UNIQUE(customer_id,order_id,notification_type,occurrence_key)");
    if ($db->query("SHOW COLUMNS FROM shipments LIKE 'delivery_revision'")->fetch() === false) {
        throw new RuntimeException('Temporary post-schema cutoff not detected.');
    }
    echo "Schema detection after temporary extension: delivery_revision present, legacy cutoff active (not migration execution).\n";
    $db->exec("INSERT INTO admins(id,name,email,password_hash) VALUES(1,'Temporary Admin','temporary-admin@example.invalid','not-a-login')");
    $db->exec("INSERT INTO customers(id,first_name,last_name,email,password_hash) VALUES(1,'Temporary','Customer','temporary@example.invalid','not-a-login')");
    return $db;
}

function delivery_test_fixture(PDO $db, int $id, string $method = 'COD'): void
{
    $db->prepare("INSERT INTO orders(id,customer_id,status,subtotal,total_amount,shipping_name,shipping_phone,shipping_address)
        VALUES(?,1,'SHIPPED',100,100,'Temporary','000','Temporary fixture only')")->execute([$id]);
    $db->prepare("INSERT INTO products(id,name,category,condition_label,price,status) VALUES(?,'Temporary','SHIRTS','Test',100,'SOLD')")->execute([$id]);
    $db->prepare("INSERT INTO order_items(id,order_id,product_id,product_name,unit_price,quantity,subtotal)
        VALUES(?,?,?,'Temporary',100,1,100)")->execute([$id, $id, $id]);
    $db->prepare("INSERT INTO payments(order_id,payment_method,payment_status) VALUES(?,?,'PENDING')")->execute([$id, $method]);
    $db->prepare("INSERT INTO shipments(order_id,delivery_method,shipment_status,tracking_number,shipped_at)
        VALUES(?,'LOCAL_COURIER','SHIPPED','TEMPORARY',NOW())")->execute([$id]);
    $db->prepare("INSERT INTO order_item_verifications(order_id,order_item_id,product_id,verified_by,method,reason)
        VALUES(?,?,?,1,'MANUAL','Temporary legacy item')")->execute([$id, $id, $id]);
}
