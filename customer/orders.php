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
    'CONFIRMED' => 'Order Confirmed',
    'PACKED' => 'Being Prepared',
    'SHIPPED' => 'Shipped',
    'DELIVERED' => 'Delivered',
    'CANCELLED' => 'Cancelled'
];

/*
 * Cancellation indicators. Shown on an order card only when a
 * cancellation request exists for the order.
 */

$cancellationStatusLabels = [
    'REQUESTED' => 'Cancellation Requested',
    'APPROVED' => 'Cancellation Approved',
    'REJECTED' => 'Cancellation Rejected'
];

/*
 * Status badge classes per order status.
 */

$orderStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'CONFIRMED' => 'badge-info',
    'PACKED' => 'badge-warning',
    'SHIPPED' => 'badge-info',
    'DELIVERED' => 'badge-success',
    'CANCELLED' => 'badge-cancelled'
];

/*
 * Simple customer-facing filter groups over the existing
 * database statuses. Display-only; no new statuses.
 */

$activeStatuses = ['PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED'];

$orderFilter = isset($_GET['filter']) ? strtoupper(trim((string) $_GET['filter'])) : 'ALL';

if (!in_array($orderFilter, ['ALL', 'ACTIVE', 'COMPLETED'], true)) {
    $orderFilter = 'ALL';
}

/*
 * Retrieve only the orders belonging to the authenticated
 * customer, newest first. The query is unchanged from the
 * previous version; filtering happens on the fetched rows.
 */

$ordersStmt = $pdo->prepare(
    'SELECT id, status, cancellation_status, total_amount, created_at
     FROM orders
     WHERE customer_id = :customer_id
     ORDER BY created_at DESC, id DESC'
);

$ordersStmt->execute([':customer_id' => $customerId]);
$orders = $ordersStmt->fetchAll();

/*
 * Fetch exactly one representative item per order using a
 * window function. This avoids ONLY_FULL_GROUP_BY issues by
 * isolating the row-numbering logic in a derived table where
 * the non-grouped columns are not subject to the mode.
 *
 * Strategy: assign row_number() partitioned by order_id,
 * ordered by item id (first item = smallest id), then
 * select only rn=1 rows.
 */

$orderItemsStmt = $pdo->prepare(
    'SELECT rep.order_id, rep.product_name, pi.image_path
     FROM (
         SELECT oi.order_id, oi.product_name,
                ROW_NUMBER() OVER (
                    PARTITION BY oi.order_id
                    ORDER BY oi.id ASC
                ) AS rn
         FROM order_items oi
         WHERE oi.order_id IN (
             SELECT DISTINCT o2.id
             FROM orders o2
             WHERE o2.customer_id = :customer_id
         )
     ) AS rep
     LEFT JOIN product_images pi
         ON pi.id = (
             SELECT pi2.id
             FROM product_images pi2
             WHERE pi2.product_id = (
                 SELECT oi2.product_id
                 FROM order_items oi2
                 WHERE oi2.order_id = rep.order_id
                 ORDER BY oi2.id ASC
                 LIMIT 1
             )
             ORDER BY pi2.sort_order ASC, pi2.id ASC
             LIMIT 1
         )
     WHERE rep.rn = 1'
);

$orderItemsStmt->execute([':customer_id' => $customerId]);
$orderItemsRows = $orderItemsStmt->fetchAll();

$orderItemsMap = [];
foreach ($orderItemsRows as $row) {
    $orderItemsMap[(int) $row['order_id']] = [
        'product_name' => $row['product_name'],
        'image_path' => $row['image_path']
    ];
}

/*
 * Build the UI page. Include shared head, header, and footer.
 */

require_once __DIR__ . '/../includes/ui.php';

$page_title = 'My Orders - ' . hopia_site_name();
$page_description = 'Track your orders at ' . hopia_site_name() . '.';
$ui_active = 'orders.php';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';

?>

<section class="orders-page">
    <header class="orders-header">
        <h1>MY ORDERS</h1>
    </header>

    <nav class="orders-filters" aria-label="Order filters">
        <ul class="orders-filter-list" role="list">
            <li>
                <a class="orders-filter<?= $orderFilter === 'ALL' ? ' is-active' : '' ?>"
                   href="?filter=all">ALL</a>
            </li>
            <li>
                <a class="orders-filter<?= $orderFilter === 'ACTIVE' ? ' is-active' : '' ?>"
                   href="?filter=active">ACTIVE</a>
            </li>
            <li>
                <a class="orders-filter<?= $orderFilter === 'COMPLETED' ? ' is-active' : '' ?>"
                   href="?filter=completed">COMPLETED</a>
            </li>
        </ul>
    </nav>

    <?php

    /*
     * Apply the requested display filter to the already-fetched
     * orders. No additional database query required.
     */

    $filteredOrders = array_filter($orders, function ($order) use ($orderFilter, $activeStatuses) {
        if ($orderFilter === 'ALL') {
            return true;
        }
        if ($orderFilter === 'ACTIVE') {
            return in_array($order['status'], $activeStatuses, true);
        }
        return $order['status'] === 'DELIVERED' || $order['status'] === 'CANCELLED';
    });

    ?>

    <?php if (count($filteredOrders) === 0): ?>

        <div class="orders-empty">
            <div class="orders-empty__icon" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
            </div>
            <?php if ($orderFilter === 'ALL'): ?>
                <h2>No orders yet</h2>
                <p>Start shopping to place your first order.</p>
            <?php else: ?>
                <h2>No <?= hopia_e(strtolower($orderFilter)) ?> orders</h2>
                <p>You have no orders in this category right now.</p>
            <?php endif; ?>
            <a class="btn btn-primary" href="products.php">SHOP ALL FINDS</a>
        </div>

    <?php else: ?>

        <ul class="orders-list" role="list">
            <?php foreach ($filteredOrders as $order):
                $orderId = (int) $order['id'];
                $orderStatus = (string) $order['status'];
                $cancellationStatus = (string) $order['cancellation_status'];
                $statusLabel = $orderStatusLabels[$orderStatus] ?? $orderStatus;
                $badgeClass = $orderStatusBadgeClasses[$orderStatus] ?? 'badge';
                $item = $orderItemsMap[$orderId] ?? null;
                $viewUrl = 'order-detail.php?id=' . $orderId;
                $hasCancellation = $cancellationStatus !== 'NONE'
                    && isset($cancellationStatusLabels[$cancellationStatus]);
            ?>
                <li class="order-card">
                    <a class="order-card__link" href="<?= hopia_e($viewUrl) ?>">
                        <div class="order-card__product">
                            <?php if ($item !== null && trim($item['image_path']) !== ''): ?>
                                <div class="order-card__thumb">
                                    <img
                                        src="<?= hopia_e('../' . ltrim(trim($item['image_path']), '/')) ?>"
                                        alt="<?= hopia_e($item['product_name']) ?>"
                                        loading="lazy"
                                    >
                                </div>
                            <?php else: ?>
                                <div class="order-card__thumb order-card__thumb--placeholder" aria-hidden="true">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                </div>
                            <?php endif; ?>
                            <div class="order-card__info">
                                <p class="order-card__name">
                                    <?= hopia_e($item !== null ? $item['product_name'] : 'Order #' . $orderId) ?>
                                </p>
                                <p class="order-card__date">
                                    <?= hopia_e(date('M j, Y', strtotime($order['created_at']))) ?>
                                </p>
                            </div>
                        </div>
                    </a>
                    <div class="order-card__meta">
                        <span class="badge <?= hopia_e($badgeClass) ?>">
                            <?= hopia_e($statusLabel) ?>
                        </span>
                        <?php if ($hasCancellation): ?>
                            <span class="order-card__cancel-note">
                                <?= hopia_e($cancellationStatusLabels[$cancellationStatus]) ?>
                            </span>
                        <?php endif; ?>
                        <p class="order-card__amount price">
                            ₱<?= number_format((float) $order['total_amount'], 2) ?>
                        </p>
                        <a class="btn btn-secondary btn-sm order-card__view" href="<?= hopia_e($viewUrl) ?>">
                            VIEW ORDER
                        </a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
