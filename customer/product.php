<?php

require_once __DIR__ . '/../includes/database.php';

/*
 * Validate the product ID from GET.
 */

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$product = null;
$images = [];

if ($productId !== false && $productId !== null && $productId > 0) {

    /*
     * Fetch the product only if it is AVAILABLE.
     */

    $stmt = $pdo->prepare(
        'SELECT id, name, category, description, condition_label, defects,
                price, size, color, status
         FROM products
         WHERE id = :id AND status = \'AVAILABLE\''
    );

    $stmt->execute([':id' => $productId]);

    $product = $stmt->fetch();

    /*
     * Fetch all images for the product.
     */

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php if (!$productNotFound): ?>
        <title><?= htmlspecialchars($product['name']) ?> - Hopia's Ukay-Ukay</title>
    <?php else: ?>
        <title>Product Not Found - Hopia's Ukay-Ukay</title>
    <?php endif; ?>

    <style>
        .product-images img,
        .image-placeholder {
            display: block;
            width: 100%;
            max-width: 400px;
            height: auto;
            object-fit: cover;
            border: 1px solid #ccc;
            margin-bottom: 10px;
        }

        .image-placeholder {
            max-width: 400px;
            height: 300px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
        }

        .add-to-cart {
            display: inline-block;
            padding: 12px 24px;
            font-size: 1rem;
            background: #eee;
            border: 2px solid #999;
            color: #666;
            cursor: not-allowed;
        }

        .coming-soon-note {
            color: #666;
        }
    </style>
</head>
<body>

    <h1>Hopia's Ukay-Ukay</h1>

    <p>
        <a href="products.php">&larr; Back to Shop</a>
    </p>

    <hr>

    <?php if ($productNotFound): ?>

        <h2>Product Not Found</h2>

        <p>
            This product does not exist, is no longer available, or was
            already sold.
        </p>

        <p>
            <a href="products.php">Back to Shop</a>
        </p>

    <?php else: ?>

        <?php
        $size = trim($product['size'] ?? '') !== ''
            ? htmlspecialchars($product['size'])
            : '&mdash;';

        $color = trim($product['color'] ?? '') !== ''
            ? htmlspecialchars($product['color'])
            : '&mdash;';
        ?>

        <h2><?= htmlspecialchars($product['name']) ?></h2>

        <div class="product-images">

            <?php if (count($images) > 0): ?>

                <?php foreach ($images as $image): ?>

                    <img
                        src="<?= htmlspecialchars('../' . ltrim($image['image_path'], '/')) ?>"
                        alt="<?= htmlspecialchars($product['name']) ?>"
                    >

                <?php endforeach; ?>

            <?php else: ?>

                <div class="image-placeholder">No image</div>

            <?php endif; ?>

        </div>

        <p class="product-price">
            <strong>Price:</strong>
            ₱<?= number_format((float) $product['price'], 2) ?>
        </p>

        <p>
            <strong>Category:</strong>
            <?= htmlspecialchars($product['category']) ?>
        </p>

        <p>
            <strong>Size:</strong>
            <?= $size ?>
        </p>

        <p>
            <strong>Color:</strong>
            <?= $color ?>
        </p>

        <p>
            <strong>Condition:</strong>
            <?= htmlspecialchars($product['condition_label']) ?>
        </p>

        <?php if (trim($product['description'] ?? '') !== ''): ?>

            <h3>Description</h3>

            <p><?= nl2br(htmlspecialchars($product['description'])) ?></p>

        <?php endif; ?>

        <?php if (trim($product['defects'] ?? '') !== ''): ?>

            <h3>Defects</h3>

            <p><?= nl2br(htmlspecialchars($product['defects'])) ?></p>

        <?php endif; ?>

        <hr>

        <div>
            <span class="add-to-cart">Add to Cart</span>

            <p class="coming-soon-note">
                Cart functionality is coming soon.
            </p>
        </div>

    <?php endif; ?>

</body>
</html>
