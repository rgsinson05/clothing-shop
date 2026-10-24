<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/admin-feedback.php';
require_once __DIR__ . '/../includes/database.php';

require_once __DIR__ . '/../includes/order_verify.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/pickup.php';
require_once __DIR__ . '/../includes/order_cancel.php';
require_once __DIR__ . '/../includes/customer_notifications.php';
require_once __DIR__ . '/../includes/admin_delivery.php';
require_once __DIR__ . '/../includes/delivery_schema.php';

$adminName = $_SESSION['admin_name'] ?? 'Admin';

/*
 * Separate CSRF token for admin state-changing forms. It is
 * intentionally kept apart from the customer-facing token so
 * the two form protections never interfere with each other.
 */

if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}

$adminCsrfToken = $_SESSION['admin_csrf_token'];

/*
 * Marker exception for intentional, admin-facing validation
 * messages. It deliberately does NOT extend PDOException, so
 * database failures can never surface their raw SQL messages.
 */

class OrderActionException extends RuntimeException
{
}

/*
 * Friendly labels. The database values remain unchanged.
 */

$orderStatusLabels = [
    'PENDING' => 'Pending',
    'CONFIRMED' => 'Confirmed',
    'PACKED' => 'Packed',
    'SHIPPED' => 'Shipped',
    'IN_TRANSIT' => 'In Transit',
    'DELIVERED' => 'Delivered',
    'COMPLETED' => 'Completed',
    'CANCELLED' => 'Cancelled'
];

$cancellationStatusLabels = [
    'NONE' => 'None',
    'REQUESTED' => 'Cancellation Requested',
    'APPROVED' => 'Cancellation Approved',
    'REJECTED' => 'Cancellation Rejected'
];

$paymentMethodLabels = [
    'COD' => 'Cash on Delivery',
    'GCASH' => 'GCash',
    'CARD' => 'Card',
    'CASH_ON_PICKUP' => 'Cash on Pickup'
];

$paymentStatusLabels = [
    'PENDING' => 'Pending',
    'PAID' => 'Paid',
    'FAILED' => 'Failed',
    'REFUNDED' => 'Refunded'
];

$shipmentStatusLabels = [
    'NOT_SHIPPED' => 'Not Shipped',
    'READY_TO_SHIP' => 'Ready to Ship',
    'SHIPPED' => 'Shipped',
    'IN_TRANSIT' => 'In Transit',
    'OUT_FOR_DELIVERY' => 'Out for Delivery',
    'DELIVERY_ATTEMPT_FAILED' => 'Delivery Attempt Failed',
    'RETURN_TO_SELLER' => 'Return to Seller',
    'RETURNED_TO_SHOP' => 'Returned to Shop',
    'DELIVERED' => 'Delivered'
];

/*
 * Normal order flow. Only the immediate next status is a valid
 * target, and the target is always derived on the server from
 * the locked database row. The form never submits a status.
 */

$orderStatusFlow = [
    'PENDING' => 'CONFIRMED',
    'CONFIRMED' => 'PACKED',
    'PACKED' => 'SHIPPED',
    'SHIPPED' => 'DELIVERED'
];

/*
 * State-changing actions are submitted from this page via POST
 * and follow the post/redirect/get pattern. The CSRF token, the
 * order ID, and the action are validated before any database
 * mutation happens. A failed request falls through so the page
 * renders again with a generic error notice.
 */

$actionError = '';
$expiredCancellationReason = 'GCash payment was rejected and the customer did not resubmit within the 24-hour resubmission period.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    hopia_admin_success_clear();

    $submittedCsrfToken = $_POST['csrf_token'] ?? '';
    $postedOrderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $postedAction = $_POST['action'] ?? '';

    $allowedActions = [
        'advance_status',
        'save_tracking_number',
        'approve_cancellation',
        'reject_cancellation',
        'confirm_payment',
        'reject_payment',
        'cancel_expired_gcash',
        'advance_pickup',
        'collect_cash_on_pickup',
        'confirm_pickup_record_cash',
        'collect_cod_complete_sale',
        'finalize_gcash_sale',
        'confirm_pickup_gcash_complete_sale'
    ];

    $paymentRevision = filter_input(INPUT_POST, 'payment_revision', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $needsPaymentRevision = in_array($postedAction, ['confirm_payment', 'reject_payment', 'cancel_expired_gcash', 'collect_cod_complete_sale', 'finalize_gcash_sale', 'confirm_pickup_record_cash', 'confirm_pickup_gcash_complete_sale'], true);

    if (
        !is_string($submittedCsrfToken)
        || $submittedCsrfToken === ''
        || empty($_SESSION['admin_csrf_token'])
        || !hash_equals($_SESSION['admin_csrf_token'], $submittedCsrfToken)
        || $postedOrderId === null
        || $postedOrderId === false
        || $postedOrderId <= 0
        || !is_string($postedAction)
        || !in_array($postedAction, $allowedActions, true)
        || ($needsPaymentRevision && ($paymentRevision === null || $paymentRevision === false))
    ) {
        $actionError = 'The order could not be updated. Please try again.';
    } else if ($postedAction === 'confirm_payment' || $postedAction === 'reject_payment') {

        /*
         * GCash payment verification actions. These use the shared,
         * self-locking payment helpers (each manages its own transaction with
         * SELECT ... FOR UPDATE and a conditional UPDATE), so they are handled
         * OUTSIDE the order-status transaction below. They change ONLY the
         * payments row: the order status, shipment, products, and sales are
         * never touched, so verifying a payment never fabricates a completed
         * sale or collected revenue, and the conditional UPDATE makes a
         * duplicate confirm/reject a safe no-op.
         */
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);

        $paymentResult = $postedAction === 'confirm_payment'
            ? payment_gcash_verify($pdo, $adminId, (int) $postedOrderId, (int) $paymentRevision)
            : payment_gcash_reject($pdo, $adminId, (int) $postedOrderId, is_string($_POST['rejection_reason'] ?? null) ? $_POST['rejection_reason'] : '', (int) $paymentRevision);

        if (!empty($paymentResult['ok'])) {
            $successMessage = $postedAction === 'confirm_payment'
                ? 'GCash payment for Order #' . (int) $postedOrderId . ' has been verified as paid.'
                : 'GCash payment proof for Order #' . (int) $postedOrderId . " was rejected. The customer's 24-hour correction and resubmission period has started.";
            header('Location: ' . hopia_admin_success_redirect('order-view.php?id=' . (int) $postedOrderId, $successMessage));
            exit;
        }

        $actionError = $paymentResult['message'] ?? 'The payment could not be updated. Please try again.';

    } else if ($postedAction === 'cancel_expired_gcash') {

        $expiredCancellationReason = is_string($_POST['cancellation_reason'] ?? null) ? trim($_POST['cancellation_reason']) : '';
        if (($_POST['confirm_cancellation'] ?? '') !== '1') {
            $actionError = 'Review the cancellation reason and confirm that you want to cancel this order.';
        } else {
            $cancelResult = order_cancel_expired_gcash(
                $pdo, (int) $_SESSION['admin_id'], (int) $postedOrderId,
                $expiredCancellationReason, (int) $paymentRevision
            );
            if (!empty($cancelResult['ok'])) {
                header('Location: ' . hopia_admin_success_redirect(
                    'order-view.php?id=' . (int) $postedOrderId,
                    'Order #' . (int) $postedOrderId . ' was cancelled after its GCash resubmission deadline expired. Eligible item holds were released; no payment was collected.'
                ));
                exit;
            }
            $actionError = $cancelResult['message'] ?? 'The order could not be cancelled. Please reload and review its current state.';
        }

    } else if ($postedAction === 'advance_pickup') {

        /*
         * Store Pickup lifecycle advance (Phase 3). Uses the shared,
         * self-locking pickup helper (its own transaction with SELECT ...
         * FOR UPDATE and a conditional UPDATE), so it sits outside the
         * order-status transaction like the payment actions. It writes ONLY
         * the four pickup columns on orders plus, on the PICKED_UP step, the
         * exactly-once ONLINE completed sale: shipment, products, and the
         * courier status workflow are untouched, and payment status is never
         * changed by an advance. The same helper backs admin/pickups.php,
         * so there is exactly one enforcement point for the transition
         * rules.
         */
        $pickupResult = pickup_advance_status($pdo, (int) $postedOrderId);

        if (!empty($pickupResult['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'order-view.php?id=' . (int) $postedOrderId,
                'Order #' . (int) $postedOrderId . ' pickup status is now ' . pickup_stage_label($pickupResult['stage']) . '.'
            ));
            exit;
        }

        $actionError = $pickupResult['message'] ?? 'The pickup status could not be updated. Please try again.';

    } else if ($postedAction === 'collect_cash_on_pickup') {

        /*
         * Cash on Pickup collection (Phase 3). Uses the shared,
         * self-locking payment helper: the ONLY path that can set a
         * CASH_ON_PICKUP payment to PAID. It requires the pickup lifecycle
         * to already be terminal (PICKED_UP) and the payment to still be
         * PENDING, and it touches nothing but the payments row, so
         * recording the collected cash never completes a sale or changes
         * the order. It stays a separate, explicit action from the
         * handover: the admin records it only after the physical cash is
         * in hand.
         */
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);

        $collectResult = payment_collect_cash_on_pickup($pdo, $adminId, (int) $postedOrderId);

        if (!empty($collectResult['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'order-view.php?id=' . (int) $postedOrderId,
                'Cash on Pickup payment for Order #' . (int) $postedOrderId . ' has been recorded as collected.'
            ));
            exit;
        }

        $actionError = $collectResult['message'] ?? 'The cash collection could not be recorded. Please try again.';

    } else if ($postedAction === 'confirm_pickup_record_cash') {

        /*
         * Phase 9: ONE atomic action for a Store Pickup Cash on Pickup order
         * at Ready for Pickup. The helper owns the transaction and, under row
         * locks, records the cash collection, confirms the handover, and
         * creates the canonical completed sale. Any failure rolls back all
         * three changes together.
         */
        $confirmResult = payment_confirm_pickup_and_collect_cash(
            $pdo, (int) $_SESSION['admin_id'], (int) $postedOrderId, (int) $paymentRevision
        );

        if (!empty($confirmResult['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'order-view.php?id=' . (int) $postedOrderId,
                'Pickup handover confirmed and cash collection recorded for Order #' . (int) $postedOrderId . '. The completed sale has been recorded in Sales Monitoring.'
            ));
            exit;
        }

        $actionError = $confirmResult['message'] ?? payment_message('cop_error');

    } else if ($postedAction === 'collect_cod_complete_sale') {

        // This helper owns the collection + sale transaction, outside the status transaction below.
        $collectResult = payment_collect_cod_and_complete_sale(
            $pdo, (int) $_SESSION['admin_id'], (int) $postedOrderId, (int) $paymentRevision
        );
        if (!empty($collectResult['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'order-view.php?id=' . (int) $postedOrderId,
                'Local Courier COD collection confirmed for Order #' . (int) $postedOrderId . '. The completed sale has been recorded in Sales Monitoring.'
            ));
            exit;
        }
        $actionError = $collectResult['message'] ?? payment_message('cod_error');

    } else if ($postedAction === 'finalize_gcash_sale') {

        // The customer already paid via GCash and the admin already verified
        // the payment. This helper owns ONE transaction that records the
        // completed ONLINE sale only (no physical cash collection), outside
        // the status transaction below.
        $finalizeResult = payment_finalize_gcash_sale(
            $pdo, (int) $_SESSION['admin_id'], (int) $postedOrderId, (int) $paymentRevision
        );
        if (!empty($finalizeResult['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'order-view.php?id=' . (int) $postedOrderId,
                'Local Courier GCash sale for Order #' . (int) $postedOrderId . ' has been finalized and recorded in Sales Monitoring. No cash was collected.'
            ));
            exit;
        }
        $actionError = $finalizeResult['message'] ?? payment_message('gcash_error');

    } else if ($postedAction === 'confirm_pickup_gcash_complete_sale') {

        /*
         * Phase 10: ONE atomic action for a Store Pickup GCash order at Ready
         * for Pickup. The GCash payment was already verified/paid by an admin,
         * so this action confirms the handover and creates the canonical
         * completed sale in one transaction - it never re-verifies the GCash
         * payment or records cash collection. The helper owns the transaction
         * and revalidates every condition under row locks.
         */
        $pickupGcashResult = payment_confirm_pickup_gcash_and_complete_sale(
            $pdo, (int) $_SESSION['admin_id'], (int) $postedOrderId, (int) $paymentRevision
        );

        if (!empty($pickupGcashResult['ok'])) {
            header('Location: ' . hopia_admin_success_redirect(
                'order-view.php?id=' . (int) $postedOrderId,
                'Pickup handover confirmed for Order #' . (int) $postedOrderId . '. The completed GCash sale has been recorded in Sales Monitoring; no cash was collected.'
            ));
            exit;
        }

        $actionError = $pickupGcashResult['message'] ?? payment_message('pg_error');

    } else {

        try {

            $submittedTrackingNumber = null;

            /*
             * Tracking input is validated server-side before any
             * database work starts. Only trimming and a length
             * limit are applied; no courier-specific pattern is
             * enforced, and an empty value is always rejected.
             */

            if ($postedAction === 'save_tracking_number') {
                $submittedTrackingNumber = $_POST['tracking_number'] ?? null;

                if (!is_string($submittedTrackingNumber) || trim($submittedTrackingNumber) === '') {
                    throw new OrderActionException('Enter a tracking number.');
                }

                $submittedTrackingNumber = trim($submittedTrackingNumber);

                if (strlen($submittedTrackingNumber) > 100) {
                    throw new OrderActionException('The tracking number must be 100 characters or fewer.');
                }
            }

            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();

            /*
             * Lock the order row first, then re-check the business
             * rules against the locked database state so a stale
             * browser page cannot cause an invalid transition.
             */

            $lockStmt = $pdo->prepare(
                'SELECT id, status, cancellation_status
                 FROM orders
                 WHERE id = :order_id
                 LIMIT 1
                 FOR UPDATE'
            );

            $lockStmt->execute([
                ':order_id' => $postedOrderId
            ]);

            $lockedOrder = $lockStmt->fetch();

            if ($lockedOrder === false) {
                throw new OrderActionException('The order no longer exists.');
            }

            $lockedOrderId = (int) $lockedOrder['id'];

            if ($postedAction === 'advance_status') {

                if ($lockedOrder['cancellation_status'] === 'REQUESTED') {
                    throw new OrderActionException('A cancellation request is pending for this order.');
                }

                /*
                 * Phase 3 separation, enforced server-side: a Store Pickup
                 * order never travels the courier status flow. Its
                 * orders.status stays PENDING for the whole pickup
                 * lifecycle (the dedicated advance_pickup action owns its
                 * transitions), so a crafted advance_status POST on a
                 * pickup order is rejected here, not merely hidden in the
                 * UI.
                 */

                $methodCheckStmt = $pdo->prepare(
                    'SELECT delivery_method
                     FROM shipments
                     WHERE order_id = :order_id
                     LIMIT 1'
                );

                $methodCheckStmt->execute([
                    ':order_id' => $lockedOrderId
                ]);

                $lockedDeliveryMethod = $methodCheckStmt->fetchColumn();

                if ($lockedDeliveryMethod === 'STORE_PICKUP') {
                    throw new OrderActionException('This is a Store Pickup order; use its pickup lifecycle instead.');
                }

                if (!isset($orderStatusFlow[$lockedOrder['status']])) {
                    throw new OrderActionException('This order status has no next status.');
                }

                $targetStatus = $orderStatusFlow[$lockedOrder['status']];

                // Only a demonstrably untouched pre-016 schema retains the
                // legacy path. Partial/unknown rollout must never bypass the API.
                if ($targetStatus === 'DELIVERED') {
                    $deliverySchema = delivery_schema_status($pdo);
                    if ($deliverySchema['state'] !== 'legacy') {
                        throw new OrderActionException($deliverySchema['state'] === 'ready'
                            ? 'Use the Courier delivery workflow below with current cycle, revision and stage tokens.'
                            : $deliverySchema['message']);
                    }
                }

                /*
                 * Phase 5 payment gate. A GCash order may not be confirmed,
                 * packed, shipped, or delivered until an admin has verified
                 * the submitted reference and screenshot. The payments row is
                 * locked AFTER the order row (the same order-then-payment lock
                 * order used by the verification helpers), so a concurrent
                 * confirm/reject and this fulfillment attempt cannot race into
                 * an invalid state. COD orders keep their established unpaid
                 * fulfillment workflow because the shared gate returns null
                 * for every non-GCash method.
                 */
                $fulfillmentPaymentBlock = payment_gcash_fulfillment_block($pdo, $lockedOrderId);

                if ($fulfillmentPaymentBlock !== null) {
                    throw new OrderActionException(
                        payment_gcash_fulfillment_message($fulfillmentPaymentBlock)
                    );
                }

                /*
                 * PACKED can only become SHIPPED once the shipment
                 * carries a tracking number. The check runs on the
                 * locked rows so a manually crafted POST cannot
                 * bypass it.
                 */

                if ($targetStatus === 'SHIPPED') {
                    $shipmentLockStmt = $pdo->prepare(
                        'SELECT tracking_number
                         FROM shipments
                         WHERE order_id = :order_id
                         LIMIT 1
                         FOR UPDATE'
                    );

                    $shipmentLockStmt->execute([
                        ':order_id' => $lockedOrderId
                    ]);

                    $lockedShipment = $shipmentLockStmt->fetch();

                    if (
                        $lockedShipment === false
                        || trim($lockedShipment['tracking_number'] ?? '') === ''
                    ) {
                        throw new OrderActionException('Enter a tracking number before marking this order as shipped.');
                    }

                    /*
                     * Phase B shipment gate (the SINGLE enforcement point).
                     * Before PACKED -> SHIPPED, every expected order item must
                     * carry a committed verification record. The order row is
                     * already locked FOR UPDATE above, and the shared helper
                     * order_verify_progress() counts expected items and the
                     * verification rows that belong to this same order,
                     * straight from the database. No item is excluded for a
                     * NULL product_id or a missing/invalid barcode: those must
                     * be Manual-Verified. A zero-item order or any unverified
                     * item rejects the transition and keeps the order at PACKED
                     * (the thrown exception rolls the transaction back, so no
                     * shipment row or status is changed). This mirrors the lock
                     * ordering of the verification write paths (order row
                     * first), so a verification attempt and a shipment attempt
                     * cannot race into an invalid final state.
                     */
                    $verifyProgress = order_verify_progress($pdo, $lockedOrderId);

                    if ($verifyProgress['expected'] === 0 || !$verifyProgress['complete']) {
                        throw new OrderActionException(
                            'All items must be verified before this order can be shipped. Verify each item'
                            . ' (including Manual Verify for unscannable items) on the Verify page. Verified '
                            . (int) $verifyProgress['verified'] . ' of ' . (int) $verifyProgress['expected'] . '.'
                        );
                    }
                }

                $advanceStmt = $pdo->prepare(
                    'UPDATE orders
                     SET status = :next_status
                     WHERE id = :order_id
                       AND status = :current_status
                       AND cancellation_status <> \'REQUESTED\''
                );

                $advanceStmt->execute([
                    ':next_status' => $targetStatus,
                    ':order_id' => $postedOrderId,
                    ':current_status' => $lockedOrder['status']
                ]);

                if ($advanceStmt->rowCount() !== 1) {
                    throw new OrderActionException('The order status could not be advanced.');
                }

                /*
                 * Shipment data is only touched once the flow
                 * reaches SHIPPED or DELIVERED. The tracking number
                 * is never modified here. A missing shipment row
                 * fails the whole transaction instead of silently
                 * creating unexpected data.
                 */

                if ($targetStatus === 'SHIPPED') {
                    $shipmentUpdateStmt = $pdo->prepare(
                        'UPDATE shipments
                         SET shipment_status = \'SHIPPED\',
                             shipped_at = NOW()
                         WHERE order_id = :order_id'
                    );

                    $shipmentUpdateStmt->execute([
                        ':order_id' => $lockedOrderId
                    ]);

                    if ($shipmentUpdateStmt->rowCount() !== 1) {
                        throw new OrderActionException('The shipment record could not be updated.');
                    }

                    /*
                     * Phase 14: record the customer's Shipped notification in
                     * the SAME transaction that transitioned the order, so the
                     * order change and the notification commit or roll back
                     * together. The tracking number (already validated present
                     * above) is included when available. A failure aborts the
                     * whole transition.
                     */
                    customer_notifications_create(
                        $pdo,
                        $lockedOrderId,
                        'ORDER_SHIPPED',
                        $lockedShipment !== false ? (string) ($lockedShipment['tracking_number'] ?? '') : null
                    );
                }

                if ($targetStatus === 'DELIVERED') {
                    $shipmentUpdateStmt = $pdo->prepare(
                        'UPDATE shipments
                         SET shipment_status = \'DELIVERED\',
                             delivered_at = NOW()
                         WHERE order_id = :order_id'
                    );

                    $shipmentUpdateStmt->execute([
                        ':order_id' => $lockedOrderId
                    ]);

                    if ($shipmentUpdateStmt->rowCount() !== 1) {
                        throw new OrderActionException('The shipment record could not be updated.');
                    }

                    /*
                     * Phase 14: record the customer's Delivered notification in
                     * the SAME transaction that transitioned the order. It is a
                     * reminder to confirm receipt only; it does not confirm
                     * receipt, collect payment, or finalize a sale. A failure
                     * aborts the whole transition.
                     */
                    customer_notifications_create(
                        $pdo,
                        $lockedOrderId,
                        'ORDER_DELIVERED'
                    );
                }

            } elseif ($postedAction === 'save_tracking_number') {

                /*
                 * Tracking entry is only allowed once the order is
                 * confirmed and while no cancellation request is
                 * pending. Saving a tracking number never changes
                 * the order status or the cancellation status.
                 */

                if (
                    !in_array($lockedOrder['status'], ['CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED'], true)
                    || $lockedOrder['cancellation_status'] === 'REQUESTED'
                ) {
                    throw new OrderActionException('Tracking management is not available for this order.');
                }

                $shipmentLockStmt = $pdo->prepare(
                    'SELECT tracking_number, delivery_method
                     FROM shipments
                     WHERE order_id = :order_id
                     LIMIT 1
                     FOR UPDATE'
                );

                $shipmentLockStmt->execute([
                    ':order_id' => $lockedOrderId
                ]);

                $lockedShipment = $shipmentLockStmt->fetch();

                if ($lockedShipment === false) {
                    throw new OrderActionException('The shipment record could not be found.');
                }

                /*
                 * Tracking is courier-only. The UI already hides the form for
                 * Store Pickup, but a crafted POST is rejected here as well so
                 * a pickup order can never gain a courier tracking number.
                 */
                if (pickup_is_pickup_order($lockedShipment)) {
                    throw new OrderActionException('This is a Store Pickup order; tracking numbers do not apply.');
                }

                /*
                 * MySQL reports rowCount() === 0 when a column is
                 * set to its current value, so the locked row is
                 * compared first and an identical value is treated
                 * as a successful no-op save.
                 */

                $currentTrackingNumber = trim($lockedShipment['tracking_number'] ?? '');

                if ($currentTrackingNumber !== $submittedTrackingNumber) {
                    $trackingUpdateStmt = $pdo->prepare(
                        'UPDATE shipments
                         SET tracking_number = :tracking_number
                         WHERE order_id = :order_id'
                    );

                    $trackingUpdateStmt->execute([
                        ':tracking_number' => $submittedTrackingNumber,
                        ':order_id' => $lockedOrderId
                    ]);

                    if ($trackingUpdateStmt->rowCount() !== 1) {
                        throw new OrderActionException('The tracking number could not be saved.');
                    }
                }

            } elseif ($postedAction === 'approve_cancellation') {

                if (
                    $lockedOrder['status'] !== 'PENDING'
                    || $lockedOrder['cancellation_status'] !== 'REQUESTED'
                ) {
                    throw new OrderActionException('This order has no pending cancellation request.');
                }

                order_cancel_pending_locked($pdo, $lockedOrder);

                /*
                 * Phase 14: record the customer's Cancelled notification in
                 * the SAME transaction that cancelled the order, so the
                 * cancellation and the notification commit or roll back
                 * together. The existing cancellation eligibility rules are
                 * unchanged; the notification is created only after the
                 * cancellation succeeds.
                 */
                customer_notifications_create($pdo, $lockedOrderId, 'ORDER_CANCELLED');

            } else {

                if (
                    $lockedOrder['status'] !== 'PENDING'
                    || $lockedOrder['cancellation_status'] !== 'REQUESTED'
                ) {
                    throw new OrderActionException('This order has no pending cancellation request.');
                }

                /*
                 * Rejection keeps the order status at PENDING and
                 * leaves the products SOLD.
                 */

                $rejectStmt = $pdo->prepare(
                    'UPDATE orders
                     SET cancellation_status = \'REJECTED\'
                     WHERE id = :order_id
                       AND status = \'PENDING\'
                       AND cancellation_status = \'REQUESTED\''
                );

                $rejectStmt->execute([
                    ':order_id' => $postedOrderId
                ]);

                if ($rejectStmt->rowCount() !== 1) {
                    throw new OrderActionException('The cancellation request could not be rejected.');
                }
            }

            $pdo->commit();

            /*
             * Redirect only after the transaction has committed.
             */

            if ($postedAction === 'advance_status') {
                $successMessage = 'Order #' . (int) $postedOrderId . ' is now ' . $orderStatusLabels[$targetStatus] . '.';
            } elseif ($postedAction === 'save_tracking_number') {
                $successMessage = 'Tracking number saved for Order #' . (int) $postedOrderId . '.';
            } elseif ($postedAction === 'approve_cancellation') {
                $successMessage = 'Cancellation request approved. Order #' . (int) $postedOrderId . ' has been cancelled.';
            } else {
                $successMessage = 'Cancellation request rejected. Order #' . (int) $postedOrderId . ' remains Pending.';
            }

            header('Location: ' . hopia_admin_success_redirect('order-view.php?id=' . (int) $postedOrderId, $successMessage));
            exit;

        } catch (OrderActionException $e) {

            /*
             * Every OrderActionException thrown above carries an
             * intentional, admin-facing validation message that is
             * safe to display.
             */

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $actionError = $e->getMessage();
        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $actionError = 'The order could not be updated. Please try again.';
        }
    }
}

/*
 * Validate the requested order ID. Anything that is not a
 * positive integer is treated the same as an unknown order.
 */

$requestedOrderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$order = false;
$orderItems = [];
$payment = false;
$shipment = false;

if ($requestedOrderId !== null && $requestedOrderId !== false && $requestedOrderId > 0) {

    /*
     * Fetch the order together with its customer. If no order is
     * found, the remaining queries are skipped.
     */

    $orderStmt = $pdo->prepare(
        'SELECT o.id,
                o.customer_id,
                o.status,
                o.cancellation_status,
                o.cancellation_reason,
                o.cancelled_at,
                ca.name AS cancelled_by_name,
                o.subtotal,
                o.shipping_fee,
                o.total_amount,
                o.shipping_name,
                o.shipping_phone,
                o.shipping_address,
                o.created_at,
                o.updated_at,
                o.received_at,
                o.pickup_status,
                o.ready_for_pickup_at,
                o.pickup_deadline_at,
                o.picked_up_at,
                EXISTS(SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id AND st.sale_channel = \'ONLINE\') AS has_completed_sale,
                CONCAT(c.first_name, \' \', c.last_name) AS customer_name,
                c.email AS customer_email,
                c.phone AS customer_phone
         FROM orders o
         JOIN customers c ON c.id = o.customer_id
         LEFT JOIN admins ca ON ca.id = o.cancelled_by
         WHERE o.id = :order_id
         LIMIT 1'
    );

    $orderStmt->execute([
        ':order_id' => $requestedOrderId
    ]);

    $order = $orderStmt->fetch();

    if ($order !== false) {
        $orderId = (int) $order['id'];

        /*
         * Item information comes from the order_items snapshot,
         * never from the current products row.
         */

        $itemsStmt = $pdo->prepare(
            'SELECT id, product_id, product_name, size, color, unit_price, quantity, subtotal
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );

        $itemsStmt->execute([
            ':order_id' => $orderId
        ]);

        $orderItems = $itemsStmt->fetchAll();

        /*
         * Payment and shipment records are optional. A missing row
         * is displayed as a safe fallback instead of an error.
         */

        $paymentStmt = $pdo->prepare(
            'SELECT p.payment_method, p.payment_status, p.gcash_reference,
                    p.proof_path, p.submitted_at, p.verified_by, p.verified_at, p.rejection_reason,
                    p.rejected_at, p.revision, NOW() AS checked_at,
                    a.name AS verified_by_name
             FROM payments p
             LEFT JOIN admins a ON a.id = p.verified_by
             WHERE p.order_id = :order_id
             LIMIT 1'
        );

        $paymentStmt->execute([
            ':order_id' => $orderId
        ]);

        $payment = $paymentStmt->fetch();

        $shipmentStmt = $pdo->prepare(
            'SELECT *
             FROM shipments
             WHERE order_id = :order_id
             LIMIT 1'
        );

        $shipmentStmt->execute([
            ':order_id' => $orderId
        ]);

        $shipment = $shipmentStmt->fetch();
    }
}

$pageTitle = $order !== false
    ? 'Order #' . (int) $order['id']
    : 'Order Not Found';

/*
 * Resolve labels with the raw database value as a fallback so
 * unexpected enum values still render safely.
 */

$orderStatusLabel = $order !== false
    ? ($orderStatusLabels[$order['status']] ?? $order['status'])
    : '';

$cancellationStatusLabel = $order !== false
    ? ($cancellationStatusLabels[$order['cancellation_status']] ?? $order['cancellation_status'])
    : '';

$paymentMethodLabel = ($payment !== false && $payment['payment_method'] !== null)
    ? ($paymentMethodLabels[$payment['payment_method']] ?? $payment['payment_method'])
    : 'No payment record';

$paymentStatusLabel = ($payment !== false && $payment['payment_status'] !== null)
    ? ($paymentStatusLabels[$payment['payment_status']] ?? $payment['payment_status'])
    : 'No payment record';

/*
 * GCash verification display. The confirm/reject controls appear only when a
 * GCash payment is actually awaiting verification (reference submitted,
 * status PENDING) and the order is not cancelled. The server re-checks all of
 * this under lock in the payment helpers, so a stale page cannot force a bad
 * update.
 */
$isGcashPayment = ($payment !== false && ($payment['payment_method'] ?? '') === 'GCASH');
$gcashState = $isGcashPayment ? payment_gcash_state($payment) : 'NA';
$gcashCanReview = $isGcashPayment
    && $gcashState === 'AWAITING'
    && $order !== false
    && $order['status'] !== 'CANCELLED';
$hasGcashEvidence = $isGcashPayment || ($payment !== false && (
    trim((string) ($payment['gcash_reference'] ?? '')) !== '' || trim((string) ($payment['proof_path'] ?? '')) !== ''
));
$gcashReference = $hasGcashEvidence ? trim((string) ($payment['gcash_reference'] ?? '')) : '';
$gcashHasProof = $hasGcashEvidence && trim((string) ($payment['proof_path'] ?? '')) !== '';
$gcashRejection = $hasGcashEvidence ? trim((string) ($payment['rejection_reason'] ?? '')) : '';
$gcashSubmittedAt = ($hasGcashEvidence && $payment['submitted_at'] !== null)
    ? date('M j, Y g:i A', strtotime((string) $payment['submitted_at']))
    : '';
$gcashVerifiedAt = ($isGcashPayment && $payment['verified_at'] !== null)
    ? date('M j, Y g:i A', strtotime((string) $payment['verified_at']))
    : '';
$gcashVerifiedBy = $isGcashPayment ? trim((string) ($payment['verified_by_name'] ?? '')) : '';
$gcashStateLabel = $isGcashPayment ? payment_gcash_state_label($gcashState) : '';
$gcashStateBadge = $isGcashPayment ? payment_gcash_state_badge($gcashState) : 'badge';
$gcashRecoveryWindow = payment_gcash_recovery_window($payment !== false ? $payment : []);
$showExpiredGcashCancellation = $order !== false && $payment !== false
    && payment_gcash_expired_cancel_reason($order, $payment, $shipment !== false ? $shipment : null) === null;

/*
 * Phase 5: the same server-side gate applied in the action handlers, mirrored
 * for the UI so an unverified/rejected GCash order does not offer a
 * fulfillment button that would only fail on submit. The action handlers
 * re-check under lock; this flag is presentation only.
 */
$gcashFulfillmentBlock = ($isGcashPayment && $order !== false && $order['status'] !== 'CANCELLED')
    ? payment_gcash_fulfillment_reason($payment)
    : null;

$shipmentStatusLabel = ($shipment !== false && $shipment['shipment_status'] !== null)
    ? ($shipmentStatusLabels[$shipment['shipment_status']] ?? $shipment['shipment_status'])
    : 'No shipment record';

$customerPhone = $order !== false ? trim($order['customer_phone'] ?? '') : '';

$trackingNumber = $shipment !== false
    ? trim($shipment['tracking_number'] ?? '')
    : '';

$shippedAtDisplay = ($shipment !== false && $shipment['shipped_at'] !== null)
    ? date('M j, Y g:i A', strtotime($shipment['shipped_at']))
    : '';

$deliveredAtDisplay = ($shipment !== false && $shipment['delivered_at'] !== null)
    ? date('M j, Y g:i A', strtotime($shipment['delivered_at']))
    : '';

$receivedAtDisplay = ($order !== false && $order['received_at'] !== null)
    ? date('M j, Y g:i A', strtotime($order['received_at']))
    : '';

/*
 * Order action availability. The normal flow button only exists
 * while the current status still has a valid next status and no
 * cancellation request is pending. The cancellation buttons only
 * exist for a PENDING order with a REQUESTED cancellation.
 *
 * Phase 3: a Store Pickup order advances through the dedicated pickup
 * lifecycle instead, so the courier advance control is withheld for it
 * (the shared pickup helper also rejects a courier-style advance on a
 * pickup order server-side, so a crafted POST cannot bypass this).
 */

$isPickupOrderView = pickup_is_pickup_order($shipment);

/*
 * Fulfillment terminology. The same page serves both methods, so every
 * courier-only section below is guarded by this flag instead of assuming the
 * orders table implies a courier shipment.
 */
$fulfillmentMethodLabel = $isPickupOrderView ? 'Store Pickup' : 'Local Courier';

/* Cancellation is a separate terminal/locked outcome for both methods. The
   shared pickup helper enforces this under lock; the UI mirrors it so a
   pickup advance is never offered while a cancellation decision is pending. */
$pickupCancellationLocked = $order !== false
    && in_array((string) $order['cancellation_status'], ['REQUESTED', 'APPROVED'], true);

$nextOrderStatus = null;

$courierSchema = $order !== false && !$isPickupOrderView ? delivery_schema_status($pdo) : null;
$courierView = $courierSchema !== null && $courierSchema['state'] === 'ready' && $shipment !== false
    ? admin_delivery_view($pdo, (int) $_SESSION['admin_id'], $order, $payment, $shipment)
    : null;

$courierProgress = $order !== false && !$isPickupOrderView
    ? admin_delivery_progress($order, $payment !== false ? $payment : null,
        $shipment !== false ? $shipment : null, $courierView['events'] ?? [])
    : null;
if ($courierProgress !== null) {
    $orderStatusLabel = $orderStatusLabels[$courierProgress['stage'] ?? ''] ?? 'Status Under Review';
}
$orderStatusBadgeKey = $courierProgress !== null ? ($courierProgress['stage'] ?? '') : ($order['status'] ?? '');
if ($courierProgress !== null && $courierProgress['stage'] !== 'CANCELLED'
    && in_array($shipment['shipment_status'] ?? '', ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'], true)) {
    $orderStatusLabel = admin_delivery_labels()[$shipment['shipment_status']];
    $orderStatusBadgeKey = $shipment['shipment_status'];
}
$courierMainStages = ['PENDING' => 'clock', 'CONFIRMED' => 'check', 'PACKED' => 'package',
    'SHIPPED' => 'truck', 'IN_TRANSIT' => 'truck', 'DELIVERED' => 'check-circle', 'COMPLETED' => 'check-circle'];

if (
    $order !== false
    && !$isPickupOrderView
    && $order['cancellation_status'] !== 'REQUESTED'
    && $gcashFulfillmentBlock === null
    && isset($orderStatusFlow[$order['status']])
) {
    $nextOrderStatus = $orderStatusFlow[$order['status']];
    if ($nextOrderStatus === 'DELIVERED' && ($courierSchema['state'] ?? 'blocked') !== 'legacy') {
        $nextOrderStatus = null;
    }
}

$nextOrderStatusLabel = $nextOrderStatus !== null
    ? ($orderStatusLabels[$nextOrderStatus] ?? $nextOrderStatus)
    : '';

$showCancellationActions = $order !== false
    && $order['status'] === 'PENDING'
    && $order['cancellation_status'] === 'REQUESTED';

/*
 * Tracking-number management needs an existing shipment record.
 * It is available from CONFIRMED onwards and is never available
 * for a CANCELLED order, a PENDING order, or while a cancellation
 * request is pending. The server re-checks these conditions when
 * the form is submitted.
 */

$showTrackingForm = $order !== false
    && !$isPickupOrderView
    && $shipment !== false
    && in_array($order['status'], ['CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED'], true)
    && $order['cancellation_status'] !== 'REQUESTED';

/*
 * Phase B: a small verification affordance on eligible orders. The link opens
 * the dedicated verification page; the hint shows progress from the same shared
 * helper the shipment gate uses. This does not add a second gate - the only
 * enforcement remains the PACKED -> SHIPPED transition above.
 */
$verifyEligible = $order !== false
    && order_is_verifiable($order['status'], $order['cancellation_status']);

$verifyProgress = ['expected' => 0, 'verified' => 0, 'remaining' => 0, 'complete' => false];

if ($verifyEligible) {
    $verifyProgress = order_verify_progress($pdo, (int) $order['id']);
}

/*
 * Phase 3: the pickup lifecycle view for a Store Pickup order. Derived by
 * the shared helper from the persisted columns (legacy NULL pickup_status
 * resolves to ORDER_RECEIVED). Drives the pickup card, the deadline
 * display, the overdue flag, and the single advance control. The current-state
 * card below reports the pickup stage instead of the courier order status for
 * pickup orders.
 */
$pickupProgressView = ($order !== false && $isPickupOrderView)
    ? pickup_progress($order, $shipment)
    : null;

$pickupNextStage = $pickupProgressView !== null
    ? pickup_next_stage($pickupProgressView['stage'])
    : null;

/*
 * Phase 9: atomic Store Pickup COP finalization. The shared reason helper
 * mirrors the locked checks in payment_confirm_pickup_and_collect_cash, so
 * the control and the write path cannot drift. The read-only linkage check
 * keeps the control hidden when an order item is already linked to another
 * completed sale; the locked path re-checks it authoritatively.
 */
$isCopPickupOrder = $order !== false
    && $isPickupOrderView
    && $payment !== false
    && ($payment['payment_method'] ?? '') === 'CASH_ON_PICKUP';
$copFinalizationReason = $isCopPickupOrder
    ? payment_cop_pickup_finalization_reason($order, $payment, $shipment !== false ? $shipment : null)
    : null;
$copLinkageError = $isCopPickupOrder && $copFinalizationReason === null
    ? payment_cod_sale_linkage_error($pdo, (int) $order['id'])
    : null;
$showConfirmPickupCash = $isCopPickupOrder
    && $copFinalizationReason === null
    && $copLinkageError === null;

/*
 * Phase 10: Store Pickup GCash finalization. A Store Pickup order whose GCash
 * payment is already verified/paid and whose lifecycle is at READY_FOR_PICKUP
 * uses the single atomic Confirm Pickup & Complete Sale action. The shared
 * reason helper mirrors the locked write path; the read-only linkage check
 * keeps the control hidden on a conflicting sale history.
 */
$isPickupGcashOrder = $order !== false
    && $isPickupOrderView
    && $payment !== false
    && ($payment['payment_method'] ?? '') === 'GCASH';
$pickupGcashReason = $isPickupGcashOrder
    ? payment_pickup_gcash_finalization_reason($order, $payment, $shipment !== false ? $shipment : null)
    : null;
$pickupGcashLinkageError = $isPickupGcashOrder && $pickupGcashReason === null
    ? payment_cod_sale_linkage_error($pdo, (int) $order['id'])
    : null;
$showConfirmPickupGcash = $isPickupGcashOrder
    && $pickupGcashReason === null
    && $pickupGcashLinkageError === null;
$pickupGcashAlreadyCompleted = $isPickupGcashOrder && !empty($order['has_completed_sale']);

/* At Ready for Pickup, a verified GCash payment uses the atomic Phase 10
   action instead of the generic one-step advance, so the sale can never be
   skipped at the handover. */
$pickupGcashReadyVerified = $isPickupGcashOrder
    && $pickupProgressView !== null
    && $pickupProgressView['stage'] === 'READY_FOR_PICKUP'
    && !$pickupProgressView['cancelled']
    && !$pickupCancellationLocked
    && ($payment['payment_status'] ?? '') === 'PAID';

/* At Ready for Pickup, a pending COP payment uses the atomic Phase 9 action
   instead of the generic one-step advance, so cash can never be skipped. */
$pickupCopReadyPending = $isCopPickupOrder
    && $pickupProgressView !== null
    && $pickupProgressView['stage'] === 'READY_FOR_PICKUP'
    && !$pickupProgressView['cancelled']
    && !$pickupCancellationLocked
    && ($payment['payment_status'] ?? '') === 'PENDING';

$pickupCanAdvance = $pickupProgressView !== null
    && $pickupNextStage !== null
    && !$pickupProgressView['cancelled']
    && !$pickupCancellationLocked
    && $gcashFulfillmentBlock === null
    && !$pickupCopReadyPending
    && !$pickupGcashReadyVerified;

/*
 * Phase 3 barcode gate: the ORDER_RECEIVED -> PREPARING step must go through
 * the pickup verification page. The direct advance control is replaced with a
 * link to that page for this stage only; the shared helper independently
 * rejects an advance while any item is unverified, so a direct POST cannot
 * bypass the scanner either.
 */
$pickupVerifyEligible = $order !== false
    && $isPickupOrderView
    && $pickupProgressView !== null
    && $pickupProgressView['stage'] === 'ORDER_RECEIVED'
    && !$pickupProgressView['cancelled']
    && !$pickupCancellationLocked
    && $gcashFulfillmentBlock === null;

$pickupVerifyProgress = $pickupVerifyEligible
    ? order_verify_progress($pdo, (int) $order['id'])
    : null;

/*
 * Phase 3: Cash on Pickup collection affordance. Offered ONLY when the
 * order is fulfilled by Store Pickup, the payment method is
 * CASH_ON_PICKUP, the payment is still PENDING, the pickup lifecycle is
 * terminal (PICKED_UP), and the order is not cancelled. The server
 * re-checks every condition under lock in payment_collect_cash_on_pickup,
 * so a stale page cannot force a collection. This is deliberately a
 * separate control from the handover so each recorded event reflects what
 * actually happened.
 */
$showCollectCashOnPickup = $order !== false
    && $isPickupOrderView
    && !$pickupCancellationLocked
    && ($payment !== false && ($payment['payment_method'] ?? '') === 'CASH_ON_PICKUP')
    && ($payment['payment_status'] ?? '') === 'PENDING'
    && $pickupProgressView !== null
    && $pickupProgressView['stage'] === 'PICKED_UP'
    && !$pickupProgressView['cancelled'];

$isCodCourierOrder = $order !== false
    && !$isPickupOrderView
    && $payment !== false
    && ($payment['payment_method'] ?? '') === 'COD';
$codCollectionReason = $isCodCourierOrder
    ? payment_cod_collection_reason($order, $payment, $shipment !== false ? $shipment : null)
    : null;
$codLinkageError = $isCodCourierOrder && $codCollectionReason === null
    ? payment_cod_sale_linkage_error($pdo, (int) $order['id'])
    : null;
if ($isCodCourierOrder && $codCollectionReason === null && $codLinkageError === null) {
    $codEligibility = payment_collect_cod_and_complete_sale($pdo, (int) $_SESSION['admin_id'],
        (int) $order['id'], (int) $payment['revision'], validateOnly: true);
    if (!$codEligibility['ok']) $codLinkageError = $codEligibility;
}
$showCollectCod = $isCodCourierOrder && $codCollectionReason === null && $codLinkageError === null;
$codAlreadyCompleted = $isCodCourierOrder
    && !empty($order['has_completed_sale'])
    && ($payment['payment_status'] ?? '') === 'PAID';

/*
 * Phase 8: Local Courier GCash sale completion. The customer already paid
 * via GCash and the admin already verified the payment; this action records
 * the completed ONLINE sale only. Eligibility is derived by the shared
 * reason helper so the control and the locked write path cannot drift, and
 * the server re-checks everything under lock in payment_finalize_gcash_sale.
 */
$isGcashCourierOrder = $order !== false
    && !$isPickupOrderView
    && $payment !== false
    && ($payment['payment_method'] ?? '') === 'GCASH';
$gcashCompletionReason = $isGcashCourierOrder
    ? payment_gcash_completion_reason($order, $payment, $shipment !== false ? $shipment : null)
    : null;
$gcashLinkageError = $isGcashCourierOrder && $gcashCompletionReason === null
    ? payment_cod_sale_linkage_error($pdo, (int) $order['id'])
    : null;
if ($isGcashCourierOrder && $gcashCompletionReason === null && $gcashLinkageError === null) {
    $gcashEligibility = payment_finalize_gcash_sale($pdo, (int) $_SESSION['admin_id'],
        (int) $order['id'], (int) $payment['revision'], validateOnly: true);
    if (!$gcashEligibility['ok']) $gcashLinkageError = $gcashEligibility;
}
$showFinalizeGcash = $isGcashCourierOrder && $gcashCompletionReason === null && $gcashLinkageError === null;
$gcashAlreadyCompleted = $isGcashCourierOrder && !empty($order['has_completed_sale']);

?>
<?php
$orderStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'CONFIRMED' => 'badge-info',
    'PACKED' => 'badge-warning',
    'SHIPPED' => 'badge-info',
    'IN_TRANSIT' => 'badge-info',
    'DELIVERED' => 'badge-success',
    'COMPLETED' => 'badge-success',
    'RETURN_TO_SELLER' => 'badge-warning',
    'RETURNED_TO_SHOP' => 'badge-info',
    'CANCELLED' => 'badge-cancelled'
];

$cancellationStatusBadgeClasses = [
    'NONE' => 'badge-cancellation-none',
    'REQUESTED' => 'badge-cancellation-requested',
    'APPROVED' => 'badge-cancellation-approved',
    'REJECTED' => 'badge-cancellation-rejected'
];

$paymentStatusBadgeClasses = [
    'PENDING' => 'badge-pending',
    'PAID' => 'badge-success',
    'FAILED' => 'badge-cancelled',
    'REFUNDED' => 'badge-warning'
];

$shipmentStatusBadgeClasses = [
    'NOT_SHIPPED' => 'badge-pending',
    'READY_TO_SHIP' => 'badge-warning',
    'SHIPPED' => 'badge-info',
    'IN_TRANSIT' => 'badge-info',
    'OUT_FOR_DELIVERY' => 'badge-warning',
    'DELIVERY_ATTEMPT_FAILED' => 'badge-warning',
    'RETURN_TO_SELLER' => 'badge-warning',
    'RETURNED_TO_SHOP' => 'badge-warning',
    'DELIVERED' => 'badge-success'
];

$page_title = $pageTitle . " - Admin - Hopia's Ukay-Ukay";
$page_description = "Review and manage order details at Hopia's Ukay-Ukay.";
$ui_section = 'admin';
$ui_active = $isPickupOrderView ? 'pickups.php' : 'orders.php';
$body_class = 'admin-order-detail-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<?php if ($courierSchema !== null): ?>
    <link rel="stylesheet" href="../css/admin-delivery.css">
<?php endif; ?>
<div class="admin-order-detail">
    <?php if ($actionError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($order === false): ?>
        <div class="admin-order-detail__empty">
            <p class="admin-page-header__eyebrow">Order management</p>
            <h1>ORDER NOT FOUND</h1>
            <p>Order not found. The order ID may be missing, invalid, or the order may no longer exist.</p>
            <a class="btn btn-primary" href="orders.php">Back to Orders</a>
        </div>
    <?php else: ?>
        <header class="admin-order-detail__header">
            <div>
                <a class="admin-page-header__back" href="<?= $isPickupOrderView ? 'pickups.php' : 'orders.php' ?>">
                    &larr; Back to <?= $isPickupOrderView ? 'Store Pickup' : 'Orders' ?>
                </a>
                <p class="admin-page-header__eyebrow">Order management</p>
                <h1>ORDER #<?= (int) $order['id'] ?></h1>
                <p class="admin-order-detail__date">
                    Placed on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <div class="admin-order-detail__header-status" aria-label="Order statuses">
                <span class="badge"><?= htmlspecialchars($fulfillmentMethodLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($isPickupOrderView && $pickupProgressView !== null): ?>
                    <span class="badge <?= htmlspecialchars($pickupProgressView['badge_class'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($pickupProgressView['stage_label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php else: ?>
                    <span class="badge <?= htmlspecialchars($orderStatusBadgeClasses[$orderStatusBadgeKey] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($orderStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
                <span class="badge <?= htmlspecialchars($cancellationStatusBadgeClasses[$order['cancellation_status']] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($cancellationStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </header>

        <div class="admin-order-detail__layout">
            <div class="admin-order-detail__main">
                <section class="admin-order-detail__card" aria-labelledby="order-status-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Current state</p>
                            <h2 id="order-status-heading"><?= $isPickupOrderView ? 'Order state' : 'Order status' ?></h2>
                        </div>
                        <?php if ($isPickupOrderView && $pickupProgressView !== null): ?>
                            <span class="badge <?= htmlspecialchars($pickupProgressView['badge_class'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($pickupProgressView['stage_label'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php else: ?>
                            <span class="badge <?= htmlspecialchars($orderStatusBadgeClasses[$orderStatusBadgeKey] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($orderStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="admin-order-detail__status-meta">
                        <?php if ($isPickupOrderView && $pickupProgressView !== null): ?>
                            <div>
                                <span>Fulfillment method</span>
                                <strong><?= htmlspecialchars($fulfillmentMethodLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div>
                                <span>Pickup status</span>
                                <strong><?= htmlspecialchars($pickupProgressView['stage_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        <?php else: ?>
                            <div>
                                <span>Primary fulfillment stage</span>
                                <strong><?= htmlspecialchars($orderStatusLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <div>
                                <span>Stored order status</span>
                                <strong><?= htmlspecialchars($orderStatusLabels[$order['status']] ?? $order['status'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        <?php endif; ?>
                        <div>
                            <span>Cancellation</span>
                            <strong><?= htmlspecialchars($cancellationStatusLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </div>

                    <?php if ($courierProgress !== null): ?>
                        <?php if (isset($courierMainStages[$courierProgress['stage'] ?? ''])): ?>
                            <ol class="admin-order-progress" role="list" aria-label="Courier order progress">
                                <?php foreach ($courierMainStages as $mainStage => $mainIcon): ?>
                                    <?php $mainStepState = $courierProgress['returned_step_states'][$mainStage]
                                        ?? ($courierProgress['stage'] === $mainStage ? 'current' : ''); ?>
                                    <li class="admin-order-progress__step<?= $mainStepState !== '' ? ' is-' . $mainStepState : '' ?>"<?= $mainStepState === 'current' ? ' aria-current="step"' : '' ?><?= $mainStepState === 'unreached' ? ' aria-disabled="true"' : '' ?>>
                                        <span class="admin-order-progress__node" aria-hidden="true"><?= hopia_order_icon($mainIcon) ?></span>
                                        <div class="admin-order-progress__copy">
                                            <span class="admin-order-progress__label"><?= htmlspecialchars($orderStatusLabels[$mainStage], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($mainStage === 'IN_TRANSIT'): ?>
                                                <a href="#courier-workflow-heading">Courier workflow</a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                        <div class="admin-order-detail__action-note" role="status">
                            <p><strong>Customer update:</strong> <?= htmlspecialchars($courierProgress['message'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'CANCELLED' && trim((string) ($order['cancellation_reason'] ?? '')) !== ''): ?>
                        <div class="admin-order-detail__action-note">
                            <p><strong>Cancellation reason:</strong> <?= htmlspecialchars($order['cancellation_reason'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="muted">
                                Cancelled <?= htmlspecialchars((string) ($order['cancelled_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($order['cancelled_by_name'])): ?>
                                    by <?= htmlspecialchars($order['cancelled_by_name'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($verifyEligible): ?>
                        <div class="admin-order-detail__action-note">
                            <p class="admin-order-detail__eyebrow">Item verification</p>
                            <p>
                                Verified <?= (int) $verifyProgress['verified'] ?> of <?= (int) $verifyProgress['expected'] ?> item<?= (int) $verifyProgress['expected'] === 1 ? '' : 's' ?>.
                                <?php if ($verifyProgress['complete']): ?>
                                    All items are verified.
                                <?php else: ?>
                                    Every item must be verified before this order can be shipped.
                                <?php endif; ?>
                            </p>
                            <p>
                                <a class="btn btn-secondary btn-sm" href="order-verify.php?id=<?= (int) $order['id'] ?>">Verify items</a>
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="admin-order-detail__actions">
                        <?php if ($showCancellationActions): ?>
                            <div class="admin-order-detail__action-note">
                                <p class="admin-order-detail__eyebrow">Cancellation request</p>
                                <p>This order is waiting for a cancellation decision.</p>
                            </div>

                            <div class="admin-order-detail__action-buttons">
                                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="action" value="approve_cancellation">
                                    <button class="btn btn-primary" type="submit" onclick="return confirm('Approve this cancellation request? The ordered items will become available again.')">
                                        Approve Cancellation
                                    </button>
                                </form>

                                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="action" value="reject_cancellation">
                                    <button class="btn btn-danger" type="submit" onclick="return confirm('Reject this cancellation request? The order will remain pending.')">
                                        Reject Cancellation
                                    </button>
                                </form>
                            </div>
                        <?php elseif ($nextOrderStatus !== null): ?>
                            <div class="admin-order-detail__action-note">
                                <p class="admin-order-detail__eyebrow">Next step</p>
                                <p><?= $nextOrderStatus === 'SHIPPED'
                                    ? 'Dispatch only after every item is verified and a tracking number is recorded. Dispatch records Shipped; the courier workflow below starts In Transit.'
                                    : 'Advance this order when the next fulfillment step is complete.' ?></p>
                            </div>

                            <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="action" value="advance_status">
                                <button class="btn btn-primary" type="submit"<?= $nextOrderStatus === 'SHIPPED' ? ' onclick="return confirm(\'Confirm dispatch from the shop? This records Shipped, not delivery or order completion.\');"' : '' ?>>
                                    <?= htmlspecialchars($nextOrderStatus === 'SHIPPED' ? 'Dispatch Order (Shipped)' : 'Mark as ' . $nextOrderStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </form>
                        <?php elseif ($gcashFulfillmentBlock !== null): ?>
                            <div class="admin-order-detail__action-note">
                                <p class="admin-order-detail__eyebrow">Payment verification required</p>
                                <p><?= htmlspecialchars(payment_gcash_fulfillment_message($gcashFulfillmentBlock), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <a class="btn btn-secondary btn-sm" href="#payment-heading">Review payment</a>
                        <?php elseif ($isPickupOrderView): ?>
                            <p class="muted">Pickup actions are managed in the pickup lifecycle section below.</p>
                        <?php elseif ($courierSchema !== null && $order['status'] === 'SHIPPED'): ?>
                            <a class="btn btn-secondary btn-sm" href="#courier-workflow">Review Courier Delivery</a>
                        <?php else: ?>
                            <p class="muted">No actions are available for this order.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if ($courierSchema !== null): ?>
                    <?php require __DIR__ . '/../includes/ui.admin-delivery.php'; ?>
                <?php endif; ?>

                <?php if ($pickupProgressView !== null): ?>
                    <section class="admin-order-detail__card admin-order-detail__card--pickup" aria-labelledby="pickup-lifecycle-heading">
                        <div class="admin-order-detail__card-head">
                            <div>
                                <p class="admin-order-detail__eyebrow">Store Pickup</p>
                                <h2 id="pickup-lifecycle-heading">Pickup lifecycle</h2>
                            </div>
                            <span class="badge <?= htmlspecialchars($pickupProgressView['badge_class'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($pickupProgressView['stage_label'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>

                        <?php if ($pickupProgressView['cancelled']): ?>
                            <div class="admin-order-detail__status-meta">
                                <div>
                                    <span>Pickup status</span>
                                    <strong>Cancelled</strong>
                                </div>
                                <div>
                                    <span>Deadline</span>
                                    <strong>&mdash;</strong>
                                </div>
                            </div>
                            <p class="muted">This order was cancelled; its pickup lifecycle is closed. Stock restoration follows the existing cancellation rules.</p>
                        <?php else: ?>
                            <ol class="pickup-steps" role="list">
                                <?php foreach (pickup_stages() as $stageIndex => $stageKey): ?>
                                    <?php
                                    $stepState = '';
                                    if ($stageIndex < $pickupProgressView['stage_index']) {
                                        $stepState = ' is-complete';
                                    } elseif ($stageIndex === $pickupProgressView['stage_index']) {
                                        $stepState = ' is-current';
                                    }
                                    ?>
                                    <li class="pickup-steps__step<?= htmlspecialchars($stepState, ENT_QUOTES, 'UTF-8') ?>">
                                        <span class="pickup-steps__node" aria-hidden="true"><?= hopia_order_icon(pickup_stage_icon($stageKey)) ?></span>
                                        <span class="pickup-steps__label"><?= htmlspecialchars(pickup_stage_label($stageKey), ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>

                            <div class="admin-order-detail__status-meta">
                                <div>
                                    <span>Current stage</span>
                                    <strong><?= htmlspecialchars($pickupProgressView['stage_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                                <div>
                                    <span>Ready since</span>
                                    <strong><?= $pickupProgressView['ready_at'] !== null ? htmlspecialchars(date('M j, Y g:i A', strtotime($pickupProgressView['ready_at'])), ENT_QUOTES, 'UTF-8') : '&mdash;' ?></strong>
                                </div>
                                <div>
                                    <span>Pickup deadline</span>
                                    <strong>
                                        <?php if ($pickupProgressView['deadline_at'] !== null): ?>
                                            <?= htmlspecialchars(date('M j, Y g:i A', strtotime($pickupProgressView['deadline_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ($pickupProgressView['overdue']): ?>
                                                <span class="badge badge-cancelled">Overdue</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>Picked up at</span>
                                    <strong><?= $pickupProgressView['picked_up_at'] !== null ? htmlspecialchars(date('M j, Y g:i A', strtotime($pickupProgressView['picked_up_at'])), ENT_QUOTES, 'UTF-8') : '&mdash;' ?></strong>
                                </div>
                            </div>

                            <?php if ($pickupProgressView['overdue']): ?>
                                <div class="alert alert-warning" role="status">
                                    This pickup is past its 48-hour window. It is flagged for attention only &mdash; the shop must still decide manually (cancel via the existing cancellation flow, or keep waiting).
                                </div>
                            <?php endif; ?>

                            <?php if ($showCollectCashOnPickup): ?>
                                <div class="admin-order-detail__action-note">
                                    <p class="admin-order-detail__eyebrow">Cash on Pickup collection</p>
                                    <p>
                                        Cash on Pickup payment is still pending. After the customer hands over the cash at the store, record it as collected &mdash; this is the only action that marks this payment as Paid.
                                    </p>
                                </div>

                                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="action" value="collect_cash_on_pickup">
                                    <button class="btn btn-primary" type="submit" onclick="return confirm('Record the Cash on Pickup payment as collected? Do this only after the cash is in hand at the store.')">
                                        Record Cash Collected
                                    </button>
                                </form>
                            <?php endif; ?>

                            <div class="admin-order-detail__actions">
                                <?php if ($showConfirmPickupGcash): ?>
                                    <div class="admin-order-detail__action-note">
                                        <p class="admin-order-detail__eyebrow">Confirm pickup &amp; complete sale</p>
                                        <p>
                                            The customer is collecting the items now. The
                                            <?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?>
                                            payment has already been verified and paid, so confirming
                                            records the handover and creates the completed sale together.
                                            No cash is collected and the GCash payment is not changed.
                                        </p>
                                    </div>

                                    <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="action" value="confirm_pickup_gcash_complete_sale">
                                        <input type="hidden" name="payment_revision" value="<?= (int) ($payment['revision'] ?? 0) ?>">
                                        <button class="btn btn-primary" type="submit" onclick="return confirm('Confirm that the customer has received the items? The GCash payment is already verified/paid, so this confirms the handover and records the completed sale in Sales Monitoring.')">
                                            Confirm Pickup &amp; Complete Sale
                                        </button>
                                    </form>
                                <?php elseif ($pickupGcashReadyVerified && $pickupGcashReason !== null): ?>
                                    <div class="alert alert-warning" role="status">
                                        <?= htmlspecialchars($pickupGcashLinkageError['message'] ?? payment_message($pickupGcashReason), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php elseif ($showConfirmPickupCash): ?>
                                    <div class="admin-order-detail__action-note">
                                        <p class="admin-order-detail__eyebrow">Confirm pickup and cash</p>
                                        <p>
                                            The customer is receiving the items now and the
                                            <?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?>
                                            payment of &#8369;<?= number_format((float) $order['total_amount'], 2) ?>
                                            has been collected. Confirming records the cash, completes the
                                            handover, and creates the completed sale together.
                                        </p>
                                    </div>

                                    <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="action" value="confirm_pickup_record_cash">
                                        <input type="hidden" name="payment_revision" value="<?= (int) ($payment['revision'] ?? 0) ?>">
                                        <button class="btn btn-primary" type="submit" onclick="return confirm('Confirm that the customer has received the items and the cash has been collected? This records the payment, confirms handover, and completes the sale together.')">
                                            Confirm Pickup &amp; Record Cash
                                        </button>
                                    </form>
                                <?php elseif ($pickupCopReadyPending): ?>
                                    <div class="alert alert-warning" role="status">
                                        <?= htmlspecialchars($copLinkageError['message'] ?? payment_message($copFinalizationReason ?? 'cop_error'), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php elseif ($pickupVerifyEligible && $pickupVerifyProgress !== null): ?>
                                    <div class="admin-order-detail__action-note">
                                        <p class="admin-order-detail__eyebrow">Verify items first</p>
                                        <p>
                                            Verified <?= (int) $pickupVerifyProgress['verified'] ?> of
                                            <?= (int) $pickupVerifyProgress['expected'] ?> item<?= (int) $pickupVerifyProgress['expected'] === 1 ? '' : 's' ?>.
                                            Every item must be scanned and verified before this pickup can move to
                                            Preparing for Pickup.
                                        </p>
                                    </div>

                                    <a class="btn btn-primary" href="pickup-verify.php?order_id=<?= (int) $order['id'] ?>">
                                        Verify Items &amp; Start Preparing
                                    </a>
                                <?php elseif ($pickupCanAdvance): ?>
                                    <div class="admin-order-detail__action-note">
                                        <p class="admin-order-detail__eyebrow">Next step</p>
                                        <p>
                                            Advance the pickup lifecycle one step. Marking Ready for Pickup starts the 48-hour pickup window<?= $pickupProgressView['stage'] === 'PREPARING' ? ' and notifies the deadline on the customer order page' : '' ?>.
                                        </p>
                                    </div>

                                    <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="action" value="advance_pickup">
                                        <button class="btn btn-primary" type="submit">
                                            Mark as <?= htmlspecialchars(pickup_stage_label($pickupNextStage), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </form>
                                <?php elseif ($pickupCancellationLocked): ?>
                                    <p class="muted">A cancellation request is pending; pickup actions are locked until it is resolved.</p>
                                <?php elseif ($gcashFulfillmentBlock !== null): ?>
                                    <div class="admin-order-detail__action-note">
                                        <p class="admin-order-detail__eyebrow">Payment verification required</p>
                                        <p><?= htmlspecialchars(payment_gcash_fulfillment_message($gcashFulfillmentBlock), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <a class="btn btn-secondary btn-sm" href="#payment-heading">Review payment</a>
                                <?php elseif ($pickupProgressView['stage'] === 'PICKED_UP'): ?>
                                    <p class="muted">This order has been picked up; the pickup lifecycle is complete.</p>
                                <?php else: ?>
                                    <p class="muted">No pickup action is available for this order.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <section class="admin-order-detail__card" aria-labelledby="ordered-items-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Order contents</p>
                            <h2 id="ordered-items-heading">Items</h2>
                        </div>
                        <span class="text-small muted"><?= count($orderItems) ?> item<?= count($orderItems) === 1 ? '' : 's' ?></span>
                    </div>

                    <?php if (count($orderItems) === 0): ?>
                        <p class="muted">No items are recorded for this order.</p>
                    <?php else: ?>
                        <div class="admin-order-items" role="list">
                            <?php foreach ($orderItems as $item): ?>
                                <?php
                                $size = trim($item['size'] ?? '');
                                $color = trim($item['color'] ?? '');
                                ?>
                                <article class="admin-order-item" role="listitem">
                                    <div class="admin-order-item__info">
                                        <h3><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <p class="admin-order-item__meta">
                                            Size: <?= $size !== '' ? htmlspecialchars($size, ENT_QUOTES, 'UTF-8') : '&mdash;' ?>
                                            <span aria-hidden="true">&middot;</span>
                                            Color: <?= $color !== '' ? htmlspecialchars($color, ENT_QUOTES, 'UTF-8') : '&mdash;' ?>
                                        </p>
                                    </div>
                                    <dl class="admin-order-item__details">
                                        <div>
                                            <dt>Unit price</dt>
                                            <dd>₱<?= number_format((float) $item['unit_price'], 2) ?></dd>
                                        </div>
                                        <div>
                                            <dt>Quantity</dt>
                                            <dd><?= htmlspecialchars((string) (int) $item['quantity'], ENT_QUOTES, 'UTF-8') ?></dd>
                                        </div>
                                        <div>
                                            <dt>Subtotal</dt>
                                            <dd>₱<?= number_format((float) $item['subtotal'], 2) ?></dd>
                                        </div>
                                    </dl>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <div class="admin-order-detail__info-grid">
                    <section class="admin-order-detail__card" aria-labelledby="customer-information-heading">
                        <div class="admin-order-detail__card-head">
                            <div>
                                <p class="admin-order-detail__eyebrow">Customer</p>
                                <h2 id="customer-information-heading">Customer information</h2>
                            </div>
                        </div>

                        <dl class="admin-order-detail__details">
                            <div>
                                <dt>Name</dt>
                                <dd><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt>Email</dt>
                                <dd><?= htmlspecialchars($order['customer_email'], ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt>Phone</dt>
                                <dd><?= $customerPhone !== '' ? htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                            </div>
                        </dl>
                    </section>

                    <?php if ($isPickupOrderView && $pickupProgressView !== null): ?>
                        <section class="admin-order-detail__card" aria-labelledby="pickup-information-heading">
                            <div class="admin-order-detail__card-head">
                                <div>
                                    <p class="admin-order-detail__eyebrow">Fulfillment</p>
                                    <h2 id="pickup-information-heading">Pickup information</h2>
                                </div>
                                <span class="badge">Store Pickup</span>
                            </div>

                            <dl class="admin-order-detail__details">
                                <div>
                                    <dt>Fulfillment method</dt>
                                    <dd><?= htmlspecialchars($fulfillmentMethodLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Payment method</dt>
                                    <dd><?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Pickup status</dt>
                                    <dd>
                                        <span class="badge <?= htmlspecialchars($pickupProgressView['badge_class'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($pickupProgressView['stage_label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Pickup location</dt>
                                    <dd><?= trim((string) $order['shipping_address']) !== '' ? htmlspecialchars($order['shipping_address'], ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                                </div>
                                <div>
                                    <dt>Contact name</dt>
                                    <dd><?= trim((string) $order['shipping_name']) !== '' ? htmlspecialchars($order['shipping_name'], ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                                </div>
                                <div>
                                    <dt>Contact phone</dt>
                                    <dd><?= trim((string) $order['shipping_phone']) !== '' ? htmlspecialchars($order['shipping_phone'], ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                                </div>
                            </dl>
                        </section>
                    <?php else: ?>
                        <section class="admin-order-detail__card" aria-labelledby="shipping-information-heading">
                            <div class="admin-order-detail__card-head">
                                <div>
                                    <p class="admin-order-detail__eyebrow">Delivery</p>
                                    <h2 id="shipping-information-heading">Shipping information</h2>
                                </div>
                            </div>

                            <dl class="admin-order-detail__details">
                                <div>
                                    <dt>Name</dt>
                                    <dd><?= htmlspecialchars($order['shipping_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Phone</dt>
                                    <dd><?= htmlspecialchars($order['shipping_phone'], ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Address</dt>
                                    <dd><?= htmlspecialchars($order['shipping_address'], ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                            </dl>
                        </section>
                    <?php endif; ?>
                </div>

                <section class="admin-order-detail__card" aria-labelledby="payment-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Payment</p>
                            <h2 id="payment-heading">Payment information</h2>
                        </div>
                        <span class="badge <?= htmlspecialchars($paymentStatusBadgeClasses[$payment !== false ? ($payment['payment_status'] ?? '') : ''] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($paymentStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <dl class="admin-order-detail__details admin-order-detail__details--inline">
                        <div>
                            <dt>Payment method</dt>
                            <dd><?= htmlspecialchars($paymentMethodLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Payment status</dt>
                            <dd><?= htmlspecialchars($paymentStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    </dl>

                    <?php if ($isCodCourierOrder): ?>
                        <div class="admin-order-detail__actions" aria-labelledby="cod-collection-heading">
                            <div>
                                <h3 id="cod-collection-heading">COD collection and sale</h3>
                                <p class="text-small muted">Customer receipt confirms arrival, not COD collection. Shipping fees are not merchandise revenue or profit.</p>
                            </div>
                            <dl class="admin-order-detail__details admin-order-detail__details--inline">
                                <div>
                                    <dt>Fulfillment method</dt>
                                    <dd>Local Courier</dd>
                                </div>
                                <div>
                                    <dt>Order status</dt>
                                    <dd><?= htmlspecialchars($orderStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Customer receipt</dt>
                                    <dd><?= $receivedAtDisplay !== '' ? 'Customer Order Received Confirmed: ' . htmlspecialchars($receivedAtDisplay, ENT_QUOTES, 'UTF-8') : (in_array($shipment['shipment_status'] ?? '', ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'], true) ? 'Not confirmed - returned shipment' : 'Awaiting Customer Order Received') ?></dd>
                                </div>
                                <div>
                                    <dt>Merchandise subtotal</dt>
                                    <dd>&#8369;<?= number_format((float) $order['subtotal'], 2) ?></dd>
                                </div>
                                <div>
                                    <dt>Shipping fee (reference)</dt>
                                    <dd>&#8369;<?= number_format((float) $order['shipping_fee'], 2) ?></dd>
                                </div>
                                <div>
                                    <dt>Completed sale</dt>
                                    <dd><?= !empty($order['has_completed_sale']) ? 'Recorded in Sales Monitoring' : 'Not yet recorded' ?></dd>
                                </div>
                                <?php if ($payment['payment_status'] === 'PAID' && $payment['verified_at'] !== null): ?>
                                    <div>
                                        <dt>COD collection confirmed</dt>
                                        <dd>
                                            <?= htmlspecialchars(date('M j, Y g:i A', strtotime($payment['verified_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            <?= !empty($payment['verified_by_name']) ? ' by ' . htmlspecialchars($payment['verified_by_name'], ENT_QUOTES, 'UTF-8') : '' ?>
                                        </dd>
                                    </div>
                                <?php endif; ?>
                            </dl>

                            <?php if ($showCollectCod): ?>
                                <p class="alert alert-warning" role="status">COD collection confirmation and sale finalization are pending. Confirm only after reviewing this order's collection.</p>
                                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>"
                                      onsubmit="if (this.dataset.submitting) return false; if (!confirm('Confirm actual COD collection/remittance has been reconciled, and record the merchandise sale? This does not infer collection from delivery or customer receipt.')) return false; this.dataset.submitting = '1'; this.querySelector('button[type=submit]').disabled = true; return true;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="action" value="collect_cod_complete_sale">
                                    <input type="hidden" name="payment_revision" value="<?= (int) $payment['revision'] ?>">
                                    <button class="btn btn-primary admin-order-detail__cod-confirm" type="submit">Confirm COD Collection &amp; Record Sale</button>
                                </form>
                            <?php elseif ($codAlreadyCompleted): ?>
                                <p class="text-small">COD collection is recorded as paid. The completed sale is included in <a href="sales.php">Sales Monitoring</a>.</p>
                            <?php elseif ($codLinkageError !== null): ?>
                                <button class="btn btn-primary" type="button" disabled>Confirm COD Collection &amp; Record Sale</button>
                                <p class="alert alert-warning" role="status"><?= htmlspecialchars($codLinkageError['message'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php elseif ($codCollectionReason !== null): ?>
                                <button class="btn btn-primary" type="button" disabled>Confirm COD Collection &amp; Record Sale</button>
                                <p class="<?= in_array($codCollectionReason, ['cod_sale_without_collection', 'cod_collection_conflict'], true) ? 'alert alert-warning' : 'text-small muted' ?>" role="status">
                                    <?= htmlspecialchars(payment_message($codCollectionReason), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isGcashCourierOrder): ?>
                        <div class="admin-order-detail__actions" aria-labelledby="gcash-completion-heading">
                            <div>
                                <h3 id="gcash-completion-heading">Record Sale / Finalize Sale</h3>
                                <p class="text-small muted">After delivery, customer Order Received and verified GCash payment, explicitly record the merchandise sale in Sales Monitoring. This action does not collect cash or change payment or receipt. Shipping fees are excluded from merchandise revenue and profit.</p>
                            </div>
                            <dl class="admin-order-detail__details admin-order-detail__details--inline">
                                <div>
                                    <dt>Fulfillment method</dt>
                                    <dd>Local Courier</dd>
                                </div>
                                <div>
                                    <dt>Order status</dt>
                                    <dd><?= htmlspecialchars($orderStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Customer receipt</dt>
                                    <dd><?= $receivedAtDisplay !== '' ? 'Customer Order Received Confirmed: ' . htmlspecialchars($receivedAtDisplay, ENT_QUOTES, 'UTF-8') : (in_array($shipment['shipment_status'] ?? '', ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'], true) ? 'Not confirmed - returned shipment' : 'Awaiting Customer Order Received') ?></dd>
                                </div>
                                <div>
                                    <dt>Merchandise subtotal</dt>
                                    <dd>&#8369;<?= number_format((float) $order['subtotal'], 2) ?></dd>
                                </div>
                                <div>
                                    <dt>Shipping fee (reference)</dt>
                                    <dd>&#8369;<?= number_format((float) $order['shipping_fee'], 2) ?></dd>
                                </div>
                                <div>
                                    <dt>Completed sale</dt>
                                    <dd><?= !empty($order['has_completed_sale']) ? 'Recorded in Sales Monitoring' : 'Not yet recorded' ?></dd>
                                </div>
                                <?php if ($payment['payment_status'] === 'PAID' && $payment['verified_at'] !== null): ?>
                                    <div>
                                        <dt>Payment verified</dt>
                                        <dd>
                                            <?= htmlspecialchars(date('M j, Y g:i A', strtotime($payment['verified_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            <?= !empty($payment['verified_by_name']) ? ' by ' . htmlspecialchars($payment['verified_by_name'], ENT_QUOTES, 'UTF-8') : '' ?>
                                        </dd>
                                    </div>
                                <?php endif; ?>
                            </dl>

                            <?php if ($showFinalizeGcash): ?>
                                <p class="alert alert-warning" role="status">Verified GCash payment and customer receipt are confirmed. Finalizing records the completed sale in Sales Monitoring.</p>
                                <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>"
                                      onsubmit="if (this.dataset.submitting) return false; if (!confirm('Record the merchandise sale for this delivered, receipt-confirmed order? Payment, customer receipt and inventory will not change.')) return false; this.dataset.submitting = '1'; this.querySelector('button[type=submit]').disabled = true; return true;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="action" value="finalize_gcash_sale">
                                    <input type="hidden" name="payment_revision" value="<?= (int) $payment['revision'] ?>">
                                    <button class="btn btn-primary admin-order-detail__cod-confirm" type="submit">Record Sale / Finalize Sale</button>
                                </form>
                            <?php elseif ($gcashAlreadyCompleted): ?>
                                <p class="text-small">The completed sale is included in <a href="sales.php">Sales Monitoring</a>.</p>
                            <?php elseif ($gcashLinkageError !== null): ?>
                                <button class="btn btn-primary" type="button" disabled>Record Sale / Finalize Sale</button>
                                <p class="alert alert-warning" role="status"><?= htmlspecialchars($gcashLinkageError['message'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php elseif ($gcashCompletionReason !== null): ?>
                                <button class="btn btn-primary" type="button" disabled>Record Sale / Finalize Sale</button>
                                <p class="<?= $gcashCompletionReason === 'gcash_not_verified' ? 'text-small muted' : 'text-small muted' ?>" role="status">
                                    <?= htmlspecialchars(payment_message($gcashCompletionReason), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasGcashEvidence): ?>
                        <div class="admin-order-detail__gcash">
                            <div class="admin-order-detail__gcash-head">
                                <p class="admin-order-detail__eyebrow"><?= $isGcashPayment ? 'GCash verification' : 'Previous GCash submission' ?></p>
                                <?php if ($isGcashPayment): ?>
                                    <span class="badge <?= htmlspecialchars($gcashStateBadge, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($gcashStateLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>

                            <dl class="admin-order-detail__details admin-order-detail__details--inline">
                                <div>
                                    <dt>Reference number</dt>
                                    <dd>
                                        <?php if ($gcashReference !== ''): ?>
                                            <code><?= htmlspecialchars($gcashReference, ENT_QUOTES, 'UTF-8') ?></code>
                                        <?php else: ?>
                                            <span class="muted">Not submitted yet</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Submitted</dt>
                                    <dd><?= $gcashSubmittedAt !== '' ? htmlspecialchars($gcashSubmittedAt, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                                </div>
                                <div>
                                    <dt>Payment screenshot</dt>
                                    <dd>
                                        <?php if ($gcashHasProof): ?>
                                            <a href="payment-proof.php?order=<?= (int) $order['id'] ?>" target="_blank" rel="noopener noreferrer">View screenshot</a>
                                        <?php else: ?>
                                            <span class="muted">None provided</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <?php if ($gcashVerifiedAt !== ''): ?>
                                    <div>
                                        <dt>Verified</dt>
                                        <dd>
                                            <?= htmlspecialchars($gcashVerifiedAt, ENT_QUOTES, 'UTF-8') ?>
                                            <?= $gcashVerifiedBy !== '' ? ' by ' . htmlspecialchars($gcashVerifiedBy, ENT_QUOTES, 'UTF-8') : '' ?>
                                        </dd>
                                    </div>
                                <?php endif; ?>
                                <?php if (($gcashState === 'REJECTED' || !$isGcashPayment) && $gcashRejection !== ''): ?>
                                    <div>
                                        <dt>Rejection reason</dt>
                                        <dd><?= htmlspecialchars($gcashRejection, ENT_QUOTES, 'UTF-8') ?></dd>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($payment['rejected_at'])): ?>
                                    <div>
                                        <dt>Rejected</dt>
                                        <dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($payment['rejected_at'])), ENT_QUOTES, 'UTF-8') ?> (Philippine time)</dd>
                                    </div>
                                <?php endif; ?>
                            </dl>

                            <?php if ($isGcashPayment && $gcashState === 'REJECTED' && $order['status'] !== 'CANCELLED'): ?>
                                <div class="alert alert-warning" role="status">
                                    <?php if ($gcashRecoveryWindow['deadline_at'] === null): ?>
                                        The rejection date is unavailable. Expired-deadline cancellation is not available for this payment.
                                    <?php else: ?>
                                        <strong><?= $gcashRecoveryWindow['expired'] ? 'GCash resubmission deadline expired.' : 'GCash correction period is open.' ?></strong>
                                        Deadline: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($gcashRecoveryWindow['deadline_at'])), ENT_QUOTES, 'UTF-8') ?> (Philippine time, UTC+8).
                                        Expiration does not automatically cancel the order.
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($showExpiredGcashCancellation): ?>
                                <form class="admin-order-detail__gcash-reject gcash-recovery-cancel" method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>"
                                      onsubmit="return confirm('Cancel this order for the expired GCash resubmission deadline and release its eligible item holds?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <input type="hidden" name="action" value="cancel_expired_gcash">
                                    <input type="hidden" name="payment_revision" value="<?= (int) $payment['revision'] ?>">
                                    <div class="field">
                                        <label for="gcash-cancel-reason">Cancellation reason</label>
                                        <textarea id="gcash-cancel-reason" name="cancellation_reason" rows="3" maxlength="255" required><?= htmlspecialchars($expiredCancellationReason, ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                    <label>
                                        <input type="checkbox" name="confirm_cancellation" value="1" required>
                                        I have reviewed the reason and confirm this cancellation.
                                    </label>
                                    <button class="btn btn-danger" type="submit">Cancel Order - Failed GCash Resubmission Deadline</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($gcashCanReview): ?>
                                <p class="admin-order-detail__gcash-hint">
                                    Compare the reference above (and screenshot, if any) with the actual transaction in the shop's GCash account before confirming. Confirm only after you have verified the real transfer.
                                </p>
                                <div class="admin-order-detail__gcash-actions">
                                    <form method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="action" value="confirm_payment">
                                        <input type="hidden" name="payment_revision" value="<?= (int) $payment['revision'] ?>">
                                        <button class="btn btn-primary" type="submit" onclick="return confirm('Confirm this GCash payment as PAID? Do this only after verifying the real transfer in your GCash account.')">
                                            Confirm Payment
                                        </button>
                                    </form>

                                    <form class="admin-order-detail__gcash-reject" method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                        <input type="hidden" name="action" value="reject_payment">
                                        <input type="hidden" name="payment_revision" value="<?= (int) $payment['revision'] ?>">
                                        <div class="field">
                                            <label for="gcash-reject-reason">Rejection reason</label>
                                            <input type="text" id="gcash-reject-reason" name="rejection_reason" maxlength="255" required placeholder="e.g. Reference not found in GCash account">
                                        </div>
                                        <button class="btn btn-danger" type="submit" onclick="return confirm('Reject this payment and ask the customer to correct it?')">
                                            Reject / Request Correction
                                        </button>
                                    </form>
                                </div>
                            <?php elseif ($gcashState === 'NOT_SUBMITTED'): ?>
                                <p class="admin-order-detail__gcash-hint muted">
                                    The customer has not submitted a GCash reference yet. Verification becomes available once they do.
                                </p>
                            <?php elseif (!$isGcashPayment): ?>
                                <p class="admin-order-detail__gcash-hint muted">
                                    Retained for reference after the customer's switch to cash. This is not an outstanding GCash verification or evidence of cash collection.
                                </p>
                            <?php elseif ($gcashState === 'REJECTED' && $order['status'] !== 'CANCELLED'): ?>
                                <p class="admin-order-detail__gcash-hint muted">
                                    The customer may resubmit within 24 hours of rejection or choose an eligible cash payment method while the order remains eligible. Fulfillment stays blocked while GCash is unverified.
                                </p>
                            <?php elseif ($gcashState === 'PAID'): ?>
                                <p class="admin-order-detail__gcash-hint muted">
                                    This GCash payment has been verified as paid.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if (!$isPickupOrderView): ?>
                <section class="admin-order-detail__card" aria-labelledby="shipment-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Fulfillment</p>
                            <h2 id="shipment-heading">Shipment and tracking</h2>
                        </div>
                        <span class="badge <?= htmlspecialchars($shipmentStatusBadgeClasses[$shipment !== false ? ($shipment['shipment_status'] ?? '') : ''] ?? 'badge', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($shipmentStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <dl class="admin-order-detail__details admin-order-detail__details--inline">
                        <div>
                            <dt>Shipment status</dt>
                            <dd><?= htmlspecialchars($shipmentStatusLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Tracking number</dt>
                            <dd><?= $trackingNumber !== '' ? htmlspecialchars($trackingNumber, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                        </div>
                        <div>
                            <dt>Shipped at</dt>
                            <dd><?= $shippedAtDisplay !== '' ? htmlspecialchars($shippedAtDisplay, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                        </div>
                        <div>
                            <dt>Delivered at</dt>
                            <dd><?= $deliveredAtDisplay !== '' ? htmlspecialchars($deliveredAtDisplay, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></dd>
                        </div>
                        <?php if ($receivedAtDisplay !== ''): ?>
                        <div>
                            <dt>Customer receipt confirmed</dt>
                            <dd><span class="badge badge-success"><?= htmlspecialchars($receivedAtDisplay, ENT_QUOTES, 'UTF-8') ?></span></dd>
                        </div>
                        <?php endif; ?>
                    </dl>

                    <?php if ($showTrackingForm): ?>
                        <form class="admin-order-detail__tracking-form" method="POST" action="order-view.php?id=<?= (int) $order['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="action" value="save_tracking_number">

                            <div class="field">
                                <label for="tracking-number-input">Tracking number</label>
                                <input
                                    type="text"
                                    id="tracking-number-input"
                                    name="tracking_number"
                                    maxlength="100"
                                    value="<?= htmlspecialchars($trackingNumber, ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>
                            <button class="btn btn-secondary" type="submit">Save Tracking Number</button>
                        </form>
                    <?php endif; ?>
                </section>
                <?php endif; ?>
            </div>

            <aside class="admin-order-detail__aside">
                <section class="admin-order-detail__card admin-order-detail__totals" aria-labelledby="totals-heading">
                    <div class="admin-order-detail__card-head">
                        <div>
                            <p class="admin-order-detail__eyebrow">Order summary</p>
                            <h2 id="totals-heading">Totals</h2>
                        </div>
                    </div>

                    <dl class="admin-order-detail__total-list">
                        <div>
                            <dt>Subtotal</dt>
                            <dd>₱<?= number_format((float) $order['subtotal'], 2) ?></dd>
                        </div>
                        <div>
                            <dt><?= $isPickupOrderView ? 'Pickup fee' : 'Shipping fee' ?></dt>
                            <dd>₱<?= number_format((float) $order['shipping_fee'], 2) ?></dd>
                        </div>
                        <div class="admin-order-detail__total-list-grand">
                            <dt>Total amount</dt>
                            <dd>₱<?= number_format((float) $order['total_amount'], 2) ?></dd>
                        </div>
                    </dl>
                </section>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php if ($courierSchema !== null): ?>
    <script src="../js/admin-delivery.js" defer></script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
