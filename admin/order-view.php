<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';

$adminName = $_SESSION['admin_name'] ?? 'Admin';

/*
 * Separate CSRF token for admin state-changing forms. It is
 * intentionally kept apart from the customer-facing token so
 * the two form protections never interfere with each other.
 */

if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}

$adminCsrfToken = $_SESSION['admin_csrf_token'];

/*
 * Marker exception for intentional, admin-facing validation
 * messages. It deliberately does NOT extend PDOException, so
 * database failures can never surface their raw SQL messages.
 */

class OrderActionException extends RuntimeException
{
}

/*
 * Friendly labels. The database values remain unchanged.
 */

$orderStatusLabels = [
    'PENDING' => 'Pending',
    'CONFIRMED' => 'Confirmed',
    'PACKED' => 'Packed',
    'SHIPPED' => 'Shipped',
    'DELIVERED' => 'Delivered',
    'CANCELLED' => 'Cancelled'
];

$cancellationStatusLabels = [
    'NONE' => 'None',
    'REQUESTED' => 'Cancellation Requested',
    'APPROVED' => 'Cancellation Approved',
    'REJECTED' => 'Cancellation Rejected'
];

$paymentMethodLabels = [
    'COD' => 'Cash on Delivery',
    'GCASH' => 'GCash',
    'CARD' => 'Card'
];

$paymentStatusLabels = [
    'PENDING' => 'Pending',
    'PAID' => 'Paid',
    'FAILED' => 'Failed',
    'REFUNDED' => 'Refunded'
];

$shipmentStatusLabels = [
    'NOT_SHIPPED' => 'Not Shipped',
    'READY_TO_SHIP' => 'Ready to Ship',
    'SHIPPED' => 'Shipped',
    'IN_TRANSIT' => 'In Transit',
    'OUT_FOR_DELIVERY' => 'Out for Delivery',
    'DELIVERED' => 'Delivered'
];

/*
 * Normal order flow. Only the immediate next status is a valid
 * target, and the target is always derived on the server from
 * the locked database row. The form never submits a status.
 */

$orderStatusFlow = [
    'PENDING' => 'CONFIRMED',
    'CONFIRMED' => 'PACKED',
    'PACKED' => 'SHIPPED',
    'SHIPPED' => 'DELIVERED'
];

/*
 * State-changing actions are submitted from this page via POST
 * and follow the post/redirect/get pattern. The CSRF token, the
 * order ID, and the action are validated before any database
 * mutation happens. A failed request falls through so the page
 * renders again with a generic error notice.
 */

$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedCsrfToken = $_POST['csrf_token'] ?? '';
    $postedOrderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $postedAction = $_POST['action'] ?? '';

    $allowedActions = [
        'advance_status',
        'save_tracking_number',
        'approve_cancellation',
        'reject_cancellation'
    ];

    if (
        !is_string($submittedCsrfToken)
        || $submittedCsrfToken === ''
        || empty($_SESSION['admin_csrf_token'])
        || !hash_equals($_SESSION['admin_csrf_token'], $submittedCsrfToken)
        || $postedOrderId === null
        || $postedOrderId === false
        || $postedOrderId <= 0
        || !is_string($postedAction)
        || !in_array($postedAction, $allowedActions, true)
    ) {
        $actionError = 'The order could not be updated. Please try again.';
    } else {

        try {

            $submittedTrackingNumber = null;

            /*
             * Tracking input is validated server-side before any
             * database work starts. Only trimming and a length
             * limit are applied; no courier-specific pattern is
             * enforced, and an empty value is always rejected.
             */

            if ($postedAction === 'save_tracking_number') {
                $submittedTrackingNumber = $_POST['tracking_number'] ?? null;

                if (!is_string($submittedTrackingNumber) || trim($submittedTrackingNumber) === '') {
                    throw new OrderActionException('Enter a tracking number.');
                }

                $submittedTrackingNumber = trim($submittedTrackingNumber);

                if (strlen($submittedTrackingNumber) > 100) {
                    throw new OrderActionException('The tracking number must be 100 characters or fewer.');
                }
            }

            $pdo->beginTransaction();

            /*
             * Lock the order row first, then re-check the business
             * rules against the locked database state so a stale
             * browser page cannot cause an invalid transition.
             */

            $lockStmt = $pdo->prepare(
                'SELECT id, status, cancellation_status
                 FROM orders
                 WHERE id = :order_id
                 LIMIT 1
                 FOR UPDATE'
            );

            $lockStmt->execute([
                ':order_id' => $postedOrderId
            ]);

            $lockedOrder = $lockStmt->fetch();

            if ($lockedOrder === false) {
                throw new OrderActionException('The order no longer exists.');
            }

            $lockedOrderId = (int) $lockedOrder['id'];

            if ($postedAction === 'advance_status') {

                if ($lockedOrder['cancellation_status'] === 'REQUESTED') {
                    throw new OrderActionException('A cancellation request is pending for this order.');
                }

                if (!isset($orderStatusFlow[$lockedOrder['status']])) {
                    throw new OrderActionException('This order status has no next status.');
                }

                $targetStatus = $orderStatusFlow[$lockedOrder['status']];

                /*
                 * PACKED can only become SHIPPED once the shipment
                 * carries a tracking number. The check runs on the
                 * locked rows so a manually crafted POST cannot
                 * bypass it.
                 */

                if ($targetStatus === 'SHIPPED') {
                    $shipmentLockStmt = $pdo->prepare(
                        'SELECT tracking_number
                         FROM shipments
                         WHERE order_id = :order_id
                         LIMIT 1
                         FOR UPDATE'
                    );

                    $shipmentLockStmt->execute([
                        ':order_id' => $lockedOrderId
                    ]);

                    $lockedShipment = $shipmentLockStmt->fetch();

                    if (
                        $lockedShipment === false
                        || trim($lockedShipment['tracking_number'] ?? '') === ''
                    ) {
                        throw new OrderActionException('Enter a tracking number before marking this order as shipped.');
                    }
                }

                $advanceStmt = $pdo->prepare(
                    'UPDATE orders
                     SET status = :next_status
                     WHERE id = :order_id
                       AND status = :current_status
                       AND cancellation_status <> \'REQUESTED\''
                );

                $advanceStmt->execute([
                    ':next_status' => $targetStatus,
                    ':order_id' => $postedOrderId,
                    ':current_status' => $lockedOrder['status']
                ]);

                if ($advanceStmt->rowCount() !== 1) {
                    throw new OrderActionException('The order status could not be advanced.');
                }

                /*
                 * Shipment data is only touched once the flow
                 * reaches SHIPPED or DELIVERED. The tracking number
                 * is never modified here. A missing shipment row
                 * fails the whole transaction instead of silently
                 * creating unexpected data.
                 */

                if ($targetStatus === 'SHIPPED') {
                    $shipmentUpdateStmt = $pdo->prepare(
                        'UPDATE shipments
                         SET shipment_status = \'SHIPPED\',
                             shipped_at = NOW()
                         WHERE order_id = :order_id'
                    );

                    $shipmentUpdateStmt->execute([
                        ':order_id' => $lockedOrderId
                    ]);

                    if ($shipmentUpdateStmt->rowCount() !== 1) {
                        throw new OrderActionException('The shipment record could not be updated.');
                    }
                }

                if ($targetStatus === 'DELIVERED') {
                    $shipmentUpdateStmt = $pdo->prepare(
                        'UPDATE shipments
                         SET shipment_status = \'DELIVERED\',
                             delivered_at = NOW()
                         WHERE order_id = :order_id'
                    );

                    $shipmentUpdateStmt->execute([
                        ':order_id' => $lockedOrderId
                    ]);

                    if ($shipmentUpdateStmt->rowCount() !== 1) {
                        throw new OrderActionException('The shipment record could not be updated.');
                    }
                }

            } elseif ($postedAction === 'save_tracking_number') {

                /*
                 * Tracking entry is only allowed once the order is
                 * confirmed and while no cancellation request is
                 * pending. Saving a tracking number never changes
                 * the order status or the cancellation status.
                 */

                if (
                    !in_array($lockedOrder['status'], ['CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED'], true)
                    || $lockedOrder['cancellation_status'] === 'REQUESTED'
                ) {
                    throw new OrderActionException('Tracking management is not available for this order.');
                }

                $shipmentLockStmt = $pdo->prepare(
                    'SELECT tracking_number
                     FROM shipments
                     WHERE order_id = :order_id
                     LIMIT 1
                     FOR UPDATE'
                );

                $shipmentLockStmt->execute([
                    ':order_id' => $lockedOrderId
                ]);

                $lockedShipment = $shipmentLockStmt->fetch();

                if ($lockedShipment === false) {
                    throw new OrderActionException('The shipment record could not be found.');
                }

                /*
                 * MySQL reports rowCount() === 0 when a column is
                 * set to its current value, so the locked row is
                 * compared first and an identical value is treated
                 * as a successful no-op save.
                 */

                $currentTrackingNumber = trim($lockedShipment['tracking_number'] ?? '');

                if ($currentTrackingNumber !== $submittedTrackingNumber) {
                    $trackingUpdateStmt = $pdo->prepare(
                        'UPDATE shipments
                         SET tracking_number = :tracking_number
                         WHERE order_id = :order_id'
                    );

                    $trackingUpdateStmt->execute([
                        ':tracking_number' => $submittedTrackingNumber,
                        ':order_id' => $lockedOrderId
                    ]);

                    if ($trackingUpdateStmt->rowCount() !== 1) {
                        throw new OrderActionException('The tracking number could not be saved.');
                    }
                }

            } elseif ($postedAction === 'approve_cancellation') {

                if (
                    $lockedOrder['status'] !== 'PENDING'
                    || $lockedOrder['cancellation_status'] !== 'REQUESTED'
                ) {
                    throw new OrderActionException('This order has no pending cancellation request.');
                }

                /*
                 * Lock the products referenced by this order that are
                 * currently SOLD. Items with a NULL product_id are
                 * skipped by the join, products in any other status
                 * stay untouched, and cart items are intentionally
                 * not restored.
                 */

                $soldProductsStmt = $pdo->prepare(
                    'SELECT p.id
                     FROM order_items oi
                     INNER JOIN products p ON p.id = oi.product_id
                     WHERE oi.order_id = :order_id
                       AND p.status = \'SOLD\'
                     ORDER BY p.id ASC
                     FOR UPDATE'
                );

                $soldProductsStmt->execute([
                    ':order_id' => $postedOrderId
                ]);

                $soldProductIds = array_values(array_unique(array_map(
                    static function ($productId): int {
                        return (int) $productId;
                    },
                    $soldProductsStmt->fetchAll(PDO::FETCH_COLUMN)
                )));

                $cancelStmt = $pdo->prepare(
                    'UPDATE orders
                     SET status = \'CANCELLED\',
                         cancellation_status = \'APPROVED\'
                     WHERE id = :order_id
                       AND status = \'PENDING\'
                       AND cancellation_status = \'REQUESTED\''
                );

                $cancelStmt->execute([
                    ':order_id' => $postedOrderId
                ]);

                if ($cancelStmt->rowCount() !== 1) {
                    throw new OrderActionException('The order could not be cancelled.');
                }

                if (count($soldProductIds) > 0) {

                    $productPlaceholders = implode(',', array_fill(0, count($soldProductIds), '?'));

                    $restoreProductsStmt = $pdo->prepare(
                        'UPDATE products
                         SET status = \'AVAILABLE\'
                         WHERE status = \'SOLD\'
                           AND id IN (' . $productPlaceholders . ')'
                    );

                    $restoreProductsStmt->execute($soldProductIds);

                    if ($restoreProductsStmt->rowCount() !== count($soldProductIds)) {
                        throw new OrderActionException('The ordered products could not be restored.');
                    }
                }

            } else {

                if (
                    $lockedOrder['status'] !== 'PENDING'
                    || $lockedOrder['cancellation_status'] !== 'REQUESTED'
                ) {
                    throw new OrderActionException('This order has no pending cancellation request.');
                }

                /*
                 * Rejection keeps the order status at PENDING and
                 * leaves the products SOLD.
                 */

                $rejectStmt = $pdo->prepare(
                    'UPDATE orders
                     SET cancellation_status = \'REJECTED\'
                     WHERE id = :order_id
                       AND status = \'PENDING\'
                       AND cancellation_status = \'REQUESTED\''
                );

                $rejectStmt->execute([
                    ':order_id' => $postedOrderId
                ]);

                if ($rejectStmt->rowCount() !== 1) {
                    throw new OrderActionException('The cancellation request could not be rejected.');
                }
            }

            $pdo->commit();

            /*
             * Redirect only after the transaction has committed.
             */

            header('Location: order-view.php?id=' . $postedOrderId . '&updated=1');
            exit;

        } catch (OrderActionException $e) {

            /*
             * Every OrderActionException thrown above carries an
             * intentional, admin-facing validation message that is
             * safe to display.
             */

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $actionError = $e->getMessage();
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $actionError = 'The order could not be updated. Please try again.';
        }
    }
}

/*
 * Validate the requested order ID. Anything that is not a
 * positive integer is treated the same as an unknown order.
 */

$requestedOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$order = false;
$orderItems = [];
$payment = false;
$shipment = false;

if ($requestedOrderId !== null && $requestedOrderId !== false && $requestedOrderId > 0) {

    /*
     * Fetch the order together with its customer. If no order is
     * found, the remaining queries are skipped.
     */

    $orderStmt = $pdo->prepare(
        'SELECT o.id,
                o.customer_id,
                o.status,
                o.cancellation_status,
                o.subtotal,
                o.shipping_fee,
                o.total_amount,
                o.shipping_name,
                o.shipping_phone,
                o.shipping_address,
                o.created_at,
                o.updated_at,
                CONCAT(c.first_name, \' \', c.last_name) AS customer_name,
                c.email AS customer_email,
                c.phone AS customer_phone
         FROM orders o
         JOIN customers c ON c.id = o.customer_id
         WHERE o.id = :order_id
         LIMIT 1'
    );

    $orderStmt->execute([
        ':order_id' => $requestedOrderId
    ]);

    $order = $orderStmt->fetch();

    if ($order !== false) {
        $orderId = (int) $order['id'];

        /*
         * Item information comes from the order_items snapshot,
         * never from the current products row.
         */

        $itemsStmt = $pdo->prepare(
            'SELECT product_id, product_name, size, color, unit_price, quantity, subtotal
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );

        $itemsStmt->execute([
            ':order_id' => $orderId
        ]);

        $orderItems = $itemsStmt->fetchAll();

        /*
         * Payment and shipment records are optional. A missing row
         * is displayed as a safe fallback instead of an error.
         */

        $paymentStmt = $pdo->prepare(
            'SELECT payment_method, payment_status
             FROM payments
             WHERE order_id = :order_id
             LIMIT 1'
        );

        $paymentStmt->execute([
            ':order_id' => $orderId
        ]);

        $payment = $paymentStmt->fetch();

        $shipmentStmt = $pdo->prepare(
            'SELECT shipment_status, tracking_number, shipped_at, delivered_at
             FROM shipments
             WHERE order_id = :order_id
             LIMIT 1'
        );

        $shipmentStmt->execute([
            ':order_id' => $orderId
        ]);

        $shipment = $shipmentStmt->fetch();
    }
}

$pageTitle = $order !== false
    ? 'Order #' . (int) $order['id']
    : 'Order Not Found';

/*
 * Resolve labels with the raw database value as a fallback so
 * unexpected enum values still render safely.
 */

$orderStatusLabel = $order !== false
    ? ($orderStatusLabels[$order['status']] ?? $order['status'])
    : '';

$cancellationStatusLabel = $order !== false
    ? ($cancellationStatusLabels[$order['cancellation_status']] ?? $order['cancellation_status'])
    : '';

$paymentMethodLabel = ($payment !== false && $payment['payment_method'] !== null)
    ? ($paymentMethodLabels[$payment['payment_method']] ?? $payment['payment_method'])
    : 'No payment record';

$paymentStatusLabel = ($payment !== false && $payment['payment_status'] !== null)
    ? ($paymentStatusLabels[$payment['payment_status']] ?? $payment['payment_status'])
    : 'No payment record';

$shipmentStatusLabel = ($shipment !== false && $shipment['shipment_status'] !== null)
    ? ($shipmentStatusLabels[$shipment['shipment_status']] ?? $shipment['shipment_status'])
    : 'No shipment record';

$customerPhone = $order !== false ? trim($order['customer_phone'] ?? '') : '';

$trackingNumber = $shipment !== false
    ? trim($shipment['tracking_number'] ?? '')
    : '';

$shippedAtDisplay = ($shipment !== false && $shipment['shipped_at'] !== null)
    ? date('M j, Y g:i A', strtotime($shipment['shipped_at']))
    : '';

$deliveredAtDisplay = ($shipment !== false && $shipment['delivered_at'] !== null)
    ? date('M j, Y g:i A', strtotime($shipment['delivered_at']))
    : '';

/*
 * Success notice flag for the post/redirect/get flow.
 */

$updatedFlag = filter_input(INPUT_GET, 'updated', FILTER_VALIDATE_INT);

/*
 * Order action availability. The normal flow button only exists
 * while the current status still has a valid next status and no
 * cancellation request is pending. The cancellation buttons only
 * exist for a PENDING order with a REQUESTED cancellation.
 */

$nextOrderStatus = null;

if (
    $order !== false
    && $order['cancellation_status'] !== 'REQUESTED'
    && isset($orderStatusFlow[$order['status']])
) {
    $nextOrderStatus = $orderStatusFlow[$order['status']];
}

$nextOrderStatusLabel = $nextOrderStatus !== null
    ? ($orderStatusLabels[$nextOrderStatus] ?? $nextOrderStatus)
    : '';

$showCancellationActions = $order !== false
    && $order['status'] === 'PENDING'
    && $order['cancellation_status'] === 'REQUESTED';

/*
 * Tracking-number management needs an existing shipment record.
 * It is available from CONFIRMED onwards and is never available
 * for a CANCELLED order, a PENDING order, or while a cancellation
 * request is pending. The server re-checks these conditions when
 * the form is submitted.
 */

$showTrackingForm = $order !== false
    && $shipment !== false
    && in_array($order['status'], ['CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED'], true)
    && $order['cancellation_status'] !== 'REQUESTED';

?>
<?php
$orderStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'CONFIRMED' => 'badge-info',
    'PACKED' => 'badge-warning',
    'SHIPPED' => 'badge-info',
    'DELIVERED' => 'badge-success',
    'CANCELLED' => 'badge-cancelled'
];

$cancellationStatusBadgeClasses = [
    'NONE' => 'badge-cancellation-none',
    'REQUESTED' => 'badge-cancellation-requested',
    'APPROVED' => 'badge-cancellation-approved',
    'REJECTED' => 'badge-cancellation-rejected'
];

$paymentStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'PAID' => 'badge-success',
    'FAILED' => 'badge-cancelled',
    'REFUNDED' => 'badge-warning'
];

$shipmentStatusBadgeClasses = [
    'NOT_SHIPPED' => 'badge-pending',
    'READY_TO_SHIP' => 'badge-warning',
    'SHIPPED' => 'badge-info',
    'IN_TRANSIT' => 'badge-info',
    'OUT_FOR_DELIVERY' => 'badge-warning',
    'DELIVERED' => 'badge-success'
];

$page_title = $pageTitle . " - Admin - Hopia's Ukay-Ukay";
$page_description = "Review and manage order details at Hopia's Ukay-Ukay.";
$ui_section = 'admin';
$ui_active = 'orders.php';
$body_class = 'admin-order-detail-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<div class="admin-order-detail">
    <?php if ($updatedFlag === 1): ?>
        <div class="alert alert-success" role="status">The order has been updated.</div>
    <?php endif; ?>

    <?php if ($actionError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($order === false): ?>
        <div class="admin-order-detail__empty">
            <p class="admin-page-header__eyebrow">Order management</p>
            <h1>ORDER NOT FOUND</h1>
            <p>Order not found. The order ID may be missing, invalid, or the order may no longer exist.</p>
            <a class="btn btn-primary" href="orders.php">Back to Orders</a>
        </div>
    <?php else: ?>
        <header class="admin-order-detail__header">
            <div>
                <a class="admin-page-header__back" href="orders.php">&larr; Back to Orders</a>
                <p class="admin-page-header__eyebrow">Order management</p>
                <h1>ORDER #<?= (int) $order['id'] ?></h1>
                <p class="admin-order-detail__date">
                    Placed on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <div class="admin-order-detail__header-status" aria-label="Order statuses">
                <span class="badge <?= htmlspecialchars($orderStatusBadgeClasses[$order['status']] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="badge <?= htmlspecialchars($cancellationStatusBadgeClasses[$order['cancellation_status']] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($order['cancellation_status'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </header>

        <div class="admin-order-detail__layout">
            <div class="admin-order-detail__main">
                <section class="admin-order-detail__card" aria-labelledby="order-status-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Current state</p>
                            <h2 id="order-status-heading">Order status</h2>
                        </div>
                        <span class="badge <?= htmlspecialchars($orderStatusBadgeClasses[$order['status']] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="admin-order-detail__status-meta">
                        <div>
                            <span>Order status</span>
                            <strong><?= htmlspecialchars($orderStatusLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div>
                            <span>Cancellation</span>
                            <strong><?= htmlspecialchars($cancellationStatusLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </div>

                    <div class="admin-order-detail__actions">
                        <?php if ($showCancellationActions): ?>
                            <div class="admin-order-detail__action-note">
                                <p class="admin-order-detail__eyebrow">Cancellation request</p>
                                <p>This order is waiting for a cancellation decision.</p>
                            </div>

                            <div class="admin-order-detail__action-buttons">
                                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="action" value="approve_cancellation">
                                    <button class="btn btn-primary" type="submit" onclick="return confirm('Approve this cancellation request? The ordered items will become available again.')">
                                        Approve Cancellation
                                    </button>
                                </form>

                                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="action" value="reject_cancellation">
                                    <button class="btn btn-danger" type="submit" onclick="return confirm('Reject this cancellation request? The order will remain pending.')">
                                        Reject Cancellation
                                    </button>
                                </form>
                            </div>
                        <?php elseif ($nextOrderStatus !== null): ?>
                            <div class="admin-order-detail__action-note">
                                <p class="admin-order-detail__eyebrow">Next step</p>
                                <p>Advance this order when the next fulfillment step is complete.</p>
                            </div>

                            <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="action" value="advance_status">
                                <button class="btn btn-primary" type="submit">
                                    Mark as <?= htmlspecialchars($nextOrderStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <p class="muted">No actions are available for this order.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="admin-order-detail__card" aria-labelledby="ordered-items-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Order contents</p>
                            <h2 id="ordered-items-heading">Items</h2>
                        </div>
                        <span class="text-small muted"><?= count($orderItems) ?> item<?= count($orderItems) === 1 ? '' : 's' ?></span>
                    </div>

                    <?php if (count($orderItems) === 0): ?>
                        <p class="muted">No items are recorded for this order.</p>
                    <?php else: ?>
                        <div class="admin-order-items" role="list">
                            <?php foreach ($orderItems as $item): ?>
                                <?php
                                $size = trim($item['size'] ?? '');
                                $color = trim($item['color'] ?? '');
                                ?>
                                <article class="admin-order-item" role="listitem">
                                    <div class="admin-order-item__info">
                                        <h3><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <p class="admin-order-item__meta">
                                            Size: <?= $size !== '' ? htmlspecialchars($size, ENT_QUOTES, 'UTF-8') : '&mdash;' ?>
                                            <span aria-hidden="true">&middot;</span>
                                            Color: <?= $color !== '' ? htmlspecialchars($color, ENT_QUOTES, 'UTF-8') : '&mdash;' ?>
                                        </p>
                                    </div>
                                    <dl class="admin-order-item__details">
                                        <div>
                                            <dt>Unit price</dt>
                                            <dd>₱<?= number_format((float) $item['unit_price'], 2) ?></dd>
                                        </div>
                                        <div>
                                            <dt>Quantity</dt>
                                            <dd><?= htmlspecialchars((string) (int) $item['quantity'], ENT_QUOTES, 'UTF-8') ?></dd>
                                        </div>
                                        <div>
                                            <dt>Subtotal</dt>
                                            <dd>₱<?= number_format((float) $item['subtotal'], 2) ?></dd>
                                        </div>
                                    </dl>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <div class="admin-order-detail__info-grid">
                    <section class="admin-order-detail__card" aria-labelledby="customer-information-heading">
                        <div class="admin-order-detail__card-head">
                            <div>
                                <p class="admin-order-detail__eyebrow">Customer</p>
                                <h2 id="customer-information-heading">Customer information</h2>
                            </div>
                        </div>

                        <dl class="admin-order-detail__details">
                            <div>
                                <dt>Name</dt>
                                <dd><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt>Email</dt>
                                <dd><?= htmlspecialchars($order['customer_email'], ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt>Phone</dt>
                                <dd><?= $customerPhone !== '' ? htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                            </div>
                        </dl>
                    </section>

                    <section class="admin-order-detail__card" aria-labelledby="shipping-information-heading">
                        <div class="admin-order-detail__card-head">
                            <div>
                                <p class="admin-order-detail__eyebrow">Delivery</p>
                                <h2 id="shipping-information-heading">Shipping information</h2>
                            </div>
                        </div>

                        <dl class="admin-order-detail__details">
                            <div>
                                <dt>Name</dt>
                                <dd><?= htmlspecialchars($order['shipping_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt>Phone</dt>
                                <dd><?= htmlspecialchars($order['shipping_phone'], ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt>Address</dt>
                                <dd><?= htmlspecialchars($order['shipping_address'], ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        </dl>
                    </section>
                </div>

                <section class="admin-order-detail__card" aria-labelledby="payment-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Payment</p>
                            <h2 id="payment-heading">Payment information</h2>
                        </div>
                        <span class="badge <?= htmlspecialchars($paymentStatusBadgeClasses[$payment !== false ? ($payment['payment_status'] ?? '') : ''] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($paymentStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <dl class="admin-order-detail__details admin-order-detail__details--inline">
                        <div>
                            <dt>Payment method</dt>
                            <dd><?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Payment status</dt>
                            <dd><?= htmlspecialchars($paymentStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    </dl>
                </section>

                <section class="admin-order-detail__card" aria-labelledby="shipment-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Fulfillment</p>
                            <h2 id="shipment-heading">Shipment and tracking</h2>
                        </div>
                        <span class="badge <?= htmlspecialchars($shipmentStatusBadgeClasses[$shipment !== false ? ($shipment['shipment_status'] ?? '') : ''] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($shipmentStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <dl class="admin-order-detail__details admin-order-detail__details--inline">
                        <div>
                            <dt>Shipment status</dt>
                            <dd><?= htmlspecialchars($shipmentStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Tracking number</dt>
                            <dd><?= $trackingNumber !== '' ? htmlspecialchars($trackingNumber, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                        </div>
                        <div>
                            <dt>Shipped at</dt>
                            <dd><?= $shippedAtDisplay !== '' ? htmlspecialchars($shippedAtDisplay, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                        </div>
                        <div>
                            <dt>Delivered at</dt>
                            <dd><?= $deliveredAtDisplay !== '' ? htmlspecialchars($deliveredAtDisplay, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                        </div>
                    </dl>

                    <?php if ($showTrackingForm): ?>
                        <form class="admin-order-detail__tracking-form" method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="action" value="save_tracking_number">

                            <div class="field">
                                <label for="tracking-number-input">Tracking number</label>
                                <input
                                    type="text"
                                    id="tracking-number-input"
                                    name="tracking_number"
                                    maxlength="100"
                                    value="<?= htmlspecialchars($trackingNumber, ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>
                            <button class="btn btn-secondary" type="submit">Save Tracking Number</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>

            <aside class="admin-order-detail__aside">
                <section class="admin-order-detail__card admin-order-detail__totals" aria-labelledby="totals-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Order summary</p>
                            <h2 id="totals-heading">Totals</h2>
                        </div>
                    </div>

                    <dl class="admin-order-detail__total-list">
                        <div>
                            <dt>Subtotal</dt>
                            <dd>₱<?= number_format((float) $order['subtotal'], 2) ?></dd>
                        </div>
                        <div>
                            <dt>Shipping fee</dt>
                            <dd>₱<?= number_format((float) $order['shipping_fee'], 2) ?></dd>
                        </div>
                        <div class="admin-order-detail__total-list-grand">
                            <dt>Total amount</dt>
                            <dd>₱<?= number_format((float) $order['total_amount'], 2) ?></dd>
                        </div>
                    </dl>
                </section>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
