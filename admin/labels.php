<?php

/*
 * Hopia Fits - Phase A barcode label print view.
 *
 * Authenticated admin page. Renders Code 128 labels (via the pinned local
 * JsBarcode) for products that ALREADY have an issued barcode, so staff can
 * print individual scannable labels for the same camera scanner used on
 * scan.php. It never creates or modifies a barcode value.
 *
 * Eligibility shown here = bundle-linked, has a barcode, and NOT retired.
 * Retired items are excluded so a still-known barcode or a previously printed
 * label can never make a retired item sellable. Status is filterable and
 * defaults to AVAILABLE; printing a label never changes a product's state.
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/ui.php';

$validCategories = ['SHIRTS', 'PANTS', 'SHORTS'];
$validStatuses = ['AVAILABLE', 'SOLD'];

$filterCategory = (string) ($_GET['category'] ?? '');
if (!in_array($filterCategory, $validCategories, true)) {
    $filterCategory = '';
}

/* Default to AVAILABLE (sellable) labels; allow SOLD or all explicitly. */
$filterStatus = array_key_exists('status', $_GET) ? (string) $_GET['status'] : 'AVAILABLE';
if ($filterStatus !== '' && !in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = 'AVAILABLE';
}

$filterProductId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$filterBundleId = filter_input(INPUT_GET, 'bundle_id', FILTER_VALIDATE_INT) ?: 0;

$products = [];
$queryError = false;

try {
    $where = [
        'p.bundle_id IS NOT NULL',
        'p.barcode_value IS NOT NULL',
        'p.retired_at IS NULL',
    ];
    $params = [];

    if ($filterStatus !== '') {
        $where[] = 'p.status = :status';
        $params[':status'] = $filterStatus;
    }
    if ($filterCategory !== '') {
        $where[] = 'p.category = :category';
        $params[':category'] = $filterCategory;
    }
    if ($filterProductId > 0) {
        $where[] = 'p.id = :pid';
        $params[':pid'] = $filterProductId;
    }
    if ($filterBundleId > 0) {
        $where[] = 'p.bundle_id = :bid';
        $params[':bid'] = $filterBundleId;
    }

    $sql = 'SELECT p.id, p.name, p.category, p.price, p.size, p.color, p.barcode_value
            FROM products p
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.barcode_value ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (Throwable $e) {
    $queryError = true;
    $products = [];
}

$page_title = "Barcode Labels - Admin - Hopia's Ukay-Ukay";
$page_description = "Print Code 128 barcode labels for issued products.";
$ui_section = 'admin';
$ui_active = 'labels.php';
$body_class = 'admin-labels-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>
<style>
.label-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); margin-top: 1rem; }
.label-card { border: 1px solid #11100e22; border-radius: 8px; padding: .75rem; text-align: center; background: #fff; break-inside: avoid; }
.label-card__name { font-weight: 600; font-size: .9rem; margin: .25rem 0 0; }
.label-card__meta { color: #11100e99; font-size: .78rem; margin: .1rem 0; }
.label-card__price { font-weight: 700; margin: .15rem 0 .35rem; }
.label-card svg { width: 100%; height: auto; }
@media print {
    .site-header, .site-footer, .no-print { display: none !important; }
    .site-main, .container { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; }
    .label-grid { grid-template-columns: repeat(3, 1fr); gap: .4rem; }
    .label-card { border: 1px solid #000; }
}
</style>

<div class="admin-products">
    <header class="admin-page-header">
        <div>
            <a class="admin-page-header__back no-print" href="products.php">&larr; Back to Products</a>
            <p class="admin-page-header__eyebrow">Inventory</p>
            <h1>BARCODE LABELS</h1>
            <p class="admin-page-header__copy">
                Printable Code 128 labels for items that already carry an issued barcode. Retired items are excluded.
            </p>
        </div>
        <?php if (!$queryError && count($products) > 0): ?>
            <button type="button" class="btn btn-primary no-print" onclick="window.print()">Print labels</button>
        <?php endif; ?>
    </header>

    <section class="admin-products__filters card no-print" aria-labelledby="label-filters-title">
        <div class="card-body">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-section-heading__eyebrow">Refine</p>
                    <h2 id="label-filters-title">Find labels</h2>
                </div>
            </div>
            <form class="admin-filter-form" method="get" action="labels.php">
                <?php if ($filterProductId > 0): ?>
                    <input type="hidden" name="id" value="<?= (int) $filterProductId ?>">
                <?php endif; ?>
                <?php if ($filterBundleId > 0): ?>
                    <input type="hidden" name="bundle_id" value="<?= (int) $filterBundleId ?>">
                <?php endif; ?>
                <div class="field">
                    <label for="filter-status">Status</label>
                    <select id="filter-status" name="status">
                        <option value="AVAILABLE"<?= $filterStatus === 'AVAILABLE' ? ' selected' : '' ?>>AVAILABLE</option>
                        <option value="SOLD"<?= $filterStatus === 'SOLD' ? ' selected' : '' ?>>SOLD</option>
                        <option value=""<?= $filterStatus === '' ? ' selected' : '' ?>>All (non-retired)</option>
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
                <div class="admin-filter-form__actions">
                    <button class="btn btn-primary" type="submit">Filter</button>
                    <a class="btn btn-ghost" href="labels.php">Clear</a>
                </div>
            </form>
        </div>
    </section>

    <?php if ($queryError): ?>
        <div class="alert alert-error" role="alert">Labels could not be loaded. Please try again later.</div>
    <?php elseif (count($products) === 0): ?>
        <div class="empty-state"><p>No eligible products with issued barcodes match this view.</p></div>
    <?php else: ?>
        <div class="label-grid">
            <?php foreach ($products as $product): ?>
                <?php
                $meta = [];
                if (!empty($product['size'])) { $meta[] = 'Size ' . $product['size']; }
                if (!empty($product['color'])) { $meta[] = $product['color']; }
                ?>
                <div class="label-card">
                    <svg class="label-barcode" data-barcode="<?= hopia_e($product['barcode_value']) ?>" role="img"
                         aria-label="Barcode <?= hopia_e($product['barcode_value']) ?>"></svg>
                    <p class="label-card__name"><?= hopia_e($product['name']) ?></p>
                    <?php if (count($meta) > 0): ?>
                        <p class="label-card__meta"><?= hopia_e(implode(' / ', $meta)) ?></p>
                    <?php endif; ?>
                    <p class="label-card__price">&#8369;<?= number_format((float) $product['price'], 2) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="<?= hopia_e(hopia_asset('js/vendor/jsbarcode/JsBarcode.all.min.js')) ?>"></script>
<script>
(function () {
    'use strict';
    if (typeof JsBarcode === 'undefined') { return; }
    document.querySelectorAll('svg.label-barcode').forEach(function (el) {
        var value = el.getAttribute('data-barcode') || '';
        if (value === '') { return; }
        try {
            JsBarcode(el, value, {
                format: 'CODE128',
                displayValue: true,
                fontSize: 13,
                height: 48,
                margin: 6,
                textMargin: 2
            });
        } catch (e) { /* leave the <svg> empty if encoding fails */ }
    });
})();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
