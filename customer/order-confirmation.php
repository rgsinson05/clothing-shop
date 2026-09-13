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
 * Customer-friendly labels. The database values remain unchanged.
 */

$orderStatusLabels = [
    'PENDING' => 'Order Placed',
    'CONFIRMED' => 'Confirmed',
    'PACKED' => 'Packed',
    'SHIPPED' => 'Shipped',
    'DELIVERED' => 'Delivered',
    'CANCELLED' => 'Cancelled'
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

/*
 * Validate the requested order ID. Anything that is not a
 * positive integer is treated the same as an unknown order.
 */

$requestedOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$order = false;
$orderItems = [];
$payment = false;

if ($requestedOrderId !== null && $requestedOrderId !== false && $requestedOrderId > 0) {
    /*
     * Fetch the order using BOTH the requested order ID and the
     * authenticated customer's ID, so a customer can only ever
     * view their own order.
     */

    $orderStmt = $pdo->prepare(
        'SELECT id, status, subtotal, shipping_fee, total_amount,
                shipping_name, shipping_phone, shipping_address, created_at
         FROM orders
         WHERE id = :order_id
           AND customer_id = :customer_id
         LIMIT 1'
    );

    $orderStmt->execute([
        ':order_id' => $requestedOrderId,
        ':customer_id' => $customerId
    ]);

    $order = $orderStmt->fetch();

    if ($order !== false) {
        $orderId = (int) $order['id'];

        /*
         * Item information comes from the order_items snapshot,
         * never from the current products row.
         */

        $itemsStmt = $pdo->prepare(
            'SELECT product_name, size, color, unit_price, quantity, subtotal
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );

        $itemsStmt->execute([':order_id' => $orderId]);

        $orderItems = $itemsStmt->fetchAll();

        $paymentStmt = $pdo->prepare(
            'SELECT payment_method, payment_status
             FROM payments
             WHERE order_id = :order_id
             LIMIT 1'
        );

        $paymentStmt->execute([':order_id' => $orderId]);

        $payment = $paymentStmt->fetch();
    }
}

$orderStatusLabel = $order !== false
    ? ($orderStatusLabels[$order['status']] ?? $order['status'])
    : '';

$paymentMethodLabel = ($payment !== false && $payment['payment_method'] !== null)
    ? ($paymentMethodLabels[$payment['payment_method']] ?? $payment['payment_method'])
    : '';

$paymentStatusLabel = ($payment !== false && $payment['payment_status'] !== null)
    ? ($paymentStatusLabels[$payment['payment_status']] ?? $payment['payment_status'])
    : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation - Hopia's Ukay-Ukay</title>

    <style>
        .notice {
            border: 1px solid #ccc;
            margin: 16px 0;
            padding: 12px;
        }

        .notice-success {
            border-color: #176b2c;
            color: #176b2c;
        }

        .confirmation-section {
            border: 1px solid #ccc;
            padding: 16px;
            margin-top: 16px;
        }

        .confirmation-section h3 {
            margin-top: 0;
        }

        .items-table {
            border-collapse: collapse;
            width: 100%;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        .items-table .amount {
            text-align: right;
            white-space: nowrap;
        }

        .totals p {
            margin: 8px 0;
        }

        .totals .order-total {
            border-top: 1px solid #ccc;
            font-weight: bold;
            margin-top: 8px;
            padding-top: 8px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 160px minmax(0, 1fr);
            gap: 8px 16px;
        }

        .detail-grid dt {
            font-weight: bold;
        }

        .detail-grid dd {
            margin: 0;
        }

        .confirmation-actions p {
            margin: 16px 0 0;
        }

        @media (max-width: 700px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <h1>Hopia's Ukay-Ukay</h1>

    <p>
        <a href="orders.php">&larr; My Orders</a>
    </p>

    <hr>

    <?php if ($order === false): ?>

        <h2>Order Confirmation</h2>

        <p>Order not found.</p>

        <p>
            <a href="orders.php">My Orders</a>
            |
            <a href="products.php">Continue Shopping</a>
        </p>

    <?php else: ?>

        <h2>Thank you for your order!</h2>

        <p class="notice notice-success" role="status">
            Your order has been placed successfully.
        </p>

        <section class="confirmation-section" aria-labelledby="order-details-heading">
            <h3 id="order-details-heading">Order Details</h3>

            <dl class="detail-grid">
                <dt>Order Number:</dt>
                <dd>#<?= htmlspecialchars((string) $order['id'], ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Status:</dt>
                <dd><?= htmlspecialchars($orderStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Placed On:</dt>
                <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        </section>

        <section class="confirmation-section" aria-labelledby="order-items-heading">
            <h3 id="order-items-heading">Items Purchased</h3>

            <table class="items-table">
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">Size</th>
                        <th scope="col">Color</th>
                        <th scope="col" class="amount">Unit Price</th>
                        <th scope="col" class="amount">Quantity</th>
                        <th scope="col" class="amount">Subtotal</th>
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
                            <td class="amount">₱<?= number_format((float) $item['unit_price'], 2) ?></td>
                            <td class="amount"><?= htmlspecialchars((string) (int) $item['quantity'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="amount">₱<?= number_format((float) $item['subtotal'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals">
                <p>Subtotal: ₱<?= number_format((float) $order['subtotal'], 2) ?></p>
                <p>Shipping: To be confirmed</p>
                <p class="order-total">Total: ₱<?= number_format((float) $order['total_amount'], 2) ?></p>
            </div>
        </section>

        <section class="confirmation-section" aria-labelledby="shipping-details-heading">
            <h3 id="shipping-details-heading">Shipping Details</h3>

            <dl class="detail-grid">
                <dt>Name:</dt>
                <dd><?= htmlspecialchars($order['shipping_name'], ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Phone:</dt>
                <dd><?= htmlspecialchars($order['shipping_phone'], ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Address:</dt>
                <dd><?= htmlspecialchars($order['shipping_address'], ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        </section>

        <section class="confirmation-section" aria-labelledby="payment-details-heading">
            <h3 id="payment-details-heading">Payment Details</h3>

            <dl class="detail-grid">
                <dt>Payment Method:</dt>
                <dd><?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?></dd>

                <dt>Payment Status:</dt>
                <dd><?= htmlspecialchars($paymentStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        </section>

        <div class="confirmation-actions">
            <p>
                <a href="orders.php">My Orders</a>
                |
                <a href="products.php">Continue Shopping</a>
            </p>
        </div>

    <?php endif; ?>

</body>
</html>
