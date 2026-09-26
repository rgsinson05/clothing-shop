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

$orderFilter = isset($_GET['filter'])
    ? strtoupper(str_replace('-', ' ', trim((string) $_GET['filter'])))
    : 'ALL';

if (!in_array($orderFilter, ['ALL', 'IN PROGRESS', 'DELIVERED', 'CANCELLED'], true)) {
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
        <p class="orders-intro">Keep track of your purchases and their current status.</p>
    </header>

    <nav class="orders-filters" aria-label="Order filters">
        <div class="orders-filter-tabs" role="list">
            <a class="orders-filter<?= $orderFilter === 'ALL' ? ' is-active' : '' ?>"
               href="?filter=all"<?= $orderFilter === 'ALL' ? ' aria-current="true"' : '' ?>>ALL</a>
            <a class="orders-filter<?= $orderFilter === 'IN PROGRESS' ? ' is-active' : '' ?>"
               href="?filter=in-progress"<?= $orderFilter === 'IN PROGRESS' ? ' aria-current="true"' : '' ?>>IN PROGRESS</a>
            <a class="orders-filter<?= $orderFilter === 'DELIVERED' ? ' is-active' : '' ?>"
               href="?filter=delivered"<?= $orderFilter === 'DELIVERED' ? ' aria-current="true"' : '' ?>>DELIVERED</a>
            <a class="orders-filter<?= $orderFilter === 'CANCELLED' ? ' is-active' : '' ?>"
               href="?filter=cancelled"<?= $orderFilter === 'CANCELLED' ? ' aria-current="true"' : '' ?>>CANCELLED</a>
        </div>
    </nav>

    <?php

    /*
     * Apply the requested display filter to the already-fetched
     * orders. No additional database query required.
     */

    $inProgressStatuses = ['PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED'];

    $filteredOrders = array_filter($orders, function ($order) use ($orderFilter, $inProgressStatuses) {
        if ($orderFilter === 'ALL') {
            return true;
        }
        if ($orderFilter === 'IN PROGRESS') {
            return in_array($order['status'], $inProgressStatuses, true);
        }
        if ($orderFilter === 'DELIVERED') {
            return $order['status'] === 'DELIVERED';
        }
        return $order['status'] === 'CANCELLED';
    });

    ?>

    <?php if (count($filteredOrders) === 0): ?>

        <div class="orders-empty">
            <?php if ($orderFilter === 'ALL'): ?>
                <p>No orders yet.</p>
            <?php elseif ($orderFilter === 'IN PROGRESS'): ?>
                <p>No orders in progress.</p>
            <?php elseif ($orderFilter === 'DELIVERED'): ?>
                <p>No delivered orders yet.</p>
            <?php else: ?>
                <p>No cancelled orders yet.</p>
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
                $productName = $item !== null ? (string) $item['product_name'] : 'Order #' . $orderId;
                $viewUrl = 'order-detail.php?id=' . $orderId;
                $hasCancellationNote = $cancellationStatus !== 'NONE'
                    && isset($cancellationStatusLabels[$cancellationStatus]);
            ?>
                <li class="order-card">
                    <?php if ($item !== null && trim($item['image_path']) !== ''): ?>
                        <div class="order-card__media">
                            <img
                                src="<?= hopia_e('../' . ltrim(trim($item['image_path']), '/')) ?>"
                                alt="<?= hopia_e($productName) ?>"
                                loading="lazy"
                            >
                        </div>
                    <?php else: ?>
                        <div class="order-card__media order-card__media--placeholder" aria-hidden="true">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                            </svg>
                        </div>
                    <?php endif; ?>

                    <div class="order-card__identity">
                        <h2 class="order-card__name"><?= hopia_e($productName) ?></h2>
                        <p class="order-card__date">
                            <?= hopia_e(date('M j, Y', strtotime($order['created_at']))) ?>
                        </p>
                    </div>

                    <div class="order-card__status">
                        <span class="badge <?= hopia_e($badgeClass) ?>">
                            <?= hopia_e($statusLabel) ?>
                        </span>
                        <?php if ($hasCancellationNote): ?>
                            <span class="order-card__cancel-note">
                                <?= hopia_e($cancellationStatusLabels[$cancellationStatus]) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <p class="order-card__price price">
                        ₱<?= number_format((float) $order['total_amount'], 2) ?>
                    </p>

                    <div class="order-card__action">
                        <a class="btn btn-primary btn-sm order-card__view" href="<?= hopia_e($viewUrl) ?>" aria-label="View order: <?= hopia_e($productName) ?>">
                            VIEW ORDER
                        </a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
