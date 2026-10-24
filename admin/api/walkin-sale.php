<?php

/*
 * Hopia Fits - Phase A walk-in COMPLETE SALE (authoritative, atomic).
 *
 * Thin HTTP wrapper: validates the admin session, the walk-in CSRF token, the
 * idempotency/request key, and the barcode payload, then delegates the whole
 * twelve-step transactional sale to sales_complete_walkin() in
 * includes/sales.php. Prices and totals are ALWAYS recomputed from the
 * database under row locks inside that function; client prices/totals are
 * never trusted. A retried or double-submitted request with the same
 * request_key returns the original sale rather than creating another one.
 *
 * It does NOT touch online orders, payments, cancellations, order status, or
 * any existing checkout behaviour.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../includes/admin-feedback.php';
hopia_admin_success_clear();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function walkin_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    unset($payload['status']);
    echo json_encode($payload);
    exit;
}

/* Authenticated admin session is required. */
if (empty($_SESSION['admin_id'])) {
    walkin_respond(['ok' => false, 'reason' => 'auth', 'message' => 'Your session has expired. Please sign in again.'], 401);
}

/* POST only. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    walkin_respond(['ok' => false, 'reason' => 'method', 'message' => 'Method not allowed.'], 405);
}

/*
 * CSRF validation (walk-in token). Reject missing / empty / non-string tokens
 * before hash_equals(). The token is a stable per-session value and is NOT
 * unset here, so a legitimate idempotent retry after a network blip still
 * passes CSRF.
 */
$submittedToken = $_POST['csrf_token'] ?? '';
$storedToken = $_SESSION['walkin_csrf'] ?? '';

if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || !is_string($storedToken)
    || $storedToken === ''
    || !hash_equals($storedToken, $submittedToken)
) {
    walkin_respond(['ok' => false, 'reason' => 'csrf', 'message' => 'Your form session has expired. Please reload the scan page.'], 403);
}

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/sales.php';

$adminId = (int) $_SESSION['admin_id'];

/* Validate the idempotency / request key. */
$requestKey = $_POST['request_key'] ?? '';
if (!sales_is_valid_request_key($requestKey)) {
    walkin_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing or invalid request key. Please reload the scan page.'], 400);
}

/* Validate the barcode list: array, non-empty, each plausible, unique. */
$rawBarcodes = $_POST['barcodes'] ?? null;
if (!is_array($rawBarcodes) || count($rawBarcodes) === 0) {
    walkin_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'No items to sell.'], 400);
}

$barcodes = [];
foreach ($rawBarcodes as $raw) {
    $code = sales_normalize_barcode($raw);
    if (!sales_is_plausible_barcode($code)) {
        walkin_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'One of the submitted items is malformed.'], 400);
    }
    $barcodes[] = $code;
}
if (count(array_unique($barcodes)) !== count($barcodes)) {
    walkin_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'The sale contains a duplicated item.'], 400);
}

/* Validate the expected total used for the staleness check. */
$expectedTotalCents = sales_money_to_cents($_POST['expected_total'] ?? '');
if ($expectedTotalCents === null) {
    walkin_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing sale total. Please review the draft.'], 400);
}

/* Validate and normalize the per-item preview prices.
   Each entry corresponds to the barcode at the same array index.
   Reject malformed, missing, or count-mismatched entries. */
$rawPrices = $_POST['prices'] ?? null;
if (!is_array($rawPrices)) {
    walkin_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing item prices. Please reload the scan page.'], 400);
}
if (count($rawPrices) !== count($barcodes)) {
    walkin_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Item count does not match price count. Please reload the scan page.'], 400);
}
$previewPrices = [];
foreach ($rawPrices as $rawPrice) {
    if (!is_string($rawPrice) && !is_numeric($rawPrice)) {
        walkin_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'One of the item prices is invalid. Please reload the scan page.'], 400);
    }
    $cents = sales_money_to_cents($rawPrice);
    if ($cents === null) {
        walkin_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'One of the item prices is invalid. Please reload the scan page.'], 400);
    }
    $previewPrices[] = $cents;
}

$result = sales_complete_walkin($pdo, $adminId, $barcodes, $expectedTotalCents, $previewPrices, $requestKey);

$status = isset($result['status']) ? (int) $result['status'] : 200;

if (!empty($result['ok']) && !empty($result['sale']['id'])) {
    $result['redirect'] = hopia_admin_success_redirect(
        'sales.php?id=' . (int) $result['sale']['id'],
        !empty($result['idempotent'])
            ? 'Walk-in sale already recorded. The original receipt has been reopened.'
            : 'Walk-in sale recorded successfully.'
    );
}

walkin_respond($result, $status);
