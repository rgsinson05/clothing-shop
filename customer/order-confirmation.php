<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/gcash.php';
require_once __DIR__ . '/../includes/payment.php';

/*
 * Require the customer to be logged in.
 */

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

/*
 * Customer CSRF token for the GCash reference submission form.
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$gcashConfig = gcash_config();

/*
 * Submission feedback flags from payment-submit.php (PRG). The reason, when
 * present, is mapped to a safe message; an unknown code falls back to generic.
 */
$gcashFlash = '';
if (isset($_GET['gcash'])) {
    $gcashFlash = in_array($_GET['gcash'], ['submitted', 'resubmitted', 'error'], true) ? $_GET['gcash'] : '';
}
$gcashFlashMessage = ($gcashFlash === 'error' && isset($_GET['gcash_reason']) && is_string($_GET['gcash_reason']))
    ? payment_message($_GET['gcash_reason'])
    : '';

/*
 * Validate the requested order ID.
 */

$requestedOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$order = false;
$orderItems = [];
$payment = false;
$shipment = null;

if ($requestedOrderId !== null && $requestedOrderId !== false && $requestedOrderId > 0) {

    $orderStmt = $pdo->prepare(
        'SELECT id, subtotal, shipping_fee, total_amount, status, cancellation_status,
                received_at, pickup_status, picked_up_at,
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
         * Item information comes from the order_items snapshot.
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

        $payment = payment_load($pdo, $orderId) ?? false;
        $shipmentStmt = $pdo->prepare(
            'SELECT delivery_method, shipment_status, shipped_at, delivered_at
             FROM shipments WHERE order_id = :order_id LIMIT 1'
        );
        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch() ?: null;
    }
}

$paymentMethodLabel = ($payment !== false && $payment['payment_method'] !== null)
    ? ($payment['payment_method'] === 'COD' ? 'Cash on Delivery'
       : ($payment['payment_method'] === 'GCASH' ? 'GCash'
       : ($payment['payment_method'] === 'CASH_ON_PICKUP' ? 'Cash on Pickup' : 'Card')))
    : '';

$paymentStatusLabel = ($payment !== false && $payment['payment_status'] !== null)
    ? ($payment['payment_status'] === 'PENDING' ? 'Pending'
       : ($payment['payment_status'] === 'PAID' ? 'Paid'
       : ($payment['payment_status'] === 'FAILED' ? 'Failed' : 'Refunded')))
    : '';

/* Semantic badge for the payment status (fixes the previously hardcoded one). */
$paymentStatusBadgeMap = [
    'PENDING' => 'badge-pending',
    'PAID' => 'badge-success',
    'FAILED' => 'badge-danger',
    'REFUNDED' => 'badge-info',
];
$paymentStatusBadgeClass = ($payment !== false && isset($paymentStatusBadgeMap[$payment['payment_status']]))
    ? $paymentStatusBadgeMap[$payment['payment_status']]
    : 'badge-pending';

$isGcashOrder = ($payment !== false && ($payment['payment_method'] ?? '') === 'GCASH');
$gcashState = $isGcashOrder ? payment_gcash_state($payment) : 'NA';

/*
 * A GCash order whose reference AND uploaded proof are present is
 * submitted-but-unverified (derived state AWAITING), so the post-order
 * "complete your payment" modal is obsolete for it. Legacy orders that
 * genuinely lack either value keep the existing submission path.
 */
$gcashReferenceSubmitted = $isGcashOrder
    && trim((string) ($payment['gcash_reference'] ?? '')) !== '';
$gcashProofSubmitted = $isGcashOrder
    && trim((string) ($payment['proof_path'] ?? '')) !== '';
$gcashSubmissionComplete = $gcashState === 'AWAITING'
    && $gcashReferenceSubmitted
    && $gcashProofSubmitted
    /* A resubmission flash must still reach the existing panel/form so a
     * legacy correction attempt can show its result. New checkout orders
     * arrive here without a flash and therefore suppress the panel. */
    && $gcashFlash === '';

/*
 * The payment row stays PENDING until an admin verifies the transfer. For a
 * submitted-at-checkout order, present that derived state explicitly instead
 * of the bare database word "Pending", without ever claiming verification.
 */
if ($gcashSubmissionComplete) {
    $paymentStatusLabel = 'Paid — Awaiting Admin Confirmation';
    $paymentStatusBadgeClass = 'badge-pending';
}

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

        <div class="confirmation-layout">
            <div class="confirmation-main">

                <section class="confirmation-card" aria-labelledby="purchase-heading">
                    <div class="confirmation-card__head">
                        <div class="confirmation-card__label">
                            <?= hopia_confirmation_icon('purchase') ?>
                            <p class="confirmation-card__eyebrow">YOUR PURCHASE</p>
                        </div>
                        <h2 id="purchase-heading">Order Details</h2>
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
                                </div>
                                <p class="price confirmation-item__price">₱<?= number_format((float) $item['subtotal'], 2) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="confirmation-totals">
                        <div class="confirmation-total-row">
                            <span>Merchandise subtotal</span>
                            <span>₱<?= number_format((float) $order['subtotal'], 2) ?></span>
                        </div>
                        <div class="confirmation-total-row confirmation-total-row--muted">
                            <span>Shipping</span>
                            <span>₱<?= number_format((float) $order['shipping_fee'], 2) ?></span>
                        </div>
                        <div class="confirmation-total-row confirmation-total-row--grand">
                            <span>Order total</span>
                            <span>₱<?= number_format((float) $order['total_amount'], 2) ?></span>
                        </div>
                    </div>
                </section>

                <section class="confirmation-card" aria-labelledby="payment-heading">
                    <div class="confirmation-card__head">
                        <div class="confirmation-card__label">
                            <?= hopia_confirmation_icon('payment') ?>
                            <p class="confirmation-card__eyebrow">HOW YOU WILL PAY</p>
                        </div>
                        <h2 id="payment-heading">Payment</h2>
                    </div>

                    <dl class="confirmation-details">
                        <div>
                            <dt>Payment method</dt>
                            <dd><?= hopia_e($paymentMethodLabel) ?></dd>
                        </div>
                        <div>
                            <dt>Payment status</dt>
                            <dd><span class="badge <?= hopia_e($paymentStatusBadgeClass) ?>"><?= hopia_e($paymentStatusLabel) ?></span></dd>
                        </div>
                    </dl>

                    <?php if ($gcashSubmissionComplete): ?>
                        <div class="alert alert-info" role="status">
                            <strong>GCash payment submitted</strong>
                            <p>Your GCash reference number and payment proof were submitted with this order and are awaiting admin confirmation. Our team will confirm the transfer before the payment is marked verified.</p>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($isGcashOrder && !$gcashSubmissionComplete): ?>
                    <?php
                    /*
                     * The GCash panel renders inside an automatically opened,
                     * centered modal here so the customer immediately sees how
                     * to pay and submit their proof without scrolling. It is
                     * the same single panel/form used on order-detail.php; a
                     * clearly visible reopen button restores the modal if it
                     * was closed without submitting.
                     */
                    $gcashPanel = [
                        'order_id' => (int) $order['id'],
                        'amount' => $order['total_amount'],
                        'config' => $gcashConfig,
                        'payment' => $payment,
                        'order' => $order,
                        'shipment' => $shipment,
                        'state' => $gcashState,
                        'csrf' => $csrfToken,
                        'return' => 'order-confirmation.php',
                        'proof_endpoint' => 'payment-proof.php',
                        'flash' => $gcashFlash,
                        'flash_message' => $gcashFlashMessage,
                        'modal' => true,
                    ];
                    require __DIR__ . '/../includes/ui.gcash-panel.php';
                    ?>
                <?php endif; ?>

            </div>

            <aside class="confirmation-aside" aria-label="Order actions">
                <div class="confirmation-aside__inner">
                    <p class="confirmation-aside__label">
                        <?= hopia_confirmation_icon('receipt') ?>
                        <span>Order number</span>
                    </p>
                    <p class="confirmation-aside__number">#<?= hopia_e($order['id']) ?></p>
                    <a class="btn btn-primary btn-block" href="orders.php">
                        <?= hopia_confirmation_icon('view') ?>
                        <span>VIEW ORDER</span>
                    </a>
                    <a class="btn btn-secondary btn-block" href="products.php">
                        <?= hopia_confirmation_icon('shop') ?>
                        <span>CONTINUE SHOPPING</span>
                    </a>
                </div>
            </aside>
        </div>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
