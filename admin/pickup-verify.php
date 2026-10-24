<?php

/*
 * Hopia Fits - Phase 3 Store Pickup barcode-verification / preparation gate.
 *
 * Two modes:
 *   - No ?order_id : list Store Pickup orders whose effective pickup stage is
 *                    ORDER_RECEIVED (not cancelled, no pending/approved
 *                    cancellation).
 *   - ?order_id=N  : the verification workspace for one order. Every ordered
 *                    item must be scanned successfully before the
 *                    ORDER_RECEIVED -> PREPARING transition is allowed.
 *
 * All eligibility, progress, barcode resolution, and duplicate protection are
 * server-side (includes/pickup.php + includes/order_verify.php). This page
 * only presents them. Camera scanning reuses the existing html5-qrcode
 * implementation; a USB/keyboard-wedge scanner types into the same input.
 *
 * Scanning records ONLY an order_item_verifications row. It never changes
 * products.status, reserves inventory a second time, updates payment, or
 * creates a sale. The final preparation action uses the single existing
 * pickup lifecycle helper, which re-checks everything under a row lock.
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/pickup.php';
require_once __DIR__ . '/../includes/order_verify.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/admin-feedback.php';

$adminName = $_SESSION['admin_name'] ?? 'Admin';

/*
 * Stable per-session CSRF token for pickup scans and the final preparation
 * action. Kept separate from the one-shot admin form token and the courier
 * verification token so repeated scans never clobber them.
 */
if (empty($_SESSION['pickup_verify_csrf'])) {
    $_SESSION['pickup_verify_csrf'] = bin2hex(random_bytes(32));
}
$pickupVerifyCsrf = $_SESSION['pickup_verify_csrf'];

$paymentMethodLabels = [
    'CASH_ON_PICKUP' => 'Cash on Pickup',
    'GCASH' => 'GCash',
    'COD' => 'Cash on Delivery',
    'CARD' => 'Card',
];

$actionError = '';

$requestedOrderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);

/*
 * Final preparation action: POST-only, CSRF-checked, then delegated to the
 * shared helper. It follows post/redirect/get so a refresh cannot repeat the
 * transition.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hopia_admin_success_clear();
    $submittedToken = $_POST['csrf_token'] ?? '';
    $postedOrderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $postedAction = $_POST['action'] ?? '';

    $validCsrf = is_string($submittedToken)
        && $submittedToken !== ''
        && !empty($_SESSION['pickup_verify_csrf'])
        && hash_equals($_SESSION['pickup_verify_csrf'], $submittedToken);
    $validOrderId = $postedOrderId !== null && $postedOrderId !== false && $postedOrderId > 0;
    $validAction = is_string($postedAction) && $postedAction === 'start_preparing';

    /* Keep the workspace open on a rejected submission even if the GET
       parameter is missing from the form action. */
    if ($validOrderId && ($requestedOrderId === null || $requestedOrderId === false)) {
        $requestedOrderId = (int) $postedOrderId;
    }

    if (!$validCsrf || !$validOrderId || !$validAction) {
        $actionError = 'The preparation request was invalid. Please reload the page and try again.';
    } else {
        $result = pickup_verify_begin_preparing($pdo, (int) $postedOrderId);

        if (!empty($result['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'order-view.php?id=' . (int) $postedOrderId,
                'Pickup preparation started successfully. All items have been verified.'
            ));
            exit;
        }

        $actionError = $result['message'] ?? pickup_verify_message('error');
    }
}

$hasOrderParam = $requestedOrderId !== null;

$order = false;
$shipment = false;
$orderError = null;
$items = [];
$progress = ['expected' => 0, 'verified' => 0, 'remaining' => 0, 'complete' => false];
$blockingItems = [];
$eligibleOrders = [];

if ($hasOrderParam && $requestedOrderId !== false && $requestedOrderId > 0) {
    $orderStmt = $pdo->prepare(
        'SELECT o.id,
                o.status,
                o.cancellation_status,
                o.created_at,
                o.pickup_status,
                CONCAT(c.first_name, \' \', c.last_name) AS customer_name,
                c.email AS customer_email,
                c.phone AS customer_phone,
                p.payment_method,
                p.payment_status,
                p.gcash_reference,
                p.submitted_at
         FROM orders o
         INNER JOIN customers c ON c.id = o.customer_id
         LEFT JOIN payments p ON p.order_id = o.id
         WHERE o.id = :order_id
         LIMIT 1'
    );
    $orderStmt->execute([':order_id' => $requestedOrderId]);
    $order = $orderStmt->fetch();

    if ($order !== false) {
        $shipmentStmt = $pdo->prepare(
            'SELECT delivery_method FROM shipments WHERE order_id = :order_id LIMIT 1'
        );
        $shipmentStmt->execute([':order_id' => (int) $order['id']]);
        $shipment = $shipmentStmt->fetch();

        /* Re-check eligibility server-side; never trust the inbound link.
           Phase 5: an unverified/rejected GCash payment blocks the preparation
           workspace (the shared helper also re-checks under lock on POST). */
        $paymentForGate = null;
        if (($order['payment_method'] ?? null) === 'GCASH') {
            $paymentForGate = [
                'payment_method' => 'GCASH',
                'payment_status' => $order['payment_status'] ?? 'PENDING',
                'gcash_reference' => $order['gcash_reference'] ?? null,
                'submitted_at' => $order['submitted_at'] ?? null,
            ];
        }

        $orderError = pickup_verify_order_error($order, $shipment, $paymentForGate);

        if ($orderError === null) {
            $items = pickup_verify_item_rows($pdo, (int) $order['id']);
            $progress = pickup_verify_progress($pdo, (int) $order['id']);
            $blockingItems = pickup_verify_blocking_items($items);
        }
    }
} elseif (!$hasOrderParam) {
    /* List mode: only orders awaiting preparation entry. */
    $eligibleOrders = pickup_verify_eligible_orders($pdo);
}

$pageTitleBase = ($order !== false)
    ? 'Verify Pickup Order #' . (int) $order['id']
    : 'Scan Pickup Orders';

$page_title = $pageTitleBase . " - Admin - Hopia's Ukay-Ukay";
$page_description = "Scan and verify every item of a Store Pickup order before preparation.";
$ui_section = 'admin';
$ui_active = 'pickup-verify.php';
$body_class = 'admin-pickup-verify-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<style>
/* Scoped layout for the pickup verification page (matches admin/order-verify). */
.verify-grid { display: grid; gap: 1.5rem; grid-template-columns: 1fr; }
@media (min-width: 960px) { .verify-grid { grid-template-columns: 1fr 1fr; align-items: start; } }

.verify-progress { display: flex; align-items: baseline; gap: .6rem; flex-wrap: wrap; }
.verify-progress__value { font-size: 1.5rem; font-weight: 700; }
.verify-progress__bar { position: relative; height: 8px; border-radius: 999px; background: var(--admin-border, #11100e1a); overflow: hidden; margin-top: .5rem; }
.verify-progress__fill { position: absolute; inset: 0 auto 0 0; width: 0; background: var(--admin-success, #1a7f37); border-radius: 999px; transition: width .25s ease; }

.verify-complete { margin: 1rem 0 0; }
.verify-complete[hidden] { display: none; }

/* Camera (same techniques as admin/order-verify.php). */
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
.verify-item.is-verified { opacity: .9; }
.verify-badge-unscannable { display: inline-block; font-size: .72rem; letter-spacing: .03em; text-transform: uppercase; color: var(--admin-warning, #8a5a00); background: var(--admin-warning-bg, #8a5a0014); border: 1px solid var(--admin-warning-border, #8a5a0033); border-radius: 999px; padding: .1rem .5rem; margin-top: .3rem; }

/* Final preparation gate. */
.pickup-prepare-card { margin-top: 1.5rem; }
.pickup-prepare-actions { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-top: 1rem; }
.pickup-prepare-actions .btn[disabled] { cursor: not-allowed; opacity: .55; }
</style>

<div class="admin-pickup-verify">

<?php if (!$hasOrderParam): ?>

    <?php /* ======================= LIST MODE ======================= */ ?>
    <header class="admin-page-header">
        <div>
            <p class="admin-page-header__eyebrow">Store Pickup &middot; preparation</p>
            <h1>SCAN PICKUP ORDERS</h1>
            <p class="admin-page-header__copy">
                Welcome, <?= hopia_e($adminName) ?>. Select a Store Pickup order that is still at
                <strong>Order Received</strong>. Every ordered item must be scanned and verified before the
                order can move to <strong>Preparing for Pickup</strong>.
            </p>
        </div>
    </header>

    <?php if ($actionError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= hopia_e($actionError) ?></div>
    <?php endif; ?>

    <?php if (count($eligibleOrders) === 0): ?>
        <div class="empty-state">
            <p>No Store Pickup orders are currently awaiting preparation.</p>
            <p><a class="btn btn-primary" href="pickups.php">Go to Store Pickup</a></p>
        </div>
    <?php else: ?>
        <div class="admin-orders__summary">
            <p class="text-small muted">
                Showing <?= count($eligibleOrders) ?> Store Pickup order<?= count($eligibleOrders) === 1 ? '' : 's' ?>
                awaiting preparation. Orders with a pending or approved cancellation are not listed.
            </p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Placed</th>
                        <th scope="col">Items verified</th>
                        <th scope="col">Payment</th>
                        <th scope="col">Pickup stage</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eligibleOrders as $eligible): ?>
                        <?php
                        $eligibleId = (int) $eligible['id'];
                        $expected = (int) $eligible['expected'];
                        $verified = (int) $eligible['verified'];
                        $paymentMethod = isset($eligible['payment_method'])
                            ? ($paymentMethodLabels[$eligible['payment_method']] ?? (string) $eligible['payment_method'])
                            : 'No payment record';
                        ?>
                        <tr>
                            <td><strong>#<?= $eligibleId ?></strong></td>
                            <td><?= hopia_e($eligible['customer_name']) ?></td>
                            <td><?= hopia_e(date('M j, Y g:i A', strtotime((string) $eligible['created_at']))) ?></td>
                            <td>
                                <span class="badge <?= ($expected > 0 && $verified >= $expected) ? 'badge-success' : 'badge-pending' ?>">
                                    <?= $verified ?>/<?= $expected ?>
                                </span>
                                <?php if ($expected === 0): ?>
                                    <span class="verify-item__detail">No items recorded</span>
                                <?php endif; ?>
                            </td>
                            <td><?= hopia_e($paymentMethod) ?></td>
                            <td>
                                <span class="badge <?= hopia_e(pickup_stage_badge_class('ORDER_RECEIVED')) ?>">
                                    <?= hopia_e(pickup_stage_label('ORDER_RECEIVED')) ?>
                                </span>
                            </td>
                            <td>
                                <a class="btn btn-primary btn-sm" href="pickup-verify.php?order_id=<?= $eligibleId ?>">Verify Items</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($order === false): ?>

    <?php /* ======================= NOT FOUND ======================= */ ?>
    <div class="admin-order-detail__empty">
        <p class="admin-page-header__eyebrow">Store Pickup &middot; preparation</p>
        <h1>ORDER NOT FOUND</h1>
        <p>Order not found. The order ID may be missing, invalid, or the order may no longer exist.</p>
        <a class="btn btn-primary" href="pickup-verify.php">Back to Scan Pickup Orders</a>
    </div>

<?php elseif ($orderError !== null): ?>

    <?php /* =================== LOADED BUT INELIGIBLE =================== */ ?>
    <header class="admin-page-header">
        <div>
            <a class="admin-page-header__back" href="pickup-verify.php">&larr; Back to Scan Pickup Orders</a>
            <p class="admin-page-header__eyebrow">Store Pickup &middot; preparation</p>
            <h1>PICKUP ORDER #<?= (int) $order['id'] ?></h1>
        </div>
        <span class="badge">Store Pickup</span>
    </header>

    <div class="alert alert-error" role="alert">
        <?= hopia_e(pickup_verify_message($orderError)) ?>
    </div>

    <p>
        <a class="btn btn-secondary" href="order-view.php?id=<?= (int) $order['id'] ?>">Open order details</a>
        <a class="btn btn-ghost" href="pickup-verify.php">Back to Scan Pickup Orders</a>
    </p>

<?php else: ?>

    <?php /* ==================== VERIFICATION MODE ==================== */ ?>
    <?php
    $orderIdInt = (int) $order['id'];
    $customerPhone = trim((string) ($order['customer_phone'] ?? ''));
    $paymentMethodRaw = (string) ($order['payment_method'] ?? '');
    $paymentMethodLabel = $paymentMethodRaw !== ''
        ? ($paymentMethodLabels[$paymentMethodRaw] ?? $paymentMethodRaw)
        : 'No payment record';
    $progressPercent = $progress['expected'] > 0
        ? (int) round(($progress['verified'] / $progress['expected']) * 100)
        : 0;
    ?>
    <header class="admin-page-header">
        <div>
            <a class="admin-page-header__back" href="pickup-verify.php">&larr; Back to Scan Pickup Orders</a>
            <p class="admin-page-header__eyebrow">Store Pickup &middot; verify before preparation</p>
            <h1>VERIFY PICKUP ORDER #<?= $orderIdInt ?></h1>
            <p class="admin-page-header__copy">
                Scan each item's barcode to confirm it is present for this pickup. Every item must be verified
                before the order can move to Preparing for Pickup.
            </p>
        </div>
        <div class="admin-order-detail__header-status" aria-label="Order statuses">
            <span class="badge">Store Pickup</span>
            <span class="badge <?= hopia_e(pickup_stage_badge_class('ORDER_RECEIVED')) ?>">
                <?= hopia_e(pickup_stage_label('ORDER_RECEIVED')) ?>
            </span>
        </div>
    </header>

    <?php if ($actionError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= hopia_e($actionError) ?></div>
    <?php endif; ?>

    <section class="card" aria-labelledby="pickup-verify-summary-title" style="margin-bottom:1.5rem;">
        <div class="card-body">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-section-heading__eyebrow">Order</p>
                    <h2 id="pickup-verify-summary-title">Customer &amp; progress</h2>
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
                <div>
                    <dt>Payment method</dt>
                    <dd><?= hopia_e($paymentMethodLabel) ?></dd>
                </div>
                <div>
                    <dt>Placed</dt>
                    <dd><?= hopia_e(date('M j, Y g:i A', strtotime((string) $order['created_at']))) ?></dd>
                </div>
            </dl>

            <div class="verify-progress" style="margin-top:1rem;">
                <span class="verify-progress__value">
                    Verified <span id="pickup-count-done"><?= (int) $progress['verified'] ?></span>
                    of <span id="pickup-count-total"><?= (int) $progress['expected'] ?></span> item<?= (int) $progress['expected'] === 1 ? '' : 's' ?>
                </span>
                <span class="badge <?= $progress['complete'] ? 'badge-success' : 'badge-pending' ?>" id="pickup-progress-badge">
                    <?= $progress['complete'] ? 'All verified' : 'In progress' ?>
                </span>
            </div>
            <div class="verify-progress__bar" aria-hidden="true">
                <div class="verify-progress__fill" id="pickup-progress-fill" style="width: <?= $progressPercent ?>%;"></div>
            </div>

            <div class="alert alert-success verify-complete" id="pickup-complete-banner" role="status" <?= $progress['complete'] ? '' : 'hidden' ?>>
                Every item is verified. You can start preparing this pickup below.
            </div>

            <?php if (count($blockingItems) > 0): ?>
                <div class="alert alert-warning" role="alert">
                    <strong>Preparation is blocked by an item without a usable barcode.</strong>
                    <p>
                        This item has no barcode assigned and cannot be scanned yet:
                        <?= hopia_e(implode(', ', $blockingItems)) ?>.
                        Assign a barcode to the product before preparing this pickup. Nothing is marked verified
                        automatically.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div id="pickup-status" class="verify-status" role="status" aria-live="polite"></div>

    <div class="verify-grid">
        <section class="card" aria-labelledby="pickup-scan-title">
            <div class="card-body">
                <div class="admin-section-heading">
                    <div>
                        <p class="admin-section-heading__eyebrow">Scan</p>
                        <h2 id="pickup-scan-title">Scan or enter a barcode</h2>
                    </div>
                </div>

                <form id="pickup-scan-form" class="verify-manual-entry" autocomplete="off">
                    <div class="field">
                        <label for="pickup-barcode-input">Barcode</label>
                        <input
                            type="text"
                            id="pickup-barcode-input"
                            name="barcode"
                            class="input"
                            inputmode="text"
                            autocapitalize="characters"
                            placeholder="e.g. HOPEBUNDLE-000005-01"
                            aria-describedby="pickup-input-hint"
                        >
                    </div>
                    <button type="submit" class="btn btn-primary">Verify Item</button>
                </form>
                <p id="pickup-input-hint" class="hint">
                    Works with a USB/Bluetooth scanner, the camera below, or manual typing. A scan only verifies
                    an item that belongs to this order.
                </p>

                <div class="verify-controls">
                    <button type="button" id="pickup-camera-start" class="btn btn-secondary btn-sm">Start Camera</button>
                    <button type="button" id="pickup-camera-stop" class="btn btn-ghost btn-sm" hidden>Stop Camera</button>
                    <button type="button" id="pickup-torch" class="btn btn-secondary btn-sm" hidden aria-pressed="false">Flashlight: Off</button>
                    <label id="pickup-zoom-control" class="verify-zoom" hidden>
                        <span>Zoom</span>
                        <input type="range" id="pickup-zoom" aria-label="Camera zoom">
                    </label>
                </div>

                <div id="pickup-reader-off" class="verify-reader-frame">
                    Camera is off. Press <strong>&nbsp;Start Camera&nbsp;</strong> to scan with your device camera,
                    or keep using manual entry.
                </div>

                <div id="pickup-reader-wrap" class="verify-reader-wrap" hidden>
                    <div id="pickup-reader" class="verify-reader"></div>
                    <p class="verify-reader__hint">
                        Hold steady, 10&ndash;15&nbsp;cm away. Fit the <strong>entire</strong> barcode inside the
                        frame, including its outer bars.
                    </p>
                    <p class="verify-reader__state" id="pickup-camera-state" role="status" data-kind="starting">Starting camera&hellip;</p>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="pickup-items-title">
            <div class="card-body">
                <div class="admin-section-heading">
                    <div>
                        <p class="admin-section-heading__eyebrow">Checklist</p>
                        <h2 id="pickup-items-title">Expected items (<?= count($items) ?>)</h2>
                    </div>
                </div>

                <?php if (count($items) === 0): ?>
                    <p class="muted">No items are recorded for this order, so it cannot be prepared for pickup.</p>
                <?php else: ?>
                    <div id="pickup-item-list">
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
                                        <span class="verify-badge-unscannable">No barcode assigned &ndash; cannot be scanned</span>
                                    <?php endif; ?>
                                </div>

                                <div class="verify-item__status" data-status-cell>
                                    <?php if ($verified): ?>
                                        <span class="badge badge-success">Verified</span>
                                        <span class="verify-item__detail">
                                            <?= hopia_e((string) $item['method']) ?><?php
                                                if (!empty($item['verified_at'])) {
                                                    echo ' &middot; ' . hopia_e(date('M j, g:i A', strtotime((string) $item['verified_at'])));
                                                }
                                                if (!empty($item['verified_by_name'])) {
                                                    echo ' &middot; ' . hopia_e($item['verified_by_name']);
                                                }
                                            ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">Unverified</span>
                                        <?php if ($scannable): ?>
                                            <span class="verify-item__detail">Scan its barcode</span>
                                        <?php else: ?>
                                            <span class="verify-item__detail">Blocking: no barcode assigned</span>
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

    <section class="card pickup-prepare-card" aria-labelledby="pickup-prepare-title">
        <div class="card-body">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-section-heading__eyebrow">Preparation gate</p>
                    <h2 id="pickup-prepare-title">Start Preparing for Pickup</h2>
                </div>
            </div>

            <p>
                This action is available only after every item has been scanned and verified. The server
                re-checks the order's eligibility, cancellation state, and complete verification state before
                changing the pickup stage.
            </p>

            <?php if ($progress['expected'] === 0): ?>
                <div class="alert alert-error" role="alert">
                    This order has no recorded items, so it cannot be prepared for pickup.
                </div>
            <?php endif; ?>

            <form method="POST" action="pickup-verify.php?order_id=<?= $orderIdInt ?>">
                <input type="hidden" name="csrf_token" value="<?= hopia_e($pickupVerifyCsrf) ?>">
                <input type="hidden" name="order_id" value="<?= $orderIdInt ?>">
                <input type="hidden" name="action" value="start_preparing">
                <div class="pickup-prepare-actions">
                    <button
                        class="btn btn-primary"
                        type="submit"
                        id="pickup-start-preparing"
                        <?= $progress['complete'] ? '' : 'disabled' ?>
                    >
                        Start Preparing for Pickup
                    </button>
                    <span class="text-small muted" id="pickup-prepare-hint">
                        <?php if ((int) $progress['expected'] === 0): ?>
                            No items are recorded for this order.
                        <?php elseif ($progress['complete']): ?>
                            All items are verified. You can start preparing this pickup.
                        <?php else: ?>
                            <?= (int) $progress['remaining'] ?> item<?= (int) $progress['remaining'] === 1 ? '' : 's' ?> still unverified.
                        <?php endif; ?>
                    </span>
                </div>
            </form>
        </div>
    </section>

    <script src="<?= hopia_e(hopia_asset('js/vendor/html5-qrcode/html5-qrcode.min.js')) ?>"></script>
    <script>
    (function () {
        'use strict';

        var CONFIG = {
            csrf: <?= json_encode($pickupVerifyCsrf) ?>,
            orderId: <?= json_encode($orderIdInt) ?>,
            scanUrl: 'api/pickup-verify-scan.php'
        };

        var elStatus = document.getElementById('pickup-status');
        var elForm = document.getElementById('pickup-scan-form');
        var elInput = document.getElementById('pickup-barcode-input');
        var elList = document.getElementById('pickup-item-list');
        var elCountDone = document.getElementById('pickup-count-done');
        var elCountTotal = document.getElementById('pickup-count-total');
        var elProgressFill = document.getElementById('pickup-progress-fill');
        var elProgressBadge = document.getElementById('pickup-progress-badge');
        var elCompleteBanner = document.getElementById('pickup-complete-banner');
        var elStartPreparing = document.getElementById('pickup-start-preparing');
        var elPrepareHint = document.getElementById('pickup-prepare-hint');

        var elStart = document.getElementById('pickup-camera-start');
        var elStop = document.getElementById('pickup-camera-stop');
        var elReaderOff = document.getElementById('pickup-reader-off');
        var elReaderWrap = document.getElementById('pickup-reader-wrap');
        var elCameraState = document.getElementById('pickup-camera-state');
        var elTorch = document.getElementById('pickup-torch');
        var elZoomControl = document.getElementById('pickup-zoom-control');
        var elZoom = document.getElementById('pickup-zoom');

        var inFlight = {};
        var cameraScanner = null;
        var cameraRunning = false;
        var cameraStarting = false;
        var lastScan = { code: '', at: 0 };
        var torchSupported = false;
        var torchOn = false;
        var zoomCaps = null;
        var audioCtx = null;
        var scanningDisabled = false;

        function getAudioCtx() {
            if (audioCtx) { return audioCtx; }
            try {
                var Ctor = window.AudioContext || window.webkitAudioContext;
                if (!Ctor) { return null; }
                audioCtx = new Ctor();
            } catch (e) { audioCtx = null; }
            return audioCtx;
        }

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
            if (!elStatus) { return; }
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

        function disableScanning(message) {
            scanningDisabled = true;
            if (elInput) { elInput.disabled = true; }
            if (elStartPreparing) { elStartPreparing.disabled = true; }
            if (elStart) { elStart.disabled = true; }
            setStatus(message, 'error');
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
            if (elStartPreparing) { elStartPreparing.disabled = !progress.complete || scanningDisabled; }
            if (elPrepareHint) {
                if (Number(progress.expected || 0) === 0) {
                    elPrepareHint.textContent = 'No items are recorded for this order.';
                } else if (progress.complete) {
                    elPrepareHint.textContent = 'All items are verified. You can start preparing this pickup.';
                } else {
                    var remaining = Math.max(0, (progress.expected || 0) - (progress.verified || 0));
                    elPrepareHint.textContent = remaining + ' item' + (remaining === 1 ? '' : 's') + ' still unverified.';
                }
            }
        }

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
                var parts = [verification.method || 'SCANNED'];
                if (verification.verified_by_name) { parts.push(verification.verified_by_name); }
                detail.textContent = parts.join(' \u00b7 ');
                cell.appendChild(detail);
            }
        }

        function verifyBarcode(rawCode) {
            if (scanningDisabled) { return; }
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
                    window.hopiaAdminSuccess.show('Pickup item verified successfully (' + barcode + ').');
                    setCameraState('\u2713 Verified', 'ok');
                    setTimeout(function () { setCameraState('Point camera at next barcode', 'ready'); }, 2500);
                    if (navigator.vibrate) { navigator.vibrate(80); }
                    playSuccessBeep();
                    return;
                }

                var fatalReasons = {
                    order_not_found: 1,
                    not_pickup_order: 1,
                    order_cancelled: 1,
                    cancellation_locked: 1,
                    stage_not_order_received: 1,
                    stage_mismatch: 1
                };

                if (fatalReasons[data.reason]) {
                    disableScanning((data.message || 'This order is no longer eligible for pickup preparation.') + ' Reload the page to review the order.');
                    setCameraState('\u2715 Not eligible', 'error');
                    return;
                }

                setStatus(data.message || 'That item could not be verified.', 'error');
                setCameraState('\u2715 ' + (data.message || 'Rejected'), 'warn');
                setTimeout(function () { setCameraState('Point camera at barcode', 'ready'); }, 2500);
            }).catch(function () {
                delete inFlight[barcode];
                setStatus('Could not reach the server. Please check your connection and try again.', 'error');
            });
        }

        if (elForm) {
            elForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var value = elInput ? elInput.value : '';
                if (elInput) { elInput.value = ''; elInput.focus(); }
                verifyBarcode(value);
            });
        }

        // ---- Camera (reuses the existing html5-qrcode implementation) ----
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

        if (elTorch) { elTorch.addEventListener('click', toggleTorch); }
        if (elZoom) {
            elZoom.addEventListener('input', function () { applyZoom(this.value); });
            elZoom.addEventListener('change', function () { applyZoom(this.value); });
        }

        function startCamera() {
            if (cameraStarting || cameraRunning || scanningDisabled) { return; }
            getAudioCtx();
            if (!cameraSupported()) {
                setStatus('Camera scanning is not supported on this device or browser. You can still enter barcodes manually or use a USB scanner.', 'error');
                return;
            }
            if (!secureContextOk()) {
                setStatus('The camera needs a secure connection (HTTPS or localhost). Manual entry and USB scanning still work on this page.', 'error');
                return;
            }

            elReaderOff.hidden = true;
            elReaderWrap.hidden = false;
            setCameraState('Starting camera\u2026', 'starting');
            elTorch.hidden = true;
            elZoomControl.hidden = true;

            try {
                cameraScanner = new Html5Qrcode('pickup-reader', {
                    formatsToSupport: [Html5QrcodeSupportedFormats.CODE_128],
                    verbose: false
                });
            } catch (e) {
                cameraScanner = null;
                cameraStarting = false;
                setCameraState('Scanner init failed', 'error');
                setStatus('The scanner could not be initialized. Manual entry and USB scanning still work.', 'error');
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
                    setStatus('Camera permission was denied. Check your browser camera permissions for this site, then try again, or use the manual barcode input / USB scanner.', 'error');
                } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                    setStatus('No camera was found on this device. Manual entry and USB scanning still work.', 'error');
                } else if (name === 'NotReadableError' || name === 'TrackStartError') {
                    setStatus('The camera is already in use by another app. Close it and try again, or use manual entry / USB scanning.', 'error');
                } else if (name === 'OverconstrainedError') {
                    setStatus('The requested camera settings are not supported by this device (' + name + '). Manual entry and USB scanning still work.', 'error');
                } else {
                    setStatus('The camera could not be started' + (detail ? ' (' + detail + ')' : '') + '. Check browser camera permissions or use the manual barcode input / USB scanner.', 'error');
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

        if (elStart) { elStart.addEventListener('click', startCamera); }
        if (elStop) { elStop.addEventListener('click', stopCamera); }

        if (!secureContextOk()) {
            if (elStart) { elStart.disabled = true; }
            setStatus('Camera scanning needs HTTPS or localhost. Manual entry and USB-scanner entry still work on this page.', 'info');
        }

        window.addEventListener('beforeunload', function () {
            if (cameraScanner && cameraRunning) {
                try { cameraScanner.stop(); } catch (e) { /* noop */ }
            }
        });

        if (elInput) { elInput.focus(); }
    })();
    </script>

<?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
