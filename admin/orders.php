<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';

$ordersStmt = $pdo->prepare(
    'SELECT o.id,
            o.status,
            o.cancellation_status,
            o.total_amount,
            o.created_at,
            CONCAT(c.first_name, \' \', c.last_name) AS customer_name
     FROM orders o
     JOIN customers c ON c.id = o.customer_id
     ORDER BY o.created_at DESC, o.id DESC'
);

$ordersStmt->execute();

$orders = $ordersStmt->fetchAll();

$adminName = $_SESSION['admin_name'] ?? 'Admin';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - Admin - Hopia's Ukay-Ukay</title>
</head>
<body>

    <h1>Orders</h1>

    <p>
        Welcome, <?= htmlspecialchars($adminName) ?>.
    </p>

    <p>
        <a href="index.php">Dashboard</a>
        |
        <a href="logout.php">Logout</a>
    </p>

    <hr>

    <?php if (count($orders) === 0): ?>

        <p>No orders have been placed yet.</p>

    <?php else: ?>

        <table border="1" cellpadding="8">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer Name</th>
                    <th>Order Date</th>
                    <th>Total Amount</th>
                    <th>Order Status</th>
                    <th>Cancellation Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($orders as $order): ?>

                    <tr>
                        <td>#<?= htmlspecialchars((string) $order['id'], ENT_QUOTES, 'UTF-8') ?></td>

                        <td>
                            <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td>
                            ₱<?= number_format((float) $order['total_amount'], 2) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($order['cancellation_status'], ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td>
                            <a href="order-view.php?id=<?= (int) $order['id'] ?>">
                                View Order
                            </a>
                        </td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</body>
</html>
