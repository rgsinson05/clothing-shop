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
$pendingOrders = 0;

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

    $pendingOrders = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'PENDING'"
    )->fetchColumn();
} catch (Exception $e) {
    $inventoryError = true;
}
?>

<?php
$page_title = "Dashboard - Admin - Hopia's Ukay-Ukay";
$ui_section = 'admin';
$ui_active = 'index.php';
$body_class = 'admin-dashboard-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<div class="admin-dashboard">
    <header class="admin-dashboard__header">
        <p class="admin-dashboard__eyebrow">Admin overview</p>
        <h1>DASHBOARD</h1>
        <p class="admin-dashboard__welcome">
            Welcome, <?= htmlspecialchars($adminName) ?>. You are logged in as an administrator.
        </p>
    </header>

    <?php if ($inventoryError): ?>

        <div class="alert alert-error" role="alert">
            Inventory summary is temporarily unavailable. Please try again later.
        </div>

    <?php else: ?>

        <section class="admin-dashboard__section" aria-labelledby="summary-title">
            <div class="admin-dashboard__section-heading">
                <div>
                    <p class="admin-dashboard__eyebrow">At a glance</p>
                    <h2 id="summary-title">Store summary</h2>
                </div>
            </div>

            <div class="admin-stat-grid">
                <article class="admin-stat-card">
                    <p class="admin-stat-card__label">Total products</p>
                    <p class="admin-stat-card__value"><?= (int) $inventoryTotal ?></p>
                </article>

                <article class="admin-stat-card">
                    <p class="admin-stat-card__label">Available products</p>
                    <p class="admin-stat-card__value"><?= (int) $inventoryAvailable ?></p>
                </article>

                <article class="admin-stat-card">
                    <p class="admin-stat-card__label">Sold products</p>
                    <p class="admin-stat-card__value"><?= (int) $inventorySold ?></p>
                </article>

                <article class="admin-stat-card">
                    <p class="admin-stat-card__label">Pending orders</p>
                    <p class="admin-stat-card__value"><?= (int) $pendingOrders ?></p>
                </article>
            </div>
        </section>

        <section class="admin-dashboard__section" aria-labelledby="category-breakdown-title">
            <div class="card">
                <div class="card-body">
                    <div class="admin-dashboard__section-heading">
                        <div>
                            <p class="admin-dashboard__eyebrow">Inventory</p>
                            <h2 id="category-breakdown-title">Category breakdown</h2>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Category</th>
                                    <th scope="col">Available</th>
                                    <th scope="col">Sold</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categoryBreakdown as $category => $counts): ?>
                                <tr>
                                    <th scope="row"><?= htmlspecialchars($category) ?></th>
                                    <td><?= (int) $counts['AVAILABLE'] ?></td>
                                    <td><?= (int) $counts['SOLD'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

    <?php endif; ?>

    <section class="admin-dashboard__section" aria-labelledby="actions-title">
        <div class="admin-dashboard__section-heading">
            <div>
                <p class="admin-dashboard__eyebrow">Keep things moving</p>
                <h2 id="actions-title">Quick actions</h2>
            </div>
        </div>

        <div class="admin-actions-grid">
            <a class="admin-action-card" href="products.php">
                <span class="admin-action-card__title">Manage products</span>
                <span class="admin-action-card__description">Add, edit, or remove inventory.</span>
                <span class="admin-action-card__link">Open products</span>
            </a>

            <a class="admin-action-card" href="orders.php">
                <span class="admin-action-card__title">Manage orders</span>
                <span class="admin-action-card__description">Review customer orders and updates.</span>
                <span class="admin-action-card__link">Open orders</span>
            </a>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
