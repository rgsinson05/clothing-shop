<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';

$validStatuses = ['AVAILABLE', 'SOLD'];
$validCategories = ['SHIRTS', 'PANTS', 'SHORTS'];

$filterStatus = $_GET['status'] ?? '';
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}

$filterCategory = $_GET['category'] ?? '';
if (!in_array($filterCategory, $validCategories, true)) {
    $filterCategory = '';
}

$filterName = trim($_GET['name'] ?? '');
if ($filterName === '') {
    $filterName = '';
}

$hasFilters = ($filterStatus !== '' || $filterCategory !== '' || $filterName !== '');

$products = [];
$queryError = false;

try {
    $where = [];
    $params = [];

    if ($filterStatus !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $filterStatus;
    }

    if ($filterCategory !== '') {
        $where[] = 'category = :category';
        $params[':category'] = $filterCategory;
    }

    if ($filterName !== '') {
        $where[] = 'name LIKE :name';
        $params[':name'] = '%' . $filterName . '%';
    }

    $sql = 'SELECT id, name, category, price, size, color, condition_label, status, created_at
         FROM products';

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $products = $stmt->fetchAll();
} catch (Exception $e) {
    $queryError = true;
    $products = [];
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';
$productImages = [];

if (!$queryError && count($products) > 0) {
    try {
        $imageStmt = $pdo->query(
            'SELECT product_id, image_path
             FROM product_images
             ORDER BY sort_order ASC, id ASC'
        );

        foreach ($imageStmt->fetchAll() as $image) {
            $imageProductId = (int) $image['product_id'];

            if (!isset($productImages[$imageProductId])) {
                $productImages[$imageProductId] = trim($image['image_path'] ?? '');
            }
        }
    } catch (Exception $e) {
        $productImages = [];
    }
}

$page_title = "Products - Admin - Hopia's Ukay-Ukay";
$page_description = "Manage Hopia's available and sold products.";
$ui_section = 'admin';
$ui_active = 'products.php';
$body_class = 'admin-products-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<div class="admin-products">
    <header class="admin-page-header">
        <div>
            <p class="admin-page-header__eyebrow">Inventory management</p>
            <h1>PRODUCTS</h1>
            <p class="admin-page-header__copy">
                Welcome, <?= htmlspecialchars($adminName) ?>. Manage the shop's available and sold items in one place.
            </p>
        </div>

        <a class="btn btn-primary" href="product-create.php">Add Product</a>
    </header>

    <section class="admin-products__filters card" aria-labelledby="product-filters-title">
        <div class="card-body">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-section-heading__eyebrow">Refine inventory</p>
                    <h2 id="product-filters-title">Find a product</h2>
                </div>
            </div>

            <form class="admin-filter-form" method="get" action="products.php">
                <div class="field">
                    <label for="filter-status">Status</label>
                    <select id="filter-status" name="status">
                        <option value="">All Statuses</option>
                        <option value="AVAILABLE"<?= $filterStatus === 'AVAILABLE' ? ' selected' : '' ?>>AVAILABLE</option>
                        <option value="SOLD"<?= $filterStatus === 'SOLD' ? ' selected' : '' ?>>SOLD</option>
                    </select>
                </div>

                <div class="field">
                    <label for="filter-category">Category</label>
                    <select id="filter-category" name="category">
                        <option value="">All Categories</option>
                        <option value="SHIRTS"<?= $filterCategory === 'SHIRTS' ? ' selected' : '' ?>>SHIRTS</option>
                        <option value="PANTS"<?= $filterCategory === 'PANTS' ? ' selected' : '' ?>>PANTS</option>
                        <option value="SHORTS"<?= $filterCategory === 'SHORTS' ? ' selected' : '' ?>>SHORTS</option>
                    </select>
                </div>

                <div class="field admin-filter-form__search">
                    <label for="filter-name">Product name</label>
                    <input
                        type="text"
                        id="filter-name"
                        name="name"
                        value="<?= htmlspecialchars($filterName) ?>"
                        placeholder="Search by name"
                    >
                </div>

                <div class="admin-filter-form__actions">
                    <button class="btn btn-primary" type="submit">Filter</button>
                    <a class="btn btn-ghost" href="products.php">Clear Filters</a>
                </div>
            </form>
        </div>
    </section>

    <?php if ($queryError): ?>
        <div class="alert alert-error" role="alert">
            Products could not be loaded. Please try again later.
        </div>

    <?php elseif (count($products) === 0): ?>

        <?php if ($hasFilters): ?>
            <div class="empty-state">
                <p>No products match the selected filters.</p>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p>No products have been added yet.</p>
                <p><a class="btn btn-primary" href="product-create.php">Add your first product</a></p>
            </div>
        <?php endif; ?>

    <?php else: ?>

        <div class="admin-products__summary">
            <p class="text-small muted">
                Showing <?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?>, including sold items for your records.
            </p>
        </div>

        <div class="admin-product-grid">
            <?php foreach ($products as $product): ?>
                <?php
                $productId = (int) $product['id'];
                $imagePath = $productImages[$productId] ?? '';
                $isAvailable = $product['status'] === 'AVAILABLE';
                $size = trim($product['size'] ?? '');
                $color = trim($product['color'] ?? '');
                ?>

                <article class="admin-product-card<?= $isAvailable ? '' : ' is-sold' ?>">
                    <div class="admin-product-card__image">
                        <?php if ($imagePath !== ''): ?>
                            <img
                                src="../<?= htmlspecialchars(ltrim($imagePath, '/')) ?>"
                                alt="<?= htmlspecialchars($product['name']) ?>"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <span class="admin-product-card__placeholder">No image</span>
                        <?php endif; ?>
                    </div>

                    <div class="admin-product-card__body">
                        <div class="admin-product-card__topline">
                            <p class="admin-product-card__category"><?= htmlspecialchars($product['category']) ?></p>
                            <span class="badge <?= $isAvailable ? 'badge-available' : 'badge-sold' ?>">
                                <?= htmlspecialchars($product['status']) ?>
                            </span>
                        </div>

                        <h2 class="admin-product-card__name"><?= htmlspecialchars($product['name']) ?></h2>
                        <p class="price admin-product-card__price">₱<?= number_format((float) $product['price'], 2) ?></p>

                        <dl class="admin-product-card__details">
                            <div>
                                <dt>Size</dt>
                                <dd><?= htmlspecialchars($size !== '' ? $size : 'Not specified') ?></dd>
                            </div>
                            <div>
                                <dt>Color</dt>
                                <dd><?= htmlspecialchars($color !== '' ? $color : 'Not specified') ?></dd>
                            </div>
                            <div>
                                <dt>Condition</dt>
                                <dd><?= htmlspecialchars($product['condition_label']) ?></dd>
                            </div>
                            <div>
                                <dt>Added</dt>
                                <dd><?= htmlspecialchars($product['created_at']) ?></dd>
                            </div>
                        </dl>

                        <div class="admin-product-card__actions">
                            <a class="btn btn-secondary btn-sm" href="product-edit.php?id=<?= $productId ?>">Edit</a>
                            <a
                                class="btn btn-danger btn-sm"
                                href="product-delete.php?id=<?= $productId ?>"
                                onclick="return confirm('Are you sure you want to delete this product?')"
                            >
                                Delete
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
