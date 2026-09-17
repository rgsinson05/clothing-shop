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
 * CSRF token validation.
 * Uses timing-safe comparison to prevent timing attacks.
 */

$csrfToken = filter_input(INPUT_POST, 'csrf_token', FILTER_DEFAULT);
$storedToken = $_SESSION['csrf_token_cart'] ?? '';

if ($csrfToken === false || $storedToken === '' || !hash_equals($storedToken, $csrfToken)) {
    unset($_SESSION['csrf_token_cart']);
    header('Location: products.php');
    exit;
}

unset($_SESSION['csrf_token_cart']);

/*
 * Validate the submitted product ID as a positive integer.
 */

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    header('Location: products.php');
    exit;
}

/*
 * Fetch the product and only allow AVAILABLE products.
 * Availability is re-checked here because hidden form
 * fields cannot be trusted.
 */

$productStmt = $pdo->prepare(
    "SELECT id
     FROM products
     WHERE id = :id AND status = 'AVAILABLE'"
);

$productStmt->execute([':id' => $productId]);

if ($productStmt->fetch() === false) {
    header('Location: products.php');
    exit;
}

/*
 * Create the customer's cart if it does not already exist.
 * customer_id is UNIQUE, so INSERT IGNORE keeps concurrent
 * requests from failing.
 */

$createCartStmt = $pdo->prepare(
    'INSERT IGNORE INTO carts (customer_id)
     VALUES (:customer_id)'
);

$createCartStmt->execute([':customer_id' => $customerId]);

/*
 * Fetch the customer's cart.
 */

$cartStmt = $pdo->prepare(
    'SELECT id
     FROM carts
     WHERE customer_id = :customer_id'
);

$cartStmt->execute([':customer_id' => $customerId]);

$cart = $cartStmt->fetch();

if ($cart === false) {
    header('Location: products.php');
    exit;
}

$cartId = (int) $cart['id'];

/*
 * Add the product to the cart.
 *
 * Each product is one unique physical item, so there is no
 * quantity. UNIQUE(cart_id, product_id) is respected with
 * INSERT IGNORE, so submitting the same product twice does
 * not create a duplicate cart item.
 *
 * The cart does not reserve products, so the product's
 * status is intentionally left unchanged.
 */

$addItemStmt = $pdo->prepare(
    'INSERT IGNORE INTO cart_items (cart_id, product_id)
     VALUES (:cart_id, :product_id)'
);

$addItemStmt->execute([
    ':cart_id' => $cartId,
    ':product_id' => $productId,
]);

/*
 * Redirect to the cart page or return URL.
 * Only allow local customer pages to prevent open redirects.
 */

$returnTo = filter_input(INPUT_POST, 'return_to', FILTER_SANITIZE_URL);

$allowedReturnPattern = '/^product\.php\?id=[0-9]+(&.*)?$/';

if (
    $returnTo !== null && $returnTo !== ''
    && preg_match($allowedReturnPattern, $returnTo) === 1
) {
    header('Location: ' . $returnTo . '&cart_success=1', true, 303);
    exit;
}

header('Location: cart.php', true, 303);
exit;
