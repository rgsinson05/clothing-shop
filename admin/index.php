<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';

/*
 * ============================================================
 * Hopia Fits admin dashboard (Stage 1 redesign).
 * ============================================================
 *
 * Every figure on this page is derived from committed database state. The
 * dashboard NEVER infers completed revenue from products.status = 'SOLD'
 * (online checkout uses SOLD as a reversible availability hold); completed
 * sales come exclusively from committed sales_transactions /
 * sales_transaction_items rows that pass the shared Sales Monitoring
 * eligibility checks in includes/sales.php::sales_monitor_source_sql().
 *
 * Per-bundle financial rules (unit cost = capital / original_quantity, fixed
 * at purchase time; revenue from saved price snapshots) follow
 * includes/bundle_metrics.php. Bundle Overview keeps its existing metrics.
 */

$dashboardError = false;

/* KPI accumulators (money in integer centavos). */
$walkinTodayCents = 0;
$walkinTodayCount = 0;
$onlineTodayCents = 0;
$onlineTodayCount = 0;
$completedSalesCentsToday = 0;
$completedTxToday = 0;
$itemsSoldToday = 0;
$grossProfitCentsToday = 0;
$bundleItemsToday = 0;
$legacyItemsToday = 0;
$ordersInProgress = 0;

/* Needs Attention accumulators. */
$orderQueueCounts = [];
$legacyUnassigned = 0;
$lowStockBundles = [];

/* Bundle overview. */
$overview = [
    'bundle_count' => 0, 'capital_cents' => 0, 'original_quantity' => 0,
    'registered_count' => 0, 'unregistered_count' => 0, 'available_count' => 0,
    'online_held_count' => 0, 'completed_sold_count' => 0,
    'remaining_revenue_cents' => 0, 'remaining_items' => 0, 'legacy_unassigned' => 0,
];

/* Trend + recent activity. */
$trendDays = [];
$trendMaxCents = 0;
$trendTotalCents = 0;
$recentSales = [];

/* Include-only helper (no DB access), loaded before the try block so the
   Needs Attention group definitions remain available even when the database
   connection itself fails and the page renders its error state. */
require_once __DIR__ . '/../includes/order_queues.php';

try {
    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/sales.php';
    require_once __DIR__ . '/../includes/bundle_metrics.php';

    /* One Manila-midnight reference for today and the last 7 calendar days. */
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $todayStart    = $today->format('Y-m-d H:i:s');
    $tomorrowStart = $today->modify('+1 day')->format('Y-m-d H:i:s');
    $weekStart     = $today->modify('-6 days')->format('Y-m-d H:i:s');

    /* One eligible population for sales KPIs, trend, and recent activity. */
    $eligibleSalesSql = 'SELECT * FROM (' . sales_monitor_source_sql() . ') sales'
        . ' WHERE reporting_issue IS NULL';

    /* ---- KPI 1 & today's per-channel completed sales ---------------- */
    $todayStmt = $pdo->prepare(
        "SELECT sale_channel,
                COUNT(*) AS tx_count,
                COALESCE(SUM(total_amount), 0) AS tx_total
         FROM (" . $eligibleSalesSql . ") sales
         WHERE sold_at >= :start AND sold_at < :end
         GROUP BY sale_channel"
    );
    $todayStmt->execute([':start' => $todayStart, ':end' => $tomorrowStart]);
    foreach ($todayStmt->fetchAll() as $row) {
        $cents = sales_money_to_cents($row['tx_total']) ?? 0;
        $count = (int) $row['tx_count'];
        if ((string) $row['sale_channel'] === 'WALKIN') {
            $walkinTodayCents = $cents;
            $walkinTodayCount = $count;
        } elseif ((string) $row['sale_channel'] === 'ONLINE') {
            $onlineTodayCents = $cents;
            $onlineTodayCount = $count;
        }
    }
    $completedSalesCentsToday = $walkinTodayCents + $onlineTodayCents;
    $completedTxToday = $walkinTodayCount + $onlineTodayCount;

    /* ---- KPI 3: items sold today (actual completed sale-item records) */
    $itemsStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM sales_transaction_items sti
         INNER JOIN (" . $eligibleSalesSql . ") st ON st.id = sti.sale_id
         WHERE st.sold_at >= :start AND st.sold_at < :end"
    );
    $itemsStmt->execute([':start' => $todayStart, ':end' => $tomorrowStart]);
    $itemsSoldToday = (int) $itemsStmt->fetchColumn();

    /* ---- KPI 2: gross profit today -------------------------------------
       Completed revenue (price snapshots) minus allocated COGS at the fixed
       bundle unit cost. Only bundle-linked completed items have a cost basis;
       legacy items (no purchase_bundles row) are reported separately and
       never allocated a fabricated cost. Mirrors bundle_metrics_derive(). */
    $profitStmt = $pdo->prepare(
        "SELECT pb.capital_total, pb.original_quantity,
                SUM(sti.unit_price) AS revenue, COUNT(*) AS items
         FROM sales_transaction_items sti
         INNER JOIN (" . $eligibleSalesSql . ") st ON st.id = sti.sale_id
         INNER JOIN products pr ON pr.id = sti.product_id
         INNER JOIN purchase_bundles pb ON pb.id = pr.bundle_id
         WHERE st.sold_at >= :start AND st.sold_at < :end
         GROUP BY pb.id, pb.capital_total, pb.original_quantity"
    );
    $profitStmt->execute([':start' => $todayStart, ':end' => $tomorrowStart]);
    foreach ($profitStmt->fetchAll() as $row) {
        $capitalCents = sales_money_to_cents($row['capital_total']) ?? 0;
        $originalQty  = max(1, (int) $row['original_quantity']);
        $unitCost     = (int) round($capitalCents / $originalQty);
        $revenueCents = sales_money_to_cents($row['revenue']) ?? 0;
        $items        = (int) $row['items'];

        $grossProfitCentsToday += $revenueCents - ($unitCost * $items);
        $bundleItemsToday += $items;
    }
    /* Completed items today with no bundle cost basis (legacy / unlinked). */
    $legacyItemsToday = max(0, $itemsSoldToday - $bundleItemsToday);

    /* ---- KPI 4: orders in progress -------------------------------------
       Active online orders still moving through fulfillment: everything that
       is neither delivered nor cancelled. DELIVERED orders are effectively
       fulfilled (receipt confirmation is surfaced separately below). */
    $ordersInProgress = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders
         WHERE status IN ('PENDING','CONFIRMED','PACKED','SHIPPED')"
    )->fetchColumn();

    /* ---- Needs Attention counts ----------------------------------------
       Each count comes from the shared queue definition that also drives the
       filtered destination page, so a card and its result list cannot drift. */
    foreach (order_queue_definitions() as $queueKey => $queueDefinition) {
        $orderQueueCounts[$queueKey] = order_queue_count($pdo, $queueKey);
    }

    /* ---- Bundle overview (single source of truth) ---------------------- */
    $overview = bundle_metrics_overview($pdo);
    $legacyUnassigned = (int) $overview['legacy_unassigned'];

    /* ---- Low-stock bundles (only where inventory data supports it) ------
       A bundle qualifies only when every purchased item is registered
       (no unregistered quantity remains), it has recorded completed sales,
       and at most 2 registered items are still available. This is a direct
       inventory fact, never a fabricated or projected count. */
    foreach (bundle_metrics_rows($pdo) as $rawRow) {
        $m = bundle_metrics_derive($rawRow);
        if (bundle_metrics_is_low_stock($m)) {
            $lowStockBundles[] = [
                'code'      => 'HOPEBUNDLE-' . str_pad((string) $m['bundle_id'], 6, '0', STR_PAD_LEFT),
                'available' => (int) $m['available_count'],
                'category'  => (string) $m['category'],
            ];
        }
    }

    /* ---- 7-day completed-sales trend ----------------------------------- */
    $trendRaw = [];
    $trendStmt = $pdo->prepare(
        "SELECT DATE(sold_at) AS d,
                COALESCE(SUM(total_amount), 0) AS total,
                COUNT(*) AS cnt
         FROM (" . $eligibleSalesSql . ") sales
         WHERE sold_at >= :start AND sold_at < :end
         GROUP BY DATE(sold_at)"
    );
    $trendStmt->execute([':start' => $weekStart, ':end' => $tomorrowStart]);
    foreach ($trendStmt->fetchAll() as $row) {
        $trendRaw[(string) $row['d']] = [
            'cents' => sales_money_to_cents($row['total']) ?? 0,
            'count' => (int) $row['cnt'],
        ];
    }
    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime("-{$i} days");
        $key = date('Y-m-d', $ts);
        $cents = $trendRaw[$key]['cents'] ?? 0;
        $count = $trendRaw[$key]['count'] ?? 0;
        $trendDays[] = [
            'label' => date('D', $ts),
            'full'  => date('M j', $ts),
            'cents' => $cents,
            'count' => $count,
            'today' => ($i === 0),
        ];
        $trendMaxCents = max($trendMaxCents, $cents);
        $trendTotalCents += $cents;
    }

    /* ---- Recent completed sales (both channels) ------------------------ */
    $recentStmt = $pdo->query(
        "SELECT st.id, st.sale_channel, st.total_amount, st.sold_at, st.order_id,
                st.cashier, st.item_count
         FROM (" . $eligibleSalesSql . ") st
         ORDER BY st.sold_at DESC, st.id DESC
         LIMIT 8"
    );
    $recentSales = $recentStmt->fetchAll();
} catch (Throwable $e) {
    $dashboardError = true;
}

/* Local presentation helpers (no output side effects). */
function dash_peso_cents(int $cents): string
{
    return '&#8369;' . number_format($cents / 100, 2);
}

function dash_peso_decimal($value): string
{
    return '&#8369;' . number_format((float) $value, 2);
}

function dash_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    try {
        return (new DateTime($value))->format('M j, g:i A');
    } catch (Exception $e) {
        return $value;
    }
}

function dash_icon(string $name): string
{
    $paths = [
        'peso'    => '<path d="M7 4h5.5a4.5 4.5 0 0 1 0 9H7"/><path d="M7 4v16"/><path d="M4.5 9h9"/><path d="M4.5 13h9"/>',
        'profit'  => '<path d="M4 19V5"/><path d="M4 19h16"/><path d="m7.5 14 3.2-3.4 2.6 2L19 7"/><path d="M15 7h4v4"/>',
        'items'   => '<path d="M9 4 4.6 6.7a1 1 0 0 0-.4 1.2L5.5 11l2.5-.9V20h8v-9.9l2.5.9 1.3-3.1a1 1 0 0 0-.4-1.2L15 4a3 3 0 0 1-6 0z"/>',
        'orders'  => '<path d="M6 8h12l1 12.5H5L6 8Z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
        'legacy'  => '<rect x="3.5" y="4" width="17" height="4.5" rx="1"/><path d="M5 8.5h14V19a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19V8.5Z"/><path d="M10 12h4"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'cancel'  => '<circle cx="12" cy="12" r="9"/><path d="m9.5 9.5 5 5"/><path d="m14.5 9.5-5 5"/>',
        'truck'   => '<path d="M14 17V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11h3"/><path d="M14 9h4l3 3.5V17h-3"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'package' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4 7.5 8 4.5 8-4.5"/><path d="M12 12v9"/>',
        'pickup'  => '<path d="M4 10.5V20h16v-9.5"/><path d="M3 10.5 4.5 5h15L21 10.5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"/><path d="m9.5 16.5 1.8 1.8 3.4-3.6"/>',
        'bundles' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 16.5 9 5 9-5"/>',
        'alert'   => '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $paths[$name] . '</svg>';
}

/*
 * Build the Needs Attention groups. Order cards come from the shared queue
 * definitions (the exact predicate their destination page applies); the two
 * inventory cards are appended to Others. Empty cards are omitted, but the
 * three group headings stay visible whenever anything needs attention so the
 * Online / Pick-up / Others separation is always explicit.
 */
$attentionSections = [
    'online' => ['label' => 'Online Orders', 'items' => []],
    'pickup' => ['label' => 'Pick-up Orders', 'items' => []],
    'others' => ['label' => 'Others', 'items' => []],
];

foreach (order_queue_definitions() as $queueKey => $queueDefinition) {
    $queueCount = (int) ($orderQueueCounts[$queueKey] ?? 0);
    $queueSection = (string) ($queueDefinition['section'] ?? '');

    if ($queueCount <= 0 || !isset($attentionSections[$queueSection])) {
        continue;
    }

    $attentionSections[$queueSection]['items'][] = [
        'tone'  => (string) $queueDefinition['tone'],
        'icon'  => (string) $queueDefinition['icon'],
        'href'  => (string) $queueDefinition['href'],
        'title' => (string) $queueDefinition['title'],
        'count' => $queueCount,
        'desc'  => (string) $queueDefinition['desc'],
    ];
}

if ($legacyUnassigned > 0) {
    $attentionSections['others']['items'][] = [
        'tone'  => 'neutral',
        'icon'  => 'legacy',
        'href'  => 'legacy-assign.php',
        'title' => 'Unassigned Legacy Products',
        'count' => $legacyUnassigned,
        'desc'  => 'Existing products that have not yet been assigned to a purchase bundle.',
    ];
}

if (count($lowStockBundles) > 0) {
    $attentionSections['others']['items'][] = [
        'tone'  => 'warn',
        'icon'  => 'bundles',
        'href'  => 'bundles.php?low_stock=1',
        'title' => 'Low Stock Bundles',
        'count' => count($lowStockBundles),
        'desc'  => 'Purchase bundles with remaining available items at or below the configured low-stock threshold.',
    ];
}

$attentionCount = 0;
foreach ($attentionSections as $attentionSection) {
    $attentionCount += count($attentionSection['items']);
}

$page_title = "Dashboard - Admin - Hopia Fits";
$page_description = "Store overview for Hopia Fits administrators.";
$ui_section = 'admin';
$ui_active = 'index.php';
$body_class = 'admin-dashboard-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<div class="dash">
    <header class="dash__header">
        <div class="dash__intro">
            <p class="dash__eyebrow">Admin overview</p>
            <h1 class="dash__title">Dashboard</h1>
            <p class="dash__lead">
                Welcome back, <?= hopia_e($adminName) ?>. Here is today's store overview for <?= hopia_e(date('F j, Y')) ?>.
            </p>
        </div>
        <div class="dash__actions">
            <a class="btn btn-primary dash__action" href="scan.php">Record Walk-in Sale</a>
            <a class="btn btn-ghost dash__action" href="product-create.php">Add Product</a>
        </div>
    </header>

    <?php if ($dashboardError): ?>

        <div class="alert alert-error" role="alert">
            The dashboard could not be loaded. If this is a fresh setup, confirm that the
            bundle, sales, fulfillment, payment, and pickup migrations (005&ndash;012) have been applied to this database.
        </div>

    <?php else: ?>

        <!-- PRIMARY KPI CARDS -->
        <section class="dash-kpis" aria-label="Today at a glance">
            <article class="dash-kpi dash-kpi--sales">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Completed Sales Today</span>
                    <span class="dash-kpi__icon"><?= dash_icon('peso') ?></span>
                </div>
                <p class="dash-kpi__value"><?= dash_peso_cents($completedSalesCentsToday) ?></p>
                <p class="dash-kpi__sub">
                    <?= (int) $completedTxToday ?> completed transaction<?= $completedTxToday === 1 ? '' : 's' ?>
                    &middot; Walk-in <?= dash_peso_cents($walkinTodayCents) ?>
                    &middot; Online <?= dash_peso_cents($onlineTodayCents) ?>
                </p>
                <span class="dash-kpi__bar"></span>
            </article>

            <article class="dash-kpi dash-kpi--profit">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Gross Profit Today</span>
                    <span class="dash-kpi__icon"><?= dash_icon('profit') ?></span>
                </div>
                <p class="dash-kpi__value <?= $grossProfitCentsToday < 0 ? 'is-loss' : '' ?>">
                    <?= dash_peso_cents($grossProfitCentsToday) ?>
                </p>
                <p class="dash-kpi__sub">
                    Completed revenue minus allocated bundle cost.
                    <?php if ($legacyItemsToday > 0): ?>
                        <span class="dash-kpi__note">Excludes <?= (int) $legacyItemsToday ?> legacy item<?= $legacyItemsToday === 1 ? '' : 's' ?> with no cost basis.</span>
                    <?php endif; ?>
                </p>
                <span class="dash-kpi__bar"></span>
            </article>

            <article class="dash-kpi dash-kpi--items">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Items Sold Today</span>
                    <span class="dash-kpi__icon"><?= dash_icon('items') ?></span>
                </div>
                <p class="dash-kpi__value"><?= (int) $itemsSoldToday ?> <span class="dash-kpi__unit">pcs</span></p>
                <p class="dash-kpi__sub">Actual completed sale-item records today.</p>
                <span class="dash-kpi__bar"></span>
            </article>

            <article class="dash-kpi dash-kpi--orders">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Orders in Progress</span>
                    <span class="dash-kpi__icon"><?= dash_icon('orders') ?></span>
                </div>
                <p class="dash-kpi__value"><?= (int) $ordersInProgress ?></p>
                <p class="dash-kpi__sub">Active online orders (not yet delivered or cancelled).</p>
                <span class="dash-kpi__bar"></span>
            </article>
        </section>

        <!-- NEEDS ATTENTION (conditional) -->
        <?php if ($attentionCount > 0): ?>
        <section class="dash-section" aria-labelledby="dash-attention-title">
            <div class="dash-section__head">
                <h2 id="dash-attention-title" class="dash-section__title">Needs Attention</h2>
            </div>

            <?php foreach ($attentionSections as $attentionSection): ?>
                <div class="dash-attention-group">
                    <div class="dash-attention-group__head">
                        <h3 class="dash-attention-group__title"><?= hopia_e($attentionSection['label']) ?></h3>
                        <span class="dash-attention-group__rule" aria-hidden="true"></span>
                    </div>

                    <?php if (count($attentionSection['items']) > 0): ?>
                        <div class="dash-attention">
                            <?php foreach ($attentionSection['items'] as $item): ?>
                                <a class="dash-attn dash-attn--<?= hopia_e($item['tone']) ?>" href="<?= hopia_e($item['href']) ?>">
                                    <span class="dash-attn__icon"><?= dash_icon($item['icon']) ?></span>
                                    <span class="dash-attn__body">
                                        <span class="dash-attn__title">
                                            <?= hopia_e($item['title']) ?>
                                            <span class="dash-attn__count"><?= (int) $item['count'] ?></span>
                                        </span>
                                        <span class="dash-attn__desc"><?= hopia_e($item['desc']) ?></span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="dash-attention-group__empty">Nothing needs attention here right now.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <!-- TREND + BUNDLE OVERVIEW -->
        <section class="dash-grid dash-grid--wide">
            <!-- 7-day completed-sales trend -->
            <div class="dash-card dash-card--trend">
                <div class="dash-card__head">
                    <div>
                        <h2 class="dash-card__title">7-Day Sales Trend</h2>
                        <p class="dash-card__subtitle">Completed sales revenue, last 7 days</p>
                    </div>
                    <a class="dash-card__link" href="sales.php">Sales Monitoring</a>
                </div>

                <?php if ($trendTotalCents <= 0): ?>
                    <div class="dash-empty">
                        <p>No completed sales in the last 7 days yet.</p>
                        <p class="dash-empty__hint">The trend appears here once sales are recorded.</p>
                    </div>
                <?php else: ?>
                    <div class="dash-chart" role="img" aria-label="Completed sales revenue for the last 7 days">
                        <?php foreach ($trendDays as $day): ?>
                            <?php
                            $pct = $trendMaxCents > 0 ? ($day['cents'] / $trendMaxCents) * 100 : 0;
                            if ($day['cents'] > 0 && $pct < 4) {
                                $pct = 4; // keep tiny non-zero days visible
                            }
                            ?>
                            <div class="dash-chart__col<?= $day['today'] ? ' is-today' : '' ?>"
                                 title="<?= hopia_e($day['full'] . ': ' . number_format($day['cents'] / 100, 2) . ' PHP (' . $day['count'] . ' sale' . ($day['count'] === 1 ? '' : 's') . ')') ?>">
                                <span class="dash-chart__amount"><?= $day['cents'] > 0 ? dash_peso_cents($day['cents']) : '&mdash;' ?></span>
                                <span class="dash-chart__bar-track">
                                    <span class="dash-chart__bar" style="height: <?= number_format($pct, 2) ?>%"></span>
                                </span>
                                <span class="dash-chart__label"><?= hopia_e($day['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="dash-card__foot">
                        7-day total <strong><?= dash_peso_cents($trendTotalCents) ?></strong>
                        &middot; Today <strong><?= dash_peso_cents($completedSalesCentsToday) ?></strong>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Bundle overview -->
            <div class="dash-card dash-card--bundles">
                <div class="dash-card__head">
                    <div>
                        <h2 class="dash-card__title">Bundle Overview</h2>
                        <p class="dash-card__subtitle">Capital and inventory at a glance</p>
                    </div>
                    <a class="dash-card__link" href="bundles.php">View All Bundles</a>
                </div>

                <?php if ((int) $overview['bundle_count'] === 0 && $legacyUnassigned === 0): ?>
                    <div class="dash-empty">
                        <p>No bundles created yet.</p>
                        <p class="dash-empty__hint"><a href="bundle-create.php">Create your first bundle</a> to start tracking capital and profitability.</p>
                    </div>
                <?php else: ?>
                    <dl class="dash-stats">
                        <div class="dash-stats__row">
                            <dt>Bundles</dt>
                            <dd><?= (int) $overview['bundle_count'] ?></dd>
                        </div>
                        <div class="dash-stats__row">
                            <dt>Total capital</dt>
                            <dd><?= dash_peso_cents((int) $overview['capital_cents']) ?></dd>
                        </div>
                        <div class="dash-stats__row">
                            <dt>Registered items</dt>
                            <dd><?= (int) $overview['registered_count'] ?> <span class="muted">/ <?= (int) $overview['original_quantity'] ?></span></dd>
                        </div>
                        <div class="dash-stats__row">
                            <dt>Unregistered items</dt>
                            <dd><?= (int) $overview['unregistered_count'] ?></dd>
                        </div>
                        <div class="dash-stats__row">
                            <dt>Available units</dt>
                            <dd><?= (int) $overview['available_count'] ?></dd>
                        </div>
                        <div class="dash-stats__row">
                            <dt>Online-held units</dt>
                            <dd><?= (int) $overview['online_held_count'] ?></dd>
                        </div>
                        <div class="dash-stats__row">
                            <dt>Completed units sold</dt>
                            <dd><?= (int) $overview['completed_sold_count'] ?></dd>
                        </div>
                        <div class="dash-stats__row">
                            <dt>Legacy unassigned</dt>
                            <dd><?= (int) $legacyUnassigned ?></dd>
                        </div>
                        <div class="dash-stats__row dash-stats__row--projection">
                            <dt>Expected remaining <span class="dash-tag">Projection</span></dt>
                            <dd><?= dash_peso_cents((int) $overview['remaining_revenue_cents']) ?> <span class="muted">(<?= (int) $overview['remaining_items'] ?> items)</span></dd>
                        </div>
                    </dl>
                <?php endif; ?>
            </div>
        </section>

        <!-- RECENT COMPLETED SALES -->
        <section class="dash-card dash-card--recent" aria-labelledby="dash-recent-title">
            <div class="dash-card__head">
                <div>
                    <h2 class="dash-card__title" id="dash-recent-title">Recent Completed Sales</h2>
                    <p class="dash-card__subtitle">Latest committed transactions, both channels</p>
                </div>
                <a class="dash-card__link" href="sales.php">View all</a>
            </div>

            <?php if (count($recentSales) === 0): ?>
                <div class="dash-empty">
                    <p>No completed sales recorded yet.</p>
                    <p class="dash-empty__hint"><a href="scan.php">Record a walk-in sale</a> to see activity here.</p>
                </div>
            <?php else: ?>
                <ul class="dash-feed">
                    <?php foreach ($recentSales as $sale): ?>
                        <?php
                        $saleId = (int) $sale['id'];
                        $isOnline = (string) $sale['sale_channel'] === 'ONLINE';
                        $itemCount = (int) $sale['item_count'];
                        ?>
                        <li class="dash-feed__row">
                            <a class="dash-feed__link" href="sales.php?id=<?= $saleId ?>">
                                <span class="dash-feed__time"><?= hopia_e(dash_datetime($sale['sold_at'])) ?></span>
                                <span class="dash-feed__main">
                                    <span class="dash-feed__code">SALE-<?= str_pad((string) $saleId, 6, '0', STR_PAD_LEFT) ?></span>
                                    <span class="dash-feed__meta">
                                        <?php if ($isOnline && $sale['order_id'] !== null): ?>
                                            Online &middot; Order #<?= (int) $sale['order_id'] ?>
                                        <?php else: ?>
                                            Walk-in<?php if (!empty($sale['cashier'])): ?> &middot; <?= hopia_e($sale['cashier']) ?><?php endif; ?>
                                        <?php endif; ?>
                                        &middot; <?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?>
                                    </span>
                                </span>
                                <span class="dash-feed__right">
                                    <span class="dash-feed__total"><?= dash_peso_decimal($sale['total_amount']) ?></span>
                                    <span class="dash-chip dash-chip--<?= $isOnline ? 'online' : 'walkin' ?>"><?= $isOnline ? 'ONLINE' : 'WALK-IN' ?></span>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
