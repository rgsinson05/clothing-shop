<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/shipping.php';
require_once __DIR__ . '/../includes/gcash.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/admin_notifications.php';

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

/*
 * GCash availability. GCash is only ever offered when the shop's real
 * receiving account is configured (includes/gcash.php reads it from the
 * environment). When it is not configured the option is withheld entirely
 * and a GCASH submission is rejected server-side, so a customer can never be
 * shown a placeholder/misleading destination.
 */
$gcashConfig = gcash_config();
$gcashConfigured = $gcashConfig['configured'];

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
    'delivery_method' => 'LOCAL_COURIER',
    'payment_method' => 'COD',
    'gcash_reference' => ''
];

/*
 * Delivery options available at checkout in this phase.
 *
 * LOCAL_COURIER is the default. STORE_PICKUP hides the delivery
 * address and charges zero shipping. Any other submitted value is
 * rejected server-side instead of being trusted or silently
 * rewritten. The list mirrors shipping_fulfillment_methods().
 */
$deliveryOptions = shipping_fulfillment_methods();

$pickupDetails = shipping_pickup_details();

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
        if ($field === 'delivery_method') {
            // Preserve invalid/missing input as empty so fulfillment validation
            // rejects it instead of silently creating a Local Courier order.
            $submittedDeliveryMethod = $_POST[$field] ?? '';

            $formValues[$field] = is_string($submittedDeliveryMethod)
                && isset($deliveryOptions[$submittedDeliveryMethod])
                    ? $submittedDeliveryMethod
                    : '';

            continue;
        }

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

    /*
     * Delivery address fields are required ONLY for Local Courier.
     * Store Pickup never asks for a delivery address: hidden values
     * must not bypass validation, and saved addresses are untouched.
     */
    $isPickupOrder = $formValues['delivery_method'] === 'STORE_PICKUP';

    $requiredAddressFields = [
        'region' => 'Region',
        'province' => 'Province',
        'city_municipality' => 'City/Municipality',
        'barangay' => 'Barangay',
        'house_unit_building' => 'House/Unit/Building',
        'street' => 'Street'
    ];

    if (!$isPickupOrder) {
        foreach ($requiredAddressFields as $field => $label) {
            if ($formValues[$field] === '') {
                $errors[] = $label . ' is required.';
            }
        }

        if (!in_array($formValues['region'], $regionNames, true)) {
            $errors[] = 'Please select a valid region.';
        }
    }

    /*
     * Resolve the fulfillment method and the authoritative shipping
     * fee on the server. Client-submitted fees or totals are never
     * read; the fee below is the only one stored.
     */
    $fulfillment = shipping_resolve_fulfillment(
        $formValues['delivery_method'],
        $formValues['region']
    );

    if (!$fulfillment['ok']) {
        $errors[] = $fulfillment['message'];
    }

    /*
     * Payment methods are constrained per fulfillment method. The
     * submitted value must be one actually offered for the chosen
     * fulfillment: the cash option (COD for courier, CASH_ON_PICKUP for
     * pickup) is always allowed, and GCASH is allowed for either
     * fulfillment ONLY while the shop's GCash account is configured.
     * Anything else - including a stale value from the other mode or a
     * GCASH attempt while GCash is unavailable - is rejected.
     */
    $cashPayment = $isPickupOrder ? 'CASH_ON_PICKUP' : 'COD';
    $allowedPayments = [$cashPayment];
    if ($gcashConfigured) {
        $allowedPayments[] = 'GCASH';
    }

    if (!in_array($formValues['payment_method'], $allowedPayments, true)) {
        if ($formValues['payment_method'] === 'GCASH' && !$gcashConfigured) {
            $errors[] = 'GCash is currently unavailable. Please choose the cash payment option.';
        } else {
            $errors[] = $isPickupOrder
                ? 'Choose a valid payment method for Store Pickup (Cash on Pickup or GCash).'
                : 'Choose a valid payment method for Local Courier (Cash on Delivery or GCash).';
        }
    }

    /*
     * GCash submissions must carry BOTH a valid reference number and a valid
     * payment-proof image before any order record is created. These checks
     * run before the transaction starts; the same helper rules are applied
     * again when the proof is moved into private storage. The browser-side
     * button state is convenience only and is never trusted here.
     */
    $isGcashSubmission = $gcashConfigured && $formValues['payment_method'] === 'GCASH';
    $gcashReference = '';
    $proofFile = null;

    if ($isGcashSubmission) {
        $gcashReference = payment_normalize_reference($_POST['gcash_reference'] ?? '');
        $formValues['gcash_reference'] = $gcashReference;

        if ($gcashReference === '') {
            $errors[] = payment_message('reference_required');
        } elseif (!payment_is_valid_reference($gcashReference)) {
            $errors[] = payment_message('reference_invalid');
        }

        $proofFile = $_FILES['payment_proof'] ?? null;
        $proofErrorValue = is_array($proofFile)
            ? ($proofFile['error'] ?? UPLOAD_ERR_NO_FILE)
            : UPLOAD_ERR_NO_FILE;
        $proofError = is_int($proofErrorValue) ? $proofErrorValue : UPLOAD_ERR_NO_FILE;
        $proofName = is_array($proofFile) && is_string($proofFile['name'] ?? null)
            ? $proofFile['name']
            : '';

        if (!is_array($proofFile) || $proofError === UPLOAD_ERR_NO_FILE || $proofName === '') {
            $errors[] = 'Please upload a screenshot of your GCash payment.';
        } else {
            $proofTmp = is_string($proofFile['tmp_name'] ?? null) ? $proofFile['tmp_name'] : '';
            $proofSize = is_numeric($proofFile['size'] ?? null) ? (int) $proofFile['size'] : 0;

            if ($proofTmp === '' || !is_uploaded_file($proofTmp)) {
                $errors[] = payment_message(
                    in_array($proofError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                        ? 'proof_too_large'
                        : 'proof_failed'
                );
            } else {
                $proofCheck = payment_proof_validate(
                    $proofTmp,
                    $proofSize,
                    $proofError
                );

                if (!$proofCheck['ok']) {
                    $proofReason = $proofCheck['reason'];

                    /* PHP-level upload limits surface as proof_failed; report
                     * them as an oversize file so the feedback is accurate. */
                    if (
                        $proofReason === 'proof_failed'
                        && in_array($proofError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ) {
                        $proofReason = 'proof_too_large';
                    }

                    $errors[] = payment_message($proofReason);
                }
            }
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

    if (count($errors) === 0) {
        $storedProofPath = null;

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
                    'SELECT p.id AS product_id, p.name, p.size, p.color, p.price, p.status, p.retired_at
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
                            if (!empty($lockedItem['retired_at'])) {
                                $unavailable = true;
                                break;
                            }
                        }

                        if ($unavailable) {
                            $pdo->rollBack();
                            $errors[] = 'One or more items in your cart are no longer available. Please return to your cart and review the items.';
                        } else {
                            /*
                             * Merchandise subtotal is computed from the
                             * locked database rows only. The shipping fee
                             * comes from the server-resolved fulfillment
                             * (shipping_resolve_fulfillment), never from
                             * the client. Final total = subtotal + fee.
                             */
                            $subtotalCents = 0;

                            foreach ($lockedItems as $lockedItem) {
                                $subtotalCents += (int) round((float) $lockedItem['price'] * 100);
                            }

                            $shippingFeeCents = $fulfillment['ok'] ? (int) $fulfillment['rate_cents'] : 0;
                            $totalCents = $subtotalCents + $shippingFeeCents;

                            $subtotalAmount = number_format($subtotalCents / 100, 2, '.', '');
                            $shippingFee = number_format($shippingFeeCents / 100, 2, '.', '');
                            $totalAmount = number_format($totalCents / 100, 2, '.', '');

                            if ($isPickupOrder) {
                                /*
                                 * Pickup orders record the shop as the
                                 * fulfillment location. Contact details stay
                                 * the customer's own, from the form above.
                                 */
                                $shippingAddress = 'Store Pickup - ' . $pickupDetails['shop'] . ', ' . $pickupDetails['address'];
                            } else {
                                $shippingAddress = implode(', ', [
                                    $formValues['region'],
                                    $formValues['province'],
                                    $formValues['city_municipality'],
                                    $formValues['barangay'],
                                    $formValues['house_unit_building'],
                                    $formValues['street']
                                ]);
                            }

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
                                shipping_address,
                                pickup_status
                             ) VALUES (
                                :customer_id,
                                :status,
                                :cancellation_status,
                                :subtotal,
                                :shipping_fee,
                                :total_amount,
                                :shipping_name,
                                :shipping_phone,
                                :shipping_address,
                                :pickup_status
                             )'
                        );
                        /*
                         * Phase 3: a Store Pickup order starts its dedicated
                         * pickup lifecycle at ORDER_RECEIVED. The courier
                         * orders.status workflow is untouched and stays
                         * PENDING for both fulfillment methods (the pickup
                         * lifecycle, not the courier status, drives pickup
                         * display). Courier orders store NULL pickup_status.
                         */
                        $orderStmt->execute([
                            ':customer_id' => $customerId,
                            ':status' => 'PENDING',
                            ':cancellation_status' => 'NONE',
                            ':subtotal' => $subtotalAmount,
                            ':shipping_fee' => $shippingFee,
                            ':total_amount' => $totalAmount,
                            ':shipping_name' => $formValues['shipping_name'],
                            ':shipping_phone' => $formValues['shipping_phone'],
                            ':shipping_address' => $shippingAddress,
                            ':pickup_status' => $isPickupOrder ? 'ORDER_RECEIVED' : null
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

                        /*
                         * Store the proof now that every pre-flight check has
                         * passed and the order rows exist inside the
                         * transaction. The upload was already validated
                         * before the transaction; payment_proof_store()
                         * re-validates and writes a random private filename.
                         * If anything below fails, the catch block rolls the
                         * database back AND removes this newly stored file.
                         */
                        if ($isGcashSubmission) {
                            $storeResult = payment_proof_store($proofFile);

                            if (!$storeResult['ok'] || ($storeResult['path'] ?? null) === null) {
                                throw new RuntimeException('The payment proof could not be stored.');
                            }

                            $storedProofPath = $storeResult['path'];
                        }

                        $paymentSubmittedAtSql = $isGcashSubmission ? 'NOW()' : 'NULL';
                        $paymentStmt = $pdo->prepare(
                            'INSERT INTO payments (
                                order_id,
                                payment_method,
                                payment_status,
                                gcash_reference,
                                proof_path,
                                submitted_at
                             ) VALUES (
                                :order_id,
                                :payment_method,
                                :payment_status,
                                :gcash_reference,
                                :proof_path,
                                ' . $paymentSubmittedAtSql . '
                             )'
                        );
                        /*
                         * Store the payment method the customer actually
                         * chose and validated above: the cash option (COD for
                         * courier, CASH_ON_PICKUP for pickup) or GCASH. A
                         * pickup order is never recorded as courier COD. The
                         * status always starts PENDING; a GCash order stays
                         * PENDING (with submitted_at set) until an admin
                         * verifies the real transfer, and is never marked
                         * PAID at order creation.
                         */
                        $paymentStmt->execute([
                            ':order_id' => $orderId,
                            ':payment_method' => $formValues['payment_method'],
                            ':payment_status' => 'PENDING',
                            ':gcash_reference' => $isGcashSubmission ? $gcashReference : null,
                            ':proof_path' => $storedProofPath
                        ]);

                        /*
                         * One shipment row per order, created inside the same
                         * transaction as the order. delivery_method stores the
                         * fulfillment method chosen at checkout
                         * (LOCAL_COURIER or STORE_PICKUP in this phase);
                         * shipment_status still starts at NOT_SHIPPED and is
                         * advanced by the admin later.
                         */
                        $shipmentStmt = $pdo->prepare(
                            'INSERT INTO shipments (
                                order_id,
                                shipment_status,
                                delivery_method,
                                tracking_number,
                                shipped_at,
                                delivered_at
                             ) VALUES (
                                :order_id,
                                :shipment_status,
                                :delivery_method,
                                NULL,
                                NULL,
                                NULL
                             )'
                        );
                        $shipmentStmt->execute([
                            ':order_id' => $orderId,
                            ':shipment_status' => 'NOT_SHIPPED',
                            ':delivery_method' => $formValues['delivery_method']
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

                        // Phase 13: commit the placement notification with the order.
                        admin_notifications_create($pdo, $orderId);
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

            /*
             * Filesystem writes are not part of the database transaction. A
             * proof stored for THIS submission must not survive a failed
             * order/payment write, so remove exactly the path created above
             * (never a pre-existing payment's proof).
             */
            if ($storedProofPath !== null) {
                payment_proof_delete($storedProofPath);
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

/*
 * Display-time fulfillment preview. Mirrors the server resolution so
 * the summary shows exactly the fee that order creation would store.
 * For an unchanged form this is safe: the authoritative value is
 * always recomputed inside the POST transaction.
 */
$displayFulfillment = shipping_resolve_fulfillment(
    $formValues['delivery_method'],
    $formValues['region']
);
$displayRateCents = $displayFulfillment['ok'] ? (int) $displayFulfillment['rate_cents'] : null;
$displayIsland = $displayFulfillment['ok'] ? $displayFulfillment['island'] : null;

/*
 * Payment option rendered for the currently selected fulfillment
 * method (GET render and failed-POST re-render alike). The default
 * is the cash option (COD for courier, CASH_ON_PICKUP for pickup).
 * A valid GCASH selection is preserved on re-render only while GCash
 * is configured; any other value falls back to the cash option.
 */
$isPickupOrderDisplay = $formValues['delivery_method'] === 'STORE_PICKUP';
$cashPaymentDisplay = $isPickupOrderDisplay ? 'CASH_ON_PICKUP' : 'COD';
$allowedPaymentsDisplay = [$cashPaymentDisplay];
if ($gcashConfigured) {
    $allowedPaymentsDisplay[] = 'GCASH';
}
if (!in_array($formValues['payment_method'], $allowedPaymentsDisplay, true)) {
    $formValues['payment_method'] = $cashPaymentDisplay;
}

/*
 * Region -> rate map for the client-side live preview. The server
 * recomputes the fee authoritatively at order creation; this map only
 * mirrors the server table so the summary can react instantly.
 */
$regionRateMapJs = [];
foreach ($regionNames as $regionName) {
    $rate = shipping_rate_cents_for_region($regionName);
    if ($rate !== null) {
        $regionRateMapJs[$regionName] = $rate / 100;
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
            <p class="checkout-header__count"><?= count($cartItems) ?> ITEM FROM YOUR CART</p>
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
            <form id="checkout-form" class="checkout-form" method="POST" action="checkout.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">

                <section class="checkout-card" aria-labelledby="checkout-contact-heading">
                    <div class="checkout-card__head">
                        <h2 class="checkout-card__title" id="checkout-contact-heading"><?= hopia_checkout_icon('contact') ?>Contact Information</h2>
                        <p class="checkout-card__hint">Prefilled from your account. You can edit it for this order.</p>
                    </div>

                    <div class="field">
                        <label for="shipping_name">Full Name</label>
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
                        <label for="shipping_phone">Phone Number</label>
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

                <section class="checkout-card" aria-labelledby="checkout-delivery-heading">
                    <div class="checkout-card__head">
                        <h2 class="checkout-card__title" id="checkout-delivery-heading"><?= hopia_checkout_icon('delivery') ?>Delivery Option</h2>
                        <p class="checkout-card__hint">Choose how your order is delivered.</p>
                    </div>

                    <div class="delivery-options">
                        <label class="delivery-option">
                            <input
                                type="radio"
                                name="delivery_method"
                                value="LOCAL_COURIER"
                                id="delivery-method-courier"
                                <?= $formValues['delivery_method'] === 'LOCAL_COURIER' ? 'checked' : '' ?>
                            >
                            <span class="delivery-option__body">
                                <span class="delivery-option__name"><?= hopia_checkout_icon('courier') ?>Local Courier</span>
                                <span class="delivery-option__desc">Delivered by our own local courier to your address.</span>
                            </span>
                        </label>

                        <label class="delivery-option">
                            <input
                                type="radio"
                                name="delivery_method"
                                value="STORE_PICKUP"
                                id="delivery-method-pickup"
                                <?= $formValues['delivery_method'] === 'STORE_PICKUP' ? 'checked' : '' ?>
                            >
                            <span class="delivery-option__body">
                                <span class="delivery-option__name"><?= hopia_checkout_icon('pickup') ?>Store Pickup</span>
                                <span class="delivery-option__desc">Collect your order at the shop. No shipping fee.</span>
                            </span>
                        </label>
                    </div>

                    <div class="pickup-panel" id="pickup-panel" <?= $formValues['delivery_method'] === 'STORE_PICKUP' ? '' : 'hidden' ?>>
                        <p class="pickup-panel__heading">Pickup details</p>
                        <dl class="pickup-panel__details">
                            <div class="pickup-panel__row">
                                <dt>Shop</dt>
                                <dd><?= hopia_e($pickupDetails['shop']) ?></dd>
                            </div>
                            <div class="pickup-panel__row">
                                <dt>Address</dt>
                                <dd><?= hopia_e($pickupDetails['address']) ?></dd>
                            </div>
                            <div class="pickup-panel__row">
                                <dt>Contact</dt>
                                <dd><a href="<?= hopia_e($pickupDetails['contact_href']) ?>"><?= hopia_e($pickupDetails['contact']) ?></a></dd>
                            </div>
                        </dl>
                        <a class="pickup-panel__maps" href="<?= hopia_e($pickupDetails['maps_url']) ?>" target="_blank" rel="noopener noreferrer">
                            View on Google Maps
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M7 17 17 7"></path>
                                <path d="M8 7h9v9"></path>
                            </svg>
                        </a>
                    </div>
                </section>

                <section class="checkout-card" id="delivery-address-card" aria-labelledby="checkout-address-heading" <?= $formValues['delivery_method'] === 'STORE_PICKUP' ? 'hidden' : '' ?>>
                    <div class="checkout-card__head">
                        <h2 class="checkout-card__title" id="checkout-address-heading"><?= hopia_checkout_icon('address') ?>Delivery Address</h2>
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
                        <h2 class="checkout-card__title" id="checkout-payment-heading"><?= hopia_checkout_icon('payment') ?>Payment Method</h2>
                    </div>

                    <div class="payment-options" id="payment-options">
                        <div id="payment-options-courier" <?= $formValues['delivery_method'] === 'STORE_PICKUP' ? 'hidden' : '' ?>>
                            <label class="payment-option">
                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="COD"
                                    data-fulfillment="courier"
                                    <?= $formValues['delivery_method'] !== 'STORE_PICKUP' && $formValues['payment_method'] === 'COD' ? 'checked' : '' ?>
                                >
                                <span class="payment-option__body">
                                    <span class="payment-option__name"><?= hopia_checkout_icon('cash') ?>Cash on Delivery</span>
                                    <span class="payment-option__desc">Pay in cash when your order arrives.</span>
                                </span>
                            </label>

                            <?php if ($gcashConfigured): ?>
                                <label class="payment-option">
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value="GCASH"
                                        data-fulfillment="courier"
                                        data-gcash-option
                                        <?= $formValues['delivery_method'] !== 'STORE_PICKUP' && $formValues['payment_method'] === 'GCASH' ? 'checked' : '' ?>
                                    >
                                    <span class="payment-option__body">
                                        <span class="payment-option__name"><?= hopia_checkout_icon('gcash') ?>GCash</span>
                                        <span class="payment-option__desc">Send payment via GCash, then submit your reference and proof before placing the order.</span>
                                    </span>
                                </label>
                            <?php endif; ?>
                        </div>

                        <div id="payment-options-pickup" <?= $formValues['delivery_method'] === 'STORE_PICKUP' ? '' : 'hidden' ?>>
                            <label class="payment-option">
                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="CASH_ON_PICKUP"
                                    data-fulfillment="pickup"
                                    <?= $formValues['delivery_method'] === 'STORE_PICKUP' && $formValues['payment_method'] === 'CASH_ON_PICKUP' ? 'checked' : '' ?>
                                >
                                <span class="payment-option__body">
                                    <span class="payment-option__name"><?= hopia_checkout_icon('cash') ?>Cash on Pickup</span>
                                    <span class="payment-option__desc">Pay in cash when you collect your order at the shop.</span>
                                </span>
                            </label>

                            <?php if ($gcashConfigured): ?>
                                <label class="payment-option">
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value="GCASH"
                                        data-fulfillment="pickup"
                                        data-gcash-option
                                        <?= $formValues['delivery_method'] === 'STORE_PICKUP' && $formValues['payment_method'] === 'GCASH' ? 'checked' : '' ?>
                                    >
                                    <span class="payment-option__body">
                                        <span class="payment-option__name"><?= hopia_checkout_icon('gcash') ?>GCash</span>
                                        <span class="payment-option__desc">Send payment via GCash, then submit your reference and proof before placing the order.</span>
                                    </span>
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($gcashConfigured): ?>
                        <div class="gcash-checkout" id="gcash-checkout" <?= $formValues['payment_method'] === 'GCASH' ? '' : 'hidden' ?>>
                            <div class="gcash-checkout__head">
                                <?= hopia_checkout_icon('gcash') ?>
                                <p class="gcash-checkout__title">Send your GCash payment</p>
                            </div>
                            <dl class="gcash-checkout__details">
                                <div class="gcash-checkout__row">
                                    <dt>Amount due</dt>
                                    <dd class="gcash-checkout__amount" id="gcash-checkout-amount">&#8369;<?= number_format($subtotal + ($displayRateCents ?? 0) / 100, 2) ?></dd>
                                </div>
                                <div class="gcash-checkout__row">
                                    <dt>Account name</dt>
                                    <dd><?= hopia_e($gcashConfig['name']) ?></dd>
                                </div>
                                <div class="gcash-checkout__row">
                                    <dt>GCash number</dt>
                                    <dd class="gcash-checkout__number-row">
                                        <span data-gcash-number><?= hopia_e($gcashConfig['number']) ?></span>
                                        <button type="button" class="btn btn-secondary btn-sm" data-gcash-copy>Copy number</button>
                                        <span class="gcash-checkout__copied" data-gcash-copied hidden role="status">Copied</span>
                                    </dd>
                                </div>
                            </dl>
                            <?php if ($gcashConfig['has_qr']): ?>
                                <figure class="gcash-checkout__qr">
                                    <img src="<?= hopia_e(hopia_asset($gcashConfig['qr'])) ?>" alt="GCash QR code for <?= hopia_e($gcashConfig['name']) ?>" loading="lazy" width="160" height="160">
                                    <figcaption>Scan to pay in the GCash app</figcaption>
                                </figure>
                            <?php endif; ?>
                            <ol class="gcash-panel__steps">
                                <li>Open your GCash app and send the exact amount shown above to the account details provided.</li>
                                <li>Copy the reference number shown on your GCash receipt.</li>
                                <li>Enter that reference and attach your payment screenshot below.</li>
                                <li>Place your order. Your payment stays pending until our team confirms the transfer.</li>
                            </ol>

                            <div class="field">
                                <label for="gcash_reference">GCash reference number</label>
                                <input
                                    type="text"
                                    id="gcash_reference"
                                    name="gcash_reference"
                                    value="<?= checkoutValue($formValues, 'gcash_reference') ?>"
                                    maxlength="64"
                                    autocomplete="off"
                                    inputmode="latin"
                                >
                                <p class="hint">Enter the reference exactly as shown on your GCash receipt.</p>
                            </div>

                            <div class="field">
                                <label for="gcash-proof">Payment proof <span class="muted">(required)</span></label>
                                <input
                                    type="file"
                                    id="gcash-proof"
                                    name="payment_proof"
                                    accept="image/jpeg,image/png,image/webp"
                                >
                                <p class="hint">JPG, PNG, or WebP, up to 5 MB. Only you and the shop can view it.</p>
                            </div>

                            <p class="alert alert-warning" id="gcash-requirement" role="status" hidden>Please complete GCash payment first before placing order.</p>
                        </div>
                    <?php endif; ?>
                </section>

                <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Place order</button>
            </form>

            <aside class="checkout-summary" aria-labelledby="checkout-summary-heading">
                <div class="checkout-summary__inner">
                    <h2 class="checkout-summary__title" id="checkout-summary-heading"><?= hopia_checkout_icon('summary') ?>Order Summary</h2>

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
                            <span>Merchandise subtotal</span>
                            <span class="price">₱<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="checkout-summary__row checkout-summary__row--muted">
                            <span>Shipping</span>
                            <span class="price" id="summary-shipping"><?= $displayRateCents === null ? 'To be confirmed' : '₱' . number_format($displayRateCents / 100, 2) ?></span>
                        </div>
                        <div class="checkout-summary__row checkout-summary__row--grand">
                            <span>Order total</span>
                            <span class="price" id="summary-total">₱<?= number_format($subtotal + ($displayRateCents ?? 0) / 100, 2) ?></span>
                        </div>
                    </div>

                    <div class="checkout-summary__actions">
                        <button
                            type="submit"
                            form="checkout-form"
                            class="checkout-submit"
                            id="checkout-submit"
                        >
                            <span class="checkout-submit__spinner" aria-hidden="true"></span>
                            <svg class="checkout-submit__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path>
                                <path d="M3 6h18"></path>
                                <path d="M16 10a4 4 0 0 1-8 0"></path>
                            </svg>
                            <span class="checkout-submit__label">PLACE ORDER</span>
                        </button>

                        <a class="checkout-back" href="cart.php">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m15 18-6-6 6-6"></path>
                            </svg>
                            BACK TO CART
                        </a>
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
            var courierRadio = document.getElementById('delivery-method-courier');
            var pickupRadio = document.getElementById('delivery-method-pickup');
            var addressCard = document.getElementById('delivery-address-card');
            var pickupPanel = document.getElementById('pickup-panel');
            var shippingOut = document.getElementById('summary-shipping');
            var totalOut = document.getElementById('summary-total');
            var locations = null;
            var loadPromise = null;
            var level = 'region';
            var lastFocused = null;

            var subtotalCents = <?= (int) round($subtotal * 100) ?>;
            var regionRates = <?= json_encode($regionRateMapJs) ?>;
            var paymentBlocks = {
                courier: document.getElementById('payment-options-courier'),
                pickup: document.getElementById('payment-options-pickup')
            };
            var gcashCheckout = document.getElementById('gcash-checkout');
            var gcashCheckoutAmount = document.getElementById('gcash-checkout-amount');
            var gcashReferenceInput = document.getElementById('gcash_reference');
            var gcashProofInput = document.getElementById('gcash-proof');
            var gcashRequirement = document.getElementById('gcash-requirement');

            function selectedPaymentMethod() {
                var checked = form
                    ? form.querySelector('input[name="payment_method"]:checked')
                    : null;
                return checked ? checked.value : '';
            }

            function gcashReferenceValue() {
                return gcashReferenceInput
                    ? gcashReferenceInput.value.replace(/\s+/g, ' ').trim()
                    : '';
            }

            function gcashReferenceLooksValid(reference) {
                return reference.length >= 6
                    && reference.length <= 64
                    && /^[A-Za-z0-9 \-]+$/.test(reference)
                    && /[A-Za-z0-9]/.test(reference);
            }

            function gcashProofSelected() {
                return !!(gcashProofInput
                    && gcashProofInput.files
                    && gcashProofInput.files.length > 0);
            }

            function gcashRequirementsMet() {
                if (selectedPaymentMethod() !== 'GCASH') {
                    return true;
                }

                return gcashReferenceLooksValid(gcashReferenceValue())
                    && gcashProofSelected();
            }

            function refreshGcashCheckout() {
                if (!gcashCheckout) {
                    return;
                }
                var isGcash = selectedPaymentMethod() === 'GCASH';
                gcashCheckout.hidden = !isGcash;
                if (isGcash && gcashCheckoutAmount && totalOut) {
                    gcashCheckoutAmount.textContent = totalOut.textContent;
                }

                /* GCash-only fields are required only while GCash is the
                 * active payment method; switching to cash must not leave
                 * stale native validation blocking a valid checkout. */
                if (gcashReferenceInput) {
                    gcashReferenceInput.required = isGcash;
                }
                if (gcashProofInput) {
                    gcashProofInput.required = isGcash;
                }

                var met = gcashRequirementsMet();

                if (gcashRequirement) {
                    gcashRequirement.hidden = !isGcash || met;
                }

                /* Convenience only: the server independently enforces both
                 * requirements before creating any order or payment row. */
                if (submitBtn && !submitBtn.classList.contains('is-loading')) {
                    submitBtn.disabled = !met;
                }
            }

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

                /* Programmatic .value changes do not fire 'change', so
                 * re-price the summary here after the region updates. */
                refreshFulfillmentUi();

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
            /* ---- Fulfillment toggle ----
             * Switching between Local Courier and Store Pickup updates
             * the address card, pickup panel, payment options, and the
             * summary. The server recomputes everything; this only
             * mirrors it for instant feedback. */
            function currentMethod() {
                return pickupRadio && pickupRadio.checked ? 'STORE_PICKUP' : 'LOCAL_COURIER';
            }

            function rateCentsForCurrentRegion() {
                var region = fields.region ? fields.region.value : '';
                return Object.prototype.hasOwnProperty.call(regionRates, region)
                    ? Math.round(regionRates[region] * 100)
                    : null;
            }

            function formatPeso(cents) {
                return '\u20B1' + (cents / 100).toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function refreshFulfillmentUi() {
                var isPickup = currentMethod() === 'STORE_PICKUP';

                if (addressCard) {
                    addressCard.hidden = isPickup;
                }

                if (pickupPanel) {
                    pickupPanel.hidden = !isPickup;
                }

                if (paymentBlocks.courier) {
                    paymentBlocks.courier.hidden = isPickup;
                }

                if (paymentBlocks.pickup) {
                    paymentBlocks.pickup.hidden = !isPickup;
                }

                /*
                 * Keep exactly one payment option valid for the current mode
                 * selected. Options in the hidden block are unchecked so a
                 * hidden value is never submitted; if that leaves nothing
                 * checked in the active block (e.g. after switching modes),
                 * fall back to that mode's cash option. A GCash selection made
                 * within the active mode is preserved across re-pricing.
                 */
                var activeBlock = isPickup ? paymentBlocks.pickup : paymentBlocks.courier;
                var inactiveBlock = isPickup ? paymentBlocks.courier : paymentBlocks.pickup;

                if (inactiveBlock) {
                    Array.prototype.forEach.call(
                        inactiveBlock.querySelectorAll('input[name="payment_method"]'),
                        function (radio) { radio.checked = false; }
                    );
                }

                if (activeBlock && !activeBlock.querySelector('input[name="payment_method"]:checked')) {
                    var fallback = activeBlock.querySelector(
                        'input[value="' + (isPickup ? 'CASH_ON_PICKUP' : 'COD') + '"]'
                    );
                    if (fallback) {
                        fallback.checked = true;
                    }
                }

                /* Delivery-specific required attributes follow the mode so
                 * hidden fields never trigger or bypass HTML validation. */
                var courierInputs = addressCard
                    ? addressCard.querySelectorAll('input[name="house_unit_building"], input[name="street"]')
                    : [];

                Array.prototype.forEach.call(courierInputs, function (input) {
                    input.required = !isPickup;
                });

                var feeCents = isPickup ? 0 : rateCentsForCurrentRegion();

                if (shippingOut) {
                    shippingOut.textContent = feeCents === null
                        ? 'To be confirmed'
                        : formatPeso(feeCents);
                }

                if (totalOut) {
                    totalOut.textContent = feeCents === null
                        ? formatPeso(subtotalCents)
                        : formatPeso(subtotalCents + feeCents);
                }

                refreshGcashCheckout();
            }

            [courierRadio, pickupRadio].forEach(function (radio) {
                if (radio) {
                    radio.addEventListener('change', refreshFulfillmentUi);
                }
            });

            /* Toggle the GCash details panel when the GCash option is picked. */
            if (form) {
                Array.prototype.forEach.call(
                    form.querySelectorAll('input[name="payment_method"]'),
                    function (radio) {
                        radio.addEventListener('change', refreshGcashCheckout);
                    }
                );
            }

            /* Keep the Place Order state in sync with the reference input and
             * proof selection. This is convenience feedback only. */
            [gcashReferenceInput, gcashProofInput].forEach(function (input) {
                if (!input) {
                    return;
                }

                input.addEventListener('input', refreshGcashCheckout);
                input.addEventListener('change', refreshGcashCheckout);
            });

            /* Copy GCash number from the checkout details panel. */
            if (gcashCheckout) {
                var gcashCopyBtn = gcashCheckout.querySelector('[data-gcash-copy]');
                var gcashNumberEl = gcashCheckout.querySelector('[data-gcash-number]');
                var gcashCopied = gcashCheckout.querySelector('[data-gcash-copied]');
                if (gcashCopyBtn && gcashNumberEl) {
                    gcashCopyBtn.addEventListener('click', function () {
                        var value = (gcashNumberEl.textContent || '').replace(/\s+/g, '');
                        var done = function () {
                            if (gcashCopied) {
                                gcashCopied.hidden = false;
                                window.setTimeout(function () { gcashCopied.hidden = true; }, 2000);
                            }
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(value).then(done).catch(done);
                        } else {
                            done();
                        }
                    });
                }
            }

            /* Re-price when the location picker changes the region. */
            if (fields.region) {
                fields.region.addEventListener('change', refreshFulfillmentUi);
            }

            refreshFulfillmentUi();

            form.addEventListener('submit', function (event) {
                /*
                 * Block an incomplete GCash submission here as well: the
                 * hidden in-form submit button (Enter key) can fire even when
                 * the visible Place Order button is disabled.
                 */
                if (!gcashRequirementsMet()) {
                    event.preventDefault();

                    if (gcashRequirement) {
                        gcashRequirement.hidden = false;
                    }

                    if (!gcashReferenceLooksValid(gcashReferenceValue()) && gcashReferenceInput) {
                        gcashReferenceInput.focus();
                    } else if (!gcashProofSelected() && gcashProofInput) {
                        gcashProofInput.focus();
                    }

                    refreshGcashCheckout();
                    return;
                }

                var isPickup = currentMethod() === 'STORE_PICKUP';
                var complete = true;

                if (!isPickup) {
                    complete = levelOrder.every(function (name) {
                        return selection[name] !== '';
                    });
                }

                if (!complete) {
                    event.preventDefault();

                    if (errorText) {
                        errorText.hidden = false;
                    }

                    if (!isPickup) {
                        openModal();
                    }

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

                /* Re-apply the GCash gate after restoring the button; the
                 * browser may restore a GCash selection without its proof. */
                refreshGcashCheckout();
            });
        })();
        </script>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
