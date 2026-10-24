<?php

/*
 * Hopia Fits - Phase 6 GCash recovery tests (DEVELOPER-RUN, CLI only).
 *
 *   php database/tests/phase6_gcash_recovery_test.php --unit
 *     Decisions, rendered panel, proof validation and source contracts; no PDO/.env.
 *   php database/tests/phase6_gcash_recovery_test.php
 *     Also runs real transactional helpers against LOCAL clothing_shop_bundle_test.
 *
 * Full mode requires a separately prepared, explicitly authorized disposable
 * test DB instance; neither loopback nor the database name establishes safety.
 * Integration requires the existing schema through migration 013 plus 015
 * (customer order notifications). Missing notification columns or a non-InnoDB
 * notification table cause an explicit SKIP and exit 2 BEFORE any fixture writes.
 * This runner never applies migrations, creates tables (including temporary tables),
 * or includes includes/database.php. The .env database name is never used;
 * only loopback DB_HOST values and the dedicated test database are accepted.
 *
 * Fixtures have random Phase6 labels, real fixture admins/customers, and their
 * own SOLD product holds. Only those rows are removed in finally. Stored proof
 * paths are inert fixture strings; the private proof store is never written.
 * Proof validation uses one disposable sys_get_temp_dir() file. Render/source
 * checks are NOT HTTP/browser or executed authentication tests.
 *
 * Exit codes: 0 = requested checks passed, 1 = failure, 2 = integration not run
 * (unsafe/unavailable database, missing schema) or invalid CLI arguments.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}

$unitOnly = $argc === 2 && $argv[1] === '--unit';
if ($argc > 1 && !$unitOnly) {
    fwrite(STDERR, "Usage: php database/tests/phase6_gcash_recovery_test.php [--unit]\n");
    exit(2);
}

require_once __DIR__ . '/../../includes/payment.php';
require_once __DIR__ . '/../../includes/order_cancel.php';

const TEST_DB_NAME = 'clothing_shop_bundle_test';
date_default_timezone_set('Asia/Manila');

/* Same explicit assertions/exit status as phase2_gcash_payment_test.php. */
$tests = 0;
$failures = 0;
function assertTrue(string $label, bool $condition): void
{
    global $tests, $failures;
    $tests++;
    echo ($condition ? '  ok:   ' : '  FAIL: ') . $label . "\n";
    if (!$condition) {
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
        echo "  FAIL: {$label} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
    }
}

try {
    echo "== 1. Pure: Manila 24-hour deadline, boundaries and reloads ==\n";
    $order = [
        'id' => 1, 'status' => 'PENDING', 'cancellation_status' => 'NONE',
        'pickup_status' => null, 'received_at' => null, 'picked_up_at' => null,
        'has_completed_sale' => 0,
    ];
    $payment = [
        'payment_method' => 'GCASH', 'payment_status' => 'FAILED',
        'gcash_reference' => '1234567890123', 'submitted_at' => '2026-10-10 08:00:00',
        'rejection_reason' => 'Reference not found', 'rejected_at' => '2026-10-10 09:30:00',
        'verified_by' => null, 'verified_at' => null, 'revision' => 2,
        'checked_at' => '2026-10-10 09:30:00',
    ];
    $shipment = [
        'delivery_method' => 'LOCAL_COURIER', 'shipment_status' => 'NOT_SHIPPED',
        'shipped_at' => null, 'delivered_at' => null, 'has_completed_sale' => false,
    ];
    foreach ([
        ['at rejection', '2026-10-10 09:30:00', 86400, false],
        ['reload six hours later', '2026-10-10 15:30:00', 64800, false],
        ['one second before deadline', '2026-10-11 09:29:59', 1, false],
        ['exactly at deadline', '2026-10-11 09:30:00', 0, true],
        ['one second after deadline', '2026-10-11 09:30:01', 0, true],
    ] as [$label, $checkedAt, $seconds, $expired]) {
        $row = array_replace($payment, ['checked_at' => $checkedAt]);
        $window = payment_gcash_recovery_window($row);
        assertEq("1. {$label}: deadline is fixed", $window['deadline_at'] ?? null, '2026-10-11 09:30:00');
        assertEq("1. {$label}: integer seconds", $window['remaining_seconds'] ?? null, $seconds);
        assertEq("1. {$label}: expired", $window['expired'] ?? null, $expired);
        assertEq("1. {$label}: resubmission boundary", payment_gcash_submission_reason($order, $row, $shipment), $expired ? 'deadline_expired' : null);
        assertEq("1. {$label}: cancellation boundary", payment_gcash_expired_cancel_reason($order, $row, $shipment), $expired ? null : 'deadline_not_expired');
    }

    $expiredPayment = array_replace($payment, ['checked_at' => '2026-10-12 12:00:00']);
    assertEq('1. expiry is not a common recovery/switch restriction', payment_gcash_recovery_reason($order, $expiredPayment, $shipment), null);
    $latest = array_replace($expiredPayment, ['rejected_at' => '2026-10-12 12:00:00']);
    $latestWindow = payment_gcash_recovery_window($latest);
    assertEq('1. latest rejection starts its own deadline', $latestWindow['deadline_at'] ?? null, '2026-10-13 12:00:00');
    assertEq('1. latest rejection grants 24 hours', $latestWindow['remaining_seconds'] ?? null, 86400);
    $unrelated = array_replace($payment, ['updated_at' => '2026-10-20 12:00:00', 'submitted_at' => '2026-10-20 12:00:00']);
    assertEq('1. submission/update timestamps never reset rejection deadline', payment_gcash_recovery_window($unrelated), payment_gcash_recovery_window($payment));

    foreach ([null, '', 'not-a-date', '2026-02-30 09:30:00'] as $timestamp) {
        $legacy = array_replace($unrelated, ['rejected_at' => $timestamp]);
        $window = payment_gcash_recovery_window($legacy);
        assertTrue('1. unknown window contains nullable deadline and seconds', array_key_exists('deadline_at', $window) && array_key_exists('remaining_seconds', $window));
        assertEq('1. missing/invalid rejection time stays unknown', [$window['deadline_at'] ?? null, $window['remaining_seconds'] ?? null, $window['expired'] ?? null], [null, null, false]);
        assertEq('1. unknown deadline blocks resubmit', payment_gcash_submission_reason($order, $legacy, $shipment), 'deadline_unknown');
        assertEq('1. unknown deadline blocks expiry cancellation', payment_gcash_expired_cancel_reason($order, $legacy, $shipment), 'deadline_unknown');
        assertEq('1. unknown deadline does not itself block cash recovery', payment_gcash_recovery_reason($order, $legacy, $shipment), null);
    }
    $missingTimestamp = $payment;
    unset($missingTimestamp['rejected_at']);
    assertEq('1. absent legacy rejection field is not inferred from other timestamps', payment_gcash_recovery_window($missingTimestamp)['deadline_at'] ?? null, null);

    date_default_timezone_set('America/New_York');
    $dstWindow = payment_gcash_recovery_window(['rejected_at' => '2026-03-07 12:00:00', 'checked_at' => '2026-03-07 12:00:00']);
    assertEq('1. Manila window ignores ambient DST timezone', [$dstWindow['deadline_at'] ?? null, $dstWindow['remaining_seconds'] ?? null], ['2026-03-08 12:00:00', 86400]);
    date_default_timezone_set('Asia/Manila');

    echo "== 2. Pure: current payment/order/fulfillment eligibility ==\n";
    foreach (['PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED', 'CANCELLED'] as $status) {
        $row = array_replace($order, ['status' => $status]);
        $reason = in_array($status, ['PENDING', 'CONFIRMED', 'PACKED'], true) ? null : ($status === 'CANCELLED' ? 'order_cancelled' : 'order_incompatible');
        assertEq("2. {$status}: common recovery", payment_gcash_recovery_reason($row, $payment, $shipment), $reason);
        assertEq("2. {$status}: timely resubmission", payment_gcash_submission_reason($row, $payment, $shipment), $reason);
        assertEq("2. {$status}: expired cancellation still requires PENDING", payment_gcash_expired_cancel_reason($row, $expiredPayment, $shipment), $status === 'PENDING' ? null : ($reason ?? 'order_incompatible'));
    }
    foreach ([
        ['not submitted', ['payment_status' => 'PENDING', 'gcash_reference' => null, 'submitted_at' => null], 'not_rejected', null],
        ['awaiting review', ['payment_status' => 'PENDING'], 'not_rejected', 'already_submitted'],
        ['paid', ['payment_status' => 'PAID'], 'already_paid', 'already_paid'],
        ['refunded is not FAILED', ['payment_status' => 'REFUNDED'], 'not_rejected', 'not_rejected'],
        ['verifier on inconsistent FAILED row', ['verified_by' => 1], 'already_paid', 'already_paid'],
        ['verification time on inconsistent FAILED row', ['verified_at' => '2026-10-10 08:30:00'], 'already_paid', 'already_paid'],
        ['COD pending', ['payment_method' => 'COD', 'payment_status' => 'PENDING'], 'not_gcash', 'not_gcash'],
        ['COD collected', ['payment_method' => 'COD', 'payment_status' => 'PAID'], 'not_gcash', 'not_gcash'],
        ['pickup cash pending', ['payment_method' => 'CASH_ON_PICKUP', 'payment_status' => 'PENDING'], 'not_gcash', 'not_gcash'],
        ['pickup cash collected', ['payment_method' => 'CASH_ON_PICKUP', 'payment_status' => 'PAID'], 'not_gcash', 'not_gcash'],
    ] as [$label, $changes, $recoveryReason, $submissionReason]) {
        assertEq("2. {$label}: recovery", payment_gcash_recovery_reason($order, array_replace($payment, $changes), $shipment), $recoveryReason);
        assertEq("2. {$label}: submission", payment_gcash_submission_reason($order, array_replace($payment, $changes), $shipment), $submissionReason);
        assertEq("2. {$label}: expiry cannot bypass payment state", payment_gcash_expired_cancel_reason($order, array_replace($expiredPayment, $changes), $shipment), $recoveryReason);
    }
    foreach (['NONE', 'REJECTED', 'REQUESTED', 'APPROVED'] as $cancellation) {
        $row = array_replace($order, ['cancellation_status' => $cancellation]);
        $reason = $cancellation === 'REQUESTED' ? 'cancellation_pending' : ($cancellation === 'APPROVED' ? 'order_cancelled' : null);
        assertEq("2. cancellation {$cancellation}: recovery", payment_gcash_recovery_reason($row, $payment, $shipment), $reason);
        assertEq("2. cancellation {$cancellation}: submit", payment_gcash_submission_reason($row, $payment, $shipment), $reason);
        assertEq("2. cancellation {$cancellation}: explicit request allowance", payment_gcash_recovery_reason($row, $payment, $shipment, true), $cancellation === 'APPROVED' ? 'order_cancelled' : null);
        assertEq("2. cancellation {$cancellation}: expired admin cancellation", payment_gcash_expired_cancel_reason($row, $expiredPayment, $shipment), $cancellation === 'APPROVED' ? 'order_cancelled' : null);
    }
    foreach ([['has_completed_sale' => '1'], ['received_at' => '2026-10-10 10:00:00'], ['picked_up_at' => '2026-10-10 10:00:00']] as $changes) {
        $label = key($changes);
        $row = array_replace($order, $changes);
        assertEq("2. {$label} blocks recovery", payment_gcash_recovery_reason($row, $payment, $shipment), 'order_incompatible');
        assertEq("2. {$label} blocks submission", payment_gcash_submission_reason($row, $payment, $shipment), 'order_incompatible');
        assertEq("2. {$label} blocks cancellation", payment_gcash_expired_cancel_reason($row, $expiredPayment, $shipment), 'order_incompatible');
    }
    assertEq('2. PDO string zero is not a completed sale', payment_gcash_recovery_reason(array_replace($order, ['has_completed_sale' => '0']), $payment, $shipment), null);
    foreach ([null, 'ORDER_RECEIVED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP', 'UNKNOWN'] as $stage) {
        $row = array_replace($order, ['pickup_status' => $stage]);
        $ship = array_replace($shipment, ['delivery_method' => 'STORE_PICKUP']);
        $reason = in_array($stage, ['PICKED_UP', 'UNKNOWN'], true) ? 'order_incompatible' : null;
        assertEq('2. pickup ' . ($stage ?? 'legacy NULL') . ': recovery', payment_gcash_recovery_reason($row, $payment, $ship), $reason);
        assertEq('2. pickup ' . ($stage ?? 'legacy NULL') . ': expired cancellation', payment_gcash_expired_cancel_reason($row, $expiredPayment, $ship), $reason);
    }
    foreach (['NOT_SHIPPED', 'READY_TO_SHIP', 'SHIPPED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED'] as $status) {
        $ship = array_replace($shipment, ['shipment_status' => $status]);
        $reason = in_array($status, ['NOT_SHIPPED', 'READY_TO_SHIP'], true) ? null : 'order_incompatible';
        assertEq("2. shipment {$status}: recovery", payment_gcash_recovery_reason($order, $payment, $ship), $reason);
        assertEq("2. shipment {$status}: submission", payment_gcash_submission_reason($order, $payment, $ship), $reason);
        assertEq("2. shipment {$status}: cancellation", payment_gcash_expired_cancel_reason($order, $expiredPayment, $ship), $reason);
    }
    foreach ([null, [], array_replace($shipment, ['shipped_at' => '2026-10-10 10:00:00']), array_replace($shipment, ['delivered_at' => '2026-10-10 10:00:00']), array_replace($shipment, ['has_completed_sale' => true])] as $ship) {
        assertEq('2. missing shipment/completion evidence blocks recovery', payment_gcash_recovery_reason($order, $payment, $ship), 'order_incompatible');
        assertEq('2. missing shipment/completion evidence blocks submission', payment_gcash_submission_reason($order, $payment, $ship), 'order_incompatible');
        assertEq('2. missing shipment/completion evidence blocks cancellation', payment_gcash_expired_cancel_reason($order, $expiredPayment, $ship), 'order_incompatible');
    }
    foreach (['LOCAL_COURIER' => 'COD', 'STORE_PICKUP' => 'CASH_ON_PICKUP', 'COD' => null, 'PICKUP' => null, 'local_courier' => null, '' => null] as $method => $expected) {
        assertEq("2. strict cash mapping for '{$method}'", payment_gcash_cash_method(['delivery_method' => $method]), $expected);
    }
    assertEq('2. missing shipment has no cash fallback', payment_gcash_cash_method(null), null);
    assertEq('2. missing delivery method has no cash fallback', payment_gcash_cash_method([]), null);
    assertEq('2. legacy NULL delivery method has no cash fallback', payment_gcash_cash_method(['delivery_method' => null]), null);

    echo "== 3. Pure: Phase 5 fulfillment gate remains independent ==\n";
    assertEq('3. GCash without a submission is still blocked', payment_gcash_fulfillment_reason(array_replace($payment, ['payment_status' => 'PENDING', 'gcash_reference' => null, 'submitted_at' => null])), 'gcash_awaiting_verification');
    foreach (['PENDING' => 'gcash_awaiting_verification', 'FAILED' => 'gcash_rejected', 'REFUNDED' => 'gcash_rejected', 'PAID' => null] as $status => $expected) {
        assertEq("3. GCASH {$status}", payment_gcash_fulfillment_reason(array_replace($payment, ['payment_status' => $status])), $expected);
    }
    foreach (['COD', 'CASH_ON_PICKUP'] as $method) {
        assertEq("3. {$method} pending keeps established cash workflow", payment_gcash_fulfillment_reason(array_replace($payment, ['payment_method' => $method, 'payment_status' => 'PENDING'])), null);
    }

    echo "== 4. API/source contracts ONLY (not HTTP execution) ==\n";
    foreach (['payment_gcash_submit' => 6, 'payment_gcash_switch_to_cash' => 5, 'payment_gcash_verify' => 4, 'payment_gcash_reject' => 5, 'order_cancel_expired_gcash' => 5] as $function => $arity) {
        $reflection = new ReflectionFunction($function);
        $parameters = $reflection->getParameters();
        $last = $parameters[count($parameters) - 1];
        assertEq("4. {$function}: existing arguments plus revision", count($parameters), $arity);
        assertTrue("4. {$function}: optional nullable integer expectedRevision", $last->getName() === 'expectedRevision' && $last->isOptional() && $last->allowsNull() && $last->hasType() && $last->getType()->getName() === 'int' && $last->getDefaultValue() === null);
    }
    foreach ([
        ['customer/payment-submit.php', 'customer_id', 'csrf_token', ['payment_gcash_submit', 'payment_gcash_switch_to_cash']],
        ['admin/order-view.php', 'admin_id', 'admin_csrf_token', ['payment_gcash_verify', 'payment_gcash_reject', 'order_cancel_expired_gcash']],
    ] as [$path, $sessionKey, $csrfKey, $functions]) {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        if ($source === false) {
            throw new RuntimeException("Cannot inspect {$path}");
        }
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= is_array($token) ? $token[1] : $token;
            }
        }
        $auth = strpos($code, '!isset($_SESSION[\'' . $sessionKey . '\'])');
        $post = strpos($code, "['REQUEST_METHOD']");
        $csrf = strpos($code, 'hash_equals($_SESSION[\'' . $csrfKey . '\'],');
        $revision = strpos($code, "filter_input(INPUT_POST, 'payment_revision', FILTER_VALIDATE_INT");
        foreach ($functions as $function) {
            $call = strpos($code, $function . '(');
            assertTrue("4. source {$path}: {$function} behind auth/POST/CSRF", $call !== false && $auth !== false && $auth < $call && $post !== false && $post < $call && $csrf !== false && $csrf < $call && strpos(substr($code, $post, $call - $post), "'POST'") !== false);
            assertTrue("4. source {$path}: revision parsed before {$function}", $call !== false && $revision !== false && $revision < $call);
        }
        assertTrue("4. source {$path}: missing/invalid revision explicitly rejected", preg_match('/\$\w*[Rr]evision\s*===\s*null/', $code) === 1 && preg_match('/\$\w*[Rr]evision\s*===\s*false/', $code) === 1);
        if ($path === 'admin/order-view.php') {
            assertTrue('4. source: courier advance still calls Phase 5 locked gate', strpos($code, 'payment_gcash_fulfillment_block(') !== false);
        }
    }

    echo "== 4b. Actual customer panel rendering (not HTTP/browser execution) ==\n";
    require_once __DIR__ . '/../../includes/ui.php';
    require_once __DIR__ . '/../../includes/gcash.php';
    $renderPanel = static function (array $gcashPanel): string {
        $gcashPanel['state'] = payment_gcash_state($gcashPanel['payment']);
        ob_start();
        try {
            include __DIR__ . '/../../includes/ui.gcash-panel.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    };
    $panelPayment = array_replace($payment, [
        'rejection_reason' => 'Reference <script>"wrong"</script> & retry',
        'proof_path' => 'storage/payment_proofs/phase6-private-evidence.png',
        'checked_at' => '2026-10-10 15:45:00',
    ]);
    $correctionNotice = 'Your corrected GCash payment has been submitted and is awaiting admin verification.';
    foreach (['LOCAL_COURIER' => 'COD', 'STORE_PICKUP' => 'CASH_ON_PICKUP'] as $delivery => $cash) {
        $panel = [
            'order_id' => 1, 'amount' => $delivery === 'STORE_PICKUP' ? '150.00' : '230.00',
            'order' => array_replace($order, ['pickup_status' => $delivery === 'STORE_PICKUP' ? 'ORDER_RECEIVED' : null]),
            'shipment' => array_replace($shipment, ['delivery_method' => $delivery]), 'payment' => $panelPayment,
            'config' => ['configured' => true, 'name' => 'Phase6 Render Account', 'number' => '09000000000', 'has_qr' => false, 'qr' => '', 'missing' => []],
            'csrf' => 'phase6-render-token', 'return' => 'order-detail.php', 'proof_endpoint' => 'payment-proof.php',
        ];
        foreach ([
            ['open', [], [], '', 'Please correct your payment details and resubmit within 24 hours of the rejection.'],
            ['expired', [], ['checked_at' => '2026-10-11 09:30:00'], '', 'The 24-hour resubmission period has expired.'],
            ['awaiting', [], ['payment_status' => 'PENDING', 'rejected_at' => null, 'rejection_reason' => null], 'resubmitted', $correctionNotice],
            ['cancelled', ['status' => 'CANCELLED'], [], 'resubmitted', 'This order has been cancelled. Payment recovery is no longer available.'],
        ] as [$label, $orderChanges, $paymentChanges, $flash, $notice]) {
            $html = $renderPanel(array_replace($panel, [
                'order' => array_replace($panel['order'], $orderChanges),
                'payment' => array_replace($panelPayment, $paymentChanges), 'flash' => $flash,
            ]));
            $hasCash = in_array($label, ['open', 'expired'], true);
            assertTrue("4b. {$delivery} {$label}: actual state notice", strpos($html, $notice) !== false);
            assertEq("4b. {$delivery} {$label}: resubmit form visibility", substr_count($html, 'name="action" value="submit_gcash"'), $label === 'open' ? 1 : 0);
            assertEq("4b. {$delivery} {$label}: cash recovery visibility", substr_count($html, 'name="action" value="switch_to_cash"'), $hasCash ? 1 : 0);
            assertEq("4b. {$delivery} {$label}: matching cash option only", substr_count($html, '<option value="' . $cash . '">'), $hasCash ? 1 : 0);
            assertTrue("4b. {$delivery} {$label}: mismatched cash not offered", strpos($html, '<option value="' . ($cash === 'COD' ? 'CASH_ON_PICKUP' : 'COD') . '">') === false);
            assertTrue("4b. {$delivery} {$label}: private path never exposed", strpos($html, 'storage/payment_proofs/') === false && strpos($html, 'phase6-private-evidence.png') === false);
            assertTrue("4b. {$delivery} {$label}: proof uses authorized endpoint link", strpos($html, 'href="payment-proof.php?order=1"') !== false);
            if ($label === 'open' || $label === 'expired') {
                assertTrue("4b. {$delivery} {$label}: actual rejection text escaped", strpos($html, 'Reference &lt;script&gt;&quot;wrong&quot;&lt;/script&gt; &amp; retry') !== false && strpos($html, $panelPayment['rejection_reason']) === false);
                assertTrue("4b. {$delivery} {$label}: fixed Manila deadline", strpos($html, 'Oct 11, 2026 9:30 AM (Philippine time, UTC+8).') !== false);
            }
            if ($label === 'open') {
                assertTrue("4b. {$delivery}: supplied checked_at controls remaining text", strpos($html, '17 hours 45 minutes remaining as of this page load.') !== false);
                assertTrue("4b. {$delivery}: correction submit button rendered", strpos($html, 'Resubmit GCash Payment') !== false);
            }
            if ($label === 'cancelled') {
                assertTrue("4b. {$delivery}: cancelled correction flash suppressed", strpos($html, $correctionNotice) === false);
            }
        }
    }

    echo "== 4c. Actual temporary-file proof validation (not HTTP upload) ==\n";
    $proofPath = tempnam(sys_get_temp_dir(), 'hopia_p6_');
    if ($proofPath === false) {
        throw new RuntimeException('Cannot create disposable Phase6 proof validation file.');
    }
    try {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        foreach ([['PNG', $png, true, 'png'], ['fake image', 'This is not an image.', false, 'proof_invalid_type'], ['oversize PNG', str_pad($png, PAYMENT_PROOF_MAX_BYTES + 1, "\0"), false, 'proof_too_large']] as [$label, $bytes, $ok, $expected]) {
            if (file_put_contents($proofPath, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Cannot write disposable Phase6 proof bytes.');
            }
            clearstatcache(true, $proofPath);
            $validation = payment_proof_validate($proofPath, 1, UPLOAD_ERR_OK);
            assertEq("4c. {$label}: real bytes validated despite reported size=1", [$validation['ok'] ?? null, $validation['ext'] ?? $validation['reason'] ?? null], [$ok, $expected]);
            if ($label === 'PNG') {
                assertEq('4c. partial upload rejected even with valid PNG bytes', payment_proof_validate($proofPath, 1, UPLOAD_ERR_PARTIAL)['reason'] ?? null, 'proof_failed');
            }
        }
    } finally {
        assertTrue('4c. disposable proof validation file removed', unlink($proofPath));
    }
} catch (Throwable $e) {
    $failures++;
    echo '  FAIL: no-DB exception: ' . $e->getMessage() . "\n";
    echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    date_default_timezone_set('Asia/Manila');
}

echo "\nNo-DB checks: {$tests} checks, {$failures} failure(s).\n";
if ($unitOnly || $failures > 0) {
    echo "Integration not run.\n";
    exit($failures === 0 ? 0 : 1);
}

/* No application database bootstrap: it could connect to the production DB. */
try {
    require_once __DIR__ . '/../../vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Refusing non-loopback DB_HOST; only a local test database is permitted.');
    }
    $pdo = new PDO('mysql:host=' . $host . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4', $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connectedDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($connectedDb !== TEST_DB_NAME) {
        throw new RuntimeException("Refusing connected database '{$connectedDb}'; expected " . TEST_DB_NAME . '.');
    }
    $pdo->exec("SET time_zone = '+08:00'");
    $missing = [];
    foreach (['payments' => ['rejected_at', 'revision'], 'orders' => ['cancellation_reason', 'cancelled_by', 'cancelled_at']] as $table => $columns) {
        foreach ($columns as $column) {
            if ($pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $pdo->quote($column))->fetch() === false) {
                $missing[] = $table . '.' . $column;
            }
        }
    }
    if ($missing !== []) {
        throw new RuntimeException('Migration 013 prerequisite missing: ' . implode(', ', $missing));
    }
    /* Check older prerequisites read-only before inserting any fixtures. */
    $pdo->query('SELECT cancellation_status, received_at, pickup_status, ready_for_pickup_at, pickup_deadline_at, picked_up_at FROM orders LIMIT 0');
    $pdo->query('SELECT gcash_reference, proof_path, submitted_at, verified_by, verified_at, rejection_reason FROM payments LIMIT 0');
    $pdo->query('SELECT delivery_method, shipment_status, shipped_at, delivered_at FROM shipments LIMIT 0');
    $pdo->query('SELECT barcode_value FROM products LIMIT 0');
    foreach (['order_item_verifications', 'sales_transactions', 'sales_transaction_items'] as $table) {
        $pdo->query("SELECT id FROM {$table} LIMIT 0");
    }
    /* Migration 015 is required by the pickup and cancellation helpers. */
    $pdo->query('SELECT id, customer_id, order_id, notification_type, message, created_at, read_at FROM customer_order_notifications LIMIT 0');
    $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $engine->execute([TEST_DB_NAME, 'customer_order_notifications']);
    if (strcasecmp((string) $engine->fetchColumn(), 'InnoDB') !== 0) {
        throw new RuntimeException('Migration 015 prerequisite: customer_order_notifications must use InnoDB for transactional notifications.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, "SKIP: Phase 6 integration not run; no fixtures written.\n" . $e->getMessage() . "\n");
    fwrite(STDERR, 'Requires a separately prepared, explicitly authorized disposable local ' . TEST_DB_NAME . " with migrations through 013 plus 015. The database name alone is not a safety guarantee. This runner performs no DDL.\n");
    exit(2);
}

require_once __DIR__ . '/../../includes/pickup.php';
require_once __DIR__ . '/../../includes/sales.php';

$suffix = bin2hex(random_bytes(4));
$created = ['admins' => [], 'customers' => [], 'orders' => [], 'products' => []];

function makeRecoveryOrder(PDO $pdo, int $customerId, int $adminId, string $deliveryMethod = 'LOCAL_COURIER'): int
{
    global $created, $suffix;
    $pickup = $deliveryMethod === 'STORE_PICKUP';
    $pdo->prepare(
        'INSERT INTO orders (customer_id, status, cancellation_status, subtotal, shipping_fee, total_amount, shipping_name, shipping_phone, shipping_address, pickup_status)
         VALUES (?, \'PENDING\', \'NONE\', 150.00, ?, ?, ?, \'0900\', \'Phase6 fixture address\', ?)'
    )->execute([$customerId, $pickup ? '0.00' : '80.00', $pickup ? '150.00' : '230.00', "Phase6 {$suffix}", $pickup ? 'ORDER_RECEIVED' : null]);
    $orderId = (int) $pdo->lastInsertId();
    $created['orders'][] = $orderId;
    $pdo->prepare("INSERT INTO products (name, category, condition_label, price, status) VALUES (?, 'SHIRTS', 'Phase6 fixture', 150.00, 'SOLD')")
        ->execute(["P6 {$suffix} order {$orderId}"]);
    $productId = (int) $pdo->lastInsertId();
    $created['products'][] = $productId;
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, subtotal) VALUES (?, ?, ?, 150.00, 1, 150.00)')
        ->execute([$orderId, $productId, "P6 {$suffix} item"]);
    $itemId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_item_verifications (order_id, order_item_id, product_id, verified_by, method, reason) VALUES (?, ?, ?, ?, 'MANUAL', 'Phase6 fixture')")
        ->execute([$orderId, $itemId, $productId, $adminId]);
    $pdo->prepare("INSERT INTO shipments (order_id, shipment_status, delivery_method) VALUES (?, 'NOT_SHIPPED', ?)")
        ->execute([$orderId, $deliveryMethod]);
    $pdo->prepare(
        'INSERT INTO payments (order_id, payment_method, payment_status, gcash_reference, proof_path, submitted_at, rejection_reason, rejected_at, revision)
         VALUES (?, \'GCASH\', \'FAILED\', ?, ?, DATE_SUB(NOW(), INTERVAL 2 HOUR), \'Phase6 original rejection\', DATE_SUB(NOW(), INTERVAL 1 HOUR), 2)'
    )->execute([$orderId, "P6-{$suffix}-{$orderId}", "storage/payment_proofs/phase6-{$suffix}-{$orderId}.png"]);
    return $orderId;
}

/* Every comparison is scoped to this run's order, never global row counts. */
function recoverySnapshot(PDO $pdo, int $orderId): array
{
    return [
        'order' => $pdo->query("SELECT * FROM orders WHERE id = {$orderId}")->fetch(),
        'payment' => $pdo->query("SELECT * FROM payments WHERE order_id = {$orderId}")->fetch(),
        'shipment' => $pdo->query("SELECT * FROM shipments WHERE order_id = {$orderId}")->fetch(),
        'items' => $pdo->query("SELECT * FROM order_items WHERE order_id = {$orderId} ORDER BY id")->fetchAll(),
        'products' => $pdo->query("SELECT DISTINCT p.* FROM products p INNER JOIN order_items oi ON oi.product_id = p.id WHERE oi.order_id = {$orderId} ORDER BY p.id")->fetchAll(),
        'verifications' => $pdo->query("SELECT * FROM order_item_verifications WHERE order_id = {$orderId} ORDER BY id")->fetchAll(),
        'sales' => $pdo->query("SELECT * FROM sales_transactions WHERE order_id = {$orderId} ORDER BY id")->fetchAll(),
        'sale_items' => $pdo->query("SELECT sti.* FROM sales_transaction_items sti INNER JOIN sales_transactions st ON st.id = sti.sale_id WHERE st.order_id = {$orderId} ORDER BY sti.id")->fetchAll(),
    ];
}

$blocked = static function (string $label, int $orderId, callable $action, string $reason) use ($pdo): void {
    $before = recoverySnapshot($pdo, $orderId);
    $result = $action();
    assertEq($label, [$result['ok'] ?? null, $result['reason'] ?? null], [false, $reason]);
    assertTrue($label . ': all persisted rows unchanged', recoverySnapshot($pdo, $orderId) === $before);
    assertTrue($label . ': transaction closed', !$pdo->inTransaction());
};

try {
    $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (?, ?, ?)')
        ->execute(['Phase6 Admin', "phase6admin+{$suffix}@test.local", 'x']);
    $adminId = (int) $pdo->lastInsertId();
    $created['admins'][] = $adminId;
    foreach (['owner', 'other'] as $name) {
        $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (?, ?, ?, ?)')
            ->execute(['Phase6', $name, "phase6{$name}+{$suffix}@test.local", 'x']);
        $created['customers'][] = (int) $pdo->lastInsertId();
    }
    [$customerId, $otherCustomerId] = $created['customers'];

    echo "== 5. Integration: resubmit, latest rejection, stale review, Phase 5 ==\n";
    $resubmit = makeRecoveryOrder($pdo, $customerId, $adminId, 'STORE_PICKUP');
    $original = recoverySnapshot($pdo, $resubmit);
    assertEq('5. fixture SOLD is a hold, not a completed sale', $original['sales'], []);
    $blocked('5. rejected GCash cannot begin pickup preparation', $resubmit, fn() => pickup_advance_status($pdo, $resubmit), 'gcash_rejected');
    $blocked('5. resubmit requires ownership', $resubmit, fn() => payment_gcash_submit($pdo, $otherCustomerId, $resubmit, '1234567890123', null, 2), 'not_found');
    $blocked('5. stale resubmit', $resubmit, fn() => payment_gcash_submit($pdo, $customerId, $resubmit, '1234567890123', null, 1), 'conflict');
    $blocked('5. blank reference', $resubmit, fn() => payment_gcash_submit($pdo, $customerId, $resubmit, " \t ", null, 2), 'reference_required');
    $blocked('5. malformed reference', $resubmit, fn() => payment_gcash_submit($pdo, $customerId, $resubmit, '12@@34', null, 2), 'reference_invalid');
    $result = payment_gcash_submit($pdo, $customerId, $resubmit, '  9900 1122 3344  ', null, 2);
    assertEq('5. corrected submission awaits verification', [$result['ok'] ?? null, $result['state'] ?? null], [true, 'AWAITING']);
    $pending = recoverySnapshot($pdo, $resubmit);
    assertEq('5. resubmit updates existing row', $pending['payment']['id'], $original['payment']['id']);
    assertEq('5. exactly one payment remains', (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE order_id = {$resubmit}")->fetchColumn(), 1);
    assertEq('5. normalized reference stored', $pending['payment']['gcash_reference'], '9900 1122 3344');
    assertEq('5. resubmit stays unpaid and increments revision', [$pending['payment']['payment_status'], (int) $pending['payment']['revision']], ['PENDING', 3]);
    assertEq('5. active rejection cleared', [$pending['payment']['rejection_reason'], $pending['payment']['rejected_at']], [null, null]);
    assertEq('5. optional absent upload retains prior proof', $pending['payment']['proof_path'], $original['payment']['proof_path']);
    assertTrue('5. resubmit changes no order/hold/shipment/verification/sale rows', array_diff_key($pending, ['payment' => true]) === array_diff_key($original, ['payment' => true]));
    $blocked('5. pending duplicate cannot overwrite newer reference', $resubmit, fn() => payment_gcash_submit($pdo, $customerId, $resubmit, '7777888899990', null, 3), 'already_submitted');
    $blocked('5. resubmission still blocked by Phase 5', $resubmit, fn() => pickup_advance_status($pdo, $resubmit), 'gcash_awaiting_verification');
    $blocked('5. stale admin verify', $resubmit, fn() => payment_gcash_verify($pdo, $adminId, $resubmit, 2), 'conflict');
    $blocked('5. stale admin reject', $resubmit, fn() => payment_gcash_reject($pdo, $adminId, $resubmit, 'Old form', 2), 'conflict');
    $blocked('5. blank rejection reason', $resubmit, fn() => payment_gcash_reject($pdo, $adminId, $resubmit, " \t\n", 3), 'reason_required');
    $blocked('5. overlong rejection reason', $resubmit, fn() => payment_gcash_reject($pdo, $adminId, $resubmit, str_repeat('x', 256), 3), 'reason_too_long');
    $beforeReject = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    $result = payment_gcash_reject($pdo, $adminId, $resubmit, '  Corrected reference still not found  ', 3);
    $rejected = recoverySnapshot($pdo, $resubmit);
    $afterReject = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    assertEq('5. admin rejection succeeds and increments revision', [$result['ok'] ?? null, $rejected['payment']['payment_status'], (int) $rejected['payment']['revision']], [true, 'FAILED', 4]);
    assertEq('5. rejection reason trimmed and durable', $rejected['payment']['rejection_reason'], 'Corrected reference still not found');
    assertTrue('5. rejected_at uses database NOW in Manila', $rejected['payment']['rejected_at'] !== null && $rejected['payment']['rejected_at'] >= $beforeReject && $rejected['payment']['rejected_at'] <= $afterReject);
    $blocked('5. duplicate rejection cannot restart deadline', $resubmit, fn() => payment_gcash_reject($pdo, $adminId, $resubmit, 'Duplicate', 4), 'not_pending');

    /* Backdating only this fixture avoids sleeps when testing a later rejection. */
    $pdo->prepare('UPDATE payments SET rejected_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE order_id = ?')->execute([$resubmit]);
    $earlierWindow = payment_gcash_recovery_window(payment_load($pdo, $resubmit));
    $pdo->prepare('UPDATE payments SET updated_at = DATE_ADD(NOW(), INTERVAL 2 HOUR) WHERE order_id = ?')->execute([$resubmit]);
    assertEq('5. reload after unrelated update keeps deadline', payment_gcash_recovery_window(payment_load($pdo, $resubmit))['deadline_at'], $earlierWindow['deadline_at']);
    assertTrue('5. next correction accepted', !empty(payment_gcash_submit($pdo, $customerId, $resubmit, '1111222233334', null, 4)['ok']));
    /* Simulate two submissions sharing the same second; revision must distinguish them. */
    $pdo->prepare('UPDATE payments SET submitted_at = ? WHERE order_id = ?')->execute([$pending['payment']['submitted_at'], $resubmit]);
    $blocked('5. same-second old verify form cannot verify newer submission', $resubmit, fn() => payment_gcash_verify($pdo, $adminId, $resubmit, 3), 'conflict');
    $blocked('5. same-second old rejection form cannot reject newer submission', $resubmit, fn() => payment_gcash_reject($pdo, $adminId, $resubmit, 'Old review', 3), 'conflict');
    assertTrue('5. latest rejection accepted', !empty(payment_gcash_reject($pdo, $adminId, $resubmit, 'Latest rejection', 5)['ok']));
    $beforeLoad = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    $loaded = payment_load($pdo, $resubmit);
    $afterLoad = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    $latestWindow = payment_gcash_recovery_window($loaded);
    assertEq('5. loaded revision includes latest rejection', (int) $loaded['revision'], 6);
    assertTrue('5. latest rejection replaces earlier window', $latestWindow['deadline_at'] > $earlierWindow['deadline_at']);
    assertEq('5. reloaded deadline agrees with independent DB date arithmetic', $latestWindow['deadline_at'], $pdo->query("SELECT DATE_ADD(rejected_at, INTERVAL 24 HOUR) FROM payments WHERE order_id = {$resubmit}")->fetchColumn());
    assertTrue('5. reloaded window uses fresh database checked_at', isset($loaded['checked_at']) && $loaded['checked_at'] >= $beforeLoad && $loaded['checked_at'] <= $afterLoad);
    assertTrue('5. final correction accepted', !empty(payment_gcash_submit($pdo, $customerId, $resubmit, '9999888877776', null, 6)['ok']));
    assertTrue('5. only explicit admin verification marks paid', !empty(payment_gcash_verify($pdo, $adminId, $resubmit, 7)['ok']));
    $paid = recoverySnapshot($pdo, $resubmit);
    assertEq('5. paid attribution and revision', [$paid['payment']['payment_status'], (int) $paid['payment']['verified_by'], (int) $paid['payment']['revision']], ['PAID', $adminId, 8]);
    assertTrue('5. complete correction/review loop never changes order, holds or sales', array_diff_key($paid, ['payment' => true]) === array_diff_key($original, ['payment' => true]));
    $blocked('5. duplicate verification', $resubmit, fn() => payment_gcash_verify($pdo, $adminId, $resubmit, 8), 'already_paid');

    echo "== 6. Integration: cash switch preserves evidence and fulfillment ==\n";
    foreach (['LOCAL_COURIER' => 'COD', 'STORE_PICKUP' => 'CASH_ON_PICKUP'] as $delivery => $cash) {
        $switch = makeRecoveryOrder($pdo, $customerId, $adminId, $delivery);
        /* Cash recovery remains available after the resubmission window expires. */
        $pdo->prepare('UPDATE payments SET rejected_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE order_id = ?')->execute([$switch]);
        $before = recoverySnapshot($pdo, $switch);
        $wrongCash = $cash === 'COD' ? 'CASH_ON_PICKUP' : 'COD';
        $blocked("6. {$delivery}: ownership", $switch, fn() => payment_gcash_switch_to_cash($pdo, $otherCustomerId, $switch, $cash, 2), 'not_found');
        $blocked("6. {$delivery}: stale revision", $switch, fn() => payment_gcash_switch_to_cash($pdo, $customerId, $switch, $cash, 1), 'conflict');
        foreach ([$wrongCash, 'GCASH', strtolower($cash)] as $invalid) {
            $blocked("6. {$delivery}: reject method {$invalid}", $switch, fn() => payment_gcash_switch_to_cash($pdo, $customerId, $switch, $invalid, 2), 'cash_method_invalid');
        }
        $blocked("6. {$delivery}: expired resubmit", $switch, fn() => payment_gcash_submit($pdo, $customerId, $switch, '1234567890123', null, 2), 'deadline_expired');
        $result = payment_gcash_switch_to_cash($pdo, $customerId, $switch, $cash, 2);
        $after = recoverySnapshot($pdo, $switch);
        assertEq("6. {$delivery}: switches existing payment to unpaid cash", [$result['ok'] ?? null, $after['payment']['id'], $after['payment']['payment_method'], $after['payment']['payment_status'], (int) $after['payment']['revision']], [true, $before['payment']['id'], $cash, 'PENDING', 3]);
        $cashChanges = array_fill_keys(['payment_method', 'payment_status', 'revision', 'updated_at'], true);
        assertEq("6. {$delivery}: all GCash evidence retained", array_diff_key($after['payment'], $cashChanges), array_diff_key($before['payment'], $cashChanges));
        assertTrue("6. {$delivery}: order, totals, holds, shipment, verification and sales untouched", array_diff_key($after, ['payment' => true]) === array_diff_key($before, ['payment' => true]));
        $blocked("6. {$delivery}: duplicate switch", $switch, fn() => payment_gcash_switch_to_cash($pdo, $customerId, $switch, $cash, 3), 'not_gcash');
        $blocked("6. {$delivery}: old GCash submit cannot undo switch", $switch, fn() => payment_gcash_submit($pdo, $customerId, $switch, '1234567890123', null, 3), 'not_gcash');
        $blocked("6. {$delivery}: old GCash review cannot mark cash paid", $switch, fn() => payment_gcash_verify($pdo, $adminId, $switch, 3), 'not_gcash');
        if ($delivery === 'STORE_PICKUP') {
            $blocked('6. switch alone is not cash collection', $switch, fn() => payment_collect_cash_on_pickup($pdo, $adminId, $switch), 'not_picked_up');
            foreach (['PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP'] as $stage) {
                $advance = pickup_advance_status($pdo, $switch);
                assertEq("6. existing cash pickup lifecycle reaches {$stage}", [$advance['ok'] ?? null, $advance['stage'] ?? null], [true, $stage]);
            }
            $handedOver = recoverySnapshot($pdo, $switch);
            assertEq('6. handover still does not collect cash', $handedOver['payment']['payment_status'], 'PENDING');
            assertEq('6. handover creates one real sale, not switch', count($handedOver['sales']), 1);
            assertTrue('6. explicit cash collection succeeds', !empty(payment_collect_cash_on_pickup($pdo, $adminId, $switch)['ok']));
            $collected = recoverySnapshot($pdo, $switch);
            assertEq('6. actual cash collection paid', $collected['payment']['payment_status'], 'PAID');
            foreach (['gcash_reference', 'proof_path', 'submitted_at', 'rejection_reason', 'rejected_at'] as $column) {
                assertEq("6. collected cash retains historical {$column}", $collected['payment'][$column], $before['payment'][$column]);
            }
            assertTrue('6. cash collection never changes sale/history/fulfillment', array_diff_key($collected, ['payment' => true]) === array_diff_key($handedOver, ['payment' => true]));
            $blocked('6. duplicate cash collection', $switch, fn() => payment_collect_cash_on_pickup($pdo, $adminId, $switch), 'already_paid');
            $blocked('6. collected cash cannot use GCash expiry cancellation', $switch, fn() => order_cancel_expired_gcash($pdo, $adminId, $switch, 'Invalid recovery', 4), 'not_gcash');
        }
    }

    echo "== 7. Integration: explicit expired cancellation, attribution and holds ==\n";
    $cancel = makeRecoveryOrder($pdo, $customerId, $adminId);
    $blocked('7. cancellation before deadline', $cancel, fn() => order_cancel_expired_gcash($pdo, $adminId, $cancel, 'Too early', 2), 'deadline_not_expired');
    $pdo->prepare('UPDATE payments SET rejected_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE order_id = ?')->execute([$cancel]);
    /* A NULL-linked legacy item must not break the existing hold-release join. */
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, subtotal) VALUES (?, NULL, ?, 0.00, 1, 0.00)')
        ->execute([$cancel, "P6 {$suffix} legacy item"]);
    $pdo->prepare("INSERT INTO products (name, category, condition_label, price, status) VALUES (?, 'SHIRTS', 'Phase6 fixture', 0.00, 'AVAILABLE')")
        ->execute(["P6 {$suffix} already available"]);
    $availableProduct = (int) $pdo->lastInsertId();
    $created['products'][] = $availableProduct;
    $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, subtotal) VALUES (?, ?, ?, 0.00, 1, 0.00)')
        ->execute([$cancel, $availableProduct, "P6 {$suffix} non-SOLD item"]);
    $before = recoverySnapshot($pdo, $cancel);
    $unrelatedHold = recoverySnapshot($pdo, $resubmit);
    assertTrue('7. expired deadline is derived on reload', payment_gcash_recovery_window(payment_load($pdo, $cancel))['expired']);
    assertTrue('7. reading expired deadline never auto-cancels or releases hold', recoverySnapshot($pdo, $cancel) === $before);
    $missingAdmin = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM admins')->fetchColumn();
    foreach ([0, $missingAdmin] as $invalidAdmin) {
        $blocked('7. actual admin row required', $cancel, fn() => order_cancel_expired_gcash($pdo, $invalidAdmin, $cancel, 'Expired correction', 2), 'admin_required');
    }
    $blocked('7. cancellation reason cannot be whitespace', $cancel, fn() => order_cancel_expired_gcash($pdo, $adminId, $cancel, " \n\t", 2), 'cancellation_reason_required');
    $blocked('7. cancellation reason length enforced', $cancel, fn() => order_cancel_expired_gcash($pdo, $adminId, $cancel, str_repeat('x', 256), 2), 'reason_too_long');
    $blocked('7. stale cancellation revision', $cancel, fn() => order_cancel_expired_gcash($pdo, $adminId, $cancel, 'Expired correction', 1), 'conflict');
    $cancelStarted = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    $result = order_cancel_expired_gcash($pdo, $adminId, $cancel, '  GCash correction deadline expired  ', 2);
    $after = recoverySnapshot($pdo, $cancel);
    $cancelEnded = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    assertEq('7. cancellation is explicit terminal outcome', [$result['ok'] ?? null, $after['order']['status'], $after['order']['cancellation_status']], [true, 'CANCELLED', 'APPROVED']);
    assertEq('7. trimmed cancellation reason and real admin recorded', [$after['order']['cancellation_reason'], (int) $after['order']['cancelled_by']], ['GCash correction deadline expired', $adminId]);
    assertTrue('7. cancellation timestamp uses database time', $after['order']['cancelled_at'] !== null && $after['order']['cancelled_at'] >= $cancelStarted && $after['order']['cancelled_at'] <= $cancelEnded);
    $orderChanges = array_fill_keys(['status', 'cancellation_status', 'cancellation_reason', 'cancelled_by', 'cancelled_at', 'updated_at'], true);
    assertEq('7. other order fields unchanged', array_diff_key($after['order'], $orderChanges), array_diff_key($before['order'], $orderChanges));
    assertTrue('7. cancellation preserves payment/revision/evidence, items, verification, shipment and sales', array_diff_key($after, ['order' => true, 'products' => true]) === array_diff_key($before, ['order' => true, 'products' => true]));
    assertEq('7. only own SOLD hold released', $after['products'][0]['status'], 'AVAILABLE');
    assertEq('7. release does not rewrite other product data', array_diff_key($after['products'][0], ['status' => true, 'updated_at' => true]), array_diff_key($before['products'][0], ['status' => true, 'updated_at' => true]));
    assertEq('7. non-SOLD product is left entirely untouched', $after['products'][1], $before['products'][1]);
    assertTrue('7. another order product hold is untouched', recoverySnapshot($pdo, $resubmit) === $unrelatedHold);
    $blocked('7. duplicate cancellation preserves first reason/time', $cancel, fn() => order_cancel_expired_gcash($pdo, $adminId, $cancel, 'Overwrite attempt', 2), 'order_cancelled');

    $requested = makeRecoveryOrder($pdo, $customerId, $adminId, 'STORE_PICKUP');
    $pdo->prepare("UPDATE orders SET cancellation_status = 'REQUESTED', pickup_status = 'READY_FOR_PICKUP' WHERE id = ?")->execute([$requested]);
    $pdo->prepare('UPDATE payments SET rejected_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE order_id = ?')->execute([$requested]);
    $blocked('7. pending cancellation locks customer resubmit', $requested, fn() => payment_gcash_submit($pdo, $customerId, $requested, '1234567890123', null, 2), 'cancellation_pending');
    $blocked('7. pending cancellation locks customer switch', $requested, fn() => payment_gcash_switch_to_cash($pdo, $customerId, $requested, 'CASH_ON_PICKUP', 2), 'cancellation_pending');
    $requestedBefore = recoverySnapshot($pdo, $requested);
    assertTrue('7. REQUESTED cancellation permits explicit expired admin action', !empty(order_cancel_expired_gcash($pdo, $adminId, $requested, 'Expired pickup payment', 2)['ok']));
    $requestedAfter = recoverySnapshot($pdo, $requested);
    assertEq('7. requested pickup hold released', $requestedAfter['products'][0]['status'], 'AVAILABLE');
    assertEq('7. requested pickup payment evidence untouched', $requestedAfter['payment'], $requestedBefore['payment']);

    echo "== 8. Integration: locked state guards, legacy timestamp and completed sale ==\n";
    foreach ([
        ['awaiting', "UPDATE payments SET payment_status = 'PENDING' WHERE order_id = ?", 'not_rejected', 'already_submitted'],
        ['paid', "UPDATE payments SET payment_status = 'PAID' WHERE order_id = ?", 'already_paid', 'already_paid'],
        ['refunded', "UPDATE payments SET payment_status = 'REFUNDED' WHERE order_id = ?", 'not_rejected', 'not_rejected'],
        ['collected cash', "UPDATE payments SET payment_method = 'COD', payment_status = 'PAID' WHERE order_id = ?", 'not_gcash', 'not_gcash'],
        ['shipped order', "UPDATE orders SET status = 'SHIPPED' WHERE id = ?", 'order_incompatible', 'order_incompatible'],
        ['delivered shipment', "UPDATE shipments SET shipment_status = 'DELIVERED' WHERE order_id = ?", 'order_incompatible', 'order_incompatible'],
        ['receipt recorded', 'UPDATE orders SET received_at = NOW() WHERE id = ?', 'order_incompatible', 'order_incompatible'],
        ['terminal pickup', "UPDATE orders SET pickup_status = 'PICKED_UP' WHERE id = ?", 'order_incompatible', 'order_incompatible'],
        ['approved cancellation', "UPDATE orders SET cancellation_status = 'APPROVED' WHERE id = ?", 'order_cancelled', 'order_cancelled'],
    ] as [$label, $sql, $reason, $submitReason]) {
        $guarded = makeRecoveryOrder($pdo, $customerId, $adminId);
        $pdo->prepare('UPDATE payments SET rejected_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE order_id = ?')->execute([$guarded]);
        $pdo->prepare($sql)->execute([$guarded]);
        $blocked("8. {$label}: resubmit", $guarded, fn() => payment_gcash_submit($pdo, $customerId, $guarded, '1234567890123', null, 2), $submitReason);
        $blocked("8. {$label}: switch", $guarded, fn() => payment_gcash_switch_to_cash($pdo, $customerId, $guarded, 'COD', 2), $reason);
        $blocked("8. {$label}: cancellation", $guarded, fn() => order_cancel_expired_gcash($pdo, $adminId, $guarded, 'Expired correction', 2), $reason);
    }
    foreach (['CONFIRMED', 'PACKED'] as $status) {
        $guarded = makeRecoveryOrder($pdo, $customerId, $adminId);
        $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $guarded]);
        $pdo->prepare('UPDATE payments SET rejected_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE order_id = ?')->execute([$guarded]);
        $blocked("8. {$status}: original PENDING-only cancellation restriction", $guarded, fn() => order_cancel_expired_gcash($pdo, $adminId, $guarded, 'Expired correction', 2), 'order_incompatible');
    }
    $legacy = makeRecoveryOrder($pdo, $customerId, $adminId);
    $pdo->prepare('UPDATE payments SET rejected_at = NULL WHERE order_id = ?')->execute([$legacy]);
    $blocked('8. legacy NULL timestamp cannot resubmit', $legacy, fn() => payment_gcash_submit($pdo, $customerId, $legacy, '1234567890123', null, 2), 'deadline_unknown');
    $blocked('8. legacy NULL timestamp cannot expire-cancel', $legacy, fn() => order_cancel_expired_gcash($pdo, $adminId, $legacy, 'Guessed deadline', 2), 'deadline_unknown');
    assertTrue('8. legacy unknown deadline can still switch', !empty(payment_gcash_switch_to_cash($pdo, $customerId, $legacy, 'COD', 2)['ok']));
    assertEq('8. switching legacy payment never invents a rejection timestamp', recoverySnapshot($pdo, $legacy)['payment']['rejected_at'], null);
    $unknownMethod = makeRecoveryOrder($pdo, $customerId, $adminId);
    $pdo->prepare('UPDATE shipments SET delivery_method = NULL WHERE order_id = ?')->execute([$unknownMethod]);
    $blocked('8. missing fulfillment cannot fall back to COD', $unknownMethod, fn() => payment_gcash_switch_to_cash($pdo, $customerId, $unknownMethod, 'COD', 2), 'cash_method_invalid');

    /* Deliberately inconsistent legacy data: sale exists but receipt is NULL.
       The service must discover the real sale, not trust supplied/UI state. */
    $completed = makeRecoveryOrder($pdo, $customerId, $adminId);
    $pdo->prepare('UPDATE payments SET rejected_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE order_id = ?')->execute([$completed]);
    $pdo->prepare("INSERT INTO sales_transactions (sale_channel, order_id, total_amount, request_key) VALUES ('ONLINE', ?, 150.00, ?)")
        ->execute([$completed, "phase6-{$suffix}-{$completed}"]);
    $saleId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO sales_transaction_items (sale_id, product_id, product_name, barcode_value, unit_price, order_item_id) SELECT ?, oi.product_id, oi.product_name, NULL, oi.unit_price, oi.id FROM order_items oi WHERE oi.order_id = ?')
        ->execute([$saleId, $completed]);
    $blocked('8. actual completed sale blocks resubmit', $completed, fn() => payment_gcash_submit($pdo, $customerId, $completed, '1234567890123', null, 2), 'order_incompatible');
    $blocked('8. actual completed sale blocks switch', $completed, fn() => payment_gcash_switch_to_cash($pdo, $customerId, $completed, 'COD', 2), 'order_incompatible');
    $blocked('8. actual completed sale blocks cancellation and inventory release', $completed, fn() => order_cancel_expired_gcash($pdo, $adminId, $completed, 'Expired correction', 2), 'order_incompatible');

    echo "== 9. Integration: first submission and receipt Phase 5 regression ==\n";
    $first = makeRecoveryOrder($pdo, $customerId, $adminId);
    $pdo->prepare("UPDATE payments SET payment_status = 'PENDING', gcash_reference = NULL, proof_path = NULL, submitted_at = NULL, rejection_reason = NULL, rejected_at = NULL, revision = 0 WHERE order_id = ?")->execute([$first]);
    assertTrue('9. genuine NOT_SUBMITTED still accepts first reference', !empty(payment_gcash_submit($pdo, $customerId, $first, '1234567890123', null, 0)['ok']));
    $pdo->prepare("UPDATE orders SET status = 'DELIVERED' WHERE id = ?")->execute([$first]);
    $blocked('9. delivered but unverified GCash cannot create receipt sale', $first, fn() => sales_confirm_receipt_online($pdo, $customerId, $first), 'gcash_awaiting_verification');
    $pdo->prepare("UPDATE payments SET payment_status = 'FAILED', rejected_at = NOW() WHERE order_id = ?")->execute([$first]);
    $blocked('9. delivered rejected GCash cannot create receipt sale', $first, fn() => sales_confirm_receipt_online($pdo, $customerId, $first), 'gcash_rejected');
} catch (Throwable $e) {
    $failures++;
    echo '  FAIL: integration exception: ' . $e->getMessage() . "\n";
    echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $orderIds = implode(',', array_map('intval', $created['orders']));
        if ($orderIds !== '') {
            $pdo->exec("DELETE sti FROM sales_transaction_items sti INNER JOIN sales_transactions st ON st.id = sti.sale_id WHERE st.order_id IN ({$orderIds})");
            foreach (['customer_order_notifications', 'sales_transactions', 'order_item_verifications', 'payments', 'shipments', 'order_items'] as $table) {
                $pdo->exec("DELETE FROM {$table} WHERE order_id IN ({$orderIds})");
            }
            $pdo->exec("DELETE FROM orders WHERE id IN ({$orderIds})");
        }
        foreach (['products', 'customers', 'admins'] as $table) {
            $ids = implode(',', array_map('intval', $created[$table]));
            if ($ids !== '') {
                $pdo->exec("DELETE FROM {$table} WHERE id IN ({$ids})");
            }
        }
        echo "\nPhase6 fixtures cleaned up.\n";
    } catch (Throwable $e) {
        $failures++;
        fwrite(STDERR, "FAIL: fixture cleanup failed: " . $e->getMessage() . "\nFixture suffix: {$suffix}\n");
        fwrite(STDERR, 'Fixture IDs: ' . json_encode($created) . "\n");
    }
}

echo "\nRan {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
