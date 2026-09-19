<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

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

$cancellationStatusLabels = [
    'NONE' => 'No cancellation request',
    'REQUESTED' => 'Cancellation Requested',
    'APPROVED' => 'Cancellation Approved',
    'REJECTED' => 'Cancellation Rejected'
];

$orderStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'CONFIRMED' => 'badge-info',
    'PACKED' => 'badge-warning',
    'SHIPPED' => 'badge-info',
    'DELIVERED' => 'badge-success',
    'CANCELLED' => 'badge-cancelled'
];

$requestedOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$order = false;
$orderItems = [];
$payment = false;
$shipment = false;

if ($requestedOrderId !== null && $requestedOrderId !== false && $requestedOrderId > 0) {
    /*
     * Fetch the order using BOTH the requested order ID and the
     * authenticated customer's ID, so a customer can only ever
     * view their own order.
     */

    $orderStmt = $pdo->prepare(
        'SELECT id, status, cancellation_status, subtotal, shipping_fee, total_amount,
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
            'SELECT oi.product_id, oi.product_name, oi.size, oi.color,
                    oi.unit_price, oi.quantity, oi.subtotal, pi.image_path
             FROM order_items oi
             LEFT JOIN product_images pi
                 ON pi.id = (
                     SELECT pi2.id
                     FROM product_images pi2
                     WHERE pi2.product_id = oi.product_id
                     ORDER BY pi2.sort_order ASC, pi2.id ASC
                     LIMIT 1
                 )
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC'
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

        /*
         * Fetch the shipment for display only. The order is already
         * ownership-verified above, so the shipment lookup is scoped
         * to that same validated $orderId.
         */

        $shipmentStmt = $pdo->prepare(
            'SELECT shipment_status, tracking_number, shipped_at, delivered_at
             FROM shipments
             WHERE order_id = :order_id
             LIMIT 1'
        );

        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch();
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

$shipmentStatusLabel = ($shipment !== false && $shipment['shipment_status'] !== null)
    ? ($shipmentStatusLabels[$shipment['shipment_status']] ?? $shipment['shipment_status'])
    : '';

$trackingNumber = $shipment !== false
    ? trim($shipment['tracking_number'] ?? '')
    : '';

/*
 * The cancellation status shown on the page always comes from
 * the database, never from GET or POST.
 */

$cancellationStatus = $order !== false ? (string) $order['cancellation_status'] : '';

$cancellationStatusLabel = $order !== false
    ? ($cancellationStatusLabels[$cancellationStatus] ?? $cancellationStatus)
    : '';

/*
 * PRG flags from the cancellation request endpoint. The flags
 * only control messaging; they are not treated as proof that a
 * mutation happened.
 */

$cancelRequestedFlag = isset($_GET['cancel_requested']);
$cancelErrorFlag = isset($_GET['cancel_error']);

/*
 * The cancellation request form is only offered while the order
 * is still PENDING and no request exists yet. Informational
 * messages are shown for the other cancellation states.
 */

$showCancelForm = (
    $order !== false
    && $order['status'] === 'PENDING'
    && $cancellationStatus === 'NONE'
);

$showCancelInfo = (
    !$showCancelForm
    && (
        $cancellationStatus === 'REQUESTED'
        || $cancellationStatus === 'REJECTED'
        || $cancellationStatus === 'APPROVED'
        || ($order !== false && $order['status'] === 'CANCELLED')
    )
);

require_once __DIR__ . '/../includes/ui.php';

$page_title = 'Order #' . ($order !== false ? (int) $order['id'] : '') . ' - ' . hopia_site_name();
$page_description = 'Order details at ' . hopia_site_name() . '.';
$ui_active = 'orders.php';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';

?>

<section class="order-detail-page">

    <?php if ($order === false): ?>

        <div class="order-detail-empty">
            <div class="order-detail-empty__icon" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
            </div>
            <h1>Order not found</h1>
            <p>This order does not exist or is not available in your account.</p>
            <a class="btn btn-primary" href="orders.php">VIEW ALL ORDERS</a>
        </div>

    <?php else: ?>

        <nav class="order-detail-breadcrumb" aria-label="Breadcrumb">
            <ol class="order-detail-breadcrumb__list" role="list">
                <li>
                    <a href="orders.php">My Orders</a>
                </li>
                <li aria-hidden="true" class="order-detail-breadcrumb__sep">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </li>
                <li aria-current="page">Order #<?= hopia_e($order['id']) ?></li>
            </ol>
        </nav>

        <?php if ($cancelRequestedFlag): ?>
            <p class="alert alert-success" role="status">
                Your cancellation request has been submitted and is waiting for admin review.
            </p>
        <?php elseif ($cancelErrorFlag): ?>
            <p class="alert alert-error" role="alert">
                The cancellation request could not be submitted. Please review the order and try again.
            </p>
        <?php endif; ?>

        <header class="order-detail-hero">
            <p class="order-detail-hero__eyebrow">Order details</p>
            <h1>Order #<?= hopia_e($order['id']) ?></h1>
            <p class="order-detail-hero__date">
                Placed on <?= hopia_e(date('M j, Y g:i A', strtotime($order['created_at']))) ?>
            </p>

            <div class="order-detail-status">
                <span class="badge <?= hopia_e($orderStatusBadgeClasses[$order['status']] ?? 'badge') ?>">
                    <?= hopia_e($orderStatusLabel) ?>
                </span>
            </div>
        </header>

        <div class="order-detail-layout">
            <div class="order-detail-main">

                <section class="order-detail-card" aria-labelledby="items-heading">
                    <div class="order-detail-card__head">
                        <div>
                            <p class="order-detail-card__eyebrow">Your purchase</p>
                            <h2 id="items-heading">Items</h2>
                        </div>
                    </div>

                    <ul class="order-detail-items" role="list">
                        <?php foreach ($orderItems as $item): ?>
                            <?php
                            $imagePath = trim($item['image_path'] ?? '');
                            $size = trim($item['size'] ?? '');
                            $color = trim($item['color'] ?? '');
                            $metaParts = [];

                            if ($size !== '') {
                                $metaParts[] = 'Size ' . $size;
                            }

                            if ($color !== '') {
                                $metaParts[] = $color;
                            }
                            ?>
                            <li class="order-detail-item">
                                <div class="order-item-thumb">
                                    <?php if ($imagePath !== ''): ?>
                                        <img
                                            src="<?= hopia_e('../' . ltrim($imagePath, '/')) ?>"
                                            alt="<?= hopia_e($item['product_name']) ?>"
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <div class="order-item-thumb__placeholder" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                                <polyline points="21 15 16 10 5 21"></polyline>
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="order-detail-item__info">
                                    <p class="order-detail-item__name"><?= hopia_e($item['product_name']) ?></p>
                                    <?php if (count($metaParts) > 0): ?>
                                        <p class="order-detail-item__meta"><?= hopia_e(implode(' | ', $metaParts)) ?></p>
                                    <?php endif; ?>
                                    <p class="order-detail-item__qty">
                                        Qty <?= (int) $item['quantity'] ?> · &#8369;<?= hopia_e(number_format((float) $item['unit_price'], 2)) ?> each
                                    </p>
                                </div>
                                <p class="price order-detail-item__subtotal">
                                    &#8369;<?= number_format((float) $item['subtotal'], 2) ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="order-detail-card" aria-labelledby="delivery-heading">
                    <div class="order-detail-card__head">
                        <div>
                            <p class="order-detail-card__eyebrow">Where it is going</p>
                            <h2 id="delivery-heading">Delivery Information</h2>
                        </div>
                    </div>

                    <dl class="order-detail-details">
                        <div>
                            <dt>Recipient</dt>
                            <dd><?= hopia_e($order['shipping_name']) ?></dd>
                        </div>
                        <div>
                            <dt>Phone</dt>
                            <dd><?= hopia_e($order['shipping_phone']) ?></dd>
                        </div>
                        <div>
                            <dt>Address</dt>
                            <dd><?= hopia_e($order['shipping_address']) ?></dd>
                        </div>
                    </dl>
                </section>

                <section class="order-detail-card" aria-labelledby="payment-heading">
                    <div class="order-detail-card__head">
                        <div>
                            <p class="order-detail-card__eyebrow">How you will pay</p>
                            <h2 id="payment-heading">Payment</h2>
                        </div>
                    </div>

                    <dl class="order-detail-details">
                        <div>
                            <dt>Payment method</dt>
                            <dd><?= hopia_e($paymentMethodLabel) ?></dd>
                        </div>
                        <div>
                            <dt>Payment status</dt>
                            <dd><span class="badge badge-pending"><?= hopia_e($paymentStatusLabel) ?></span></dd>
                        </div>
                    </dl>

                    <div class="order-detail-totals">
                        <div class="order-detail-total-row">
                            <span>Order total before shipping</span>
                            <span>&#8369;<?= number_format((float) $order['subtotal'], 2) ?></span>
                        </div>
                        <div class="order-detail-total-row order-detail-total-row--muted">
                            <span>Shipping</span>
                            <span>To be confirmed</span>
                        </div>
                        <div class="order-detail-total-row order-detail-total-row--grand">
                            <span>Order total</span>
                            <span>&#8369;<?= number_format((float) $order['total_amount'], 2) ?></span>
                        </div>
                    </div>
                </section>

                <?php if ($shipment !== false || $trackingNumber !== ''): ?>

                    <section class="order-detail-card" aria-labelledby="shipment-heading">
                        <div class="order-detail-card__head">
                            <div>
                                <p class="order-detail-card__eyebrow">Delivery progress</p>
                                <h2 id="shipment-heading">Shipment</h2>
                            </div>
                        </div>

                        <dl class="order-detail-details">
                            <div>
                                <dt>Shipment status</dt>
                                <dd><?= hopia_e($shipmentStatusLabel) ?></dd>
                            </div>
                            <?php if ($trackingNumber !== ''): ?>
                                <div>
                                    <dt>Tracking number</dt>
                                    <dd><?= hopia_e($trackingNumber) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>

                        <?php if ($trackingNumber !== ''): ?>
                            <p class="order-detail-tracking">
                                <a class="btn btn-secondary btn-sm" href="https://www.jtexpress.ph/track-and-trace?waybillNo=<?= rawurlencode($trackingNumber) ?>" target="_blank" rel="noopener noreferrer">
                                    TRACK WITH J&amp;T &#8599;
                                </a>
                            </p>
                        <?php endif; ?>
                    </section>

                <?php endif; ?>

                <?php if ($showCancelForm || $showCancelInfo): ?>

                    <section class="order-detail-card order-detail-cancellation" aria-labelledby="order-actions-heading">
                        <div class="order-detail-card__head">
                            <div>
                                <p class="order-detail-card__eyebrow">Need to make a change?</p>
                                <h2 id="order-actions-heading">Order Actions</h2>
                            </div>
                        </div>

                        <?php if ($showCancelForm): ?>

                            <p>
                                This order is still pending. You may request a cancellation;
                                an administrator will review it before the order is cancelled.
                            </p>

                            <form method="POST" action="order-cancel-request.php"
                                  onsubmit="return confirm('Are you sure you want to request cancellation for this order?');">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">
                                <button class="btn btn-danger" type="submit">REQUEST CANCELLATION</button>
                            </form>

                        <?php elseif ($cancellationStatus === 'REQUESTED'): ?>

                            <p role="status">Your cancellation request is waiting for admin review.</p>

                        <?php elseif ($cancellationStatus === 'REJECTED'): ?>

                            <p role="status">Your cancellation request was rejected.</p>

                        <?php else: ?>

                            <p role="status">Your cancellation request was approved. This order has been cancelled.</p>

                        <?php endif; ?>
                    </section>

                <?php endif; ?>
            </div>

            <aside class="order-detail-aside" aria-label="Order summary">
                <div class="order-detail-aside__inner">
                    <p class="order-detail-aside__label">Order number</p>
                    <p class="order-detail-aside__number">#<?= hopia_e($order['id']) ?></p>
                    <p class="order-detail-aside__status">
                        <span class="badge <?= hopia_e($orderStatusBadgeClasses[$order['status']] ?? 'badge') ?>">
                            <?= hopia_e($orderStatusLabel) ?>
                        </span>
                    </p>
                    <?php if ($trackingNumber !== ''): ?>
                        <a class="btn btn-secondary btn-block" href="https://www.jtexpress.ph/track-and-trace?waybillNo=<?= rawurlencode($trackingNumber) ?>" target="_blank" rel="noopener noreferrer">
                            TRACK WITH J&amp;T &#8599;
                        </a>
                    <?php endif; ?>
                    <a class="btn btn-primary btn-block" href="orders.php">VIEW ALL ORDERS</a>
                    <a class="btn btn-secondary btn-block" href="products.php">CONTINUE SHOPPING</a>
                </div>
            </aside>
        </div>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
