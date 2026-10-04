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
            <p class="product-eyebrow">Curated Thrift Find</p>

            <p class="product-category"><?= hopia_e(ucfirst(strtolower($product['category']))) ?></p>

            <h1 class="product-name"><?= hopia_e($product['name']) ?></h1>

            <p class="product-price price">₱<?= number_format((float) $product['price'], 2) ?></p>

            <div class="product-meta">
                <?php if (!empty(trim($product['size'] ?? ''))): ?>
                    <div class="product-meta__item">
                        <span class="product-meta__label">
                            <svg class="product-meta__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="2.5" y="9" width="19" height="6" rx="1.5" stroke="currentColor" stroke-width="2"/>
                                <path d="M6.5 9v3M10 9v2.2M13.5 9v3M17 9v2.2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Size
                        </span>
                        <span class="product-meta__value"><?= hopia_e($product['size']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty(trim($product['color'] ?? ''))): ?>
                    <div class="product-meta__item">
                        <span class="product-meta__label">
                            <svg class="product-meta__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M12 3a9 9 0 1 0 0 18c1.4 0 2-.8 2-1.9 0-.8-.4-1.4-.9-2-.4-.5-.6-1-.4-1.6.3-.8 1-1 2.1-1h1.7c2 0 3.5-1.6 3.5-3.7C20 6.3 16.4 3 12 3z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                <circle cx="7.6" cy="11" r="0.9" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="10.4" cy="6.9" r="0.9" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="15" cy="8" r="0.9" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            Color
                        </span>
                        <span class="product-meta__value"><?= hopia_e($product['color']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="product-meta__item">
                    <span class="product-meta__label">
                        <svg class="product-meta__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 2.8l2.2 1.6 2.7-.2 1 2.6 2.3 1.4-.8 2.6.8 2.6-2.3 1.4-1 2.6-2.7-.2L12 21.2l-2.2-1.6-2.7.2-1-2.6-2.3-1.4.8-2.6-.8-2.6 2.3-1.4 1-2.6 2.7.2L12 2.8z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                            <path d="M8.8 12.2l2.1 2.1 4.3-4.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Condition
                    </span>
                    <span class="product-meta__value"><?= hopia_e($product['condition_label']) ?></span>
                </div>
            </div>

            <?php if (!empty(trim($product['description'] ?? ''))): ?>
                <div class="product-section">
                    <h3 class="product-section__title">
                        <span class="product-section__heading">
                            <svg class="product-section__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                <path d="M14 3v5h5" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                <path d="M9 13h6M9 17h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Description
                        </span>
                    </h3>
                    <p class="product-description"><?= nl2br(hopia_e($product['description'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty(trim($product['defects'] ?? ''))): ?>
                <div class="product-section">
                    <h3 class="product-section__title">
                        <span class="product-section__heading">
                            <svg class="product-section__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
                                <path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <path d="M9.5 11.5l1.5 1.5 3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Defects
                        </span>
                    </h3>
                    <p class="product-defects"><?= nl2br(hopia_e($product['defects'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($isSold): ?>
                <p class="product-availability product-availability--sold" aria-label="Status: Sold">Sold</p>
                <div class="product-sold-notice">
                    <p class="product-sold-notice__text">This piece is no longer available.</p>
                    <a href="products.php" class="btn btn-secondary btn-block">Continue Shopping</a>
                </div>
            <?php else: ?>
                <p class="product-availability product-availability--available" aria-label="Status: Available">Available</p>
                <?php if (isset($_SESSION['customer_id'])): ?>
                    <?php $_SESSION['csrf_token_cart'] = $_SESSION['csrf_token_cart'] ?? bin2hex(random_bytes(32)); $csrfToken = $_SESSION['csrf_token_cart']; ?>
                    <form class="product-form" id="add-to-cart-form" method="POST" action="cart-add.php">
                        <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>">
                        <input type="hidden" name="product_id" value="<?= $productId ?>">
                        <input type="hidden" name="return_to" value="product.php?id=<?= $productId ?>">
                        <button type="submit" class="btn btn-primary btn-block btn-lg product-form__btn">
                            <svg class="product-form__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M5 8h14l-1.4 11.2a2 2 0 0 1-2 1.8H8.4a2 2 0 0 1-2-1.8L5 8z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                <path d="M9 8V6a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <span>Add to Cart</span>
                            <svg class="product-form__icon product-form__icon--arrow" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
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