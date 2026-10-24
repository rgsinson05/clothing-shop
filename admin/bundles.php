<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/pickup.php';
require_once __DIR__ . '/../includes/bundle_metrics.php';

$validCategories = ['SHIRTS', 'PANTS', 'SHORTS'];

$filterCategory = $_GET['category'] ?? '';
if (!in_array($filterCategory, $validCategories, true)) {
    $filterCategory = '';
}

$filterBundleId = isset($_GET['bundle_id']) && is_string($_GET['bundle_id'])
    ? trim($_GET['bundle_id'])
    : '';

/* Dashboard low-stock card destination. Strict allowlist value only. */
$filterLowStock = isset($_GET['low_stock']) && $_GET['low_stock'] === '1';

$hasFilters = ($filterCategory !== '' || $filterBundleId !== '' || $filterLowStock);

$bundles = [];
$queryError = false;

try {
    $rawBundles = bundle_metrics_rows($pdo, $filterCategory);

    $bundles = [];
    foreach ($rawBundles as $rawRow) {
        $derived = bundle_metrics_derive($rawRow);
        // Dashboard low-stock filter uses the same rule as the card count.
        if ($filterLowStock && !bundle_metrics_is_low_stock($derived)) {
            continue;
        }
        // Client-side bundle-id search filter
        if ($filterBundleId !== '') {
            $code = bundle_code((int) $derived['bundle_id']);
            if (stripos($code, $filterBundleId) === false) {
                continue;
            }
        }
        $bundles[] = $derived;
    }

    $legacySummary = bundle_metrics_legacy_summary($pdo);
} catch (Exception $e) {
    $queryError = true;
    $bundles = [];
    $legacySummary = ['total' => 0, 'available' => 0, 'online_held' => 0, 'recorded_sold_count' => 0, 'retired' => 0];
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';
$page_title = "Bundles & Profitability - Hopia Fits Admin";
$page_description = "Bundle profitability overview — capital, inventory, completed sales, gross profit, and remaining projections.";
$ui_section = 'admin';
$ui_active = 'bundles.php';
$body_class = 'admin-bundles-page';

function bundle_code(int $id): string
{
    return 'HOPEBUNDLE-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function bpc(int $cents): string
{
    return '&#8369;' . number_format($cents / 100, 2);
}

/* Count of a safe plural noun with an explicit item count. */
function bpc_items(int $n): string
{
    return $n . ' item' . ($n === 1 ? '' : 's');
}

/* Order status label consistent with the admin order pages. */
function bpc_order_status_label(string $status): string
{
    switch ($status) {
        case 'PENDING':
            return 'Pending';
        case 'CONFIRMED':
            return 'Confirmed';
        case 'PACKED':
            return 'Packed';
        case 'SHIPPED':
            return 'Shipped';
        case 'DELIVERED':
            return 'Delivered';
        case 'CANCELLED':
            return 'Cancelled';
        default:
            return $status;
    }
}

/* Order status badge class consistent with the admin order pages. */
function bpc_order_status_badge(string $status): string
{
    switch ($status) {
        case 'PENDING':
            return 'badge badge-pending';
        case 'CONFIRMED':
        case 'PACKED':
        case 'SHIPPED':
        case 'DELIVERED':
            return 'badge badge-info';
        default:
            return 'badge';
    }
}

/* Payment-method label consistent with checkout + admin labels. */
function bpc_payment_method_label(?string $method): string
{
    switch ($method) {
        case 'COD':
            return 'Cash on Delivery';
        case 'GCASH':
            return 'GCash';
        case 'CASH_ON_PICKUP':
            return 'Cash on Pickup';
        case 'CARD':
            return 'Card';
        default:
            return 'No payment record';
    }
}

/* Human label for an active-order item's fulfillment method. */
function bpc_fulfillment_label(?string $deliveryMethod, ?string $pickupStatus): string
{
    if ($deliveryMethod === 'STORE_PICKUP') {
        return 'Store Pickup';
    }
    if ($deliveryMethod === 'LOCAL_COURIER') {
        return 'Local Courier';
    }
    /* NULL delivery_method (legacy shipment rows) resolved from the
       order's own pickup lifecycle columns. */
    if ($pickupStatus !== null) {
        return 'Store Pickup';
    }
    return 'Online order';
}

function fmt_date(string $date): string
{
    if ($date === '' || $date === null) {
        return '';
    }
    try {
        $dt = new DateTime($date);
        return $dt->format('M j, Y');
    } catch (Exception $_) {
        return htmlspecialchars((string) $date);
    }
}

?>
<?php require __DIR__ . '/../includes/ui.head.php'; ?>
<?php require __DIR__ . '/../includes/ui.header.php'; ?>

<div class="bp">

    <!-- PAGE HEADER -->
    <header class="bp__header">
        <div class="bp__header-intro">
            <p class="bp__eyebrow">Bundle profitability</p>
            <h1 class="bp__title">BUNDLES &amp; PROFITABILITY</h1>
            <p class="bp__lead">
                Welcome, <?= htmlspecialchars($adminName) ?>.
                Track each bundle&rsquo;s <strong>Total Puhunan</strong>, recorded sold items, eligible completed sales, <strong>Profit So Far</strong>, and clear projections for items still available to sell.
                Profit here compares selling prices against item cost &mdash; other operating expenses are not included.
            </p>
        </div>
        <a class="btn btn-primary bp__add-btn" href="bundle-create.php">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Bundle
        </a>
    </header>

    <!-- FILTER TOOLBAR -->
    <section class="bp-toolbar card" aria-labelledby="bp-toolbar-title">
        <div class="card-body">
            <div class="bp-toolbar__head">
                <div>
                    <p class="bp-toolbar__eyebrow">Refine view</p>
                    <h2 class="bp-toolbar__title" id="bp-toolbar-title">Find a bundle</h2>
                    <?php if ($filterLowStock): ?>
                        <p class="text-small muted">Showing low-stock bundles only.</p>
                    <?php endif; ?>
                </div>
                <?php if ($hasFilters): ?>
                <a class="bp-toolbar__clear" href="bundles.php">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Clear filters
                </a>
                <?php endif; ?>
            </div>

            <form class="bp-filter" method="get" action="bundles.php" novalidate>
                <?php if ($filterLowStock): ?>
                    <input type="hidden" name="low_stock" value="1">
                <?php endif; ?>
                <div class="bp-filter__row">
                    <div class="bp-filter__field">
                        <label class="bp-filter__label" for="filter-bid">Bundle Code</label>
                        <div class="bp-filter__search-wrap">
                            <svg class="bp-filter__search-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input
                                class="bp-filter__input"
                                type="text"
                                id="filter-bid"
                                name="bundle_id"
                                value="<?= htmlspecialchars($filterBundleId) ?>"
                                placeholder="HOPEBUNDLE-000001"
                                autocomplete="off"
                            >
                        </div>
                    </div>

                    <div class="bp-filter__field">
                        <label class="bp-filter__label" for="filter-category">Category</label>
                        <select class="bp-filter__input" id="filter-category" name="category">
                            <option value="">All Categories</option>
                            <option value="SHIRTS"<?= $filterCategory === 'SHIRTS' ? ' selected' : '' ?>>Shirts</option>
                            <option value="PANTS"<?= $filterCategory === 'PANTS' ? ' selected' : '' ?>>Pants</option>
                            <option value="SHORTS"<?= $filterCategory === 'SHORTS' ? ' selected' : '' ?>>Shorts</option>
                        </select>
                    </div>

                    <div class="bp-filter__submit">
                        <button class="btn btn-primary bp-filter__btn" type="submit">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success" role="status">
            Bundle created successfully.
        </div>
    <?php endif; ?>

    <?php if ($queryError): ?>
        <div class="alert alert-error" role="alert">
            Bundles could not be loaded. Please try refreshing the page.
        </div>

    <?php elseif (count($bundles) === 0 && !$hasFilters): ?>
        <div class="bp-empty">
            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 16.5 9 5 9-5"/></svg>
            <p>No bundles have been created yet.</p>
            <a class="btn btn-primary btn-sm" href="bundle-create.php">Create your first bundle</a>
        </div>

    <?php elseif (count($bundles) === 0 && $hasFilters): ?>
        <div class="bp-empty">
            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <p>No bundles match the selected filters.</p>
            <a class="btn btn-outline btn-sm" href="bundles.php">Clear filters</a>
        </div>

    <?php else: ?>

        <!-- RESULT COUNT -->
        <div class="bp-results">
            <p class="bp-results__count">
                Showing <?= count($bundles) ?> bundle<?= count($bundles) === 1 ? '' : 's' ?>
                &mdash; <strong>Eligible Completed Sales</strong> figures follow Sales Monitoring eligibility. Recorded sold items are inventory history; active holds are shown separately and never counted as completed finances or available projections.
            </p>
        </div>

        <!-- ========================================================
             LEGACY UNASSIGNED PRODUCTS
             ======================================================== -->
        <?php if (!$hasFilters && $legacySummary && (int) $legacySummary['total'] > 0): ?>
        <section class="bp-legacy card" aria-labelledby="bp-legacy-title">
            <div class="card-body">
                <div class="bp-legacy__head">
                    <div>
                        <p class="bp-legacy__eyebrow">Legacy inventory</p>
                        <h2 class="bp-legacy__title" id="bp-legacy-title">Unassigned products</h2>
                    </div>
                    <span class="badge"><?= (int) $legacySummary['total'] ?> items</span>
                </div>
                <p class="bp-legacy__note">
                    Excluded from per-bundle metrics. Assign them via the Legacy Assignment workflow.
                </p>
                <div class="bp-legacy__tiles">
                    <div class="bp-inv-tile">
                        <p class="bp-inv-tile__label">Available</p>
                        <p class="bp-inv-tile__value"><?= (int) ($legacySummary['available'] ?? 0) ?></p>
                    </div>
                    <div class="bp-inv-tile bp-inv-tile--held">
                        <p class="bp-inv-tile__label">Online-held</p>
                        <p class="bp-inv-tile__value"><?= (int) ($legacySummary['online_held'] ?? 0) ?></p>
                        <p class="bp-inv-tile__sub">In active online order</p>
                    </div>
                    <div class="bp-inv-tile bp-inv-tile--sold">
                        <p class="bp-inv-tile__label">Recorded Sold Items</p>
                        <p class="bp-inv-tile__value"><?= (int) ($legacySummary['recorded_sold_count'] ?? 0) ?></p>
                        <p class="bp-inv-tile__sub">Sale-item history, not financial eligibility</p>
                    </div>
                    <div class="bp-inv-tile bp-inv-tile--retired">
                        <p class="bp-inv-tile__label">Retired</p>
                        <p class="bp-inv-tile__value"><?= (int) ($legacySummary['retired'] ?? 0) ?></p>
                    </div>
                </div>
                <div class="bp-legacy__action">
                    <a class="btn btn-secondary btn-sm" href="legacy-assign.php">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V7"/><path d="M10 11h4"/></svg>
                        Review Legacy Assignment
                    </a>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ========================================================
             BUNDLE CARDS GRID
             ======================================================== -->
        <div class="bp-grid">
            <?php foreach ($bundles as $bundle):
                $bundleId        = (int) $bundle['bundle_id'];
                $code            = bundle_code($bundleId);
                $capitalTotal    = (int) $bundle['capital_cents'];
                $originalQty     = (int) $bundle['original_qty'];
                $unitCost        = (int) $bundle['unit_cost_cents'];

                $registeredCount     = (int) $bundle['registered_count'];
                $unregisteredQty     = (int) $bundle['unregistered_qty'];
                $availableCount      = (int) $bundle['available_count'];
                $onlineHeldCount    = (int) $bundle['online_held_count'];
                $recordedSoldCount = (int) $bundle['recorded_sold_count'];
                $retiredCount       = (int) $bundle['retired_count'];

                $walkinRevenue  = (int) $bundle['walkin_revenue_cents'];
                $onlineRevenue  = (int) $bundle['online_revenue_cents'];
                $walkinItems    = (int) $bundle['walkin_items'];
                $onlineItems    = (int) $bundle['online_items'];

                $totalGrossProfit = (int) $bundle['gross_profit_cents'];

                $remainingRevenue     = (int) $bundle['remaining_revenue_cents'];
                $remainingItems       = (int) $bundle['remaining_items'];
                $remainingGrossProfit = (int) $bundle['remaining_profit_cents'];
                $remainingCogs       = (int) $bundle['remaining_cogs_cents'];

                $totalCompletedRevenue = (int) $bundle['completed_revenue_cents'];
                $totalCompletedItems   = (int) $bundle['completed_items'];
                $totalCompletedCogs    = (int) $bundle['completed_cogs_cents'];

                $gcashRevenue = (int) $bundle['gcash_paid_revenue_cents'];
                $gcashItems   = (int) $bundle['gcash_paid_items'];
                $codRevenue   = (int) $bundle['cod_paid_revenue_cents'];
                $codItems     = (int) $bundle['cod_paid_items'];
                $unrecordedItems = (int) $bundle['online_unrecorded_items'];

                $totalPotentialRevenue = (int) $bundle['potential_revenue_cents'];
                $totalPotentialProfit  = (int) $bundle['potential_profit_cents'];

                $purchaseDate   = $bundle['purchase_date'] ?? '';
                $formattedDate = fmt_date($purchaseDate);

                $hasCompletedSales = ($totalCompletedItems > 0);
                $hasRemaining     = ($remainingItems > 0);
                $isFullyRegistered = ($unregisteredQty === 0);

                /* ---- Financial Details dropdown data (read-only) ---- */

                /* A. Canonically eligible completed sale items with stored snapshots. */
                $completedItems = $queryError ? [] : bundle_metrics_completed_items($pdo, $bundleId);

                /* B. Online-held garments in active orders. Amounts are
                   computed from order snapshots when every held garment
                   resolves to an order item; otherwise null = not shown. */
                $activeOrdersTotalCents = null;
                $activePickupCount = 0;
                $activeCourierCount = 0;
                $activeOrphanedCount = 0;
                $activeOrderItems = [];
                if (!$queryError && $onlineHeldCount > 0) {
                    $active = bundle_metrics_active_order_items($pdo, $bundleId);
                    $activeOrderItems = $active['items'];
                    $activeOrphanedCount = (int) $active['orphaned_count'];

                    foreach ($activeOrderItems as $ai) {
                        if ($ai['delivery_method'] === 'STORE_PICKUP' || $ai['pickup_status'] !== null) {
                            $activePickupCount++;
                        } elseif ($ai['delivery_method'] === 'LOCAL_COURIER') {
                            $activeCourierCount++;
                        }
                        /* Garments with no shipment record fall in neither
                           count; they stay visible in the details list with
                           an honest fallback label. */
                    }

                    if ($activeOrphanedCount === 0) {
                        $sum = 0;
                        foreach ($activeOrderItems as $ai) {
                            $sum += (int) $ai['price_cents'];
                        }
                        $activeOrdersTotalCents = $sum;
                    }
                }

                /* C. Available garments feeding Potential Sales. */
                $availableItems = $queryError ? [] : bundle_metrics_available_items($pdo, $bundleId);

                /* E. Payment collection groupings, derived from the actual
                   payment records of this bundle's receipt-confirmed recorded
                   online sales, including financially ineligible records.
                   GCash sub-states follow payment_gcash_state(); a missing
                   payments row is reported as its own category. */
                $gcashAwaitingItems = 0;
                $gcashAwaitingCents = 0;
                $gcashRejectedItems = 0;
                $codPendingItems = 0;
                $codPendingCents = 0;
                $copPendingItems = 0;
                $copPendingCents = 0;
                $copPaidItems = 0;
                $copPaidCents = 0;
                $unrecordedCents = 0;
                $onlinePaidItems = 0;
                if (!$queryError) {
                    foreach (bundle_metrics_payment_items($pdo, $bundleId) as $pi) {
                        $cents = (int) $pi['price_cents'];
                        if ($pi['payment_method'] === null || $pi['payment_status'] === null) {
                            $unrecordedCents += $cents;
                            continue;
                        }
                        if ($pi['payment_method'] === 'GCASH' && $pi['payment_status'] === 'PAID') {
                            $onlinePaidItems++;
                        } elseif ($pi['payment_method'] === 'GCASH') {
                            $state = payment_gcash_state([
                                'payment_method' => 'GCASH',
                                'payment_status' => $pi['payment_status'],
                                'gcash_reference' => $pi['gcash_reference'],
                                'submitted_at' => $pi['submitted_at'],
                            ]);
                            if ($state === 'AWAITING' || $state === 'NOT_SUBMITTED') {
                                $gcashAwaitingItems++;
                                $gcashAwaitingCents += $cents;
                            } else {
                                /* REJECTED: recorded, needs correction. */
                                $gcashRejectedItems++;
                            }
                        } elseif ($pi['payment_method'] === 'COD' && $pi['payment_status'] !== 'PAID') {
                            $codPendingItems++;
                            $codPendingCents += $cents;
                        } elseif ($pi['payment_method'] === 'COD') {
                            $onlinePaidItems++;
                        } elseif ($pi['payment_method'] === 'CASH_ON_PICKUP' && $pi['payment_status'] !== 'PAID') {
                            $copPendingItems++;
                            $copPendingCents += $cents;
                        } elseif ($pi['payment_method'] === 'CASH_ON_PICKUP') {
                            $copPaidItems++;
                            $copPaidCents += $cents;
                            $onlinePaidItems++;
                        }
                    }
                }

                // Progress percentage
                $regPct = $originalQty > 0 ? min(100, round(($registeredCount / $originalQty) * 100)) : 0;
            ?>
                <article class="bp-card card" aria-labelledby="bp-card-<?= $bundleId ?>-title">

                    <!-- CARD HEADER -->
                    <div class="bp-card__head">
                        <div class="bp-card__identity">
                            <p class="bp-card__code" id="bp-card-<?= $bundleId ?>-title"><?= htmlspecialchars($code) ?></p>
                            <div class="bp-card__meta">
                                <span class="badge"><?= htmlspecialchars($bundle['category']) ?></span>
                                <?php if ($formattedDate !== ''): ?>
                                    <span class="bp-card__date"><?= htmlspecialchars($formattedDate) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="bp-card__capital">
                            <p class="bp-card__capital-label">Total Puhunan</p>
                            <p class="bp-card__capital-value"><?= bpc($capitalTotal) ?></p>
                            <p class="bp-card__unit-cost">
                                Item cost: <?= bpc($unitCost) ?><span class="muted"> / item</span>
                                &nbsp;&middot;&nbsp; Orig. qty <?= $originalQty ?>
                            </p>
                        </div>
                    </div>

                    <!-- REGISTRATION PROGRESS -->
                    <div class="bp-card__reg">
                        <div class="bp-card__reg-head">
                            <p class="bp-card__reg-label">
                                Registered
                                <span class="bp-card__reg-count">
                                    <?= $registeredCount ?> of <?= $originalQty ?>
                                </span>
                            </p>
                            <?php if ($unregisteredQty > 0): ?>
                                <span class="bp-card__unreg-badge"><?= $unregisteredQty ?> unregistered</span>
                            <?php else: ?>
                                <span class="badge badge-success">Complete</span>
                            <?php endif; ?>
                        </div>
                        <div class="bp-progress" role="progressbar" aria-valuenow="<?= $regPct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Registration progress">
                            <div class="bp-progress__bar" style="width:<?= $regPct ?>%"></div>
                        </div>
                    </div>

                    <!-- INVENTORY STATUS TILES -->
                    <div class="bp-card__inv">
                        <?php if ($availableCount > 0): ?>
                        <div class="bp-inv-tile bp-inv-tile--available">
                            <p class="bp-inv-tile__label">Available</p>
                            <p class="bp-inv-tile__value"><?= $availableCount ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($onlineHeldCount > 0): ?>
                        <div class="bp-inv-tile bp-inv-tile--held">
                            <p class="bp-inv-tile__label">Online-held</p>
                            <p class="bp-inv-tile__value"><?= $onlineHeldCount ?></p>
                            <p class="bp-inv-tile__sub">In active order</p>
                        </div>
                        <?php endif; ?>
                        <?php if ($recordedSoldCount > 0): ?>
                        <div class="bp-inv-tile bp-inv-tile--sold">
                            <p class="bp-inv-tile__label">Recorded Sold Items</p>
                            <p class="bp-inv-tile__value"><?= $recordedSoldCount ?></p>
                            <p class="bp-inv-tile__sub">Sale-item history, not financial eligibility</p>
                        </div>
                        <?php endif; ?>
                        <?php if ($retiredCount > 0): ?>
                        <div class="bp-inv-tile bp-inv-tile--retired">
                            <p class="bp-inv-tile__label">Retired</p>
                            <p class="bp-inv-tile__value"><?= $retiredCount ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ($availableCount === 0 && $onlineHeldCount === 0 && $recordedSoldCount === 0 && $retiredCount === 0): ?>
                        <div class="bp-inv-tile">
                            <p class="bp-inv-tile__label muted">No items registered</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- COMPLETED FINANCIAL RESULTS -->
                    <div class="bp-card__results">
                        <?php if (!$hasCompletedSales): ?>
                        <p class="bp-card__results-empty muted text-small">Eligible Completed Sales: 0 items. No eligible merchandise revenue or COGS yet.</p>
                        <?php else: ?>
                        <div class="bp-results-grid">
                            <div class="bp-result-item">
                                <p class="bp-result-item__label">Eligible Completed Sales</p>
                                <p class="bp-result-item__value"><?= bpc($totalCompletedRevenue) ?></p>
                                <p class="bp-result-item__sub muted">
                                    <?= bpc_items($totalCompletedItems) ?> financially eligible
                                </p>
                            </div>
                            <div class="bp-result-item">
                                <p class="bp-result-item__label">Profit So Far</p>
                                <p class="bp-result-item__value <?= $totalGrossProfit >= 0 ? 'is-profit' : 'is-loss' ?>">
                                    <?= bpc($totalGrossProfit) ?>
                                </p>
                                <p class="bp-result-item__sub muted">
                                    COGS (eligible items): <?= bpc($totalCompletedCogs) ?>
                                </p>
                            </div>
                        </div>
                        <p class="bp-profit-note muted">
                            Eligible completed revenue minus allocated item cost. Not net business profit &mdash; operating expenses are not included.
                        </p>
                        <?php endif; ?>
                    </div>

                    <!-- ITEMS IN ACTIVE ORDERS (visibility strip) -->
                    <?php if ($onlineHeldCount > 0): ?>
                    <div class="bp-card__held" role="note">
                        <span class="bp-card__held-label">
                            In active online orders:
                            <strong><?= bpc_items($onlineHeldCount) ?></strong>
                            <?php if ($activeCourierCount > 0): ?>
                                &middot; <?= $activeCourierCount ?> Local Courier
                            <?php endif; ?>
                            <?php if ($activePickupCount > 0): ?>
                                &middot; <?= $activePickupCount ?> Store Pickup
                            <?php endif; ?>
                        </span>
                        <?php if ($activeOrdersTotalCents !== null): ?>
                        <span class="bp-card__held-amount">
                            Sales in Active Orders: <strong class="is-projection"><?= bpc($activeOrdersTotalCents) ?></strong>
                        </span>
                        <?php else: ?>
                        <span class="bp-card__held-amount muted">Order amounts unavailable</span>
                        <?php endif; ?>
                        <span class="bp-card__held-note muted">Not yet completed sales; excluded from the figures below.</span>
                    </div>
                    <?php endif; ?>

                    <!-- AVAILABLE ITEMS PROJECTION -->
                    <div class="bp-card__projection">
                        <?php if (!$hasRemaining): ?>
                        <p class="bp-card__proj-empty muted text-small">No available items to project</p>
                        <?php else: ?>
                        <div class="bp-proj-grid">
                            <div class="bp-proj-item">
                                <p class="bp-proj-item__label">Potential Sales</p>
                                <p class="bp-proj-item__value is-projection"><?= bpc($remainingRevenue) ?></p>
                                <p class="bp-proj-item__sub muted">
                                    <?= bpc_items($remainingItems) ?> still available
                                </p>
                            </div>
                            <div class="bp-proj-item">
                                <p class="bp-proj-item__label">Potential Profit</p>
                                <p class="bp-proj-item__value <?= $remainingGrossProfit >= 0 ? 'is-profit' : 'is-loss' ?>">
                                    <?= bpc($remainingGrossProfit) ?>
                                </p>
                                <p class="bp-proj-item__sub muted">
                                    Item cost: <?= bpc($remainingCogs) ?>
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- COMBINED PROJECTION -->
                    <?php if ($hasCompletedSales && $hasRemaining): ?>
                    <div class="bp-card__total">
                        <div class="bp-total-grid">
                            <div class="bp-total-item">
                                <p class="bp-total-item__label">Total If Remaining Items Sell</p>
                                <p class="bp-total-item__value is-projection"><?= bpc($totalPotentialRevenue) ?></p>
                            </div>
                            <div class="bp-total-item">
                                <p class="bp-total-item__label">Potential Total Profit <span class="bp-card__proj-flag">Projection</span></p>
                                <p class="bp-total-item__value <?= $totalPotentialProfit >= 0 ? 'is-profit' : 'is-loss' ?>">
                                    <?= bpc($totalPotentialProfit) ?>
                                </p>
                            </div>
                        </div>
                        <p class="bp-total-note muted">
                            Projection: Eligible Completed Sales (<?= bpc($totalCompletedRevenue) ?>) + Potential Sales (<?= bpc($remainingRevenue) ?>). Not yet earned.
                        </p>
                    </div>
                    <?php endif; ?>

                    <!-- CARD ACTIONS -->
                    <div class="bp-card__actions">
                        <?php if ($isFullyRegistered): ?>
                            <span class="badge badge-sold bp-card__full-badge">Full — all items registered</span>
                        <?php else: ?>
                            <a class="btn btn-secondary btn-sm" href="product-create.php?bundle_id=<?= $bundleId ?>">
                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Register Item
                            </a>
                        <?php endif; ?>
                        <a class="btn btn-outline btn-sm" href="labels.php?bundle_id=<?= $bundleId ?>">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 5v14M7 5v14M10.5 5v14M14 5v14M17 5v14M20 5v14"/></svg>
                            Print Labels
                        </a>
                    </div>

                    <!-- EXPANDABLE FINANCIAL DETAILS -->
                    <details class="bp-card__details">
                        <summary class="bp-card__details-toggle">
                            <svg class="bp-card__details-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                            Financial details
                        </summary>
                        <div class="bp-card__details-body">

                            <?php if (!$hasCompletedSales && $recordedSoldCount === 0 && !$hasRemaining && $onlineHeldCount === 0 && $retiredCount === 0 && $unregisteredQty === 0): ?>
                            <p class="muted text-small bp-details-empty">Nothing to break down yet.</p>
                            <?php endif; ?>

                            <?php if ($hasCompletedSales || $recordedSoldCount > 0): ?>
                            <details class="bp-sub">
                                <summary class="bp-sub__toggle">
                                    <span class="bp-sub__title">A. Eligible Completed Sales</span>
                                    <span class="bp-sub__hint"><?= bpc($totalCompletedRevenue) ?> &middot; <?= bpc_items($totalCompletedItems) ?></span>
                                </summary>
                                <div class="bp-sub__body">
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Eligible completed revenue (merchandise)</span>
                                        <span class="bp-detail-row__value"><?= bpc($totalCompletedRevenue) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($totalCompletedItems) ?> passing Sales Monitoring eligibility</span>
                                    </div>
                                    <?php foreach ($completedItems as $ci): ?>
                                    <div class="bp-detail-row bp-detail-row--item">
                                        <span class="bp-detail-row__label">
                                            <?= htmlspecialchars($ci['name']) ?>
                                            <span class="badge <?= $ci['channel'] === 'WALKIN' ? 'badge-info' : 'badge-pending' ?>"><?= $ci['channel'] === 'WALKIN' ? 'Walk-in' : 'Online' ?></span>
                                        </span>
                                        <span class="bp-detail-row__value"><?= bpc($ci['price_cents']) ?></span>
                                        <span class="bp-detail-row__sub muted">
                                            <?= $ci['channel'] === 'WALKIN' ? 'Ref SALE-' . str_pad((string) $ci['sale_id'], 6, '0', STR_PAD_LEFT) : 'Order #' . (int) $ci['order_id'] ?>
                                            &middot; <?= fmt_date($ci['sold_at']) ?>
                                            <?php if ($ci['barcode'] !== null): ?> &middot; <?= htmlspecialchars($ci['barcode']) ?><?php endif; ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="bp-detail-row bp-detail-row--divider">
                                        <span class="bp-detail-row__label">Profit So Far</span>
                                        <span class="bp-detail-row__value <?= $totalGrossProfit >= 0 ? 'is-profit' : 'is-loss' ?>"><?= bpc($totalGrossProfit) ?></span>
                                        <span class="bp-detail-row__sub muted">Eligible completed revenue &minus; COGS (<?= bpc($totalCompletedCogs) ?>)</span>
                                    </div>
                                </div>
                            </details>
                            <?php endif; ?>

                            <details class="bp-sub">
                                <summary class="bp-sub__toggle">
                                    <span class="bp-sub__title">B. Items in Active Orders</span>
                                    <span class="bp-sub__hint">
                                        <?php if ($onlineHeldCount > 0 && $activeOrdersTotalCents !== null): ?>
                                            <?= bpc_items($onlineHeldCount) ?> &middot; <?= bpc($activeOrdersTotalCents) ?>
                                        <?php elseif ($onlineHeldCount > 0): ?>
                                            <?= bpc_items($onlineHeldCount) ?>
                                        <?php else: ?>
                                            None
                                        <?php endif; ?>
                                    </span>
                                </summary>
                                <div class="bp-sub__body">
                                    <?php if ($onlineHeldCount === 0): ?>
                                    <p class="muted text-small">No garments are held by active online orders right now.</p>
                                    <?php else: ?>
                                        <?php if ($activeOrdersTotalCents !== null): ?>
                                        <div class="bp-detail-row">
                                            <span class="bp-detail-row__label">Sales in Active Orders</span>
                                            <span class="bp-detail-row__value is-projection"><?= bpc($activeOrdersTotalCents) ?></span>
                                            <span class="bp-detail-row__sub muted">Order prices; not yet completed sales</span>
                                        </div>
                                        <?php else: ?>
                                        <p class="muted text-small">Order amounts cannot be shown reliably for these held items.</p>
                                        <?php endif; ?>
                                        <?php if ($activePickupCount > 0 || $activeCourierCount > 0): ?>
                                        <div class="bp-detail-row">
                                            <span class="bp-detail-row__label">Holds by fulfillment</span>
                                            <span class="bp-detail-row__value"><?= (int) $activePickupCount ?> SP &middot; <?= (int) $activeCourierCount ?> LC</span>
                                            <span class="bp-detail-row__sub muted">SP = Store Pickup, LC = Local Courier</span>
                                        </div>
                                        <?php endif; ?>
                                        <?php foreach ($activeOrderItems as $ai): ?>
                                        <div class="bp-detail-row bp-detail-row--item">
                                            <span class="bp-detail-row__label">
                                                <?= htmlspecialchars($ai['name']) ?>
                                                <span class="badge badge-warning"><?= htmlspecialchars(bpc_fulfillment_label($ai['delivery_method'], $ai['pickup_status'])) ?></span>
                                            </span>
                                            <?php if ($ai['price_cents'] > 0): ?>
                                            <span class="bp-detail-row__value"><?= bpc($ai['price_cents']) ?></span>
                                            <?php else: ?>
                                            <span class="bp-detail-row__value muted">&mdash;</span>
                                            <?php endif; ?>
                                            <span class="bp-detail-row__sub muted">
                                                Order #<?= (int) $ai['order_id'] ?>
                                                &middot; <span class="badge <?= bpc_order_status_badge($ai['order_status']) ?>"><?= htmlspecialchars(bpc_order_status_label($ai['order_status'])) ?></span>
                                                <?php if ($ai['pickup_status'] !== null && $ai['pickup_status'] !== 'PICKED_UP'): ?>
                                                    &middot; <?= htmlspecialchars(pickup_stage_label($ai['pickup_status'])) ?>
                                                <?php endif; ?>
                                                <?php if ($ai['price_cents'] === 0): ?> &middot; price not recorded<?php endif; ?>
                                            </span>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if ($activeOrphanedCount > 0): ?>
                                        <div class="bp-detail-row bp-detail-row--item">
                                            <span class="bp-detail-row__label muted">Held but not linked to an active order</span>
                                            <span class="bp-detail-row__value muted"><?= (int) $activeOrphanedCount ?></span>
                                            <span class="bp-detail-row__sub muted">Marked sold but no active order holds them; amount cannot be determined</span>
                                        </div>
                                        <?php endif; ?>
                                        <p class="bp-sub-note muted">
                                            These garments are not yet completed sales and are not part of Potential Sales. Amounts may change if an order is cancelled or becomes inactive.
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </details>

                            <?php if ($hasRemaining): ?>
                            <details class="bp-sub">
                                <summary class="bp-sub__toggle">
                                    <span class="bp-sub__title">C. Available Items &mdash; Potential Sales</span>
                                    <span class="bp-sub__hint"><?= bpc($remainingRevenue) ?> &middot; <?= bpc_items($remainingItems) ?></span>
                                </summary>
                                <div class="bp-sub__body">
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Potential Sales total</span>
                                        <span class="bp-detail-row__value is-projection"><?= bpc($remainingRevenue) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($remainingItems) ?> available at current prices</span>
                                    </div>
                                    <?php foreach ($availableItems as $avail): ?>
                                    <div class="bp-detail-row bp-detail-row--item">
                                        <span class="bp-detail-row__label"><?= htmlspecialchars($avail['name']) ?></span>
                                        <span class="bp-detail-row__value is-projection"><?= bpc($avail['price_cents']) ?></span>
                                        <span class="bp-detail-row__sub muted">
                                            <?php if ($avail['barcode'] !== null): ?><?= htmlspecialchars($avail['barcode']) ?> &middot; <?php endif; ?>
                                            Item cost <?= bpc($unitCost) ?> &middot; Potential Profit contribution <?= bpc($avail['price_cents'] - $unitCost) ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="bp-detail-row bp-detail-row--divider">
                                        <span class="bp-detail-row__label">Potential Profit</span>
                                        <span class="bp-detail-row__value <?= $remainingGrossProfit >= 0 ? 'is-profit' : 'is-loss' ?>"><?= bpc($remainingGrossProfit) ?></span>
                                        <span class="bp-detail-row__sub muted">Potential Sales &minus; item cost of these <?= bpc_items($remainingItems) ?> (<?= bpc($remainingCogs) ?>)</span>
                                    </div>
                                </div>
                            </details>
                            <?php endif; ?>

                            <details class="bp-sub">
                                <summary class="bp-sub__toggle">
                                    <span class="bp-sub__title">D. Item Costs and Profit</span>
                                    <span class="bp-sub__hint">Profit So Far <?= bpc($totalGrossProfit) ?></span>
                                </summary>
                                <div class="bp-sub__body">
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Total Puhunan</span>
                                        <span class="bp-detail-row__value"><?= bpc($capitalTotal) ?></span>
                                        <span class="bp-detail-row__sub muted">Bundle total puhunan</span>
                                    </div>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Item cost</span>
                                        <span class="bp-detail-row__value"><?= bpc($unitCost) ?></span>
                                        <span class="bp-detail-row__sub muted">Total Puhunan &divide; original qty (<?= $originalQty ?>), fixed at purchase</span>
                                    </div>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">COGS (eligible items)</span>
                                        <span class="bp-detail-row__value"><?= bpc($totalCompletedCogs) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc($unitCost) ?> &times; <?= bpc_items($totalCompletedItems) ?> in eligible completed sales</span>
                                    </div>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Profit So Far</span>
                                        <span class="bp-detail-row__value <?= $totalGrossProfit >= 0 ? 'is-profit' : 'is-loss' ?>"><?= bpc($totalGrossProfit) ?></span>
                                        <span class="bp-detail-row__sub muted">Eligible completed revenue (<?= bpc($totalCompletedRevenue) ?>) &minus; COGS (<?= bpc($totalCompletedCogs) ?>)</span>
                                    </div>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Potential Profit</span>
                                        <span class="bp-detail-row__value <?= $remainingGrossProfit >= 0 ? 'is-profit' : 'is-loss' ?>"><?= bpc($remainingGrossProfit) ?></span>
                                        <span class="bp-detail-row__sub muted">Potential Sales (<?= bpc($remainingRevenue) ?>) &minus; item cost of <?= $remainingItems ?> available item<?= $remainingItems === 1 ? '' : 's' ?> (<?= bpc($remainingCogs) ?>)</span>
                                    </div>
                                    <p class="bp-sub-note muted">
                                        Profit figures are not net business profit; other operating expenses are not included.
                                    </p>
                                </div>
                            </details>

                            <details class="bp-sub">
                                <summary class="bp-sub__toggle">
                                    <span class="bp-sub__title">E. Payment Collection Diagnostics</span>
                                    <span class="bp-sub__hint"><?= bpc_items($onlinePaidItems) ?> recorded as paid online</span>
                                </summary>
                                <div class="bp-sub__body">
                                    <?php if ($hasCompletedSales && $walkinItems > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Walk-in cash</span>
                                        <span class="bp-detail-row__value"><?= bpc($walkinRevenue) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($walkinItems) ?> &middot; paid at the counter at sale time</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($gcashAwaitingItems > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">GCash awaiting verification</span>
                                        <span class="bp-detail-row__value"><?= bpc($gcashAwaitingCents) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($gcashAwaitingItems) ?> &middot; reference submitted, not yet verified</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($gcashItems > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">GCash verified / paid</span>
                                        <span class="bp-detail-row__value is-profit"><?= bpc($gcashRevenue) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($gcashItems) ?> &middot; recorded as PAID</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($codPendingItems > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Cash on Delivery &mdash; awaiting collection</span>
                                        <span class="bp-detail-row__value"><?= bpc($codPendingCents) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($codPendingItems) ?> &middot; still pending</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($codItems > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Cash on Delivery &mdash; recorded as paid</span>
                                        <span class="bp-detail-row__value is-profit"><?= bpc($codRevenue) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($codItems) ?> &middot; recorded as PAID</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($copPendingItems > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Cash on Pickup &mdash; awaiting collection</span>
                                        <span class="bp-detail-row__value"><?= bpc($copPendingCents) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($copPendingItems) ?> &middot; still pending</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($copPaidItems > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Cash on Pickup &mdash; recorded as paid</span>
                                        <span class="bp-detail-row__value is-profit"><?= bpc($copPaidCents) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($copPaidItems) ?> &middot; cash received at handover</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($gcashRejectedItems > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">GCash &mdash; needs correction</span>
                                        <span class="bp-detail-row__value muted"><?= bpc_items($gcashRejectedItems) ?></span>
                                        <span class="bp-detail-row__sub muted">Rejected submission awaiting customer re-submission</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($unrecordedItems > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label muted">Recorded online sales without a payment record</span>
                                        <span class="bp-detail-row__value muted"><?= bpc($unrecordedCents) ?></span>
                                        <span class="bp-detail-row__sub muted"><?= bpc_items($unrecordedItems) ?> &middot; no payments row exists</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($gcashAwaitingItems === 0 && $gcashItems === 0 && $gcashRejectedItems === 0 && $codPendingItems === 0 && $codItems === 0 && $copPendingItems === 0 && $copPaidItems === 0 && $unrecordedItems === 0 && $walkinItems === 0): ?>
                                    <p class="muted text-small">No payment collection records to show for this diagnostic population.</p>
                                    <?php endif; ?>
                                    <p class="bp-sub-note muted">
                                        Diagnostics cover receipt-confirmed recorded online sale items, including unresolved payments excluded from Eligible Completed Sales. Amounts use merchandise snapshots grouped by stored payment state; recorded as PAID does not by itself establish full financial eligibility.
                                    </p>
                                </div>
                            </details>

                            <details class="bp-sub">
                                <summary class="bp-sub__toggle">
                                    <span class="bp-sub__title">F. Retired Items and Data Quality</span>
                                    <span class="bp-sub__hint"><?= ($retiredCount + $unregisteredQty) > 0 ? ((int) $retiredCount . ' retired' . ($unregisteredQty > 0 ? ' &middot; ' . (int) $unregisteredQty . ' unregistered' : '')) : 'None' ?></span>
                                </summary>
                                <div class="bp-sub__body">
                                    <?php if ($retiredCount > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Retired items</span>
                                        <span class="bp-detail-row__value muted"><?= (int) $retiredCount ?></span>
                                        <span class="bp-detail-row__sub muted">No longer eligible for sale; excluded from Potential Sales and Potential Profit</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($unregisteredQty > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label">Unregistered items</span>
                                        <span class="bp-detail-row__value muted"><?= (int) $unregisteredQty ?></span>
                                        <span class="bp-detail-row__sub muted">Purchased but not yet registered; excluded from Potential Sales and Potential Profit</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($retiredCount === 0 && $unregisteredQty === 0): ?>
                                    <p class="muted text-small">No retired or unregistered items for this bundle.</p>
                                    <?php endif; ?>
                                    <?php if ($activeOrphanedCount > 0): ?>
                                    <div class="bp-detail-row">
                                        <span class="bp-detail-row__label muted">Marked sold without an active order or recorded sale</span>
                                        <span class="bp-detail-row__value muted"><?= (int) $activeOrphanedCount ?></span>
                                        <span class="bp-detail-row__sub muted">Counted in registered and online-held inventory; no reliable order amount, completed finances or available projection</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </details>

                        </div>
                    </details>

                </article>
            <?php endforeach; ?>
        </div>

        <!-- ========================================================
             DATA QUALITY NOTES
             ======================================================== -->
        <section class="bp-notes" aria-labelledby="bp-notes-title">
            <details>
                <summary id="bp-notes-title" class="bp-notes__toggle">
                    <svg class="bp-notes__toggle-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>
                    Data quality notes and limitations
                </summary>
                <div class="bp-notes__content">
                    <ul>
                        <li>
                            <strong>Recorded Sold Items</strong> counts physical products with
                            recorded sale-item history, whether financially eligible or not.
                            These garments remain excluded from available inventory and projections.
                            An item merely marked <code>SOLD</code> without sale-item history is
                            an availability hold, not proof of a completed sale.
                        </li>
                        <li>
                            <strong>Eligible Completed Sales</strong>, revenue, COGS and
                            Profit So Far use the same canonical eligibility as Sales Monitoring.
                            Both channels require sale-item/header integrity; online sales also
                            require the canonical payment, fulfillment, receipt/handover and
                            cancellation checks. Sale-item history or receipt alone is insufficient.
                            Bundle figures are all-time, not today's or the Dashboard's seven-day totals.
                        </li>
                        <li>
                            <strong>Items in Active Orders</strong> are held by live online
                            orders (Local Courier or Store Pickup). They are kept visible
                            under <em>Sales in Active Orders</em> but are not completed
                            sales and are excluded from <em>Potential Sales</em> and
                            <em>Potential Profit</em>, which cover garments available for a
                            new sale. Cancelled orders never contribute. These amounts may
                            change if an order is cancelled or becomes inactive.
                        </li>
                        <li>
                            <strong>Item cost</strong> = Total Puhunan &divide; original
                            quantity, fixed at bundle creation. <em>COGS</em>
                            allocates that fixed item cost to each garment in eligible completed
                            sales; it is never recalculated against remaining inventory.
                        </li>
                        <li>
                            <strong>Potential Sales / Total If Remaining Items Sell</strong>
                            are forward projections using current selling prices of available
                            garments. They represent what <em>would</em> be earned if all
                            currently available items sell at today&rsquo;s prices, and are not
                            completed revenue.
                        </li>
                        <li>
                            <strong>Profit So Far / Potential Profit</strong> compare selling
                            prices with item cost only. They are not net business profit
                            because rent, utilities, and other operating expenses are not
                            included.
                        </li>
                        <li>
                            <strong>Retired and unregistered items</strong> are not eligible
                            for sale and are excluded from every projection. Unregistered
                            items (purchased but not yet registered) are counted separately
                            in each bundle card.
                        </li>
                        <li>
                            <strong>Payment collection diagnostics include unresolved
                            recorded sales.</strong> GCash counts as recorded paid only where the
                            payment record says <code>PAID</code>; a submitted reference or
                            proof is still awaiting verification. Cash on Delivery and Cash
                            on Pickup stay pending until their collection workflow records
                            them as paid. A recorded, delivered, or picked-up order never
                            implies the payment was collected, and recorded online sales
                            without a payment record are listed as such.
                        </li>
                        <li>
                            <strong>Walk-in sales</strong> are always cash at the point of
                            sale; no payment record exists for them by design.
                        </li>
                        <li>
                            <strong>Shipping fees</strong> are excluded from all merchandise
                            sales, item cost, and profit figures on this page.
                        </li>
                    </ul>
                </div>
            </details>
        </section>

    <?php endif; ?>
</div>

<style>
/* ============================================================
   BUNDLES & PROFITABILITY PAGE — Admin card-based redesign
   ============================================================ */

/* Page container */
.bp {
    display: flex;
    flex-direction: column;
    gap: clamp(0.875rem, 2vw, 1.25rem);
}

/* Page header */
.bp__header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.bp__eyebrow {
    color: var(--admin-accent-strong);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    margin: 0;
}

.bp__title {
    margin-top: 0.2rem;
    font-size: clamp(1.5rem, 3vw, 2rem);
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--admin-ink);
    line-height: 1.1;
}

.bp__lead {
    margin-top: 0.4rem;
    max-width: 64ch;
    color: var(--color-text-soft);
    font-size: 0.9375rem;
}

.bp__add-btn {
    flex-shrink: 0;
    align-self: flex-start;
}

/* Override admin green for this page's primary action */
.bp__add-btn {
    background: var(--admin-accent);
    border-color: var(--admin-accent);
    color: var(--admin-on-action, #fff);
}
.bp__add-btn:hover {
    background: var(--admin-action-hover, var(--admin-accent-strong));
    border-color: var(--admin-action-hover, var(--admin-accent-strong));
    color: var(--admin-on-action, #fff);
}

/* Filter toolbar */
.bp-toolbar .card-body {
    padding: 1.25rem 1.5rem;
}

.bp-toolbar__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.bp-toolbar__eyebrow {
    color: var(--color-text-muted);
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    margin: 0;
}

.bp-toolbar__title {
    margin-top: 0.2rem;
    font-size: 1rem;
    font-weight: 700;
    color: var(--admin-ink);
}

.bp-toolbar__clear {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.6rem;
    border-radius: 6px;
    color: var(--color-text-muted);
    font-size: 0.8125rem;
    font-weight: 500;
    text-decoration: none;
    transition: color var(--transition), background-color var(--transition);
    white-space: nowrap;
}
.bp-toolbar__clear:hover {
    color: var(--admin-ink);
    background: var(--color-surface-muted);
}

.bp-filter__row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: flex-end;
}

.bp-filter__field {
    flex: 1 1 200px;
    min-width: 160px;
}

.bp-filter__label {
    display: block;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    margin-bottom: 0.3rem;
}

.bp-filter__search-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.bp-filter__search-icon {
    position: absolute;
    left: 0.625rem;
    color: var(--color-text-muted);
    pointer-events: none;
    flex-shrink: 0;
}

.bp-filter__search-wrap .bp-filter__input {
    padding-left: 2.25rem;
}

.bp-filter__input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    min-height: 38px;
    background: var(--admin-input, var(--color-surface));
    border: 1px solid var(--color-border-strong);
    border-radius: 8px;
    font-family: inherit;
    font-size: 0.875rem;
    color: var(--admin-ink);
    transition: border-color var(--transition);
}
.bp-filter__input:focus {
    outline: none;
    border-color: var(--admin-success, var(--admin-accent));
    box-shadow: 0 0 0 3px var(--admin-success-bg, rgba(22, 163, 74, 0.15));
}

.bp-filter__submit {
    flex: 0 0 auto;
}
.bp-filter__btn {
    min-height: 38px;
    padding: 0.5rem 1rem;
    font-size: 0.8125rem;
}

/* Result count */
.bp-results {
    display: flex;
    align-items: center;
    gap: 1rem;
}
.bp-results__count {
    font-size: 0.8125rem;
    color: var(--color-text-muted);
}

/* Empty state */
.bp-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1rem;
    padding: 3.5rem 1rem;
    background: var(--color-surface);
    border: 1px dashed var(--color-border-strong);
    border-radius: 16px;
    text-align: center;
    color: var(--color-text-muted);
}
.bp-empty p {
    font-size: 0.9375rem;
}

/* Legacy section */
.bp-legacy .card-body {
    padding: 1.25rem 1.5rem;
}

.bp-legacy__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.5rem;
}

.bp-legacy__eyebrow {
    color: var(--color-text-muted);
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    margin: 0;
}

.bp-legacy__title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--admin-ink);
    margin-top: 0.15rem;
}

.bp-legacy__note {
    font-size: 0.8125rem;
    color: var(--color-text-muted);
    margin-bottom: 1rem;
}

.bp-legacy__tiles {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.bp-legacy__action {
    display: flex;
    gap: 0.5rem;
}

/* Inventory tiles — shared compact tile used in both legacy and bundle cards */
.bp-inv-tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.1rem;
    min-width: 72px;
    padding: 0.5rem 0.75rem;
    background: var(--color-surface-muted);
    border: 1px solid var(--color-border);
    border-radius: 10px;
    text-align: center;
}

.bp-inv-tile--available {
    background: var(--admin-accent-soft);
    border-color: var(--admin-success-border, #bbf7d0);
}
.bp-inv-tile--available .bp-inv-tile__label { color: var(--admin-accent-strong); }
.bp-inv-tile--available .bp-inv-tile__value { color: var(--admin-accent-strong); }

.bp-inv-tile--held {
    background: var(--admin-warning-bg, #fef9c3);
    border-color: var(--admin-warning-border, #fde68a);
}
.bp-inv-tile--held .bp-inv-tile__label { color: var(--admin-warning, #92400e); }
.bp-inv-tile--held .bp-inv-tile__value { color: var(--admin-warning, #92400e); }

.bp-inv-tile--sold {
    background: var(--color-surface-muted);
    border-color: var(--color-border-strong);
}
.bp-inv-tile--sold .bp-inv-tile__label { color: var(--color-text-muted); }
.bp-inv-tile--sold .bp-inv-tile__value { color: var(--color-text-soft); }

.bp-inv-tile--retired {
    background: var(--color-surface-muted);
    border-color: var(--color-border);
    opacity: 0.7;
}
.bp-inv-tile--retired .bp-inv-tile__label { color: var(--color-text-muted); }
.bp-inv-tile--retired .bp-inv-tile__value { color: var(--color-text-muted); }

.bp-inv-tile__label {
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-muted);
}

.bp-inv-tile__value {
    font-size: 1.25rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--admin-ink);
    line-height: 1.1;
}

.bp-inv-tile__sub {
    font-size: 0.6rem;
    color: var(--color-text-muted);
    line-height: 1.3;
}

/* Bundle cards grid */
.bp-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}

@media (min-width: 640px) {
    .bp-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Bundle card */
.bp-card {
    background: var(--admin-surface, #ffffff);
    border: 1px solid var(--color-border);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
}

/* Card header */
.bp-card__head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.1rem 1.25rem 0.875rem;
    border-bottom: 1px solid var(--color-border);
}

.bp-card__identity {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.bp-card__code {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--admin-ink);
    letter-spacing: 0.01em;
    margin: 0;
}

.bp-card__meta {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.bp-card__date {
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.bp-card__capital {
    text-align: right;
    flex-shrink: 0;
}

.bp-card__capital-label {
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    margin: 0;
}

.bp-card__capital-value {
    font-size: 1.125rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--admin-ink);
    margin: 0.1rem 0 0;
    line-height: 1.1;
}

.bp-card__unit-cost {
    font-size: 0.7rem;
    color: var(--color-text-muted);
    margin: 0.15rem 0 0;
    white-space: nowrap;
}

/* Registration progress */
.bp-card__reg {
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
}

.bp-card__reg-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.bp-card__reg-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    margin: 0;
}

.bp-card__reg-count {
    font-size: 0.875rem;
    font-weight: 800;
    color: var(--admin-ink);
    letter-spacing: 0;
    text-transform: none;
    margin-left: 0.3rem;
}

.bp-card__unreg-badge {
    font-size: 0.68rem;
    font-weight: 600;
    padding: 0.2rem 0.5rem;
    border-radius: 20px;
    background: var(--admin-warning-bg, #fef3c7);
    border: 1px solid var(--admin-warning-border, #fde68a);
    color: var(--admin-warning, #92400e);
}

/* Progress bar */
.bp-progress {
    height: 6px;
    background: var(--color-border);
    border-radius: 999px;
    overflow: hidden;
}

.bp-progress__bar {
    height: 100%;
    background: var(--admin-success, var(--admin-accent));
    border-radius: 999px;
    transition: width 0.4s ease;
    min-width: 3px;
}

/* Inventory status tiles */
.bp-card__inv {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
}

/* Completed financial results */
.bp-card__results {
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
}

.bp-card__results-empty {
    margin: 0;
    font-size: 0.8125rem;
}

.bp-results-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}

.bp-result-item {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.bp-result-item__label {
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    margin: 0;
}

.bp-result-item__value {
    font-size: 1.0625rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--admin-ink);
    line-height: 1.1;
}

.bp-result-item__value.is-profit { color: var(--admin-accent-strong); }
.bp-result-item__value.is-loss   { color: var(--color-danger); }

.bp-result-item__sub {
    font-size: 0.68rem;
    color: var(--color-text-muted);
    margin: 0;
}

/* Remaining projection */
.bp-card__projection {
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
    background: var(--admin-surface-alt, #f8fffe);
}

.bp-card__proj-empty {
    margin: 0;
    font-size: 0.8125rem;
}

.bp-proj-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}

.bp-proj-item {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.bp-proj-item__label {
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    margin: 0;
}

.bp-proj-item__value {
    font-size: 1.0625rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    line-height: 1.1;
}

.bp-proj-item__value.is-projection { color: var(--admin-info, #1d4ed8); }
.bp-proj-item__value.is-profit    { color: var(--admin-accent-strong); }
.bp-proj-item__value.is-loss      { color: var(--color-danger); }

.bp-proj-item__sub {
    font-size: 0.68rem;
    color: var(--color-text-muted);
    margin: 0;
}

/* Card actions */
.bp-card__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
}

.bp-card__full-badge {
    font-size: 0.75rem;
}

/* Expandable financial details */
.bp-card__details {
    border-top: 0;
}

.bp-card__details-toggle {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    width: 100%;
    padding: 0.75rem 1.25rem;
    border: 0;
    background: var(--color-surface-muted);
    color: var(--color-text-soft);
    font-family: inherit;
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    cursor: pointer;
    text-align: left;
    transition: color var(--transition), background-color var(--transition);
}
.bp-card__details-toggle:hover {
    color: var(--admin-ink);
    background: var(--color-border);
}

.bp-card__details-toggle:focus-visible,
.bp-sub__toggle:focus-visible,
.bp-notes__toggle:focus-visible {
    outline: 2px solid var(--admin-success, var(--admin-accent));
    outline-offset: -2px;
}

.bp-card__details-icon {
    flex-shrink: 0;
    transition: transform var(--transition);
}

details[open] .bp-card__details-icon {
    transform: rotate(180deg);
}

.bp-card__details-body {
    padding: 0.75rem 1.25rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    border-top: 1px solid var(--color-border);
}

.bp-details-empty {
    margin: 0;
}

/* Profit explainer under the summary results */
.bp-profit-note {
    margin: 0.5rem 0 0;
    font-size: 0.68rem;
    line-height: 1.35;
}

/* Online-held visibility strip */
.bp-card__held {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.6rem 1.25rem;
    background: var(--admin-warning-bg, #fffbeb);
    border-bottom: 1px solid var(--color-border);
    font-size: 0.75rem;
}

.bp-card__held-label {
    color: var(--admin-warning, #92400e);
    font-weight: 600;
}

.bp-card__held-amount {
    color: var(--color-text-soft);
}

.bp-card__held-amount strong {
    font-weight: 700;
}

.bp-card__held-note {
    font-size: 0.68rem;
    line-height: 1.3;
}

/* Combined projection total */
.bp-card__total {
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
    background: var(--admin-surface-alt, #f8fffe);
}

.bp-total-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}

.bp-total-item {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.bp-total-item__label {
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--color-text-muted);
    margin: 0;
}

.bp-total-item__value {
    font-size: 1.0625rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    line-height: 1.1;
}

.bp-total-item__value.is-projection { color: var(--admin-info, #1d4ed8); }
.bp-total-item__value.is-profit    { color: var(--admin-accent-strong); }
.bp-total-item__value.is-loss      { color: var(--color-danger); }

.bp-total-note {
    margin: 0.4rem 0 0;
    font-size: 0.68rem;
    line-height: 1.35;
}

/* Independently expandable detail sections */
.bp-sub {
    border: 1px solid var(--color-border);
    border-radius: 10px;
    background: var(--color-surface);
    overflow: hidden;
}

.bp-sub__toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    width: 100%;
    padding: 0.55rem 0.75rem;
    cursor: pointer;
    font-family: inherit;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-soft);
    text-align: left;
    background: var(--color-surface-muted);
    transition: color var(--transition), background-color var(--transition);
    list-style: none;
}

.bp-sub__toggle:hover {
    color: var(--admin-ink);
    background: var(--color-border);
}

.bp-sub__toggle::-webkit-details-marker { display: none; }

.bp-sub__toggle::after {
    content: '\25BE';
    flex-shrink: 0;
    color: var(--color-text-muted);
    font-size: 0.7rem;
    transition: transform var(--transition);
}

.bp-sub[open] > .bp-sub__toggle::after {
    transform: rotate(180deg);
}

.bp-sub__title {
    display: flex;
    align-items: center;
    min-width: 0;
}

.bp-sub__hint {
    flex-shrink: 0;
    font-size: 0.68rem;
    font-weight: 500;
    color: var(--color-text-muted);
    white-space: nowrap;
}

.bp-sub__body {
    padding: 0.6rem 0.75rem 0.75rem;
    border-top: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.bp-sub__body .bp-detail-row {
    padding: 0.3rem 0;
}

.bp-detail-row--item .bp-detail-row__label {
    font-weight: 600;
}

.bp-sub-note {
    margin: 0.35rem 0 0;
    font-size: 0.7rem;
    line-height: 1.45;
    color: var(--color-text-muted);
}

/* Detail row */
.bp-detail-row {
    display: grid;
    grid-template-columns: 1fr auto;
    grid-template-rows: auto auto;
    column-gap: 0.5rem;
    row-gap: 0.1rem;
    padding: 0.4rem 0;
    align-items: baseline;
}

.bp-detail-row--sub {
    padding-left: 0.75rem;
    opacity: 0.85;
}

.bp-detail-row--divider {
    border-top: 1px solid var(--color-border);
    padding-top: 0.625rem;
    margin-top: 0.25rem;
}

.bp-detail-row__label {
    font-size: 0.8rem;
    color: var(--color-text-soft);
    font-weight: 500;
    grid-column: 1;
    grid-row: 1;
    display: flex;
    align-items: center;
    gap: 0.3rem;
    flex-wrap: wrap;
}

.bp-detail-row__value {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--admin-ink);
    grid-column: 2;
    grid-row: 1;
    text-align: right;
    white-space: nowrap;
}

.bp-detail-row__value.is-profit     { color: var(--admin-accent-strong); }
.bp-detail-row__value.is-loss      { color: var(--color-danger); }
.bp-detail-row__value.is-projection { color: var(--admin-info, #1d4ed8); }

.bp-detail-row__sub {
    font-size: 0.7rem;
    color: var(--color-text-muted);
    grid-column: 1 / -1;
    grid-row: 2;
}

/* Color utilities for detail rows */
.is-profit { color: var(--admin-accent-strong); }
.is-loss    { color: var(--color-danger); }
.is-projection { color: var(--admin-info, #1d4ed8); }

/* Data quality notes */
.bp-notes {
    margin-top: 0.5rem;
}

.bp-notes__toggle {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    cursor: pointer;
    font-size: 0.8125rem;
    color: var(--color-text-muted);
    font-weight: 500;
    user-select: none;
    list-style: none;
    padding: 0.5rem 0;
    border-radius: 6px;
    transition: color var(--transition);
}
.bp-notes__toggle:hover { color: var(--admin-ink); }

.bp-notes__toggle-icon {
    flex-shrink: 0;
    transition: transform var(--transition);
}
details[open] .bp-notes__toggle-icon {
    transform: rotate(180deg);
}
details summary::-webkit-details-marker { display: none; }

.bp-notes__content {
    margin-top: 0.75rem;
    padding: 1rem 1.25rem;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 12px;
}

.bp-notes__content ul {
    margin: 0;
    padding-left: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.bp-notes__content li {
    font-size: 0.8125rem;
    color: var(--color-text-soft);
    line-height: 1.6;
}

.bp-notes__content li strong {
    color: var(--admin-ink);
    font-weight: 600;
}

.bp-notes__content code {
    font-size: 0.8rem;
    background: var(--color-surface-muted);
    padding: 0.1em 0.35em;
    border-radius: 4px;
    border: 1px solid var(--color-border);
}

/* Responsive adjustments */
@media (max-width: 639px) {
    .bp-card__head {
        flex-direction: column;
    }
    .bp-card__capital {
        text-align: left;
    }
    .bp-results-grid,
    .bp-proj-grid,
    .bp-total-grid {
        grid-template-columns: 1fr;
    }
    .bp-card__held {
        flex-wrap: wrap;
    }
    .bp-filter__row {
        flex-direction: column;
    }
    .bp-filter__field {
        min-width: 0;
    }
    .bp-filter__submit {
        width: 100%;
    }
    .bp-filter__btn {
        width: 100%;
    }
}
</style>

<?php require __DIR__ . '/../includes/ui.footer.php';
