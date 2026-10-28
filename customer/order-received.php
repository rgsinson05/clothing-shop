<?php

/*
 * Customer Order Received confirmation endpoint.
 *
 * Receives the Order Received POST from customer/order-detail.php and
 * persists the customer's delivery-confirmation event. Local Courier COD and
 * GCash record receipt only; their payment collection/reconciliation and the
 * completed sale require the separate admin confirmation. Other payments
 * keep their existing flow.
 *
 * Rules enforced here (server-side, never trusted from the browser):
 *
 *   1. The customer must be authenticated.
 *   2. The order must belong to the authenticated customer.
 *   3. The Local Courier shipment must be OUT_FOR_DELIVERY (primary SHIPPED)
 *      or already DELIVERED, without an active cancellation.
 *   4. received_at must be NULL (first-time confirmation).
 *   5. The order must have at least one item.
 *   6. Every item quantity must be exactly 1.
 *   7. Payment state is never changed.
 *
 * COD/GCash receipt retries succeed without creating or requiring a sale.
 * Other payments retain their existing completed-sale retry validation.
 *
 * Uses POST/Redirect/Get: both success and failure redirect to
 * order-detail.php with a query flag so the page can show the
 * appropriate message without repeating the action on refresh.
 */

session_start();

/*
 * Redirect helper for POST/Redirect/Get. Uses the same flag names as
 * order-cancel-request.php so the pattern is consistent across the
 * customer order actions.
 */
function orderReceivedRedirect(int $orderId, bool $success): never
{
    $query = $success
        ? 'id=' . $orderId . '&received=1'
        : 'id=' . $orderId . '&received_error=1';
    header('Location: order-detail.php?' . $query);
    exit;
}

/*
 * Accept only POST submissions.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $directOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    orderReceivedRedirect(
        ($directOrderId !== false && $directOrderId !== null && $directOrderId > 0)
            ? (int) $directOrderId
            : 0,
        false
    );
}

/*
 * 1. Authentication.
 */
if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

/*
 * CSRF token validation (same convention as order-cancel-request.php).
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    $filteredOrderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    orderReceivedRedirect(
        ($filteredOrderId !== false && $filteredOrderId !== null && $filteredOrderId > 0)
            ? (int) $filteredOrderId
            : 0,
        false
    );
}

/*
 * Validate the order ID as a positive integer.
 */
$filteredOrderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

if ($filteredOrderId === false || $filteredOrderId === null || $filteredOrderId < 1) {
    orderReceivedRedirect(0, false);
}

$orderId = (int) $filteredOrderId;

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/sales.php';

/*
 * Execute the confirmation. The shared helper handles all business
 * logic inside a transaction: ownership, courier eligibility, automatic
 * DELIVERED transition, idempotency, and receipt persistence. COD never creates a sale here.
 * Any violation rolls the transaction back and returns a failure.
 */
$result = sales_confirm_receipt_online($pdo, $customerId, $orderId);

if ($result['ok']) {
    orderReceivedRedirect($orderId, true);
} else {
    orderReceivedRedirect($orderId, false);
}
