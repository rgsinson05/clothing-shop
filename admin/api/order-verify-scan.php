<?php

/*
 * Hopia Fits - Phase B online-order SCAN verification endpoint.
 *
 * Thin HTTP wrapper: validates the admin session, the Phase B verify CSRF
 * token, and the (order_id, barcode) payload, then delegates the whole
 * transactional verification to order_verify_record() in
 * includes/order_verify.php. Prices, status, identity, and eligibility are
 * ALWAYS derived from the database under row locks inside that function;
 * client-supplied fields are never trusted.
 *
 * Scanning records a verification immediately (there is no draft/commit split
 * like the walk-in sale): one accepted scan = one committed verification row.
 * It NEVER creates a sale, changes products.status, or advances the order.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../includes/admin-feedback.php';
hopia_admin_success_clear();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function order_verify_scan_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    unset($payload['status']);
    echo json_encode($payload);
    exit;
}

/* Authenticated admin session is required. */
if (empty($_SESSION['admin_id'])) {
    order_verify_scan_respond(['ok' => false, 'reason' => 'auth', 'message' => 'Your session has expired. Please sign in again.'], 401);
}

/* POST only. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    order_verify_scan_respond(['ok' => false, 'reason' => 'method', 'message' => 'Method not allowed.'], 405);
}

/*
 * CSRF validation (Phase B verify token). Reject missing / empty / non-string
 * tokens before hash_equals(). The token is a stable per-session value set by
 * admin/order-verify.php and is NOT rotated here, so repeated scans keep
 * working across one verification session.
 */
$submittedToken = $_POST['csrf_token'] ?? '';
$storedToken = $_SESSION['order_verify_csrf'] ?? '';

if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || !is_string($storedToken)
    || $storedToken === ''
    || !hash_equals($storedToken, $submittedToken)
) {
    order_verify_scan_respond(['ok' => false, 'reason' => 'csrf', 'message' => 'Your form session has expired. Please reload the verification page.'], 403);
}

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/order_verify.php';

$adminId = (int) $_SESSION['admin_id'];

/* Validate the order id. */
$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
if ($orderId === null || $orderId === false || $orderId <= 0) {
    order_verify_scan_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing or invalid order.'], 400);
}

/* Validate the barcode shape (eligibility is re-checked against the DB). */
$barcode = $_POST['barcode'] ?? '';
if (!is_string($barcode)) {
    order_verify_scan_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing barcode.'], 400);
}

try {
    $result = order_verify_record($pdo, $adminId, (int) $orderId, $barcode);
} catch (Throwable $e) {
    order_verify_scan_respond(['ok' => false, 'reason' => 'error', 'message' => 'The verification could not be completed. Please try again.'], 500);
}

$status = isset($result['status']) ? (int) $result['status'] : 200;
order_verify_scan_respond($result, $status);
