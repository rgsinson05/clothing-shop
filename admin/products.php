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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Admin - Hopia's Ukay-Ukay</title>
</head>
<body>

    <h1>Products</h1>

    <p>
        Welcome, <?= htmlspecialchars($adminName) ?>.
    </p>

    <p>
        <a href="index.php">Dashboard</a>
        |
        <a href="logout.php">Logout</a>
    </p>

    <hr>

    <p>
        <a href="product-create.php">Add Product</a>
    </p>

    <form method="get" action="products.php">
        <select name="status">
            <option value="">All Statuses</option>
            <option value="AVAILABLE"<?= $filterStatus === 'AVAILABLE' ? ' selected' : '' ?>>AVAILABLE</option>
            <option value="SOLD"<?= $filterStatus === 'SOLD' ? ' selected' : '' ?>>SOLD</option>
        </select>

        <select name="category">
            <option value="">All Categories</option>
            <option value="SHIRTS"<?= $filterCategory === 'SHIRTS' ? ' selected' : '' ?>>SHIRTS</option>
            <option value="PANTS"<?= $filterCategory === 'PANTS' ? ' selected' : '' ?>>PANTS</option>
            <option value="SHORTS"<?= $filterCategory === 'SHORTS' ? ' selected' : '' ?>>SHORTS</option>
        </select>

        <input type="text" name="name" value="<?= htmlspecialchars($filterName) ?>" placeholder="Product name">

        <button type="submit">Filter</button>

        <a href="products.php">Clear Filters</a>
    </form>

    <hr>

    <?php if ($queryError): ?>

        <p>Products could not be loaded. Please try again later.</p>

    <?php elseif (count($products) === 0): ?>

        <?php if ($hasFilters): ?>

            <p>No products match the selected filters.</p>

        <?php else: ?>

            <p>No products have been added yet.</p>

        <?php endif; ?>

    <?php else: ?>

        <table border="1" cellpadding="8">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Size</th>
                    <th>Color</th>
                    <th>Condition</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($products as $product): ?>

                    <tr>
                        <td><?= htmlspecialchars($product['id']) ?></td>

                        <td>
                            <?= htmlspecialchars($product['name']) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($product['category']) ?>
                        </td>

                        <td>
                            ₱<?= number_format((float) $product['price'], 2) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($product['size'] ?? '') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($product['color'] ?? '') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($product['condition_label']) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($product['status']) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($product['created_at']) ?>
                        </td>

                        <td>
    <a href="product-edit.php?id=<?= $product['id'] ?>">
        Edit
    </a>

     <a
        href="product-delete.php?id=<?= $product['id'] ?>"
        onclick="return confirm('Are you sure you want to delete this product?')"
    >
        Delete
    </a>
</td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</body>
</html>