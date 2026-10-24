<?php

/*
 * Hopia Fits - Legacy product bundle assignment (admin UI).
 *
 * Assigns EXISTING legacy products (bundle_id, bundle_item_number, and
 * barcode_value ALL NULL) to one REAL purchase bundle chosen explicitly
 * by the admin, issuing their individual barcodes safely.
 *
 * Flow (one product at a time, no auto-grouping):
 *   1. Select product: every unassigned product, searchable and
 *      filterable, with status and retirement state visible so
 *      ambiguous items are never silently treated as stock.
 *   2. Select bundle: the admin explicitly picks one existing purchase
 *      bundle. Bundles are NEVER merged or guessed from category or
 *      price; ineligible bundles are disabled with a written reason.
 *   3. Review: a server-rendered review repeats both details plus the
 *      projected item number and barcode before anything is written.
 *      SOLD/retired items must re-acknowledge their status here; the
 *      commit step enforces the same rule server-side.
 *   4. Commit: includes/bundle_assignment.php performs the assignment
 *      inside one transaction with SELECT ... FOR UPDATE locking.
 *
 * The assignment only writes the three bundle columns. Status, price,
 * images, description, defects, and retirement state are never touched,
 * and no sale, order, or verification record is created.
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/bundle_assignment.php';

$validCategories = ['SHIRTS', 'PANTS', 'SHORTS'];
$validStatuses = ['AVAILABLE', 'SOLD'];

function bundle_display_code(int $id): string
{
    return 'HOPEBUNDLE-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function format_date_or_muted($date): string
{
    if ($date === null || $date === '') {
        return '<span class="muted">Not recorded</span>';
    }
    try {
        return htmlspecialchars((new DateTime($date))->format('M j, Y'));
    } catch (Exception $_) {
        return htmlspecialchars((string) $date);
    }
}

$errors = [];
$assignedResult = null;
$step = 'list';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfTokenValue = $_SESSION['csrf_token'];

$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT)
    ?: 0;
$bundleId = filter_input(INPUT_POST, 'bundle_id', FILTER_VALIDATE_INT) ?: 0;

/*
 * ---- Step resolution --------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken = filter_input(INPUT_POST, 'csrf_token', FILTER_DEFAULT);
    $storedToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($csrfToken)
        || $csrfToken === ''
        || !is_string($storedToken)
        || $storedToken === ''
        || !hash_equals($storedToken, $csrfToken)
    ) {
        unset($_SESSION['csrf_token']);
        header('Location: legacy-assign.php');
        exit;
    }

    $postedStep = $_POST['step'] ?? '';
    $statusAck = !empty($_POST['status_ack']);

    if ($postedStep === 'confirm' && $productId > 0 && $bundleId > 0) {
        /* Review step: nothing is written yet. */
        $step = 'confirm';
    } elseif ($postedStep === 'commit' && $productId > 0 && $bundleId > 0) {
        /* Commit step: run the transactional assignment. */
        try {
            /*
             * Ambiguous-status items (SOLD or retired) must have their
             * status explicitly acknowledged before assignment, both
             * here and on the review screen.
             */
            $ackStmt = $pdo->prepare(
                'SELECT status, retired_at FROM products WHERE id = :id'
            );
            $ackStmt->execute([':id' => $productId]);
            $ackRow = $ackStmt->fetch();

            if (
                $ackRow
                && ($ackRow['status'] === 'SOLD' || $ackRow['retired_at'] !== null)
                && empty($_POST['status_ack'])
            ) {
                throw new RuntimeException(
                    'This product is SOLD or retired. Tick the status acknowledgment box to confirm you have reviewed its status before assigning it to a bundle.'
                );
            }

            $assignedResult = assignLegacyProductToBundle($pdo, $productId, $bundleId);
            $step = 'success';
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
            $step = 'select';
        }
    } else {
        $errors[] = 'Invalid assignment request. Please pick a product and a bundle again.';
        $step = 'list';
    }
} elseif ($productId > 0) {
    $step = 'select';
}

/*
 * ---- Data loading for the resolved step -------------------------------
 */

$product = null;
$productImage = '';
$bundles = [];
$unassignedProducts = [];
$productImages = [];
$totalUnassigned = null;
$queryError = false;

try {

    if ($step === 'select' || $step === 'confirm' || $step === 'success') {

        $productStmt = $pdo->prepare(
            'SELECT id, name, category, gender, price, size, color,
                    condition_label, defects, status, retired_at, created_at
             FROM products
             WHERE id = :id'
        );
        $productStmt->execute([':id' => $productId]);
        $product = $productStmt->fetch();

        if (!$product) {
            $errors[] = 'Product not found. It may have been deleted.';
            $step = 'list';
        }
    }

    if ($step === 'select' || $step === 'confirm') {

        $bundleStmt = $pdo->prepare(
            'SELECT pb.id, pb.category, pb.capital_total,
                    pb.original_quantity, pb.purchase_date,
                    COUNT(p.id) AS registered_count
             FROM purchase_bundles pb
             LEFT JOIN products p ON p.bundle_id = pb.id
             GROUP BY pb.id, pb.category, pb.capital_total,
                      pb.original_quantity, pb.purchase_date
             ORDER BY pb.created_at DESC, pb.id DESC'
        );
        $bundleStmt->execute();
        $bundles = $bundleStmt->fetchAll();
    }

    if ($step === 'list') {

        $filterStatus = (string) ($_GET['status'] ?? '');
        $filterCategory = (string) ($_GET['category'] ?? '');
        $filterQuery = trim((string) ($_GET['q'] ?? ''));

        $where = [
            'p.bundle_id IS NULL',
            'p.bundle_item_number IS NULL',
            'p.barcode_value IS NULL',
        ];
        $params = [];

        if (in_array($filterStatus, $validStatuses, true)) {
            $where[] = 'p.status = :status';
            $params[':status'] = $filterStatus;
        }

        if (in_array($filterCategory, $validCategories, true)) {
            $where[] = 'p.category = :category';
            $params[':category'] = $filterCategory;
        }

        if ($filterQuery !== '') {
            $where[] = 'p.name LIKE :q';
            $params[':q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filterQuery) . '%';
        }

        $sql = 'SELECT p.id, p.name, p.category, p.gender, p.price, p.size, p.color,
                       p.condition_label, p.status, p.retired_at, p.created_at
                FROM products p
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.created_at DESC, p.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $unassignedProducts = $stmt->fetchAll();

        /* Total of ALL unassigned products (unfiltered) for the notice. */
        $totalUnassigned = (int) $pdo->query(
            'SELECT COUNT(*) FROM products
             WHERE bundle_id IS NULL
               AND bundle_item_number IS NULL
               AND barcode_value IS NULL'
        )->fetchColumn();
    }

    /* Batch-load the first image per listed product (no N+1). */
    if (!$queryError && count($unassignedProducts) > 0) {
        try {
            $ids = array_map('intval', array_column($unassignedProducts, 'id'));
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

    if (($step === 'select' || $step === 'confirm' || $step === 'success') && $product) {
        try {
            $imgStmt = $pdo->prepare(
                'SELECT image_path
                 FROM product_images
                 WHERE product_id = :id
                 ORDER BY sort_order ASC, id ASC
                 LIMIT 1'
            );
            $imgStmt->execute([':id' => (int) $product['id']]);
            $firstImage = $imgStmt->fetchColumn();
            $productImage = is_string($firstImage) ? trim($firstImage) : '';
        } catch (Throwable $e) {
            $productImage = '';
        }
    }

} catch (Throwable $e) {
    $queryError = true;
}

$isSold = $product !== null && $product['status'] === 'SOLD';
$isRetired = $product !== null && $product['retired_at'] !== null;
$needsStatusAck = $isSold || $isRetired;

/*
 * Ambiguous-status items must acknowledge their status on the select
 * form before the confirmation screen is shown.
 */
if ($step === 'confirm' && $needsStatusAck && empty($statusAck)) {
    $errors[] = 'This product is SOLD or retired. Tick the status acknowledgment box to confirm you have reviewed its status before assigning it to a bundle.';
    $step = 'select';
}

$page_title = "Legacy Inventory Assignment - Admin - Hopia Fits";
$page_description = "Assign existing garments to their confirmed purchase bundles and issue individual barcodes.";
$ui_section = 'admin';
$ui_active = 'legacy-assign.php';
$body_class = 'admin-legacy-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';

/*
 * Shared stepper state: the assignment is a four-step flow.
 */
$legSteps = [
    1 => ['label' => 'Select product', 'icon' => 'shirt'],
    2 => ['label' => 'Choose bundle', 'icon' => 'layers'],
    3 => ['label' => 'Review &amp; confirm', 'icon' => 'check'],
    4 => ['label' => 'Done', 'icon' => 'check'],
];
$legStepActive = ['list' => 1, 'select' => 2, 'confirm' => 3, 'success' => 4][$step] ?? 1;

function leg_step_icon(string $name): string
{
    $paths = [
        'shirt' => '<path d="M9 4 4.6 6.7a1 1 0 0 0-.4 1.2L5.5 11l2.5-.9V20h8v-9.9l2.5.9 1.3-3.1a1 1 0 0 0-.4-1.2L15 4a3 3 0 0 1-6 0z"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 16.5 9 5 9-5"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'clipboard' => '<rect x="5" y="4.5" width="14" height="16" rx="1.5"/><path d="M9 4.5V3.6A1.1 1.1 0 0 1 10.1 2.5h3.8A1.1 1.1 0 0 1 15 3.6v.9"/><path d="m8.8 13 2.2 2.2 4.2-4.4"/>',
    ];
    $path = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}
?>

<div class="leg">

    <!-- Breadcrumb / back link -->
    <nav class="leg__breadcrumb" aria-label="Breadcrumb">
        <a href="products.php">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            Products
        </a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Legacy Inventory Assignment</span>
    </nav>

    <!-- Page header -->
    <header class="leg__header">
        <div class="leg__intro">
            <p class="leg__eyebrow">Bundle management</p>
            <h1 class="leg__title">Legacy Inventory Assignment</h1>
            <p class="leg__lead">
                Assign existing garments to their confirmed purchase bundles and issue individual barcodes.
            </p>
        </div>
        <div class="leg__header-actions">
            <a class="btn btn-secondary btn-sm" href="products.php">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4 4.6 6.7a1 1 0 0 0-.4 1.2L5.5 11l2.5-.9V20h8v-9.9l2.5.9 1.3-3.1a1 1 0 0 0-.4-1.2L15 4a3 3 0 0 1-6 0z"/></svg>
                Back to Products
            </a>
        </div>
    </header>

    <!-- Informational notice -->
    <div class="leg__notice" role="note">
        <span class="leg__notice-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </span>
        <p>
            Each garment must be assigned to its <strong>actual purchase batch</strong>.
            Bundles are not automatically matched by category or capital.
        </p>
        <?php if ($totalUnassigned !== null): ?>
            <span class="leg__notice-count" aria-label="<?= (int) $totalUnassigned ?> unassigned products">
                <?= number_format($totalUnassigned) ?> unassigned
            </span>
        <?php endif; ?>
    </div>

    <?php if ($queryError): ?>
        <div class="alert alert-error" role="alert">
            The inventory could not be loaded. Please try again later.
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error leg__alert" role="alert">
            <strong>This item cannot be assigned yet:</strong>
            <ul class="alert__list">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Progress indicator -->
    <nav class="leg-steps" aria-label="Assignment progress">
        <ol class="leg-steps__list">
            <?php foreach ($legSteps as $legStepNum => $legStepMeta): ?>
                <?php
                if ($legStepNum < $legStepActive) {
                    $legStepState = 'is-done';
                } elseif ($legStepNum === $legStepActive) {
                    $legStepState = 'is-current';
                } else {
                    $legStepState = '';
                }
                ?>
                <li class="leg-steps__item <?= $legStepState ?>">
                    <span class="leg-steps__marker" aria-hidden="true">
                        <?php if ($legStepState === 'is-done'): ?>
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php else: ?>
                            <?= (int) $legStepNum ?>
                        <?php endif; ?>
                    </span>
                    <span class="leg-steps__label"><?= $legStepMeta['label'] ?></span>
                    <?php if ($legStepNum < 4): ?>
                        <span class="leg-steps__divider" aria-hidden="true"></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <?php if ($step === 'list'): ?>

        <!-- Search + filters -->
        <section class="leg-card" aria-labelledby="leg-filters-title">
            <div class="leg-card__head">
                <div>
                    <p class="leg-card__eyebrow">Not yet grouped</p>
                    <h2 class="leg-card__title" id="leg-filters-title">Products without a bundle or barcode</h2>
                </div>
            </div>

            <form class="leg-filter" method="get" action="legacy-assign.php">
                <div class="leg-filter__row">
                    <div class="leg-filter__search">
                        <label class="leg-filter__label" for="filter-q">Search</label>
                        <div class="leg-filter__search-wrap">
                            <svg class="leg-filter__search-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input
                                class="leg-filter__input"
                                type="text"
                                id="filter-q"
                                name="q"
                                value="<?= htmlspecialchars(trim((string) ($_GET['q'] ?? ''))) ?>"
                                placeholder="Product name"
                                autocomplete="off"
                            >
                        </div>
                    </div>

                    <div class="leg-filter__field">
                        <label class="leg-filter__label" for="filter-status">Status</label>
                        <select class="leg-filter__input" id="filter-status" name="status">
                            <option value="">All Statuses</option>
                            <option value="AVAILABLE"<?= ($_GET['status'] ?? '') === 'AVAILABLE' ? ' selected' : '' ?>>Available</option>
                            <option value="SOLD"<?= ($_GET['status'] ?? '') === 'SOLD' ? ' selected' : '' ?>>Sold</option>
                        </select>
                    </div>

                    <div class="leg-filter__field">
                        <label class="leg-filter__label" for="filter-category">Category</label>
                        <select class="leg-filter__input" id="filter-category" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($validCategories as $cat): ?>
                                <option value="<?= $cat ?>"<?= ($_GET['category'] ?? '') === $cat ? ' selected' : '' ?>><?= ucfirst(strtolower($cat)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="leg-filter__submit">
                        <button class="btn btn-primary leg-filter__btn" type="submit">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            Search
                        </button>
                        <a class="btn btn-ghost btn-sm" href="legacy-assign.php">Clear</a>
                    </div>
                </div>
            </form>
        </section>

        <?php if (count($unassignedProducts) === 0): ?>
            <div class="leg-empty">
                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="4" width="17" height="4.5" rx="1"/><path d="M5 8.5h14V19a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19V8.5Z"/><path d="M10 12h4"/></svg>
                <p>No unassigned legacy products were found. Every product already carries a bundle and barcode.</p>
                <a class="btn btn-outline btn-sm" href="products.php">Back to Products</a>
            </div>
        <?php else: ?>

            <div class="leg__results-bar">
                <p>
                    Showing <?= count($unassignedProducts) ?> unassigned product<?= count($unassignedProducts) === 1 ? '' : 's' ?><?= $totalUnassigned !== null && $totalUnassigned !== count($unassignedProducts) ? ' of ' . number_format($totalUnassigned) . ' total' : '' ?>.
                    Assignment only adds the bundle, item number, and barcode &mdash; nothing else about the product changes.
                </p>
            </div>

            <div class="leg-card">
                <div class="table-wrap table-wrap--flush">
                    <table class="leg-table">
                        <thead>
                            <tr>
                                <th scope="col" class="leg-table__col-thumb"><span class="sr-only">Image</span></th>
                                <th scope="col">ID</th>
                                <th scope="col">Product</th>
                                <th scope="col">Category</th>
                                <th scope="col">Price</th>
                                <th scope="col">Condition</th>
                                <th scope="col">Status</th>
                                <th scope="col">Added</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unassignedProducts as $row): ?>
                                <?php
                                $rowId = (int) $row['id'];
                                $rowIsAmbiguous = ($row['status'] === 'SOLD' || $row['retired_at'] !== null);
                                $rowImage = trim($productImages[$rowId] ?? '');
                                ?>
                                <tr>
                                    <td class="leg-table__cell-thumb">
                                        <?php if ($rowImage !== ''): ?>
                                            <img class="leg-table__thumb" src="../<?= htmlspecialchars(ltrim($rowImage, '/')) ?>" alt="" loading="lazy" width="44" height="44">
                                        <?php else: ?>
                                            <span class="leg-table__thumb leg-table__thumb--empty" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="leg-table__id">#<?= $rowId ?></span></td>
                                    <td>
                                        <strong class="leg-table__name"><?= htmlspecialchars($row['name']) ?></strong>
                                        <?php if ($row['size'] !== null || $row['color'] !== null): ?>
                                            <span class="leg-table__sub">
                                                <?= htmlspecialchars(trim(($row['size'] ?? '') . ($row['size'] !== null && $row['color'] !== null ? ' / ' : '') . ($row['color'] ?? ''))) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="leg-badge leg-badge--category"><?= htmlspecialchars($row['category']) ?></span>
                                        <?php if (trim((string) ($row['gender'] ?? '')) !== ''): ?>
                                            <span class="leg-badge leg-badge--muted"><?= htmlspecialchars(trim((string) $row['gender'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="leg-table__price">&#8369;<?= number_format((float) $row['price'], 2) ?></span></td>
                                    <td><?= htmlspecialchars((string) $row['condition_label']) ?></td>
                                    <td>
                                        <span class="leg-badge<?= $row['status'] === 'SOLD' ? ' leg-badge--sold' : ' leg-badge--available' ?>">
                                            <?= htmlspecialchars($row['status']) ?>
                                        </span>
                                        <?php if ($row['retired_at'] !== null): ?>
                                            <span class="leg-badge leg-badge--retired">RETIRED</span>
                                        <?php endif; ?>
                                        <span class="leg-badge leg-badge--unassigned">Unassigned</span>
                                        <?php if ($rowIsAmbiguous): ?>
                                            <span class="leg-table__hint">Status review required</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= format_date_or_muted($row['created_at']) ?></td>
                                    <td>
                                        <a class="btn btn-primary btn-sm" href="legacy-assign.php?product_id=<?= $rowId ?>">
                                            Assign to Bundle
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>

    <?php elseif ($step === 'select'): ?>

        <?php if ($product === null): ?>
            <div class="leg-empty">
                <p>Product could not be loaded.</p>
                <a class="btn btn-outline btn-sm" href="legacy-assign.php">Back to product list</a>
            </div>
        <?php else: ?>

        <div class="leg-layout">

            <!-- Step 1: the garment being assigned -->
            <section class="leg-card" aria-labelledby="leg-product-title">
                <div class="leg-card__head">
                    <div>
                        <p class="leg-card__eyebrow">Step 1 of 3</p>
                        <h2 class="leg-card__title" id="leg-product-title">Garment to assign</h2>
                    </div>
                    <span class="leg-badge leg-badge--unassigned">Unassigned</span>
                </div>

                <div class="leg-product">
                    <?php if ($productImage !== ''): ?>
                        <img class="leg-product__image" src="../<?= htmlspecialchars(ltrim($productImage, '/')) ?>" alt="<?= htmlspecialchars($product['name']) ?>" width="112" height="140">
                    <?php else: ?>
                        <div class="leg-product__image leg-product__image--empty" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        </div>
                    <?php endif; ?>

                    <div class="leg-product__body">
                        <p class="leg-product__idline">
                            <span class="leg-table__id">#<?= (int) $product['id'] ?></span>
                            <span class="leg-badge leg-badge--category"><?= htmlspecialchars($product['category']) ?></span>
                            <?php if (trim((string) ($product['gender'] ?? '')) !== ''): ?>
                                <span class="leg-badge leg-badge--muted"><?= htmlspecialchars(trim((string) $product['gender'])) ?></span>
                            <?php endif; ?>
                        </p>
                        <h3 class="leg-product__name"><?= htmlspecialchars($product['name']) ?></h3>
                        <p class="leg-product__price">&#8369;<?= number_format((float) $product['price'], 2) ?></p>

                        <dl class="leg-product__meta">
                            <div>
                                <dt>Size / Color</dt>
                                <dd><?php echo htmlspecialchars(($product['size'] ?? '-') . ' / ' . ($product['color'] ?? '-')); ?></dd>
                            </div>
                            <div>
                                <dt>Condition</dt>
                                <dd><?php echo htmlspecialchars((string) $product['condition_label']); ?></dd>
                            </div>
                            <div>
                                <dt>Status</dt>
                                <dd>
                                    <span class="leg-badge<?php echo $isSold ? ' leg-badge--sold' : ' leg-badge--available'; ?>"><?php echo htmlspecialchars($product['status']); ?></span>
                                    <?php if ($isRetired): ?><span class="leg-badge leg-badge--retired">RETIRED</span><?php endif; ?>
                                </dd>
                            </div>
                            <div>
                                <dt>Defects</dt>
                                <dd><?php echo $product['defects'] !== null ? htmlspecialchars($product['defects']) : '<span class="muted">None recorded</span>'; ?></dd>
                            </div>
                            <div>
                                <dt>Added</dt>
                                <dd><?php echo format_date_or_muted($product['created_at']); ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <?php if ($needsStatusAck): ?>
                    <div class="leg-warning" role="alert">
                        <span class="leg-warning__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </span>
                        <div>
                            <strong>Status review required.</strong>
                            <?php if ($isSold): ?>
                                This product is marked SOLD (it may have been sold before barcode assignment existed).
                            <?php else: ?>
                                This product is retired (removed from the active catalog).
                            <?php endif; ?>
                            Assignment will <em>not</em> change this status, but you must explicitly acknowledge you have reviewed it below. Printable labels exclude retired items.
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Step 2: choose the real purchase bundle -->
            <form class="leg-form" method="POST" action="legacy-assign.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenValue, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="step" value="confirm">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                <section class="leg-card" aria-labelledby="leg-bundle-title">
                    <div class="leg-card__head">
                        <div>
                            <p class="leg-card__eyebrow">Step 2 of 3</p>
                            <h2 class="leg-card__title" id="leg-bundle-title">Choose the real purchase bundle</h2>
                            <p class="leg-card__hint">
                                Pick the ACTUAL purchase batch this garment came from. Two batches with the same category and capital are still separate bundles &mdash; never guess from price or category.
                            </p>
                        </div>
                    </div>

                    <?php if (count($bundles) === 0): ?>
                        <div class="leg-empty leg-empty--compact">
                            <p>No purchase bundles exist yet.</p>
                            <a class="btn btn-primary btn-sm" href="bundle-create.php">Create a bundle first</a>
                        </div>
                    <?php else: ?>
                        <div class="leg-bundle-grid">
                            <?php foreach ($bundles as $bundle): ?>
                                <?php
                                $bid = (int) $bundle['id'];
                                $registered = (int) $bundle['registered_count'];
                                $origQty = (int) $bundle['original_quantity'];
                                $remaining = $origQty - $registered;
                                $categoryMatches = ($bundle['category'] === $product['category']);
                                $eligible = ($remaining > 0 && $categoryMatches);
                                $capacityPct = $origQty > 0 ? min(100, (int) round($registered / max($origQty, 1) * 100)) : 100;

                                $reason = '';
                                if (!$categoryMatches) {
                                    $reason = 'Category mismatch: this bundle is ' . $bundle['category'] . ', but the product is ' . $product['category'] . '.';
                                } elseif ($remaining <= 0) {
                                    $reason = 'Bundle is at full capacity (' . $origQty . ' of ' . $origQty . ' items assigned).';
                                }
                                ?>
                                <label class="leg-bundle<?= $eligible ? '' : ' is-disabled' ?>">
                                    <input
                                        type="radio"
                                        id="bundle-<?= $bid ?>"
                                        name="bundle_id"
                                        value="<?= $bid ?>"
                                        class="sr-only"
                                        <?= $eligible ? '' : 'disabled' ?>
                                        required
                                    >
                                    <span class="leg-bundle__body">
                                        <span class="leg-bundle__head">
                                            <code class="leg-bundle__code"><?= htmlspecialchars(bundle_display_code($bid)) ?></code>
                                            <span class="leg-badge<?= $categoryMatches ? ' leg-badge--available' : ' leg-badge--sold' ?>">
                                                <?= htmlspecialchars($bundle['category']) ?>
                                            </span>
                                        </span>
                                        <span class="leg-bundle__meta">
                                            <span>
                                                <span class="leg-bundle__meta-label">Capital total</span>
                                                <span class="leg-bundle__price">&#8369;<?php echo number_format((float) $bundle['capital_total'], 2); ?></span>
                                            </span>
                                            <span>
                                                <span class="leg-bundle__meta-label">Original quantity</span>
                                                <span><?php echo $origQty; ?> item<?php echo $origQty === 1 ? '' : 's'; ?></span>
                                            </span>
                                            <span>
                                                <span class="leg-bundle__meta-label">Purchase date</span>
                                                <span><?php echo format_date_or_muted($bundle['purchase_date']); ?></span>
                                            </span>
                                            <span>
                                                <span class="leg-bundle__meta-label">Assigned items</span>
                                                <span><?php echo $registered; ?> of <?php echo $origQty; ?></span>
                                            </span>
                                        </span>
                                        <span class="leg-bundle__capacity">
                                            <?php if ($remaining > 0): ?>
                                                <span class="leg-badge leg-badge--available"><?php echo $remaining; ?> slot<?php echo $remaining === 1 ? '' : 's'; ?> remaining</span>
                                                <span class="leg-bundle__meter" aria-hidden="true"><span style="width: <?php echo $capacityPct; ?>%"></span></span>
                                            <?php else: ?>
                                                <span class="leg-badge leg-badge--sold">Full &mdash; no remaining capacity</span>
                                            <?php endif; ?>
                                        </span>
                                        <?php if ($reason !== ''): ?>
                                            <span class="leg-bundle__reason"><?= htmlspecialchars($reason) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($needsStatusAck): ?>
                        <div class="leg-ack">
                            <label class="leg-ack__label">
                                <input type="checkbox" name="status_ack" value="1" required>
                                <span>
                                    I have reviewed this product's <strong><?= $isSold ? 'SOLD' : 'RETIRED' ?></strong> status and confirm bundle assignment is still appropriate. Assignment will not change this status.
                                </span>
                            </label>
                        </div>
                    <?php endif; ?>

                    <div class="leg-form__actions">
                        <button class="btn btn-primary" type="submit">Review Assignment</button>
                        <a class="btn btn-secondary" href="legacy-assign.php">Cancel</a>
                    </div>
                </section>
            </form>
        </div>

        <?php endif; ?>

    <?php elseif ($step === 'confirm'): ?>

        <?php
        $chosenBundle = null;
        foreach ($bundles as $bundle) {
            if ((int) $bundle['id'] === (int) $bundleId) {
                $chosenBundle = $bundle;
                break;
            }
        }
        ?>

        <?php if ($product === null || $chosenBundle === null): ?>
            <div class="leg-empty">
                <p>The product or the selected bundle could not be found. Please start again.</p>
                <a class="btn btn-primary btn-sm" href="legacy-assign.php">Back to product list</a>
            </div>
        <?php else: ?>

            <?php
            $confirmRegistered = (int) $chosenBundle['registered_count'];
            $confirmQty = (int) $chosenBundle['original_quantity'];
            $confirmRemaining = $confirmQty - $confirmRegistered;
            $confirmCategoryMatch = ($chosenBundle['category'] === $product['category']);
            $confirmItemNumber = $confirmRegistered + 1;
            $confirmBarcode = 'HOPEBUNDLE-'
                . str_pad((string) $chosenBundle['id'], 6, '0', STR_PAD_LEFT)
                . '-'
                . str_pad((string) $confirmItemNumber, 2, '0', STR_PAD_LEFT);
            ?>

            <div class="leg-layout">

                <!-- Review: the garment -->
                <section class="leg-card" aria-labelledby="leg-review-title">
                    <div class="leg-card__head">
                        <div>
                            <p class="leg-card__eyebrow">Step 3 of 3</p>
                            <h2 class="leg-card__title" id="leg-review-title">Review before assigning</h2>
                            <p class="leg-card__hint">
                                The bundle is locked and re-validated inside the assignment transaction. Nothing is written until you confirm below.
                            </p>
                        </div>
                    </div>

                    <div class="leg-product">
                        <?php if ($productImage !== ''): ?>
                            <img class="leg-product__image" src="../<?= htmlspecialchars(ltrim($productImage, '/')) ?>" alt="<?= htmlspecialchars($product['name']) ?>" width="112" height="140">
                        <?php else: ?>
                            <div class="leg-product__image leg-product__image--empty" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            </div>
                        <?php endif; ?>

                        <div class="leg-product__body">
                            <p class="leg-product__idline">
                                <span class="leg-table__id">#<?= (int) $product['id'] ?></span>
                                <span class="leg-badge leg-badge--category"><?= htmlspecialchars($product['category']) ?></span>
                                <?php if (trim((string) ($product['gender'] ?? '')) !== ''): ?>
                                    <span class="leg-badge leg-badge--muted"><?= htmlspecialchars(trim((string) $product['gender'])) ?></span>
                                <?php endif; ?>
                            </p>
                            <h3 class="leg-product__name"><?= htmlspecialchars($product['name']) ?></h3>
                            <p class="leg-product__price">&#8369;<?= number_format((float) $product['price'], 2) ?></p>
                            <p class="leg-product__statusline">
                                Status:
                                <span class="leg-badge<?= $isSold ? ' leg-badge--sold' : ' leg-badge--available' ?>"><?= htmlspecialchars($product['status']) ?></span>
                                <?php if ($isRetired): ?><span class="leg-badge leg-badge--retired">RETIRED</span><?php endif; ?>
                                <span class="muted">(will not change)</span>
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Review: the bundle -->
                <form class="leg-form" method="POST" action="legacy-assign.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenValue, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="step" value="commit">
                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                    <input type="hidden" name="bundle_id" value="<?= (int) $chosenBundle['id'] ?>">

                    <section class="leg-card" aria-labelledby="leg-review-bundle-title">
                        <div class="leg-card__head">
                            <div>
                                <p class="leg-card__eyebrow">Selected purchase bundle</p>
                                <h2 class="leg-card__title" id="leg-review-bundle-title">
                                    <code class="leg-bundle__code"><?= htmlspecialchars(bundle_display_code((int) $chosenBundle['id'])) ?></code>
                                </h2>
                            </div>
                            <?php if ($confirmCategoryMatch): ?>
                                <span class="leg-badge leg-badge--available">Category match</span>
                            <?php else: ?>
                                <span class="leg-badge leg-badge--sold">Category mismatch</span>
                            <?php endif; ?>
                        </div>

                        <dl class="leg-review">
                            <div>
                                <dt>Bundle code</dt>
                                <dd><code class="leg-bundle__code"><?= htmlspecialchars(bundle_display_code((int) $chosenBundle['id'])) ?></code></dd>
                            </div>
                            <div>
                                <dt>Bundle category</dt>
                                <dd><?= htmlspecialchars($chosenBundle['category']) ?></dd>
                            </div>
                            <div>
                                <dt>Capital total</dt>
                                <dd>&#8369;<?= number_format((float) $chosenBundle['capital_total'], 2) ?></dd>
                            </div>
                            <div>
                                <dt>Original quantity</dt>
                                <dd><?= $confirmQty ?> item<?= $confirmQty === 1 ? '' : 's' ?></dd>
                            </div>
                            <div>
                                <dt>Items already assigned</dt>
                                <dd><?= $confirmRegistered ?></dd>
                            </div>
                            <div>
                                <dt>Remaining capacity</dt>
                                <dd><?= $confirmRemaining ?> slot<?= $confirmRemaining === 1 ? '' : 's' ?></dd>
                            </div>
                        </dl>

                        <div class="leg-projected">
                            <div class="leg-projected__row">
                                <span class="leg-bundle__meta-label">Projected item sequence</span>
                                <span class="leg-projected__item">Item #<?= $confirmItemNumber ?> of <?= $confirmQty ?></span>
                            </div>
                            <div>
                                <span class="leg-bundle__meta-label">Generated barcode</span>
                                <code class="leg-projected__barcode"><?= htmlspecialchars($confirmBarcode) ?></code>
                            </div>
                            <p class="leg-projected__note">
                                The final item number and barcode are allocated server-side during the assignment transaction; this is the projected value.
                            </p>
                        </div>

                        <div class="leg-note" role="note">
                            <span class="leg__notice-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </span>
                            <p>
                                Assignment <strong>does not change</strong> the garment's selling price, photo, description, defects, or inventory status. No sale, order, or verification record is created.
                            </p>
                        </div>

                        <?php if ($needsStatusAck): ?>
                            <div class="leg-ack">
                                <label class="leg-ack__label">
                                    <input type="checkbox" name="status_ack" value="1" required>
                                    <span>
                                    I have reviewed this product's <strong><?php echo $isSold ? 'SOLD' : 'RETIRED'; ?></strong> status and confirm bundle assignment is still appropriate. Assignment will not change this status.
                                    </span>
                                </label>
                            </div>
                        <?php endif; ?>

                        <div class="leg-form__actions">
                            <button class="btn btn-primary" type="submit">Confirm Bundle Assignment</button>
                            <a class="btn btn-secondary" href="legacy-assign.php?product_id=<?= (int) $product['id'] ?>">Go Back</a>
                        </div>
                    </section>
                </form>
            </div>

            <?php endif; ?>

    <?php elseif ($step === 'success' && $assignedResult !== null): ?>

        <section class="leg-card leg-success" aria-labelledby="leg-success-title">
            <div class="leg-card__head">
                <div>
                    <p class="leg-card__eyebrow">Success</p>
                    <h2 class="leg-card__title" id="leg-success-title">
                        <span class="leg-success__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </span>
                        Product assigned successfully
                    </h2>
                    <p class="leg-card__hint">
                        The product now belongs to its real purchase bundle and carries a permanent, unique barcode. Its status and all other details are unchanged.
                    </p>
                </div>
                <span class="leg-badge leg-badge--available">Assigned</span>
            </div>

            <dl class="leg-review">
                <div>
                    <dt>Product</dt>
                    <dd>#<?= (int) $product['id'] ?> &mdash; <?= htmlspecialchars($product['name']) ?></dd>
                </div>
                <div>
                    <dt>Bundle</dt>
                    <dd><code class="leg-bundle__code"><?= htmlspecialchars(bundle_display_code((int) $assignedResult['bundle_id'])) ?></code></dd>
                </div>
                <div>
                    <dt>Assigned item number</dt>
                    <dd><?= (int) $assignedResult['bundle_item_number'] ?></dd>
                </div>
                <div>
                    <dt>Barcode value</dt>
                    <dd><code class="leg-projected__barcode"><?= htmlspecialchars($assignedResult['barcode_value']) ?></code></dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>
                        <span class="leg-badge<?= $isSold ? ' leg-badge--sold' : ' leg-badge--available' ?>"><?= htmlspecialchars($product['status']) ?></span>
                        <span class="muted">(unchanged)</span>
                    </dd>
                </div>
            </dl>

            <div class="leg-barcode" aria-label="Barcode preview">
                <svg class="leg-barcode" data-barcode="<?= htmlspecialchars($assignedResult['barcode_value']) ?>" role="img" aria-label="Barcode <?= htmlspecialchars($assignedResult['barcode_value']) ?>"></svg>
            </div>

            <div class="leg-form__actions">
                <a class="btn btn-primary" href="labels.php?id=<?= (int) $product['id'] ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    Print Barcode Label
                </a>
                <a class="btn btn-secondary" href="legacy-assign.php">Assign Another Product</a>
                <a class="btn btn-ghost" href="products.php">Back to Products</a>
            </div>
        </section>

    <?php endif; ?>
</div>

<?php if ($step === 'success' && $assignedResult !== null): ?>
<script src="<?= hopia_e(hopia_asset('js/vendor/jsbarcode/JsBarcode.all.min.js')) ?>"></script>
<script>
(function () {
    'use strict';
    if (typeof JsBarcode === 'undefined') { return; }
    document.querySelectorAll('svg.leg-barcode').forEach(function (el) {
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
<?php endif; ?>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
