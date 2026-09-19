<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

/*
 * Require the customer to be logged in.
 */

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

/*
 * Ensure a CSRF token exists for customer forms.
 */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
 * Redirect helper for the POST/Redirect/GET flow. When a valid
 * order ID is known, the customer is sent back to the order
 * detail page, otherwise to the orders list. Every failure
 * shares the same generic error flag so order ownership and
 * order state are never revealed.
 */

function orderCancelRedirect(?int $orderId, bool $success = false)
{
    if ($orderId !== null && $orderId > 0) {
        $query = $success
            ? 'id=' . $orderId . '&cancel_requested=1'
            : 'id=' . $orderId . '&cancel_error=1';
        header('Location: order-detail.php?' . $query);
    } else {
        header('Location: orders.php');
    }
    exit;
}

/*
 * Accept only POST submissions. Direct URL access never
 * changes any state.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $directOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    orderCancelRedirect(
        ($directOrderId !== false && $directOrderId !== null && $directOrderId > 0)
            ? (int) $directOrderId
            : null
    );
}

/*
 * Validate the order ID as a positive integer before anything
 * else so later failures can redirect back to the order page.
 */

$filteredOrderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

if ($filteredOrderId === false || $filteredOrderId === null || $filteredOrderId < 1) {
    orderCancelRedirect(null);
}

$orderId = (int) $filteredOrderId;

/*
 * Verify the CSRF token against the customer session token.
 */

$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($submittedToken)
    || $submittedToken === ''
    || !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    orderCancelRedirect($orderId);
}

/*
 * Request the cancellation. The whole operation runs inside a
 * transaction and the target order is locked first, so a stale
 * browser page, a repeated submission, or a concurrent admin
 * action cannot cause an invalid transition. This is only a
 * request: the order stays PENDING and only the cancellation
 * status moves from NONE to REQUESTED.
 */

try {
    $pdo->beginTransaction();

    $lockStmt = $pdo->prepare(
        'SELECT status, cancellation_status
         FROM orders
         WHERE id = :order_id
           AND customer_id = :customer_id
         LIMIT 1
         FOR UPDATE'
    );

    $lockStmt->execute([
        ':order_id' => $orderId,
        ':customer_id' => $customerId
    ]);

    $lockedOrder = $lockStmt->fetch();

    if (
        $lockedOrder === false
        || $lockedOrder['status'] !== 'PENDING'
        || $lockedOrder['cancellation_status'] !== 'NONE'
    ) {
        /*
         * Unknown order, another customer's order, or an order
         * that is not in a requestable state. All of these
         * cases share the same generic failure response.
         */

        throw new RuntimeException('This cancellation request cannot be processed.');
    }

    /*
     * The UPDATE re-enforces the business rules server-side, so
     * even a change that happens between the lock and the
     * update cannot produce an invalid state. Only
     * orders.cancellation_status is changed. The order status,
     * products, order_items, payments, shipments, and
     * cart_items are never touched here.
     */

    $updateStmt = $pdo->prepare(
        "UPDATE orders
         SET cancellation_status = 'REQUESTED'
         WHERE id = :order_id
           AND customer_id = :customer_id
           AND status = 'PENDING'
           AND cancellation_status = 'NONE'"
    );

    $updateStmt->execute([
        ':order_id' => $orderId,
        ':customer_id' => $customerId
    ]);

    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException('This cancellation request cannot be processed.');
    }

    $pdo->commit();

    /*
     * Post/Redirect/GET on success.
     */

    orderCancelRedirect($orderId, true);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * Do not expose database exception details to the customer.
     */

    orderCancelRedirect($orderId);
}
