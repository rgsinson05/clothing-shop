<?php

/*
 * Hopia Fits - Admin Store Pickup section (Phase 3).
 *
 * Dedicated admin page listing ONLY Store Pickup orders with their pickup
 * lifecycle, pickup deadline, and payment state, plus the controls to
 * advance the lifecycle. It reuses the existing order-management building
 * blocks instead of duplicating them:
 *   - the single transition path includes/pickup.php pickup_advance_status()
 *     (the same helper admin/order-view.php uses),
 *   - the existing admin CSRF token convention,
 *   - links into the existing order-view.php detail/payment-verification
 *     page (GCash confirmation/rejection stay there).
 *
 * The list makes READY_FOR_PICKUP orders easy to spot (they sort first and
 * carry a highlighted badge) and flags overdue pickups WITHOUT any
 * automatic cancellation: passing the 48h deadline is a display-only
 * attention signal here.
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/admin-feedback.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/pickup.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/order_queues.php';
require_once __DIR__ . '/../includes/admin_order_ui.php';

$adminName = $_SESSION['admin_name'] ?? 'Admin';

/*
 * Separate CSRF token for admin state-changing forms (same convention as
 * admin/order-view.php).
 */
if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$adminCsrfToken = $_SESSION['admin_csrf_token'];

/*
 * Advance action. POST-only, CSRF-checked, executed through the shared
 * transactional helper. It follows the post/redirect/get pattern so a
 * refresh never repeats the transition.
 */
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hopia_admin_success_clear();

    $submittedCsrfToken = $_POST['csrf_token'] ?? '';
    $postedOrderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $postedAction = $_POST['action'] ?? '';

    $validCsrf = is_string($submittedCsrfToken)
        && $submittedCsrfToken !== ''
        && !empty($_SESSION['admin_csrf_token'])
        && hash_equals($_SESSION['admin_csrf_token'], $submittedCsrfToken);
    $validOrderId = $postedOrderId !== null
        && $postedOrderId !== false
        && $postedOrderId > 0;
    $validAction = is_string($postedAction)
        && in_array($postedAction, ['advance_pickup', 'collect_cash_on_pickup', 'confirm_pickup_record_cash', 'confirm_pickup_gcash_complete_sale'], true);
    $paymentRevision = filter_input(INPUT_POST, 'payment_revision', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $needsPaymentRevision = is_string($postedAction)
    && in_array($postedAction, ['confirm_pickup_record_cash', 'confirm_pickup_gcash_complete_sale'], true);
    $validRevision = !$needsPaymentRevision
        || ($paymentRevision !== null && $paymentRevision !== false);

    if (!$validCsrf || !$validOrderId || !$validAction || !$validRevision) {
        $actionError = 'The pickup status could not be updated. Please try again.';
    } elseif ($postedAction === 'advance_pickup') {
        $result = pickup_advance_status($pdo, (int) $postedOrderId);

        if (!empty($result['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'pickups.php',
                'Order #' . (int) $postedOrderId . ' pickup status is now ' . pickup_stage_label($result['stage']) . '.'
            ));
            exit;
        }

        $actionError = $result['message'] ?? 'The pickup status could not be updated. Please try again.';
    } elseif ($postedAction === 'confirm_pickup_record_cash') {
        /* Phase 9: ONE atomic action that records the cash collection,
           confirms the handover, and creates the canonical completed sale.
           The helper owns the transaction and revalidates every condition
           under row locks. */
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $result = payment_confirm_pickup_and_collect_cash(
            $pdo, $adminId, (int) $postedOrderId, (int) $paymentRevision
        );

        if (!empty($result['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'pickups.php',
                'Pickup handover confirmed and cash collection recorded for Order #' . (int) $postedOrderId . '. The completed sale has been recorded in Sales Monitoring.'
            ));
            exit;
        }

        $actionError = $result['message'] ?? payment_message('cop_error');
    } elseif ($postedAction === 'confirm_pickup_gcash_complete_sale') {
        /* Phase 10: ONE atomic action that confirms the Store Pickup handover
           and creates the canonical completed sale for a verified GCash order
           at Ready for Pickup. The GCash payment is already verified/paid, so
           this action does NOT collect cash or re-verify the payment. The
           helper owns the transaction and revalidates every condition under
           row locks. */
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $result = payment_confirm_pickup_gcash_and_complete_sale(
            $pdo, $adminId, (int) $postedOrderId, (int) $paymentRevision
        );

        if (!empty($result['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'pickups.php',
                'Pickup handover confirmed for Order #' . (int) $postedOrderId . '. The completed GCash sale has been recorded in Sales Monitoring; no cash was collected.'
            ));
            exit;
        }

        $actionError = $result['message'] ?? payment_message('pg_error');
    } else {
        /* collect_cash_on_pickup: the legacy explicit collection path for a
           Cash on Pickup order that was already handed over. Separate from
           the handover; the admin submits it only after the cash is in hand. */
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $result = payment_collect_cash_on_pickup($pdo, $adminId, (int) $postedOrderId);

        if (!empty($result['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'pickups.php',
                'Cash on Pickup payment for Order #' . (int) $postedOrderId . ' has been recorded as collected.'
            ));
            exit;
        }

        $actionError = $result['message'] ?? 'The cash collection could not be recorded. Please try again.';
    }
}

/*
 * Allowlisted pickup filters. The dashboard cards open this page with a
 * queue key from includes/order_queues.php; ?stage=<stage> is also supported
 * for direct links. Unknown values are ignored and fall back to the full
 * Store Pickup list, never interpolated into SQL.
 */
$pickupListFilters = order_list_filter_definitions('pickups');

$requestedQueue = isset($_GET['queue']) && is_string($_GET['queue']) ? $_GET['queue'] : '';
$queueFilter = isset($pickupListFilters[$requestedQueue]) && $requestedQueue !== 'all'
    ? $requestedQueue
    : null;

$requestedStage = isset($_GET['stage']) && is_string($_GET['stage'])
    ? strtoupper(trim($_GET['stage']))
    : '';
$stageFilter = in_array($requestedStage, pickup_stages(), true) ? $requestedStage : null;

$pickupWhere = '';
$pickupParams = [];
$activePickupFilterDefinition = null;

if ($queueFilter !== null) {
    $filterWhere = order_list_filter_where('pickups', $queueFilter);

    if ($filterWhere !== null) {
        $pickupWhere = $filterWhere['sql'];
        $pickupParams = $filterWhere['params'];
    }

    $activePickupFilterDefinition = $pickupListFilters[$queueFilter] ?? null;
} elseif ($stageFilter !== null) {
    /*
     * Direct stage links remain supported. Cancelled orders and orders with a
     * pending/approved cancellation are not part of an operational pickup
     * stage, matching the shared queue rules above.
     */
    $pickupWhere = "COALESCE(o.pickup_status, 'ORDER_RECEIVED') = :stage"
        . " AND o.status <> 'CANCELLED'"
        . " AND o.cancellation_status NOT IN ('REQUESTED', 'APPROVED')";
    $pickupParams[':stage'] = $stageFilter;
}

$hasActivePickupFilter = $queueFilter !== null || $stageFilter !== null;
$activePickupFilterLabel = 'All Store Pickup orders';
if ($activePickupFilterDefinition !== null) {
    $activePickupFilterLabel = (string) $activePickupFilterDefinition['label'];
} elseif ($stageFilter !== null) {
    $activePickupFilterLabel = pickup_stage_label($stageFilter);
}

/* The selected tab. A direct ?stage= link (kept for compatibility) has no
   matching tab, so no tab is highlighted in that case. */
$activePickupFilterKey = $queueFilter;
if ($activePickupFilterKey === null && $stageFilter === null) {
    $activePickupFilterKey = 'all';
}

/*
 * Global pickup summary (always unfiltered) for the KPI cards. Filtering the
 * list must not silently change the meaning of the summary figures.
 */
$allPickupOrders = pickup_admin_list($pdo);
$allProgressByOrder = [];
foreach ($allPickupOrders as $row) {
    $progress = pickup_progress($row, ['delivery_method' => 'STORE_PICKUP']);
    if ($progress !== null) {
        $allProgressByOrder[(int) $row['id']] = $progress;
    }
}

/*
 * The filtered pickup list + derived progress (stage, overdue) per order.
 */
$pickupOrders = pickup_admin_list($pdo, $pickupWhere, $pickupParams);
$pickupListDetails = admin_order_list_details($pdo, array_column($pickupOrders, 'id'));

$progressByOrder = [];
foreach ($pickupOrders as $row) {
    $progress = pickup_progress($row, ['delivery_method' => 'STORE_PICKUP']);
    if ($progress !== null) {
        $progressByOrder[(int) $row['id']] = $progress;
    }
}

$paymentMethodLabels = [
    'COD' => 'Cash on Delivery',
    'GCASH' => 'GCash',
    'CARD' => 'Card',
    'CASH_ON_PICKUP' => 'Cash on Pickup',
];

$paymentStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'PAID' => 'badge-success',
    'FAILED' => 'badge-cancelled',
    'REFUNDED' => 'badge-warning',
];

$paymentStatusLabels = [
    'PENDING' => 'Pending',
    'PAID' => 'Paid',
    'FAILED' => 'Failed',
    'REFUNDED' => 'Refunded',
];

/*
 * KPI card icons (same line-art language as the admin nav). Trusted static
 * inline SVG; returns '' for an unknown key.
 */
function pickup_admin_icon(string $name): string
{
    $paths = [
        'items' => '<path d="M9 4 4.6 6.7a1 1 0 0 0-.4 1.2L5.5 11l2.5-.9V20h8v-9.9l2.5.9 1.3-3.1a1 1 0 0 0-.4-1.2L15 4a3 3 0 0 1-6 0z"/>',
        'store' => '<path d="M4 10.5V20h16v-9.5"/><path d="M3 10.5 4.5 5h15L21 10.5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"/><path d="M10 20v-4.5h4V20"/>',
        'receipt' => '<path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21V3Z"/><path d="M9.5 8h5"/><path d="M9.5 12h5"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $paths[$name] . '</svg>';
}

$readyCount = 0;
$overdueCount = 0;
foreach ($allProgressByOrder as $progress) {
    if ($progress['stage'] === 'READY_FOR_PICKUP') {
        $readyCount++;
        if ($progress['overdue']) {
            $overdueCount++;
        }
    }
}

$page_title = "Store Pickup - Admin - Hopia's Ukay-Ukay";
$page_description = "Manage Store Pickup orders.";
$ui_section = 'admin';
$ui_active = 'pickups.php';
$body_class = 'admin-pickups-page';
$ui_admin_order_list = true;

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<div class="admin-pickups om-page">
    <header class="admin-page-header">
        <div>
            <p class="admin-page-header__eyebrow">Order management</p>
            <h1>Store Pickup</h1>
            <p class="admin-page-header__copy">
                Welcome, <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>. Store Pickup orders, their pickup
                lifecycle, 48-hour pickup window, and payment state. Courier orders stay on the Online Orders page.
            </p>
        </div>
    </header>

    <?php if ($actionError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="dash-kpis" aria-label="Pickup summary">
        <article class="dash-kpi dash-kpi--orders">
            <div class="dash-kpi__top">
                <span class="dash-kpi__label">Store Pickup orders</span>
                <span class="dash-kpi__icon"><?= pickup_admin_icon('items') ?></span>
            </div>
            <p class="dash-kpi__value"><?= count($allPickupOrders) ?></p>
            <p class="dash-kpi__sub">All pickup orders, newest first.</p>
            <span class="dash-kpi__bar"></span>
        </article>
        <article class="dash-kpi dash-kpi--sales">
            <div class="dash-kpi__top">
                <span class="dash-kpi__label">Ready for pickup</span>
                <span class="dash-kpi__icon"><?= pickup_admin_icon('store') ?></span>
            </div>
            <p class="dash-kpi__value"><?= (int) $readyCount ?></p>
            <p class="dash-kpi__sub">Waiting for the customer to collect.</p>
            <span class="dash-kpi__bar"></span>
        </article>
        <article class="dash-kpi dash-kpi--profit">
            <div class="dash-kpi__top">
                <span class="dash-kpi__label">Overdue pickups</span>
                <span class="dash-kpi__icon"><?= pickup_admin_icon('receipt') ?></span>
            </div>
            <p class="dash-kpi__value"><?= (int) $overdueCount ?></p>
            <p class="dash-kpi__sub">Past the 48-hour window. Flagged for attention &mdash; never auto-cancelled.</p>
            <span class="dash-kpi__bar"></span>
        </article>
    </section>

    <?php admin_order_filter_nav('pickups', $pickupListFilters, $activePickupFilterKey, '', null,
        $queueFilter === null && $stageFilter !== null ? ['name' => 'stage', 'value' => $stageFilter, 'label' => pickup_stage_label($stageFilter)] : null); ?>

    <?php if (count($pickupOrders) === 0): ?>

        <div class="empty-state">
            <p>
                <?= $hasActivePickupFilter
                    ? 'No Store Pickup orders match this filter.'
                    : 'No Store Pickup orders have been placed yet.' ?>
            </p>
            <?php if ($hasActivePickupFilter): ?>
                <p><a href="pickups.php">Show all Store Pickup orders</a></p>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div class="admin-pickups__summary">
            <p class="text-small muted">
                Showing <?= count($pickupOrders) ?> Store Pickup order<?= count($pickupOrders) === 1 ? '' : 's' ?><?php if ($hasActivePickupFilter): ?>
                    &mdash; <?= htmlspecialchars($activePickupFilterLabel, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>.
                Ready-for-pickup orders are listed first.
                <?php if ($hasActivePickupFilter): ?>
                    &middot; <a href="pickups.php">Clear filter</a>
                <?php endif; ?>
            </p>
        </div>

        <?php $desktopPickupRows = []; ?>
        <div class="admin-pickup-list om-cards" data-order-card-section="store-pickup">
            <?php foreach ($pickupOrders as $row): ?>
                <?php
                $orderId = (int) $row['id'];
                $progress = $progressByOrder[$orderId] ?? null;

                if ($progress === null) {
                    continue;
                }

                $stage = $progress['stage'];
                $isCancelled = $progress['cancelled'];
                $cancellationStatus = (string) ($row['cancellation_status'] ?? 'NONE');
                $isCancellationLocked = in_array($cancellationStatus, ['REQUESTED', 'APPROVED'], true);
                $nextStage = pickup_next_stage($stage);
                $nextStageLabel = $nextStage !== null ? pickup_stage_label($nextStage) : '';

                /*
                 * Phase 5 payment state. A GCash order whose payment is not
                 * verified may not enter preparation, become ready, or be
                 * handed over. The same gate is enforced under lock by
                 * pickup_advance_status(), so hiding the button is only a
                 * convenience.
                 */
                $gcashState = 'NA';
                $gcashFulfillmentBlock = null;
                if (($row['payment_method'] ?? '') === 'GCASH') {
                    $gcashPaymentRow = [
                        'payment_method' => 'GCASH',
                        'payment_status' => $row['payment_status'] ?? 'PENDING',
                        'gcash_reference' => $row['gcash_reference'] ?? null,
                        'submitted_at' => $row['submitted_at'] ?? null,
                    ];
                    $gcashState = payment_gcash_state($gcashPaymentRow);
                    $gcashFulfillmentBlock = payment_gcash_fulfillment_reason($gcashPaymentRow);
                }

                /*
                 * Phase 9: Store Pickup COP finalization. At Ready for
                 * Pickup, a pending Cash on Pickup order uses the single
                 * atomic Confirm Pickup & Record Cash action instead of the
                 * generic one-step advance. The shared reason helper mirrors
                 * the locked write path; the read-only linkage check keeps
                 * the control hidden on a conflicting sale history.
                 */
                $isCopPayment = ($row['payment_method'] ?? '') === 'CASH_ON_PICKUP';
                $copReadyPending = $isCopPayment
                    && $stage === 'READY_FOR_PICKUP'
                    && !$isCancelled
                    && !$isCancellationLocked
                    && ($row['payment_status'] ?? '') === 'PENDING';
                $copFinalizationReason = $copReadyPending
                    ? payment_cop_pickup_finalization_reason($row, $row, ['delivery_method' => 'STORE_PICKUP'])
                    : null;
                $copLinkageError = $copReadyPending && $copFinalizationReason === null
                    ? payment_cod_sale_linkage_error($pdo, $orderId)
                    : null;
                $canConfirmPickupCash = $copReadyPending
                    && $copFinalizationReason === null
                    && $copLinkageError === null;

                /*
                 * Phase 10: Store Pickup GCash finalization. At Ready for
                 * Pickup, a verified GCash payment uses the single atomic
                 * Confirm Pickup & Complete Sale action. The shared reason
                 * helper mirrors the locked write path; the read-only linkage
                 * check keeps the control hidden on a conflicting sale history.
                 */
                $isPickupGcash = ($row['payment_method'] ?? '') === 'GCASH';
                $pgReadyVerified = $isPickupGcash
                    && $stage === 'READY_FOR_PICKUP'
                    && !$isCancelled
                    && !$isCancellationLocked
                    && ($row['payment_status'] ?? '') === 'PAID';
                $pgFinalizationReason = $pgReadyVerified
                    ? payment_pickup_gcash_finalization_reason($row, $row, ['delivery_method' => 'STORE_PICKUP'])
                    : null;
                $pgLinkageError = $pgReadyVerified && $pgFinalizationReason === null
                    ? payment_cod_sale_linkage_error($pdo, $orderId)
                    : null;
                $canConfirmPickupGcash = $pgReadyVerified
                    && $pgFinalizationReason === null
                    && $pgLinkageError === null;

                $canAdvance = $nextStage !== null
                    && !$isCancelled
                    && !$isCancellationLocked
                    && $gcashFulfillmentBlock === null
                    && !$copReadyPending
                    && !$pgReadyVerified;

                /*
                 * Phase 3 barcode gate: at ORDER_RECEIVED the preparation step
                 * must go through the pickup verification page. The direct
                 * advance button is replaced with a Verify Items link; the
                 * shared pickup helper still rejects a direct POST while any
                 * item is unverified.
                 */
                $verifyProgress = null;
                if ($stage === 'ORDER_RECEIVED' && !$isCancelled && !$isCancellationLocked) {
                    $verifyProgress = pickup_verify_progress($pdo, $orderId);
                }

                $paymentMethodLabel = isset($row['payment_method'])
                    ? ($paymentMethodLabels[$row['payment_method']] ?? $row['payment_method'])
                    : 'No payment record';
                $paymentStatusLabel = isset($row['payment_status'])
                    ? ($paymentStatusLabels[$row['payment_status']] ?? $row['payment_status'])
                    : 'No payment record';
                $paymentStatusBadge = isset($row['payment_status']) && isset($paymentStatusBadgeClasses[$row['payment_status']])
                    ? $paymentStatusBadgeClasses[$row['payment_status']]
                    : 'badge';
                $listDetails = $pickupListDetails[$orderId] ?? [];
                $listDeadline = $progress['deadline_at'] !== null
                    ? date('M j, Y g:i A', strtotime($progress['deadline_at']))
                    : ($stage === 'PICKED_UP' && $progress['picked_up_at'] !== null
                        ? 'Picked up ' . date('M j, Y g:i A', strtotime($progress['picked_up_at']))
                        : 'Set when Ready for Pickup');
                ?>
                <article class="admin-pickup-card om-card<?= $progress['overdue'] ? ' is-overdue' : '' ?>">
                    <div class="admin-pickup-card__header">
                        <div>
                            <p class="admin-pickup-card__eyebrow">Order</p>
                            <h2>#<?= $orderId ?></h2>
                        </div>

                        <?php ob_start(); ?>
                        <div class="admin-pickup-card__statuses">
                            <span class="badge">Store Pickup</span>
                            <span class="badge <?= htmlspecialchars(pickup_stage_badge_class($stage), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(pickup_stage_label($stage), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if ($isCancelled): ?>
                                <span class="badge badge-cancelled">Cancelled</span>
                            <?php endif; ?>
                            <?php if ($cancellationStatus === 'REQUESTED'): ?>
                                <span class="badge badge-cancellation-requested">Cancellation requested</span>
                            <?php elseif ($cancellationStatus === 'APPROVED'): ?>
                                <span class="badge badge-cancellation-approved">Cancellation approved</span>
                            <?php endif; ?>
                            <?php if ($progress['overdue']): ?>
                                <span class="badge badge-cancelled">Overdue</span>
                            <?php endif; ?>
                            <?php if ($gcashState !== 'NA'): ?>
                                <span class="badge <?= htmlspecialchars(payment_gcash_state_badge($gcashState), ENT_QUOTES, 'UTF-8') ?>">
                                    GCash: <?= htmlspecialchars(payment_gcash_state_label($gcashState), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php $listStatusHtml = ob_get_clean(); echo $listStatusHtml; ?>
                    </div>

                    <div class="om-card-summary">
                        <strong class="om-card-summary__customer"><?= hopia_e($row['customer_name']) ?></strong>
                        <strong class="om-card-summary__total">&#8369;<?= number_format((float) $row['total_amount'], 2) ?></strong>
                        <span class="om-card-summary__meta"><?= hopia_e(date('M j, Y g:i A', strtotime($row['created_at']))) ?></span>
                        <span class="om-card-summary__meta"><?= (int) ($listDetails['item_count'] ?? 0) ?> items</span>
                        <span class="om-card-summary__deadline">Pickup deadline: <?= hopia_e($listDeadline) ?></span>
                        <span class="om-card-summary__payment"><?= hopia_e($paymentMethodLabel) ?> &middot; <?= hopia_e($paymentStatusLabel) ?></span>
                    </div>
                    <button class="om-card-toggle" id="pickup-toggle-<?= $orderId ?>" type="button" data-order-expand data-order-id="<?= $orderId ?>" aria-expanded="false" aria-controls="pickup-details-<?= $orderId ?>" aria-label="Expand details for order #<?= $orderId ?>">
                        <span data-order-toggle-label>Show Details</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="om-card-details" id="pickup-details-<?= $orderId ?>" hidden>
                    <dl class="admin-pickup-card__details">
                        <div>
                            <dt>Customer</dt>
                            <dd><?= htmlspecialchars($row['customer_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Order date</dt>
                            <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Payment</dt>
                            <dd>
                                <?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?>
                                <span class="badge <?= htmlspecialchars($paymentStatusBadge, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($paymentStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt>Pickup deadline</dt>
                            <dd>
                                <?php if ($progress['deadline_at'] !== null): ?>
                                    <?= htmlspecialchars(date('M j, Y g:i A', strtotime($progress['deadline_at'])), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($progress['overdue']): ?>
                                        <span class="badge badge-cancelled">Past due</span>
                                    <?php endif; ?>
                                <?php elseif ($stage === 'PICKED_UP' && $progress['picked_up_at'] !== null): ?>
                                    Picked up <?= htmlspecialchars(date('M j, Y g:i A', strtotime($progress['picked_up_at'])), ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <span class="muted">Set when marked Ready for Pickup</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Total amount</dt>
                            <dd>₱<?= number_format((float) $row['total_amount'], 2) ?></dd>
                        </div>
                        <?php if ($verifyProgress !== null): ?>
                            <div>
                                <dt>Items verified</dt>
                                <dd>
                                    <span class="badge <?= $verifyProgress['complete'] ? 'badge-success' : 'badge-pending' ?>">
                                        <?= (int) $verifyProgress['verified'] ?>/<?= (int) $verifyProgress['expected'] ?>
                                    </span>
                                    <?php if ((int) $verifyProgress['expected'] === 0): ?>
                                        <span class="muted text-small">No items recorded</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        <?php endif; ?>
                    </dl>

                    <?php admin_order_expanded_details($row, $listDetails, true); ?>
                    <?php ob_start(); ?>
                    <div class="admin-pickup-card__actions">
                        <a class="btn btn-secondary btn-sm" href="order-view.php?id=<?= $orderId ?>">
                            View Order
                        </a>

                        <?php if ($canConfirmPickupGcash): ?>
                            <form method="POST" action="pickups.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <input type="hidden" name="action" value="confirm_pickup_gcash_complete_sale">
                                <input type="hidden" name="payment_revision" value="<?= (int) ($row['revision'] ?? 0) ?>">
                                <button class="btn btn-primary btn-sm" type="submit" onclick="return confirm('Confirm that the customer has received the items? The GCash payment is already verified/paid, so this confirms the handover and records the completed sale in Sales Monitoring.')">
                                    Confirm Pickup &amp; Complete Sale
                                </button>
                            </form>
                        <?php elseif ($pgReadyVerified): ?>
                            <span class="muted text-small">
                                <?= htmlspecialchars($pgLinkageError['message'] ?? payment_message($pgFinalizationReason ?? 'pg_error'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php elseif ($canConfirmPickupCash): ?>
                            <form method="POST" action="pickups.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <input type="hidden" name="action" value="confirm_pickup_record_cash">
                                <input type="hidden" name="payment_revision" value="<?= (int) ($row['revision'] ?? 0) ?>">
                                <button class="btn btn-primary btn-sm" type="submit" onclick="return confirm('Confirm that the customer has received the items and the cash has been collected? This records the payment, confirms handover, and completes the sale together.')">
                                    Confirm Pickup &amp; Record Cash
                                </button>
                            </form>
                        <?php elseif ($copReadyPending): ?>
                            <span class="muted text-small">
                                <?= htmlspecialchars($copLinkageError['message'] ?? payment_message($copFinalizationReason ?? 'cop_error'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php elseif ($canAdvance && $stage === 'ORDER_RECEIVED' && $verifyProgress !== null): ?>
                            <a class="btn btn-primary btn-sm" href="pickup-verify.php?order_id=<?= $orderId ?>">
                                Verify Items
                            </a>
                            <span class="muted text-small">
                                <?= (int) $verifyProgress['verified'] ?>/<?= (int) $verifyProgress['expected'] ?> verified
                            </span>
                        <?php elseif ($canAdvance): ?>
                            <form method="POST" action="pickups.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <input type="hidden" name="action" value="advance_pickup">
                                <button class="btn btn-primary btn-sm" type="submit">
                                    Mark as <?= htmlspecialchars($nextStageLabel, ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </form>
                        <?php elseif ($isCancelled): ?>
                            <span class="muted text-small">Cancelled &mdash; no pickup actions.</span>
                        <?php elseif ($isCancellationLocked): ?>
                            <span class="muted text-small">Cancellation pending &mdash; pickup actions are locked.</span>
                        <?php elseif ($gcashFulfillmentBlock !== null): ?>
                            <span class="muted text-small">
                                <?= htmlspecialchars(payment_gcash_fulfillment_message($gcashFulfillmentBlock), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <a class="btn btn-secondary btn-sm" href="order-view.php?id=<?= $orderId ?>#payment-heading">
                                Review payment
                            </a>
                        <?php elseif ($stage !== 'PICKED_UP' || ($row['payment_method'] ?? '') !== 'CASH_ON_PICKUP' || ($row['payment_status'] ?? '') !== 'PENDING'): ?>
                            <span class="muted text-small">Picked up &mdash; completed.</span>
                        <?php endif; ?>

                        <?php if (
                            !$isCancelled
                            && !$isCancellationLocked
                            && $stage === 'PICKED_UP'
                            && ($row['payment_method'] ?? '') === 'CASH_ON_PICKUP'
                            && ($row['payment_status'] ?? '') === 'PENDING'
                        ): ?>
                            <form method="POST" action="pickups.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                <input type="hidden" name="action" value="collect_cash_on_pickup">
                                <button class="btn btn-primary btn-sm" type="submit" onclick="return confirm('Record the Cash on Pickup payment as collected? Do this only after the cash is in hand at the store.')">
                                    Record Cash Collected
                                </button>
                            </form>
                        <?php elseif (!$isCancelled && $stage === 'PICKED_UP' && ($row['payment_method'] ?? '') === 'CASH_ON_PICKUP' && ($row['payment_status'] ?? '') === 'PAID'): ?>
                            <span class="badge badge-success">Cash collected</span>
                        <?php endif; ?>
                    </div>
                    <?php $listActionsHtml = ob_get_clean(); echo $listActionsHtml; ?>
                    </div>
                </article>
                <?php $desktopPickupRows[] = ['order' => $row, 'item_count' => $listDetails['item_count'] ?? 0,
                    'statuses' => $listStatusHtml, 'payment_method' => $paymentMethodLabel, 'actions' => $listActionsHtml,
                    'deadline' => $listDeadline, 'overdue' => $progress['overdue']]; ?>
            <?php endforeach; ?>
        </div>
        <?php admin_order_desktop_table($desktopPickupRows, true); ?>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
