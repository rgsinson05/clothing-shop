<?php

session_start();

require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/customer_auth.php';

// Retain an authenticated, quoted receipt even if the database is unavailable on this request.
$receiptToken = $_POST['checkout_token'] ?? $_POST['buy_now'] ?? $_GET['checkout'] ?? $_GET['buy_now'] ?? '';
$receiptQuoteToken = $_POST['gcash_quote_token'] ?? '';
$receiptCsrf = $_POST['csrf_token'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['payment_method'] ?? '') === 'GCASH'
    && is_string($receiptToken) && is_string($receiptQuoteToken) && is_string($receiptCsrf)
    && isset($_SESSION['customer_id'], $_SESSION['csrf_token'], $_SESSION['checkout_attempts'][$receiptToken])
    && hash_equals($_SESSION['csrf_token'], $receiptCsrf)) {
    $receiptAttempt =& $_SESSION['checkout_attempts'][$receiptToken];
    if ($receiptAttempt['customer_id'] === (int) $_SESSION['customer_id'] && empty($receiptAttempt['order_id'])
        && !empty($receiptAttempt['quote']) && hash_equals($receiptAttempt['quote']['token'], $receiptQuoteToken)) {
        $receiptReference = payment_normalize_reference($_POST['gcash_reference'] ?? '');
        if (payment_is_valid_reference($receiptReference)) {
            $receiptKey = checkoutReferenceKey($receiptReference);
            if ((!empty($receiptAttempt['reference_valid']) && checkoutReferenceKey($receiptAttempt['reference']) !== $receiptKey)
                || (isset($_SESSION['gcash_reference_checkouts'][$receiptKey]) && $_SESSION['gcash_reference_checkouts'][$receiptKey] !== $receiptToken)) {
                $receiptAttempt['review_required'] = true;
            } else {
                $_SESSION['gcash_reference_checkouts'][$receiptKey] = $receiptToken;
                $receiptAttempt['reference'] = $receiptReference;
                $receiptAttempt['reference_valid'] = true;
            }
        }
        if (empty($receiptAttempt['proof_path']) && is_array($_FILES['proof'] ?? null)
            && ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $receiptResult = payment_proof_store($_FILES['proof']);
                if ($receiptResult['ok'] && !empty($receiptResult['path'])) {
                    $receiptAttempt['proof_path'] = $receiptResult['path'];
                    $receiptAttempt['submitted_at'] = time();
                    checkoutBindProof($receiptAttempt, $receiptToken);
                }
            } catch (Throwable $receiptError) {
                $receiptAttempt['retention_error'] = true;
            }
        }
    }
    unset($receiptAttempt);
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/shipping.php';
require_once __DIR__ . '/../includes/gcash.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/admin_notifications.php';
require_once __DIR__ . '/../includes/cart_selection.php';

function checkoutLoadItems(PDO $pdo, bool $buyNow, int $productId, int $cartId, array $selectedIds, bool $lock = false): array
{
    if (($buyNow && $productId < 1) || (!$buyNow && $selectedIds === [])) {
        return [];
    }
    $sql = 'SELECT ' . ($buyNow ? '0' : 'ci.id') . ' AS cart_item_id,
                   p.id, p.id AS product_id, p.name, p.size, p.category, p.color, p.price, p.status, p.retired_at';
    if (!$lock) {
        $sql .= ', pi.image_path';
    }
    $sql .= $buyNow ? ' FROM products p' : ' FROM cart_items ci INNER JOIN products p ON p.id = ci.product_id';
    if (!$lock) {
        $sql .= ' LEFT JOIN product_images pi ON pi.id = (
                    SELECT pi2.id FROM product_images pi2 WHERE pi2.product_id = p.id
                    ORDER BY pi2.sort_order ASC, pi2.id ASC LIMIT 1)';
    }
    if ($buyNow) {
        $sql .= ' WHERE p.id = ?';
        $params = [$productId];
    } else {
        $sql .= ' WHERE ci.cart_id = ? AND ci.id IN (' . implode(',', array_fill(0, count($selectedIds), '?')) . ')';
        $params = array_merge([$cartId], $selectedIds);
    }
    $sql .= ($lock || $buyNow) ? ' ORDER BY p.id' : ' ORDER BY ci.created_at ASC, ci.id ASC';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function checkoutSnapshot(array $items): array
{
    $snapshot = array_map(static function (array $item): array {
        return [
            'cart_item_id' => (int) $item['cart_item_id'],
            'product_id' => (int) $item['product_id'],
            'price_cents' => (int) round((float) $item['price'] * 100),
            'name' => $item['name'],
            'size' => $item['size'],
            'color' => $item['color'],
        ];
    }, $items);
    usort($snapshot, static function (array $left, array $right): int {
        return $left['product_id'] <=> $right['product_id'];
    });
    return $snapshot;
}

function checkoutMissingItems(array $snapshot, array $items): array
{
    $found = [];
    foreach ($items as $item) {
        $found[(int) $item['cart_item_id'] . ':' . (int) $item['product_id']] = true;
    }
    $missing = [];
    foreach ($snapshot as $item) {
        if (!isset($found[$item['cart_item_id'] . ':' . $item['product_id']])) {
            $missing[] = $item['name'] . ' (#' . $item['product_id'] . ')';
        }
    }
    return $missing;
}

function checkoutJson(array $payload, int $status = 200): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function checkoutReferenceKey(string $reference): string
{
    return strtoupper(str_replace([' ', '-'], '', $reference));
}

function checkoutBindProof(array &$attempt, string $token): void
{
    $path = payment_proof_abspath($attempt['proof_path']);
    $fingerprint = $path === null ? false : hash_file('sha256', $path);
    if ($fingerprint === false || (isset($_SESSION['gcash_proof_checkouts'][$fingerprint])
        && $_SESSION['gcash_proof_checkouts'][$fingerprint] !== $token)) {
        $attempt['review_required'] = true;
        return;
    }
    $_SESSION['gcash_proof_checkouts'][$fingerprint] = $token;
    $attempt['proof_fingerprint'] = $fingerprint;
}

$isQuoteRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['checkout_action'] ?? '') === 'prepare_gcash';
$isRestartRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['checkout_action'] ?? '') === 'restart_checkout';
$wantsCheckoutJson = $isQuoteRequest && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

// Buy Now selects an item, but never creates or changes a persistent cart.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['checkout_action'] ?? '') === 'buy_now') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $storedToken = $_SESSION['csrf_token_buy_now'] ?? '';
    $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
    $returnTo = $productId > 0 && ($_POST['return_to'] ?? '') === 'product.php?id=' . $productId
        ? 'product.php?id=' . $productId
        : 'products.php';

    if (!is_string($submittedToken) || !is_string($storedToken) || $storedToken === ''
        || !hash_equals($storedToken, $submittedToken)) {
        $_SESSION['buy_now_error'] = 'Your session expired. Please try again.';
    } elseif ($productId === false || $productId === null || $productId < 1) {
        $_SESSION['buy_now_error'] = 'This item could not be purchased.';
    } else {
        $previousBuyNow = $_SESSION['buy_now_checkout'] ?? null;
        $previousAttempt = is_array($previousBuyNow) && is_string($previousBuyNow['token'] ?? null)
            ? ($_SESSION['checkout_attempts'][$previousBuyNow['token']] ?? null) : null;
        if (isset($_SESSION['customer_id']) && is_array($previousAttempt)
            && $previousAttempt['customer_id'] === (int) $_SESSION['customer_id']
            && $previousAttempt['product_id'] === $productId && empty($previousAttempt['order_id'])
            && (!empty($previousAttempt['proof_path']) || !empty($previousAttempt['reference_valid']))) {
            if ((int) $previousBuyNow['created_at'] >= time() - 1800) {
                header('Location: checkout.php?buy_now=' . rawurlencode($previousBuyNow['token']), true, 303);
            } else {
                $_SESSION['buy_now_error'] = 'Your original GCash submission is retained, but this Buy Now session needs shop assistance. Do not pay again.';
                header('Location: ' . $returnTo, true, 303);
            }
            exit;
        }

        $productStmt = $pdo->prepare(
            "SELECT id FROM products
             WHERE id = :id AND status = 'AVAILABLE' AND retired_at IS NULL"
        );
        $productStmt->execute([':id' => $productId]);

        if ($productStmt->fetch() === false) {
            $_SESSION['buy_now_error'] = 'Sorry, this piece is no longer available.';
        } else {
            $contextToken = bin2hex(random_bytes(32));
            $_SESSION['buy_now_checkout'] = [
                'token' => $contextToken,
                'product_id' => $productId,
                'customer_id' => isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null,
                'created_at' => time(),
            ];
            unset($_SESSION['buy_now_error'], $_SESSION['buy_now_pending_login']);

            if (!isset($_SESSION['customer_id'])) {
                $_SESSION['buy_now_pending_login'] = $contextToken;
                header('Location: login.php', true, 303);
            } else {
                header('Location: checkout.php?buy_now=' . $contextToken, true, 303);
            }
            exit;
        }
    }

    header('Location: ' . $returnTo, true, 303);
    exit;
}

if (!isset($_SESSION['customer_id'])) {
    if ($wantsCheckoutJson) {
        checkoutJson(['ok' => false, 'message' => 'Your session expired. Please sign in again.'], 401);
    }
    hopia_customer_set_intended_destination('checkout.php');
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

// An explicit context prevents stale tabs or invalid tokens from checking out the cart instead.
$isBuyNowCheckout = array_key_exists('buy_now', $_GET) || array_key_exists('buy_now', $_POST);
$buyNowToken = $_GET['buy_now'] ?? $_POST['buy_now'] ?? '';
$buyNowContext = $_SESSION['buy_now_checkout'] ?? null;
$buyNowProductId = 0;
$buyNowContextValid = false;

if ($isBuyNowCheckout) {
    $buyNowContextValid = is_string($buyNowToken) && $buyNowToken !== ''
        && is_array($buyNowContext)
        && is_string($buyNowContext['token'] ?? null)
        && hash_equals($buyNowContext['token'], $buyNowToken)
        && ($buyNowContext['customer_id'] ?? null) === $customerId
        && (int) ($buyNowContext['product_id'] ?? 0) > 0
        && (int) ($buyNowContext['created_at'] ?? 0) >= time() - 1800
        && (!array_key_exists('buy_now', $_POST) || $_POST['buy_now'] === $buyNowToken);

    if (!$buyNowContextValid) {
        $errors[] = 'Your Buy Now session has expired. Please choose the piece again.';
    } elseif (!empty($buyNowContext['order_id'])) {
        // Replaying a completed checkout returns its confirmation, not a second order.
        header('Location: order-confirmation.php?id=' . (int) $buyNowContext['order_id'], true, 303);
        exit;
    } else {
        $buyNowProductId = (int) $buyNowContext['product_id'];
    }
}

$checkoutUrl = $isBuyNowCheckout && is_string($buyNowToken)
    ? 'checkout.php?buy_now=' . rawurlencode($buyNowToken)
    : 'checkout.php';
$checkoutBackUrl = $isBuyNowCheckout
    ? ($buyNowProductId > 0 ? 'product.php?id=' . $buyNowProductId : 'products.php')
    : 'cart.php';

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

$cart = false;
$currentSelectedIds = [];
if (!$isBuyNowCheckout) {
    $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE customer_id = ? LIMIT 1');
    $cartStmt->execute([$customerId]);
    $cart = $cartStmt->fetch();
    if ($cart !== false) {
        $currentSelectedIds = $_SERVER['REQUEST_METHOD'] === 'GET'
            ? cart_selection_prune($pdo, (int) $cart['id'])
            : cart_selection_get((int) $cart['id']);
    }
}
$cartId = $cart === false ? 0 : (int) $cart['id'];
$checkoutItems = checkoutLoadItems($pdo, $isBuyNowCheckout, $buyNowProductId, $cartId, $currentSelectedIds);
$currentSnapshot = checkoutSnapshot($checkoutItems);
$checkoutToken = $isBuyNowCheckout ? $buyNowToken : ($_GET['checkout'] ?? $_POST['checkout_token'] ?? '');
$checkoutAttemptValid = false;
$checkoutAttempt = null;
$_SESSION['checkout_attempts'] = $_SESSION['checkout_attempts'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!$isBuyNowCheckout || $buyNowContextValid)) {
    if (!$isBuyNowCheckout && !array_key_exists('checkout', $_GET) && $currentSelectedIds !== []) {
        foreach (array_reverse($_SESSION['checkout_attempts'], true) as $token => $attempt) {
            if ($attempt['customer_id'] === $customerId && $attempt['mode'] === 'cart'
                && $attempt['cart_id'] === $cartId && $attempt['selected_ids'] === $currentSelectedIds
                && $attempt['selection_revision'] === cart_selection_revision($cartId) && empty($attempt['order_id'])
                && empty($attempt['closed'])
                && ($attempt['snapshot'] === $currentSnapshot || !empty($attempt['quote']) || !empty($attempt['proof_path']))) {
                $checkoutToken = $token;
                break;
            }
        }
        if ($checkoutToken === '') {
            $checkoutToken = bin2hex(random_bytes(32));
        }
    }
    if (is_string($checkoutToken) && preg_match('/^[a-f0-9]{64}$/D', $checkoutToken) === 1
        && !isset($_SESSION['checkout_attempts'][$checkoutToken])
        && ($isBuyNowCheckout || !array_key_exists('checkout', $_GET))) {
        $_SESSION['checkout_attempts'][$checkoutToken] = [
            'customer_id' => $customerId,
            'mode' => $isBuyNowCheckout ? 'buy_now' : 'cart',
            'cart_id' => $cartId,
            'product_id' => $buyNowProductId,
            'selected_ids' => $currentSelectedIds,
            'selection_revision' => $isBuyNowCheckout ? 0 : cart_selection_revision($cartId),
            'snapshot' => $currentSnapshot,
            'created_at' => time(),
        ];
    }
}

if (is_string($checkoutToken) && preg_match('/^[a-f0-9]{64}$/D', $checkoutToken) === 1
    && isset($_SESSION['checkout_attempts'][$checkoutToken])) {
    $checkoutAttempt =& $_SESSION['checkout_attempts'][$checkoutToken];
    $checkoutAttemptValid = $checkoutAttempt['customer_id'] === $customerId
        && $checkoutAttempt['mode'] === ($isBuyNowCheckout ? 'buy_now' : 'cart')
        && (!$isBuyNowCheckout || ($buyNowContextValid && $checkoutAttempt['product_id'] === $buyNowProductId))
        && (!array_key_exists('checkout_token', $_POST) || $_POST['checkout_token'] === $checkoutToken)
        && (!$isBuyNowCheckout || !array_key_exists('checkout', $_GET));
}

if ($checkoutAttemptValid && !empty($checkoutAttempt['order_id'])) {
    header('Location: order-confirmation.php?id=' . (int) $checkoutAttempt['order_id'], true, 303);
    exit;
}
if (!$isBuyNowCheckout && $currentSelectedIds === []) {
    $errors[] = 'Select at least one item';
} elseif (!$checkoutAttemptValid) {
    $errors[] = 'Your checkout session has expired. Return to your ' . ($isBuyNowCheckout ? 'product' : 'cart') . ' to review the items.';
} elseif (!$isBuyNowCheckout && ($checkoutAttempt['cart_id'] !== $cartId
    || $checkoutAttempt['selected_ids'] !== $currentSelectedIds
    || $checkoutAttempt['selection_revision'] !== cart_selection_revision($cartId))) {
    $errors[] = 'Your cart selection changed after checkout started. Return to your cart and review your selection.';
}
if ($checkoutAttemptValid && !empty($checkoutAttempt['review_required'])) {
    $errors[] = 'This checkout needs shop assistance before it can be retried. '
        . (!empty($checkoutAttempt['proof_path']) || !empty($checkoutAttempt['reference_valid'])
            ? 'Your GCash evidence is retained; do not send another payment.'
            : 'The order result must be checked before an automatic retry is allowed.');
}
if ($checkoutAttemptValid && !empty($checkoutAttempt['closed'])) {
    $errors[] = 'This checkout was restarted. Do not pay its old GCash amount. Contact the shop if you sent a payment for it.';
}
$relatedPayment = null;
if ($checkoutAttemptValid) {
    foreach ($_SESSION['checkout_attempts'] as $token => $attempt) {
        if ($token !== $checkoutToken && $attempt['customer_id'] === $customerId && empty($attempt['order_id'])
            && (!empty($attempt['proof_path']) || !empty($attempt['reference_valid']))
            && array_intersect(array_column($attempt['snapshot'], 'product_id'), array_column($checkoutAttempt['snapshot'], 'product_id')) !== []) {
            $relatedPayment = $attempt;
            $errors[] = 'An earlier GCash checkout for these products has submitted payment evidence. Resume the original checkout or contact the shop before ordering these items again. Do not pay again.';
            break;
        }
    }
}

if (!$isBuyNowCheckout && $checkoutAttemptValid) {
    $checkoutUrl = 'checkout.php?checkout=' . rawurlencode($checkoutToken);
}
$retainedProof = $checkoutAttemptValid && !empty($checkoutAttempt['proof_path']);
if ($checkoutAttemptValid && !empty($checkoutAttempt['reference'])) {
    $formValues['gcash_reference'] = $checkoutAttempt['reference'];
    $formValues['payment_method'] = 'GCASH';
}
if ($checkoutAttemptValid && !empty($checkoutAttempt['quote'])) {
    $formValues['delivery_method'] = $checkoutAttempt['quote']['delivery_method'];
    $formValues['region'] = $checkoutAttempt['quote']['region'];
    if ($gcashConfigured) {
        $formValues['payment_method'] = 'GCASH';
    }
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
}

$requestTrusted = $checkoutAttemptValid && $_SERVER['REQUEST_METHOD'] === 'POST'
    && is_string($_POST['csrf_token'] ?? null) && hash_equals($csrfToken, $_POST['csrf_token']);

if ($isRestartRequest) {
    if (!$requestTrusted || ($_POST['confirm_unpaid'] ?? '') !== '1') {
        $errors[] = 'Confirm that you have not sent this GCash payment before restarting checkout.';
    } elseif (!empty($checkoutAttempt['proof_path']) || !empty($checkoutAttempt['reference_valid']) || !empty($checkoutAttempt['review_required'])) {
        $errors[] = 'This checkout has submitted payment evidence or needs review. Contact the shop instead of restarting or paying again.';
    } elseif (empty($checkoutAttempt['quote'])) {
        $errors[] = 'There is no confirmed GCash amount to restart. Review your current checkout instead.';
    } else {
        // Keep the old quote as evidence; a new checkout gets a different server token.
        $checkoutAttempt['closed'] = true;
        header('Location: ' . ($isBuyNowCheckout ? $checkoutBackUrl : 'checkout.php'), true, 303);
        exit;
    }
}

if ($isQuoteRequest) {
    $quoteFulfillment = shipping_resolve_fulfillment($formValues['delivery_method'], $formValues['region']);
    if (!$gcashConfigured) {
        $errors[] = 'GCash is currently unavailable. Please choose the cash payment option.';
    }
    if (!$quoteFulfillment['ok']) {
        $errors[] = $quoteFulfillment['message'];
    }
    $missingQuoteItems = $checkoutAttemptValid ? checkoutMissingItems($checkoutAttempt['snapshot'], $checkoutItems) : [];
    if ($missingQuoteItems !== [] || count($checkoutItems) !== ($isBuyNowCheckout ? 1 : count($currentSelectedIds))) {
        $errors[] = 'Selected items missing or changed: ' . ($missingQuoteItems === [] ? 'cart entries #' . implode(', #', $currentSelectedIds) : implode(', ', $missingQuoteItems)) . '. Review the whole selection before proceeding.';
    }
    $unavailable = [];
    foreach ($checkoutItems as $item) {
        if ($item['status'] !== 'AVAILABLE' || $item['retired_at'] !== null) {
            $unavailable[] = $item['name'] . ' (#' . (int) $item['product_id'] . ')';
        }
    }
    if ($unavailable !== []) {
        $errors[] = $isBuyNowCheckout ? 'Sorry, this piece is no longer available.' : cart_selection_unavailable_message($unavailable);
    }
    if ($checkoutAttemptValid && $checkoutAttempt['snapshot'] !== $currentSnapshot) {
        $errors[] = 'The selected products or prices changed. Do not pay this amount. Review checkout again; if you already paid, contact the shop.';
    }
    if ($errors === []) {
        $subtotalCents = array_sum(array_column($currentSnapshot, 'price_cents'));
        $quoteValues = [
            'snapshot' => $currentSnapshot,
            'subtotal_cents' => $subtotalCents,
            'shipping_cents' => (int) $quoteFulfillment['rate_cents'],
            'total_cents' => $subtotalCents + (int) $quoteFulfillment['rate_cents'],
            'delivery_method' => $formValues['delivery_method'],
            'region' => $formValues['delivery_method'] === 'STORE_PICKUP' ? '' : $formValues['region'],
            'receiver' => hash('sha256', $gcashConfig['name'] . '|' . $gcashConfig['number']),
        ];
        if (!empty($checkoutAttempt['quote']) && array_diff_key($checkoutAttempt['quote'], ['token' => true]) !== $quoteValues) {
            $errors[] = 'The GCash amount or delivery option changed. The original payment amount is kept. If you already paid, contact the shop; otherwise start checkout again from your ' . ($isBuyNowCheckout ? 'product' : 'cart') . '.';
        } else {
            if (empty($checkoutAttempt['quote'])) {
                $checkoutAttempt['quote'] = array_merge($quoteValues, ['token' => bin2hex(random_bytes(32))]);
            }
            $formValues['payment_method'] = 'GCASH';
        }
    }
    if ($wantsCheckoutJson) {
        if ($errors !== []) {
            checkoutJson(['ok' => false, 'message' => implode(' ', array_unique($errors))], 422);
        }
        checkoutJson([
            'ok' => true,
            'quote_token' => $checkoutAttempt['quote']['token'],
            'subtotal_cents' => $checkoutAttempt['quote']['subtotal_cents'],
            'shipping_cents' => $checkoutAttempt['quote']['shipping_cents'],
            'total_cents' => $checkoutAttempt['quote']['total_cents'],
            'delivery_method' => $checkoutAttempt['quote']['delivery_method'],
            'region' => $checkoutAttempt['quote']['region'],
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isQuoteRequest && !$isRestartRequest) {

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
    $isGcashEvidenceSubmission = $requestTrusted && $formValues['payment_method'] === 'GCASH'
        && ($gcashConfigured || !empty($checkoutAttempt['quote']));
    $gcashReference = '';
    $proofFile = null;
    $storedProofPath = null;

    if ($checkoutAttemptValid && $formValues['payment_method'] !== 'GCASH'
        && (!empty($checkoutAttempt['proof_path']) || !empty($checkoutAttempt['reference_valid']))) {
        $errors[] = 'GCash evidence has already been submitted for this checkout. Use this same GCash checkout or contact the shop before changing payment. Do not pay again.';
    }

    if ($isGcashSubmission || $isGcashEvidenceSubmission) {
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

        if ($requestTrusted && !empty($checkoutAttempt['reference_valid'])
            && checkoutReferenceKey($gcashReference) !== checkoutReferenceKey($checkoutAttempt['reference'])) {
            $errors[] = 'The submitted GCash reference belongs to this original checkout and cannot be replaced here. Contact the shop if it needs correction.';
            $gcashReference = $checkoutAttempt['reference'];
            $formValues['gcash_reference'] = $gcashReference;
        }

        if ($requestTrusted && payment_is_valid_reference($gcashReference)) {
            $referenceKey = checkoutReferenceKey($gcashReference);
            $boundCheckout = $_SESSION['gcash_reference_checkouts'][$referenceKey] ?? $checkoutToken;
            if ($boundCheckout !== $checkoutToken) {
                $errors[] = 'That GCash reference is already attached to another checkout. It cannot be applied to different selected items.';
                $checkoutAttempt['review_required'] = true;
            } else {
                $_SESSION['gcash_reference_checkouts'][$referenceKey] = $checkoutToken;
                $checkoutAttempt['reference'] = $gcashReference;
                $checkoutAttempt['reference_valid'] = true;
            }
        } elseif ($requestTrusted && $gcashReference !== '') {
            $checkoutAttempt['reference_input'] = substr($gcashReference, 0, 100);
        }

        if ($requestTrusted && !empty($checkoutAttempt['proof_path'])) {
            $storedProofPath = $checkoutAttempt['proof_path'];
            $retainedProofPath = payment_proof_abspath($storedProofPath);
            if ($retainedProofPath === null || !is_file($retainedProofPath)) {
                $errors[] = 'Your retained GCash proof could not be accessed. Contact the shop with your receipt; do not send another payment.';
                $checkoutAttempt['review_required'] = true;
            } else {
                $retainedFingerprint = hash_file('sha256', $retainedProofPath);
                if (!is_string($retainedFingerprint) || !hash_equals($checkoutAttempt['proof_fingerprint'] ?? '', $retainedFingerprint)) {
                    $errors[] = 'The retained GCash proof changed or could not be read. Contact the shop before retrying; do not pay again.';
                    $checkoutAttempt['review_required'] = true;
                }
            }
        } elseif (!is_array($proofFile) || $proofError === UPLOAD_ERR_NO_FILE || $proofName === '') {
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
                } elseif ($requestTrusted) {
                    // A database rollback cannot reverse a transfer. Keep valid evidence before any order write.
                    try {
                        $storeResult = payment_proof_store($proofFile);
                        if (!$storeResult['ok'] || empty($storeResult['path'])) {
                            $errors[] = payment_message($storeResult['reason'] ?? 'proof_failed');
                        } else {
                            $storedProofPath = $storeResult['path'];
                            $checkoutAttempt['proof_path'] = $storedProofPath;
                            $checkoutAttempt['submitted_at'] = time();
                            checkoutBindProof($checkoutAttempt, $checkoutToken);
                            if (!empty($checkoutAttempt['review_required'])) {
                                $errors[] = 'This GCash proof could not be bound to this checkout or was already submitted for another checkout. It is retained for shop review, not reused for new selected items.';
                            }
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'We could not retain your screenshot. Keep your GCash receipt and contact the shop if you have already paid. Do not pay again.';
                    }
                }
            }
        }

        $quote = $checkoutAttemptValid ? ($checkoutAttempt['quote'] ?? null) : null;
        $quoteToken = $_POST['gcash_quote_token'] ?? '';
        if (!is_array($quote) || !is_string($quoteToken) || !hash_equals($quote['token'], $quoteToken)) {
            $errors[] = 'The GCash amount was not confirmed for this checkout. If you already paid, contact the shop; do not send another payment.';
            if ($requestTrusted && ($storedProofPath !== null || !empty($checkoutAttempt['reference_valid']))) {
                $checkoutAttempt['review_required'] = true;
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
        $referenceLock = null;
        $commitStarted = false;
        $placedOrderId = null;
        $orderId = null;

        try {
            if ($isGcashSubmission) {
                // Serialize new orders using the same reference without adding a database column.
                $lockName = 'gcash-' . substr(hash('sha256', checkoutReferenceKey($gcashReference)), 0, 58);
                $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
                $lockStmt->execute([$lockName]);
                if ((int) $lockStmt->fetchColumn() !== 1) {
                    $errors[] = 'This GCash reference is being processed. Retry this same checkout later without sending another payment.';
                    throw new RuntimeException('GCash reference is locked.');
                }
                $referenceLock = $lockName;
            }
            $pdo->beginTransaction();

            $transactionCart = false;
            if (!$isBuyNowCheckout) {
                /* Re-fetch the cart from the authenticated customer, never from POST data. */
                $transactionCartStmt = $pdo->prepare(
                    'SELECT id
                     FROM carts
                     WHERE customer_id = :customer_id
                     LIMIT 1 FOR UPDATE'
                );
                $transactionCartStmt->execute([':customer_id' => $customerId]);
                $transactionCart = $transactionCartStmt->fetch();
            }

            if (!$isBuyNowCheckout && $transactionCart === false) {
                $pdo->rollBack();
                $errors[] = 'Select at least one item';
            } else {
                if (!$isBuyNowCheckout && ((int) $transactionCart['id'] !== $checkoutAttempt['cart_id']
                    || cart_selection_get((int) $transactionCart['id']) !== $checkoutAttempt['selected_ids']
                    || cart_selection_revision((int) $transactionCart['id']) !== $checkoutAttempt['selection_revision'])) {
                    $errors[] = 'Your selected cart items changed. Review the selection before proceeding.';
                    throw new RuntimeException('Cart selection changed.');
                }
                $lockedItems = checkoutLoadItems(
                    $pdo, $isBuyNowCheckout, $buyNowProductId, $checkoutAttempt['cart_id'], $checkoutAttempt['selected_ids'], true
                );

                $missingLockedItems = checkoutMissingItems($checkoutAttempt['snapshot'], $lockedItems);
                if ($missingLockedItems !== [] || count($lockedItems) === 0 || count($lockedItems) !== ($isBuyNowCheckout ? 1 : count($checkoutAttempt['selected_ids']))) {
                    $pdo->rollBack();
                    $errors[] = $isBuyNowCheckout
                        ? 'Sorry, this piece is no longer available.'
                        : 'Selected items missing or changed: ' . ($missingLockedItems === [] ? 'cart entries #' . implode(', #', $checkoutAttempt['selected_ids']) : implode(', ', $missingLockedItems)) . '. Review the whole selection before proceeding.';
                } else {
                        $unavailable = [];

                        foreach ($lockedItems as $lockedItem) {
                            if ($lockedItem['status'] !== 'AVAILABLE' || $lockedItem['retired_at'] !== null) {
                                $unavailable[] = $lockedItem['name'] . ' (#' . (int) $lockedItem['product_id'] . ')';
                            }
                        }

                        if ($unavailable) {
                            $pdo->rollBack();
                            $errors[] = $isBuyNowCheckout
                                ? 'Sorry, this piece is no longer available.'
                                : cart_selection_unavailable_message($unavailable);
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

                            if ($isGcashSubmission) {
                                $quote = $checkoutAttempt['quote'];
                                if ($quote['snapshot'] !== checkoutSnapshot($lockedItems)
                                    || $quote['subtotal_cents'] !== $subtotalCents
                                    || $quote['shipping_cents'] !== $shippingFeeCents
                                    || $quote['total_cents'] !== $totalCents
                                    || $quote['delivery_method'] !== $formValues['delivery_method']
                                    || $quote['region'] !== ($isPickupOrder ? '' : $formValues['region'])
                                    || $quote['receiver'] !== hash('sha256', $gcashConfig['name'] . '|' . $gcashConfig['number'])) {
                                    $errors[] = 'The selected products, prices, or delivery amount changed after GCash payment was established. No order was created. Your evidence is retained; contact the shop and do not pay again.';
                                    $checkoutAttempt['review_required'] = true;
                                    throw new RuntimeException('GCash quote mismatch.');
                                }
                                $referenceStmt = $pdo->prepare(
                                    "SELECT order_id FROM payments
                                     WHERE UPPER(REPLACE(REPLACE(gcash_reference, ' ', ''), '-', '')) = ?
                                     LIMIT 1 FOR UPDATE"
                                );
                                $referenceStmt->execute([checkoutReferenceKey($gcashReference)]);
                                if ($referenceStmt->fetchColumn() !== false) {
                                    $errors[] = 'This GCash reference already belongs to an existing order and cannot fund another checkout. Contact the shop if needed; do not pay again.';
                                    $checkoutAttempt['review_required'] = true;
                                    throw new RuntimeException('GCash reference already used.');
                                }
                            }

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
                               AND retired_at IS NULL
                                AND id IN (' . $productPlaceholders . ')'
                        );
                        $soldProductsStmt->execute(array_merge(
                            ['SOLD', 'AVAILABLE'],
                            $productIds
                        ));

                        if ($soldProductsStmt->rowCount() !== count($productIds)) {
                            throw new RuntimeException('The ordered products could not be updated.');
                        }

                        if (!$isBuyNowCheckout) {
                            $cartItemIds = array_map('intval', array_column($lockedItems, 'cart_item_id'));
                            $cartItemPlaceholders = implode(',', array_fill(0, count($cartItemIds), '?'));
                            $removeCartItemsStmt = $pdo->prepare(
                                'DELETE FROM cart_items WHERE cart_id = ? AND id IN (' . $cartItemPlaceholders . ')'
                            );
                            $removeCartItemsStmt->execute(array_merge([(int) $transactionCart['id']], $cartItemIds));
                            if ($removeCartItemsStmt->rowCount() !== count($cartItemIds)) {
                                throw new RuntimeException('The ordered cart items could not be removed.');
                            }
                        }

                        // Phase 13: commit the placement notification with the order.
                        admin_notifications_create($pdo, $orderId);
                        $commitStarted = true;
                        $pdo->commit();

                        $checkoutAttempt['order_id'] = $orderId;
                        if ($isBuyNowCheckout) {
                            $_SESSION['buy_now_checkout']['order_id'] = $orderId;
                        } else {
                            cart_selection_remove((int) $transactionCart['id'], $cartItemIds);
                        }

                        $placedOrderId = $orderId;
                    }
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollbackError) {
                    $checkoutAttempt['review_required'] = true;
                }
            }

            if ($commitStarted) {
                $checkoutAttempt['review_required'] = true;
                $checkoutAttempt['candidate_order_id'] = $orderId;
                $errors[] = 'We could not confirm the order result. Contact the shop before retrying. Any submitted GCash evidence is retained; do not pay again.';
            } elseif (!empty($checkoutAttempt['review_required'])) {
                $errors[] = 'This checkout needs shop assistance before retrying. Any GCash evidence is retained; do not send another payment.';
            } elseif ($errors === []) {
                $errors[] = $isGcashSubmission
                    ? 'We could not place your order. Your GCash reference and proof are kept for this same checkout. Retry without sending another payment, or contact the shop.'
                    : 'We could not place your order right now. Please try again.';
            }
        } finally {
            if ($referenceLock !== null) {
                try {
                    $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $releaseStmt->execute([$referenceLock]);
                } catch (Throwable $releaseError) {
                    // A non-persistent PDO connection also releases its advisory locks on close.
                }
            }
        }
        if ($placedOrderId !== null) {
            header('Location: order-confirmation.php?id=' . $placedOrderId, true, 303);
            exit;
        }
    }
}

/* Display the same server-owned item set used by the transaction, including blocked products. */
$summaryIds = $checkoutAttemptValid ? $checkoutAttempt['selected_ids'] : $currentSelectedIds;
$cartItems = checkoutLoadItems($pdo, $isBuyNowCheckout, $buyNowProductId, $cartId, $summaryIds);
$summarySnapshot = checkoutSnapshot($cartItems);
$subtotal = array_sum(array_column($summarySnapshot, 'price_cents')) / 100;
$unavailableDisplay = [];
foreach ($cartItems as $item) {
    if ($item['status'] !== 'AVAILABLE' || $item['retired_at'] !== null) {
        $unavailableDisplay[] = $item['name'] . ' (#' . (int) $item['product_id'] . ')';
    }
}
$expectedItemCount = $isBuyNowCheckout ? 1 : count($summaryIds);
$missingDisplayItems = $checkoutAttemptValid ? checkoutMissingItems($checkoutAttempt['snapshot'], $cartItems) : [];
$selectionIsCurrent = $checkoutAttemptValid && ($isBuyNowCheckout
    || ($checkoutAttempt['cart_id'] === $cartId && $checkoutAttempt['selected_ids'] === $currentSelectedIds
        && $checkoutAttempt['selection_revision'] === cart_selection_revision($cartId)));
if ($unavailableDisplay !== []) {
    $errors[] = $isBuyNowCheckout ? 'Sorry, this piece is no longer available.' : cart_selection_unavailable_message($unavailableDisplay);
}
if ($missingDisplayItems !== [] || count($cartItems) !== $expectedItemCount) {
    $errors[] = $isBuyNowCheckout ? 'Sorry, this piece is no longer available.'
        : 'Selected items missing or changed: ' . ($missingDisplayItems === [] ? 'cart entries #' . implode(', #', $summaryIds) : implode(', ', $missingDisplayItems)) . '. Review your whole selection.';
}
$checkoutAttemptOwned = is_array($checkoutAttempt) && $checkoutAttempt['customer_id'] === $customerId
    && $checkoutAttempt['mode'] === ($isBuyNowCheckout ? 'buy_now' : 'cart');
$savedGcashQuote = $checkoutAttemptOwned ? ($checkoutAttempt['quote'] ?? null) : null;
$retainedProof = $checkoutAttemptOwned && !empty($checkoutAttempt['proof_path']);
$hasGcashEvidence = $checkoutAttemptOwned && ($retainedProof || !empty($checkoutAttempt['reference_valid']));
if ($hasGcashEvidence) {
    $formValues['gcash_reference'] = $checkoutAttempt['reference'] ?? $formValues['gcash_reference'];
}
$checkoutCanSubmit = $selectionIsCurrent && $cartItems !== [] && count($cartItems) === $expectedItemCount
    && $unavailableDisplay === [] && empty($checkoutAttempt['review_required']) && empty($checkoutAttempt['closed'])
    && $missingDisplayItems === []
    && $relatedPayment === null && (!$hasGcashEvidence || $gcashConfigured);
$errors = array_values(array_unique($errors));

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
$savedQuoteMatches = is_array($savedGcashQuote) && $savedGcashQuote['snapshot'] === $summarySnapshot
    && $savedGcashQuote['shipping_cents'] === $displayRateCents
    && $savedGcashQuote['delivery_method'] === $formValues['delivery_method']
    && $savedGcashQuote['region'] === ($formValues['delivery_method'] === 'STORE_PICKUP' ? '' : $formValues['region'])
    && $savedGcashQuote['receiver'] === hash('sha256', $gcashConfig['name'] . '|' . $gcashConfig['number']);
$gcashQuoteReady = $checkoutCanSubmit && $savedQuoteMatches;
if ($savedGcashQuote !== null && !$savedQuoteMatches && $formValues['payment_method'] === 'GCASH') {
    $errors[] = 'The original GCash amount no longer matches the current products or delivery details. Do not pay a new amount. If already paid, contact the shop with your receipt.';
}

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
if ($hasGcashEvidence && $gcashConfigured) {
    $formValues['payment_method'] = 'GCASH';
}
$errors = array_values(array_unique($errors));

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
            <p class="checkout-header__count"><?= $isBuyNowCheckout ? 'BUY NOW &middot; ' . count($cartItems) . ' SELECTED ITEM' : count($cartItems) . ' SELECTED ' . (count($cartItems) === 1 ? 'ITEM' : 'ITEMS') . ' FROM YOUR CART' ?></p>
        </div>
        <a class="link-subtle checkout-header__back" href="<?= hopia_e($checkoutBackUrl) ?>"><?= $isBuyNowCheckout ? 'Back to shop' : 'Back to cart' ?></a>
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

    <?php if ($hasGcashEvidence): ?>
        <div class="alert alert-warning" role="status">
            Your GCash <?= $retainedProof ? 'proof' : 'reference' ?> is retained for this checkout.
            <?php if (!empty($checkoutAttempt['reference'])): ?>Reference: <strong><?= hopia_e($checkoutAttempt['reference']) ?></strong>.<?php endif; ?>
            It is not automatically marked paid. Do not send another payment. Retry this same checkout only if eligible, or contact the shop with your receipt.
        </div>
    <?php endif; ?>

    <?php if (count($cartItems) === 0): ?>

        <div class="checkout-empty">
            <h2><?= $isBuyNowCheckout ? 'This piece cannot be checked out' : 'No items selected for checkout' ?></h2>
            <p><?= $isBuyNowCheckout ? 'Choose an available piece to start Buy Now again. Your cart has not changed.' : 'Select at least one item' ?></p>
            <div class="checkout-empty__actions">
                <a class="btn btn-primary" href="products.php">SHOP ALL FINDS</a>
                <a class="btn btn-ghost" href="cart.php">View cart</a>
            </div>
        </div>

    <?php else: ?>

        <div class="checkout-layout">
            <form id="checkout-form" class="checkout-form" method="POST" action="<?= hopia_e($checkoutUrl) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">
                <input type="hidden" name="checkout_token" value="<?= hopia_e(is_string($checkoutToken) ? $checkoutToken : '') ?>">
                <input type="hidden" name="gcash_quote_token" id="gcash-quote-token" value="<?= hopia_e($savedGcashQuote['token'] ?? '') ?>">
                <?php if ($isBuyNowCheckout): ?>
                    <input type="hidden" name="buy_now" value="<?= hopia_e($buyNowToken) ?>">
                <?php endif; ?>

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
                                    <?= $hasGcashEvidence ? 'disabled' : '' ?>
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
                                    <?= $hasGcashEvidence ? 'disabled' : '' ?>
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

                    <p class="alert alert-warning" id="gcash-cash-warning" <?= $savedGcashQuote === null || $formValues['payment_method'] === 'GCASH' ? 'hidden' : '' ?>>Only switch to cash if you have not sent the GCash payment. If already paid, choose GCash and submit your original receipt or contact the shop.</p>

                    <?php if ($gcashConfigured): ?>
                        <div class="gcash-checkout" id="gcash-checkout" <?= $formValues['payment_method'] === 'GCASH' ? '' : 'hidden' ?>>
                            <div class="gcash-checkout__head">
                                <?= hopia_checkout_icon('gcash') ?>
                                <p class="gcash-checkout__title"><?= $hasGcashEvidence ? 'Your submitted GCash payment' : 'Send your GCash payment' ?></p>
                            </div>
                            <dl class="gcash-checkout__details">
                                <div class="gcash-checkout__row">
                                    <dt>Amount due</dt>
                                    <dd class="gcash-checkout__amount" id="gcash-checkout-amount"><?= $savedGcashQuote === null ? 'Confirm the amount before paying' : '&#8369;' . number_format($savedGcashQuote['total_cents'] / 100, 2) ?></dd>
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
                            <button type="button" class="btn btn-secondary btn-sm" id="gcash-confirm-amount" <?= !$checkoutCanSubmit || $hasGcashEvidence ? 'disabled' : '' ?>>Confirm GCash amount</button>
                            <p class="alert alert-warning" id="gcash-quote-status" role="status" <?= $gcashQuoteReady ? 'hidden' : '' ?>>Confirm your selected items and delivery amount before sending payment. If you have already paid, do not send another payment.</p>
                            <?php if (!$hasGcashEvidence): ?>
                                <div class="field" id="gcash-restart-controls" <?= $savedGcashQuote === null ? 'hidden' : '' ?>>
                                    <label class="checkbox-label"><input type="checkbox" name="confirm_unpaid" value="1"> I have not sent this GCash payment.</label>
                                    <button type="submit" name="checkout_action" value="restart_checkout" formnovalidate class="btn btn-ghost btn-sm" id="gcash-restart-checkout">Restart checkout if not paid</button>
                                    <p class="hint">If you have already paid, keep this checkout and contact the shop instead.</p>
                                </div>
                            <?php endif; ?>
                            <?php if ($gcashConfig['has_qr']): ?>
                                <figure class="gcash-checkout__qr">
                                    <img src="<?= hopia_e(hopia_asset($gcashConfig['qr'])) ?>" alt="GCash QR code for <?= hopia_e($gcashConfig['name']) ?>" loading="lazy" width="160" height="160">
                                    <figcaption>Scan to pay in the GCash app</figcaption>
                                </figure>
                            <?php endif; ?>
                            <ol class="gcash-panel__steps" <?= $hasGcashEvidence || !$checkoutCanSubmit ? 'hidden' : '' ?>>
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
                                    <?= $checkoutAttemptValid && !empty($checkoutAttempt['reference_valid']) ? 'readonly' : '' ?>
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
                                <?php if ($retainedProof): ?><p class="hint">Your original uploaded proof is retained. No new payment or screenshot is required for this retry.</p><?php endif; ?>
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
                            <span><?= $isBuyNowCheckout ? 'Merchandise subtotal' : 'Selected merchandise subtotal' ?></span>
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
                            <?= !$checkoutCanSubmit || ($formValues['payment_method'] === 'GCASH' && (!$gcashQuoteReady || !$retainedProof || empty($checkoutAttempt['reference_valid']))) ? 'disabled' : '' ?>
                        >
                            <span class="checkout-submit__spinner" aria-hidden="true"></span>
                            <svg class="checkout-submit__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path>
                                <path d="M3 6h18"></path>
                                <path d="M16 10a4 4 0 0 1-8 0"></path>
                            </svg>
                            <span class="checkout-submit__label">PLACE ORDER</span>
                        </button>

                        <a class="checkout-back" href="<?= hopia_e($checkoutBackUrl) ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m15 18-6-6 6-6"></path>
                            </svg>
                            <?= $isBuyNowCheckout ? 'BACK TO SHOP' : 'BACK TO CART' ?>
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
            var gcashQuoteToken = document.getElementById('gcash-quote-token');
            var gcashConfirmAmount = document.getElementById('gcash-confirm-amount');
            var gcashQuoteStatus = document.getElementById('gcash-quote-status');
            var gcashCashWarning = document.getElementById('gcash-cash-warning');
            var gcashRestartControls = document.getElementById('gcash-restart-controls');
            var gcashRestartButton = document.getElementById('gcash-restart-checkout');
            var checkoutEligible = <?= json_encode($checkoutCanSubmit) ?>;
            var retainedGcashProof = <?= json_encode($retainedProof) ?>;
            var submittedGcashEvidence = <?= json_encode($hasGcashEvidence) ?>;
            var quoteBusy = false;
            var quoteError = '';
            var gcashQuote = <?= json_encode($savedGcashQuote === null ? null : [
                'quote_token' => $savedGcashQuote['token'],
                'total_cents' => $savedGcashQuote['total_cents'],
                'delivery_method' => $savedGcashQuote['delivery_method'],
                'region' => $savedGcashQuote['region'],
                'usable' => $gcashQuoteReady,
            ]) ?>;

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
                return retainedGcashProof || !!(gcashProofInput
                    && gcashProofInput.files
                    && gcashProofInput.files.length > 0);
            }

            function gcashQuoteMatchesSelection() {
                return gcashQuote && gcashQuote.usable && gcashQuote.delivery_method === currentMethod()
                    && gcashQuote.region === (currentMethod() === 'STORE_PICKUP' ? '' : (fields.region ? fields.region.value : ''));
            }

            function gcashRequirementsMet() {
                if (selectedPaymentMethod() !== 'GCASH') {
                    return true;
                }

                return !quoteBusy && gcashQuoteMatchesSelection() && gcashReferenceLooksValid(gcashReferenceValue())
                    && gcashProofSelected();
            }

            function refreshGcashCheckout() {
                var isGcash = selectedPaymentMethod() === 'GCASH';
                if (submitBtn && !submitBtn.classList.contains('is-loading')) {
                    submitBtn.disabled = !checkoutEligible || quoteBusy || !gcashRequirementsMet();
                }
                if (gcashCashWarning) {
                    gcashCashWarning.hidden = !gcashQuote || isGcash;
                }
                if (!gcashCheckout) {
                    return;
                }
                gcashCheckout.hidden = !isGcash;
                if (isGcash && gcashCheckoutAmount) {
                    gcashCheckoutAmount.textContent = gcashQuote
                        ? formatPeso(gcashQuote.total_cents) : 'Confirm the amount before paying';
                }

                /* GCash-only fields are required only while GCash is the
                 * active payment method; switching to cash must not leave
                 * stale native validation blocking a valid checkout. */
                if (gcashReferenceInput) {
                    gcashReferenceInput.required = isGcash;
                    gcashReferenceInput.disabled = !isGcash;
                }
                if (gcashProofInput) {
                    gcashProofInput.required = isGcash && !retainedGcashProof;
                    gcashProofInput.disabled = !isGcash || retainedGcashProof;
                }

                var met = gcashRequirementsMet();

                if (gcashRequirement) {
                    gcashRequirement.hidden = !isGcash || met;
                }

                if (gcashConfirmAmount) {
                    gcashConfirmAmount.disabled = !isGcash || quoteBusy || !checkoutEligible || submittedGcashEvidence;
                    gcashConfirmAmount.textContent = quoteBusy ? 'Confirming amount...' : 'Confirm GCash amount';
                }
                if (gcashQuoteStatus) {
                    gcashQuoteStatus.hidden = !quoteError && !!gcashQuoteMatchesSelection();
                    gcashQuoteStatus.textContent = quoteError || (gcashQuote
                        ? 'The original payment amount is kept. Restore its delivery details or, if you already paid, contact the shop. Do not pay a changed amount.'
                        : 'Confirm your selected items and delivery amount before paying. If you have already paid, do not send another payment.');
                }
                if (gcashRestartControls) {
                    gcashRestartControls.hidden = !gcashQuote;
                }
                if (gcashRestartButton) {
                    gcashRestartButton.disabled = !isGcash || !gcashQuote || quoteBusy || submittedGcashEvidence;
                }
            }

            if (gcashConfirmAmount) {
                gcashConfirmAmount.addEventListener('click', function (event) {
                    event.preventDefault();
                    if (quoteBusy || !checkoutEligible || submittedGcashEvidence) {
                        return;
                    }
                    var data = new FormData();
                    data.append('checkout_action', 'prepare_gcash');
                    data.append('csrf_token', form.elements.csrf_token.value);
                    data.append('checkout_token', form.elements.checkout_token.value);
                    data.append('delivery_method', currentMethod());
                    data.append('region', fields.region ? fields.region.value : '');
                    data.append('payment_method', 'GCASH');
                    if (form.elements.buy_now) {
                        data.append('buy_now', form.elements.buy_now.value);
                    }
                    quoteBusy = true;
                    quoteError = '';
                    refreshGcashCheckout();
                    fetch(form.action, {
                        method: 'POST',
                        body: data,
                        credentials: 'same-origin',
                        headers: {'X-Requested-With': 'XMLHttpRequest'}
                    }).then(function (response) {
                        return response.json();
                    }).then(function (payload) {
                        if (!payload.ok) {
                            throw new Error(payload.message);
                        }
                        gcashQuote = payload;
                        gcashQuote.usable = true;
                        gcashQuoteToken.value = payload.quote_token;
                    }).catch(function (error) {
                        quoteError = error.message || 'We could not confirm the amount. Do not pay until the checkout amount is confirmed.';
                        if (gcashQuote) {
                            gcashQuote.usable = false;
                        }
                    }).finally(function () {
                        quoteBusy = false;
                        refreshGcashCheckout();
                    });
                });
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
                        'input[value="' + (submittedGcashEvidence ? 'GCASH' : (isPickup ? 'CASH_ON_PICKUP' : 'COD')) + '"]'
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
                if (event.submitter && event.submitter.name === 'checkout_action') {
                    // Quote confirmation and an explicitly unpaid restart do not create an order.
                    return;
                }
                if (!checkoutEligible || quoteBusy) {
                    event.preventDefault();
                    return;
                }
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
                    } else if (!gcashQuoteMatchesSelection() && gcashConfirmAmount) {
                        gcashConfirmAmount.focus();
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

            window.addEventListener('pageshow', function (event) {
                if (event.persisted) {
                    window.location.reload();
                    return;
                }
                var label = submitBtn.querySelector('.checkout-submit__label');

                submitBtn.disabled = !checkoutEligible;
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
