<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

$allowedCategories = ['SHIRTS', 'PANTS', 'SHORTS'];

$search = trim($_GET['search'] ?? '');

$category = $_GET['category'] ?? '';

if (!in_array($category, $allowedCategories, true)) {
    $category = '';
}

$sql = "SELECT p.id, p.name, p.category, p.price, p.size, p.color, p.status, pi.image_path
     FROM products p
     LEFT JOIN product_images pi
         ON pi.id = (
             SELECT pi2.id
             FROM product_images pi2
             WHERE pi2.product_id = p.id
             ORDER BY pi2.sort_order ASC, pi2.id ASC
             LIMIT 1
         )
     WHERE p.status IN ('AVAILABLE', 'SOLD')";

$params = [];

if ($category !== '') {
    $sql .= ' AND p.category = :category';
    $params[':category'] = $category;
}

if ($search !== '') {
    $sql .= ' AND p.name LIKE :search';
    $params[':search'] = '%' . $search . '%';
}

$sql .= " ORDER BY CASE p.status WHEN 'AVAILABLE' THEN 0 ELSE 1 END, p.created_at DESC";

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$products = $stmt->fetchAll();

$hasFilters = ($search !== '' || $category !== '');

$availableCount = 0;

foreach ($products as $product) {
    if (($product['status'] ?? '') === 'AVAILABLE') {
        $availableCount++;
    }
}

require_once __DIR__ . '/../includes/ui.php';

define('HUPIA_BASE', '..');

$page_title = 'Shop - ' . hopia_site_name();
$page_description = 'Preloved shirts, pants, and shorts at ' . hopia_site_name() . '.';
$ui_active = 'products.php';
$body_class = 'shop-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';

?>

<section class="shop-intro">
    <p class="shop-intro__eyebrow">Preloved &amp; ready to wear</p>
    <h1>Shop All Finds</h1>
    <p class="shop-intro__copy">
        Every piece is one-of-a-kind. Browse what's in store and grab it before it's gone.
    </p>
</section>

<section class="shop-filters" aria-label="Product filters">
    <form class="shop-search" method="GET" action="products.php" role="search">
        <label class="sr-only" for="shop-search">Search pieces</label>
        <input
            type="search"
            id="shop-search"
            name="search"
            placeholder="Search pieces..."
            value="<?= hopia_e($search) ?>"
        >
        <?php if ($category !== ''): ?>
            <input type="hidden" name="category" value="<?= hopia_e($category) ?>">
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
    </form>

    <nav class="shop-categories" aria-label="Categories">
        <a
            class="shop-category<?= $category === '' ? ' is-active' : '' ?>"
            href="products.php?search=<?= urlencode($search) ?>"
            <?= $category === '' ? 'aria-current="page"' : '' ?>
        >All</a>

        <?php foreach ($allowedCategories as $cat): ?>
            <a
                class="shop-category<?= $category === $cat ? ' is-active' : '' ?>"
                href="products.php?category=<?= $cat ?>&search=<?= urlencode($search) ?>"
                <?= $category === $cat ? 'aria-current="page"' : '' ?>
            ><?= hopia_e(ucfirst(strtolower($cat))) ?></a>
        <?php endforeach; ?>
    </nav>
</section>

<?php if (count($products) === 0): ?>

    <?php if ($hasFilters): ?>
        <div class="empty-state">
            <p>No products found matching your search or filters.</p>
            <p><a class="btn btn-secondary btn-sm" href="products.php">Clear filters</a></p>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <p>No products are available right now. Please check back later.</p>
        </div>
    <?php endif; ?>

<?php else: ?>

    <p class="shop-count text-small muted">
        Showing <?= count($products) ?> piece<?= count($products) === 1 ? '' : 's' ?>
        <?php if ($availableCount !== count($products)): ?>
            &middot; <?= $availableCount ?> available
        <?php endif; ?>
    </p>

    <div class="shop-grid">

        <?php foreach ($products as $product): ?>

            <?php
            $productId = (int) $product['id'];

            $isSold = ($product['status'] ?? '') === 'SOLD';

            $productUrl = 'product.php?id=' . $productId;

            $imagePath = trim($product['image_path'] ?? '');

            $size = trim($product['size'] ?? '');

            $color = trim($product['color'] ?? '');
            ?>

            <article class="shop-card<?= $isSold ? ' is-sold' : '' ?>">

                <div class="shop-card__image">
                    <?php if ($imagePath !== ''): ?>
                        <?php if (!$isSold): ?>
                            <a href="<?= hopia_e($productUrl) ?>">
                        <?php endif; ?>
                                <img
                                    src="<?= hopia_e('../' . ltrim($imagePath, '/')) ?>"
                                    alt="<?= hopia_e($product['name']) ?>"
                                    loading="lazy"
                                >
                        <?php if (!$isSold): ?>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="shop-card__placeholder">No image</span>
                    <?php endif; ?>

                    <?php if ($isSold): ?>
                        <span class="shop-card__sold-badge">Sold</span>
                    <?php endif; ?>
                </div>

                <div class="shop-card__body">
                    <p class="shop-card__category"><?= hopia_e(ucfirst(strtolower($product['category']))) ?></p>

                    <h2 class="shop-card__name">
                        <?php if (!$isSold): ?>
                            <a href="<?= hopia_e($productUrl) ?>"><?= hopia_e($product['name']) ?></a>
                        <?php else: ?>
                            <?= hopia_e($product['name']) ?>
                        <?php endif; ?>
                    </h2>

                    <?php if ($size !== '' || $color !== ''): ?>
                        <p class="shop-card__meta">
                            <?= hopia_e(implode(' &middot; ', array_filter([$size, $color], static function ($value) {
                                return $value !== '';
                            }))) ?>
                        </p>
                    <?php endif; ?>

                    <p class="price shop-card__price">₱<?= number_format((float) $product['price'], 2) ?></p>

                    <?php if ($isSold): ?>
                        <span class="shop-card__sold-label">SOLD</span>
                    <?php endif; ?>
                </div>

            </article>

        <?php endforeach; ?>

    </div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/ui.footer.php';
