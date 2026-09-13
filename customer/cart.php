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
 * Ensure a CSRF token exists for the removal form.
 */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
 * Retrieve the customer's cart, if one exists.
 */

$cartStmt = $pdo->prepare(
    'SELECT id
     FROM carts
     WHERE customer_id = :customer_id'
);

$cartStmt->execute([':customer_id' => $customerId]);

$cart = $cartStmt->fetch();

$cartItems = [];
$subtotal = 0.0;

if ($cart !== false) {
    /*
     * Fetch all cart items joined with products and each
     * product's first image (sort_order ASC, id ASC).
     */

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

    /*
     * Each item is one unique product, so every quantity is 1.
     */

    foreach ($cartItems as $item) {
        $subtotal += (float) $item['price'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart - Hopia's Ukay-Ukay</title>

    <style>
        .cart-table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 16px;
        }

        .cart-table th,
        .cart-table td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        .cart-table img,
        .image-placeholder {
            display: block;
            width: 100px;
            height: 120px;
            object-fit: cover;
            background: #f0f0f0;
        }

        .image-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
        }

        .cart-subtotal {
            font-weight: bold;
        }

        .cart-summary {
            border: 1px solid #ccc;
            margin-top: 24px;
            max-width: 420px;
            padding: 16px;
        }

        .cart-summary p {
            margin: 8px 0;
        }

        .checkout-link {
            display: inline-block;
            margin-top: 8px;
            padding: 10px 16px;
            border: 2px solid #333;
            color: #333;
            text-decoration: none;
        }

        .checkout-link:hover {
            background: #333;
            color: #fff;
        }
    </style>
</head>
<body>

    <h1>Hopia's Ukay-Ukay</h1>

    <h2>Your Cart</h2>

    <?php if (count($cartItems) === 0): ?>

        <p>Your cart is empty.</p>

        <p>
            <a href="products.php">Continue Shopping</a>
        </p>

    <?php else: ?>

        <table class="cart-table">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Product</th>
                    <th>Size</th>
                    <th>Color</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>

                <?php foreach ($cartItems as $item): ?>

                    <?php
                    $imagePath = trim($item['image_path'] ?? '');

                    $size = trim($item['size'] ?? '') !== ''
                        ? htmlspecialchars($item['size'])
                        : '&mdash;';

                    $color = trim($item['color'] ?? '') !== ''
                        ? htmlspecialchars($item['color'])
                        : '&mdash;';
                    ?>

                    <tr>
                        <td>
                            <?php if ($imagePath !== ''): ?>

                                <img
                                    src="<?= htmlspecialchars('../' . ltrim($imagePath, '/')) ?>"
                                    alt="<?= htmlspecialchars($item['name']) ?>"
                                >

                            <?php else: ?>

                                <div class="image-placeholder">No image</div>

                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= $size ?></td>
                        <td><?= $color ?></td>
                        <td>₱<?= number_format((float) $item['price'], 2) ?></td>
                        <td>1</td>
                        <td>
                            <form method="POST" action="cart-remove.php">
                                <input type="hidden" name="cart_item_id" value="<?= (int) $item['cart_item_id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <button type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>

                <?php endforeach; ?>

            </tbody>
        </table>

        <p>
            <a href="products.php">Continue Shopping</a>
        </p>

    <?php endif; ?>

    <section class="cart-summary" aria-labelledby="cart-summary-heading">
        <h3 id="cart-summary-heading">Cart Summary</h3>

        <p>Items: <?= count($cartItems) ?></p>
        <p class="cart-subtotal">
            Subtotal: ₱<?= number_format($subtotal, 2) ?>
        </p>
        <p>Shipping: Not calculated yet</p>
        <p>Total: Not calculated yet</p>

        <?php if (count($cartItems) > 0): ?>
            <p>
                <a class="checkout-link" href="checkout.php">Proceed to Checkout</a>
            </p>
        <?php endif; ?>
    </section>

</body>
</html>
