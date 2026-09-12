<?php

require_once __DIR__ . '/../includes/database.php';

$allowedCategories = ['SHIRTS', 'PANTS', 'SHORTS'];

$search = trim($_GET['search'] ?? '');

$category = $_GET['category'] ?? '';

if (!in_array($category, $allowedCategories, true)) {
    $category = '';
}

$sql = "SELECT p.id, p.name, p.category, p.price, p.size, p.color, pi.image_path
     FROM products p
     LEFT JOIN product_images pi
         ON pi.id = (
             SELECT pi2.id
             FROM product_images pi2
             WHERE pi2.product_id = p.id
             ORDER BY pi2.sort_order ASC, pi2.id ASC
             LIMIT 1
         )
     WHERE p.status = 'AVAILABLE'";

$params = [];

if ($category !== '') {
    $sql .= ' AND p.category = :category';
    $params[':category'] = $category;
}

if ($search !== '') {
    $sql .= ' AND p.name LIKE :search';
    $params[':search'] = '%' . $search . '%';
}

$sql .= ' ORDER BY p.created_at DESC';

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$products = $stmt->fetchAll();

$hasFilters = ($search !== '' || $category !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop - Hopia's Ukay-Ukay</title>

    <style>
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .product-card {
            border: 1px solid #ccc;
            padding: 12px;
        }

        .product-card img,
        .image-placeholder {
            display: block;
            width: 100%;
            height: 220px;
            object-fit: cover;
            margin: 0 0 10px 0;
            background: #f0f0f0;
        }

        .image-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
        }

        .product-card h2 {
            font-size: 1rem;
            margin: 0 0 8px 0;
        }

        .product-price {
            font-weight: bold;
            margin: 0 0 8px 0;
        }

        .product-meta {
            margin: 0 0 10px 0;
            color: #555;
        }
    </style>
</head>
<body>

    <h1>Hopia's Ukay-Ukay</h1>

    <h2>Shop</h2>

    <form method="GET">

        <label for="search">Search</label>
        <input
            type="text"
            id="search"
            name="search"
            placeholder="Search by product name"
            value="<?= htmlspecialchars($search) ?>"
        >

        <label for="category">Category</label>
        <select id="category" name="category">
            <option value="">All</option>

            <option value="SHIRTS" <?= $category === 'SHIRTS' ? 'selected' : '' ?>>
                Shirts
            </option>

            <option value="PANTS" <?= $category === 'PANTS' ? 'selected' : '' ?>>
                Pants
            </option>

            <option value="SHORTS" <?= $category === 'SHORTS' ? 'selected' : '' ?>>
                Shorts
            </option>
        </select>

        <button type="submit">Apply</button>

    </form>

    <?php if (count($products) === 0): ?>

        <?php if ($hasFilters): ?>

            <p>No products found matching your search or filters.</p>

        <?php else: ?>

            <p>No products are available right now. Please check back later.</p>

        <?php endif; ?>

    <?php else: ?>

        <div class="product-grid">

            <?php foreach ($products as $product): ?>

                <?php
                $productId = (int) $product['id'];

                $imagePath = trim($product['image_path'] ?? '');

                $size = trim($product['size'] ?? '') !== ''
                    ? htmlspecialchars($product['size'])
                    : '&mdash;';

                $color = trim($product['color'] ?? '') !== ''
                    ? htmlspecialchars($product['color'])
                    : '&mdash;';
                ?>

                <div class="product-card">

                    <?php if ($imagePath !== ''): ?>

                        <a href="product.php?id=<?= $productId ?>">
                            <img
                                src="<?= htmlspecialchars('../' . ltrim($imagePath, '/')) ?>"
                                alt="<?= htmlspecialchars($product['name']) ?>"
                            >
                        </a>

                    <?php else: ?>

                        <div class="image-placeholder">No image</div>

                    <?php endif; ?>

                    <h2>
                        <a href="product.php?id=<?= $productId ?>">
                            <?= htmlspecialchars($product['name']) ?>
                        </a>
                    </h2>

                    <p class="product-price">
                        ₱<?= number_format((float) $product['price'], 2) ?>
                    </p>

                    <p class="product-meta">
                        Size: <?= $size ?>
                        <br>
                        Color: <?= $color ?>
                        <br>
                        Category: <?= htmlspecialchars($product['category']) ?>
                    </p>

                    <a href="product.php?id=<?= $productId ?>">View details</a>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</body>
</html>
