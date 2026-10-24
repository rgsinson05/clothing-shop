<?php

/*
 * Hopia Fits - Phase B online-order MANUAL verification endpoint.
 *
 * Admin-only. Thin HTTP wrapper: validates the admin session, the Phase B
 * verify CSRF token, and the (order_id, order_item_id, reason) payload, then
 * delegates the whole transactional manual verification to
 * order_verify_record_manual() in includes/order_verify.php.
 *
 * This endpoint exists ONLY for expected items that cannot be scanned (NULL
 * product_id, or a product with no valid issued barcode / that cannot be
 * resolved). order_verify_record_manual() re-proves the item's order
 * association, order eligibility, and unscannable condition under lock, and
 * REFUSES a scannable item so manual can never become a bypass for the scanner.
 * A blank/whitespace reason is rejected. It never marks an item verified
 * silently, never creates a sale, and never changes products.status or any
 * order lifecycle field. Client-supplied product data / ids / status / method
 * are ignored; identity comes from the session, time/state from the database.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../includes/admin-feedback.php';
hopia_admin_success_clear();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function order_verify_manual_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    unset($payload['status']);
    echo json_encode($payload);
    exit;
}

/* Authenticated admin session is required. */
if (empty($_SESSION['admin_id'])) {
    order_verify_manual_respond(['ok' => false, 'reason' => 'auth', 'message' => 'Your session has expired. Please sign in again.'], 401);
}

/* POST only. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    order_verify_manual_respond(['ok' => false, 'reason' => 'method', 'message' => 'Method not allowed.'], 405);
}

/* CSRF validation (Phase B verify token, stable per session). */
$submittedToken = $_POST['csrf_token'] ?? '';
$storedToken = $_SESSION['order_verify_csrf'] ?? '';

if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || !is_string($storedToken)
    || $storedToken === ''
    || !hash_equals($storedToken, $submittedToken)
) {
    order_verify_manual_respond(['ok' => false, 'reason' => 'csrf', 'message' => 'Your form session has expired. Please reload the verification page.'], 403);
}

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/order_verify.php';

$adminId = (int) $_SESSION['admin_id'];

/* Validate the order id and order item id. */
$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
if ($orderId === null || $orderId === false || $orderId <= 0) {
    order_verify_manual_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing or invalid order.'], 400);
}

$orderItemId = filter_input(INPUT_POST, 'order_item_id', FILTER_VALIDATE_INT);
if ($orderItemId === null || $orderItemId === false || $orderItemId <= 0) {
    order_verify_manual_respond(['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing or invalid order item.'], 400);
}

/* Reason is required; detailed non-empty/whitespace/length checks are enforced
   inside order_verify_record_manual() so the rule has one source of truth. */
$reason = $_POST['reason'] ?? '';
if (!is_string($reason)) {
    order_verify_manual_respond(['ok' => false, 'reason' => 'reason_required', 'message' => 'Enter a reason to manually verify this item.'], 400);
}

try {
    $result = order_verify_record_manual($pdo, $adminId, (int) $orderId, (int) $orderItemId, $reason);
} catch (Throwable $e) {
    order_verify_manual_respond(['ok' => false, 'reason' => 'error', 'message' => 'The verification could not be completed. Please try again.'], 500);
}

$status = isset($result['status']) ? (int) $result['status'] : 200;
order_verify_manual_respond($result, $status);
