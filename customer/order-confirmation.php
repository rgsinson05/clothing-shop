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
 * Validate the requested order ID.
 */

$requestedOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$order = false;
$orderItems = [];
$payment = false;

if ($requestedOrderId !== null && $requestedOrderId !== false && $requestedOrderId > 0) {

    $orderStmt = $pdo->prepare(
        'SELECT id, subtotal, shipping_fee, total_amount
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

        $paymentStmt = $pdo->prepare(
            'SELECT payment_method, payment_status
             FROM payments
             WHERE order_id = :order_id
             LIMIT 1'
        );

        $paymentStmt->execute([':order_id' => $orderId]);

        $payment = $paymentStmt->fetch();
    }
}

$paymentMethodLabel = ($payment !== false && $payment['payment_method'] !== null)
    ? ($payment['payment_method'] === 'COD' ? 'Cash on Delivery'
       : ($payment['payment_method'] === 'GCASH' ? 'GCash' : 'Card'))
    : '';

$paymentStatusLabel = ($payment !== false && $payment['payment_status'] !== null)
    ? ($payment['payment_status'] === 'PENDING' ? 'Pending'
       : ($payment['payment_status'] === 'PAID' ? 'Paid'
       : ($payment['payment_status'] === 'FAILED' ? 'Failed' : 'Refunded')))
    : '';

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
                        <p class="confirmation-card__eyebrow">YOUR PURCHASE</p>
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
                </section>

                <section class="confirmation-card" aria-labelledby="payment-heading">
                    <div class="confirmation-card__head">
                        <p class="confirmation-card__eyebrow">HOW YOU WILL PAY</p>
                        <h2 id="payment-heading">Payment</h2>
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
