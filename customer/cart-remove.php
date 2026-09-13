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
 * Verify the CSRF token. If it is missing or invalid, do
 * nothing and redirect safely back to the cart.
 */

$submittedToken = $_POST['csrf_token'] ?? '';

if (
    $submittedToken === '' ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    header('Location: cart.php');
    exit;
}

/*
 * Validate the cart item ID as a positive integer.
 */

$cartItemId = $_POST['cart_item_id'] ?? '';

$filteredCartItemId = filter_var($cartItemId, FILTER_VALIDATE_INT);

if (
    $filteredCartItemId === false ||
    $filteredCartItemId < 1
) {
    header('Location: cart.php');
    exit;
}

$cartItemId = $filteredCartItemId;

/*
 * Delete only the specified cart item and only if it belongs
 * to the logged-in customer's cart. The customer ID comes from
 * the session, never from the browser. The product itself is
 * never deleted and products.status is never changed.
 */

try {
    $stmt = $pdo->prepare(
        'DELETE ci
         FROM cart_items ci
         INNER JOIN carts c ON c.id = ci.cart_id
         WHERE ci.id = :cart_item_id
           AND c.customer_id = :customer_id'
    );

    $stmt->execute([
        ':cart_item_id' => $cartItemId,
        ':customer_id' => $customerId
    ]);
} catch (PDOException $e) {
    /*
     * Do not expose database errors to the customer.
     */
}

header('Location: cart.php');
exit;
