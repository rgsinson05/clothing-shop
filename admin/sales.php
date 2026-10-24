<?php

/*
 * Hopia Fits - Sales Monitoring (Phase 11).
 *
 * Authenticated admin page. Lists ALL COMPLETED sale transactions (one row
 * per transaction, not per garment) and opens a print-friendly receipt
 * detail for a single sale (?id=).
 *
 * Reports canonical WALKIN and ONLINE sales, separating online fulfillment
 * into Local Courier and Store Pickup without changing stored channels.
 * Receipt, delivery or payment alone never substitutes for a finalized sale.
 * Conflicting history is shown for manual review, never rewritten here.
 *
 * The list supports combined search (SALE- reference, online order number,
 * cashier, or customer name), a channel filter (All / Walk-in / Local Courier
 * / Store Pickup),
 * and the original inclusive date-range filter. Search terms and channel
 * values only ever enter the query as bound parameters, and LIKE
 * wildcards in the typed term are escaped so they match literally.
 *
 * The ten Today cards show global transaction counts and merchandise revenue
 * for Walk-in and each online fulfillment/payment combination, not profit.
 * Payment metadata is displayed as stored; shipping is not sales revenue.
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/shipping.php';
require_once __DIR__ . '/../includes/payment.php';

$adminName = $_SESSION['admin_name'] ?? 'Admin';

/* Human-friendly, zero-padded sale code derived from the permanent id. */
function sale_code(int $id): string
{
    return 'SALE-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function format_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('M j, Y g:i A');
    } catch (Exception $e) {
        return $value;
    }
}

/* Restrained, readable labels for the stored payment enum values. */
function payment_method_label(?string $method, string $channel): string
{
    if ($method === null) {
        /* Walk-in sales are tendered at the counter; no payments row
           is created for them by design. */
        return $channel === 'WALKIN' ? 'Cash' : 'Not recorded';
    }
    $labels = [
        'COD' => 'Cash on Delivery',
        'GCASH' => 'GCash',
        'CARD' => 'Card',
        'CASH_ON_PICKUP' => 'Cash on Pickup',
    ];
    return $labels[$method] ?? $method;
}

function payment_status_label(?string $status, array $payment = []): string
{
    if ($status === null) {
        return '';
    }
    if (($payment['payment_method'] ?? null) === 'GCASH' && $status !== 'REFUNDED') {
        if ($status === 'PAID' && (($payment['verified_at'] ?? null) === null || ($payment['verified_by'] ?? null) === null)) {
            return 'Paid; verification not recorded';
        }
        return payment_gcash_state_label(payment_gcash_state($payment));
    }
    if ($status === 'PAID' && in_array($payment['payment_method'] ?? null, ['COD', 'CASH_ON_PICKUP'], true)) {
        return 'Cash collected';
    }
    $labels = [
        'PENDING' => 'Pending',
        'PAID' => 'Paid',
        'FAILED' => 'Failed',
        'REFUNDED' => 'Refunded',
    ];
    return $labels[$status] ?? $status;
}

/* Map a payment status to the existing restrained badge classes. */
function payment_status_badge(?string $status): string
{
    switch ($status) {
        case 'PAID':
            return 'badge badge-success';
        case 'PENDING':
            return 'badge badge-pending';
        case 'FAILED':
        case 'REFUNDED':
            return 'badge badge-danger';
        default:
            return 'badge';
    }
}

function channel_badge(string $channel): string
{
    return $channel === 'WALKIN' ? 'dash-chip dash-chip--walkin' : 'dash-chip dash-chip--online';
}

function channel_label(string $channel): string
{
    return $channel === 'WALKIN' ? 'Walk-in' : (shipping_fulfillment_methods()[$channel] ?? 'Not recorded');
}

function sales_monitor_icon(string $name): string
{
    $paths = [
        'store' => '<path d="M4 10.5V20h16v-9.5"/><path d="M3 10.5 4.5 5h15L21 10.5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"/>',
        'peso' => '<path d="M7 4h5.5a4.5 4.5 0 0 1 0 9H7"/><path d="M7 4v16"/><path d="M4.5 9h9"/><path d="M4.5 13h9"/>',
        'truck' => '<path d="M14 17V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11h3"/><path d="M14 9h4l3 3.5V17h-3"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'receipt' => '<path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21V3Z"/><path d="M9.5 8h5"/><path d="M9.5 12h5"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'items' => '<path d="M9 4 4.6 6.7a1 1 0 0 0-.4 1.2L5.5 11l2.5-.9V20h8v-9.9l2.5.9 1.3-3.1a1 1 0 0 0-.4-1.2L15 4a3 3 0 0 1-6 0z"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $paths[$name] . '</svg>';
}

function sales_monitor_money(int $cents): string
{
    return '&#8369;' . number_format($cents / 100, 2);
}

/* Validate once so the list, pagination and receipt back-link share state. */
$validDate = static function (string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
};

$filterFrom = trim((string) ($_GET['date_from'] ?? ''));
$filterTo = trim((string) ($_GET['date_to'] ?? ''));
if ($filterFrom !== '' && !$validDate($filterFrom)) {
    $filterFrom = '';
}
if ($filterTo !== '' && !$validDate($filterTo)) {
    $filterTo = '';
}

$filterChannel = strtoupper(trim((string) ($_GET['channel'] ?? '')));
if (!in_array($filterChannel, ['WALKIN', 'LOCAL_COURIER', 'STORE_PICKUP'], true)) {
    $filterChannel = '';
}

$filterSearch = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($filterSearch) > 120) {
    $filterSearch = mb_substr($filterSearch, 0, 120);
}

$hasFilters = ($filterFrom !== '' || $filterTo !== '' || $filterChannel !== '' || $filterSearch !== '');
$perPage = 50;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $perPage;
$qsArgs = array_filter([
    'search' => $filterSearch,
    'channel' => $filterChannel,
    'date_from' => $filterFrom,
    'date_to' => $filterTo,
], static function (string $value): bool { return $value !== ''; });
$baseQs = count($qsArgs) > 0 ? '?' . http_build_query($qsArgs) : '';

function page_href(int $p, string $baseQs): string
{
    $sep = $baseQs !== '' ? '&' : '?';
    return 'sales.php' . $baseQs . $sep . 'page=' . $p;
}

$viewId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

/* ============================================================
 * DETAIL / RECEIPT VIEW
 * ============================================================ */
if ($viewId) {
    $detailError = false;
    $sale = null;

    try {
        $sale = sales_monitor_detail($pdo, $viewId);
    } catch (Throwable $e) {
        $detailError = true;
    }

    $isOnline = $sale !== null && $sale['channel'] === 'ONLINE';
    $page_title = "Sale " . ($sale ? sale_code($sale['id']) : 'detail') . " - Admin - Hopia's Ukay-Ukay";
    $page_description = "Completed sale detail.";
    $ui_section = 'admin';
    $ui_active = 'sales.php';
    $body_class = 'admin-sale-detail-page';

    require __DIR__ . '/../includes/ui.head.php';
    require __DIR__ . '/../includes/ui.header.php';
    ?>

    <div class="admin-products sales-detail">
        <header class="admin-page-header">
            <div>
                <a class="admin-page-header__back no-print" href="<?= hopia_e(page_href($page, $baseQs)) ?>">&larr; Back to Sales</a>
                <p class="admin-page-header__eyebrow"><?= $sale && $sale['reporting_issue'] === null ? 'Completed sale' : 'Sale record' ?></p>
                <h1><?= $sale ? hopia_e(sale_code($sale['id'])) : 'SALE' ?></h1>
            </div>
            <?php if ($sale): ?>
                <button type="button" class="btn btn-secondary no-print" onclick="window.print()">Print receipt</button>
            <?php endif; ?>
        </header>

        <?php if ($detailError): ?>
            <div class="alert alert-error" role="alert">This sale could not be loaded. Please try again later.</div>
        <?php elseif (!$sale): ?>
            <div class="empty-state">
                <p>That sale was not found.</p>
                <p><a class="btn btn-primary" href="<?= hopia_e(page_href($page, $baseQs)) ?>">Back to Sales</a></p>
            </div>
        <?php else: ?>
            <?php if ($sale['reporting_issue'] !== null): ?>
                <div class="alert alert-warning" role="alert">
                    <?= hopia_e($sale['reporting_issue']) ?> This record is excluded from completed-sales totals.
                    Manual review is required; the original sale and its recorded amounts have not been changed.
                </div>
            <?php endif; ?>
            <section class="card receipt">
                <div class="card-body">
                    <div class="admin-section-heading">
                        <div>
                            <p class="admin-section-heading__eyebrow">Receipt</p>
                            <h2><?= hopia_e(sale_code($sale['id'])) ?></h2>
                        </div>
                        <span class="<?= channel_badge((string) $sale['reporting_channel']) ?>"><?= hopia_e(channel_label((string) $sale['reporting_channel'])) ?></span>
                    </div>

                    <dl class="admin-product-card__details">
                        <div>
                            <dt>Recorded sale date &amp; time</dt>
                            <dd><?= hopia_e(format_datetime($sale['sold_at'])) ?></dd>
                        </div>
                        <div>
                            <dt>Channel / fulfillment</dt>
                            <dd><?= hopia_e(channel_label((string) $sale['reporting_channel'])) ?></dd>
                        </div>
                        <?php if ($isOnline): ?>
                            <div>
                                <dt>Order</dt>
                                <dd>
                                    <a href="order-view.php?id=<?= (int) $sale['order_id'] ?>">
                                        #<?= (int) $sale['order_number'] ?>
                                    </a>
                                </dd>
                            </div>
                            <div>
                                <dt>Customer</dt>
                                <dd><?= hopia_e($sale['customer_name'] ?? ('Customer #' . (int) $sale['customer_id'])) ?></dd>
                            </div>
                            <?php if (!empty($sale['shipping_name']) && $sale['shipping_name'] !== $sale['customer_name']): ?>
                                <div>
                                    <dt>Order contact</dt>
                                    <dd><?= hopia_e($sale['shipping_name']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($sale['shipping_phone'])): ?>
                                <div>
                                    <dt>Contact number</dt>
                                    <dd><?= hopia_e($sale['shipping_phone']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if ($sale['reporting_channel'] === 'LOCAL_COURIER' && !empty($sale['shipping_address'])): ?>
                                <div>
                                    <dt>Delivery address</dt>
                                    <dd><?= hopia_e($sale['shipping_address']) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($sale['received_at'])): ?>
                                <div>
                                    <dt><?= $sale['reporting_channel'] === 'STORE_PICKUP' ? 'Pickup receipt recorded' : 'Customer receipt confirmed' ?></dt>
                                    <dd><?= hopia_e(format_datetime($sale['received_at'])) ?></dd>
                                </div>
                            <?php endif; ?>
                            <?php if ($sale['reporting_channel'] === 'STORE_PICKUP' && !empty($sale['picked_up_at'])): ?>
                                <div>
                                    <dt>Picked up</dt>
                                    <dd><?= hopia_e(format_datetime($sale['picked_up_at'])) ?></dd>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div>
                                <dt>Customer</dt>
                                <dd>Walk-in customer (name not recorded)</dd>
                            </div>
                            <div>
                                <dt>Cashier</dt>
                                <dd><?= hopia_e($sale['cashier'] ?? 'Not recorded') ?></dd>
                            </div>
                        <?php endif; ?>
                        <div>
                            <dt>Payment method</dt>
                            <dd><?= hopia_e(payment_method_label($sale['payment_method'], $sale['channel'])) ?></dd>
                        </div>
                        <div>
                            <dt>Payment status</dt>
                            <dd>
                                <?php if ($sale['payment_status'] !== null): ?>
                                    <span class="<?= payment_status_badge($sale['payment_status']) ?>"><?= hopia_e(payment_status_label($sale['payment_status'], $sale)) ?></span>
                                <?php elseif ($isOnline): ?>
                                    <span class="badge">Not recorded</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Settled in store</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <?php if ($sale['payment_method'] === 'GCASH'): ?>
                            <div>
                                <dt>GCash reference</dt>
                                <dd><?= hopia_e($sale['gcash_reference'] ?: 'Not recorded') ?></dd>
                            </div>
                            <?php if (!empty($sale['submitted_at'])): ?>
                                <div>
                                    <dt>Payment submitted</dt>
                                    <dd><?= hopia_e(format_datetime($sale['submitted_at'])) ?></dd>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($isOnline && in_array($sale['payment_method'], ['GCASH', 'COD', 'CASH_ON_PICKUP'], true)): ?>
                            <div>
                                <dt><?= $sale['payment_method'] === 'GCASH' ? 'Verification recorded at' : 'Collection recorded at' ?></dt>
                                <dd><?= hopia_e($sale['verified_at'] !== null ? format_datetime($sale['verified_at']) : 'Not recorded') ?></dd>
                            </div>
                            <div>
                                <dt><?= $sale['payment_method'] === 'GCASH' ? 'Verified by' : 'Collected by' ?></dt>
                                <dd><?= hopia_e($sale['payment_admin'] ?? ($sale['verified_by'] !== null ? 'Admin #' . (int) $sale['verified_by'] : 'Not recorded')) ?></dd>
                            </div>
                        <?php endif; ?>
                        <div>
                            <dt>Items</dt>
                            <dd><?= count($sale['items']) ?></dd>
                        </div>
                    </dl>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Item</th>
                                    <?php if (!$isOnline): ?>
                                        <th scope="col">Barcode</th>
                                    <?php endif; ?>
                                    <th scope="col">Qty</th>
                                    <th scope="col">Unit price</th>
                                    <th scope="col">Line total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sale['items'] as $item): ?>
                                    <tr>
                                        <td><?= hopia_e($item['name']) ?></td>
                                        <?php if (!$isOnline): ?>
                                            <td>
                                                <?php if ($item['barcode'] !== null): ?>
                                                    <code><?= hopia_e($item['barcode']) ?></code>
                                                <?php else: ?>
                                                    <span class="text-small muted">Not recorded</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        <td><?= (int) $item['quantity'] ?></td>
                                        <td><?= sales_monitor_money($item['unit_price_cents']) ?></td>
                                        <td><?= sales_monitor_money($item['line_total_cents']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="receipt__note">
                        Recorded item prices are preserved. Shipping is not merchandise revenue.
                    </p>

                    <div class="receipt__total">
                        <span>Merchandise total</span>
                        <span class="receipt__total-value"><?= sales_monitor_money($sale['total_cents']) ?></span>
                    </div>
                    <?php if ($isOnline): ?>
                        <dl class="admin-product-card__details">
                            <div>
                                <dt>Shipping charge</dt>
                                <dd><?= $sale['shipping_cents'] !== null ? sales_monitor_money($sale['shipping_cents']) : 'Not recorded' ?></dd>
                            </div>
                            <div>
                                <dt>Recorded order total (including shipping)</dt>
                                <dd><?= $sale['order_total_cents'] !== null ? sales_monitor_money($sale['order_total_cents']) : 'Not recorded' ?></dd>
                            </div>
                        </dl>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <?php
    require __DIR__ . '/../includes/ui.footer.php';
    exit;
}

/* ============================================================
 * LIST VIEW — filters, KPIs, table
 * ============================================================ */
$sales = [];
$totalRows = 0;
$summary = ['cents' => 0, 'conflicts' => []];
$queryError = false;

/* Today's channel/payment categories (Asia/Manila day boundaries). */
$today = [];

try {
    $sales = sales_monitor_list(
        $pdo,
        [
            'search' => $filterSearch,
            'channel' => $filterChannel,
            'date_from' => $filterFrom,
            'date_to' => $filterTo,
        ],
        $totalRows,
        $perPage,
        $offset,
        $summary
    );

    /* Clamp the page when a deep page outruns the filtered result set. */
    if (count($sales) === 0 && $page > 1) {
        $page = max(1, (int) ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;
        $sales = sales_monitor_list(
            $pdo,
            [
                'search' => $filterSearch,
                'channel' => $filterChannel,
                'date_from' => $filterFrom,
                'date_to' => $filterTo,
            ],
            $totalRows,
            $perPage,
            $offset,
            $summary
        );
    }

    $today = sales_monitor_today_cents($pdo);
} catch (Throwable $e) {
    $queryError = true;
    $sales = [];
    $totalRows = 0;
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));

$page_title = "Sales - Admin - Hopia's Ukay-Ukay";
$page_description = "Completed sales monitoring.";
$ui_section = 'admin';
$ui_active = 'sales.php';
$body_class = 'admin-sales-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<div class="admin-products sales-monitor">
    <header class="admin-page-header">
        <div>
            <p class="admin-page-header__eyebrow">Sales monitoring</p>
            <h1>COMPLETED SALES</h1>
            <p class="admin-page-header__copy">
                Welcome, <?= hopia_e($adminName) ?>. Every completed sale transaction &mdash; walk-in
                counter sales and finalized Local Courier or Store Pickup orders, one row per transaction.
            </p>
        </div>
        <a class="btn btn-primary" href="scan.php">New Walk-in Sale</a>
    </header>

    <section class="dash-kpis" aria-label="Today's sales summary">
        <?php foreach ([
            'WALKIN' => ['label' => 'Walk-in', 'icon' => 'store', 'note' => 'Completed counter transactions, Philippine local day.'],
            'LOCAL_COURIER_COD' => ['label' => 'Local Courier COD', 'icon' => 'truck', 'note' => 'Cash collected and sale finalized.'],
            'LOCAL_COURIER_GCASH' => ['label' => 'Local Courier GCash', 'icon' => 'truck', 'note' => 'GCash verified and sale finalized.'],
            'STORE_PICKUP_CASH_ON_PICKUP' => ['label' => 'Store Pickup COP', 'icon' => 'store', 'note' => 'Cash on Pickup collected, handover and sale completed.'],
            'STORE_PICKUP_GCASH' => ['label' => 'Store Pickup GCash', 'icon' => 'store', 'note' => 'GCash verified, handover and sale completed.'],
        ] as $category => $card):
            $isWalkinCard = $category === 'WALKIN';
        ?>
            <article class="dash-kpi dash-kpi--<?= $isWalkinCard ? 'sales' : 'items' ?>">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label"><?= hopia_e($card['label']) ?> Sales Today</span>
                    <span class="dash-kpi__icon"><?= sales_monitor_icon($card['icon']) ?></span>
                </div>
                <p class="dash-kpi__value"><?= $queryError ? '&mdash;' : (int) $today[$category]['count'] ?></p>
                <p class="dash-kpi__sub"><?= hopia_e($card['note']) ?></p>
                <span class="dash-kpi__bar"></span>
            </article>
            <article class="dash-kpi dash-kpi--<?= $isWalkinCard ? 'profit' : 'orders' ?>">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label"><?= hopia_e($card['label']) ?> Revenue Today</span>
                    <span class="dash-kpi__icon"><?= sales_monitor_icon($isWalkinCard ? 'peso' : 'receipt') ?></span>
                </div>
                <p class="dash-kpi__value"><?= $queryError ? '&mdash;' : sales_monitor_money($today[$category]['cents']) ?></p>
                <p class="dash-kpi__sub"><?= $isWalkinCard ? 'Merchandise totals from committed walk-in sales.' : 'Merchandise revenue only; shipping excluded.' ?></p>
                <span class="dash-kpi__bar"></span>
            </article>
        <?php endforeach; ?>
    </section>
    <p class="text-small muted">Today cards use the Philippine local day and are not changed by the filters below.</p>
    <?php if (!$queryError && $today['unclassified_count'] > 0): ?>
        <div class="alert alert-warning" role="alert">
            <?= (int) $today['unclassified_count'] ?> completed transaction(s) today have a stored channel/payment combination
            outside these five KPI categories. They remain in the completed-transactions list for review;
            no payment method, channel or stored sale has been changed.
        </div>
    <?php endif; ?>

    <section class="admin-products__filters card" aria-labelledby="sales-filters-title">
        <div class="card-body">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-section-heading__eyebrow">Refine</p>
                    <h2 id="sales-filters-title">Search &amp; filter transactions</h2>
                </div>
            </div>

            <form class="admin-filter-form sales-monitor__filters" method="get" action="sales.php">
                <div class="field sales-monitor__search">
                    <label for="sales-search">Search</label>
                    <input type="search" id="sales-search" name="search" maxlength="120"
                        placeholder="Receipt no. (SALE-000123), order no., or customer name"
                        value="<?= hopia_e($filterSearch) ?>">
                    <p class="hint">Matches the SALE- reference, online order number, cashier, or customer name.</p>
                </div>
                <div class="field">
                    <label for="sales-channel">Channel</label>
                    <select id="sales-channel" name="channel">
                        <option value=""<?= $filterChannel === '' ? ' selected' : '' ?>>All Sales</option>
                        <option value="WALKIN"<?= $filterChannel === 'WALKIN' ? ' selected' : '' ?>>Walk-in</option>
                        <option value="LOCAL_COURIER"<?= $filterChannel === 'LOCAL_COURIER' ? ' selected' : '' ?>>Local Courier</option>
                        <option value="STORE_PICKUP"<?= $filterChannel === 'STORE_PICKUP' ? ' selected' : '' ?>>Store Pickup</option>
                    </select>
                </div>
                <div class="field">
                    <label for="date-from">From</label>
                    <input type="date" id="date-from" name="date_from" value="<?= hopia_e($filterFrom) ?>">
                </div>
                <div class="field">
                    <label for="date-to">To</label>
                    <input type="date" id="date-to" name="date_to" value="<?= hopia_e($filterTo) ?>">
                </div>
                <div class="admin-filter-form__actions">
                    <button class="btn btn-primary" type="submit">Apply</button>
                    <?php if ($hasFilters): ?>
                        <a class="btn btn-ghost" href="sales.php">Clear search &amp; filters</a>
                    <?php else: ?>
                        <a class="btn btn-ghost" href="sales.php">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <?php if (!$queryError && count($summary['conflicts']) > 0): ?>
        <div class="alert alert-warning" role="alert">
            <p>Some canonical sale records conflict with their recorded items or completion/payment state.
                They are excluded from completed totals and need manual review. No records were changed.
                Showing up to five matching records:</p>
            <ul>
                <?php foreach ($summary['conflicts'] as $conflict): ?>
                    <li>
                        <a href="<?= hopia_e(page_href($page, $baseQs) . '&id=' . (int) $conflict['id']) ?>"><?= hopia_e(sale_code((int) $conflict['id'])) ?></a>
                        <?php if ($conflict['order_id'] !== null): ?> (Order #<?= (int) $conflict['order_id'] ?>)<?php endif; ?>:
                        <?= hopia_e($conflict['reporting_issue']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($queryError): ?>
        <div class="alert alert-error" role="alert">
            Sales could not be loaded. Please try again or ask the administrator to check the existing sales, fulfillment and payment setup.
        </div>
    <?php elseif (count($sales) === 0): ?>
        <div class="empty-state empty-state--search">
            <?php if ($hasFilters): ?>
                <p class="empty-state__title">No matching transactions</p>
                <p>No completed sales match your current search or filters.</p>
                <p><a class="btn btn-primary" href="sales.php">Clear search &amp; filters</a></p>
            <?php else: ?>
                <p>No completed sales have been recorded yet.</p>
                <p><a class="btn btn-primary" href="scan.php">Record your first walk-in sale</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="admin-products__summary sales-monitor__summary">
            <p class="text-small muted">
                Showing <?= count($sales) ?> of <?= (int) $totalRows ?> completed transaction<?= (int) $totalRows === 1 ? '' : 's' ?>
                <?php if ($totalPages > 1): ?>
                    &middot; Page <?= (int) $page ?> of <?= (int) $totalPages ?>
                <?php endif; ?>.
            </p>
            <p class="text-small muted">Filtered merchandise total: <strong><?= sales_monitor_money($summary['cents']) ?></strong> (shipping excluded).</p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Receipt</th>
                        <th scope="col">Recorded sale date &amp; time</th>
                        <th scope="col">Channel</th>
                        <th scope="col">Customer / reference</th>
                        <th scope="col">Items</th>
                        <th scope="col">Merchandise total</th>
                        <th scope="col">Payment method</th>
                        <th scope="col">Payment status</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $saleRow):
                        $saleId = (int) $saleRow['id'];
                        $isOnlineRow = (string) $saleRow['sale_channel'] === 'ONLINE';
                    ?>
                        <tr>
                            <td><code class="bundle-code"><?= hopia_e(sale_code($saleId)) ?></code></td>
                            <td><?= hopia_e(format_datetime($saleRow['sold_at'])) ?></td>
                            <td><span class="<?= channel_badge((string) $saleRow['reporting_channel']) ?>"><?= hopia_e(channel_label((string) $saleRow['reporting_channel'])) ?></span></td>
                            <td>
                                <?php if ($isOnlineRow): ?>
                                    <a href="order-view.php?id=<?= (int) $saleRow['order_id'] ?>">Order #<?= (int) $saleRow['order_number'] ?></a>
                                    <span class="sales-monitor__cust"><?= hopia_e($saleRow['customer_name'] ?? ('Customer #' . (int) $saleRow['customer_id'])) ?></span>
                                <?php else: ?>
                                    Walk-in customer
                                    <span class="sales-monitor__cust">Cashier: <?= hopia_e($saleRow['cashier'] ?: 'Not recorded') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $saleRow['item_count'] ?></td>
                            <td><span class="price"><?= sales_monitor_money((int) sales_money_to_cents($saleRow['total_amount'])) ?></span></td>
                            <td><?= hopia_e(payment_method_label(
                                is_string($saleRow['payment_method']) && $saleRow['payment_method'] !== '' ? $saleRow['payment_method'] : null,
                                (string) $saleRow['sale_channel']
                            )) ?></td>
                            <td>
                                <?php $rowStatus = is_string($saleRow['payment_status']) && $saleRow['payment_status'] !== '' ? $saleRow['payment_status'] : null; ?>
                                <?php if ($rowStatus !== null): ?>
                                    <span class="<?= payment_status_badge($rowStatus) ?>"><?= hopia_e(payment_status_label($rowStatus, $saleRow)) ?></span>
                                <?php elseif ($isOnlineRow): ?>
                                    <span class="badge">Not recorded</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Settled in store</span>
                                <?php endif; ?>
                            </td>
                            <td><a class="btn btn-secondary btn-sm" href="<?= hopia_e(page_href($page, $baseQs) . '&id=' . $saleId) ?>">View Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="sales-monitor__pagination inv-pagination" aria-label="Completed sales pages">
                <?php if ($page > 1): ?>
                    <a class="inv-pagination__btn" href="<?= hopia_e(page_href($page - 1, $baseQs)) ?>">&larr; Previous</a>
                <?php endif; ?>
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $startPage + 4);
                $startPage = max(1, $endPage - 4);
                for ($p = $startPage; $p <= $endPage; $p++):
                    ?>
                    <a class="inv-pagination__num<?= $p === $page ? ' is-current' : '' ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>
                        href="<?= hopia_e(page_href($p, $baseQs)) ?>"><?= (int) $p ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="inv-pagination__btn" href="<?= hopia_e(page_href($page + 1, $baseQs)) ?>">Next &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php';
