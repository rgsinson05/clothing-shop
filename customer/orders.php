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

$cancellationStatusLabels = [
    'NONE' => 'No Request',
    'REQUESTED' => 'Cancellation Requested',
    'APPROVED' => 'Cancellation Approved',
    'REJECTED' => 'Cancellation Rejected'
];

/*
 * Retrieve only the orders belonging to the authenticated
 * customer, newest first.
 */

$ordersStmt = $pdo->prepare(
    'SELECT id, status, cancellation_status, total_amount, created_at
     FROM orders
     WHERE customer_id = :customer_id
     ORDER BY created_at DESC, id DESC'
);

$ordersStmt->execute([':customer_id' => $customerId]);

$orders = $ordersStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - Hopia's Ukay-Ukay</title>

    <style>
        .orders-table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 16px;
        }

        .orders-table th,
        .orders-table td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        .orders-table .amount {
            text-align: right;
            white-space: nowrap;
        }

        .empty-state {
            margin-top: 16px;
        }
    </style>
</head>
<body>

    <h1>Hopia's Ukay-Ukay</h1>

    <p>
        <a href="products.php">&larr; Continue Shopping</a>
    </p>

    <hr>

    <h2>My Orders</h2>

    <?php if (count($orders) === 0): ?>

        <p class="empty-state">
            You have no orders yet.
            <a href="products.php">Continue Shopping</a>
        </p>

    <?php else: ?>

        <table class="orders-table">
            <thead>
                <tr>
                    <th scope="col">Order Number</th>
                    <th scope="col">Status</th>
                    <th scope="col">Cancellation</th>
                    <th scope="col" class="amount">Total</th>
                    <th scope="col">Placed On</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $statusLabel = $orderStatusLabels[$order['status']] ?? $order['status'];
                    $cancellationLabel = $cancellationStatusLabels[$order['cancellation_status']] ?? $order['cancellation_status'];
                    $viewUrl = 'order-confirmation.php?id=' . (int) $order['id'];
                    ?>
                    <tr>
                        <td>#<?= htmlspecialchars((string) $order['id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($cancellationLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="amount">₱<?= number_format((float) $order['total_amount'], 2) ?></td>
                        <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>">View Order</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</body>
</html>
