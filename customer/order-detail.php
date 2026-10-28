<?php

session_start();
$orderLiveRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['order_live'] ?? '') === '1';

if (!isset($_SESSION['customer_id'])) {
    if ($orderLiveRequest) { http_response_code(401); header('Cache-Control: no-store'); exit; }
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/feedback-data.php';
require_once __DIR__ . '/../includes/gcash.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/pickup.php';
require_once __DIR__ . '/../includes/shipping.php';
require_once __DIR__ . '/../includes/customer_delivery.php';

$customerId = (int) $_SESSION['customer_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
 * Customer-friendly labels. The database values remain unchanged.
 */

$orderStatusLabels = [
    'PENDING' => 'Pending',
    'CONFIRMED' => 'Confirmed',
    'PACKED' => 'Packed',
    'SHIPPED' => 'Shipped',
    'IN_TRANSIT' => 'In Transit',
    'DELIVERED' => 'Delivered',
    'RETURN_TO_SELLER' => 'Return to Seller',
    'RETURNED_TO_SHOP' => 'Returned to Shop',
    'CANCELLED' => 'Cancelled'
];

$paymentMethodLabels = [
    'COD' => 'Cash on Delivery',
    'GCASH' => 'GCash',
    'CARD' => 'Card',
    'CASH_ON_PICKUP' => 'Cash on Pickup'
];

$paymentStatusLabels = [
    'PENDING' => 'Pending',
    'PAID' => 'Paid',
    'FAILED' => 'Failed',
    'REFUNDED' => 'Refunded'
];

$shipmentStatusLabels = customer_delivery_labels();

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
    'IN_TRANSIT' => 'badge-info',
    'RETURN_TO_SELLER' => 'badge-warning',
    'RETURNED_TO_SHOP' => 'badge-info',
    'DELIVERED' => 'badge-success',
    'CANCELLED' => 'badge-cancelled'
];

/*
 * Display-only maps for the order progress timeline and the
 * semantic badge styling. No database values are changed.
 */

$orderWorkflow = ['PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED', 'IN_TRANSIT', 'DELIVERED'];

$orderStepIcons = [
    'PENDING' => 'clock',
    'CONFIRMED' => 'check',
    'PACKED' => 'package',
    'SHIPPED' => 'truck',
    'IN_TRANSIT' => 'truck',
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
    'DELIVERED' => 'badge-success',
    'DELIVERY_ATTEMPT_FAILED' => 'badge-warning',
    'RETURN_TO_SELLER' => 'badge-warning',
    'RETURNED_TO_SHOP' => 'badge-info'
];

$requestedOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$order = false;
$orderItems = [];
$payment = false;
$shipment = false;
$courierHistory = null;

if ($requestedOrderId !== null && $requestedOrderId !== false && $requestedOrderId > 0) {
    if ($orderLiveRequest) {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
    }
    /*
     * Fetch the order using BOTH the requested order ID and the
     * authenticated customer's ID, so a customer can only ever
     * view their own order.
     */

    $orderStmt = $pdo->prepare(
        'SELECT id, status, cancellation_status, subtotal, shipping_fee, total_amount,
                shipping_name, shipping_phone, shipping_address, created_at, received_at,
                pickup_status, ready_for_pickup_at, pickup_deadline_at, picked_up_at,
                EXISTS(SELECT 1 FROM sales_transactions st WHERE st.order_id = orders.id) AS has_completed_sale
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

        $payment = payment_load($pdo, $orderId) ?? false;

        /*
         * Fetch the shipment for display only. The order is already
         * ownership-verified above, so the shipment lookup is scoped
         * to that same validated $orderId.
         */

        $shipmentStmt = $pdo->prepare(
            'SELECT *
             FROM shipments
             WHERE order_id = :order_id
             LIMIT 1'
        );

        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch();

        if ($shipment !== false && $shipment['delivery_method'] === 'LOCAL_COURIER') {
            $courierSchema = delivery_schema_status($pdo);
            $courierHistory = customer_delivery_history($pdo, $orderId, $customerId, $courierSchema);
        }

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
    if ($orderLiveRequest) $pdo->commit();
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

/*
 * GCash payment state + configuration for the GCash panel. The panel is only
 * rendered for a GCASH payment; its submission form posts to payment-submit.php.
 */
$gcashConfig = gcash_config();
$isGcashOrder = ($payment !== false && ($payment['payment_method'] ?? '') === 'GCASH');
$gcashState = $isGcashOrder ? payment_gcash_state($payment) : 'NA';

$gcashFlash = '';
if (isset($_GET['gcash'])) {
    $gcashFlash = in_array($_GET['gcash'], ['submitted', 'resubmitted', 'cash_changed', 'error'], true) ? $_GET['gcash'] : '';
}
$gcashFlashMessage = ($gcashFlash === 'error' && isset($_GET['gcash_reason']) && is_string($_GET['gcash_reason']))
    ? payment_message($_GET['gcash_reason'])
    : '';

$shipmentStatusLabel = ($shipment !== false && $shipment['shipment_status'] !== null)
    ? ($shipmentStatusLabels[$shipment['shipment_status']] ?? $shipment['shipment_status'])
    : '';

$trackingNumber = $shipment !== false
    ? trim($shipment['tracking_number'] ?? '')
    : '';

/*
 * Display-only: total purchased quantity for the item count label.
 */

$itemCount = 0;
foreach ($orderItems as $item) {
    $itemCount += (int) $item['quantity'];
}

$itemCountLabel = $itemCount === 1 ? '1 ITEM' : $itemCount . ' ITEMS';

/*
 * Phase 3: the Store Pickup lifecycle view. Resolved from the persisted
 * fulfillment method (shipments.delivery_method) — a pickup order renders
 * the pickup timeline below instead of the courier PENDING→…→DELIVERED
 * progression; a courier order keeps the courier timeline untouched. The
 * pickup stage never overwrites or implies payment state.
 */
$pickupProgress = ($order !== false)
    ? pickup_progress($order, $shipment)
    : null;

$orderTimeline = ($order !== false && $pickupProgress === null)
    ? customer_order_timeline($order, $payment !== false ? $payment : null,
        $shipment !== false ? $shipment : null, $courierHistory)
    : null;
if ($orderTimeline !== null) {
    $orderStatusLabel = $orderStatusLabels[$orderTimeline['badge_stage'] ?? ''] ?? 'Status unavailable';
    $shipmentStatusLabel = $orderTimeline['stage'] === 'CANCELLED' ? 'Cancelled'
        : ($orderTimeline['courier_label'] ?? 'Status unavailable');
}
$orderStatusBadgeClass = $order !== false && ($order['status'] === 'CANCELLED' || $order['cancellation_status'] === 'APPROVED')
    ? 'badge-cancelled' : ($pickupProgress !== null ? $pickupProgress['badge_class']
        : ($orderStatusBadgeClasses[$orderTimeline['badge_stage'] ?? ''] ?? 'badge'));
if ($pickupProgress !== null) {
    $orderStatusLabel = $order !== false && ($order['status'] === 'CANCELLED' || $order['cancellation_status'] === 'APPROVED')
        ? 'Cancelled' : $pickupProgress['stage_label'];
}

$pickupPickupDetails = shipping_pickup_details();

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
 * PRG flags from the Order Received confirmation endpoint.
 * Both are informational only; the authoritative receipt state is
 * the database received_at column.
 */
$receivedFlag = isset($_GET['received']);
$receivedErrorFlag = isset($_GET['received_error']);
// Match the receipt endpoint's existing legacy NULL-method courier handling.
$receiptDeliveryEligible = $order !== false && $pickupProgress === null
    && customer_receipt_delivery_eligible($order, $shipment !== false ? $shipment : null)
    && ($order['status'] === 'DELIVERED' || ($courierHistory['state'] ?? '') === 'ready');

/*
 * The cancellation request form is only offered while the order
 * is still PENDING and no request exists yet. Informational
 * messages are shown for the other cancellation states.
 */

$showCancelForm = (
    $order !== false
    && $order['status'] === 'PENDING'
    && $cancellationStatus === 'NONE'
    && !($pickupProgress !== null && $pickupProgress['stage'] === 'PICKED_UP')
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

/*
 * Feedback gating: order must be DELIVERED and the customer must have
 * confirmed receipt. The received_at check is the Phase C addition.
 * Edit mode for existing feedback is still allowed for legacy records.
 */
$showFeedbackAction = $order !== false
    && $receiptDeliveryEligible
    && $order['status'] === 'DELIVERED'
    && $order['received_at'] !== null;
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
$ui_order_live = $order !== false && $pickupProgress === null;
$ui_feedback_overlay = $ui_order_live;
$orderLiveVersion = hash('sha256', serialize([$order, $shipment,
    $payment !== false ? array_diff_key($payment, ['checked_at' => true]) : false, $courierHistory]));

if ($orderLiveRequest) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$ui_order_live) { http_response_code($order === false ? 404 : 422); exit; }
} else {
    require __DIR__ . '/../includes/ui.head.php';
    require __DIR__ . '/../includes/ui.header.php';
}

?>

<section class="order-detail-page"<?= $ui_order_live ? ' data-order-live' : '' ?> data-version="<?= hopia_e($orderLiveVersion) ?>">

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

        <?php if ($receivedFlag && $receiptDeliveryEligible && $order['received_at'] !== null): ?>
            <p class="alert alert-success" role="status">
                Your order receipt has been confirmed. Thank you!
            </p>
        <?php elseif ($receivedErrorFlag): ?>
            <p class="alert alert-error" role="alert">
                The order receipt could not be confirmed. Please try again.
            </p>
        <?php elseif ($cancelRequestedFlag): ?>
            <p class="alert alert-success" role="status">
                Your cancellation request has been submitted and is waiting for admin review.
            </p>
        <?php elseif ($cancelErrorFlag): ?>
            <p class="alert alert-error" role="alert">
                The cancellation request could not be submitted. Please review the order and try again.
            </p>
        <?php endif; ?>

        <?php if ($gcashFlash === 'cash_changed' && !$isGcashOrder && $order['status'] !== 'CANCELLED'
            && $order['cancellation_status'] !== 'APPROVED' && in_array($payment['payment_method'] ?? '', ['COD', 'CASH_ON_PICKUP'], true)): ?>
            <p class="alert alert-success" role="status">
                Your payment method has been changed to <?= hopia_e($paymentMethodLabel) ?>.
                <?php if (($payment['payment_status'] ?? '') === 'PENDING'): ?>
                    Payment is still due <?= ($payment['payment_method'] ?? '') === 'COD' ? 'when your order is delivered' : 'when you collect your order at the shop' ?>.
                <?php endif; ?>
            </p>
        <?php elseif ($gcashFlash === 'error' && !$isGcashOrder): ?>
            <p class="alert alert-error" role="alert"><?= hopia_e($gcashFlashMessage) ?></p>
        <?php endif; ?>

        <header class="order-detail-hero" data-order-live-region="header">
            <p class="order-detail-hero__eyebrow">Order details</p>
            <h1>Order #<?= hopia_e($order['id']) ?></h1>
            <p class="order-detail-hero__meta">
                <span>
                    Placed on <?= hopia_e(date('M j, Y g:i A', strtotime($order['created_at']))) ?>
                </span>
                <span class="badge <?= hopia_e($orderStatusBadgeClass) ?>">
                    <?= hopia_e($orderStatusLabel) ?>
                </span>
            </p>
        </header>

        <section class="order-detail-card order-detail-card--progress" aria-labelledby="order-status-heading" data-order-live-region="status">
            <div class="order-detail-card__head">
                <div class="order-detail-card__label">
                    <?= hopia_order_icon('status') ?>
                    <h2 id="order-status-heading" class="order-detail-card__title">Order status</h2>
                </div>
            </div>

            <?php if ($order['status'] === 'CANCELLED' || $cancellationStatus === 'APPROVED'): ?>

                <div class="od-cancelled">
                    <span class="od-cancelled__icon" aria-hidden="true"><?= hopia_order_icon('x-circle') ?></span>
                    <div>
                        <p class="od-cancelled__title">Cancelled</p>
                        <p>This order has been cancelled.</p>
                    </div>
                </div>

            <?php elseif ($pickupProgress !== null): ?>

                <?php /*
                     * Store Pickup lifecycle (Phase 3): the customer sees the
                     * pickup stages here — never the courier Packed/Shipped/
                     * Delivered progression. Cancellation renders above as its
                     * own separate terminal outcome.
                     */ ?>
                <ol class="od-progress od-progress--pickup" role="list">
                    <?php foreach (pickup_stages() as $stageIndex => $stageKey): ?>
                        <?php
                        $stepState = '';
                        if ($stageIndex < $pickupProgress['stage_index']) {
                            $stepState = ' is-complete';
                        } elseif ($stageIndex === $pickupProgress['stage_index']) {
                            $stepState = ' is-current';
                        }
                        ?>
                        <li class="od-progress__step<?= hopia_e($stepState) ?>">
                            <span class="od-progress__node" aria-hidden="true">
                                <?= hopia_order_icon(pickup_stage_icon($stageKey)) ?>
                            </span>
                            <span class="od-progress__label"><?= hopia_e(pickup_stage_label($stageKey)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <?php if ($pickupProgress['stage'] === 'READY_FOR_PICKUP'): ?>
                    <div class="pickup-deadline-note<?= $pickupProgress['overdue'] ? ' is-overdue' : '' ?>">
                        <p class="pickup-deadline-note__title">
                            <?= $pickupProgress['overdue'] ? 'Your order is ready and waiting for you' : 'Your order is ready for pickup' ?>
                        </p>
                        <p>
                            Please collect it at the shop
                            <?php if ($pickupProgress['deadline_at'] !== null): ?>
                                <strong>by <?= hopia_e(date('M j, Y g:i A', strtotime($pickupProgress['deadline_at']))) ?></strong>
                                (48-hour pickup window)
                            <?php endif; ?>.
                            Bring a valid ID and your order number <strong>#<?= hopia_e($order['id']) ?></strong>.
                        </p>
                        <?php if ($pickupProgress['overdue']): ?>
                            <p class="pickup-deadline-note__overdue">
                                This order has passed its pickup window. Please contact the shop as soon as possible
                                so we can arrange your pickup.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php elseif ($pickupProgress['stage'] === 'PREPARING'): ?>
                    <p class="pickup-deadline-note__intro">
                        The shop is preparing your items. We will update this page as soon as your order is ready
                        for pickup; the 48-hour pickup window starts then.
                    </p>
                <?php elseif ($pickupProgress['stage'] === 'PICKED_UP' && $pickupProgress['picked_up_at'] !== null): ?>
                    <p class="pickup-deadline-note__intro">
                        You collected this order on <?= hopia_e(date('M j, Y g:i A', strtotime($pickupProgress['picked_up_at']))) ?>. Enjoy your finds!
                    </p>
                <?php endif; ?>

            <?php else: ?>

                <div class="od-timeline-status" role="status">
                    <p class="od-timeline-status__heading">
                        <strong><?= hopia_e($orderTimeline['closure'] === 'RECEIVED' ? 'Delivery received'
                            : ($orderTimeline['closure'] === 'RETURNED_TO_SHOP' ? 'Returned to Shop'
                                : ($orderTimeline['stage'] !== null ? 'Current stage: ' . $orderStatusLabels[$orderTimeline['stage']]
                                    : 'Order status under review'))) ?></strong>
                        <?php if (in_array($orderTimeline['stage'], ['IN_TRANSIT', 'DELIVERED'], true) && isset($shipmentStatusLabels[$orderTimeline['courier_stage'] ?? ''])): ?>
                            <span class="badge <?= hopia_e($shipmentStatusBadgeClasses[$orderTimeline['courier_stage']] ?? 'badge') ?>"><?= hopia_e($orderTimeline['stage'] === 'DELIVERED'
                                ? $orderTimeline['courier_label'] : 'Current courier state: ' . $orderTimeline['courier_label']) ?></span>
                        <?php endif; ?>
                    </p>
                    <p><?= hopia_e($orderTimeline['message']) ?></p>
                </div>

                <?php if ($orderTimeline['stage'] !== null): ?>
                <ol class="od-progress od-progress--courier" role="list" aria-label="Order progress">
                    <?php foreach ($orderWorkflow as $stepStatus): ?>
                        <?php
                        $stepState = $orderTimeline['returned_step_states'][$stepStatus]
                            ?? ($orderTimeline['stage'] === $stepStatus ? 'current' : '');
                        $stepIsCurrent = $stepState === 'current';
                        $showCourierLink = $stepStatus === 'IN_TRANSIT' && $courierHistory !== null
                            && $courierHistory['state'] === 'ready' && $courierHistory['stage'] !== null;
                        ?>
                        <li class="od-progress__step<?= $stepState !== '' ? ' is-' . hopia_e($stepState) : '' ?>"<?= $stepIsCurrent ? ' aria-current="step"' : '' ?><?= $stepState === 'unreached' ? ' aria-disabled="true"' : '' ?>>
                            <span class="od-progress__node" aria-hidden="true">
                                <?= hopia_order_icon($orderStepIcons[$stepStatus] ?? 'package') ?>
                            </span>
                            <span class="od-progress__copy">
                                <span class="od-progress__label"><?= hopia_e($orderStatusLabels[$stepStatus]) ?></span>
                                <?php if ($showCourierLink): ?>
                                    <a class="od-progress__details-link" href="#courier-history-heading">Courier updates<span class="sr-only"> for In Transit</span></a>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>

            <?php endif; ?>
            <?php if ($pickupProgress === null): ?>
                <?php require __DIR__ . '/../includes/ui.customer-delivery.php'; ?>
            <?php endif; ?>
        </section>

        <?php if ($pickupProgress === null && in_array($orderTimeline['closure'] ?? null, ['RECEIVED', 'RETURNED_TO_SHOP'], true)): ?>
            <section class="order-detail-card order-detail-card--closure" aria-labelledby="delivery-journey-heading" data-order-live-region="closure">
                <div class="order-detail-card__head">
                    <div class="order-detail-card__label">
                        <?= hopia_order_icon('check-circle') ?>
                        <h2 id="delivery-journey-heading" class="order-detail-card__title"><?= $orderTimeline['closure'] === 'RECEIVED'
                            ? 'Delivery journey complete' : 'Delivery journey ended' ?></h2>
                    </div>
                </div>
                <?php if ($orderTimeline['closure'] === 'RECEIVED'): ?>
                    <p>Order received successfully. Thank you for shopping with Hopia Fits! Your delivery journey is complete.</p>
                <?php else: ?>
                    <p>This parcel has been returned to Hopia Fits, and its delivery journey has ended. If you need assistance regarding your order, please contact the shop.</p>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <div data-order-live-region="closure" hidden></div>
        <?php endif; ?>

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
                            <span>Merchandise subtotal</span>
                            <span>&#8369;<?= number_format((float) $order['subtotal'], 2) ?></span>
                        </div>
                        <div class="order-detail-total-row order-detail-total-row--muted">
                            <span>Shipping</span>
                            <span>&#8369;<?= number_format((float) $order['shipping_fee'], 2) ?></span>
                        </div>
                        <div class="order-detail-total-row order-detail-total-row--grand">
                            <span>Order total</span>
                            <span>&#8369;<?= number_format((float) $order['total_amount'], 2) ?></span>
                        </div>
                    </div>
                </section>
            </div>

            <div class="order-detail-rail">

                    <section class="order-detail-card order-detail-card--delivery" aria-labelledby="delivery-heading" data-order-live-region="delivery">
                        <div class="order-detail-card__head">
                            <div class="order-detail-card__heading">
                                <div class="order-detail-card__label">
                                    <?= hopia_order_icon('location') ?>
                                    <h2 id="delivery-heading" class="order-detail-card__title">
                                        <?= $pickupProgress !== null ? 'Where to pick up' : 'Where it is going' ?>
                                    </h2>
                                </div>
                                <p class="order-detail-card__sub">
                                    <?= $pickupProgress !== null ? 'Store Pickup information' : 'Delivery information' ?>
                                </p>
                            </div>
                        </div>

                        <?php if ($pickupProgress !== null): ?>
                            <dl class="order-detail-details">
                                <div>
                                    <dt>Shop</dt>
                                    <dd><?= hopia_e($pickupPickupDetails['shop']) ?></dd>
                                </div>
                                <div>
                                    <dt>Address</dt>
                                    <dd><?= hopia_e($pickupPickupDetails['address']) ?></dd>
                                </div>
                                <div>
                                    <dt>Contact</dt>
                                    <dd><a href="<?= hopia_e($pickupPickupDetails['contact_href']) ?>"><?= hopia_e($pickupPickupDetails['contact']) ?></a></dd>
                                </div>
                                <div>
                                    <dt>Collector</dt>
                                    <dd><?= hopia_e($order['shipping_name']) ?></dd>
                                </div>
                            </dl>
                            <p class="order-detail-tracking">
                                <a class="btn btn-outline btn-sm" href="<?= hopia_e($pickupPickupDetails['maps_url']) ?>" target="_blank" rel="noopener noreferrer">
                                    View on Google Maps
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                        <path d="M7 17 17 7"></path>
                                        <path d="M8 7h9v9"></path>
                                    </svg>
                                </a>
                            </p>
                        <?php else: ?>
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
                                <?php if ($orderTimeline['closure'] === 'RECEIVED'): ?>
                                <div>
                                    <dt>Receipt confirmed</dt>
                                    <dd>
                                        <span class="badge badge-success">
                                            <?= hopia_e(date('M j, Y g:i A', strtotime($order['received_at']))) ?>
                                        </span>
                                    </dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                        <?php endif; ?>
                    </section>

                <section class="order-detail-card order-detail-card--payment" aria-labelledby="payment-heading" data-order-live-region="payment">
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

                <?php if ($isGcashOrder): ?>
                    <?php
                    $gcashPanel = [
                        'order_id' => (int) $order['id'],
                        'amount' => $order['total_amount'],
                        'config' => $gcashConfig,
                        'payment' => $payment,
                        'order' => $order,
                        'shipment' => $shipment !== false ? $shipment : null,
                        'state' => $gcashState,
                        'csrf' => $csrfToken,
                        'return' => 'order-detail.php',
                        'proof_endpoint' => 'payment-proof.php',
                        'flash' => $gcashFlash,
                        'flash_message' => $gcashFlashMessage,
                    ];
                    require __DIR__ . '/../includes/ui.gcash-panel.php';
                    ?>
                <?php endif; ?>

                <?php if ($pickupProgress === null && ($shipment !== false || $trackingNumber !== '')): ?>

                    <section class="order-detail-card order-detail-card--shipment" aria-labelledby="shipment-heading" data-order-live-region="shipment">
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
                                <dd><span class="badge <?= hopia_e($orderTimeline['stage'] === 'CANCELLED' ? 'badge-cancelled'
                                    : ($shipmentStatusBadgeClasses[$orderTimeline['courier_stage'] ?? ''] ?? 'badge')) ?>"><?= hopia_e($shipmentStatusLabel) ?></span></dd>
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

                <section class="order-detail-card order-detail-card--footer" aria-labelledby="order-next-heading" data-order-live-region="actions">
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
                        <?php if ($receiptDeliveryEligible && $order['received_at'] === null && (!$isGcashOrder || $gcashState === 'PAID')): ?>
                            <p class="od-order-received-intro">
                                Confirm only after you have physically received your parcel. This marks the delivery as Delivered and unlocks feedback; it does not confirm payment collection.
                            </p>
                            <form method="POST" action="order-received.php"
                                  onsubmit="return confirm('Confirm that you have received your order?');">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">
                                <button class="btn btn-success btn-block" type="submit">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                    </svg>
                                    <span>ORDER RECEIVED</span>
                                </button>
                            </form>
                        <?php elseif ($receiptDeliveryEligible && $order['received_at'] === null && $isGcashOrder && $gcashState !== 'PAID'): ?>
                            <p class="od-order-received-intro" role="status">
                                <?= $gcashState === 'REJECTED'
                                    ? 'Your GCash payment was rejected. Please contact the shop for assistance; receipt confirmation remains unavailable until payment is verified.'
                                    : 'Your GCash payment is still awaiting verification by the shop. You can confirm receipt after it has been verified.' ?>
                            </p>
                        <?php endif; ?>
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

<?php if ($orderLiveRequest) exit; ?>
<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
