<?php

/*
 * Hopia Fits - Phase 3 Store Pickup barcode-verification endpoint.
 *
 * Thin HTTP wrapper: validates the admin session, the pickup-verification CSRF
 * token, and the (order_id, barcode) payload, then delegates the whole
 * transactional verification to pickup_verify_record() in includes/pickup.php.
 * Eligibility, the order's own item list, product resolution, and duplicate
 * protection are ALWAYS derived from the database under row locks inside that
 * function; client-supplied fields are never trusted.
 *
 * One accepted scan = one committed order_item_verifications row. Scanning
 * NEVER changes products.status, reserves inventory, creates a stock movement,
 * updates payment, or creates a sale.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../includes/admin-feedback.php';
hopia_admin_success_clear();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pickup_verify_scan_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    unset($payload['status']);
    echo json_encode($payload);
    exit;
}

/* Authenticated admin session is required. */
if (empty($_SESSION['admin_id'])) {
    pickup_verify_scan_respond(['ok' => false, 'reason' => 'auth', 'message' => 'Your session has expired. Please sign in again.'], 401);
}

/* POST only: scanning changes persisted verification state. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pickup_verify_scan_respond(['ok' => false, 'reason' => 'method', 'message' => 'Method not allowed.'], 405);
}

/*
 * CSRF validation (stable per-session pickup-verification token). Reject
 * missing / empty / non-string tokens before hash_equals(). The token is not
 * rotated here, so repeated scans keep working across one verification
 * session.
 */
$submittedToken = $_POST['csrf_token'] ?? '';
$storedToken = $_SESSION['pickup_verify_csrf'] ?? '';

if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || !is_string($storedToken)
    || $storedToken === ''
    || !hash_equals($storedToken, $submittedToken)
) {
    pickup_verify_scan_respond(['ok' => false, 'reason' => 'csrf', 'message' => 'Your form session has expired. Please reload the verification page.'], 403);
}

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/pickup.php';

$adminId = (int) $_SESSION['admin_id'];

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
if ($orderId === null || $orderId === false || $orderId <= 0) {
    pickup_verify_scan_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing or invalid order.'], 400);
}

$barcode = $_POST['barcode'] ?? '';
if (!is_string($barcode)) {
    pickup_verify_scan_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing barcode.'], 400);
}

try {
    $result = pickup_verify_record($pdo, $adminId, (int) $orderId, $barcode);
} catch (Throwable $e) {
    pickup_verify_scan_respond(['ok' => false, 'reason' => 'error', 'message' => 'The verification could not be completed. Please try again.'], 500);
}

$status = isset($result['status']) ? (int) $result['status'] : 200;
pickup_verify_scan_respond($result, $status);
