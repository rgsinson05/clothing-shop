<?php

declare(strict_types=1);

// Capability probe, NOT a genuine contention test. Exit 3 means BLOCKED/SKIP,
// never a concurrency pass. See migration 016 runbook section 8 for the required
// shared-fixture tests. No persistent fixture fallback is permitted.
if (PHP_SAPI !== 'cli') exit(1);
if ($argc !== 2 || $argv[1] !== '--probe-temporary-isolation') {
    echo "BLOCKED: same-record concurrency requires shared fixtures; temporary tables are session-local.\n";
    echo "Use --probe-temporary-isolation only to verify that limitation, not to claim a race test passed.\n";
    exit(3);
}
require_once __DIR__ . '/../../includes/delivery.php';
require_once __DIR__ . '/delivery_temporary_fixture.php';
$a = $b = null;
try {
    $a = delivery_test_connection();
    $b = delivery_test_connection();
    if (!str_starts_with((string) $a->query('SELECT VERSION()')->fetchColumn(), '8.4.')
        || $a->query('SELECT CONNECTION_ID()')->fetchColumn() === $b->query('SELECT CONNECTION_ID()')->fetchColumn()) {
        throw new RuntimeException('Probe requires two independent MySQL 8.4 sessions.');
    }
    delivery_test_fixture($a, 1);
    delivery_test_fixture($b, 1);
    delivery_test_fixture($a, 2);
    $a->exec('UPDATE order_items SET product_id=1 WHERE order_id=2');
    $a->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $a->beginTransaction();
    $a->query('SELECT id FROM orders WHERE id=1 FOR UPDATE')->fetchAll();
    $a->query('SELECT id FROM products WHERE id=1 FOR UPDATE')->fetchAll();
    if (!inventory_has_other_hold_locked($a, 1, 1)) throw new RuntimeException('A fixture hold missing.');
    $key = str_repeat('a', 32);
    // B operates while A's transaction holds the identically numbered local
    // rows. It cannot see either A's locks or A's competing hold.
    $b->exec('SET SESSION innodb_lock_wait_timeout=2');
    $resultB = delivery_action($b, 1, 1, 'out_for_delivery', 1, 0, 'SHIPPED', $key);
    if (!$resultB['ok'] || $resultB['idempotent']
        || $a->query('SELECT COUNT(*) FROM delivery_events')->fetchColumn() !== 0
        || $b->query('SELECT COUNT(*) FROM delivery_events')->fetchColumn() !== 1) {
        throw new RuntimeException('Independent temporary helper execution not proven.');
    }
    $a->rollBack();
    // Remove only the temporary competing membership to exercise A's helper.
    $a->exec('UPDATE order_items SET product_id=2 WHERE order_id=2');
    $resultA = delivery_action($a, 1, 1, 'out_for_delivery', 1, 0, 'SHIPPED', $key);
    if (!$resultA['ok'] || $resultA['idempotent']
        || $a->query('SELECT COUNT(*) FROM delivery_events')->fetchColumn() !== 1) {
        throw new RuntimeException('A did not operate on its separate temporary fixture.');
    }
    echo "Isolation verified with real helper: B committed despite A's local locks/hold; each session has its own event for the same key.\n";
    echo "BLOCKED/SKIP: identical-key race, competing stale revisions and competing reservation/hold contention were NOT tested.\n";
    $a = $b = null;
    exit(3);
} catch (Throwable $e) {
    foreach ([$a, $b] as $db) if ($db !== null && $db->inTransaction()) $db->rollBack();
    $a = $b = null;
    echo 'FAIL isolation probe: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
