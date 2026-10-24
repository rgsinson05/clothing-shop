<?php
/*
 * Render harness for ui.gcash-panel.php: verifies modal vs non-modal output
 * without a browser. Stubs the UI helpers and captures the partial's HTML.
 * CLI-only, no database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI-only.\n");
}

require_once __DIR__ . '/../../includes/payment.php';

function hopia_e($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hopia_asset($path) { return '../' . ltrim($path, '/'); }
function hopia_checkout_icon($name) { return '<svg data-icon="' . $name . '"></svg>'; }

$tests = 0; $failures = 0;
function assertTrue(string $label, bool $cond): void {
    global $tests, $failures;
    $tests++;
    echo ($cond ? "  ok:   " : "  FAIL: ") . $label . "\n";
    if (!$cond) $failures++;
}
function assertEq(string $label, $a, $b): void {
    global $tests, $failures;
    $tests++;
    if ($a === $b) { echo "  ok:   {$label}\n"; }
    else { $failures++; echo "  FAIL: {$label} (expected " . var_export($b, true) . ", got " . var_export($a, true) . ")\n"; }
}

function renderPanel(array $overrides = []): string {
    $gcashPanel = array_merge([
        'order_id' => 42,
        'amount' => '580.00',
        'config' => [
            'name' => 'Test Receiver',
            'number' => '09171234567',
            'qr' => '',
            'has_qr' => false,
            'configured' => true,
            'missing' => [],
        ],
        'payment' => [
            'payment_method' => 'GCASH',
            'payment_status' => 'PENDING',
            'gcash_reference' => null,
            'proof_path' => null,
            'submitted_at' => null,
            'verified_at' => null,
            'rejection_reason' => null,
        ],
        'order' => ['status' => 'PENDING', 'cancellation_status' => 'NONE', 'has_completed_sale' => false],
        'shipment' => ['delivery_method' => 'LOCAL_COURIER', 'shipment_status' => 'NOT_SHIPPED'],
        'state' => 'NOT_SUBMITTED',
        'csrf' => 'csrftoken123',
        'return' => 'order-confirmation.php',
        'proof_endpoint' => 'payment-proof.php',
        'flash' => '',
        'flash_message' => '',
        'modal' => false,
    ], $overrides);
    ob_start();
    require __DIR__ . '/../../includes/ui.gcash-panel.php';
    return (string) ob_get_clean();
}

echo "== 1. Non-modal (order-detail) mode ==\n";
$html = renderPanel(['modal' => false, 'return' => 'order-detail.php']);
assertTrue('1.1 renders the panel section', strpos($html, 'class="gcash-panel"') !== false);
assertTrue('1.2 NO modal wrapper', strpos($html, 'gcash-modal"') === false && strpos($html, 'gcash-modal__panel') === false);
assertTrue('1.3 NO reopen button', strpos($html, 'data-gcash-modal-open') === false);
assertTrue('1.4 NO modal JS (no sessionId logic)', strpos($html, 'sessionStorage') === false);
assertEq('1.5 exactly ONE form', substr_count($html, '<form'), 1);
assertEq('1.6 exactly ONE copy button element', substr_count($html, 'gcash-panel__copy" data-gcash-copy'), 1);
assertTrue('1.7 posts to the existing endpoint', strpos($html, 'action="payment-submit.php"') !== false);
assertTrue('1.8 return targets order-detail', strpos($html, 'name="return" value="order-detail.php"') !== false);

echo "== 2. Modal (order-confirmation) mode ==\n";
$html = renderPanel(['modal' => true]);
assertTrue('2.1 modal wrapper present and hidden by default', strpos($html, 'class="gcash-modal" id="gcash-panel-42-modal" hidden') !== false);
assertEq('2.2 close attr on backdrop + close button (+1 JS reader)', substr_count($html, 'data-gcash-modal-close'), 3);
assertTrue('2.3 visible close button with aria-label', strpos($html, 'aria-label="Close GCash payment instructions"') !== false);
assertTrue('2.4 role=dialog aria-modal', strpos($html, 'role="dialog" aria-modal="true"') !== false);
assertTrue('2.5 reopen button clearly visible', strpos($html, 'data-gcash-modal-open') !== false);
assertTrue('2.6 reopen says Pay with GCash', strpos($html, '>Pay with GCash<') !== false);
assertTrue('2.7 panel is INSIDE the modal body (one instance)', strpos($html, 'class="gcash-modal__body"') !== false && substr_count($html, 'class="gcash-panel"') === 1);
assertEq('2.8 exactly ONE form (no duplicate)', substr_count($html, '<form'), 1);
assertEq('2.9 exactly ONE reference input', substr_count($html, 'name="gcash_reference"'), 1);
assertEq('2.10 exactly ONE file input', substr_count($html, 'name="payment_proof"'), 1);
assertEq('2.11 exactly ONE copy button', substr_count($html, 'gcash-panel__copy" data-gcash-copy'), 1);
assertTrue('2.12 auto-open is session-scoped to the order', strpos($html, "'hopiaGcashModalShown.42'") !== false);
assertTrue('2.13 auto-open skipped when PAID', strpos($html, "state !== 'PAID'") !== false);
assertTrue('2.14 noscript de-modal fallback present', strpos($html, '<noscript>') !== false);
assertTrue('2.15 amount shown', strpos($html, '580.00') !== false);
assertTrue('2.16 account name/number shown', strpos($html, 'Test Receiver') !== false && strpos($html, '09171234567') !== false);
assertTrue('2.17 Escape/focus trap present', strpos($html, "event.key === 'Escape'") !== false);

echo "== 3. Modal after submission (flash) ==\n";
$html = renderPanel(['modal' => true, 'flash' => 'submitted', 'state' => 'AWAITING', 'payment' => [
    'payment_method' => 'GCASH', 'payment_status' => 'PENDING',
    'gcash_reference' => '1234567890123', 'proof_path' => null,
    'submitted_at' => '2026-10-15 10:00:00', 'verified_at' => null, 'rejection_reason' => null,
]]);
assertTrue('3.1 success confirmation mentions admin verification', strpos($html, 'awaiting verification by our team') !== false);
assertTrue('3.2 shows the submitted reference', strpos($html, '1234567890123') !== false);
assertTrue('3.3 no resubmission while awaiting verification', substr_count($html, '<form') === 0);

echo "== 4. Modal for verified payment ==\n";
$html = renderPanel(['modal' => true, 'flash' => '', 'state' => 'PAID', 'payment' => [
    'payment_method' => 'GCASH', 'payment_status' => 'PAID',
    'gcash_reference' => '1234567890123', 'proof_path' => null,
    'submitted_at' => '2026-10-15 10:00:00', 'verified_at' => '2026-10-15 11:00:00', 'rejection_reason' => null,
]]);
assertTrue('4.1 no submission form when paid', substr_count($html, '<form') === 0);
assertTrue('4.2 reopen label changes to view details', strpos($html, 'View GCash payment details') !== false);

echo "== 5. Unconfigured guard in modal mode ==\n";
$html = renderPanel(['modal' => true, 'config' => [
    'name' => '', 'number' => '', 'qr' => '', 'has_qr' => false, 'configured' => false, 'missing' => ['GCASH_ACCOUNT_NAME', 'GCASH_ACCOUNT_NUMBER'],
]]);
assertTrue('5.1 no account details leaked', strpos($html, '0917') === false);
assertTrue('5.2 unavailable notice shown', strpos($html, 'temporarily unavailable') !== false);
assertTrue('5.3 no form when unconfigured', substr_count($html, '<form') === 0);

echo "\nRan {$tests} checks, {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
