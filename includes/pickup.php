<?php

/*
 * Hopia Fits - Phase 3 Store Pickup lifecycle shared helpers.
 *
 * Include-only: this file declares functions and performs no output and no
 * side effects, matching includes/sales.php, includes/order_verify.php,
 * includes/payment.php, and includes/shipping.php. It is the SINGLE SOURCE
 * OF TRUTH for:
 *   - the pickup lifecycle stages and their customer/admin labels,
 *   - the fulfillment-method detection (shipments.delivery_method),
 *   - pickup-progress derivation (including legacy NULL -> ORDER_RECEIVED),
 *   - the 48-hour pickup-deadline rule,
 *   - overdue highlighting, and
 *   - the ONE transactional advance path (pickup_advance_status).
 *
 * It is required by admin/pickups.php, admin/order-view.php,
 * customer/order-detail.php, customer/orders.php, and the Phase 3 tests, so
 * the eligibility and transition rules can never drift between the admin
 * pages, the customer pages, and the tests.
 *
 * CRITICAL invariants (Phase 3 brief):
 *   - The pickup lifecycle is SEPARATE from the courier lifecycle. A pickup
 *     order's orders.status stays PENDING throughout; pickup_progress()
 *     drives every customer-facing timeline. Courier orders never gain a
 *     pickup_status.
 *   - Transitions advance ONE step at a time (ORDER_RECEIVED -> PREPARING ->
 *     READY_FOR_PICKUP -> PICKED_UP); skipping (e.g. straight to PICKED_UP
 *     before READY_FOR_PICKUP) is rejected.
 *   - The 48h deadline is computed ONCE, when the order becomes ready, and
 *     stored. It is never recalculated. Being overdue only highlights the
 *     order; it never cancels, refunds, marks paid, or restores inventory.
 *   - Cancellation stays a separate terminal outcome under the existing
 *     cancellation rules (order-cancel-request.php + admin approval with
 *     inventory restoration). A cancelled order rejects pickup advances.
 *   - A pickup status change is never payment: payment_status is untouched
 *     here, so GCash stays pending until admin verification and Cash on
 *     Pickup stays pending until its explicit collection workflow
 *     (payment_collect_cash_on_pickup in includes/payment.php).
 *   - Entering PICKED_UP records the handover AND creates the exactly-once
 *     ONLINE completed sale (sales_complete_pickup_online_locked in
 *     includes/sales.php, inside the same transaction) with the customer
 *     receipt timestamp (orders.received_at). The two commit together or
 *     not at all; payment collection stays a separate event.
 */

if (!defined('PICKUP_DEADLINE_HOURS')) {
    /* The 48-hour pickup window required by the Phase 3 brief. */
    define('PICKUP_DEADLINE_HOURS', 48);
}

if (!function_exists('pickup_stages')) {
    /**
     * The ordered pickup lifecycle stages (stored pickup_status values),
     * display order. ORDER_RECEIVED is the initial state every pickup order
     * starts from; PICKED_UP is terminal.
     */
    function pickup_stages(): array
    {
        return ['ORDER_RECEIVED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP'];
    }
}

if (!function_exists('pickup_next_stage')) {
    /**
     * The single valid next stage after $stage, or null at/after the
     * terminal stage or for an unknown value.
     */
    function pickup_next_stage(string $stage): ?string
    {
        $stages = pickup_stages();
        $index = array_search($stage, $stages, true);

        if ($index === false || $index >= count($stages) - 1) {
            return null;
        }

        return $stages[$index + 1];
    }
}

if (!function_exists('pickup_finalization_block')) {
    /**
     * The handover finalization boundary, applied when a pickup advance would
     * ENTER PICKED_UP from READY_FOR_PICKUP.
     *
     * Entering PICKED_UP confirms the handover, records the completed sale,
     * and sends the PICKUP_HANDED_OVER notification. That must never happen
     * through the generic one-step advance when a dedicated finalizer owns
     * the step:
     *
     *   - a READY_FOR_PICKUP Cash on Pickup order whose payment is not yet
     *     PAID must go through payment_confirm_pickup_and_collect_cash (which
     *     records the cash collection); the generic advance would record the
     *     handover and sale while cash is still PENDING.
     *   - a READY_FOR_PICKUP verified GCash order must go through
     *     payment_confirm_pickup_gcash_and_complete_sale so the sale is
     *     recorded through the canonical verified-GCash finalizer.
     *
     * PURE decision over an already-loaded (locked) payments row; returns a
     * stable reason code or null when the generic advance may proceed. The
     * admin UI already suppresses the generic control in these states; this
     * is the same boundary enforced server-side for a crafted or stale POST.
     */
    function pickup_finalization_block(array $payment, string $nextStage, string $currentStage): ?string
    {
        if ($nextStage !== 'PICKED_UP' || $currentStage !== 'READY_FOR_PICKUP') {
            return null;
        }

        $method = (string) ($payment['payment_method'] ?? '');
        $status = (string) ($payment['payment_status'] ?? '');

        if ($method === 'CASH_ON_PICKUP' && $status !== 'PAID') {
            return 'cop_finalization_required';
        }

        if ($method === 'GCASH' && $status === 'PAID') {
            return 'pg_finalization_required';
        }

        return null;
    }
}

if (!function_exists('pickup_stage_labels')) {
    /**
     * Customer-facing labels for the pickup stages.
     */
    function pickup_stage_labels(): array
    {
        return [
            'ORDER_RECEIVED' => 'Order Received',
            'PREPARING' => 'Preparing for Pickup',
            'READY_FOR_PICKUP' => 'Ready for Pickup',
            'PICKED_UP' => 'Picked Up',
        ];
    }
}

if (!function_exists('pickup_stage_label')) {
    /**
     * One customer-facing label, with a safe fallback for an unknown value.
     */
    function pickup_stage_label(string $stage): string
    {
        return pickup_stage_labels()[$stage] ?? $stage;
    }
}

if (!function_exists('pickup_stage_badge_classes')) {
    /**
     * Existing badge classes for the pickup stages.
     */
    function pickup_stage_badge_classes(): array
    {
        return [
            'ORDER_RECEIVED' => 'badge-pending',
            'PREPARING' => 'badge-warning',
            'READY_FOR_PICKUP' => 'badge-info',
            'PICKED_UP' => 'badge-success',
        ];
    }
}

if (!function_exists('pickup_stage_badge_class')) {
    /**
     * One badge class, with a neutral fallback.
     */
    function pickup_stage_badge_class(string $stage): string
    {
        return pickup_stage_badge_classes()[$stage] ?? 'badge';
    }
}

if (!function_exists('pickup_stage_icons')) {
    /**
     * Existing hopia_order_icon keys for the pickup timeline nodes.
     */
    function pickup_stage_icons(): array
    {
        return [
            'ORDER_RECEIVED' => 'clock',
            'PREPARING' => 'package',
            'READY_FOR_PICKUP' => 'check',
            'PICKED_UP' => 'check-circle',
        ];
    }
}

if (!function_exists('pickup_stage_icon')) {
    /**
     * One timeline-node icon key, with a neutral fallback.
     */
    function pickup_stage_icon(string $stage): string
    {
        return pickup_stage_icons()[$stage] ?? 'package';
    }
}

if (!function_exists('pickup_is_pickup_order')) {
    /**
     * True when the order is fulfilled by Store Pickup, resolved from the
     * persisted shipments.delivery_method (the fulfillment method recorded at
     * checkout). Accepts the raw shipment row (or false) so callers can pass
     * their fetch result directly.
     */
    function pickup_is_pickup_order($shipment): bool
    {
        return is_array($shipment)
            && (string) ($shipment['delivery_method'] ?? '') === 'STORE_PICKUP';
    }
}

if (!function_exists('pickup_effective_stage')) {
    /**
     * The effective pickup stage for a pickup order. A legacy pickup order
     * created before migration 012 has pickup_status = NULL; it is treated
     * as ORDER_RECEIVED until first advanced, without any data rewrite.
     * Returns null for a non-pickup (courier) order row.
     */
    function pickup_effective_stage(?string $pickupStatus, bool $isPickup): ?string
    {
        if (!$isPickup) {
            return null;
        }

        if ($pickupStatus === null || $pickupStatus === '') {
            return 'ORDER_RECEIVED';
        }

        return in_array($pickupStatus, pickup_stages(), true) ? $pickupStatus : 'ORDER_RECEIVED';
    }
}

if (!function_exists('pickup_deadline_is_overdue')) {
    /**
     * True when the order is sitting at READY_FOR_PICKUP and its stored
     * 48-hour deadline has passed. Display-only signal for admin attention;
     * it never mutates anything. Orders in any other stage (including
     * PICKED_UP) are never overdue.
     */
    function pickup_deadline_is_overdue(?string $stage, ?string $deadlineAt, ?string $now = null): bool
    {
        if ($stage !== 'READY_FOR_PICKUP' || $deadlineAt === null || $deadlineAt === '') {
            return false;
        }

        $now = $now ?? date('Y-m-d H:i:s');

        return strtotime($deadlineAt) !== false && strtotime($now) !== false
            && strtotime($deadlineAt) < strtotime($now);
    }
}

if (!function_exists('pickup_progress')) {
    /**
     * The full pickup-progress description for one order, derived from the
     * order + shipment rows. Returns null for a courier order. On success:
     *   'is_pickup'        bool
     *   'stage'            string effective stage (legacy NULL resolves to
     *                      ORDER_RECEIVED)
     *   'stage_index'      int    0-based position in pickup_stages()
     *   'stage_label'      string
     *   'badge_class'      string
     *   'ready_at'         ?string stored ready timestamp
     *   'deadline_at'      ?string stored 48h deadline
     *   'picked_up_at'     ?string stored handover timestamp
     *   'overdue'          bool   READY_FOR_PICKUP past its deadline
     *   'cancelled'        bool   order status is CANCELLED (separate
     *                      terminal outcome; timeline suppressed)
     */
    function pickup_progress(array $order, $shipment): ?array
    {
        if (!pickup_is_pickup_order($shipment)) {
            return null;
        }

        $stage = pickup_effective_stage(
            isset($order['pickup_status']) ? (string) $order['pickup_status'] : null,
            true
        );

        $deadlineAt = isset($order['pickup_deadline_at']) && $order['pickup_deadline_at'] !== null
            ? (string) $order['pickup_deadline_at']
            : null;
        $readyAt = isset($order['ready_for_pickup_at']) && $order['ready_for_pickup_at'] !== null
            ? (string) $order['ready_for_pickup_at']
            : null;
        $pickedUpAt = isset($order['picked_up_at']) && $order['picked_up_at'] !== null
            ? (string) $order['picked_up_at']
            : null;

        $stageIndex = array_search($stage, pickup_stages(), true);
        $cancelled = (string) ($order['status'] ?? '') === 'CANCELLED';

        return [
            'is_pickup' => true,
            'stage' => $stage,
            'stage_index' => $stageIndex === false ? 0 : (int) $stageIndex,
            'stage_label' => pickup_stage_label($stage),
            'badge_class' => pickup_stage_badge_class($stage),
            'ready_at' => $readyAt,
            'deadline_at' => $deadlineAt,
            'picked_up_at' => $pickedUpAt,
            'overdue' => pickup_deadline_is_overdue($stage, $deadlineAt),
            'cancelled' => $cancelled,
        ];
    }
}

if (!function_exists('pickup_advance_status')) {
    /**
     * Advance one Store Pickup order's lifecycle by EXACTLY ONE step,
     * atomically. Shared by admin/pickups.php, admin/order-view.php, and the
     * Phase 3 tests, so the transition rules have one enforcement point.
     *
     * Transaction sequence:
     *   1. begin;
     *   2. lock the order row FOR UPDATE;
     *   3. reject when the order is not fulfilled by STORE_PICKUP (courier
     *      orders can never gain a pickup lifecycle), is CANCELLED, or has a
     *      pending/approved cancellation request;
     *   4. resolve the effective stage (legacy NULL = ORDER_RECEIVED) and
     *      compute the ONE valid next stage - skipping ahead is impossible;
     *   5. conditional UPDATE guarding the expected current pickup_status
     *      (NULL-safe for the legacy initial state), so a duplicate or
     *      racing advance is a no-op reported as 'conflict', never a double
     *      advance;
     *   6. set ready_for_pickup_at + pickup_deadline_at exactly once (only
     *      when ENTERING READY_FOR_PICKUP and both columns are still NULL),
     *      and picked_up_at once (when ENTERING PICKED_UP);
     *   7. commit.
     *
     * It writes ONLY the four pickup columns on orders (plus, when entering
     * PICKED_UP, the exactly-once ONLINE completed sale and receipt
     * timestamp through sales_complete_pickup_online_locked, in the same
     * transaction). Payment status, shipments, products, and the courier
     * status workflow are never touched: a pickup advance is never payment,
     * and the deadline passing (or any advance) never cancels or restocks
     * anything.
     *
     * Phase 3 barcode gate: entering PREPARING (the ORDER_RECEIVED ->
     * PREPARING step) additionally requires every order item to have a
     * committed verification row. This is the ONE server-side enforcement
     * point, so every existing advance entry point obeys it and a direct POST
     * cannot bypass the scanner.
     *
     * $requiredCurrentStage optionally pins the expected current stage (the
     * pickup-verification page always passes 'ORDER_RECEIVED'), so a stale
     * page cannot accidentally advance a later stage.
     *
     * Returns ['ok'=>true,...] or ['ok'=>false,'reason','message','status'].
     */
    function pickup_advance_status(PDO $pdo, int $orderId, ?string $requiredCurrentStage = null): array
    {
        $fail = static function (string $reason, int $status = 200): array {
            return ['ok' => false, 'reason' => $reason, 'message' => pickup_message($reason), 'status' => $status];
        };

        try {
            $pdo->beginTransaction();

            /* STEP 2 - lock the order row. */
            $orderStmt = $pdo->prepare(
                'SELECT id, status, cancellation_status, pickup_status,
                        ready_for_pickup_at, pickup_deadline_at, picked_up_at
                 FROM orders
                 WHERE id = :order_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $orderStmt->execute([':order_id' => $orderId]);
            $order = $orderStmt->fetch();

            if ($order === false) {
                $pdo->rollBack();
                return $fail('order_not_found', 404);
            }

            /* STEP 3 - fulfillment + cancellation guards under the lock. */
            $shipmentStmt = $pdo->prepare(
                'SELECT delivery_method
                 FROM shipments
                 WHERE order_id = :order_id
                 LIMIT 1'
            );
            $shipmentStmt->execute([':order_id' => $orderId]);
            $shipment = $shipmentStmt->fetch();

            if (!pickup_is_pickup_order($shipment)) {
                $pdo->rollBack();
                return $fail('not_pickup_order', 409);
            }

            if ((string) $order['status'] === 'CANCELLED') {
                $pdo->rollBack();
                return $fail('order_cancelled', 409);
            }

            if (in_array((string) $order['cancellation_status'], ['REQUESTED', 'APPROVED'], true)) {
                $pdo->rollBack();
                return $fail('cancellation_locked', 409);
            }

            /* STEP 4 - exactly one step forward. */
            $currentStage = pickup_effective_stage(
                $order['pickup_status'] !== null ? (string) $order['pickup_status'] : null,
                true
            );

            /* A caller that must act from one specific stage (the
               pickup-verification page) passes $requiredCurrentStage; a stale
               page cannot advance a later stage by accident. */
            if ($requiredCurrentStage !== null && $currentStage !== $requiredCurrentStage) {
                $pdo->rollBack();
                return $fail('stage_mismatch', 409);
            }

            $nextStage = pickup_next_stage($currentStage);

            if ($nextStage === null) {
                $pdo->rollBack();
                return $fail('already_terminal', 200);
            }

            /* STEP 4a - Phase 5 payment gate. A GCash order may not enter
               preparation, become ready for pickup, or be handed over until
               an admin has verified the submitted reference and screenshot.
               The payments row is locked AFTER the order row, matching the
               order-then-payment lock order of the verification helpers, so a
               concurrent confirm/reject cannot race this transition. COD and
               Cash on Pickup are not GCash and keep their established
               unpaid-before-collection workflows. */
            if (!function_exists('payment_gcash_fulfillment_block')) {
                require_once __DIR__ . '/payment.php';
            }

            $paymentBlock = payment_gcash_fulfillment_block($pdo, $orderId);

            if ($paymentBlock !== null) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'reason' => $paymentBlock,
                    'message' => payment_gcash_fulfillment_message($paymentBlock),
                    'status' => 409,
                ];
            }

            /* STEP 4b - barcode-verification gate for preparation entry. This
               runs under the same order row lock as the stage UPDATE, so a
               concurrent scan cannot change the count between this check and
               the transition. The disabled UI button is only a convenience;
               this is the authoritative guard. */
            if ($nextStage === 'PREPARING') {
                if (!function_exists('order_verify_progress')) {
                    require_once __DIR__ . '/order_verify.php';
                }

                $verification = order_verify_progress($pdo, $orderId);

                if (empty($verification['complete'])) {
                    $pdo->rollBack();
                    return $fail('verification_incomplete', 409);
                }
            }

            /* STEP 4c - finalization boundary at the handover. A generic
               advance is never allowed to hand over a READY_FOR_PICKUP order
               whose dedicated finalizer owns the step: an unpaid Cash on
               Pickup order must go through payment_confirm_pickup_and_collect_
               cash, and a verified GCash order through payment_confirm_pickup_
               gcash_and_complete_sale. The payments row is re-locked under the
               order lock so a concurrent payment change cannot race this check
               (see payment_gcash_fulfillment_block above, which uses the same
               lock order). The generic advance still works for every other
               transition (entering PREPARING, entering READY_FOR_PICKUP, and a
               handover whose payment state is already consistent with an
               explicit collection recorded elsewhere). */
            if ($nextStage === 'PICKED_UP' && $currentStage === 'READY_FOR_PICKUP') {
                if (!function_exists('payment_gcash_lock_payment')) {
                    require_once __DIR__ . '/payment.php';
                }

                $lockedPayment = payment_gcash_lock_payment($pdo, $orderId);

                if (is_array($lockedPayment)) {
                    $finalizationBlock = pickup_finalization_block($lockedPayment, $nextStage, $currentStage);

                    if ($finalizationBlock !== null) {
                        $pdo->rollBack();
                        return $fail($finalizationBlock, 409);
                    }
                }
            }

            /* STEP 5/6 - one conditional UPDATE for stage + timestamps. The
               WHERE guards the expected current pickup_status, so a stale
               duplicate submission cannot double-advance. */
            $expectedPickupStatus = $order['pickup_status'] !== null ? (string) $order['pickup_status'] : null;

            $sets = ['pickup_status = :next_stage'];
            $params = [
                ':next_stage' => $nextStage,
                ':order_id' => $orderId,
            ];

            if ($expectedPickupStatus === null) {
                $stageGuard = 'pickup_status IS NULL';
            } else {
                $stageGuard = 'pickup_status = :expected_stage';
                $params[':expected_stage'] = $expectedPickupStatus;
            }

            if ($nextStage === 'READY_FOR_PICKUP') {
                /* Computed + stored exactly once; later refreshes and
                   unrelated updates only ever read it. */
                $sets[] = 'ready_for_pickup_at = COALESCE(ready_for_pickup_at, NOW())';
                $sets[] = 'pickup_deadline_at = COALESCE(pickup_deadline_at, DATE_ADD(NOW(), INTERVAL 48 HOUR))';
            }

            if ($nextStage === 'PICKED_UP') {
                $sets[] = 'picked_up_at = COALESCE(picked_up_at, NOW())';
            }

            $update = $pdo->prepare(
                'UPDATE orders
                 SET ' . implode(', ', $sets) . '
                 WHERE id = :order_id
                  AND ' . $stageGuard . '
                    AND status <> \'CANCELLED\''
            );
            $update->execute($params);

            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return $fail('conflict', 409);
            }

            /* STEP 6b - completed online sale, exactly once, at the moment
               of handover. The handover IS the customer receipt event for a
               Store Pickup order, so the ONLINE sales_transactions header
               and details are created (or verified, when a prior completion
               exists) inside THIS transaction, using the same snapshot and
               merchandise-only-total rules as the courier receipt path. A
               failure aborts the whole handover: picked_up_at and the sale
               commit together or not at all. Payment status is NOT touched
               here; Cash on Pickup collection stays its own explicit
               admin action (payment_collect_cash_on_pickup). */
            if ($nextStage === 'PICKED_UP') {
                if (!function_exists('sales_complete_pickup_online_locked')) {
                    require_once __DIR__ . '/sales.php';
                }

                $pickedUpAt = date('Y-m-d H:i:s');
                $completion = sales_complete_pickup_online_locked($pdo, $orderId, $pickedUpAt);

                if (empty($completion['ok'])) {
                    $pdo->rollBack();
                    return $fail('completion_failed', 500);
                }
            }

            /*
             * Phase 14: record the customer notification for the committed
             * transition in the SAME transaction. READY_FOR_PICKUP notifies
             * the customer to collect; PICKED_UP notifies that the handover
             * succeeded. Each is created only after the transition UPDATE
             * above has applied, and only for the step actually reached, so
             * preparation or barcode-verification alone never notifies. A
             * failure aborts the whole advance: the stage change and the
             * notification commit or roll back together.
             */
            if ($nextStage === 'READY_FOR_PICKUP' || $nextStage === 'PICKED_UP') {
                if (!function_exists('customer_notifications_create')) {
                    require_once __DIR__ . '/customer_notifications.php';
                }

                customer_notifications_create(
                    $pdo,
                    $orderId,
                    $nextStage === 'PICKED_UP' ? 'PICKUP_HANDED_OVER' : 'PICKUP_READY'
                );
            }

            $pdo->commit();

            return [
                'ok' => true,
                'previous_stage' => $currentStage,
                'stage' => $nextStage,
                'status' => 200,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'reason' => 'error', 'message' => pickup_message('error'), 'status' => 500];
        }
    }
}

if (!function_exists('pickup_message')) {
    /**
     * Map a stable machine reason code to a short, admin/customer-safe
     * message. No raw SQL error is ever surfaced; database failures
     * collapse to 'error'.
     */
    function pickup_message(string $reason): string
    {
        switch ($reason) {
            case 'order_not_found':
                return 'The order could not be found.';
            case 'not_pickup_order':
                return 'This order is not a Store Pickup order.';
            case 'order_cancelled':
                return 'This order has been cancelled and its pickup status cannot be changed.';
            case 'cancellation_locked':
                return 'This order has a pending or approved cancellation and cannot be advanced.';
            case 'already_terminal':
                return 'This order has already been picked up.';
            case 'verification_incomplete':
                return 'Every item must be scanned and verified before pickup preparation can start.';
            case 'stage_mismatch':
                return 'This order is no longer eligible for pickup preparation. Please reload and review it.';
            case 'cop_finalization_required':
                return 'This order is ready for pickup and still owes its Cash on Pickup payment. Record the cash collection through the dedicated confirmation action; the generic handover advance cannot record payment.';
            case 'pg_finalization_required':
                return 'This order is ready for pickup with a verified GCash payment. Confirm the handover through the dedicated confirmation action so the completed sale is recorded.';
            case 'conflict':
                return 'The pickup status was updated by another request. Please reload the page.';
            case 'completion_failed':
                return 'The handover could not be recorded because the completed sale could not be created. Please try again.';
            case 'error':
            default:
                return 'The pickup status could not be updated. Please try again.';
        }
    }
}

if (!function_exists('pickup_verify_require_item_helpers')) {
    /**
     * Load the shared item-verification helpers on demand. includes/pickup.php
     * is also included by customer pages, so the courier verification include
     * is only pulled in when a pickup verification function actually needs it.
     */
    function pickup_verify_require_item_helpers(): void
    {
        if (!function_exists('order_verify_progress')) {
            require_once __DIR__ . '/order_verify.php';
        }
    }
}

if (!function_exists('pickup_verify_order_error')) {
    /**
     * Classify why a Store Pickup order cannot enter the barcode-verification
     * workflow, or null when it can. The authoritative re-check runs under the
     * order row lock inside pickup_verify_record() and pickup_advance_status(),
     * never from page state.
     *
     *   order_not_found          no such order.
     *   not_pickup_order         fulfillment is not STORE_PICKUP.
     *   order_cancelled          orders.status is CANCELLED.
     *   cancellation_locked      cancellation is REQUESTED or APPROVED.
     *   gcash_*                  the GCash payment is not verified (Phase 5).
     *   stage_not_order_received effective pickup stage is not ORDER_RECEIVED.
     *
     * $payment is optional so existing two-argument callers keep working. When
     * provided, a GCash payment that is not PAID blocks the preparation
     * workspace; callers that mutate state pass the row they locked.
     */
    function pickup_verify_order_error($order, $shipment, $payment = null): ?string
    {
        if (!is_array($order) || !isset($order['id'])) {
            return 'order_not_found';
        }

        if (!pickup_is_pickup_order($shipment)) {
            return 'not_pickup_order';
        }

        if ((string) ($order['status'] ?? '') === 'CANCELLED') {
            return 'order_cancelled';
        }

        if (in_array((string) ($order['cancellation_status'] ?? 'NONE'), ['REQUESTED', 'APPROVED'], true)) {
            return 'cancellation_locked';
        }

        if (is_array($payment)) {
            if (!function_exists('payment_gcash_fulfillment_reason')) {
                require_once __DIR__ . '/payment.php';
            }

            $paymentBlock = payment_gcash_fulfillment_reason($payment);
            if ($paymentBlock !== null) {
                return $paymentBlock;
            }
        }

        $stage = pickup_effective_stage(
            isset($order['pickup_status']) && $order['pickup_status'] !== null
                ? (string) $order['pickup_status']
                : null,
            true
        );

        if ($stage !== 'ORDER_RECEIVED') {
            return 'stage_not_order_received';
        }

        return null;
    }
}

if (!function_exists('pickup_verify_message')) {
    /**
     * Map a pickup-verification machine reason to an admin-facing message.
     * Centralized so the page and the scan endpoint phrase every outcome the
     * same way and never leak a raw SQL error.
     */
    function pickup_verify_message(string $reason): string
    {
        switch ($reason) {
            case 'order_not_found':
                return 'That order could not be found.';
            case 'not_pickup_order':
                return 'This order is not a Store Pickup order.';
            case 'order_cancelled':
            case 'cancellation_locked':
            case 'stage_not_order_received':
            case 'stage_mismatch':
                return 'This order is no longer eligible for pickup preparation.';
            case 'not_found':
                return 'This barcode is not recognized.';
            case 'unexpected_item':
                return 'This barcode does not belong to this order.';
            case 'already_verified':
                return 'This item has already been verified.';
            case 'no_barcode':
                return 'This item has no barcode assigned and cannot be scanned yet.';
            case 'verification_incomplete':
                return 'Every item must be scanned and verified before pickup preparation can start.';
            case 'order_item_missing':
                return 'That order item could not be found for this order.';
            case 'gcash_awaiting_verification':
            case 'gcash_rejected':
                if (!function_exists('payment_gcash_fulfillment_message')) {
                    require_once __DIR__ . '/payment.php';
                }
                return payment_gcash_fulfillment_message($reason);
            case 'bad_request':
                return 'The verification request was invalid. Please reload the page and try again.';
            case 'error':
                return 'The verification could not be completed. Please try again.';
            default:
                return 'That item could not be verified.';
        }
    }
}

if (!function_exists('pickup_verify_item_rows')) {
    /**
     * The expected-item checklist for one order, including unscannable rows.
     * Delegates to the shared courier checklist so both fulfillment methods
     * read order_items + order_item_verifications identically. Pickup and
     * courier orders are disjoint (a pickup order's orders.status stays
     * PENDING and a courier order never has STORE_PICKUP), so the shared
     * UNIQUE(order_item_id) verification table can never mix the two states
     * for the same item.
     */
    function pickup_verify_item_rows(PDO $pdo, int $orderId): array
    {
        pickup_verify_require_item_helpers();

        return order_verify_item_rows($pdo, $orderId);
    }
}

if (!function_exists('pickup_verify_progress')) {
    /**
     * Authoritative pickup-verification progress for one order, computed from
     * the database. complete is true only when expected > 0 and every expected
     * order item has a committed verification row.
     */
    function pickup_verify_progress(PDO $pdo, int $orderId): array
    {
        pickup_verify_require_item_helpers();

        return order_verify_progress($pdo, $orderId);
    }
}

if (!function_exists('pickup_verify_blocking_items')) {
    /**
     * Names of unverified items that cannot be scanned because they have no
     * usable barcode. Used only to explain which item blocks preparation;
     * nothing is ever marked verified automatically.
     */
    function pickup_verify_blocking_items(array $items): array
    {
        $blocking = [];

        foreach ($items as $item) {
            if (!empty($item['verified'])) {
                continue;
            }

            if (empty($item['scannable'])) {
                $blocking[] = (string) ($item['product_name'] ?? ('Item #' . (int) ($item['order_item_id'] ?? 0)));
            }
        }

        return $blocking;
    }
}

if (!function_exists('pickup_verify_eligible_orders')) {
    /**
     * Store Pickup orders awaiting preparation entry: effective pickup stage
     * ORDER_RECEIVED, not cancelled, and without a pending/approved
     * cancellation. Each row carries its expected/verified item counts from
     * the same shared progress helper the scanner uses.
     */
    function pickup_verify_eligible_orders(PDO $pdo): array
    {
        pickup_verify_require_item_helpers();

        $rows = pickup_admin_list(
            $pdo,
            "COALESCE(o.pickup_status, 'ORDER_RECEIVED') = 'ORDER_RECEIVED'
             AND o.status <> 'CANCELLED'
             AND o.cancellation_status NOT IN ('REQUESTED', 'APPROVED')
             AND (p.payment_method IS NULL OR p.payment_method <> 'GCASH' OR p.payment_status = 'PAID')"
        );

        foreach ($rows as &$row) {
            $progress = order_verify_progress($pdo, (int) $row['id']);
            $row['expected'] = $progress['expected'];
            $row['verified'] = $progress['verified'];
            $row['remaining'] = $progress['remaining'];
            $row['complete'] = $progress['complete'];
            $row['stage'] = 'ORDER_RECEIVED';
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('pickup_verify_record')) {
    /**
     * Record ONE SCANNED pickup-item verification, atomically.
     *
     * Sequence:
     *   1. begin;
     *   2. lock the order row FOR UPDATE (scans and the preparation transition
     *      therefore serialize on the same row);
     *   3. revalidate Store Pickup + ORDER_RECEIVED + cancellation state
     *      against the locked row;
     *   4. resolve + lock the product carrying the scanned barcode;
     *   5. find & lock the expected order_items row(s) in THIS order;
     *   6. choose the first not-yet-verified matching item;
     *   7. insert exactly one SCANNED verification row;
     *   8. recompute progress from the database and commit.
     *
     * The barcode never resolves to another order's item: the security
     * boundary is an order_items row in the selected order. The verification
     * table's UNIQUE(order_item_id) makes a duplicate/racing scan collapse to
     * 'already_verified'. Nothing else is written: no product status change,
     * no reservation, no stock movement, no payment, no sale.
     */
    function pickup_verify_record(PDO $pdo, int $adminId, int $orderId, string $barcode): array
    {
        pickup_verify_require_item_helpers();

        $abort = static function (array $payload) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!isset($payload['status'])) {
                $payload['status'] = 200;
            }
            return $payload;
        };

        /* Only trim; the stored barcode is compared verbatim. */
        $barcode = sales_normalize_barcode($barcode);

        if (!sales_is_plausible_barcode($barcode)) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => pickup_verify_message('not_found'), 'status' => 200];
        }

        try {
            $pdo->beginTransaction();

            $orderStmt = $pdo->prepare(
                'SELECT id, status, cancellation_status, pickup_status
                 FROM orders
                 WHERE id = :order_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $orderStmt->execute([':order_id' => $orderId]);
            $order = $orderStmt->fetch();

            if ($order === false) {
                return $abort(['ok' => false, 'reason' => 'order_not_found', 'message' => pickup_verify_message('order_not_found'), 'status' => 404]);
            }

            $shipmentStmt = $pdo->prepare(
                'SELECT delivery_method FROM shipments WHERE order_id = :order_id LIMIT 1'
            );
            $shipmentStmt->execute([':order_id' => $orderId]);
            $shipment = $shipmentStmt->fetch();

            /* Phase 5: lock and re-check the payment row under the same order
               lock. An unverified/rejected GCash payment blocks preparation
               scans as well as the later stage transition. */
            if (!function_exists('payment_gcash_lock_payment')) {
                require_once __DIR__ . '/payment.php';
            }
            $payment = payment_gcash_lock_payment($pdo, $orderId);

            $orderError = pickup_verify_order_error($order, $shipment, $payment === false ? null : $payment);
            if ($orderError !== null) {
                return $abort(['ok' => false, 'reason' => $orderError, 'message' => pickup_verify_message($orderError), 'status' => 409]);
            }

            $productStmt = $pdo->prepare(
                'SELECT id, barcode_value FROM products WHERE barcode_value = :barcode LIMIT 1 FOR UPDATE'
            );
            $productStmt->execute([':barcode' => $barcode]);
            $product = $productStmt->fetch();

            if ($product === false) {
                return $abort(['ok' => false, 'reason' => 'not_found', 'message' => pickup_verify_message('not_found'), 'status' => 200]);
            }

            $productId = (int) $product['id'];

            $itemStmt = $pdo->prepare(
                'SELECT id FROM order_items
                 WHERE order_id = :order_id AND product_id = :product_id
                 ORDER BY id ASC
                 FOR UPDATE'
            );
            $itemStmt->execute([':order_id' => $orderId, ':product_id' => $productId]);
            $candidateIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN));

            if (count($candidateIds) === 0) {
                return $abort(['ok' => false, 'reason' => 'unexpected_item', 'message' => pickup_verify_message('unexpected_item'), 'status' => 200]);
            }

            $chosenItemId = null;
            foreach ($candidateIds as $candidateId) {
                $vStmt = $pdo->prepare('SELECT 1 FROM order_item_verifications WHERE order_item_id = :oiid LIMIT 1');
                $vStmt->execute([':oiid' => $candidateId]);
                if ($vStmt->fetchColumn() === false) {
                    $chosenItemId = $candidateId;
                    break;
                }
            }

            if ($chosenItemId === null) {
                return $abort(['ok' => false, 'reason' => 'already_verified', 'message' => pickup_verify_message('already_verified'), 'status' => 200]);
            }

            try {
                $insert = $pdo->prepare(
                    'INSERT INTO order_item_verifications
                        (order_id, order_item_id, product_id, barcode_value, verified_by, method, reason)
                     VALUES
                        (:order_id, :order_item_id, :product_id, :barcode, :verified_by, \'SCANNED\', NULL)'
                );
                $insert->execute([
                    ':order_id' => $orderId,
                    ':order_item_id' => $chosenItemId,
                    ':product_id' => $productId,
                    ':barcode' => $barcode,
                    ':verified_by' => $adminId,
                ]);
            } catch (PDOException $e) {
                $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

                /* Only the UNIQUE(order_item_id) duplicate (MySQL/MariaDB
                   error 1062) means "already verified"; any other integrity
                   failure is a real error and must not be disguised. */
                if ($e->getCode() === '23000' && $driverCode === 1062) {
                    return $abort(['ok' => false, 'reason' => 'already_verified', 'message' => pickup_verify_message('already_verified'), 'status' => 200]);
                }
                throw $e;
            }

            $verificationId = (int) $pdo->lastInsertId();
            $display = order_verify_fetch_display($pdo, $verificationId);
            $progress = order_verify_progress($pdo, $orderId);

            $pdo->commit();

            return [
                'ok' => true,
                'status' => 200,
                'method' => 'SCANNED',
                'order_item_id' => $chosenItemId,
                'product_id' => $productId,
                'barcode' => $barcode,
                'verification' => $display,
                'progress' => $progress,
                'message' => 'Item verified successfully.',
            ];
        } catch (Throwable $e) {
            return $abort(['ok' => false, 'reason' => 'error', 'message' => pickup_verify_message('error'), 'status' => 500]);
        }
    }
}

if (!function_exists('pickup_verify_begin_preparing')) {
    /**
     * The final preparation action for one pickup order.
     *
     * A read-only pre-check produces a precise message (including which
     * unscannable item blocks preparation), then pickup_advance_status() runs
     * the authoritative transactional check and the ORDER_RECEIVED ->
     * PREPARING transition. The required-current-stage pin prevents a stale
     * page from advancing an order that already moved on.
     */
    function pickup_verify_begin_preparing(PDO $pdo, int $orderId): array
    {
        pickup_verify_require_item_helpers();

        $orderStmt = $pdo->prepare(
            'SELECT id, status, cancellation_status, pickup_status
             FROM orders
             WHERE id = :order_id
             LIMIT 1'
        );
        $orderStmt->execute([':order_id' => $orderId]);
        $order = $orderStmt->fetch();

        if ($order === false) {
            return ['ok' => false, 'reason' => 'order_not_found', 'message' => pickup_verify_message('order_not_found'), 'status' => 404];
        }

        $shipmentStmt = $pdo->prepare(
            'SELECT delivery_method FROM shipments WHERE order_id = :order_id LIMIT 1'
        );
        $shipmentStmt->execute([':order_id' => $orderId]);
        $shipment = $shipmentStmt->fetch();

        /* Phase 5 pre-check; pickup_advance_status() re-checks under lock. */
        if (!function_exists('payment_load')) {
            require_once __DIR__ . '/payment.php';
        }
        $payment = payment_load($pdo, $orderId);

        $orderError = pickup_verify_order_error($order, $shipment, $payment);
        if ($orderError !== null) {
            return ['ok' => false, 'reason' => $orderError, 'message' => pickup_verify_message($orderError), 'status' => 409];
        }

        $items = order_verify_item_rows($pdo, $orderId);
        $blocking = pickup_verify_blocking_items($items);
        $progress = order_verify_progress($pdo, $orderId);

        if (empty($progress['complete'])) {
            if (count($blocking) > 0) {
                $shown = array_slice($blocking, 0, 5);
                $message = 'This item has no barcode assigned and cannot be scanned yet: '
                    . implode(', ', $shown)
                    . (count($blocking) > count($shown) ? ' and ' . (count($blocking) - count($shown)) . ' more' : '')
                    . '. Assign a barcode before preparing this pickup.';
            } else {
                $message = pickup_verify_message('verification_incomplete');
            }

            return ['ok' => false, 'reason' => 'verification_incomplete', 'message' => $message, 'status' => 409];
        }

        return pickup_advance_status($pdo, $orderId, 'ORDER_RECEIVED');
    }
}

if (!function_exists('pickup_admin_list')) {
    /**
     * Every Store Pickup order (newest first) with the fields the admin
     * pickup section needs: order number, customer, dates, the pickup
     * lifecycle columns, and the payment method/status. Payment comes from
     * the order's payments row (LEFT JOIN: a missing row stays honest
     * instead of fabricating one). The derived stage/overdue flags are
     * computed by the caller through pickup_progress() so the SQL and the
     * PHP rules can never drift.
     *
     * $extraWhere / $params let the admin page apply an allowlisted queue
     * predicate from includes/order_queues.php to the SAME base query, so a
     * dashboard card's count and this list cannot disagree. The predicate
     * uses the aliases o, s, and p and is always built from a fixed
     * definition, never from raw request input.
     */
    function pickup_admin_list(PDO $pdo, string $extraWhere = '', array $params = []): array
    {
        $sql =
            'SELECT o.id,
                    o.status,
                    o.cancellation_status,
                    o.total_amount,
                    o.created_at,
                    o.received_at,
                    o.pickup_status,
                    o.ready_for_pickup_at,
                    o.pickup_deadline_at,
                    o.picked_up_at,
                    CONCAT(c.first_name, \' \', c.last_name) AS customer_name,
                    c.email AS customer_email,
                    p.payment_method,
                    p.payment_status,
                    p.gcash_reference,
                    p.submitted_at,
                    p.verified_at,
                    p.verified_by,
                    p.revision,
                    EXISTS(SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id) AS has_completed_sale
             FROM orders o
             INNER JOIN customers c ON c.id = o.customer_id
             INNER JOIN shipments s ON s.order_id = o.id
             LEFT JOIN payments p ON p.order_id = o.id
             WHERE s.delivery_method = \'STORE_PICKUP\'';

        if ($extraWhere !== '') {
            $sql .= ' AND (' . $extraWhere . ')';
        }

        $sql .=
            ' ORDER BY
                 (o.pickup_status = \'READY_FOR_PICKUP\') DESC,
                 o.created_at DESC,
                 o.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
