<?php

session_start();
$orderLiveRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['order_live'] ?? '') === '1';

if (!isset($_SESSION['admin_id'])) {
    if ($orderLiveRequest) { http_response_code(401); header('Cache-Control: no-store'); exit; }
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';

require_once __DIR__ . '/../includes/order_verify.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/order_queues.php';
require_once __DIR__ . '/../includes/admin_delivery.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/admin_order_ui.php';

/*
 * This page is the Local Courier order list. Store Pickup has its own page
 * (pickups.php), and its orders stay at orders.status = PENDING for their
 * whole pickup lifecycle, so they MUST be excluded here by the persisted
 * fulfillment method rather than by status.
 *
 * Filters are allowlisted. An unknown queue or status is ignored (never
 * interpolated into SQL), which falls back to the unfiltered Local Courier
 * list. A legacy NULL delivery_method is treated as LOCAL_COURIER, matching
 * pickup_is_pickup_order().
 */
$orderListFilters = order_list_filter_definitions('orders');

/* This page is Local Courier only. The parameter is allowlisted, and the
   value is still bound (never interpolated) in the query below. */
$allowedFulfillments = ['LOCAL_COURIER'];
$requestedFulfillment = isset($_GET['fulfillment']) && is_string($_GET['fulfillment'])
    ? $_GET['fulfillment']
    : '';
$fulfillmentFilter = in_array($requestedFulfillment, $allowedFulfillments, true)
    ? $requestedFulfillment
    : 'LOCAL_COURIER';

$requestedQueue = isset($_GET['queue']) && is_string($_GET['queue']) ? $_GET['queue'] : '';
$queueFilter = isset($orderListFilters[$requestedQueue]) && $requestedQueue !== 'all'
    ? $requestedQueue
    : null;
$isCodCollectionQueue = $queueFilter === 'online_cod_collection';
$isGcashCompletionQueue = $queueFilter === 'online_gcash_completion';

$allowedOrderStatuses = ['PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED', 'IN_TRANSIT', 'RETURNS', 'COMPLETED', 'DELIVERED', 'CANCELLED'];
$requestedStatus = isset($_GET['status']) && is_string($_GET['status'])
    ? strtoupper(trim($_GET['status']))
    : '';
$statusFilter = in_array($requestedStatus, $allowedOrderStatuses, true) ? $requestedStatus : null;
$statusTabs = ['PENDING' => 'online_pending', 'CONFIRMED' => 'online_confirmed', 'PACKED' => 'online_packed',
    'SHIPPED' => 'online_shipped', 'IN_TRANSIT' => 'online_in_transit', 'RETURNS' => 'online_returns', 'COMPLETED' => 'online_completed'];
if ($queueFilter === null && isset($statusTabs[$statusFilter ?? ''])) {
    $queueFilter = $statusTabs[$statusFilter];
    $statusFilter = null;
}

$ordersWhere = ['(s.delivery_method IS NULL OR s.delivery_method = :fulfillment)'];
$ordersParams = [':fulfillment' => $fulfillmentFilter];
$orderSearch = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
if ($orderSearch !== '') {
    $ordersWhere[] = '(CAST(o.id AS CHAR) LIKE :search_order OR CONCAT_WS(\' \', c.first_name, c.last_name) LIKE :search_customer)';
    $ordersParams[':search_order'] = $ordersParams[':search_customer'] = sales_monitor_like_param($orderSearch);
}
$ordersBaseWhere = $ordersWhere;
$ordersBaseParams = $ordersParams;
$activeListFilterDefinition = null;

if ($queueFilter !== null) {
    $filterWhere = order_list_filter_where('orders', $queueFilter);

    if ($filterWhere !== null) {
        $ordersWhere[] = '(' . $filterWhere['sql'] . ')';
        $ordersParams = array_merge($ordersParams, $filterWhere['params']);
    }

    $activeListFilterDefinition = $orderListFilters[$queueFilter] ?? null;
} elseif ($statusFilter !== null) {
    $ordersWhere[] = 'o.status = :status';
    $ordersParams[':status'] = $statusFilter;
    if ($statusFilter === 'DELIVERED') $ordersWhere[] = "s.shipment_status = 'DELIVERED'";
}

$orderFrom = ' FROM orders o JOIN customers c ON c.id = o.customer_id
    LEFT JOIN shipments s ON s.order_id = o.id LEFT JOIN payments p ON p.order_id = o.id';
$tabCountColumns = ['COUNT(*) AS all_count'];
foreach ($orderListFilters as $key => $definition) {
    $where = order_list_filter_where('orders', $key);
    if ($where !== null) $tabCountColumns[] = 'SUM(CASE WHEN (' . $where['sql'] . ') THEN 1 ELSE 0 END) AS ' . $key;
}
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->exec('SET TRANSACTION READ ONLY');
$pdo->beginTransaction();
$countsStmt = $pdo->prepare('SELECT ' . implode(', ', $tabCountColumns) . $orderFrom
    . ' WHERE ' . implode(' AND ', $ordersBaseWhere));
$countsStmt->execute($ordersBaseParams);
$orderTabCounts = $countsStmt->fetch();
$orderTabCounts['all'] = (int) $orderTabCounts['all_count'];
if ($queueFilter !== null) {
    $orderTotal = (int) ($orderTabCounts[$queueFilter] ?? 0);
} elseif ($statusFilter === null) {
    $orderTotal = $orderTabCounts['all'];
} else {
    $totalStmt = $pdo->prepare('SELECT COUNT(*)' . $orderFrom . ' WHERE ' . implode(' AND ', $ordersWhere));
    $totalStmt->execute($ordersParams);
    $orderTotal = (int) $totalStmt->fetchColumn();
}
$perPage = 24;
$totalPages = max(1, (int) ceil($orderTotal / $perPage));
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = min($totalPages, $requestedPage === false ? 1 : $requestedPage);
$offset = ($page - 1) * $perPage;
$orderPageUrl = static function (int $number) use ($queueFilter, $statusFilter, $orderSearch): string {
    return 'orders.php?' . http_build_query(array_filter(['queue' => $queueFilter, 'status' => $statusFilter,
        'q' => $orderSearch, 'page' => $number], static fn ($value) => $value !== null && $value !== ''));
};
$ordersStmt = $pdo->prepare(
    'SELECT o.id,
            o.status,
            o.cancellation_status,
            o.subtotal,
            o.shipping_fee,
            o.total_amount,
            o.created_at,
            o.received_at,
            EXISTS(SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id AND st.sale_channel = \'ONLINE\') AS has_completed_sale,
            s.delivery_method,
            s.shipment_status,
            s.delivery_cycle, s.delivery_revision, s.failed_attempts,
            CONCAT(c.first_name, \' \', c.last_name) AS customer_name,
            p.payment_method,
            p.payment_status,
            p.gcash_reference,
            p.submitted_at
     FROM orders o
     JOIN customers c ON c.id = o.customer_id
     LEFT JOIN shipments s ON s.order_id = o.id
     LEFT JOIN payments p ON p.order_id = o.id
     WHERE ' . implode(' AND ', $ordersWhere) . '
     ORDER BY o.created_at DESC, o.id DESC LIMIT :limit OFFSET :offset'
);

foreach ($ordersParams as $key => $value) $ordersStmt->bindValue($key, $value);
$ordersStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$ordersStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$ordersStmt->execute();

$orders = $ordersStmt->fetchAll();
$orderListDetails = admin_order_list_details($pdo, array_column($orders, 'id'), true);
$pdo->commit();

/*
 * Friendly labels. Database values remain unchanged.
 */
$orderStatusLabels = [
    'PENDING' => 'Pending',
    'CONFIRMED' => 'Confirmed',
    'PACKED' => 'Packed',
    'SHIPPED' => 'Shipped',
    'IN_TRANSIT' => 'In Transit',
    'DELIVERED' => 'Delivered',
    'COMPLETED' => 'Completed',
    'CANCELLED' => 'Cancelled',
];

$cancellationStatusLabels = [
    'NONE' => 'None',
    'REQUESTED' => 'Cancellation Requested',
    'APPROVED' => 'Cancellation Approved',
    'REJECTED' => 'Cancellation Rejected',
];

$paymentMethodLabels = [
    'COD' => 'Cash on Delivery',
    'GCASH' => 'GCash',
    'CARD' => 'Card',
    'CASH_ON_PICKUP' => 'Cash on Pickup',
];

$hasActiveOrderFilter = $queueFilter !== null || $statusFilter !== null || $orderSearch !== '';
$activeOrderFilterLabel = 'Local Courier orders';
if ($activeListFilterDefinition !== null) {
    $activeOrderFilterLabel = (string) $activeListFilterDefinition['label'];
} elseif ($statusFilter !== null) {
    $activeOrderFilterLabel = match ($statusFilter) {
        'DELIVERED' => 'delivery records (Delivered / Completed)',
        default => 'status ' . ($orderStatusLabels[$statusFilter] ?? $statusFilter),
    };
}

/* The selected tab. A direct ?status= link (kept for compatibility) has no
   matching tab, so no tab is highlighted in that case. */
$activeListFilterKey = $queueFilter;
if ($activeListFilterKey === null && $statusFilter === null) {
    $activeListFilterKey = 'all';
}

/*
 * Phase B: a small verification-progress indicator for orders that are
 * eligible for verification (CONFIRMED/PACKED, no pending/approved
 * cancellation). Progress is read through the shared order_verify_progress()
 * helper so the count rule matches the shipment gate exactly, and only for
 * eligible orders so the list stays light. This is a display hint only; the
 * sole shipment enforcement point remains the PACKED -> SHIPPED transition in
 * admin/order-view.php.
 */
$verifyProgressByOrder = [];
foreach ($orders as $orderRow) {
    if (order_is_verifiable((string) $orderRow['status'], (string) $orderRow['cancellation_status'])) {
        $verifyProgressByOrder[(int) $orderRow['id']] = order_verify_progress($pdo, (int) $orderRow['id']);
    }
}

/*
 * Phase 2: a GCash review-state chip so an admin can spot, from the list,
 * which GCash orders are awaiting manual verification. Derived from the same
 * payments columns as the detail page (no extra status column), so the list
 * and the detail view can never disagree.
 */
$gcashStateByOrder = [];
foreach ($orders as $orderRow) {
    if (($orderRow['payment_method'] ?? null) === 'GCASH') {
        $gcashStateByOrder[(int) $orderRow['id']] = payment_gcash_state([
            'payment_method' => 'GCASH',
            'payment_status' => $orderRow['payment_status'] ?? 'PENDING',
            'gcash_reference' => $orderRow['gcash_reference'] ?? null,
            'submitted_at' => $orderRow['submitted_at'] ?? null,
        ]);
    }
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

$orderStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'CONFIRMED' => 'badge-info',
    'PACKED' => 'badge-warning',
    'SHIPPED' => 'badge-info',
    'IN_TRANSIT' => 'badge-info',
    'DELIVERED' => 'badge-success',
    'COMPLETED' => 'badge-success',
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
$ui_order_live = true;
$ui_admin_order_list = true;
$orderLiveVersion = hash('sha256', serialize([$orders, $orderTabCounts, $verifyProgressByOrder, $gcashStateByOrder, $orderListDetails]));

if ($orderLiveRequest) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
} else {
    require __DIR__ . '/../includes/ui.head.php';
    require __DIR__ . '/../includes/ui.header.php';
}
?>

<div class="admin-orders om-page" data-order-live data-version="<?= htmlspecialchars($orderLiveVersion, ENT_QUOTES, 'UTF-8') ?>">
    <header class="admin-page-header">
        <div>
            <p class="admin-page-header__eyebrow">Order management</p>
            <h1>Online Orders</h1>
            <p class="admin-page-header__copy">
                Welcome, <?= htmlspecialchars($adminName) ?>. Review and manage Local Courier orders.
                Store Pickup orders are managed on the Store Pickup page.
            </p>
        </div>
    </header>

    <form method="GET" action="orders.php" class="om-search">
        <?php if ($queueFilter !== null): ?><input type="hidden" name="queue" value="<?= htmlspecialchars($queueFilter, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <?php if ($statusFilter !== null): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <div class="field"><label for="order-search">Search order number or customer</label>
            <input id="order-search" name="q" type="search" maxlength="120" value="<?= htmlspecialchars($orderSearch, ENT_QUOTES, 'UTF-8') ?>"></div>
        <button class="btn btn-secondary btn-sm" type="submit">Search Orders</button>
    </form>

    <div data-order-live-region="filters">
        <?php admin_order_filter_nav('orders', $orderListFilters, $activeListFilterKey, $orderSearch, $orderTabCounts,
            $queueFilter === null && $statusFilter !== null ? ['name' => 'status', 'value' => $statusFilter, 'label' => $orderStatusLabels[$statusFilter] ?? $statusFilter] : null); ?>
    </div>

    <div data-order-live-region="orders">
    <?php if (count($orders) === 0): ?>

        <div class="empty-state">
            <p>
                <?= htmlspecialchars($orderSearch !== '' ? 'No orders match this search in the selected tab.'
                    : ($activeListFilterDefinition['empty'] ?? ($hasActiveOrderFilter
                        ? 'No Local Courier orders match this filter.' : 'No Local Courier orders have been placed yet.')), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php if ($hasActiveOrderFilter): ?>
                <p><a href="orders.php">Show all Local Courier orders</a></p>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div class="admin-orders__summary">
            <p class="text-small muted">
                Showing <?= $offset + 1 ?>-<?= $offset + count($orders) ?> of <?= $orderTotal ?> Local Courier order<?= $orderTotal === 1 ? '' : 's' ?><?php if ($hasActiveOrderFilter): ?>
                    &mdash; <?= htmlspecialchars($activeOrderFilterLabel, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>,
                newest first.
                <?php if ($hasActiveOrderFilter): ?>
                    &middot; <a href="orders.php">Clear filter</a>
                <?php endif; ?>
            </p>
            <?php if ($isCodCollectionQueue): ?>
                <p class="text-small muted">These orders are delivered and customer receipt is confirmed. COD collection and sale finalization are still pending; shipping is shown separately from merchandise revenue.</p>
            <?php endif; ?>
            <?php if ($isGcashCompletionQueue): ?>
                <p class="text-small muted">These GCash orders are paid and verified, delivered, and customer receipt is confirmed. Sale completion is pending; shipping is shown separately from merchandise revenue.</p>
            <?php endif; ?>
        </div>

        <?php $desktopOrderRows = []; ?>
        <div class="admin-order-list om-cards" data-order-card-section="online-orders">
            <?php foreach ($orders as $order): ?>
                <?php
                $orderProgress = admin_delivery_progress($order, isset($order['payment_method']) ? $order : null,
                    isset($order['shipment_status']) ? $order : null);
                $orderStatus = $orderProgress['stage'] ?? '';
                $cancellationStatus = (string) $order['cancellation_status'];
                $orderStatusBadgeClass = $orderStatusBadgeClasses[$orderStatus] ?? 'badge';
                $cancellationStatusBadgeClass = $cancellationStatusBadgeClasses[$cancellationStatus] ?? 'badge';
                $orderStatusLabel = $orderStatusLabels[$orderStatus] ?? 'Status Under Review';
                if ($orderStatus !== 'CANCELLED'
                    && in_array($order['shipment_status'] ?? '', ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'], true)) {
                    $orderStatusLabel = admin_delivery_labels()[$order['shipment_status']];
                    $orderStatusBadgeClass = admin_delivery_badge($order['shipment_status']);
                }
                $cancellationStatusLabel = $cancellationStatusLabels[$cancellationStatus] ?? $cancellationStatus;
                $paymentMethodLabel = isset($order['payment_method'])
                    ? ($paymentMethodLabels[$order['payment_method']] ?? $order['payment_method'])
                    : 'No payment record';
                $listDetails = $orderListDetails[(int) $order['id']] ?? [];
                if ($order['status'] === 'SHIPPED' && in_array($order['shipment_status'] ?? '', ['OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED'], true)) {
                    $orderStatusLabel = admin_delivery_labels()[$order['shipment_status']];
                    $orderStatusBadgeClass = admin_delivery_badge($order['shipment_status']);
                }
                // Customer receipt completes delivery; sale/payment finalization stays independent.
                if ($order['status'] === 'DELIVERED' && ($order['shipment_status'] ?? '') === 'DELIVERED'
                    && !empty($order['received_at']) && in_array($cancellationStatus, ['NONE', 'REJECTED'], true)) {
                    $orderStatusLabel = 'Completed';
                    $orderStatusBadgeClass = 'badge-success';
                }
                ?>

                <article class="admin-order-card om-card">
                    <div class="admin-order-card__header">
                        <div>
                            <p class="admin-order-card__eyebrow">Order</p>
                            <h2>#<?= htmlspecialchars((string) $order['id'], ENT_QUOTES, 'UTF-8') ?></h2>
                        </div>

                        <?php ob_start(); ?>
                        <div class="admin-order-card__statuses">
                            <span class="badge">Local Courier</span>
                            <span class="badge <?= htmlspecialchars($orderStatusBadgeClass, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($orderStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="badge <?= htmlspecialchars($cancellationStatusBadgeClass, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($cancellationStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if (isset($verifyProgressByOrder[(int) $order['id']])): ?>
                                <?php $vp = $verifyProgressByOrder[(int) $order['id']]; ?>
                                <span class="badge <?= $vp['complete'] ? 'badge-success' : 'badge-pending' ?>">
                                    Verified <?= (int) $vp['verified'] ?>/<?= (int) $vp['expected'] ?>
                                </span>
                            <?php endif; ?>
                            <?php if (isset($gcashStateByOrder[(int) $order['id']])): ?>
                                <?php $gstate = $gcashStateByOrder[(int) $order['id']]; ?>
                                <span class="badge <?= htmlspecialchars(payment_gcash_state_badge($gstate), ENT_QUOTES, 'UTF-8') ?>">
                                    GCash: <?= htmlspecialchars(payment_gcash_state_label($gstate), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($isCodCollectionQueue): ?>
                                <span class="badge badge-pending">Collection &amp; sale pending</span>
                            <?php endif; ?>
                            <?php if ($isGcashCompletionQueue): ?>
                                <span class="badge badge-pending">Sale completion pending</span>
                            <?php endif; ?>
                            <?php if ($orderStatusLabel === 'Completed' && $orderProgress['stage'] !== 'COMPLETED' && !$isCodCollectionQueue && !$isGcashCompletionQueue): ?>
                                <span class="badge badge-pending">Sale finalization pending</span>
                            <?php endif; ?>
                        </div>
                        <?php $listStatusHtml = ob_get_clean(); echo $listStatusHtml; ?>
                    </div>

                    <div class="om-card-summary">
                        <strong class="om-card-summary__customer"><?= hopia_e($order['customer_name']) ?></strong>
                        <strong class="om-card-summary__total">&#8369;<?= number_format((float) $order['total_amount'], 2) ?></strong>
                        <span class="om-card-summary__meta"><?= hopia_e(date('M j, Y g:i A', strtotime($order['created_at']))) ?></span>
                        <span class="om-card-summary__meta"><?= (int) ($listDetails['item_count'] ?? 0) ?> items</span>
                        <span class="om-card-summary__payment"><?= hopia_e($paymentMethodLabel) ?> &middot; <?= hopia_e(ucfirst(strtolower($order['payment_status'] ?? 'No payment record'))) ?></span>
                    </div>
                    <button class="om-card-toggle" id="order-toggle-<?= (int) $order['id'] ?>" type="button" data-order-expand data-order-id="<?= (int) $order['id'] ?>" aria-expanded="false" aria-controls="order-details-<?= (int) $order['id'] ?>" aria-label="Expand details for order #<?= (int) $order['id'] ?>">
                        <span data-order-toggle-label>Show Details</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="om-card-details" id="order-details-<?= (int) $order['id'] ?>" hidden>
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
                            <dt>Payment method</dt>
                            <dd><?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Cancellation</dt>
                            <dd><?= htmlspecialchars($cancellationStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Shipment status</dt>
                                    <dd><span class="badge <?= htmlspecialchars(admin_delivery_badge((string) ($order['shipment_status'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($orderProgress['courier_label'] ?? admin_delivery_labels()[$order['shipment_status'] ?? ''] ?? 'No shipment record', ENT_QUOTES, 'UTF-8') ?></span></dd>
                        </div>
                        <?php if ($isCodCollectionQueue): ?>
                            <div>
                                <dt>Merchandise subtotal</dt>
                                <dd>&#8369;<?= number_format((float) $order['subtotal'], 2) ?></dd>
                            </div>
                            <div>
                                <dt>Shipping fee (reference)</dt>
                                <dd>&#8369;<?= number_format((float) $order['shipping_fee'], 2) ?></dd>
                            </div>
                            <div>
                                <dt>Customer receipt confirmed</dt>
                                <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['received_at'])), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($isGcashCompletionQueue): ?>
                            <div>
                                <dt>Merchandise subtotal</dt>
                                <dd>&#8369;<?= number_format((float) $order['subtotal'], 2) ?></dd>
                            </div>
                            <div>
                                <dt>Shipping fee (reference)</dt>
                                <dd>&#8369;<?= number_format((float) $order['shipping_fee'], 2) ?></dd>
                            </div>
                            <div>
                                <dt>Customer receipt confirmed</dt>
                                <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['received_at'])), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt>Payment verified</dt>
                                <dd>GCash &middot; Paid</dd>
                            </div>
                        <?php endif; ?>
                    </dl>

                    <?php admin_order_expanded_details($order, $listDetails); ?>
                    <?php ob_start(); ?>
                    <div class="admin-order-card__actions">
                        <a class="btn btn-primary btn-sm" href="order-view.php?id=<?= (int) $order['id'] ?>">
                            <?= $isCodCollectionQueue ? 'Review COD Collection' : ($isGcashCompletionQueue ? 'Finalize GCash Sale' : 'View Order') ?>
                        </a>
                        <?php if ($order['status'] === 'SHIPPED' && !$isCodCollectionQueue && !$isGcashCompletionQueue): ?>
                            <a class="btn btn-secondary btn-sm" href="order-view.php?id=<?= (int) $order['id'] ?>#courier-workflow">Courier Delivery</a>
                        <?php endif; ?>
                    </div>
                    <?php $listActionsHtml = ob_get_clean(); echo $listActionsHtml; ?>
                    </div>
                </article>
                <?php $desktopOrderRows[] = ['order' => $order, 'item_count' => $listDetails['item_count'] ?? 0,
                    'statuses' => $listStatusHtml, 'payment_method' => $paymentMethodLabel, 'actions' => $listActionsHtml]; ?>
            <?php endforeach; ?>
        </div>
        <?php admin_order_desktop_table($desktopOrderRows); ?>

        <?php if ($totalPages > 1): ?>
            <nav class="inv-pagination" aria-label="Order list pagination">
                <div class="inv-pagination__pages">
                    <?php if ($page > 1): ?><a class="inv-pagination__btn" rel="prev" href="<?= htmlspecialchars($orderPageUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php endif; ?>
                    <span class="inv-pagination__num is-current" aria-current="page">Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?><a class="inv-pagination__btn" rel="next" href="<?= htmlspecialchars($orderPageUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a><?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

    <?php endif; ?>
    </div>
</div>

<?php if ($orderLiveRequest) exit; ?>
<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
