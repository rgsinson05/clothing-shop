<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];
$cartItemCount = 0;

try {
    $cartStmt = $pdo->prepare(
        'SELECT COUNT(ci.id)
         FROM carts c
         LEFT JOIN cart_items ci ON ci.cart_id = c.id
         WHERE c.customer_id = :customer_id'
    );

    $cartStmt->execute([':customer_id' => $customerId]);
    $cartItemCount = (int) $cartStmt->fetchColumn();
} catch (PDOException $e) {
    // Keep database details out of the customer-facing page.
    $cartItemCount = 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Hopia's Ukay-Ukay</title>
</head>
<body>

    <h1>Hopia's Ukay-Ukay</h1>

    <p>
        <a href="cart.php">&larr; Back to Cart</a>
    </p>

    <hr>

    <h2>Checkout</h2>

    <?php if ($cartItemCount === 0): ?>

        <p>Your cart is empty. Add an item before continuing to checkout.</p>

        <p>
            <a href="products.php">Browse Products</a>
        </p>

    <?php else: ?>

        <p>
            Checkout and order placement will be implemented in the next stage.
        </p>

        <p>
            <a href="cart.php">Return to Cart</a>
        </p>

    <?php endif; ?>

</body>
</html>
