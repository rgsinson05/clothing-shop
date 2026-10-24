<?php

/*
 * Hopia Fits - DB-free Store Pickup handover finalization boundary tests.
 *
 * Exercises pickup_finalization_block(), the PURE decision that stops the
 * generic one-step pickup advance from handing over an order whose payment
 * must be finalized through a dedicated action:
 *   - READY_FOR_PICKUP + unpaid Cash on Pickup  -> cop_finalization_required
 *   - READY_FOR_PICKUP + verified GCash         -> pg_finalization_required
 *   - every other stage/payment combination     -> null (generic advance ok)
 *
 * This is a DB-free suite: it loads no PDO connection, .env, database driver
 * or integration suite, exactly like database/tests/sales_unit_test.php. The
 * configured clothing_shop_bundle_test contains active data, NOT disposable
 * test data; this suite must never be replaced with active-database
 * integration tests.
 *
 *   php database/tests/pickup_finalization_unit_test.php
 */

require_once __DIR__ . '/../../includes/pickup.php';

$tests = 0;
$failures = 0;

function check(string $label, $actual, $expected): void
{
    global $tests, $failures;
    $tests++;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
        echo "  FAIL: {$label}\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
    } else {
        echo "  ok:   {$label}\n";
    }
}

$ready = 'READY_FOR_PICKUP';
$handover = 'PICKED_UP';
$preparing = 'PREPARING';

$copPending = ['payment_method' => 'CASH_ON_PICKUP', 'payment_status' => 'PENDING'];
$copPaid = ['payment_method' => 'CASH_ON_PICKUP', 'payment_status' => 'PAID'];
$gcashPending = ['payment_method' => 'GCASH', 'payment_status' => 'PENDING'];
$gcashPaid = ['payment_method' => 'GCASH', 'payment_status' => 'PAID'];
$cod = ['payment_method' => 'COD', 'payment_status' => 'PENDING'];
$missing = [];

check('ready unpaid COP requires COP finalizer', pickup_finalization_block($copPending, $handover, $ready), 'cop_finalization_required');
check('ready paid COP may use generic advance', pickup_finalization_block($copPaid, $handover, $ready), null);
check('ready verified GCash requires GCash finalizer', pickup_finalization_block($gcashPaid, $handover, $ready), 'pg_finalization_required');
check('ready pending GCash already blocked by payment gate', pickup_finalization_block($gcashPending, $handover, $ready), null);
check('ready COD (courier-only) not a pickup boundary', pickup_finalization_block($cod, $handover, $ready), null);
check('missing payment row is not a boundary block', pickup_finalization_block($missing, $handover, $ready), null);

check('entering PREPARING never triggers the handover boundary', pickup_finalization_block($copPending, $preparing, 'ORDER_RECEIVED'), null);
check('entering READY_FOR_PICKUP never triggers the handover boundary', pickup_finalization_block($copPending, $ready, $preparing), null);
check('advance from a non-ready stage cannot hit the boundary', pickup_finalization_block($gcashPaid, $handover, $preparing), null);

check('COP boundary has a user-safe message', pickup_message('cop_finalization_required') !== '', true);
check('GCash boundary has a user-safe message', pickup_message('pg_finalization_required') !== '', true);

// Source wiring: the transactional advance must actually invoke the boundary
// helper at the handover, so a future refactor cannot silently drop it.
$pickupSource = (string) file_get_contents(__DIR__ . '/../../includes/pickup.php');
check('advance calls the boundary helper', strpos($pickupSource, 'pickup_finalization_block($lockedPayment, $nextStage, $currentStage)') !== false, true);
check('boundary rejection rolls back before any write', preg_match('/if \(\$finalizationBlock !== null\) \{\s*\$pdo->rollBack\(\);/s', $pickupSource) === 1, true);

echo "\nRan {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);