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

            $pdo->beginTransaction();

            /*
             * Lock the order row first, then re-check the business
             * rules against the locked database state so a stale
             * browser page cannot cause an invalid transition.
             */

            $lockStmt = $pdo->prepare(
                'SELECT status, cancellation_status
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
                throw new RuntimeException('The order no longer exists.');
            }

            if ($postedAction === 'advance_status') {

                if ($lockedOrder['cancellation_status'] === 'REQUESTED') {
                    throw new RuntimeException('A cancellation request is pending for this order.');
                }

                if (!isset($orderStatusFlow[$lockedOrder['status']])) {
                    throw new RuntimeException('This order status has no next status.');
                }

                $targetStatus = $orderStatusFlow[$lockedOrder['status']];

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
                    throw new RuntimeException('The order status could not be advanced.');
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
                        ':order_id' => $postedOrderId
                    ]);

                    if ($shipmentUpdateStmt->rowCount() !== 1) {
                        throw new RuntimeException('The shipment record could not be updated.');
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
                        ':order_id' => $postedOrderId
                    ]);

                    if ($shipmentUpdateStmt->rowCount() !== 1) {
                        throw new RuntimeException('The shipment record could not be updated.');
                    }
                }

            } elseif ($postedAction === 'approve_cancellation') {

                if (
                    $lockedOrder['status'] !== 'PENDING'
                    || $lockedOrder['cancellation_status'] !== 'REQUESTED'
                ) {
                    throw new RuntimeException('This order has no pending cancellation request.');
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
                    throw new RuntimeException('The order could not be cancelled.');
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
                        throw new RuntimeException('The ordered products could not be restored.');
                    }
                }

            } else {

                if (
                    $lockedOrder['status'] !== 'PENDING'
                    || $lockedOrder['cancellation_status'] !== 'REQUESTED'
                ) {
                    throw new RuntimeException('This order has no pending cancellation request.');
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
                    throw new RuntimeException('The cancellation request could not be rejected.');
                }
            }

            $pdo->commit();

            /*
             * Redirect only after the transaction has committed.
             */

            header('Location: order-view.php?id=' . $postedOrderId . '&updated=1');
            exit;

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - Admin - Hopia's Ukay-Ukay</title>
</head>
<body>

    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

    <p>
        Welcome, <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>.
    </p>

    <p>
        <a href="index.php">Dashboard</a>
        |
        <a href="orders.php">Orders</a>
        |
        <a href="logout.php">Logout</a>
    </p>

    <hr>

    <?php if ($updatedFlag === 1): ?>

        <p role="status">The order has been updated.</p>

    <?php endif; ?>

    <?php if ($actionError !== ''): ?>

        <p role="alert"><?= htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8') ?></p>

    <?php endif; ?>

    <?php if ($order === false): ?>

        <p>Order not found. The order ID may be missing, invalid, or the order may no longer exist.</p>

        <p>
            <a href="orders.php">&larr; Back to Orders</a>
        </p>

    <?php else: ?>

        <section aria-labelledby="order-information-heading">
            <h2 id="order-information-heading">Order Information</h2>

            <dl>
                <dt>Order ID:</dt>
                <dd>#<?= (int) $order['id'] ?></dd>

                <dt>Order Date:</dt>
                <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Order Status:</dt>
                <dd><?= htmlspecialchars($orderStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Cancellation Status:</dt>
                <dd><?= htmlspecialchars($cancellationStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        </section>

        <section aria-labelledby="customer-information-heading">
            <h2 id="customer-information-heading">Customer Information</h2>

            <dl>
                <dt>Customer Name:</dt>
                <dd><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Email:</dt>
                <dd><?= htmlspecialchars($order['customer_email'], ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Phone:</dt>
                <dd><?= $customerPhone !== '' ? htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
            </dl>
        </section>

        <section aria-labelledby="ordered-items-heading">
            <h2 id="ordered-items-heading">Ordered Items</h2>

            <?php if (count($orderItems) === 0): ?>

                <p>No items are recorded for this order.</p>

            <?php else: ?>

                <table border="1" cellpadding="8">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Size</th>
                            <th>Color</th>
                            <th>Unit Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($orderItems as $item): ?>
                            <?php
                            $size = trim($item['size'] ?? '');
                            $color = trim($item['color'] ?? '');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $size !== '' ? htmlspecialchars($size, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></td>
                                <td><?= $color !== '' ? htmlspecialchars($color, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></td>
                                <td>₱<?= number_format((float) $item['unit_price'], 2) ?></td>
                                <td><?= htmlspecialchars((string) (int) $item['quantity'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>₱<?= number_format((float) $item['subtotal'], 2) ?></td>
                            </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endif; ?>
        </section>

        <section aria-labelledby="totals-heading">
            <h2 id="totals-heading">Totals</h2>

            <dl>
                <dt>Subtotal:</dt>
                <dd>₱<?= number_format((float) $order['subtotal'], 2) ?></dd>

                <dt>Shipping Fee:</dt>
                <dd>₱<?= number_format((float) $order['shipping_fee'], 2) ?></dd>

                <dt>Total Amount:</dt>
                <dd>₱<?= number_format((float) $order['total_amount'], 2) ?></dd>
            </dl>
        </section>

        <section aria-labelledby="shipping-information-heading">
            <h2 id="shipping-information-heading">Shipping Information</h2>

            <dl>
                <dt>Shipping Name:</dt>
                <dd><?= htmlspecialchars($order['shipping_name'], ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Shipping Phone:</dt>
                <dd><?= htmlspecialchars($order['shipping_phone'], ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Shipping Address:</dt>
                <dd><?= htmlspecialchars($order['shipping_address'], ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        </section>

        <section aria-labelledby="payment-heading">
            <h2 id="payment-heading">Payment</h2>

            <dl>
                <dt>Payment Method:</dt>
                <dd><?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Payment Status:</dt>
                <dd><?= htmlspecialchars($paymentStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        </section>

        <section aria-labelledby="shipment-heading">
            <h2 id="shipment-heading">Shipment</h2>

            <dl>
                <dt>Shipment Status:</dt>
                <dd><?= htmlspecialchars($shipmentStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Tracking Number:</dt>
                <dd><?= $trackingNumber !== '' ? htmlspecialchars($trackingNumber, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>

                <dt>Shipped At:</dt>
                <dd><?= $shippedAtDisplay !== '' ? htmlspecialchars($shippedAtDisplay, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>

                <dt>Delivered At:</dt>
                <dd><?= $deliveredAtDisplay !== '' ? htmlspecialchars($deliveredAtDisplay, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
            </dl>
        </section>

        <section aria-labelledby="order-actions-heading">
            <h2 id="order-actions-heading">Order Actions</h2>

            <?php if ($showCancellationActions): ?>

                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="action" value="approve_cancellation">

                    <button type="submit" onclick="return confirm('Approve this cancellation request? The ordered items will become available again.')">
                        Approve Cancellation
                    </button>
                </form>

                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="action" value="reject_cancellation">

                    <button type="submit" onclick="return confirm('Reject this cancellation request? The order will remain pending.')">
                        Reject Cancellation
                    </button>
                </form>

            <?php elseif ($nextOrderStatus !== null): ?>

                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="action" value="advance_status">

                    <button type="submit">
                        Mark as <?= htmlspecialchars($nextOrderStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>

            <?php else: ?>

                <p>No actions are available for this order.</p>

            <?php endif; ?>
        </section>

        <p>
            <a href="orders.php">&larr; Back to Orders</a>
        </p>

    <?php endif; ?>

</body>
</html>
