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

$areas = [
    'Metro Manila',
    'North Luzon',
    'South Luzon',
    'Visayas',
    'Mindanao'
];

$formValues = [
    'shipping_name' => '',
    'shipping_phone' => '',
    'area' => '',
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
        'area' => 'Area',
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

    if (!in_array($formValues['area'], $areas, true)) {
        $errors[] = 'Please select a valid area.';
    }

    if ($formValues['payment_method'] !== 'COD') {
        $errors[] = 'Cash on Delivery is the only available payment method in V1.';
    }

    if (count($errors) === 0) {
        $message = 'Checkout details are valid and ready. Actual order placement will be connected in Part 3.';
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
        'SELECT ci.id AS cart_item_id, p.id, p.name, p.size, p.color, p.price, pi.image_path
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

$addressParts = [
    $formValues['house_unit_building'],
    $formValues['street'],
    $formValues['barangay'],
    $formValues['city_municipality'],
    $formValues['province'],
    $formValues['area']
];
$addressParts = array_values(array_filter($addressParts, static function ($part) {
    return $part !== '';
}));
$addressPreview = implode(', ', $addressParts);

function checkoutValue(array $values, string $field): string
{
    return htmlspecialchars($values[$field] ?? '', ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Hopia's Ukay-Ukay</title>

    <style>
        .checkout-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 420px);
            gap: 24px;
            align-items: start;
            margin-top: 16px;
        }

        .checkout-section,
        .order-summary {
            border: 1px solid #ccc;
            padding: 16px;
        }

        .checkout-section + .checkout-section {
            margin-top: 16px;
        }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .field-wide {
            grid-column: 1 / -1;
        }

        input,
        select {
            box-sizing: border-box;
            max-width: 100%;
            padding: 8px;
            font: inherit;
        }

        .required {
            color: #a00;
        }

        .payment-option {
            display: block;
            margin: 10px 0;
        }

        .payment-option input {
            margin-right: 8px;
        }

        .payment-option.disabled {
            color: #777;
        }

        .address-preview {
            background: #f7f7f7;
            border: 1px solid #ddd;
            margin-top: 16px;
            padding: 10px;
        }

        .notice {
            border: 1px solid #ccc;
            margin: 16px 0;
            padding: 12px;
        }

        .notice-error {
            border-color: #a00;
            color: #800;
        }

        .notice-success {
            border-color: #176b2c;
            color: #176b2c;
        }

        .errors {
            margin: 0;
            padding-left: 20px;
        }

        .summary-item {
            border-bottom: 1px solid #ddd;
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr);
            gap: 10px;
            padding: 12px 0;
        }

        .summary-item:first-child {
            padding-top: 0;
        }

        .summary-item img,
        .image-placeholder {
            display: block;
            width: 72px;
            height: 88px;
            object-fit: cover;
            background: #f0f0f0;
        }

        .image-placeholder {
            align-items: center;
            display: flex;
            justify-content: center;
            color: #888;
            font-size: 0.85rem;
            text-align: center;
        }

        .summary-item p {
            margin: 0 0 4px;
        }

        .summary-name {
            font-weight: bold;
        }

        .summary-price {
            white-space: nowrap;
        }

        .summary-total {
            border-top: 1px solid #ccc;
            margin-top: 16px;
            padding-top: 12px;
        }

        .summary-total p {
            margin: 8px 0;
        }

        .summary-subtotal {
            font-weight: bold;
        }

        .place-order {
            margin-top: 16px;
            padding: 10px 16px;
        }

        @media (max-width: 700px) {
            .checkout-layout,
            .field-grid {
                grid-template-columns: 1fr;
            }

            .field-wide {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>

    <h1>Hopia's Ukay-Ukay</h1>

    <p>
        <a href="cart.php">&larr; Back to Cart</a>
    </p>

    <hr>

    <h2>Checkout</h2>

    <?php if (count($cartItems) === 0): ?>

        <p>Your cart is empty. Add an item before continuing to checkout.</p>

        <p>
            <a href="cart.php">View Cart</a>
            |
            <a href="products.php">Browse Products</a>
        </p>

    <?php else: ?>

        <?php if (count($errors) > 0): ?>
            <div class="notice notice-error" role="alert">
                <strong>Please correct the following:</strong>
                <ul class="errors">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($message !== ''): ?>
            <p class="notice notice-success" role="status">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <div class="checkout-layout">
            <form method="POST" action="checkout.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <section class="checkout-section" aria-labelledby="customer-information-heading">
                    <h3 id="customer-information-heading">Customer Information</h3>

                    <div class="field-grid">
                        <div class="field field-wide">
                            <label for="shipping_name">Full Name <span class="required">*</span></label>
                            <input
                                type="text"
                                id="shipping_name"
                                name="shipping_name"
                                value="<?= checkoutValue($formValues, 'shipping_name') ?>"
                                maxlength="150"
                                required
                            >
                        </div>

                        <div class="field field-wide">
                            <label for="shipping_phone">Phone Number <span class="required">*</span></label>
                            <input
                                type="text"
                                id="shipping_phone"
                                name="shipping_phone"
                                value="<?= checkoutValue($formValues, 'shipping_phone') ?>"
                                maxlength="30"
                                required
                            >
                        </div>
                    </div>
                </section>

                <section class="checkout-section" aria-labelledby="delivery-address-heading">
                    <h3 id="delivery-address-heading">Delivery Address</h3>
                    <p>Provide enough detail for delivery. The address will be saved as one text value when order placement is implemented.</p>

                    <div class="field-grid">
                        <div class="field">
                            <label for="area">Area <span class="required">*</span></label>
                            <select id="area" name="area" required>
                                <option value="">Select area</option>
                                <?php foreach ($areas as $area): ?>
                                    <option value="<?= htmlspecialchars($area, ENT_QUOTES, 'UTF-8') ?>" <?= $formValues['area'] === $area ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($area, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="province">Province <span class="required">*</span></label>
                            <input type="text" id="province" name="province" value="<?= checkoutValue($formValues, 'province') ?>" maxlength="100" required>
                        </div>

                        <div class="field">
                            <label for="city_municipality">City/Municipality <span class="required">*</span></label>
                            <input type="text" id="city_municipality" name="city_municipality" value="<?= checkoutValue($formValues, 'city_municipality') ?>" maxlength="100" required>
                        </div>

                        <div class="field">
                            <label for="barangay">Barangay <span class="required">*</span></label>
                            <input type="text" id="barangay" name="barangay" value="<?= checkoutValue($formValues, 'barangay') ?>" maxlength="100" required>
                        </div>

                        <div class="field">
                            <label for="house_unit_building">House/Unit/Building <span class="required">*</span></label>
                            <input type="text" id="house_unit_building" name="house_unit_building" value="<?= checkoutValue($formValues, 'house_unit_building') ?>" maxlength="150" required>
                        </div>

                        <div class="field">
                            <label for="street">Street <span class="required">*</span></label>
                            <input type="text" id="street" name="street" value="<?= checkoutValue($formValues, 'street') ?>" maxlength="150" required>
                        </div>
                    </div>

                    <?php if ($addressPreview !== ''): ?>
                        <p class="address-preview">
                            <strong>Address preview:</strong>
                            <?= htmlspecialchars($addressPreview, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>
                </section>

                <section class="checkout-section" aria-labelledby="payment-method-heading">
                    <h3 id="payment-method-heading">Payment Method</h3>

                    <label class="payment-option">
                        <input
                            type="radio"
                            name="payment_method"
                            value="COD"
                            <?= $formValues['payment_method'] === 'COD' ? 'checked' : '' ?>
                        >
                        Cash on Delivery
                    </label>

                    <label class="payment-option disabled">
                        <input type="radio" name="payment_method" value="GCASH" disabled>
                        GCash &mdash; Coming Soon
                    </label>

                    <button class="place-order" type="submit">Place Order</button>
                </section>
            </form>

            <section class="order-summary" aria-labelledby="order-summary-heading">
                <h3 id="order-summary-heading">Order Summary</h3>

                <?php foreach ($cartItems as $item): ?>
                    <?php
                    $imagePath = trim($item['image_path'] ?? '');
                    $size = trim($item['size'] ?? '');
                    $color = trim($item['color'] ?? '');
                    $unitPrice = (float) $item['price'];
                    ?>
                    <article class="summary-item">
                        <?php if ($imagePath !== ''): ?>
                            <img
                                src="<?= htmlspecialchars('../' . ltrim($imagePath, '/'), ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>"
                            >
                        <?php else: ?>
                            <div class="image-placeholder">No image</div>
                        <?php endif; ?>

                        <div>
                            <p class="summary-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if ($size !== ''): ?>
                                <p>Size: <?= htmlspecialchars($size, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <?php if ($color !== ''): ?>
                                <p>Color: <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <p>Unit price: <span class="summary-price">₱<?= number_format($unitPrice, 2) ?></span></p>
                            <p>Quantity: 1</p>
                            <p>Item subtotal: <span class="summary-price">₱<?= number_format($unitPrice, 2) ?></span></p>
                        </div>
                    </article>
                <?php endforeach; ?>

                <div class="summary-total">
                    <p class="summary-subtotal">Subtotal: ₱<?= number_format($subtotal, 2) ?></p>
                    <p>Shipping: To be confirmed</p>
                    <p>Total: Subtotal + shipping to be confirmed</p>
                </div>
            </section>
        </div>

    <?php endif; ?>

</body>
</html>
