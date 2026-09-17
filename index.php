<?php

session_start();

require_once __DIR__ . '/includes/database.php';

$stmt = $pdo->query(
    "SELECT p.id, p.name, p.category, p.price, p.size, p.color, pi.image_path
     FROM products p
     LEFT JOIN product_images pi
         ON pi.id = (
             SELECT pi2.id
             FROM product_images pi2
             WHERE pi2.product_id = p.id
             ORDER BY pi2.sort_order ASC, pi2.id ASC
             LIMIT 1
         )
     WHERE p.status = 'AVAILABLE'
     ORDER BY p.created_at DESC
     LIMIT 6"
);

$products = $stmt->fetchAll();

define('HUPIA_BASE', '.');

$page_title = "Hopia's Ukay-Ukay - Pre-loved Finds";
$page_description = 'Pre-loved finds, handpicked for everyday style. Browse the latest shirts, pants, and shorts.';
$ui_active = 'index.php';
$ui_path_prefix = 'customer/';
$ui_brand_href = 'index.php';
$body_class = 'home-page';

require __DIR__ . '/includes/ui.head.php';
require __DIR__ . '/includes/ui.header.php';
?>

<section class="home-intro" aria-labelledby="home-title">
    <p class="home-intro__eyebrow">Thoughtfully rehomed clothing</p>
    <h1 id="home-title">Hopia's ukay-ukay</h1>
    <p class="home-intro__copy">
        Pre-loved finds, handpicked for everyday style. Browse our latest pieces and find something that fits you.
    </p>
    <a class="btn btn-primary" href="customer/products.php">Shop now</a>
</section>

<section class="home-section" aria-labelledby="newest-pieces-title">
    <div class="home-section__heading">
        <div>
            <p class="home-section__eyebrow">Recently added</p>
            <h2 id="newest-pieces-title">Newest pieces</h2>
        </div>
        <a class="home-section__link" href="customer/products.php">View all products</a>
    </div>

    <?php if (count($products) === 0): ?>
        <div class="empty-state">
            <p>No products are available right now. Please check back later.</p>
        </div>
    <?php else: ?>
        <div class="home-product-grid">
            <?php foreach ($products as $product): ?>
                <?php
                $productId = (int) $product['id'];
                $productUrl = 'customer/product.php?id=' . $productId;
                $imagePath = trim($product['image_path'] ?? '');
                $size = trim($product['size'] ?? '');
                $color = trim($product['color'] ?? '');
                ?>
                <article class="home-product-card">
                    <a class="home-product-card__image" href="<?= hopia_e($productUrl) ?>">
                        <?php if ($imagePath !== ''): ?>
                            <img
                                src="<?= hopia_e(ltrim($imagePath, '/')) ?>"
                                alt="<?= hopia_e($product['name']) ?>"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <span class="home-product-card__placeholder">No image</span>
                        <?php endif; ?>
                    </a>
                    <div class="home-product-card__body">
                        <p class="home-product-card__category"><?= hopia_e(ucfirst(strtolower($product['category']))) ?></p>
                        <h3>
                            <a href="<?= hopia_e($productUrl) ?>"><?= hopia_e($product['name']) ?></a>
                        </h3>
                        <?php if ($size !== '' || $color !== ''): ?>
                            <p class="home-product-card__meta">
                                <?= hopia_e(implode(' · ', array_filter([$size, $color], static function ($value) {
                                    return $value !== '';
                                }))) ?>
                            </p>
                        <?php endif; ?>
                        <p class="price">₱<?= number_format((float) $product['price'], 2) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="home-section home-categories" aria-labelledby="categories-title">
    <div class="home-section__heading">
        <div>
            <p class="home-section__eyebrow">Find your next staple</p>
            <h2 id="categories-title">Shop by category</h2>
        </div>
    </div>

    <div class="home-category-grid">
        <a class="home-category" href="customer/products.php?category=SHIRTS">
            <span>Shirts</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
        <a class="home-category" href="customer/products.php?category=PANTS">
            <span>Pants</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
        <a class="home-category" href="customer/products.php?category=SHORTS">
            <span>Shorts</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
    </div>

    <div class="home-categories__action">
        <a class="btn btn-secondary" href="customer/products.php">View all products</a>
    </div>
</section>

<?php require __DIR__ . '/includes/ui.footer.php'; ?>
