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
$errors = [];
$message = '';

$regionNames = [
    'National Capital Region',
    'Cordillera Administrative Region',
    'Ilocos Region',
    'Cagayan Valley',
    'Central Luzon',
    'Calabarzon',
    'Mimaropa',
    'Bicol Region',
    'Western Visayas',
    'Central Visayas',
    'Eastern Visayas',
    'Zamboanga Peninsula',
    'Northern Mindanao',
    'Davao Region',
    'Soccsksargen',
    'Caraga',
    'Bangsamoro Autonomous Region'
];

$formValues = [
    'shipping_name' => '',
    'shipping_phone' => '',
    'region' => '',
    'province' => '',
    'city_municipality' => '',
    'barangay' => '',
    'house_unit_building' => '',
    'street' => '',
    'payment_method' => 'COD'
];

/* Load account defaults without changing the customer's saved account. */
$customerStmt = $pdo->prepare(
    'SELECT first_name, last_name, phone
     FROM customers
     WHERE id = :customer_id
     LIMIT 1'
);
$customerStmt->execute([':customer_id' => $customerId]);
$customer = $customerStmt->fetch();

if ($customer !== false) {
    $formValues['shipping_name'] = trim(
        ($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')
    );
    $formValues['shipping_phone'] = trim($customer['phone'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($formValues) as $field) {
        $formValues[$field] = isset($_POST[$field]) && is_string($_POST[$field])
            ? trim($_POST[$field])
            : '';
    }

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        $submittedToken === '' ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        $errors[] = 'Your form session has expired. Please submit the form again.';
    }

    if ($formValues['shipping_name'] === '') {
        $errors[] = 'Full name is required.';
    }

    if ($formValues['shipping_phone'] === '') {
        $errors[] = 'Phone number is required.';
    }

    $requiredAddressFields = [
        'region' => 'Region',
        'province' => 'Province',
        'city_municipality' => 'City/Municipality',
        'barangay' => 'Barangay',
        'house_unit_building' => 'House/Unit/Building',
        'street' => 'Street'
    ];

    foreach ($requiredAddressFields as $field => $label) {
        if ($formValues[$field] === '') {
            $errors[] = $label . ' is required.';
        }
    }

    $fieldLimits = [
        'shipping_name' => 150,
        'shipping_phone' => 30,
        'region' => 100,
        'province' => 100,
        'city_municipality' => 100,
        'barangay' => 100,
        'house_unit_building' => 150,
        'street' => 150
    ];

    foreach ($fieldLimits as $field => $maxLength) {
        if (strlen($formValues[$field]) > $maxLength) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is too long.';
        }
    }

    if (!in_array($formValues['region'], $regionNames, true)) {
        $errors[] = 'Please select a valid region.';
    }

    if ($formValues['payment_method'] !== 'COD') {
        $errors[] = 'Cash on Delivery is the only available payment method in V1.';
    }

    if (count($errors) === 0) {
        try {
            $pdo->beginTransaction();

            /* Re-fetch the cart from the authenticated customer, never from POST data. */
            $transactionCartStmt = $pdo->prepare(
                'SELECT id
                 FROM carts
                 WHERE customer_id = :customer_id
                 LIMIT 1'
            );
            $transactionCartStmt->execute([':customer_id' => $customerId]);
            $transactionCart = $transactionCartStmt->fetch();

            if ($transactionCart === false) {
                $pdo->rollBack();
                $errors[] = 'Your cart is empty. Please add an item before checking out.';
            } else {
                /* Lock every product in the cart before checking availability or pricing. */
                $lockedItemsStmt = $pdo->prepare(
                    'SELECT p.id AS product_id, p.name, p.size, p.color, p.price, p.status
                     FROM cart_items ci
                     INNER JOIN products p ON p.id = ci.product_id
                     WHERE ci.cart_id = :cart_id
                     ORDER BY ci.created_at ASC, ci.id ASC
                     FOR UPDATE'
                );
                $lockedItemsStmt->execute([':cart_id' => (int) $transactionCart['id']]);
                $lockedItems = $lockedItemsStmt->fetchAll();

                if (count($lockedItems) === 0) {
                    $pdo->rollBack();
                    $errors[] = 'Your cart is empty. Please add an item before checking out.';
                } else {
                    $unavailable = false;

                    foreach ($lockedItems as $lockedItem) {
                        if ($lockedItem['status'] !== 'AVAILABLE') {
                            $unavailable = true;
                            break;
                        }
                    }

                    if ($unavailable) {
                        $pdo->rollBack();
                        $errors[] = 'One or more items in your cart are no longer available. Please return to your cart and review the items.';
                    } else {
                        $subtotalCents = 0;

                        foreach ($lockedItems as $lockedItem) {
                            $subtotalCents += (int) round((float) $lockedItem['price'] * 100);
                        }

                        $subtotalAmount = number_format($subtotalCents / 100, 2, '.', '');
                        $shippingFee = '0.00';
                        $totalAmount = number_format($subtotalCents / 100, 2, '.', '');
                        $shippingAddress = implode(', ', [
                            $formValues['region'],
                            $formValues['province'],
                            $formValues['city_municipality'],
                            $formValues['barangay'],
                            $formValues['house_unit_building'],
                            $formValues['street']
                        ]);

                        $orderStmt = $pdo->prepare(
                            'INSERT INTO orders (
                                customer_id,
                                status,
                                cancellation_status,
                                subtotal,
                                shipping_fee,
                                total_amount,
                                shipping_name,
                                shipping_phone,
                                shipping_address
                             ) VALUES (
                                :customer_id,
                                :status,
                                :cancellation_status,
                                :subtotal,
                                :shipping_fee,
                                :total_amount,
                                :shipping_name,
                                :shipping_phone,
                                :shipping_address
                             )'
                        );
                        $orderStmt->execute([
                            ':customer_id' => $customerId,
                            ':status' => 'PENDING',
                            ':cancellation_status' => 'NONE',
                            ':subtotal' => $subtotalAmount,
                            ':shipping_fee' => $shippingFee,
                            ':total_amount' => $totalAmount,
                            ':shipping_name' => $formValues['shipping_name'],
                            ':shipping_phone' => $formValues['shipping_phone'],
                            ':shipping_address' => $shippingAddress
                        ]);
                        $orderId = (int) $pdo->lastInsertId();

                        $orderItemStmt = $pdo->prepare(
                            'INSERT INTO order_items (
                                order_id,
                                product_id,
                                product_name,
                                size,
                                color,
                                unit_price,
                                quantity,
                                subtotal
                             ) VALUES (
                                :order_id,
                                :product_id,
                                :product_name,
                                :size,
                                :color,
                                :unit_price,
                                :quantity,
                                :subtotal
                             )'
                        );

                        foreach ($lockedItems as $lockedItem) {
                            $unitPrice = number_format((float) $lockedItem['price'], 2, '.', '');
                            $orderItemStmt->execute([
                                ':order_id' => $orderId,
                                ':product_id' => (int) $lockedItem['product_id'],
                                ':product_name' => $lockedItem['name'],
                                ':size' => $lockedItem['size'],
                                ':color' => $lockedItem['color'],
                                ':unit_price' => $unitPrice,
                                ':quantity' => 1,
                                ':subtotal' => $unitPrice
                            ]);
                        }

                        $paymentStmt = $pdo->prepare(
                            'INSERT INTO payments (
                                order_id,
                                payment_method,
                                payment_status
                             ) VALUES (
                                :order_id,
                                :payment_method,
                                :payment_status
                             )'
                        );
                        $paymentStmt->execute([
                            ':order_id' => $orderId,
                            ':payment_method' => 'COD',
                            ':payment_status' => 'PENDING'
                        ]);

                        $shipmentStmt = $pdo->prepare(
                            'INSERT INTO shipments (
                                order_id,
                                shipment_status,
                                tracking_number,
                                shipped_at,
                                delivered_at
                             ) VALUES (
                                :order_id,
                                :shipment_status,
                                NULL,
                                NULL,
                                NULL
                             )'
                        );
                        $shipmentStmt->execute([
                            ':order_id' => $orderId,
                            ':shipment_status' => 'NOT_SHIPPED'
                        ]);

                        $productIds = array_map(static function (array $item): int {
                            return (int) $item['product_id'];
                        }, $lockedItems);
                        $productPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
                        $soldProductsStmt = $pdo->prepare(
                            'UPDATE products
                             SET status = ?
                             WHERE status = ?
                               AND id IN (' . $productPlaceholders . ')'
                        );
                        $soldProductsStmt->execute(array_merge(
                            ['SOLD', 'AVAILABLE'],
                            $productIds
                        ));

                        if ($soldProductsStmt->rowCount() !== count($productIds)) {
                            throw new RuntimeException('The ordered products could not be updated.');
                        }

                        $cartItemIdsStmt = $pdo->prepare(
                            'SELECT ci.id
                             FROM cart_items ci
                             WHERE ci.cart_id = ?
                               AND ci.product_id IN (' . $productPlaceholders . ')
                             FOR UPDATE'
                        );
                        $cartItemIdsStmt->execute(array_merge(
                            [$transactionCart['id']],
                            $productIds
                        ));
                        $cartItemIds = $cartItemIdsStmt->fetchAll(PDO::FETCH_COLUMN);

                        if (count($cartItemIds) !== count($productIds)) {
                            throw new RuntimeException('The ordered cart items could not be found.');
                        }

                        $cartItemPlaceholders = implode(',', array_fill(0, count($cartItemIds), '?'));
                        $removeCartItemsStmt = $pdo->prepare(
                            'DELETE FROM cart_items
                             WHERE cart_id = ?
                               AND id IN (' . $cartItemPlaceholders . ')'
                        );
                        $removeCartItemsStmt->execute(array_merge(
                            [$transactionCart['id']],
                            $cartItemIds
                        ));

                        if ($removeCartItemsStmt->rowCount() !== count($cartItemIds)) {
                            throw new RuntimeException('The ordered cart items could not be removed.');
                        }

                        $pdo->commit();

                        header('Location: order-confirmation.php?id=' . $orderId);
                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'We could not place your order right now. Please try again.';
        }
    }
}

/* Retrieve only the products currently in this customer's cart. */
$cartStmt = $pdo->prepare(
    'SELECT id
     FROM carts
     WHERE customer_id = :customer_id
     LIMIT 1'
);
$cartStmt->execute([':customer_id' => $customerId]);
$cart = $cartStmt->fetch();

$cartItems = [];
$subtotal = 0.0;

if ($cart !== false) {
    $itemsStmt = $pdo->prepare(
        'SELECT ci.id AS cart_item_id, p.id, p.name, p.size, p.category, p.color, p.price, pi.image_path
         FROM cart_items ci
         INNER JOIN products p ON p.id = ci.product_id
         LEFT JOIN product_images pi
             ON pi.id = (
                 SELECT pi2.id
                 FROM product_images pi2
                 WHERE pi2.product_id = p.id
                 ORDER BY pi2.sort_order ASC, pi2.id ASC
                 LIMIT 1
             )
         WHERE ci.cart_id = :cart_id
         ORDER BY ci.created_at ASC, ci.id ASC'
    );
    $itemsStmt->execute([':cart_id' => (int) $cart['id']]);
    $cartItems = $itemsStmt->fetchAll();

    foreach ($cartItems as $item) {
        $subtotal += (float) $item['price'];
    }
}

$locationSummary = implode(', ', array_values(array_filter([
    $formValues['region'],
    $formValues['province'],
    $formValues['city_municipality'],
    $formValues['barangay']
], static function ($part) {
    return $part !== '';
})));

function checkoutValue(array $values, string $field): string
{
    return htmlspecialchars($values[$field] ?? '', ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/../includes/ui.php';

$page_title = 'Checkout - ' . hopia_site_name();

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<section class="checkout-page">
    <header class="checkout-header">
        <div>
            <h1>CHECKOUT</h1>
            <p class="checkout-header__count"><?= count($cartItems) ?> <?= count($cartItems) === 1 ? 'item' : 'items' ?> from your cart</p>
        </div>
        <a class="link-subtle checkout-header__back" href="cart.php">Back to cart</a>
    </header>

    <?php if (count($errors) > 0): ?>
        <div class="alert alert-error" role="alert">
            <strong>Please review the following:</strong>
            <ul class="alert__list">
                <?php foreach ($errors as $error): ?>
                    <li><?= hopia_e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <p class="alert alert-success" role="status"><?= hopia_e($message) ?></p>
    <?php endif; ?>

    <?php if (count($cartItems) === 0): ?>

        <div class="checkout-empty">
            <h2>Your cart is empty</h2>
            <p>Add an item before continuing to checkout.</p>
            <div class="checkout-empty__actions">
                <a class="btn btn-primary" href="products.php">SHOP ALL FINDS</a>
                <a class="btn btn-ghost" href="cart.php">View cart</a>
            </div>
        </div>

    <?php else: ?>

        <div class="checkout-layout">
            <form id="checkout-form" class="checkout-form" method="POST" action="checkout.php">
                <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">

                <section class="checkout-card" aria-labelledby="checkout-contact-heading">
                    <div class="checkout-card__head">
                        <h2 class="checkout-card__title" id="checkout-contact-heading">Contact information</h2>
                        <p class="checkout-card__hint">Prefilled from your account. You can edit it for this order.</p>
                    </div>

                    <div class="field">
                        <label for="shipping_name">Full name</label>
                        <input
                            type="text"
                            id="shipping_name"
                            name="shipping_name"
                            value="<?= checkoutValue($formValues, 'shipping_name') ?>"
                            maxlength="150"
                            autocomplete="name"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="shipping_phone">Phone number</label>
                        <input
                            type="text"
                            id="shipping_phone"
                            name="shipping_phone"
                            value="<?= checkoutValue($formValues, 'shipping_phone') ?>"
                            maxlength="30"
                            inputmode="tel"
                            autocomplete="tel"
                            required
                        >
                    </div>
                </section>

                <section class="checkout-card" aria-labelledby="checkout-address-heading">
                    <div class="checkout-card__head">
                        <h2 class="checkout-card__title" id="checkout-address-heading">Delivery address</h2>
                        <p class="checkout-card__hint">Choose your location, then add the street details.</p>
                    </div>

                    <div class="field">
                        <span class="field-label" id="checkout-location-label">Location</span>

                        <input type="hidden" name="region" id="checkout-region" value="<?= checkoutValue($formValues, 'region') ?>">
                        <input type="hidden" name="province" id="checkout-province" value="<?= checkoutValue($formValues, 'province') ?>">
                        <input type="hidden" name="city_municipality" id="checkout-city" value="<?= checkoutValue($formValues, 'city_municipality') ?>">
                        <input type="hidden" name="barangay" id="checkout-barangay" value="<?= checkoutValue($formValues, 'barangay') ?>">

                        <button
                            type="button"
                            class="location-trigger"
                            id="location-trigger"
                            aria-haspopup="dialog"
                            aria-labelledby="checkout-location-label"
                        >
                            <span class="location-trigger__icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
                                    <circle cx="12" cy="10" r="3"></circle>
                                </svg>
                            </span>
                            <span class="location-trigger__text<?= $locationSummary === '' ? ' is-placeholder' : '' ?>" id="location-trigger-text"><?= $locationSummary === '' ? 'Select region, province, city/municipality, barangay' : hopia_e($locationSummary) ?></span>
                            <span class="location-trigger__chevron" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m9 18 6-6-6-6"></path>
                                </svg>
                            </span>
                        </button>

                        <p class="hint">Region, province, city/municipality, then barangay.</p>
                        <p class="field-error" id="location-error" hidden>Please select your complete location.</p>
                    </div>

                    <div class="checkout-grid">
                        <div class="field">
                            <label for="house_unit_building">House / Unit / Building</label>
                            <input
                                type="text"
                                id="house_unit_building"
                                name="house_unit_building"
                                value="<?= checkoutValue($formValues, 'house_unit_building') ?>"
                                maxlength="150"
                                autocomplete="address-line1"
                                required
                            >
                        </div>

                        <div class="field">
                            <label for="street">Street</label>
                            <input
                                type="text"
                                id="street"
                                name="street"
                                value="<?= checkoutValue($formValues, 'street') ?>"
                                maxlength="150"
                                autocomplete="address-line2"
                                required
                            >
                        </div>
                    </div>
                </section>

                <section class="checkout-card" aria-labelledby="checkout-payment-heading">
                    <div class="checkout-card__head">
                        <h2 class="checkout-card__title" id="checkout-payment-heading">Payment method</h2>
                    </div>

                    <div class="payment-options">
                        <label class="payment-option">
                            <input
                                type="radio"
                                name="payment_method"
                                value="COD"
                                <?= $formValues['payment_method'] === 'COD' ? 'checked' : '' ?>
                            >
                            <span class="payment-option__body">
                                <span class="payment-option__name">Cash on Delivery</span>
                                <span class="payment-option__desc">Pay in cash when your order arrives.</span>
                            </span>
                        </label>

                        <label class="payment-option is-disabled" aria-disabled="true">
                            <input type="radio" name="payment_method" value="GCASH" disabled>
                            <span class="payment-option__body">
                                <span class="payment-option__name">GCash <span class="badge badge-pending">Coming Soon</span></span>
                                <span class="payment-option__desc">Not available yet.</span>
                            </span>
                        </label>
                    </div>
                </section>

                <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Place order</button>
            </form>

            <aside class="checkout-summary" aria-labelledby="checkout-summary-heading">
                <div class="checkout-summary__inner">
                    <h2 class="checkout-summary__title" id="checkout-summary-heading">Order summary</h2>

                    <ul class="checkout-summary__items" role="list">
                        <?php foreach ($cartItems as $item): ?>
                            <?php
                            $imagePath = trim($item['image_path'] ?? '');
                            $size = trim($item['size'] ?? '');
                            $color = trim($item['color'] ?? '');
                            $category = trim($item['category'] ?? '');
                            $unitPrice = (float) $item['price'];
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
                            <li class="checkout-summary__item">
                                <div class="checkout-summary__image">
                                    <?php if ($imagePath !== ''): ?>
                                        <img
                                            src="<?= hopia_e('../' . ltrim($imagePath, '/')) ?>"
                                            alt="<?= hopia_e($item['name']) ?>"
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <div class="checkout-summary__placeholder">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                                <polyline points="21 15 16 10 5 21"></polyline>
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="checkout-summary__details">
                                    <p class="checkout-summary__name"><?= hopia_e($item['name']) ?></p>
                                    <?php if (count($metaParts) > 0): ?>
                                        <p class="checkout-summary__meta"><?= hopia_e(implode(' · ', $metaParts)) ?></p>
                                    <?php endif; ?>
                                    <p class="price checkout-summary__price">₱<?= number_format($unitPrice, 2) ?></p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="checkout-summary__totals">
                        <div class="checkout-summary__row">
                            <span>Order total before shipping</span>
                            <span class="price">₱<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="checkout-summary__row checkout-summary__row--muted">
                            <span>Shipping</span>
                            <span>To be confirmed</span>
                        </div>
                    </div>

                    <div class="checkout-summary__actions">
                        <button
                            type="submit"
                            form="checkout-form"
                            class="btn btn-primary btn-block checkout-submit"
                            id="checkout-submit"
                        >
                            <span class="checkout-submit__spinner" aria-hidden="true"></span>
                            <span class="checkout-submit__label">PLACE ORDER</span>
                        </button>

                        <a class="btn btn-ghost btn-block" href="cart.php">Back to cart</a>
                    </div>
                </div>
            </aside>
        </div>

        <div class="location-modal" id="location-modal" hidden>
            <div class="location-modal__backdrop" data-location-close></div>

            <div class="location-modal__panel" role="dialog" aria-modal="true" aria-labelledby="location-modal-title">
                <div class="location-modal__head">
                    <button type="button" class="location-modal__back" id="location-back" aria-label="Back to previous level" hidden>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m15 18-6-6 6-6"></path>
                        </svg>
                    </button>
                    <h2 class="location-modal__title" id="location-modal-title">Select location</h2>
                    <button type="button" class="location-modal__close" id="location-close" aria-label="Close location selector">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 6 6 18"></path>
                            <path d="M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <ol class="location-steps" id="location-steps"></ol>

                <div class="location-modal__search" id="location-search" hidden>
                    <input
                        type="search"
                        id="location-search-input"
                        placeholder="Search"
                        autocomplete="off"
                        aria-label="Filter list"
                    >
                </div>

                <p class="location-modal__status" id="location-status">Loading locations&hellip;</p>
                <ul class="location-list" id="location-list" role="list"></ul>
            </div>
        </div>

        <script>
        (function () {
            'use strict';

            var modal = document.getElementById('location-modal');

            if (!modal) {
                return;
            }

            var dataUrl = <?= json_encode(hopia_asset('data/ph-locations.json')) ?>;
            var levelOrder = ['region', 'province', 'city', 'barangay'];
            var levelLabels = {
                region: 'Region',
                province: 'Province',
                city: 'City / Municipality',
                barangay: 'Barangay'
            };
            var fields = {
                region: document.getElementById('checkout-region'),
                province: document.getElementById('checkout-province'),
                city: document.getElementById('checkout-city'),
                barangay: document.getElementById('checkout-barangay')
            };
            var selection = { region: '', province: '', city: '', barangay: '' };
            var trigger = document.getElementById('location-trigger');
            var triggerText = document.getElementById('location-trigger-text');
            var errorText = document.getElementById('location-error');
            var stepsEl = document.getElementById('location-steps');
            var listEl = document.getElementById('location-list');
            var statusEl = document.getElementById('location-status');
            var searchWrap = document.getElementById('location-search');
            var searchInput = document.getElementById('location-search-input');
            var backBtn = document.getElementById('location-back');
            var closeBtn = document.getElementById('location-close');
            var panel = modal.querySelector('.location-modal__panel');
            var form = document.getElementById('checkout-form');
            var submitBtn = document.getElementById('checkout-submit');
            var locations = null;
            var loadPromise = null;
            var level = 'region';
            var lastFocused = null;

            levelOrder.forEach(function (name) {
                var field = fields[name];
                selection[name] = field && typeof field.value === 'string' ? field.value : '';
            });

            function childrenFor(levelName) {
                if (!locations) {
                    return [];
                }

                if (levelName === 'region') {
                    return Object.keys(locations);
                }

                var region = locations[selection.region];

                if (!selection.region || !region) {
                    return [];
                }

                if (levelName === 'province') {
                    return Object.keys(region);
                }

                var province = region[selection.province];

                if (!selection.province || !province) {
                    return [];
                }

                if (levelName === 'city') {
                    return Object.keys(province);
                }

                var city = province[selection.city];

                if (!selection.city || !city) {
                    return [];
                }

                return city;
            }

            function syncFields() {
                var complete = true;

                levelOrder.forEach(function (name) {
                    if (fields[name]) {
                        fields[name].value = selection[name];
                    }

                    if (selection[name] === '') {
                        complete = false;
                    }
                });

                var text = levelOrder
                    .map(function (name) { return selection[name]; })
                    .filter(function (value) { return value !== ''; })
                    .join(', ');

                if (triggerText) {
                    triggerText.textContent = text === ''
                        ? 'Select region, province, city/municipality, barangay'
                        : text;
                    triggerText.classList.toggle('is-placeholder', text === '');
                }

                if (complete && errorText) {
                    errorText.hidden = true;
                }

                return complete;
            }

            function renderSteps() {
                stepsEl.textContent = '';

                levelOrder.forEach(function (name, index) {
                    var reachable = index === 0 || selection[levelOrder[index - 1]] !== '';
                    var item = document.createElement('li');
                    var button = document.createElement('button');

                    item.className = 'location-step';

                    if (name === level) {
                        item.className += ' is-current';
                    } else if (selection[name] !== '') {
                        item.className += ' is-done';
                    }

                    button.type = 'button';
                    button.className = 'location-step__button';
                    button.textContent = selection[name] !== '' ? selection[name] : levelLabels[name];
                    button.disabled = !reachable;
                    button.addEventListener('click', function () {
                        if (button.disabled) {
                            return;
                        }

                        level = name;
                        refresh();
                    });

                    item.appendChild(button);
                    stepsEl.appendChild(item);
                });
            }

            function renderList() {
                var items = childrenFor(level);
                var query = searchWrap && !searchWrap.hidden && searchInput
                    ? searchInput.value.trim().toLowerCase()
                    : '';
                var filtered = items.filter(function (item) {
                    return query === '' || item.toLowerCase().indexOf(query) !== -1;
                });

                listEl.textContent = '';

                if (filtered.length === 0) {
                    var empty = document.createElement('li');
                    empty.className = 'location-list__empty';
                    empty.textContent = items.length === 0 ? 'No options available.' : 'No matches found.';
                    listEl.appendChild(empty);
                    return;
                }

                filtered.forEach(function (item) {
                    var entry = document.createElement('li');
                    var button = document.createElement('button');

                    button.type = 'button';
                    button.className = 'location-list__button';

                    if (level !== 'barangay') {
                        button.className += ' has-children';
                    }

                    if (selection[level] === item) {
                        button.className += ' is-selected';
                    }

                    button.textContent = item;
                    button.addEventListener('click', function () {
                        choose(item);
                    });

                    entry.appendChild(button);
                    listEl.appendChild(entry);
                });
            }

            function refresh() {
                if (!locations) {
                    return;
                }

                renderSteps();

                var items = childrenFor(level);

                if (searchWrap) {
                    searchWrap.hidden = items.length <= 10;
                }

                if (searchInput) {
                    searchInput.value = '';
                    searchInput.placeholder = 'Search ' + levelLabels[level].toLowerCase();
                }

                if (backBtn) {
                    backBtn.hidden = levelOrder.indexOf(level) === 0;
                }

                if (items.length === 0) {
                    statusEl.hidden = false;
                    statusEl.textContent = 'Select the previous level first.';
                    listEl.textContent = '';
                    return;
                }

                statusEl.hidden = true;
                renderList();
            }

            function choose(item) {
                var index = levelOrder.indexOf(level);

                selection[level] = item;

                for (var i = index + 1; i < levelOrder.length; i += 1) {
                    selection[levelOrder[i]] = '';
                }

                if (index < levelOrder.length - 1) {
                    level = levelOrder[index + 1];
                    refresh();
                    return;
                }

                syncFields();
                closeModal();
            }

            function loadLocations() {
                if (locations) {
                    return Promise.resolve(locations);
                }

                if (loadPromise) {
                    return loadPromise;
                }

                loadPromise = fetch(dataUrl, { credentials: 'same-origin' })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Request failed');
                        }

                        return response.json();
                    })
                    .then(function (json) {
                        locations = json;
                        return locations;
                    })
                    .catch(function (error) {
                        loadPromise = null;
                        throw error;
                    });

                return loadPromise;
            }

            function openModal() {
                lastFocused = document.activeElement;
                modal.hidden = false;
                document.body.classList.add('is-modal-open');

                level = levelOrder[0];

                var firstEmpty = null;

                levelOrder.some(function (name) {
                    if (selection[name] === '') {
                        firstEmpty = name;
                        return true;
                    }

                    return false;
                });

                level = firstEmpty !== null ? firstEmpty : levelOrder[levelOrder.length - 1];

                statusEl.hidden = false;
                statusEl.textContent = 'Loading locations\u2026';
                listEl.textContent = '';
                stepsEl.textContent = '';

                if (searchWrap) {
                    searchWrap.hidden = true;
                }

                if (searchInput) {
                    searchInput.value = '';
                }

                if (backBtn) {
                    backBtn.hidden = true;
                }

                if (closeBtn) {
                    closeBtn.focus();
                }

                loadLocations()
                    .then(function () {
                        refresh();
                    })
                    .catch(function () {
                        statusEl.hidden = false;
                        statusEl.textContent = 'We could not load the location list. Please try again.';
                        listEl.textContent = '';
                    });
            }

            function closeModal() {
                modal.hidden = true;
                document.body.classList.remove('is-modal-open');

                if (lastFocused && typeof lastFocused.focus === 'function') {
                    lastFocused.focus();
                }
            }

            trigger.addEventListener('click', openModal);
            closeBtn.addEventListener('click', closeModal);

            backBtn.addEventListener('click', function () {
                var index = levelOrder.indexOf(level);

                if (index > 0) {
                    level = levelOrder[index - 1];
                    refresh();
                }
            });

            modal.addEventListener('click', function (event) {
                if (event.target.hasAttribute('data-location-close')) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (modal.hidden) {
                    return;
                }

                if (event.key === 'Escape') {
                    closeModal();
                    return;
                }

                if (event.key !== 'Tab') {
                    return;
                }

                var focusables = panel.querySelectorAll('button:not([hidden]):not([disabled]), input, [href]');

                if (focusables.length === 0) {
                    return;
                }

                var first = focusables[0];
                var last = focusables[focusables.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    renderList();
                });
            }

            form.addEventListener('submit', function (event) {
                var complete = levelOrder.every(function (name) {
                    return selection[name] !== '';
                });

                if (!complete) {
                    event.preventDefault();

                    if (errorText) {
                        errorText.hidden = false;
                    }

                    openModal();
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.classList.add('is-loading');
                submitBtn.setAttribute('aria-busy', 'true');

                var label = submitBtn.querySelector('.checkout-submit__label');

                if (label) {
                    label.textContent = 'PLACING ORDER\u2026';
                }
            });

            window.addEventListener('pageshow', function () {
                var label = submitBtn.querySelector('.checkout-submit__label');

                submitBtn.disabled = false;
                submitBtn.classList.remove('is-loading');
                submitBtn.removeAttribute('aria-busy');

                if (label) {
                    label.textContent = 'PLACE ORDER';
                }
            });
        })();
        </script>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
