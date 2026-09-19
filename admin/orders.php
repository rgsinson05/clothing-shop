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

$page_title = "Orders - Admin - Hopia's Ukay-Ukay";
$page_description = "Manage customer orders at Hopia's Ukay-Ukay.";
$ui_section = 'admin';
$ui_active = 'orders.php';
$body_class = 'admin-orders-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<div class="admin-orders">
    <header class="admin-page-header">
        <div>
            <p class="admin-page-header__eyebrow">Order management</p>
            <h1>ORDERS</h1>
            <p class="admin-page-header__copy">
                Welcome, <?= htmlspecialchars($adminName) ?>. Review and manage customer orders from one place.
            </p>
        </div>
    </header>

    <?php if (count($orders) === 0): ?>

        <div class="empty-state">
            <p>No orders have been placed yet.</p>
        </div>

    <?php else: ?>

        <div class="admin-orders__summary">
            <p class="text-small muted">
                Showing <?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?>, newest first.
            </p>
        </div>

        <div class="admin-order-list">
            <?php foreach ($orders as $order): ?>
                <?php
                $orderStatus = (string) $order['status'];
                $cancellationStatus = (string) $order['cancellation_status'];
                $orderStatusBadgeClass = $orderStatusBadgeClasses[$orderStatus] ?? 'badge';
                $cancellationStatusBadgeClass = $cancellationStatusBadgeClasses[$cancellationStatus] ?? 'badge';
                ?>

                <article class="admin-order-card">
                    <div class="admin-order-card__header">
                        <div>
                            <p class="admin-order-card__eyebrow">Order</p>
                            <h2>#<?= htmlspecialchars((string) $order['id'], ENT_QUOTES, 'UTF-8') ?></h2>
                        </div>

                        <div class="admin-order-card__statuses">
                            <span class="badge <?= htmlspecialchars($orderStatusBadgeClass, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="badge <?= htmlspecialchars($cancellationStatusBadgeClass, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($cancellationStatus, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>

                    <dl class="admin-order-card__details">
                        <div>
                            <dt>Customer</dt>
                            <dd><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Order date</dt>
                            <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Total amount</dt>
                            <dd class="admin-order-card__total">₱<?= number_format((float) $order['total_amount'], 2) ?></dd>
                        </div>
                        <div>
                            <dt>Cancellation</dt>
                            <dd><?= htmlspecialchars($cancellationStatus, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    </dl>

                    <div class="admin-order-card__actions">
                        <a class="btn btn-primary btn-sm" href="order-view.php?id=<?= (int) $order['id'] ?>">
                            View Order
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
