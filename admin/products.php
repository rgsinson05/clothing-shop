<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/bundle_metrics.php';

/* ============================================================
   Summary card counts — all from real database state.
   Online-held = products with SOLD status but no completed
   sales_transaction record (reversible online checkout hold).
   ============================================================ */
$summaryTotals = ['total' => 0, 'available' => 0, 'online_held' => 0, 'legacy_unassigned' => 0];
$completedSoldCount = 0;
$retiredCount = 0;

try {
    /* Total products (non-retired visible catalog). Retired items are
       shown as a separate count but kept in the filterable grid so
       admins can still find them by name/barcode. */
    $summaryTotals['total'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM products WHERE retired_at IS NULL"
    )->fetchColumn();

    /* Available: not retired, not sold, not in a completed transaction. */
    $summaryTotals['available'] = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM products p
         WHERE p.retired_at IS NULL
           AND p.status = 'AVAILABLE'
           AND NOT EXISTS (SELECT 1 FROM sales_transaction_items sx WHERE sx.product_id = p.id)"
    )->fetchColumn();

    /* Online-held: SOLD status with no completed-sale record.
       Matches bundle_metrics_rows() online_held_count predicate. */
    $summaryTotals['online_held'] = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM products p
         WHERE p.retired_at IS NULL
           AND p.status = 'SOLD'
           AND NOT EXISTS (SELECT 1 FROM sales_transaction_items sx WHERE sx.product_id = p.id)"
    )->fetchColumn();

    /* Legacy unassigned: bundle_id IS NULL. */
    $legacy = bundle_metrics_legacy_summary($pdo);
    $summaryTotals['legacy_unassigned'] = (int) ($legacy['total'] ?? 0);

    /* Completed-sold (both channels): product appears in a committed
       sales_transaction_items row. Retired items in this set are
       shown with their sale badge. */
    $completedSoldCount = (int) $pdo->query(
        "SELECT COUNT(DISTINCT sti.product_id)
         FROM sales_transaction_items sti
         INNER JOIN products p ON p.id = sti.product_id
         WHERE p.retired_at IS NULL"
    )->fetchColumn();

    /* Retired (preserved for records). */
    $retiredCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM products WHERE retired_at IS NOT NULL"
    )->fetchColumn();

} catch (Throwable $e) {
    /* Non-fatal: counts will show 0, page remains functional. */
}

/* ============================================================
   Filter / search state.
   Valid filter values match the current schema exactly.
   ============================================================ */
$validStatuses  = ['AVAILABLE', 'SOLD', 'RETIRED'];
$validCategories = ['SHIRTS', 'PANTS', 'SHORTS'];
$validGenders   = ['MEN', 'WOMEN'];
$validBundles   = ['assigned', 'needs_assignment'];

$filterStatus    = $_GET['status']    ?? '';
$filterCategory   = $_GET['category']   ?? '';
$filterGender     = $_GET['gender']     ?? '';
$filterBundle     = $_GET['bundle']     ?? '';
$filterName       = trim($_GET['name']  ?? '');
$filterBarcode    = trim($_GET['barcode'] ?? '');

$rawStatus = $_GET['status'] ?? '';
if (!in_array($rawStatus, $validStatuses, true)) {
    $filterStatus = '';
}

$rawCategory = $_GET['category'] ?? '';
if (!in_array($rawCategory, $validCategories, true)) {
    $filterCategory = '';
}

$rawGender = $_GET['gender'] ?? '';
if (!in_array($rawGender, $validGenders, true)) {
    $filterGender = '';
}

$rawBundle = $_GET['bundle'] ?? '';
if (!in_array($rawBundle, $validBundles, true)) {
    $filterBundle = '';
}

$hasFilters = ($filterStatus !== ''
    || $filterCategory !== ''
    || $filterGender !== ''
    || $filterBundle !== ''
    || $filterName !== ''
    || $filterBarcode !== '');

/* ============================================================
   Pagination.
   ============================================================ */
$perPage = 24;
$page    = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

/* Build the WHERE clause from active filters. */
$where = [];
$params = [];

if ($filterStatus !== '') {
    if ($filterStatus === 'RETIRED') {
        $where[] = 'p.retired_at IS NOT NULL';
    } else {
        $where[] = 'p.status = :status';
        $params[':status'] = $filterStatus;
    }
}

if ($filterCategory !== '') {
    $where[] = 'p.category = :category';
    $params[':category'] = $filterCategory;
}

if ($filterGender !== '') {
    $where[] = 'p.gender = :gender';
    $params[':gender'] = $filterGender;
}

if ($filterBundle !== '') {
    if ($filterBundle === 'assigned') {
        $where[] = 'p.bundle_id IS NOT NULL';
    } else {
        $where[] = 'p.bundle_id IS NULL';
    }
}

if ($filterName !== '') {
    $where[] = 'p.name LIKE :name';
    $params[':name'] = '%' . $filterName . '%';
}

if ($filterBarcode !== '') {
    $where[] = 'p.barcode_value LIKE :barcode';
    $params[':barcode'] = '%' . $filterBarcode . '%';
}

/* ============================================================
   Product query (filtered, paginated).
   Always selects all columns needed for card rendering.
   ============================================================ */
$products    = [];
$totalCount  = 0;
$queryError  = false;

try {
    /* Count query */
    $countSql = 'SELECT COUNT(*) FROM products p';
    if ($where) {
        $countSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();

    /* Main query */
    $dataSql = 'SELECT p.id, p.name, p.category, p.gender, p.price, p.size,
                       p.color, p.condition_label, p.status, p.created_at,
                       p.bundle_id, p.barcode_value, p.retired_at,
                       p.description, p.defects
                FROM products p';
    if ($where) {
        $dataSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $dataSql .= ' ORDER BY p.retired_at IS NULL DESC, p.created_at DESC LIMIT :limit OFFSET :offset';

    $dataStmt = $pdo->prepare($dataSql);
    $dataStmt->bindValue(':limit',  $perPage,  PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $dataStmt->bindValue($key, $value);
    }
    $dataStmt->execute();
    $products = $dataStmt->fetchAll();

} catch (Throwable $e) {
    $queryError = true;
    $products   = [];
    $totalCount = 0;
}

$totalPages   = $totalCount > 0 ? (int) ceil($totalCount / $perPage) : 0;
$showingCount = count($products);

/* Build pagination query-string (preserving filters, resetting to page 1). */
function pageUrl(int $p): string
{
    $args = $_GET;
    $args['page'] = $p;
    return '?' . http_build_query($args);
}

/* ============================================================
   Load product images (batch, no N+1).
   ============================================================ */
$productImages = [];
if (!$queryError && $products) {
    try {
        $ids = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $imgStmt = $pdo->prepare(
            "SELECT product_id, image_path
             FROM product_images
             WHERE product_id IN ({$placeholders})
             ORDER BY sort_order ASC, id ASC"
        );
        $imgStmt->execute($ids);
        foreach ($imgStmt->fetchAll() as $img) {
            $pid = (int) $img['product_id'];
            if (!isset($productImages[$pid])) {
                $productImages[$pid] = trim($img['image_path'] ?? '');
            }
        }
    } catch (Throwable $e) {
        $productImages = [];
    }
}

/* ============================================================
   Batch-load completed-sale membership for this page's products.
   A product is "completed sold" when it appears in a committed
   sales_transaction_items row (either channel) — the same
   authoritative predicate bundle_metrics_rows() uses. Everything
   else with status SOLD is a reversible online checkout hold.
   ============================================================ */
$saleRecordedIds = [];
if (!$queryError && $products) {
    try {
        $ids = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $saleStmt = $pdo->prepare(
            "SELECT DISTINCT product_id
             FROM sales_transaction_items
             WHERE product_id IN ({$placeholders})"
        );
        $saleStmt->execute($ids);
        foreach ($saleStmt->fetchAll(PDO::FETCH_COLUMN) as $saleProductId) {
            $saleRecordedIds[(int) $saleProductId] = true;
        }
    } catch (Throwable $e) {
        $saleRecordedIds = [];
    }
}

/* ============================================================
   Status helpers (mirror bundle_metrics_rows predicates).
   Completed-sale membership is batch-loaded once below, so no
   per-product queries run while rendering the grid.
   ============================================================ */
function product_is_available(array $p): bool
{
    return $p['status'] === 'AVAILABLE'
        && empty($p['retired_at']);
}

function product_is_retired(array $p): bool
{
    return !empty($p['retired_at']);
}

/* Completed-sold: appears in a committed sale record (either channel). */
function product_is_completed_sold(array $p, array $saleRecordedIds): bool
{
    return isset($saleRecordedIds[(int) $p['id']]);
}

/* Online-held: SOLD with no completed sale record. This is a reversible
   online-checkout hold, never a completed sale. */
function product_is_online_held(array $p, array $saleRecordedIds): bool
{
    return $p['status'] === 'SOLD'
        && empty($p['retired_at'])
        && !isset($saleRecordedIds[(int) $p['id']]);
}

/* ============================================================
   Page vars for shared header.
   ============================================================ */
$adminName   = $_SESSION['admin_name'] ?? 'Admin';
$page_title  = "Products - Admin - Hopia Fits";
$page_description = "Manage individual garments and their inventory details.";
$ui_section  = 'admin';
$ui_active   = 'products.php';
$body_class  = 'admin-products-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<!-- ============================================================
     PRODUCTS PAGE — Hopia Fits Admin
     ============================================================ -->
<div class="inv">

    <!-- PAGE HEADER -->
    <header class="inv__header">
        <div class="inv__intro">
            <p class="inv__eyebrow">Inventory management</p>
            <h1 class="inv__title">Products</h1>
            <p class="inv__lead">
                Manage individual garments and their inventory details.
            </p>
        </div>
        <div class="inv__header-actions">
            <a class="btn btn-ghost inv__secondary-btn" href="legacy-assign.php">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V7"/><path d="M10 11h4"/></svg>
                Legacy Bundle Assignment
            </a>
            <a class="btn btn-ghost inv__secondary-btn" href="labels.php">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                Barcode Labels
            </a>
            <a class="btn btn-primary inv__primary-btn" href="product-create.php">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Product
            </a>
        </div>
    </header>

    <!-- SUMMARY CARDS -->
    <section class="inv-kpis" aria-label="Inventory overview">
        <article class="inv-kpi inv-kpi--total">
            <div class="inv-kpi__top">
                <span class="inv-kpi__label">Total Products</span>
                <span class="inv-kpi__icon">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4 4.6 6.7a1 1 0 0 0-.4 1.2L5.5 11l2.5-.9V20h8v-9.9l2.5.9 1.3-3.1a1 1 0 0 0-.4-1.2L15 4a3 3 0 0 1-6 0z"/></svg>
                </span>
            </div>
            <p class="inv-kpi__value"><?= number_format($summaryTotals['total']) ?></p>
            <p class="inv-kpi__sub">
                <?= number_format($completedSoldCount) ?> completed sold
                &middot; <?= number_format($retiredCount) ?> retired
            </p>
        </article>

        <article class="inv-kpi inv-kpi--available">
            <div class="inv-kpi__top">
                <span class="inv-kpi__label">Available Items</span>
                <span class="inv-kpi__icon">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                </span>
            </div>
            <p class="inv-kpi__value"><?= number_format($summaryTotals['available']) ?></p>
            <p class="inv-kpi__sub">Ready to sell</p>
        </article>

        <article class="inv-kpi inv-kpi--held">
            <div class="inv-kpi__top">
                <span class="inv-kpi__label">Online-Held Items</span>
                <span class="inv-kpi__icon">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>
                </span>
            </div>
            <p class="inv-kpi__value"><?= number_format($summaryTotals['online_held']) ?></p>
            <p class="inv-kpi__sub">In pending online orders</p>
        </article>

        <article class="inv-kpi inv-kpi--legacy">
            <div class="inv-kpi__top">
                <span class="inv-kpi__label">Legacy / Unassigned</span>
                <span class="inv-kpi__icon">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="4" width="17" height="4.5" rx="1"/><path d="M5 8.5h14V19a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19V8.5Z"/><path d="M10 12h4"/></svg>
                </span>
            </div>
            <p class="inv-kpi__value"><?= number_format($summaryTotals['legacy_unassigned']) ?></p>
            <p class="inv-kpi__sub">Need bundle/barcode assignment</p>
        </article>
    </section>

    <!-- SEARCH + FILTER TOOLBAR -->
    <section class="inv-toolbar card" aria-labelledby="inv-toolbar-title">
        <div class="card-body">
            <div class="inv-toolbar__head">
                <div>
                    <p class="inv-toolbar__eyebrow">Refine inventory</p>
                    <h2 class="inv-toolbar__title" id="inv-toolbar-title">Find a product</h2>
                </div>
                <?php if ($hasFilters): ?>
                <a class="inv-toolbar__clear-all" href="products.php">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Clear filters
                </a>
                <?php endif; ?>
            </div>

            <form class="inv-filter" method="get" action="products.php" novalidate>
                <div class="inv-filter__row">
                    <!-- Search -->
                    <div class="inv-filter__search">
                        <label class="inv-filter__label" for="filter-name">Search</label>
                        <div class="inv-filter__search-wrap">
                            <svg class="inv-filter__search-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input
                                class="inv-filter__input"
                                type="text"
                                id="filter-name"
                                name="name"
                                value="<?= htmlspecialchars($filterName) ?>"
                                placeholder="Product name"
                                autocomplete="off"
                            >
                        </div>
                    </div>

                    <!-- Barcode -->
                    <div class="inv-filter__field">
                        <label class="inv-filter__label" for="filter-barcode">Barcode</label>
                        <input
                            class="inv-filter__input"
                            type="text"
                            id="filter-barcode"
                            name="barcode"
                            value="<?= htmlspecialchars($filterBarcode) ?>"
                            placeholder="Barcode"
                            autocomplete="off"
                        >
                    </div>

                    <!-- Category -->
                    <div class="inv-filter__field">
                        <label class="inv-filter__label" for="filter-category">Category</label>
                        <select class="inv-filter__input" id="filter-category" name="category">
                            <option value="">All Categories</option>
                            <option value="SHIRTS"<?= $filterCategory === 'SHIRTS' ? ' selected' : '' ?>>Shirts</option>
                            <option value="PANTS"<?= $filterCategory === 'PANTS' ? ' selected' : '' ?>>Pants</option>
                            <option value="SHORTS"<?= $filterCategory === 'SHORTS' ? ' selected' : '' ?>>Shorts</option>
                        </select>
                    </div>

                    <!-- Gender -->
                    <div class="inv-filter__field">
                        <label class="inv-filter__label" for="filter-gender">Gender</label>
                        <select class="inv-filter__input" id="filter-gender" name="gender">
                            <option value="">Men &amp; Women</option>
                            <option value="MEN"<?= $filterGender === 'MEN' ? ' selected' : '' ?>>Men</option>
                            <option value="WOMEN"<?= $filterGender === 'WOMEN' ? ' selected' : '' ?>>Women</option>
                        </select>
                    </div>

                    <!-- Status -->
                    <div class="inv-filter__field">
                        <label class="inv-filter__label" for="filter-status">Status</label>
                        <select class="inv-filter__input" id="filter-status" name="status">
                            <option value="">All Statuses</option>
                            <option value="AVAILABLE"<?= $filterStatus === 'AVAILABLE' ? ' selected' : '' ?>>Available</option>
                            <option value="SOLD"<?= $filterStatus === 'SOLD' ? ' selected' : '' ?>>Sold / Online-Held</option>
                            <option value="RETIRED"<?= $filterStatus === 'RETIRED' ? ' selected' : '' ?>>Retired</option>
                        </select>
                    </div>

                    <!-- Bundle -->
                    <div class="inv-filter__field">
                        <label class="inv-filter__label" for="filter-bundle">Bundle</label>
                        <select class="inv-filter__input" id="filter-bundle" name="bundle">
                            <option value="">All Items</option>
                            <option value="assigned"<?= $filterBundle === 'assigned' ? ' selected' : '' ?>>Bundle Assigned</option>
                            <option value="needs_assignment"<?= $filterBundle === 'needs_assignment' ? ' selected' : '' ?>>Needs Assignment</option>
                        </select>
                    </div>

                    <!-- Submit -->
                    <div class="inv-filter__submit">
                        <button class="btn btn-primary inv-filter__btn" type="submit">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            Search
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <!-- PRODUCT GRID -->
    <?php if ($queryError): ?>
        <div class="alert alert-error" role="alert">
            Products could not be loaded. Please try refreshing the page.
        </div>
    <?php elseif ($totalCount === 0): ?>
        <div class="inv-empty">
            <?php if ($hasFilters): ?>
                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <p>No products match the selected filters.</p>
                <a class="btn btn-outline btn-sm" href="products.php">Clear filters</a>
            <?php else: ?>
                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M9 4 4.6 6.7a1 1 0 0 0-.4 1.2L5.5 11l2.5-.9V20h8v-9.9l2.5.9 1.3-3.1a1 1 0 0 0-.4-1.2L15 4a3 3 0 0 1-6 0z"/></svg>
                <p>No products have been added yet.</p>
                <a class="btn btn-primary btn-sm" href="product-create.php">Add your first product</a>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <!-- Result count + pagination info -->
        <div class="inv-results-bar">
            <p class="inv-results-bar__count">
                <?php if ($hasFilters): ?>
                    <?= number_format($totalCount) ?> result<?= $totalCount === 1 ? '' : 's' ?>
                    for your filters
                    &middot;
                    showing <?= $showingCount ?>
                    <?= $showingCount === 1 ? 'item' : 'items' ?>
                <?php else: ?>
                    <?= number_format($totalCount) ?>
                    <?= $totalCount === 1 ? 'product' : 'products' ?>
                    in inventory
                <?php endif; ?>
            </p>
            <?php if ($totalPages > 1): ?>
                <p class="inv-results-bar__pages">
                    Page <?= $page ?> of <?= $totalPages ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Cards -->
        <div class="inv-grid">
            <?php foreach ($products as $product):
                $pid           = (int) $product['id'];
                $imagePath     = $productImages[$pid] ?? '';
                $isRetired     = product_is_retired($product);
                $isAvailable   = product_is_available($product);
                $isOnlineHeld  = product_is_online_held($product, $saleRecordedIds);
                $isCompletedSold = product_is_completed_sold($product, $saleRecordedIds);
                $isBundled     = !empty($product['bundle_id']);
                $size          = trim($product['size'] ?? '');
                $color         = trim($product['color'] ?? '');
                $barcode       = trim($product['barcode_value'] ?? '');
                $condition     = trim($product['condition_label'] ?? '');
                $defects       = trim($product['defects'] ?? '');
                $gender        = trim($product['gender'] ?? '');

                /* Derive status badge type. */
                if ($isRetired) {
                    $badgeClass = 'inv-badge--retired';
                    $badgeLabel = 'Retired';
                } elseif ($isCompletedSold) {
                    $badgeClass = 'inv-badge--sold';
                    $badgeLabel = 'Sold';
                } elseif ($isOnlineHeld) {
                    $badgeClass = 'inv-badge--held';
                    $badgeLabel = 'Online-Held';
                } elseif ($isAvailable) {
                    $badgeClass = 'inv-badge--available';
                    $badgeLabel = 'Available';
                } else {
                    $badgeClass = 'inv-badge--sold';
                    $badgeLabel = htmlspecialchars($product['status']);
                }
            ?>
                <article class="inv-card<?= $isRetired ? ' is-retired' : '' ?><?= $isCompletedSold && !$isRetired ? ' is-sold' : '' ?><?= $isOnlineHeld && !$isRetired ? ' is-held' : '' ?>">

                    <!-- Image -->
                    <div class="inv-card__image">
                        <?php if ($imagePath !== ''): ?>
                            <img
                                src="../<?= htmlspecialchars(ltrim($imagePath, '/')) ?>"
                                alt="<?= htmlspecialchars($product['name']) ?>"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <div class="inv-card__placeholder" aria-label="No image">
                                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            </div>
                        <?php endif; ?>

                        <!-- Status badge overlay -->
                        <span class="inv-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                    </div>

                    <!-- Body -->
                    <div class="inv-card__body">

                        <!-- Top line: category + gender -->
                        <div class="inv-card__meta-top">
                            <span class="inv-card__category"><?= htmlspecialchars($product['category']) ?></span>
                            <?php if ($gender !== ''): ?>
                                <span class="inv-card__gender"><?= htmlspecialchars($gender) ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Name + price -->
                        <h2 class="inv-card__name"><?= htmlspecialchars($product['name']) ?></h2>
                        <p class="inv-card__price">&#8369;<?= number_format((float) $product['price'], 2) ?></p>

                        <!-- Size / Color / Condition -->
                        <?php if ($size !== '' || $color !== '' || $condition !== ''): ?>
                            <div class="inv-card__attrs">
                                <?php if ($size !== ''): ?>
                                    <span class="inv-card__attr">
                                        <span class="inv-card__attr-label">Size</span>
                                        <span class="inv-card__attr-val"><?= htmlspecialchars($size) ?></span>
                                    </span>
                                <?php endif; ?>
                                <?php if ($color !== ''): ?>
                                    <span class="inv-card__attr">
                                        <span class="inv-card__attr-label">Color</span>
                                        <span class="inv-card__attr-val"><?= htmlspecialchars($color) ?></span>
                                    </span>
                                <?php endif; ?>
                                <?php if ($condition !== ''): ?>
                                    <span class="inv-card__attr">
                                        <span class="inv-card__attr-label">Condition</span>
                                        <span class="inv-card__attr-val"><?= htmlspecialchars($condition) ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Defects (truncated) -->
                        <?php if ($defects !== ''): ?>
                            <p class="inv-card__defects">
                                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <?= htmlspecialchars($defects) ?>
                            </p>
                        <?php endif; ?>

                        <!-- Barcode + bundle info -->
                        <?php if ($barcode !== '' || !$isBundled): ?>
                            <div class="inv-card__bundle-info">
                                <?php if ($barcode !== ''): ?>
                                    <span class="inv-card__barcode">
                                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                                        <code><?= htmlspecialchars($barcode) ?></code>
                                    </span>
                                <?php elseif (!$isBundled): ?>
                                    <span class="inv-card__unassigned">
                                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                        Legacy — no bundle assigned
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Actions -->
                        <?php if ($isRetired): ?>
                            <div class="inv-card__actions">
                                <span class="inv-card__retired-note">Retired &mdash; preserved for records</span>
                            </div>
                        <?php elseif ($isBundled && $barcode !== ''): ?>
                            <div class="inv-card__actions">
                                <a class="btn btn-secondary btn-sm" href="product-edit.php?id=<?= $pid ?>">
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit
                                </a>
                                <a class="btn btn-ghost btn-sm" href="labels.php?id=<?= $pid ?>">
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                                    Label
                                </a>
                                <a class="btn btn-danger btn-sm" href="product-retire.php?id=<?= $pid ?>"
                                   onclick="return confirm('Retire this item? It will be removed from the catalog but its record and barcode will be preserved.')">
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                    Retire
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="inv-card__actions">
                                <a class="btn btn-secondary btn-sm" href="product-edit.php?id=<?= $pid ?>">
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit
                                </a>
                                <a class="btn btn-danger btn-sm" href="product-delete.php?id=<?= $pid ?>"
                                   onclick="return confirm('Are you sure you want to delete this product? This action cannot be undone.')">
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                    Delete
                                </a>
                            </div>
                        <?php endif; ?>

                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
            <nav class="inv-pagination" aria-label="Product list pagination">
                <div class="inv-pagination__pages">
                    <?php if ($page > 1): ?>
                        <a class="inv-pagination__btn" href="<?= pageUrl($page - 1) ?>" rel="prev">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                            Prev
                        </a>
                    <?php endif; ?>

                    <?php
                    $range     = 2;
                    $startPage = max(1, $page - $range);
                    $endPage   = min($totalPages, $page + $range);
                    if ($startPage > 1): ?>
                        <a class="inv-pagination__num" href="<?= pageUrl(1) ?>">1</a>
                        <?php if ($startPage > 2): ?>
                            <span class="inv-pagination__ellipsis">&#8230;</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <?php if ($p === $page): ?>
                            <span class="inv-pagination__num is-current" aria-current="page"><?= $p ?></span>
                        <?php else: ?>
                            <a class="inv-pagination__num" href="<?= pageUrl($p) ?>"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="inv-pagination__ellipsis">&#8230;</span>
                        <?php endif; ?>
                        <a class="inv-pagination__num" href="<?= pageUrl($totalPages) ?>"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="inv-pagination__btn" href="<?= pageUrl($page + 1) ?>" rel="next">
                            Next
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
