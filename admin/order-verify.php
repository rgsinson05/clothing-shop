<?php

/*
 * Hopia Fits - Phase B online-order Scan-to-Verify page.
 *
 * Authenticated admin page, SEPARATE from the Phase A walk-in scanner
 * (admin/scan.php). It lets an admin confirm that every item of an eligible
 * online order is physically present before the order is shipped, by scanning
 * each item's barcode or explicitly Manual-Verifying an unscannable/legacy
 * item.
 *
 * Two modes:
 *   - No ?id          : list orders eligible for verification (CONFIRMED or
 *                       PACKED, no pending/approved cancellation).
 *   - ?id=<order id>  : the verification workspace for one order.
 *
 * Verifying NEVER completes a sale, never shows a sellable total, never
 * changes products.status, and never advances the order. The single shipment
 * gate lives in admin/order-view.php (PACKED -> SHIPPED). All eligibility and
 * progress logic is shared from includes/order_verify.php; this page only
 * presents it. Camera scanning reuses the proven Phase A techniques (Code 128,
 * rear-camera preference, torch/zoom, beep + duplicate suppression, manual
 * entry) but funnels every scan into the Phase B verify endpoint.
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/order_verify.php';
require_once __DIR__ . '/../includes/ui.php';

$adminName = $_SESSION['admin_name'] ?? 'Admin';

/*
 * Stable per-session CSRF token for the Phase B verify endpoints. Separate from
 * the one-shot $_SESSION['admin_csrf_token'] (admin forms) and the Phase A
 * $_SESSION['walkin_csrf'] so repeated verify scans never clobber those tokens.
 */
if (empty($_SESSION['order_verify_csrf'])) {
    $_SESSION['order_verify_csrf'] = bin2hex(random_bytes(32));
}
$verifyCsrf = $_SESSION['order_verify_csrf'];

$orderStatusLabels = [
    'PENDING' => 'Pending',
    'CONFIRMED' => 'Confirmed',
    'PACKED' => 'Packed',
    'SHIPPED' => 'Shipped',
    'DELIVERED' => 'Delivered',
    'CANCELLED' => 'Cancelled',
];

$orderStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'CONFIRMED' => 'badge-info',
    'PACKED' => 'badge-warning',
    'SHIPPED' => 'badge-info',
    'DELIVERED' => 'badge-success',
    'CANCELLED' => 'badge-cancelled',
];

$requestedOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$hasOrderParam = $requestedOrderId !== null; // present but maybe invalid

$order = false;
$orderError = null;    // eligibility reason code when an order is loaded but not verifiable
$items = [];
$progress = ['expected' => 0, 'verified' => 0, 'remaining' => 0, 'complete' => false];
$eligibleOrders = [];

if ($hasOrderParam && $requestedOrderId !== false && $requestedOrderId > 0) {

    $orderStmt = $pdo->prepare(
        'SELECT o.id,
                o.status,
                o.cancellation_status,
                o.created_at,
                CONCAT(c.first_name, \' \', c.last_name) AS customer_name,
                c.email AS customer_email,
                c.phone AS customer_phone
         FROM orders o
         JOIN customers c ON c.id = o.customer_id
         WHERE o.id = :order_id
         LIMIT 1'
    );
    $orderStmt->execute([':order_id' => $requestedOrderId]);
    $order = $orderStmt->fetch();

    if ($order !== false) {
        /* Re-check eligibility server-side; never trust the inbound link. */
        $orderError = order_verify_order_error($order);

        if ($orderError === null) {
            $items = order_verify_item_rows($pdo, (int) $order['id']);
            $progress = order_verify_progress($pdo, (int) $order['id']);
        }
    }
} else {
    /* List mode: eligible orders only. */
    $eligibleOrders = order_verify_eligible_orders($pdo);
}

$pageTitleBase = ($order !== false)
    ? 'Verify Order #' . (int) $order['id']
    : 'Verify Orders';

$page_title = $pageTitleBase . " - Admin - Hopia's Ukay-Ukay";
$page_description = "Scan each item of an online order to verify it before shipment.";
$ui_section = 'admin';
$ui_active = 'order-verify.php';
$body_class = 'admin-order-verify-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<style>
/* Scoped layout for the Phase B verify page (keeps css/styles.css untouched). */
.verify-grid { display: grid; gap: 1.5rem; grid-template-columns: 1fr; }
@media (min-width: 960px) { .verify-grid { grid-template-columns: 1fr 1fr; align-items: start; } }

.verify-progress { display: flex; align-items: baseline; gap: .6rem; flex-wrap: wrap; }
.verify-progress__value { font-size: 1.5rem; font-weight: 700; }
.verify-progress__bar { position: relative; height: 8px; border-radius: 999px; background: var(--admin-border, #11100e1a); overflow: hidden; margin-top: .5rem; }
.verify-progress__fill { position: absolute; inset: 0 auto 0 0; width: 0; background: var(--admin-success, #1a7f37); border-radius: 999px; transition: width .25s ease; }

.verify-complete { margin: 1rem 0 0; }
.verify-complete[hidden] { display: none; }

/* Camera (same techniques as admin/scan.php). */
.verify-reader-frame { width: 100%; max-width: 640px; min-height: 220px; margin: 0 auto; display: grid; place-items: center; border: 1px dashed var(--admin-border-strong, #11100e33); border-radius: 12px; color: var(--admin-text-muted, #11100e99); font-size: .9rem; padding: 1rem; text-align: center; }
.verify-reader-frame[hidden] { display: none; }
.verify-reader-wrap { position: relative; width: 100%; max-width: 640px; margin: 0 auto; border-radius: 12px; overflow: hidden; background: #11100e; transition: box-shadow .15s ease; }
.verify-reader-wrap[hidden] { display: none; }
.verify-reader-wrap.is-flash { box-shadow: 0 0 0 4px var(--admin-success, #1a7f37); }
.verify-reader { width: 100%; }
.verify-reader video { display: block; width: 100% !important; height: auto; background: #11100e; }
.verify-reader__hint { position: absolute; top: 8px; left: 8px; right: 8px; margin: 0; padding: 6px 10px; border-radius: 8px; background: rgba(17,16,14,.75); color: #fff; font-size: .82rem; line-height: 1.4; text-align: center; pointer-events: none; }
.verify-reader__state { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); margin: 0; padding: 5px 14px; border-radius: 999px; background: rgba(17,16,14,.85); color: #fff; font-size: .85rem; font-weight: 600; max-width: 92%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center; pointer-events: none; }
.verify-reader__state[data-kind="starting"] { background: rgba(138,90,0,.92); }
.verify-reader__state[data-kind="ready"] { background: rgba(29,122,60,.92); }
.verify-reader__state[data-kind="ok"] { background: #1a7f37; }
.verify-reader__state[data-kind="warn"] { background: rgba(138,90,0,.92); }
.verify-reader__state[data-kind="error"], .verify-reader__state[data-kind="offline"] { background: rgba(180,35,24,.92); }
.verify-controls { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; align-items: center; }
.verify-controls .btn[hidden] { display: none; }
.verify-zoom { display: inline-flex; align-items: center; gap: .45rem; font-size: .85rem; color: var(--admin-text, #11100e); }
.verify-zoom[hidden] { display: none; }
.verify-zoom input[type="range"] { width: 120px; }
.verify-manual-entry { display: flex; gap: .5rem; flex-wrap: wrap; align-items: flex-end; }
.verify-manual-entry .field { flex: 1 1 220px; margin: 0; }
.verify-status { margin: .75rem 0; }

/* Checklist. */
.verify-item { display: flex; gap: 1rem; justify-content: space-between; align-items: flex-start; padding: .9rem 0; border-top: 1px solid var(--admin-border, #11100e14); }
.verify-item:first-child { border-top: 0; }
.verify-item__info h3 { margin: 0 0 .2rem; font-size: 1rem; }
.verify-item__meta { margin: 0; font-size: .82rem; color: var(--admin-text-muted, #11100e99); }
.verify-item__code { font-size: .8rem; color: var(--admin-text-muted, #11100e99); }
.verify-item__status { text-align: right; min-width: 150px; }
.verify-item__detail { display: block; font-size: .78rem; color: var(--admin-text-muted, #11100e99); margin-top: .25rem; }
.verify-item__reason { display: block; font-size: .8rem; color: var(--admin-text, #11100e); margin-top: .25rem; font-style: italic; }
.verify-item.is-verified { opacity: .9; }
.verify-item__action { margin-top: .4rem; }
.verify-item__action[hidden] { display: none; }
.verify-badge-unscannable { display: inline-block; font-size: .72rem; letter-spacing: .03em; text-transform: uppercase; color: var(--admin-warning, #8a5a00); background: var(--admin-warning-bg, #8a5a0014); border: 1px solid var(--admin-warning-border, #8a5a0033); border-radius: 999px; padding: .1rem .5rem; margin-top: .3rem; }

/* Manual verify modal. */
.verify-modal { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center; padding: 1rem; }
.verify-modal[hidden] { display: none; }
.verify-modal__backdrop { position: absolute; inset: 0; background: var(--admin-overlay, rgba(17,16,14,.55)); }
.verify-modal__panel { position: relative; width: 100%; max-width: 460px; background: var(--admin-surface, #fff); border-radius: 14px; padding: 1.5rem; box-shadow: 0 20px 60px rgba(17,16,14,.3); }
.verify-modal__panel h2 { margin-top: 0; }
.verify-modal__confirm { display: flex; gap: .5rem; align-items: flex-start; margin: .75rem 0; font-size: .9rem; }
.verify-modal__actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1rem; flex-wrap: wrap; }
</style>

<div class="admin-order-verify">

<?php if (!$hasOrderParam || $requestedOrderId === false || $requestedOrderId <= 0): ?>

    <?php /* ======================= LIST MODE ======================= */ ?>
    <header class="admin-page-header">
        <div>
            <p class="admin-page-header__eyebrow">Online fulfilment</p>
            <h1>VERIFY ORDERS</h1>
            <p class="admin-page-header__copy">
                Welcome, <?= hopia_e($adminName) ?>. Select an order to scan each item and verify it is in
                the parcel before it ships. Only <strong>Confirmed</strong> and <strong>Packed</strong> orders
                without a pending or approved cancellation are listed.
            </p>
        </div>
    </header>

    <?php if (count($eligibleOrders) === 0): ?>
        <div class="empty-state">
            <p>No orders are currently awaiting verification.</p>
            <p><a class="btn btn-primary" href="orders.php">Back to Orders</a></p>
        </div>
    <?php else: ?>
        <div class="admin-orders__summary">
            <p class="text-small muted">
                Showing <?= count($eligibleOrders) ?> order<?= count($eligibleOrders) === 1 ? '' : 's' ?> eligible for verification.
            </p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Status</th>
                        <th scope="col">Verified</th>
                        <th scope="col">Placed</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eligibleOrders as $eligible): ?>
                        <?php
                        $expected = (int) $eligible['expected'];
                        $verified = (int) $eligible['verified'];
                        $statusKey = (string) $eligible['status'];
                        $badgeClass = $orderStatusBadgeClasses[$statusKey] ?? 'badge';
                        $allDone = $expected > 0 && $verified >= $expected;
                        ?>
                        <tr>
                            <td><strong>#<?= (int) $eligible['id'] ?></strong></td>
                            <td><?= hopia_e($eligible['customer_name']) ?></td>
                            <td><span class="badge <?= hopia_e($badgeClass) ?>"><?= hopia_e($statusKey) ?></span></td>
                            <td>
                                <span class="badge <?= $allDone ? 'badge-success' : 'badge-pending' ?>">
                                    <?= $verified ?>/<?= $expected ?>
                                </span>
                            </td>
                            <td><?= hopia_e(date('M j, Y g:i A', strtotime($eligible['created_at']))) ?></td>
                            <td><a class="btn btn-primary btn-sm" href="order-verify.php?id=<?= (int) $eligible['id'] ?>">Verify Items</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($order === false): ?>

    <?php /* ======================= NOT FOUND ======================= */ ?>
    <div class="admin-order-detail__empty">
        <p class="admin-page-header__eyebrow">Online fulfilment</p>
        <h1>ORDER NOT FOUND</h1>
        <p>Order not found. The order ID may be missing, invalid, or the order may no longer exist.</p>
        <a class="btn btn-primary" href="order-verify.php">Back to Verify Orders</a>
    </div>

<?php elseif ($orderError !== null): ?>

    <?php /* =================== LOADED BUT INELIGIBLE =================== */ ?>
    <header class="admin-page-header">
        <div>
            <a class="admin-page-header__back" href="order-verify.php">&larr; Back to Verify Orders</a>
            <p class="admin-page-header__eyebrow">Online fulfilment</p>
            <h1>ORDER #<?= (int) $order['id'] ?></h1>
        </div>
        <span class="badge <?= hopia_e($orderStatusBadgeClasses[$order['status']] ?? 'badge') ?>">
            <?= hopia_e($order['status']) ?>
        </span>
    </header>

    <div class="alert alert-error" role="alert">
        <?= hopia_e(order_verify_message($orderError)) ?>
    </div>

    <p><a class="btn btn-secondary" href="order-view.php?id=<?= (int) $order['id'] ?>">Open order details</a></p>

<?php else: ?>

    <?php /* ==================== VERIFICATION MODE ==================== */ ?>
    <?php
    $customerPhone = trim($order['customer_phone'] ?? '');
    $orderIdInt = (int) $order['id'];
    ?>
    <header class="admin-page-header">
        <div>
            <a class="admin-page-header__back" href="order-verify.php">&larr; Back to Verify Orders</a>
            <p class="admin-page-header__eyebrow">Online fulfilment &middot; verify before shipment</p>
            <h1>VERIFY ORDER #<?= $orderIdInt ?></h1>
            <p class="admin-page-header__copy">
                Scan each item's barcode to verify it is in the parcel. Items that cannot be scanned must be
                verified manually with a reason. Every item must be verified before the order can be shipped.
            </p>
        </div>
        <span class="badge <?= hopia_e($orderStatusBadgeClasses[$order['status']] ?? 'badge') ?>">
            <?= hopia_e($order['status']) ?>
        </span>
    </header>

    <section class="card" aria-labelledby="verify-customer-title" style="margin-bottom:1.5rem;">
        <div class="card-body">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-section-heading__eyebrow">Order</p>
                    <h2 id="verify-customer-title">Customer &amp; progress</h2>
                </div>
                <a class="btn btn-ghost btn-sm" href="order-view.php?id=<?= $orderIdInt ?>">Order details</a>
            </div>

            <dl class="admin-order-detail__details admin-order-detail__details--inline">
                <div>
                    <dt>Customer</dt>
                    <dd><?= hopia_e($order['customer_name']) ?></dd>
                </div>
                <div>
                    <dt>Email</dt>
                    <dd><?= hopia_e($order['customer_email']) ?></dd>
                </div>
                <div>
                    <dt>Phone</dt>
                    <dd><?= $customerPhone !== '' ? hopia_e($customerPhone) : '&mdash;' ?></dd>
                </div>
            </dl>

            <div class="verify-progress" style="margin-top:1rem;">
                <span class="verify-progress__value">Verified <span id="verify-count-done"><?= (int) $progress['verified'] ?></span> of <span id="verify-count-total"><?= (int) $progress['expected'] ?></span></span>
                <span class="badge <?= $progress['complete'] ? 'badge-success' : 'badge-pending' ?>" id="verify-progress-badge">
                    <?= $progress['complete'] ? 'All verified' : 'In progress' ?>
                </span>
            </div>
            <div class="verify-progress__bar" aria-hidden="true">
                <div class="verify-progress__fill" id="verify-progress-fill"></div>
            </div>

            <div class="alert alert-success verify-complete" id="verify-complete-banner" role="status" <?= $progress['complete'] ? '' : 'hidden' ?>>
                Every item is verified. You can ship this order from
                <a href="order-view.php?id=<?= $orderIdInt ?>">the order page</a> once a tracking number is saved.
            </div>
        </div>
    </section>

    <div id="verify-status" class="verify-status" role="status" aria-live="polite"></div>

    <div class="verify-grid">
        <section class="card" aria-labelledby="verify-scan-title">
            <div class="card-body">
                <div class="admin-section-heading">
                    <div>
                        <p class="admin-section-heading__eyebrow">Scan</p>
                        <h2 id="verify-scan-title">Scan or enter a barcode</h2>
                    </div>
                </div>

                <form id="verify-manual-form" class="verify-manual-entry" autocomplete="off">
                    <div class="field">
                        <label for="verify-barcode-input">Barcode</label>
                        <input
                            type="text"
                            id="verify-barcode-input"
                            name="barcode"
                            class="input"
                            inputmode="text"
                            autocapitalize="characters"
                            placeholder="e.g. HOPEBUNDLE-000005-01"
                            aria-describedby="verify-input-hint"
                        >
                    </div>
                    <button type="submit" class="btn btn-primary">Verify Item</button>
                </form>
                <p id="verify-input-hint" class="hint">
                    Works with a USB/Bluetooth scanner, the camera below, or manual typing. A scan only verifies
                    an item that belongs to this order.
                </p>

                <div class="verify-controls">
                    <button type="button" id="verify-camera-start" class="btn btn-secondary btn-sm">Start Camera</button>
                    <button type="button" id="verify-camera-stop" class="btn btn-ghost btn-sm" hidden>Stop Camera</button>
                    <button type="button" id="verify-torch" class="btn btn-secondary btn-sm" hidden aria-pressed="false">Flashlight: Off</button>
                    <label id="verify-zoom-control" class="verify-zoom" hidden>
                        <span>Zoom</span>
                        <input type="range" id="verify-zoom" aria-label="Camera zoom">
                    </label>
                </div>

                <div id="verify-reader-off" class="verify-reader-frame">
                    Camera is off. Press <strong>&nbsp;Start Camera&nbsp;</strong> to scan with your device camera,
                    or keep using manual entry.
                </div>

                <div id="verify-reader-wrap" class="verify-reader-wrap" hidden>
                    <div id="verify-reader" class="verify-reader"></div>
                    <p class="verify-reader__hint">
                        Hold steady, 10&ndash;15&nbsp;cm away. Fit the <strong>entire</strong> barcode inside the
                        frame, including its outer bars.
                    </p>
                    <p class="verify-reader__state" id="verify-camera-state" role="status" data-kind="starting">Starting camera&hellip;</p>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="verify-items-title">
            <div class="card-body">
                <div class="admin-section-heading">
                    <div>
                        <p class="admin-section-heading__eyebrow">Checklist</p>
                        <h2 id="verify-items-title">Expected items (<?= count($items) ?>)</h2>
                    </div>
                </div>

                <?php if (count($items) === 0): ?>
                    <p class="muted">No items are recorded for this order, so it cannot be verified or shipped.</p>
                <?php else: ?>
                    <div id="verify-item-list">
                        <?php foreach ($items as $item): ?>
                            <?php
                            $oiId = (int) $item['order_item_id'];
                            $size = trim((string) ($item['size'] ?? ''));
                            $color = trim((string) ($item['color'] ?? ''));
                            $scannable = (bool) $item['scannable'];
                            $verified = (bool) $item['verified'];
                            $productBarcode = trim((string) ($item['product_barcode'] ?? ''));
                            $meta = [];
                            if ($size !== '') { $meta[] = 'Size ' . $size; }
                            if ($color !== '') { $meta[] = $color; }
                            ?>
                            <article class="verify-item<?= $verified ? ' is-verified' : '' ?>"
                                     data-order-item-id="<?= $oiId ?>"
                                     data-scannable="<?= $scannable ? '1' : '0' ?>"
                                     data-verified="<?= $verified ? '1' : '0' ?>"
                                     data-name="<?= hopia_e($item['product_name']) ?>">
                                <div class="verify-item__info">
                                    <h3><?= hopia_e($item['product_name']) ?></h3>
                                    <?php if (count($meta) > 0): ?>
                                        <p class="verify-item__meta"><?= hopia_e(implode(' · ', $meta)) ?></p>
                                    <?php endif; ?>
                                    <?php if ($scannable): ?>
                                        <p class="verify-item__meta verify-item__code">Barcode: <code><?= hopia_e($productBarcode) ?></code></p>
                                    <?php else: ?>
                                        <span class="verify-badge-unscannable">Unscannable &ndash; manual verify</span>
                                    <?php endif; ?>

                                    <?php if (!$scannable): ?>
                                        <div class="verify-item__action" data-manual-action <?= $verified ? 'hidden' : '' ?>>
                                            <button type="button" class="btn btn-secondary btn-sm"
                                                    data-manual-verify
                                                    data-order-item-id="<?= $oiId ?>"
                                                    data-name="<?= hopia_e($item['product_name']) ?>">
                                                Manual Verify
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="verify-item__status" data-status-cell>
                                    <?php if ($verified): ?>
                                        <span class="badge badge-success">Verified</span>
                                        <span class="verify-item__detail">
                                            <?= hopia_e($item['method']) ?><?php
                                                if ($item['verified_at']) {
                                                    echo ' &middot; ' . hopia_e(date('M j, g:i A', strtotime((string) $item['verified_at'])));
                                                }
                                                if (!empty($item['verified_by_name'])) {
                                                    echo ' &middot; ' . hopia_e($item['verified_by_name']);
                                                }
                                            ?>
                                        </span>
                                        <?php if ($item['method'] === 'MANUAL' && !empty($item['reason'])): ?>
                                            <span class="verify-item__reason">&ldquo;<?= hopia_e($item['reason']) ?>&rdquo;</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-pending">Unverified</span>
                                        <?php if ($scannable): ?>
                                            <span class="verify-item__detail">Scan its barcode</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Manual verify modal -->
    <div class="verify-modal" id="verify-modal" hidden>
        <div class="verify-modal__backdrop" data-manual-cancel></div>
        <div class="verify-modal__panel" role="dialog" aria-modal="true" aria-labelledby="verify-modal-title">
            <h2 id="verify-modal-title">Manually verify item</h2>
            <p>You are manually verifying: <strong id="verify-modal-item-name"></strong></p>
            <p class="text-small muted">
                Use this only for items that cannot be scanned (no barcode or a legacy item). An item with a valid
                barcode must be scanned instead.
            </p>

            <form id="verify-modal-form">
                <label class="verify-modal__confirm">
                    <input type="checkbox" id="verify-modal-confirm" required>
                    <span>I confirm the physical garment was checked and is included in this parcel.</span>
                </label>

                <div class="field">
                    <label for="verify-modal-reason">Reason</label>
                    <textarea id="verify-modal-reason" name="reason" class="input" rows="3" maxlength="255"
                              placeholder="e.g. Legacy item with no barcode label; matched by name and description."></textarea>
                    <p class="hint">Required. Explain how you confirmed this item without a scan.</p>
                </div>

                <div class="verify-modal__actions">
                    <button type="button" class="btn btn-ghost" data-manual-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary" id="verify-modal-submit">Confirm Manual Verify</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= hopia_e(hopia_asset('js/vendor/html5-qrcode/html5-qrcode.min.js')) ?>"></script>
    <script>
    (function () {
        'use strict';

        var CONFIG = {
            csrf: <?= json_encode($verifyCsrf) ?>,
            orderId: <?= json_encode($orderIdInt) ?>,
            scanUrl: 'api/order-verify-scan.php',
            manualUrl: 'api/order-verify-manual.php'
        };

        var elStatus = document.getElementById('verify-status');
        var elForm = document.getElementById('verify-manual-form');
        var elInput = document.getElementById('verify-barcode-input');
        var elList = document.getElementById('verify-item-list');
        var elCountDone = document.getElementById('verify-count-done');
        var elCountTotal = document.getElementById('verify-count-total');
        var elProgressFill = document.getElementById('verify-progress-fill');
        var elProgressBadge = document.getElementById('verify-progress-badge');
        var elCompleteBanner = document.getElementById('verify-complete-banner');

        var elStart = document.getElementById('verify-camera-start');
        var elStop = document.getElementById('verify-camera-stop');
        var elReaderOff = document.getElementById('verify-reader-off');
        var elReaderWrap = document.getElementById('verify-reader-wrap');
        var elCameraState = document.getElementById('verify-camera-state');
        var elTorch = document.getElementById('verify-torch');
        var elZoomControl = document.getElementById('verify-zoom-control');
        var elZoom = document.getElementById('verify-zoom');

        var inFlight = {};
        var cameraScanner = null;
        var cameraRunning = false;
        var cameraStarting = false;
        var lastScan = { code: '', at: 0 };
        var torchSupported = false;
        var torchOn = false;
        var zoomCaps = null;
        var audioCtx = null;

        function getAudioCtx() {
            if (audioCtx) { return audioCtx; }
            try {
                var Ctor = window.AudioContext || window.webkitAudioContext;
                if (!Ctor) { return null; }
                audioCtx = new Ctor();
            } catch (e) { audioCtx = null; }
            return audioCtx;
        }

        /* One crisp ~800 Hz beep, mirroring the Phase A scanner. */
        function playSuccessBeep() {
            var ctx = getAudioCtx();
            if (!ctx) { return; }
            try {
                if (ctx.state === 'suspended') { ctx.resume(); }
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'square';
                osc.frequency.setValueAtTime(800, ctx.currentTime);
                gain.gain.setValueAtTime(0, ctx.currentTime);
                gain.gain.linearRampToValueAtTime(0.20, ctx.currentTime + 0.005);
                gain.gain.setValueAtTime(0.20, ctx.currentTime + 0.070);
                gain.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.090);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.100);
            } catch (e) { /* blocked */ }
        }

        function setStatus(message, kind) {
            if (kind === 'error') { window.hopiaAdminSuccess.dismiss(); }
            elStatus.textContent = '';
            if (!message) { return; }
            var box = document.createElement('div');
            box.className = 'alert ' + (kind === 'error' ? 'alert-error' : (kind === 'success' ? 'alert-success' : 'alert-info'));
            box.setAttribute('role', kind === 'error' ? 'alert' : 'status');
            box.textContent = message;
            elStatus.appendChild(box);
        }

        function setCameraState(text, kind) {
            if (!elCameraState) { return; }
            elCameraState.textContent = text;
            elCameraState.setAttribute('data-kind', kind);
        }

        function updateProgress(progress) {
            if (!progress) { return; }
            if (elCountDone) { elCountDone.textContent = String(progress.verified); }
            if (elCountTotal) { elCountTotal.textContent = String(progress.expected); }
            var pct = progress.expected > 0 ? Math.round((progress.verified / progress.expected) * 100) : 0;
            if (elProgressFill) { elProgressFill.style.width = pct + '%'; }
            if (elProgressBadge) {
                elProgressBadge.textContent = progress.complete ? 'All verified' : 'In progress';
                elProgressBadge.className = 'badge ' + (progress.complete ? 'badge-success' : 'badge-pending');
            }
            if (elCompleteBanner) { elCompleteBanner.hidden = !progress.complete; }
        }

        // Initialise the bar from the server-rendered counts.
        updateProgress({
            verified: parseInt(elCountDone ? elCountDone.textContent : '0', 10) || 0,
            expected: parseInt(elCountTotal ? elCountTotal.textContent : '0', 10) || 0,
            complete: elCompleteBanner ? !elCompleteBanner.hidden : false
        });

        function markVerified(orderItemId, verification) {
            var row = elList ? elList.querySelector('[data-order-item-id="' + orderItemId + '"]') : null;
            if (!row) { return; }
            row.setAttribute('data-verified', '1');
            row.classList.add('is-verified');

            var action = row.querySelector('[data-manual-action]');
            if (action) { action.hidden = true; }

            var cell = row.querySelector('[data-status-cell]');
            if (!cell) { return; }
            cell.textContent = '';

            var badge = document.createElement('span');
            badge.className = 'badge badge-success';
            badge.textContent = 'Verified';
            cell.appendChild(badge);

            if (verification) {
                var detail = document.createElement('span');
                detail.className = 'verify-item__detail';
                var parts = [verification.method];
                if (verification.verified_by_name) { parts.push(verification.verified_by_name); }
                detail.textContent = parts.join(' \u00b7 ');
                cell.appendChild(detail);

                if (verification.method === 'MANUAL' && verification.reason) {
                    var reason = document.createElement('span');
                    reason.className = 'verify-item__reason';
                    reason.textContent = '\u201c' + verification.reason + '\u201d';
                    cell.appendChild(reason);
                }
            }
        }

        // ---- Scan / manual-entry verification ----
        function verifyBarcode(rawCode) {
            var barcode = String(rawCode == null ? '' : rawCode).trim();
            if (barcode === '') { return; }
            if (inFlight[barcode]) { return; }
            inFlight[barcode] = true;
            window.hopiaAdminSuccess.dismiss();

            var body = new URLSearchParams();
            body.set('csrf_token', CONFIG.csrf);
            body.set('order_id', String(CONFIG.orderId));
            body.set('barcode', barcode);

            fetch(CONFIG.scanUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body: body.toString()
            }).then(function (res) {
                return res.json().then(function (data) { return { status: res.status, data: data }; });
            }).then(function (result) {
                delete inFlight[barcode];
                var data = result.data || {};
                if (data.ok) {
                    markVerified(data.order_item_id, data.verification);
                    updateProgress(data.progress);
                    setStatus('', 'info');
                    window.hopiaAdminSuccess.show('Order item verified successfully (' + barcode + ').');
                    setCameraState('\u2713 Verified', 'ok');
                    setTimeout(function () { setCameraState('Point camera at next barcode', 'ready'); }, 2500);
                    if (navigator.vibrate) { navigator.vibrate(80); }
                    playSuccessBeep();
                } else {
                    setStatus(data.message || 'That item could not be verified.', 'error');
                    setCameraState('\u2715 ' + (data.message || 'Rejected'), 'warn');
                    setTimeout(function () { setCameraState('Point camera at barcode', 'ready'); }, 2500);
                }
            }).catch(function () {
                delete inFlight[barcode];
                setStatus('Could not reach the server. Please check your connection and try again.', 'error');
            });
        }

        elForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var value = elInput.value;
            elInput.value = '';
            elInput.focus();
            verifyBarcode(value);
        });

        // ---- Manual verify modal ----
        var elModal = document.getElementById('verify-modal');
        var elModalName = document.getElementById('verify-modal-item-name');
        var elModalForm = document.getElementById('verify-modal-form');
        var elModalConfirm = document.getElementById('verify-modal-confirm');
        var elModalReason = document.getElementById('verify-modal-reason');
        var elModalSubmit = document.getElementById('verify-modal-submit');
        var manualTargetId = null;
        var manualInFlight = false;

        function openManual(orderItemId, name) {
            manualTargetId = orderItemId;
            if (elModalName) { elModalName.textContent = name || ('Item #' + orderItemId); }
            if (elModalConfirm) { elModalConfirm.checked = false; }
            if (elModalReason) { elModalReason.value = ''; }
            if (elModal) { elModal.hidden = false; }
            if (elModalReason) { elModalReason.focus(); }
        }

        function closeManual() {
            manualTargetId = null;
            if (elModal) { elModal.hidden = true; }
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest ? event.target.closest('[data-manual-verify]') : null;
            if (trigger) {
                event.preventDefault();
                openManual(parseInt(trigger.getAttribute('data-order-item-id'), 10), trigger.getAttribute('data-name'));
                return;
            }
            if (event.target.closest && event.target.closest('[data-manual-cancel]')) {
                event.preventDefault();
                closeManual();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && elModal && !elModal.hidden) { closeManual(); }
        });

        if (elModalForm) {
            elModalForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (manualInFlight || manualTargetId === null) { return; }
                if (!elModalConfirm || !elModalConfirm.checked) {
                    setStatus('Please confirm you checked the physical garment.', 'error');
                    return;
                }
                var reason = elModalReason ? elModalReason.value.trim() : '';
                if (reason === '') {
                    setStatus('Enter a reason to manually verify this item.', 'error');
                    return;
                }

                manualInFlight = true;
                if (elModalSubmit) { elModalSubmit.disabled = true; elModalSubmit.textContent = 'Verifying\u2026'; }

                var body = new URLSearchParams();
                body.set('csrf_token', CONFIG.csrf);
                body.set('order_id', String(CONFIG.orderId));
                body.set('order_item_id', String(manualTargetId));
                body.set('reason', reason);

                var targetId = manualTargetId;

                fetch(CONFIG.manualUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                    body: body.toString()
                }).then(function (res) {
                    return res.json().then(function (data) { return { status: res.status, data: data }; });
                }).then(function (result) {
                    manualInFlight = false;
                    if (elModalSubmit) { elModalSubmit.disabled = false; elModalSubmit.textContent = 'Confirm Manual Verify'; }
                    var data = result.data || {};
                    if (data.ok) {
                        markVerified(data.order_item_id || targetId, data.verification);
                        updateProgress(data.progress);
                        setStatus('', 'info');
                        closeManual();
                        window.hopiaAdminSuccess.show('Order item manually verified successfully.');
                    } else {
                        setStatus(data.message || 'That item could not be verified.', 'error');
                    }
                }).catch(function () {
                    manualInFlight = false;
                    if (elModalSubmit) { elModalSubmit.disabled = false; elModalSubmit.textContent = 'Confirm Manual Verify'; }
                    setStatus('Could not reach the server. Please try again.', 'error');
                });
            });
        }

        // ---- Camera (reused Phase A techniques) ----
        function cameraSupported() {
            return typeof Html5Qrcode !== 'undefined'
                && navigator.mediaDevices
                && typeof navigator.mediaDevices.getUserMedia === 'function';
        }

        function secureContextOk() {
            if (window.isSecureContext) { return true; }
            var host = window.location.hostname;
            return host === 'localhost' || host === '127.0.0.1' || host === '::1';
        }

        function onScanSuccess(decodedText) {
            var now = Date.now();
            if (decodedText === lastScan.code && (now - lastScan.at) < 1500) { return; }
            lastScan = { code: decodedText, at: now };
            verifyBarcode(decodedText);
        }

        function probeCameraCaps(scanner) {
            if (torchSupported || !scanner) { return; }
            try {
                var caps = scanner.getRunningTrackCapabilities();
                if (caps && typeof caps.torch !== 'undefined') {
                    torchSupported = true;
                    elTorch.hidden = false;
                }
                if (caps && caps.zoom && typeof caps.zoom.min === 'number' && typeof caps.zoom.max === 'number' && caps.zoom.max > caps.zoom.min) {
                    zoomCaps = { min: caps.zoom.min, max: caps.zoom.max, current: 1.0 };
                    var currentZoom = 1.0;
                    try {
                        var settings = scanner.getRunningTrackSettings();
                        if (settings && typeof settings.zoom === 'number') { currentZoom = settings.zoom; }
                    } catch (e2) { /* keep default */ }
                    elZoom.min = zoomCaps.min;
                    elZoom.max = zoomCaps.max;
                    elZoom.step = Math.max(0.1, (zoomCaps.max - zoomCaps.min) / 20);
                    elZoom.value = Math.min(zoomCaps.max, Math.max(zoomCaps.min, currentZoom));
                    elZoomControl.hidden = false;
                }
            } catch (e) { /* capability probing unsupported */ }
        }

        function applyZoom(rawValue) {
            if (!zoomCaps || !cameraScanner) { return; }
            var val = parseFloat(rawValue);
            if (!isFinite(val)) { return; }
            zoomCaps.current = val;
            try { cameraScanner.applyVideoConstraints({ advanced: [{ zoom: val }] }); } catch (e) { /* ignore */ }
        }

        function toggleTorch() {
            if (!torchSupported || !cameraScanner) { return; }
            torchOn = !torchOn;
            try { cameraScanner.applyVideoConstraints({ advanced: [{ torch: torchOn }] }); } catch (e) { /* ignore */ }
            elTorch.textContent = 'Flashlight: ' + (torchOn ? 'On' : 'Off');
            elTorch.setAttribute('aria-pressed', torchOn ? 'true' : 'false');
            if (torchOn) { elReaderWrap.classList.add('is-flash'); } else { elReaderWrap.classList.remove('is-flash'); }
        }

        elTorch.addEventListener('click', toggleTorch);
        elZoom.addEventListener('input', function () { applyZoom(this.value); });
        elZoom.addEventListener('change', function () { applyZoom(this.value); });

        function startCamera() {
            if (cameraStarting || cameraRunning) { return; }
            getAudioCtx();
            if (!cameraSupported()) {
                setStatus('Camera scanning is not supported on this device or browser. You can still enter barcodes manually.', 'error');
                return;
            }
            if (!secureContextOk()) {
                setStatus('The camera needs a secure connection (HTTPS or localhost). Manual entry still works.', 'error');
                return;
            }

            elReaderOff.hidden = true;
            elReaderWrap.hidden = false;
            setCameraState('Starting camera\u2026', 'starting');
            elTorch.hidden = true;
            elZoomControl.hidden = true;

            try {
                cameraScanner = new Html5Qrcode('verify-reader', {
                    formatsToSupport: [Html5QrcodeSupportedFormats.CODE_128],
                    verbose: false
                });
            } catch (e) {
                cameraScanner = null;
                cameraStarting = false;
                setCameraState('Scanner init failed', 'error');
                setStatus('The scanner could not be initialized. Manual entry still works.', 'error');
                return;
            }

            cameraStarting = true;

            cameraScanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 280, height: 160 } },
                onScanSuccess,
                function () { /* per-frame no-decode: ignore */ }
            ).then(function () {
                cameraStarting = false;
                cameraRunning = true;
                elStart.hidden = true;
                elStop.hidden = false;
                setCameraState('Point camera at barcode', 'ready');
                setStatus('Camera started. Point it at a Code 128 barcode.', 'info');
                probeCameraCaps(cameraScanner);
            }).catch(function (err) {
                cameraStarting = false;
                cameraRunning = false;
                if (cameraScanner) {
                    try { cameraScanner.clear(); } catch (e2) { /* noop */ }
                    cameraScanner = null;
                }
                elReaderOff.hidden = false;
                elReaderWrap.hidden = true;
                elStart.hidden = false;
                elStop.hidden = true;
                var name = (err && err.name) ? err.name : '';
                var detail = (err && err.message) ? err.message : (typeof err === 'string' ? err : '');
                if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                    setStatus('Camera permission was denied. Allow camera access in your browser settings, or use manual entry.', 'error');
                } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                    setStatus('No camera was found on this device. Manual entry still works.', 'error');
                } else if (name === 'NotReadableError' || name === 'TrackStartError') {
                    setStatus('The camera is already in use by another app. Close it and try again, or use manual entry.', 'error');
                } else if (name === 'OverconstrainedError') {
                    setStatus('The requested camera settings are not supported by this device (' + name + '). Manual entry still works.', 'error');
                } else {
                    setStatus('The camera could not be started' + (detail ? ' (' + detail + ')' : '') + '. Manual entry still works.', 'error');
                }
            });
        }

        function stopCamera() {
            if (!cameraScanner || !cameraRunning) {
                elStart.hidden = false;
                elStop.hidden = true;
                elReaderOff.hidden = false;
                elReaderWrap.hidden = true;
                return;
            }
            torchSupported = false;
            torchOn = false;
            zoomCaps = null;
            cameraScanner.stop().then(function () {
                cameraRunning = false;
                try { cameraScanner.clear(); } catch (e) { /* noop */ }
                cameraScanner = null;
                elStart.hidden = false;
                elStop.hidden = true;
                elReaderOff.hidden = false;
                elReaderWrap.hidden = true;
                elTorch.hidden = true;
                elZoomControl.hidden = true;
                setCameraState('', '');
            }).catch(function () {
                elStart.hidden = false;
                elStop.hidden = true;
                elReaderOff.hidden = false;
                elReaderWrap.hidden = true;
            });
        }

        elStart.addEventListener('click', startCamera);
        elStop.addEventListener('click', stopCamera);

        if (!secureContextOk()) {
            elStart.disabled = true;
            setStatus('Camera scanning needs HTTPS or localhost. Manual and USB-scanner entry still work on this page.', 'info');
        }

        window.addEventListener('beforeunload', function () {
            if (cameraScanner && cameraRunning) {
                try { cameraScanner.stop(); } catch (e) { /* noop */ }
            }
        });

        elInput.focus();
    })();
    </script>

<?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
