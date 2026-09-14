<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';

$adminName = $_SESSION['admin_name'] ?? 'Admin';

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

        <p>
            <a href="orders.php">&larr; Back to Orders</a>
        </p>

    <?php endif; ?>

</body>
</html>
