<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/feedback-data.php';

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

/*
 * Display-only maps for the order progress timeline and the
 * semantic badge styling. No database values are changed.
 */

$orderWorkflow = ['PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED'];

$orderStepIcons = [
    'PENDING' => 'clock',
    'CONFIRMED' => 'check',
    'PACKED' => 'package',
    'SHIPPED' => 'truck',
    'DELIVERED' => 'check-circle'
];

$paymentStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'PAID' => 'badge-success',
    'FAILED' => 'badge-danger',
    'REFUNDED' => 'badge-info'
];

$shipmentStatusBadgeClasses = [
    'NOT_SHIPPED' => 'badge',
    'READY_TO_SHIP' => 'badge-warning',
    'SHIPPED' => 'badge-info',
    'IN_TRANSIT' => 'badge-info',
    'OUT_FOR_DELIVERY' => 'badge-warning',
    'DELIVERED' => 'badge-success'
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
            'SELECT oi.id AS order_item_id, oi.product_id, oi.product_name, oi.size, oi.color,
                    oi.unit_price, oi.quantity, oi.subtotal, pi.image_path, p.category
             FROM order_items oi
             LEFT JOIN product_images pi
                 ON pi.id = (
                     SELECT pi2.id
                     FROM product_images pi2
                     WHERE pi2.product_id = oi.product_id
                     ORDER BY pi2.sort_order ASC, pi2.id ASC
                     LIMIT 1
                 )
             LEFT JOIN products p
                 ON p.id = oi.product_id
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

        /*
         * Feedback lookup for the LEAVE FEEDBACK / EDIT FEEDBACK decision.
         * One read-only query covers every item of this order, keyed by
         * order_item_id, so the aside action can tell whether this customer
         * already submitted feedback for the item it refers to.
         */

        $feedbackByItem = hopia_feedback_fetch_customer_item_map(
            $pdo,
            $customerId,
            array_map(function ($item) {
                return (int) $item['order_item_id'];
            }, $orderItems)
        );
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
 * Display-only: total purchased quantity for the item count label, and
 * the order's position in the real workflow for the progress timeline.
 */

$itemCount = 0;
foreach ($orderItems as $item) {
    $itemCount += (int) $item['quantity'];
}

$itemCountLabel = $itemCount === 1 ? '1 ITEM' : $itemCount . ' ITEMS';

$currentStepIndex = $order !== false
    ? array_search($order['status'], $orderWorkflow, true)
    : false;

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

/*
 * Feedback entry point for delivered orders. The aside action refers to
 * the first item of the order; the lookup above decides between
 * LEAVE FEEDBACK and EDIT FEEDBACK and supplies the edit prefill.
 */

$showFeedbackAction = $order !== false && $order['status'] === 'DELIVERED';
$feedbackItem = $showFeedbackAction ? ($orderItems[0] ?? null) : null;
$feedbackRow = $feedbackItem !== null
    ? ($feedbackByItem[(int) $feedbackItem['order_item_id']] ?? null)
    : null;
$feedbackSubmitted = $feedbackRow !== null;
$feedbackLabel = $feedbackSubmitted ? 'EDIT FEEDBACK' : 'LEAVE FEEDBACK';
$feedbackMode = $feedbackSubmitted ? 'edit' : 'leave';
$feedbackRating = $feedbackSubmitted ? (int) $feedbackRow['rating'] : 0;
$feedbackText = $feedbackSubmitted ? (string) $feedbackRow['feedback_text'] : '';
$feedbackPhoto = $feedbackSubmitted ? hopia_feedback_photo_src($feedbackRow['photo_path']) : '';
$feedbackPhotoName = $feedbackSubmitted ? basename((string) $feedbackRow['photo_path']) : '';

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
            <p class="order-detail-hero__meta">
                <span>
                    Placed on <?= hopia_e(date('M j, Y g:i A', strtotime($order['created_at']))) ?>
                </span>
                <span class="badge <?= hopia_e($orderStatusBadgeClasses[$order['status']] ?? 'badge') ?>">
                    <?= hopia_e($orderStatusLabel) ?>
                </span>
            </p>
        </header>

        <section class="order-detail-card order-detail-card--progress" aria-labelledby="order-status-heading">
            <div class="order-detail-card__head">
                <div class="order-detail-card__label">
                    <?= hopia_order_icon('status') ?>
                    <h2 id="order-status-heading" class="order-detail-card__title">Order status</h2>
                </div>
            </div>

            <?php if ($order['status'] === 'CANCELLED'): ?>

                <div class="od-cancelled">
                    <span class="od-cancelled__icon" aria-hidden="true"><?= hopia_order_icon('x-circle') ?></span>
                    <div>
                        <p class="od-cancelled__title">Cancelled</p>
                        <p>This order has been cancelled.</p>
                    </div>
                </div>

            <?php else: ?>

                <ol class="od-progress" role="list">
                    <?php foreach ($orderWorkflow as $stepIndex => $stepStatus): ?>
                        <?php
                        $stepState = '';
                        if ($currentStepIndex !== false && $stepIndex < $currentStepIndex) {
                            $stepState = ' is-complete';
                        } elseif ($currentStepIndex !== false && $stepIndex === $currentStepIndex) {
                            $stepState = ' is-current';
                        }
                        ?>
                        <li class="od-progress__step<?= hopia_e($stepState) ?>">
                            <span class="od-progress__node" aria-hidden="true">
                                <?= hopia_order_icon($orderStepIcons[$stepStatus] ?? 'package') ?>
                            </span>
                            <span class="od-progress__label"><?= hopia_e($stepStatus) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>

            <?php endif; ?>
        </section>

        <div class="order-detail-layout">
            <div class="order-detail-main">

                <section class="order-detail-card order-detail-card--purchase" aria-labelledby="items-heading">
                    <div class="order-detail-card__head">
                        <div class="order-detail-card__label">
                            <?= hopia_order_icon('purchase') ?>
                            <h2 id="items-heading" class="order-detail-card__title">Your purchase</h2>
                        </div>
                        <p class="order-detail-card__count"><?= hopia_e($itemCountLabel) ?></p>
                    </div>

                    <ul class="order-detail-items" role="list">
                        <?php foreach ($orderItems as $item): ?>
                            <?php
                            $imagePath = trim($item['image_path'] ?? '');
                            $size = trim($item['size'] ?? '');
                            $color = trim($item['color'] ?? '');
                            $category = strtoupper(trim((string) ($item['category'] ?? '')));
                            $metaParts = [];

                            if ($category !== '') {
                                $metaParts[] = ucfirst(strtolower($category));
                            }

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
                                        <p class="order-detail-item__meta"><?= hopia_e(implode(' · ', $metaParts)) ?></p>
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
            </div>

            <div class="order-detail-rail">

                <section class="order-detail-card order-detail-card--delivery" aria-labelledby="delivery-heading">
                    <div class="order-detail-card__head">
                        <div class="order-detail-card__heading">
                            <div class="order-detail-card__label">
                                <?= hopia_order_icon('location') ?>
                                <h2 id="delivery-heading" class="order-detail-card__title">Where it is going</h2>
                            </div>
                            <p class="order-detail-card__sub">Delivery information</p>
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

                <section class="order-detail-card order-detail-card--payment" aria-labelledby="payment-heading">
                    <div class="order-detail-card__head">
                        <div class="order-detail-card__heading">
                            <div class="order-detail-card__label">
                                <?= hopia_order_icon('payment') ?>
                                <h2 id="payment-heading" class="order-detail-card__title">How you will pay</h2>
                            </div>
                            <p class="order-detail-card__sub">Payment</p>
                        </div>
                    </div>

                    <dl class="order-detail-details">
                        <div>
                            <dt>Payment method</dt>
                            <dd><?= hopia_e($paymentMethodLabel) ?></dd>
                        </div>
                        <div>
                            <dt>Payment status</dt>
                            <dd><span class="badge <?= hopia_e($payment !== false ? ($paymentStatusBadgeClasses[$payment['payment_status']] ?? 'badge-pending') : 'badge-pending') ?>"><?= hopia_e($paymentStatusLabel) ?></span></dd>
                        </div>
                    </dl>
                </section>

                <?php if ($shipment !== false || $trackingNumber !== ''): ?>

                    <section class="order-detail-card order-detail-card--shipment" aria-labelledby="shipment-heading">
                        <div class="order-detail-card__head">
                            <div class="order-detail-card__heading">
                                <div class="order-detail-card__label">
                                    <?= hopia_order_icon('truck') ?>
                                    <h2 id="shipment-heading" class="order-detail-card__title">Delivery progress</h2>
                                </div>
                                <p class="order-detail-card__sub">Shipment</p>
                            </div>
                        </div>

                        <dl class="order-detail-details">
                            <div>
                                <dt>Shipment</dt>
                                <dd><span class="badge <?= hopia_e($shipment !== false ? ($shipmentStatusBadgeClasses[$shipment['shipment_status']] ?? 'badge') : 'badge') ?>"><?= hopia_e($shipmentStatusLabel) ?></span></dd>
                            </div>
                            <?php if ($trackingNumber !== ''): ?>
                                <div>
                                    <dt>Tracking number</dt>
                                    <dd class="od-tracking-number"><?= hopia_e($trackingNumber) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>

                        <?php if ($trackingNumber !== ''): ?>
                            <p class="order-detail-tracking">
                                <a class="btn btn-outline btn-sm" href="https://www.jtexpress.ph/track-and-trace?waybillNo=<?= rawurlencode($trackingNumber) ?>" target="_blank" rel="noopener noreferrer" aria-label="Track with J&amp;T (opens in a new tab)">
                                    Track with J&amp;T
                                    <svg class="order-detail-action__icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                        <polyline points="15 3 21 3 21 9"></polyline>
                                        <line x1="10" y1="14" x2="21" y2="3"></line>
                                    </svg>
                                </a>
                            </p>
                        <?php endif; ?>
                    </section>

                <?php endif; ?>

                <section class="order-detail-card order-detail-card--footer" aria-labelledby="order-next-heading">
                    <div class="order-detail-card__head">
                        <div class="order-detail-card__heading">
                            <div class="order-detail-card__label">
                                <?= hopia_order_icon('actions') ?>
                                <h2 id="order-next-heading" class="order-detail-card__title">What&rsquo;s next?</h2>
                            </div>
                            <p class="order-detail-card__sub">Order actions</p>
                        </div>
                    </div>

                    <div class="order-detail-actions">
                        <?php if ($showFeedbackAction): ?>
                            <?php
                            $feedbackName = $feedbackItem !== null
                                ? (string) $feedbackItem['product_name']
                                : 'Order #' . (int) $order['id'];
                            $feedbackSize = $feedbackItem !== null ? trim((string) $feedbackItem['size']) : '';
                            $feedbackColor = $feedbackItem !== null ? trim((string) $feedbackItem['color']) : '';
                            $feedbackImage = $feedbackItem !== null ? trim((string) ($feedbackItem['image_path'] ?? '')) : '';
                            $feedbackItemId = $feedbackItem !== null ? (int) $feedbackItem['order_item_id'] : 0;
                            $ui_feedback_overlay = true;
                            ?>
                            <button class="btn btn-outline btn-block" type="button"
                                data-feedback-open
                                data-feedback-mode="<?= hopia_e($feedbackMode) ?>"
                                data-feedback-order="<?= (int) $order['id'] ?>"
                                data-feedback-order-item="<?= $feedbackItemId ?>"
                                data-feedback-name="<?= hopia_e($feedbackName) ?>"
                                data-feedback-size="<?= hopia_e($feedbackSize) ?>"
                                data-feedback-color="<?= hopia_e($feedbackColor) ?>"
                                data-feedback-image="<?= hopia_e($feedbackImage !== '' ? '../' . ltrim($feedbackImage, '/') : '') ?>"
                                <?php if ($feedbackSubmitted): ?>
                                    data-feedback-rating="<?= (int) $feedbackRating ?>"
                                    data-feedback-text="<?= hopia_e($feedbackText) ?>"
                                    data-feedback-photo="<?= hopia_e($feedbackPhoto) ?>"
                                    data-feedback-photo-name="<?= hopia_e($feedbackPhotoName) ?>"
                                <?php endif; ?>>
                                <?= hopia_e($feedbackLabel) ?>
                            </button>
                        <?php endif; ?>
                        <a class="btn btn-primary btn-block" href="orders.php">
                            <span>VIEW ALL ORDERS</span>
                            <?= hopia_order_icon('arrow-right') ?>
                        </a>
                        <a class="btn btn-secondary btn-block" href="products.php">
                            <span>CONTINUE SHOPPING</span>
                            <?= hopia_order_icon('bag') ?>
                        </a>
                    </div>
                </section>

                <?php if ($showCancelForm || $showCancelInfo): ?>

                    <section class="order-detail-card order-detail-cancellation order-detail-card--actions" aria-labelledby="order-actions-heading">
                        <div class="order-detail-card__head">
                            <div class="order-detail-card__heading">
                                <div class="order-detail-card__label">
                                    <?= hopia_order_icon('actions') ?>
                                    <h2 id="order-actions-heading" class="order-detail-card__title">Need to make a change?</h2>
                                </div>
                                <p class="order-detail-card__sub">Order actions</p>
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
                                <button class="btn btn-danger btn-block" type="submit">
                                    <?= hopia_order_icon('x') ?>
                                    <span>REQUEST CANCELLATION</span>
                                </button>
                            </form>

                        <?php elseif ($cancellationStatus === 'REQUESTED'): ?>

                            <p class="od-status-note" role="status">Your cancellation request is waiting for admin review.</p>

                        <?php elseif ($cancellationStatus === 'REJECTED'): ?>

                            <p class="od-status-note" role="status">Your cancellation request was rejected.</p>

                        <?php else: ?>

                            <p class="od-status-note" role="status">Your cancellation request was approved. This order has been cancelled.</p>

                        <?php endif; ?>
                    </section>

                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
