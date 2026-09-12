<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';

$stmt = $pdo->query(
    'SELECT id, name, category, price, size, color, condition_label, status, created_at
     FROM products
     ORDER BY created_at DESC'
);

$products = $stmt->fetchAll();

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

    <?php if (count($products) === 0): ?>

        <p>No products have been added yet.</p>

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