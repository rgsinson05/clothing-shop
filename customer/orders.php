<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/feedback-data.php';
require_once __DIR__ . '/../includes/pickup.php';
require_once __DIR__ . '/../includes/customer_delivery.php';

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
    'PENDING' => 'Pending',
    'CONFIRMED' => 'Confirmed',
    'PACKED' => 'Packed',
    'SHIPPED' => 'Shipped',
    'IN_TRANSIT' => 'In Transit',
    'RETURN_TO_SELLER' => 'Return to Seller',
    'RETURNED_TO_SHOP' => 'Returned to Shop',
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
    'IN_TRANSIT' => 'badge-info',
    'RETURN_TO_SELLER' => 'badge-warning',
    'RETURNED_TO_SHOP' => 'badge-info',
    'DELIVERED' => 'badge-success',
    'CANCELLED' => 'badge-cancelled'
];

/*
 * Status icons. PENDING = clock (waiting); CONFIRMED / PACKED / SHIPPED =
 * package (being prepared / in motion); DELIVERED = check; CANCELLED = x.
 * Returns '' for unknown statuses so the badge renders icon-less.
 */

$orderStatusIconKeys = [
    'PENDING' => 'clock',
    'CONFIRMED' => 'package',
    'PACKED' => 'package',
    'SHIPPED' => 'package',
    'IN_TRANSIT' => 'package',
    'RETURN_TO_SELLER' => 'package',
    'RETURNED_TO_SHOP' => 'package',
    'DELIVERED' => 'check',
    'CANCELLED' => 'x'
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
 * customer, newest first. Shipment and payment facts support display-only
 * status and receipt gating; filtering happens on the fetched rows.
 */

$ordersStmt = $pdo->prepare(
    'SELECT o.id, o.status, o.cancellation_status, o.total_amount, o.created_at, o.received_at,
            o.pickup_status, o.ready_for_pickup_at, o.pickup_deadline_at, o.picked_up_at,
            s.delivery_method, s.shipment_status, p.payment_method, p.payment_status
     FROM orders o
     LEFT JOIN shipments s ON s.order_id = o.id
     LEFT JOIN payments p ON p.order_id = o.id
     WHERE o.customer_id = :customer_id
     ORDER BY o.created_at DESC, o.id DESC'
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
    'SELECT rep.order_id, rep.order_item_id, rep.product_name, rep.size, rep.color, pi.image_path,
            p.category
     FROM (
        SELECT oi.id AS order_item_id, oi.order_id, oi.product_name, oi.size, oi.color,
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
     LEFT JOIN products p
         ON p.id = (
             SELECT oi3.product_id
             FROM order_items oi3
             WHERE oi3.order_id = rep.order_id
             ORDER BY oi3.id ASC
             LIMIT 1
         )
     WHERE rep.rn = 1'
);

$orderItemsStmt->execute([':customer_id' => $customerId]);
$orderItemsRows = $orderItemsStmt->fetchAll();

$orderItemsMap = [];
foreach ($orderItemsRows as $row) {
    $orderItemsMap[(int) $row['order_id']] = [
        'order_item_id' => (int) $row['order_item_id'],
        'product_name' => $row['product_name'],
        'size' => $row['size'],
        'color' => $row['color'],
        'category' => isset($row['category']) ? (string) $row['category'] : '',
        'image_path' => $row['image_path']
    ];
}

/*
 * Feedback lookup for the LEAVE FEEDBACK / EDIT FEEDBACK decision. One
 * read-only query covers every representative order item, keyed by
 * order_item_id, so each DELIVERED order card can tell whether this
 * customer already submitted feedback for that item.
 */

$feedbackByItem = hopia_feedback_fetch_customer_item_map(
    $pdo,
    $customerId,
    array_map(function ($row) {
        return (int) $row['order_item_id'];
    }, $orderItemsRows)
);

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
        <p class="orders-header__eyebrow">ACCOUNT <span aria-hidden="true">/</span> PURCHASES</p>
        <h1>MY ORDERS</h1>
        <div class="orders-header__rule" aria-hidden="true"></div>
        <p class="orders-intro">Track your purchases and see their latest status.</p>
    </header>

    <nav class="orders-filters" aria-label="Order filters">
        <div class="orders-filter-tabs" role="list">
            <a class="orders-filter<?= $orderFilter === 'ALL' ? ' is-active' : '' ?>"
               href="?filter=all"<?= $orderFilter === 'ALL' ? ' aria-current="true"' : '' ?>>
                <span class="orders-filter__icon" aria-hidden="true"><?= hopia_orders_icon('layers') ?></span>
                <span class="orders-filter__label">ALL</span>
            </a>
            <a class="orders-filter<?= $orderFilter === 'IN PROGRESS' ? ' is-active' : '' ?>"
               href="?filter=in-progress"<?= $orderFilter === 'IN PROGRESS' ? ' aria-current="true"' : '' ?>>
                <span class="orders-filter__icon" aria-hidden="true"><?= hopia_orders_icon('clock') ?></span>
                <span class="orders-filter__label">IN PROGRESS</span>
            </a>
            <a class="orders-filter<?= $orderFilter === 'DELIVERED' ? ' is-active' : '' ?>"
               href="?filter=delivered"<?= $orderFilter === 'DELIVERED' ? ' aria-current="true"' : '' ?>>
                <span class="orders-filter__icon" aria-hidden="true"><?= hopia_orders_icon('check-circle') ?></span>
                <span class="orders-filter__label">DELIVERED</span>
            </a>
            <a class="orders-filter<?= $orderFilter === 'CANCELLED' ? ' is-active' : '' ?>"
               href="?filter=cancelled"<?= $orderFilter === 'CANCELLED' ? ' aria-current="true"' : '' ?>>
                <span class="orders-filter__icon" aria-hidden="true"><?= hopia_orders_icon('x-circle') ?></span>
                <span class="orders-filter__label">CANCELLED</span>
            </a>
        </div>
    </nav>

    <?php

    /*
     * Apply the requested display filter to the already-fetched
     * orders. No additional database query required.
     */

    $inProgressStatuses = ['PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED', 'IN_TRANSIT'];

    /*
     * Pickup-aware progress per order (Phase 3). A Store Pickup order is
     * displayed (and filtered) by its pickup lifecycle; courier orders keep
     * the courier statuses. Computed once here with the shared helper.
     */
    $pickupProgressByOrder = [];
    $customerTimelinesByOrder = [];
    foreach ($orders as $orderRow) {
        $progress = pickup_progress($orderRow, $orderRow);
        if ($progress !== null) {
            $pickupProgressByOrder[(int) $orderRow['id']] = $progress;
        } else {
            $customerTimelinesByOrder[(int) $orderRow['id']] = customer_order_timeline($orderRow, $orderRow, $orderRow, null);
        }
    }

    $filteredOrders = array_filter($orders, function ($order) use ($orderFilter, $inProgressStatuses, $pickupProgressByOrder, $customerTimelinesByOrder) {
        if ($orderFilter === 'ALL') {
            return true;
        }
        $pickup = $pickupProgressByOrder[(int) $order['id']] ?? null;
        $cancelled = $order['status'] === 'CANCELLED' || $order['cancellation_status'] === 'APPROVED';
        if ($cancelled) return $orderFilter === 'CANCELLED';
        $timeline = $customerTimelinesByOrder[(int) $order['id']] ?? null;
        if ($orderFilter === 'IN PROGRESS') {
            if ($pickup !== null) {
                return $pickup['stage'] !== 'PICKED_UP' && !$pickup['cancelled'];
            }
            return $timeline['closure'] !== 'RETURNED_TO_SHOP'
                && in_array($timeline['stage'], $inProgressStatuses, true);
        }
        if ($orderFilter === 'DELIVERED') {
            if ($pickup !== null) {
                return $pickup['stage'] === 'PICKED_UP';
            }
            return $timeline['stage'] === 'DELIVERED';
        }
        return false;
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
                $pickupProgress = $pickupProgressByOrder[$orderId] ?? null;
                $orderTimeline = $customerTimelinesByOrder[$orderId] ?? null;
                $isCancelled = $orderStatus === 'CANCELLED' || $cancellationStatus === 'APPROVED';
                $displayStatus = $isCancelled ? 'CANCELLED' : ($orderTimeline['badge_stage'] ?? null);
                /*
                 * Pickup orders display their pickup stage; courier orders keep
                 * the courier label. A cancelled order keeps the Cancelled label
                 * regardless of fulfillment (cancellation is its own terminal
                 * outcome).
                 */
                $statusLabel = $isCancelled
                    ? 'Cancelled'
                    : ($pickupProgress !== null
                        ? $pickupProgress['stage_label']
                        : ($orderStatusLabels[$displayStatus ?? ''] ?? 'Status unavailable'));
                $badgeClass = $isCancelled
                    ? 'badge-cancelled'
                    : ($pickupProgress !== null
                        ? $pickupProgress['badge_class']
                        : ($orderStatusBadgeClasses[$displayStatus ?? ''] ?? 'badge'));
                $statusIconKey = $isCancelled
                    ? 'x'
                    : ($pickupProgress !== null
                        ? pickup_stage_icon($pickupProgress['stage'])
                        : ($orderStatusIconKeys[$displayStatus ?? ''] ?? ''));
                $item = $orderItemsMap[$orderId] ?? null;
                $productName = $item !== null ? (string) $item['product_name'] : 'Order #' . $orderId;
                $viewUrl = 'order-detail.php?id=' . $orderId;
                $receivedAt = $order['received_at'] ?? null;
                $isDelivered = !$isCancelled && $pickupProgress === null
                    && $orderStatus === 'DELIVERED' && $order['shipment_status'] === 'DELIVERED'
                    && in_array($order['delivery_method'], [null, 'LOCAL_COURIER'], true) && $orderTimeline['stage'] === 'DELIVERED';
                $hasConfirmedReceipt = $receivedAt !== null;
                /* Feedback gating requires DELIVERED + confirmed receipt (Phase C). */
                $showFeedbackAction = $isDelivered && $hasConfirmedReceipt;
                $showOrderReceived = $isDelivered && !$hasConfirmedReceipt
                    && in_array($cancellationStatus, ['NONE', 'REJECTED'], true)
                    && ($order['payment_method'] !== 'GCASH' || $order['payment_status'] === 'PAID');
                $feedbackRow = $item !== null ? ($feedbackByItem[(int) $item['order_item_id']] ?? null) : null;
                $feedbackSubmitted = $feedbackRow !== null;
                $feedbackLabel = $feedbackSubmitted ? 'EDIT FEEDBACK' : 'LEAVE FEEDBACK';
                $feedbackMode = $feedbackSubmitted ? 'edit' : 'leave';
                $feedbackRating = $feedbackSubmitted ? (int) $feedbackRow['rating'] : 0;
                $feedbackText = $feedbackSubmitted ? (string) $feedbackRow['feedback_text'] : '';
                $feedbackPhoto = $feedbackSubmitted ? hopia_feedback_photo_src($feedbackRow['photo_path']) : '';
                $feedbackPhotoName = $feedbackSubmitted ? basename((string) $feedbackRow['photo_path']) : '';
                $hasCancellationNote = !$isCancelled && $cancellationStatus !== 'NONE'
                    && isset($cancellationStatusLabels[$cancellationStatus]);

                $metaParts = [];
                if ($item !== null) {
                    $category = strtoupper(trim((string) $item['category']));
                    if ($category !== '') {
                        $metaParts[] = ucfirst(strtolower($category));
                    }
                    $size = trim((string) $item['size']);
                    if ($size !== '') {
                        $metaParts[] = 'Size ' . $size;
                    }
                    $color = trim((string) $item['color']);
                    if ($color !== '') {
                        $metaParts[] = $color;
                    }
                }
                $metaText = implode(' · ', $metaParts);
            ?>
                <li class="order-card">
                    <div class="order-card__head">
                        <p class="order-card__number">ORDER #<?= $orderId ?></p>
                        <div class="order-card__status">
                            <span class="badge <?= hopia_e($badgeClass) ?>">
                                <?php if ($statusIconKey !== ''): ?>
                                    <span class="badge__icon" aria-hidden="true"><?= hopia_orders_icon($statusIconKey) ?></span>
                                <?php endif; ?>
                                <span class="badge__label"><?= hopia_e($statusLabel) ?></span>
                            </span>
                            <?php if ($displayStatus === 'DELIVERED'): ?>
                                <span class="badge <?= $hasConfirmedReceipt ? 'badge-success' : 'badge-warning' ?>"><?= hopia_e($orderTimeline['courier_label']) ?></span>
                            <?php endif; ?>
                            <?php if ($displayStatus === 'IN_TRANSIT' && $orderTimeline['courier_label'] !== null
                                && $orderTimeline['courier_label'] !== 'In Transit'): ?>
                                <span class="order-card__cancel-note"><?= hopia_e($orderTimeline['courier_label']) ?></span>
                            <?php endif; ?>
                            <?php if ($hasCancellationNote): ?>
                                <span class="order-card__cancel-note">
                                    <?= hopia_e($cancellationStatusLabels[$cancellationStatus]) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($pickupProgress !== null && $pickupProgress['stage'] === 'READY_FOR_PICKUP' && $pickupProgress['deadline_at'] !== null && !$isCancelled): ?>
                                <span class="order-card__cancel-note">
                                    Pickup by <?= hopia_e(date('M j, Y g:i A', strtotime($pickupProgress['deadline_at']))) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="order-card__media">
                        <?php if ($item !== null && trim($item['image_path']) !== ''): ?>
                            <img
                                src="<?= hopia_e('../' . ltrim(trim($item['image_path']), '/')) ?>"
                                alt="<?= hopia_e($productName) ?>"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <span class="order-card__media-placeholder" aria-hidden="true">
                                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="order-card__identity">
                        <h2 class="order-card__name"><?= hopia_e($productName) ?></h2>
                        <p class="order-card__date">
                            <?= hopia_e(date('M j, Y', strtotime($order['created_at']))) ?>
                        </p>
                        <?php if ($metaText !== ''): ?>
                            <p class="order-card__meta"><?= hopia_e($metaText) ?></p>
                        <?php endif; ?>
                        <?php if ($orderTimeline !== null && $orderTimeline['closure'] !== null): ?>
                            <p class="order-card__meta"><?= hopia_e($orderTimeline['message']) ?></p>
                        <?php endif; ?>
                        <p class="order-card__price price">
                            ₱<?= number_format((float) $order['total_amount'], 2) ?>
                        </p>
                    </div>

                    <div class="order-card__action">
                        <a class="btn btn-primary btn-sm order-card__view" href="<?= hopia_e($viewUrl) ?>" aria-label="View order: <?= hopia_e($productName) ?>">
                            <span class="order-card__view-label">VIEW ORDER</span>
                            <span class="order-card__view-arrow" aria-hidden="true"><?= hopia_orders_icon('arrow-right') ?></span>
                        </a>
                        <?php if ($showOrderReceived): ?>
                            <a class="btn btn-success btn-sm order-card__confirm-receipt" href="<?= hopia_e($viewUrl) ?>" aria-label="Confirm receipt for order #<?= $orderId ?>">
                                <span>CONFIRM RECEIPT</span>
                            </a>
                        <?php elseif ($showFeedbackAction): ?>
                            <?php
                            $feedbackImagePath = $item !== null ? trim((string) $item['image_path']) : '';
                            $ui_feedback_overlay = true;
                            ?>
                            <button class="btn btn-outline btn-sm order-card__feedback" type="button"
                                data-feedback-open
                                data-feedback-mode="<?= hopia_e($feedbackMode) ?>"
                                data-feedback-order="<?= (int) $orderId ?>"
                                data-feedback-order-item="<?= (int) $item['order_item_id'] ?>"
                                data-feedback-name="<?= hopia_e($productName) ?>"
                                data-feedback-size="<?= hopia_e($item !== null ? (string) $item['size'] : '') ?>"
                                data-feedback-color="<?= hopia_e($item !== null ? (string) $item['color'] : '') ?>"
                                data-feedback-image="<?= hopia_e($feedbackImagePath !== '' ? '../' . ltrim($feedbackImagePath, '/') : '') ?>"
                                <?php if ($feedbackSubmitted): ?>
                                    data-feedback-rating="<?= (int) $feedbackRating ?>"
                                    data-feedback-text="<?= hopia_e($feedbackText) ?>"
                                    data-feedback-photo="<?= hopia_e($feedbackPhoto) ?>"
                                    data-feedback-photo-name="<?= hopia_e($feedbackPhotoName) ?>"
                                <?php endif; ?>>
                                <?= hopia_e($feedbackLabel) ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
