<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

$inventoryTotal = 0;
$inventoryAvailable = 0;
$inventorySold = 0;

$categoryBreakdown = [
    'SHIRTS' => ['AVAILABLE' => 0, 'SOLD' => 0],
    'PANTS' => ['AVAILABLE' => 0, 'SOLD' => 0],
    'SHORTS' => ['AVAILABLE' => 0, 'SOLD' => 0],
];

$inventoryError = false;

try {
    require_once __DIR__ . '/../includes/database.php';

    $statusRows = $pdo->query(
        'SELECT status, COUNT(*) AS item_count
         FROM products
         GROUP BY status'
    )->fetchAll();

    foreach ($statusRows as $row) {
        if ($row['status'] === 'AVAILABLE') {
            $inventoryAvailable = (int) $row['item_count'];
        } elseif ($row['status'] === 'SOLD') {
            $inventorySold = (int) $row['item_count'];
        }
    }

    $inventoryTotal = $inventoryAvailable + $inventorySold;

    $categoryRows = $pdo->query(
        'SELECT category, status, COUNT(*) AS item_count
         FROM products
         GROUP BY category, status'
    )->fetchAll();

    foreach ($categoryRows as $row) {
        if (isset($categoryBreakdown[$row['category']][$row['status']])) {
            $categoryBreakdown[$row['category']][$row['status']] = (int) $row['item_count'];
        }
    }
} catch (Exception $e) {
    $inventoryError = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Hopia's Ukay-Ukay</title>
</head>
<body>

    <h1>Admin Dashboard</h1>

    <p>Welcome, <?= htmlspecialchars($adminName) ?>!</p>

    <p>You are logged in as an administrator.</p>

    <h2>Inventory Summary</h2>

    <?php if ($inventoryError): ?>

        <p>Inventory summary is temporarily unavailable. Please try again later.</p>

    <?php else: ?>

        <p>Total Products: <?= (int) $inventoryTotal ?></p>
        <p>Available: <?= (int) $inventoryAvailable ?></p>
        <p>Sold: <?= (int) $inventorySold ?></p>

        <h3>Category Breakdown</h3>

        <table border="1" cellpadding="8">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Available</th>
                    <th>Sold</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categoryBreakdown as $category => $counts): ?>
                <tr>
                    <td><?= htmlspecialchars($category) ?></td>
                    <td><?= (int) $counts['AVAILABLE'] ?></td>
                    <td><?= (int) $counts['SOLD'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

    <p>
    <a href="products.php">Manage Products</a>
    </p>

    <p>
    <a href="orders.php">Manage Orders</a>
    </p>

    <a href="logout.php">Logout</a>

</body>
</html>