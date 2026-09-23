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

require_once __DIR__ . '/../includes/ui.php';

$page_title = 'Your Cart - ' . hopia_site_name();

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<section class="cart-page">
    <header class="cart-header">
        <h1>YOUR CART</h1>
        <?php if (count($cartItems) > 0): ?>
            <p class="cart-header__count"><?= count($cartItems) ?> <?= count($cartItems) === 1 ? 'item' : 'items' ?></p>
        <?php endif; ?>
    </header>

    <?php if (count($cartItems) === 0): ?>

        <div class="cart-empty">
            <div class="cart-empty__icon" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
            </div>
            <h2>Your Cart Is Empty</h2>
            <p>Discover unique pre-loved pieces waiting for a new home.</p>
            <a class="btn btn-primary" href="products.php">Shop All Finds</a>
        </div>

    <?php else: ?>

        <div class="cart-layout">
            <div class="cart-items" role="list">
                <?php foreach ($cartItems as $item): ?>
                    <?php
                    $imagePath = trim($item['image_path'] ?? '');
                    $size = trim($item['size'] ?? '');
                    $color = trim($item['color'] ?? '');
                    $category = trim($item['category'] ?? '');
                    ?>
                    <article class="cart-item" role="listitem">
                        <div class="cart-item__image">
                            <?php if ($imagePath !== ''): ?>
                                <img
                                    src="<?= hopia_e('../' . ltrim($imagePath, '/')) ?>"
                                    alt="<?= hopia_e($item['name']) ?>"
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <div class="cart-item__placeholder">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="cart-item__details">
                            <div class="cart-item__header">
                                <h3 class="cart-item__name"><?= hopia_e($item['name']) ?></h3>
                                <p class="price cart-item__price">₱<?= number_format((float) $item['price'], 2) ?></p>
                            </div>
                            <div class="cart-item__meta">
                                <?php if ($category !== ''): ?>
                                    <span class="cart-item__meta-item"><?= hopia_e(ucfirst(strtolower($category))) ?></span>
                                <?php endif; ?>
                                <?php if ($size !== ''): ?>
                                    <span class="cart-item__meta-item">Size: <?= hopia_e($size) ?></span>
                                <?php endif; ?>
                                <?php if ($color !== ''): ?>
                                    <span class="cart-item__meta-item">Color: <?= hopia_e($color) ?></span>
                                <?php endif; ?>
                            </div>
                            <form class="cart-item__actions" method="POST" action="cart-remove.php">
                                <input type="hidden" name="cart_item_id" value="<?= (int) $item['cart_item_id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">
                                <button type="submit" class="cart-item__remove">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    </svg>
                                    Remove
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <aside class="cart-summary" aria-labelledby="cart-summary-heading">
                <div class="cart-summary__inner">
                    <h2 id="cart-summary-heading" class="cart-summary__title">Order Summary</h2>

                    <div class="cart-summary__row cart-summary__row--total">
                        <span class="cart-summary__row-label">Order total before shipping</span>
                        <span class="cart-summary__row-value price">₱<?= number_format($subtotal, 2) ?></span>
                    </div>

                    <div class="cart-summary__row cart-summary__row--muted">
                        <span class="cart-summary__row-label">Shipping</span>
                        <span class="cart-summary__row-value">To be confirmed</span>
                    </div>

                    <hr class="cart-summary__divider">

                    <div class="cart-summary__actions">
                        <a class="btn btn-primary" href="checkout.php">Proceed to Checkout</a>
                        <a class="cart-summary__continue" href="products.php">Continue Shopping</a>
                    </div>
                </div>
            </aside>
        </div>

    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>