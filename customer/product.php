<?php

session_start();

require_once __DIR__ . '/../includes/database.php';

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$product = null;
$images = [];

if ($productId !== false && $productId !== null && $productId > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, name, category, description, condition_label, defects,
                price, size, color, status
         FROM products
         WHERE id = :id'
    );

    $stmt->execute([':id' => $productId]);

    $product = $stmt->fetch();

    if ($product !== false) {
        $imageStmt = $pdo->prepare(
            'SELECT id, image_path
             FROM product_images
             WHERE product_id = :product_id
             ORDER BY sort_order ASC, id ASC'
        );

        $imageStmt->execute([':product_id' => $productId]);

        $images = $imageStmt->fetchAll();
    }
}

$productNotFound = ($product === false || $product === null);

$isSold = $product !== null && ($product['status'] ?? '') === 'SOLD';

require_once __DIR__ . '/../includes/ui.php';

define('HUPIA_BASE', '..');

$page_title = $productNotFound
    ? 'Product Not Found - ' . hopia_site_name()
    : htmlspecialchars($product['name']) . ' - ' . hopia_site_name();
$page_description = $productNotFound
    ? 'This product is not available.'
    : 'Preloved ' . ($product['category'] ?? '') . ' - ' . ($product['name'] ?? '');
$ui_active = 'products.php';
$body_class = 'product-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';

?>

<?php if ($productNotFound): ?>

    <div class="empty-state">
        <h2>Product Not Found</h2>
        <p>This product does not exist or is no longer available.</p>
        <p><a href="products.php" class="btn btn-primary btn-sm">Back to Shop</a></p>
    </div>

<?php else: ?>

    <nav class="breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb__list">
            <li class="breadcrumb__item"><a href="products.php">Shop</a></li>
            <li class="breadcrumb__item" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M6 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </li>
            <li class="breadcrumb__item"><?= hopia_e($product['name']) ?></li>
        </ol>
    </nav>

    <div class="product-layout">

        <div class="product-gallery">
            <?php if (count($images) > 0): ?>
                <div class="gallery-main">
                    <img
                        id="gallery-main-image"
                        src="<?= hopia_e('../' . ltrim($images[0]['image_path'], '/')) ?>"
                        alt="<?= hopia_e($product['name']) ?>"
                        class="gallery-main__image"
                    >
                </div>

                <?php if (count($images) > 1): ?>
                    <div class="gallery-thumbs" role="list" aria-label="Product images">
                        <?php foreach ($images as $index => $image): ?>
                            <button
                                type="button"
                                class="gallery-thumb<?= $index === 0 ? ' is-active' : '' ?>"
                                data-image="<?= hopia_e('../' . ltrim($image['image_path'], '/')) ?>"
                                aria-label="View image <?= $index + 1 ?>"
                                role="listitem"
                            >
                                <img
                                    src="<?= hopia_e('../' . ltrim($image['image_path'], '/')) ?>"
                                    alt=""
                                    loading="lazy"
                                >
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="gallery-placeholder">
                    <svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <rect x="6" y="10" width="36" height="28" rx="2" stroke="currentColor" stroke-width="2"/>
                        <circle cx="16" cy="22" r="4" stroke="currentColor" stroke-width="2"/>
                        <path d="M6 32l10-8 8 6 6-4 12 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>No image</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="product-info">
            <p class="product-category"><?= hopia_e(ucfirst(strtolower($product['category']))) ?></p>

            <h1 class="product-name"><?= hopia_e($product['name']) ?></h1>

            <p class="product-price price">₱<?= number_format((float) $product['price'], 2) ?></p>

            <div class="product-meta">
                <?php if (!empty(trim($product['size'] ?? ''))): ?>
                    <div class="product-meta__item">
                        <span class="product-meta__label">Size</span>
                        <span class="product-meta__value"><?= hopia_e($product['size']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty(trim($product['color'] ?? ''))): ?>
                    <div class="product-meta__item">
                        <span class="product-meta__label">Color</span>
                        <span class="product-meta__value"><?= hopia_e($product['color']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="product-meta__item">
                    <span class="product-meta__label">Condition</span>
                    <span class="product-meta__value"><?= hopia_e($product['condition_label']) ?></span>
                </div>
            </div>

            <?php if (!empty(trim($product['description'] ?? ''))): ?>
                <div class="product-section">
                    <h3 class="product-section__title">Description</h3>
                    <p class="product-description"><?= nl2br(hopia_e($product['description'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty(trim($product['defects'] ?? ''))): ?>
                <div class="product-section">
                    <h3 class="product-section__title">Defects</h3>
                    <p class="product-defects"><?= nl2br(hopia_e($product['defects'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($isSold): ?>
                <div class="product-sold-notice">
                    <p class="product-sold-notice__text">This piece is no longer available.</p>
                    <a href="products.php" class="btn btn-secondary btn-block">Continue Shopping</a>
                </div>
            <?php else: ?>
                <?php if (isset($_SESSION['customer_id'])): ?>
                    <?php $_SESSION['csrf_token_cart'] = $_SESSION['csrf_token_cart'] ?? bin2hex(random_bytes(32)); $csrfToken = $_SESSION['csrf_token_cart']; ?>
                    <form class="product-form" id="add-to-cart-form" method="POST" action="cart-add.php">
                        <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">
                        <input type="hidden" name="product_id" value="<?= $productId ?>">
                        <input type="hidden" name="return_to" value="product.php?id=<?= $productId ?>">
                        <button type="submit" class="btn btn-primary btn-block btn-lg">Add to Cart</button>
                    </form>
                <?php else: ?>
                    <p class="product-auth-notice">
                        <a href="login.php">Log in</a> to add this piece to your cart.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<div id="cart-toast" class="toast" role="status" aria-live="polite">
    <div class="toast__content">
        <svg class="toast__icon" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M5 10l4 4 6-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span>Added to cart</span>
    </div>
    <a href="cart.php" class="btn btn-sm">View Cart</a>
    <button type="button" class="toast__close" aria-label="Dismiss">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
    </button>
</div>

<script>
(function() {
    const thumbs = document.querySelectorAll('.gallery-thumb');
    const mainImage = document.getElementById('gallery-main-image');

    thumbs.forEach(function(thumb) {
        thumb.addEventListener('click', function() {
            thumbs.forEach(function(t) { t.classList.remove('is-active'); });
            this.classList.add('is-active');
            if (mainImage) {
                mainImage.src = this.dataset.image;
            }
        });
    });

    const form = document.getElementById('add-to-cart-form');
    const toast = document.getElementById('cart-toast');

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                if (response.redirected && response.url) {
                    window.location.href = response.url;
                    return;
                }
                window.location.href = form.action.replace('cart-add.php', 'products.php');
            })
            .catch(function() {
                form.submit();
            });
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('cart_success') === '1' && toast) {
        toast.classList.add('is-visible');
        setTimeout(function() {
            toast.classList.remove('is-visible');
        }, 5000);
    }

    if (toast) {
        const closeBtn = toast.querySelector('.toast__close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                toast.classList.remove('is-visible');
            });
        }
    }
})();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php';