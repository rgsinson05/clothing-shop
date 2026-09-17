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
 * Ensure a CSRF token exists for the cancellation request form.
 */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

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

$cancellationStatusLabels = [
    'NONE' => 'No cancellation request',
    'REQUESTED' => 'Cancellation Requested',
    'APPROVED' => 'Cancellation Approved',
    'REJECTED' => 'Cancellation Rejected'
];

/*
 * Validate the requested order ID. Anything that is not a
 * positive integer is treated the same as an unknown order.
 */

$requestedOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$order = false;
$orderItems = [];
$payment = false;
$trackingNumber = '';

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
         * Fetch the tracking number for display only. The order is
         * already ownership-verified above, so the shipment lookup
         * is scoped to that same validated $orderId. A missing row
         * or NULL tracking number simply means nothing is shown.
         */

        $shipmentStmt = $pdo->prepare(
            'SELECT tracking_number
             FROM shipments
             WHERE order_id = :order_id
             LIMIT 1'
        );

        $shipmentStmt->execute([':order_id' => $orderId]);

        $shipment = $shipmentStmt->fetch();

        $trackingNumber = $shipment !== false
            ? trim($shipment['tracking_number'] ?? '')
            : '';
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

?>
<?php

require_once __DIR__ . '/../includes/ui.php';

$page_title = 'Order Confirmation - ' . hopia_site_name();
$page_description = 'Your order confirmation at ' . hopia_site_name() . '.';
$ui_active = 'orders.php';
$body_class = 'confirmation-page-shell';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';

?>

<section class="confirmation-page">
    <?php if ($order === false): ?>

        <div class="empty-state confirmation-empty">
            <h1>Order Not Found</h1>
            <p>This order does not exist or is not available in your account.</p>
            <div class="confirmation-actions">
                <a class="btn btn-primary" href="orders.php">VIEW ORDERS</a>
                <a class="btn btn-secondary" href="products.php">CONTINUE SHOPPING</a>
            </div>
        </div>

    <?php else: ?>

        <header class="confirmation-hero">
            <div class="confirmation-hero__icon" aria-hidden="true">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="m8 12 2.5 2.5L16 9"></path>
                </svg>
            </div>
            <p class="confirmation-hero__eyebrow">ORDER PLACED</p>
            <h1>Your order has been received.</h1>
            <p class="confirmation-hero__number">
                Order <strong>#<?= hopia_e($order['id']) ?></strong>
            </p>
        </header>

        <?php if ($cancelRequestedFlag): ?>

            <p class="alert alert-success" role="status">
                Your cancellation request has been submitted and is waiting for admin review.
            </p>

        <?php elseif ($cancelErrorFlag): ?>

            <p class="alert alert-error" role="alert">
                The cancellation request could not be submitted. Please review the order and try again.
            </p>

        <?php endif; ?>

        <div class="confirmation-layout">
            <div class="confirmation-main">
                <section class="confirmation-card" aria-labelledby="order-details-heading">
                    <div class="confirmation-card__head">
                        <div>
                            <p class="confirmation-card__eyebrow">Your purchase</p>
                            <h2 id="order-details-heading">Order Details</h2>
                        </div>
                        <span class="badge badge-pending"><?= hopia_e($orderStatusLabel) ?></span>
                    </div>

                    <ul class="confirmation-items" role="list">
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
                            <li class="confirmation-item">
                                <div class="confirmation-item__image">
                                    <?php if ($imagePath !== ''): ?>
                                        <img
                                            src="<?= hopia_e('../' . ltrim($imagePath, '/')) ?>"
                                            alt="<?= hopia_e($item['product_name']) ?>"
                                        >
                                    <?php else: ?>
                                        <div class="confirmation-item__placeholder">
                                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                                <polyline points="21 15 16 10 5 21"></polyline>
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="confirmation-item__details">
                                    <h3><?= hopia_e($item['product_name']) ?></h3>
                                    <?php if (count($metaParts) > 0): ?>
                                        <p><?= hopia_e(implode(' · ', $metaParts)) ?></p>
                                    <?php endif; ?>
                                    <?php if ((int) $item['quantity'] > 1): ?>
                                        <p>Quantity <?= hopia_e($item['quantity']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <p class="price confirmation-item__price">₱<?= number_format((float) $item['subtotal'], 2) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="confirmation-totals">
                        <div class="confirmation-total-row">
                            <span>Order total before shipping</span>
                            <span>₱<?= number_format((float) $order['subtotal'], 2) ?></span>
                        </div>
                        <div class="confirmation-total-row confirmation-total-row--muted">
                            <span>Shipping</span>
                            <span>To be confirmed</span>
                        </div>
                        <div class="confirmation-total-row confirmation-total-row--grand">
                            <span>Order total</span>
                            <span>₱<?= number_format((float) $order['total_amount'], 2) ?></span>
                        </div>
                    </div>

                    <dl class="confirmation-meta">
                        <div>
                            <dt>Placed on</dt>
                            <dd><?= hopia_e(date('M j, Y g:i A', strtotime($order['created_at']))) ?></dd>
                        </div>
                        <div>
                            <dt>Cancellation</dt>
                            <dd><?= hopia_e($cancellationStatusLabel) ?></dd>
                        </div>
                    </dl>
                </section>

                <section class="confirmation-card" aria-labelledby="payment-details-heading">
                    <div class="confirmation-card__head">
                        <div>
                            <p class="confirmation-card__eyebrow">How you will pay</p>
                            <h2 id="payment-details-heading">Payment</h2>
                        </div>
                    </div>

                    <dl class="confirmation-details">
                        <div>
                            <dt>Payment method</dt>
                            <dd><?= hopia_e($paymentMethodLabel) ?></dd>
                        </div>
                        <div>
                            <dt>Payment status</dt>
                            <dd><span class="badge badge-pending"><?= hopia_e($paymentStatusLabel) ?></span></dd>
                        </div>
                    </dl>
                </section>

                <section class="confirmation-card" aria-labelledby="delivery-details-heading">
                    <div class="confirmation-card__head">
                        <div>
                            <p class="confirmation-card__eyebrow">Where it is going</p>
                            <h2 id="delivery-details-heading">Delivery</h2>
                        </div>
                    </div>

                    <dl class="confirmation-details">
                        <div>
                            <dt>Shipping</dt>
                            <dd>To be confirmed</dd>
                        </div>
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
                        <?php if ($trackingNumber !== ''): ?>
                            <div>
                                <dt>Tracking number</dt>
                                <dd><?= hopia_e($trackingNumber) ?></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                </section>

                <?php if ($showCancelForm || $showCancelInfo): ?>

                    <section class="confirmation-card confirmation-cancellation" aria-labelledby="order-actions-heading">
                        <div class="confirmation-card__head">
                            <div>
                                <p class="confirmation-card__eyebrow">Need to make a change?</p>
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

            <aside class="confirmation-aside" aria-label="Order actions">
                <div class="confirmation-aside__inner">
                    <p class="confirmation-aside__label">Order number</p>
                    <p class="confirmation-aside__number">#<?= hopia_e($order['id']) ?></p>
                    <a class="btn btn-primary btn-block" href="orders.php">VIEW ORDER</a>
                    <a class="btn btn-secondary btn-block" href="products.php">CONTINUE SHOPPING</a>
                </div>
            </aside>
        </div>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
