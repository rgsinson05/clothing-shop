<?php

/*
 * Hopia Fits - manual GCash verification and Phase 6 recovery helpers.
 *
 * Include-only: this file declares functions and performs no output and no
 * side effects, matching includes/sales.php, includes/order_verify.php, and
 * includes/shipping.php. It is the SINGLE SOURCE OF TRUTH for:
 *   - GCash reference validation,
 *   - the private payment-proof upload + retrieval rules,
 *   - the derived GCash payment state (not submitted / awaiting / paid /
 *     rejected) from the EXISTING payments columns, and
 *   - the transactional payment write paths:
 *       * customer submission       (payment_gcash_submit)
 *       * admin confirm as PAID      (payment_gcash_verify)
 *       * admin reject / request fix (payment_gcash_reject)
 *       * customer cash recovery     (payment_gcash_switch_to_cash)
 *       * admin COD collection/sale  (payment_collect_cod_and_complete_sale)
 *
 * It is required by customer/payment-submit.php, admin/order-view.php, the
 * proof streaming endpoints, and the Phase 2 tests, so the ownership checks,
 * state rules, and locking can never drift between the pages, the endpoints,
 * and the tests.
 *
 * CRITICAL invariants (Phase 2 brief):
 *   - Reuses the ONE existing payments row per order (UNIQUE(order_id)); it
 *     never creates a competing payment record.
 *   - A GCash payment NEVER becomes PAID automatically. Submitting a
 *     reference or screenshot keeps payment_status = 'PENDING'.
 *   - Only payment_gcash_verify() (admin) sets a GCash payment to 'PAID'. It never changes
 *     orders.status, shipments, products.status, or sales_transactions, so
 *     verification can never fabricate a completed sale or collected revenue.
 *   - A cancelled order and an already-PAID order reject every mutation.
 *   - Payment proof is stored OUTSIDE the public image flow and is served
 *     only through authenticated endpoints.
 */

if (!defined('PAYMENT_PROOF_MAX_BYTES')) {
    // 5 MB, consistent with the product/feedback image flow.
    define('PAYMENT_PROOF_MAX_BYTES', 5 * 1024 * 1024);
}

if (!function_exists('payment_message')) {
    /**
     * Map a stable machine reason code to a short, user-safe message. No raw
     * SQL error is ever surfaced; database failures collapse to 'error'.
     */
    function payment_message(string $reason): string
    {
        switch ($reason) {
            case 'not_found':
                return 'Order not found or access denied.';
            case 'not_gcash':
                return 'This order is not a GCash payment.';
            case 'not_cash_on_pickup':
                return 'This order is not a Cash on Pickup payment.';
            case 'cop_not_pickup':
                return 'Pickup and cash confirmation is only available for Store Pickup orders.';
            case 'cop_not_cash_on_pickup':
                return 'This order is not a Cash on Pickup payment.';
            case 'cop_already_completed':
                return 'This pickup and its completed sale have already been recorded. No changes were made.';
            case 'cop_sale_without_collection':
                return 'A completed sale already exists for this order, but the cash collection is not recorded as paid. Manual review is required; no records were changed.';
            case 'cop_collection_conflict':
                return 'This order has payment collection data but no completed sale. Manual review is required; no records were changed.';
            case 'cop_not_pending':
                return 'This Cash on Pickup payment is not pending and cannot be collected.';
            case 'cop_cancellation_locked':
                return 'Pickup confirmation is unavailable for a cancelled order or one with a pending or approved cancellation.';
            case 'cop_not_ready':
                return 'Pickup and cash collection can only be confirmed when the order is Ready for Pickup.';
            case 'cop_verification_incomplete':
                return 'Every item must be scanned and verified before pickup and cash collection can be confirmed.';
            case 'cop_admin_required':
                return 'An authorized administrator must confirm pickup and cash collection.';
            case 'cop_conflict':
                return 'The pickup or payment state changed while you were confirming. Reload the order and review its current state.';
            case 'cop_error':
                return 'Pickup confirmation, cash collection, and sale completion could not be saved. Reload the order to check its current state before retrying.';
            case 'not_cod':
                return 'This order is not a Cash on Delivery payment.';
            case 'cod_not_courier':
                return 'COD collection is only available for Local Courier orders with a shipment record.';
            case 'cod_not_delivered':
                return 'COD collection can only be confirmed after the order and shipment are marked Delivered.';
            case 'cod_receipt_pending':
                return 'The customer must confirm Order Received before COD collection and sale completion.';
            case 'cod_cancellation_locked':
                return 'COD collection is unavailable for a cancelled order or one with a pending or approved cancellation.';
            case 'cod_already_completed':
                return 'COD collection and the completed sale have already been recorded. No changes were made.';
            case 'cod_sale_without_collection':
                return 'A completed sale already exists for this order, but COD collection is not recorded as paid. Manual review is required; no records were changed.';
            case 'cod_collection_conflict':
                return 'This order has payment collection data but no completed sale. Manual review is required; no records were changed.';
            case 'cod_not_pending':
                return 'This COD payment is not pending and cannot be collected.';
            case 'gcash_not_courier':
                return 'Sale completion is only available for Local Courier GCash orders with a shipment record.';
            case 'gcash_not_verified':
                return 'The GCash payment must first be verified as paid before the sale can be finalized.';
            case 'gcash_not_delivered':
                return 'The sale can only be finalized after the order and shipment are marked Delivered.';
            case 'gcash_receipt_pending':
                return 'The customer must confirm Order Received before the GCash sale can be finalized.';
            case 'gcash_cancellation_locked':
                return 'Sale completion is unavailable for a cancelled order or one with a pending or approved cancellation.';
            case 'gcash_already_completed':
                return 'This GCash order has already been finalized and recorded in Sales Monitoring. No changes were made.';
            case 'gcash_admin_required':
                return 'An authorized administrator must finalize the GCash sale.';
            case 'gcash_error':
                return 'The GCash sale could not be finalized. Reload the order to check its current state before retrying.';
            case 'cod_admin_required':
                return 'An authorized administrator must confirm COD collection.';
            case 'cod_error':
                return 'COD collection and sale completion could not be confirmed. Reload the order to check its current state before retrying.';
            case 'pg_not_pickup':
                return 'This action is only available for Store Pickup orders.';
            case 'pg_not_gcash':
                return 'This order is not a GCash payment.';
            case 'pg_not_verified':
                return 'The GCash payment must be verified as paid before pickup and sale completion can be confirmed.';
            case 'pg_not_ready':
                return 'Pickup and sale completion can only be confirmed when the order is Ready for Pickup.';
            case 'pg_already_completed':
                return 'This pickup and its completed sale have already been recorded in Sales Monitoring. No changes were made.';
            case 'pg_cancellation_locked':
                return 'Pickup confirmation is unavailable for a cancelled order or one with a pending or approved cancellation.';
            case 'pg_verification_incomplete':
                return 'Every item must be scanned and verified before pickup and sale completion can be confirmed.';
            case 'pg_admin_required':
                return 'An authorized administrator must confirm pickup and sale completion.';
            case 'pg_conflict':
                return 'The pickup or payment state changed while you were confirming. Reload the order and review its current state.';
            case 'pg_error':
                return 'Pickup confirmation and sale completion could not be saved. Reload the order to check its current state before retrying.';
            case 'not_pickup_order':
                return 'This order is not a Store Pickup order.';
            case 'not_picked_up':
                return 'Cash can only be recorded as collected after the order has been picked up.';
            case 'order_cancelled':
                return 'This order has been cancelled and its payment cannot be changed.';
            case 'already_paid':
                return 'This payment has already been verified as paid.';
            case 'not_submitted':
                return 'No GCash reference has been submitted for this order yet.';
            case 'reference_required':
                return 'Enter the GCash reference number.';
            case 'reference_invalid':
                return 'That GCash reference number looks invalid. Enter the reference exactly as shown in your GCash receipt.';
            case 'reason_required':
                return 'Enter a reason for rejecting this payment.';
            case 'reason_too_long':
                return 'The reason must be 255 characters or fewer.';
            case 'proof_invalid_type':
                return 'The screenshot must be a JPG, PNG, or WebP image.';
            case 'proof_too_large':
                return 'The screenshot must be 5 MB or smaller.';
            case 'proof_failed':
                return 'The screenshot could not be uploaded. Please try again.';
            case 'not_pending':
                return 'This payment is not awaiting verification.';
            case 'already_submitted':
                return 'Your GCash payment is already awaiting verification. Please wait for the shop to review it.';
            case 'not_rejected':
                return 'Only a rejected, unverified GCash payment can use this recovery option.';
            case 'order_incompatible':
                return 'Payment recovery is not available at this stage of the order. Please contact the shop.';
            case 'cancellation_pending':
                return 'A cancellation request is pending. Please wait for the shop to review it before changing payment.';
            case 'deadline_expired':
                return 'The 24-hour GCash resubmission period has expired. Please contact the shop or use an available cash payment option.';
            case 'deadline_unknown':
                return 'The resubmission deadline is unavailable. Please contact the shop for assistance.';
            case 'deadline_not_expired':
                return 'The 24-hour GCash resubmission period has not expired. This cancellation action is not available yet.';
            case 'cash_method_invalid':
                return 'The cash payment option must match this order\'s fulfillment method.';
            case 'cancellation_reason_required':
                return 'Enter a reason for cancelling this order.';
            case 'admin_required':
                return 'An authorized administrator must cancel this order.';
            case 'conflict':
                return 'This payment was updated by another request. Please reload the page.';
            case 'bad_request':
                return 'The request was invalid. Please reload the page and try again.';
            case 'error':
            default:
                return 'The payment could not be updated. Please try again.';
        }
    }
}

/* ============================================================
 * GCash reference validation
 * ============================================================ */

if (!function_exists('payment_normalize_reference')) {
    /**
     * Trim and collapse internal whitespace in a submitted GCash reference so
     * a copy/paste with stray spaces/newlines resolves to a canonical value.
     */
    function payment_normalize_reference($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return $value === null ? '' : $value;
    }
}

if (!function_exists('payment_is_valid_reference')) {
    /**
     * Structural validity of a NORMALIZED GCash reference. GCash references
     * are typically a 13-digit number, but entries vary (spaces, dashes), so
     * the rule is intentionally lenient yet safe: 6-64 characters, letters,
     * digits, spaces and dashes only, and at least one alphanumeric character.
     * Length-bounded to the gcash_reference VARCHAR(100) column.
     */
    function payment_is_valid_reference(string $reference): bool
    {
        if ($reference === '' || strlen($reference) < 6 || strlen($reference) > 64) {
            return false;
        }

        if (preg_match('/[^A-Za-z0-9 \-]/', $reference) === 1) {
            return false;
        }

        // Must carry real content, not only spaces/dashes.
        return preg_match('/[A-Za-z0-9]/', $reference) === 1;
    }
}

/* ============================================================
 * Private payment-proof storage (NOT the public images/ flow)
 * ============================================================ */

if (!function_exists('payment_proof_dir')) {
    /**
     * Absolute path to the PRIVATE proof directory. It lives under storage/
     * (guarded by a deny-all .htaccess) and is never linked directly; proofs
     * are served only through authenticated streaming endpoints.
     */
    function payment_proof_dir(): string
    {
        return __DIR__ . '/../storage/payment_proofs';
    }
}

if (!function_exists('payment_proof_allowed_types')) {
    /**
     * The real content types accepted for a payment screenshot, mapped to the
     * extension actually written. Validated against file CONTENTS with finfo;
     * the client MIME and original filename are never trusted.
     */
    function payment_proof_allowed_types(): array
    {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
    }
}

if (!function_exists('payment_proof_validate')) {
    /**
     * Pure validation of an uploaded proof, independent of is_uploaded_file()
     * so it is unit-testable with a real temp file. Confirms the upload
     * succeeded, the size is within the cap, and the ACTUAL contents are a
     * supported image. Returns ['ok'=>true,'ext'=>'png'] or
     * ['ok'=>false,'reason'=>'<code>'].
     */
    function payment_proof_validate(string $tmpPath, int $size, int $uploadError): array
    {
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'reason' => 'proof_failed'];
        }

        if ($tmpPath === '' || !is_file($tmpPath)) {
            return ['ok' => false, 'reason' => 'proof_failed'];
        }

        $actualSize = filesize($tmpPath);
        if ($actualSize === false || $actualSize <= 0) {
            return ['ok' => false, 'reason' => 'proof_failed'];
        }

        if ($size > PAYMENT_PROOF_MAX_BYTES || $actualSize > PAYMENT_PROOF_MAX_BYTES) {
            return ['ok' => false, 'reason' => 'proof_too_large'];
        }

        $allowed = payment_proof_allowed_types();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);

        if (!is_string($mime) || !isset($allowed[$mime])) {
            return ['ok' => false, 'reason' => 'proof_invalid_type'];
        }

        return ['ok' => true, 'ext' => $allowed[$mime]];
    }
}

if (!function_exists('payment_proof_store')) {
    /**
     * Validate and move ONE optional uploaded proof into the private directory
     * under a random filename. Returns:
     *   ['ok'=>true,  'path'=>'storage/payment_proofs/<rand>.png']  (stored)
     *   ['ok'=>true,  'path'=>null]                                 (no file)
     *   ['ok'=>false, 'reason'=>'<code>']                           (rejected)
     *
     * The original filename and client MIME are discarded, so a disguised
     * executable can never be written with a runnable name or extension.
     */
    function payment_proof_store(?array $file): array
    {
        // No file provided is NOT an error: the screenshot is optional.
        if (
            $file === null
            || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
            || ($file['name'] ?? '') === ''
        ) {
            return ['ok' => true, 'path' => null];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        // Must be a genuine HTTP upload: blocks pointing at an arbitrary path.
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['ok' => false, 'reason' => 'proof_failed'];
        }

        $validation = payment_proof_validate(
            $tmpName,
            (int) ($file['size'] ?? 0),
            (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)
        );

        if (!$validation['ok']) {
            return ['ok' => false, 'reason' => $validation['reason']];
        }

        $dir = payment_proof_dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'reason' => 'proof_failed'];
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $validation['ext'];
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            return ['ok' => false, 'reason' => 'proof_failed'];
        }

        @chmod($destination, 0640);

        return ['ok' => true, 'path' => 'storage/payment_proofs/' . $filename];
    }
}

if (!function_exists('payment_proof_abspath')) {
    /**
     * Resolve a stored relative proof path to its absolute path, guaranteeing
     * the resolved file is contained within the private proof directory.
     * Returns null for an empty path, traversal attempt, or missing file, so a
     * crafted DB value or request can never read outside the proof store.
     */
    function payment_proof_abspath(?string $relativePath): ?string
    {
        if (!is_string($relativePath) || trim($relativePath) === '') {
            return null;
        }

        $dirReal = realpath(payment_proof_dir());
        if ($dirReal === false) {
            return null;
        }

        $candidate = realpath(__DIR__ . '/../' . ltrim($relativePath, '/'));
        if ($candidate === false) {
            return null;
        }

        $dirReal = rtrim(str_replace('\\', '/', $dirReal), '/') . '/';
        $candidateNorm = str_replace('\\', '/', $candidate);

        if (strncmp($candidateNorm, $dirReal, strlen($dirReal)) !== 0) {
            return null;
        }

        return $candidate;
    }
}

if (!function_exists('payment_proof_delete')) {
    /**
     * Best-effort removal of a stored proof file (used when a resubmission
     * replaces an older screenshot). Containment-checked; never throws.
     */
    function payment_proof_delete(?string $relativePath): void
    {
        $absolute = payment_proof_abspath($relativePath);
        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
        }
    }
}

/* ============================================================
 * Derived GCash payment state (from existing payments columns)
 * ============================================================ */

if (!function_exists('payment_gcash_state')) {
    /**
     * Derive the GCash workflow state from a payments row WITHOUT any extra
     * status column. $payment needs payment_method, payment_status,
     * gcash_reference, and submitted_at. Returns one of:
     *   'NA'            - not a GCash payment
     *   'NOT_SUBMITTED' - GCash, pending, no reference submitted yet
     *   'AWAITING'      - GCash, pending, reference submitted, needs review
     *   'PAID'          - admin-verified paid
     *   'REJECTED'      - admin rejected / correction requested
     */
    function payment_gcash_state(array $payment): string
    {
        if (($payment['payment_method'] ?? '') !== 'GCASH') {
            return 'NA';
        }

        $status = (string) ($payment['payment_status'] ?? 'PENDING');

        if ($status === 'PAID') {
            return 'PAID';
        }
        if ($status === 'FAILED') {
            return 'REJECTED';
        }
        if ($status === 'REFUNDED') {
            // Not produced by this phase, but render honestly if present.
            return 'REJECTED';
        }

        $reference = trim((string) ($payment['gcash_reference'] ?? ''));
        $submitted = $payment['submitted_at'] ?? null;

        if ($reference === '' && ($submitted === null || $submitted === '')) {
            return 'NOT_SUBMITTED';
        }

        return 'AWAITING';
    }
}

if (!function_exists('payment_gcash_state_label')) {
    /** Customer/admin-facing label for a derived GCash state. */
    function payment_gcash_state_label(string $state): string
    {
        switch ($state) {
            case 'NOT_SUBMITTED':
                return 'Awaiting your GCash payment';
            case 'AWAITING':
                return 'Submitted - awaiting verification';
            case 'PAID':
                return 'Payment verified';
            case 'REJECTED':
                return 'Payment rejected - correction needed';
            default:
                return '';
        }
    }
}

if (!function_exists('payment_gcash_state_badge')) {
    /** Existing badge class for a derived GCash state. */
    function payment_gcash_state_badge(string $state): string
    {
        switch ($state) {
            case 'PAID':
                return 'badge-success';
            case 'AWAITING':
                return 'badge-pending';
            case 'REJECTED':
                return 'badge-danger';
            case 'NOT_SUBMITTED':
            default:
                return 'badge-warning';
        }
    }
}

if (!function_exists('payment_load')) {
    /**
     * Load the full payments row for an order (including the Phase 2 columns),
     * or null when there is none. Read-only convenience for endpoints/pages.
     */
    function payment_load(PDO $pdo, int $orderId): ?array
    {
        $stmt = $pdo->prepare(
             'SELECT order_id, payment_method, payment_status, gcash_reference,
                    proof_path, submitted_at, verified_by, verified_at, rejection_reason,
                    rejected_at, revision, NOW() AS checked_at
             FROM payments
             WHERE order_id = :order_id
             LIMIT 1'
        );
        $stmt->execute([':order_id' => $orderId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}

/* ============================================================
 * Transactional write paths
 * ============================================================ */

if (!function_exists('payment_gcash_lock_order')) {
    /**
     * Lock the order row FOR UPDATE. When $customerId is non-null the row must
     * also belong to that customer (ownership gate). Returns the row or false.
     * Internal helper so every write path locks the order row FIRST, then the
     * payment row, giving one consistent lock order and avoiding deadlocks.
     */
    function payment_gcash_lock_order(PDO $pdo, int $orderId, ?int $customerId)
    {
        if ($customerId !== null) {
            $stmt = $pdo->prepare(
                'SELECT o.id, o.status, o.cancellation_status, o.pickup_status,
                        o.received_at, o.picked_up_at
                 FROM orders o
                 WHERE o.id = :order_id AND o.customer_id = :customer_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([':order_id' => $orderId, ':customer_id' => $customerId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT o.id, o.status, o.cancellation_status, o.pickup_status,
                        o.received_at, o.picked_up_at
                 FROM orders o
                 WHERE o.id = :order_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([':order_id' => $orderId]);
        }

        return $stmt->fetch();
    }
}

if (!function_exists('payment_gcash_lock_payment')) {
    /** Lock the payments row for an order FOR UPDATE. Returns the row or false. */
    function payment_gcash_lock_payment(PDO $pdo, int $orderId)
    {
        $stmt = $pdo->prepare(
             'SELECT id, order_id, payment_method, payment_status, gcash_reference,
                    proof_path, submitted_at, verified_by, verified_at, rejection_reason,
                    rejected_at, revision, NOW() AS checked_at
             FROM payments
             WHERE order_id = :order_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':order_id' => $orderId]);

        return $stmt->fetch();
    }
}

/* Phase 6 decisions are shared by the forms and the locked write paths. */
if (!function_exists('payment_gcash_recovery_window')) {
    function payment_gcash_recovery_window(array $payment): array
    {
        $unknown = ['deadline_at' => null, 'remaining_seconds' => null, 'expired' => false];
        $rejectedAt = $payment['rejected_at'] ?? null;
        if (!is_string($rejectedAt) || $rejectedAt === '') {
            return $unknown;
        }

        $zone = new DateTimeZone('Asia/Manila');
        $rejected = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $rejectedAt, $zone);
        if ($rejected === false || $rejected->format('Y-m-d H:i:s') !== $rejectedAt) {
            return $unknown;
        }
        // Loaded rows use the database clock, the same clock that records rejection.
        $now = isset($payment['checked_at'])
            ? new DateTimeImmutable($payment['checked_at'], $zone)
            : new DateTimeImmutable('now', $zone);
        $deadline = $rejected->modify('+24 hours');
        $seconds = $deadline->getTimestamp() - $now->getTimestamp();

        return [
            'deadline_at' => $deadline->format('Y-m-d H:i:s'),
            'remaining_seconds' => max(0, $seconds),
            'expired' => $seconds <= 0,
        ];
    }
}

if (!function_exists('payment_gcash_lock_shipment')) {
    /** Caller holds the order and payment locks, in that order. */
    function payment_gcash_lock_shipment(PDO $pdo, int $orderId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT delivery_method, shipment_status, shipped_at, delivered_at
             FROM shipments WHERE order_id = :order_id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':order_id' => $orderId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        // A current locking read avoids an earlier transaction snapshot hiding
        // a sale that committed while this request waited for the order lock.
        $saleStmt = $pdo->prepare('SELECT id FROM sales_transactions WHERE order_id = :order_id LIMIT 1 FOR UPDATE');
        $saleStmt->execute([':order_id' => $orderId]);
        $row['has_completed_sale'] = $saleStmt->fetchColumn() !== false;
        return $row;
    }
}

if (!function_exists('payment_gcash_order_recovery_reason')) {
    function payment_gcash_order_recovery_reason(array $order, array $payment, ?array $shipment, bool $allowCancellationRequest = false): ?string
    {
        if (($order['status'] ?? '') === 'CANCELLED' || ($order['cancellation_status'] ?? '') === 'APPROVED') {
            return 'order_cancelled';
        }
        if (($payment['payment_method'] ?? '') !== 'GCASH') {
            return 'not_gcash';
        }
        if (($payment['payment_status'] ?? '') === 'PAID'
            || ($payment['verified_at'] ?? null) !== null
            || ($payment['verified_by'] ?? null) !== null) {
            return 'already_paid';
        }
        if (!$allowCancellationRequest && ($order['cancellation_status'] ?? '') === 'REQUESTED') {
            return 'cancellation_pending';
        }
        if (!in_array($order['status'] ?? '', ['PENDING', 'CONFIRMED', 'PACKED'], true)
            || !empty($order['has_completed_sale'])
            || !empty($shipment['has_completed_sale'])
            || !empty($order['received_at']) || !empty($order['picked_up_at'])
            || !in_array($order['pickup_status'] ?? null, [null, 'ORDER_RECEIVED', 'PREPARING', 'READY_FOR_PICKUP'], true)
            || $shipment === null
            || !in_array($shipment['shipment_status'] ?? '', ['NOT_SHIPPED', 'READY_TO_SHIP'], true)
            || !empty($shipment['shipped_at']) || !empty($shipment['delivered_at'])) {
            return 'order_incompatible';
        }
        return null;
    }
}

if (!function_exists('payment_gcash_recovery_reason')) {
    function payment_gcash_recovery_reason(array $order, array $payment, ?array $shipment, bool $allowCancellationRequest = false): ?string
    {
        $reason = payment_gcash_order_recovery_reason($order, $payment, $shipment, $allowCancellationRequest);
        if ($reason !== null) {
            return $reason;
        }
        // REFUNDED is not a rejected submission, even though its display badge is similar.
        return ($payment['payment_status'] ?? '') === 'FAILED' ? null : 'not_rejected';
    }
}

if (!function_exists('payment_gcash_submission_reason')) {
    function payment_gcash_submission_reason(array $order, array $payment, ?array $shipment): ?string
    {
        $reason = payment_gcash_order_recovery_reason($order, $payment, $shipment);
        if ($reason !== null) {
            return $reason;
        }
        if (($payment['payment_status'] ?? '') === 'PENDING') {
            return payment_gcash_state($payment) === 'NOT_SUBMITTED' ? null : 'already_submitted';
        }
        if (($payment['payment_status'] ?? '') !== 'FAILED') {
            return 'not_rejected';
        }
        $window = payment_gcash_recovery_window($payment);
        if ($window['deadline_at'] === null) {
            return 'deadline_unknown';
        }
        return $window['expired'] ? 'deadline_expired' : null;
    }
}

if (!function_exists('payment_gcash_cash_method')) {
    function payment_gcash_cash_method(?array $shipment): ?string
    {
        // Do not use the checkout helper's courier fallback for an unknown method.
        switch ($shipment['delivery_method'] ?? '') {
            case 'LOCAL_COURIER': return 'COD';
            case 'STORE_PICKUP': return 'CASH_ON_PICKUP';
            default: return null;
        }
    }
}

if (!function_exists('payment_gcash_expired_cancel_reason')) {
    function payment_gcash_expired_cancel_reason(array $order, array $payment, ?array $shipment): ?string
    {
        $reason = payment_gcash_recovery_reason($order, $payment, $shipment, true);
        if ($reason !== null) {
            return $reason;
        }
        // Existing cancellation rules only release holds for PENDING orders.
        if (($order['status'] ?? '') !== 'PENDING') {
            return 'order_incompatible';
        }
        $window = payment_gcash_recovery_window($payment);
        if ($window['deadline_at'] === null) {
            return 'deadline_unknown';
        }
        return $window['expired'] ? null : 'deadline_not_expired';
    }
}

if (!function_exists('payment_gcash_fulfillment_reason')) {
    /**
     * The fulfillment gate for a payments row. Returns null when the order may
     * proceed to the next fulfillment step, or a stable reason code when a
     * GCash payment is not cleared:
     *
     *   'gcash_awaiting_verification' - GCash PENDING (reference submitted or
     *                                    not yet submitted). The admin has not
     *                                    confirmed the real transfer.
     *   'gcash_rejected'              - GCash FAILED/REFUNDED. The customer
     *                                    must resubmit a corrected reference
     *                                    (or the order must be cancelled).
     *
     * Non-GCash methods (COD / CASH_ON_PICKUP) and a verified GCash payment
     * return null: their established unpaid-before-collection workflows must
     * never be blocked by the GCash gate.
     *
     * This is a PURE decision from an already-loaded payments row. Callers
     * that mutate state must load the row under the same order-row lock (see
     * payment_gcash_fulfillment_block) so a concurrent verification or
     * rejection cannot change the answer after the check.
     */
    function payment_gcash_fulfillment_reason(array $payment): ?string
    {
        $state = payment_gcash_state($payment);

        if ($state === 'AWAITING' || $state === 'NOT_SUBMITTED') {
            return 'gcash_awaiting_verification';
        }

        if ($state === 'REJECTED') {
            return 'gcash_rejected';
        }

        return null;
    }
}

if (!function_exists('payment_gcash_fulfillment_message')) {
    /** Admin/customer-facing explanation for a fulfillment gate reason. */
    function payment_gcash_fulfillment_message(string $reason): string
    {
        switch ($reason) {
            case 'gcash_awaiting_verification':
                return 'This order cannot be fulfilled until its GCash payment is verified. Review the reference and screenshot in the payment section; if the customer has not submitted them yet, they must do so first.';
            case 'gcash_rejected':
                return 'This order cannot be fulfilled because its GCash payment was rejected. The customer must resubmit a valid reference, or the order must be cancelled.';
            default:
                return 'This order cannot be fulfilled until its payment is verified.';
        }
    }
}

if (!function_exists('payment_gcash_fulfillment_block')) {
    /**
     * Load and lock the order's payments row, then apply the fulfillment gate.
     * Returns the blocking reason or null.
     *
     * The caller MUST already hold the order row lock (SELECT ... FOR UPDATE),
     * matching the order-then-payment lock order used by every payment write
     * path. This makes a concurrent verify/reject and a fulfillment attempt
     * serialize on the same order row, so a payment cannot be rejected after a
     * fulfillment action has already decided it was verified.
     */
    function payment_gcash_fulfillment_block(PDO $pdo, int $orderId): ?string
    {
        $payment = payment_gcash_lock_payment($pdo, $orderId);

        if ($payment === false) {
            return null;
        }

        return payment_gcash_fulfillment_reason($payment);
    }
}

if (!function_exists('payment_gcash_submit')) {
    /**
     * Customer submission of a GCash reference (+ optional screenshot) for an
     * order they own, atomically. The reference is REQUIRED; the proof is
     * optional. Never marks the payment PAID: it keeps PENDING and clears any
     * prior rejection so the admin can review the resubmission.
     *
     * A first submission or a rejected payment within its latest 24-hour
     * window is accepted. Already-awaiting submissions cannot be overwritten.
     * All recovery/finalization checks are repeated under order/payment locks.
     *
     * Returns ['ok'=>true,'state'=>'AWAITING', ...] or
     * ['ok'=>false,'reason'=>'<code>','message'=>'<text>','status'=>int].
     */
    function payment_gcash_submit(PDO $pdo, int $customerId, int $orderId, string $reference, ?array $proofFile = null, ?int $expectedRevision = null): array
    {
        $fail = static function (string $reason, int $status = 200): array {
            return ['ok' => false, 'reason' => $reason, 'message' => payment_message($reason), 'status' => $status];
        };

        $reference = payment_normalize_reference($reference);
        if ($reference === '') {
            return $fail('reference_required', 422);
        }
        if (!payment_is_valid_reference($reference)) {
            return $fail('reference_invalid', 422);
        }

        /* Validate (but do NOT yet move) the optional proof so an invalid file
           is rejected before any transaction and no orphan file is written. */
        $hasProof = $proofFile !== null
            && (int) ($proofFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            && ($proofFile['name'] ?? '') !== '';

        if ($hasProof) {
            $tmp = (string) ($proofFile['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                return $fail('proof_failed', 422);
            }
            $check = payment_proof_validate($tmp, (int) ($proofFile['size'] ?? 0), (int) ($proofFile['error'] ?? UPLOAD_ERR_NO_FILE));
            if (!$check['ok']) {
                return $fail($check['reason'], 422);
            }
        }

        $storedProofPath = null;
        $oldProofToDelete = null;

        try {
            $pdo->beginTransaction();

            $order = payment_gcash_lock_order($pdo, $orderId, $customerId);
            if ($order === false) {
                $pdo->rollBack();
                return $fail('not_found', 404);
            }
            if ((string) $order['status'] === 'CANCELLED') {
                $pdo->rollBack();
                return $fail('order_cancelled', 409);
            }

            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false || (string) $payment['payment_method'] !== 'GCASH') {
                $pdo->rollBack();
                return $fail('not_gcash', 409);
            }
            if ((string) $payment['payment_status'] === 'PAID') {
                $pdo->rollBack();
                return $fail('already_paid', 409);
            }

            $shipment = payment_gcash_lock_shipment($pdo, $orderId);
            $eligibilityReason = payment_gcash_submission_reason($order, $payment, $shipment);
            if ($eligibilityReason !== null) {
                $pdo->rollBack();
                return $fail($eligibilityReason, 409);
            }
            if ($expectedRevision !== null && (int) $payment['revision'] !== $expectedRevision) {
                $pdo->rollBack();
                return $fail('conflict', 409);
            }
            $resubmitted = (string) $payment['payment_status'] === 'FAILED';

            // Store the proof now that the row is confirmed updatable.
            if ($hasProof) {
                $store = payment_proof_store($proofFile);
                if (!$store['ok']) {
                    $pdo->rollBack();
                    return $fail($store['reason'], 422);
                }
                $storedProofPath = $store['path'];
                $oldProofToDelete = ($payment['proof_path'] !== null && trim((string) $payment['proof_path']) !== '')
                    ? (string) $payment['proof_path']
                    : null;
            }

            // When no new proof is uploaded, keep any existing one.
            $proofClause = $hasProof ? 'proof_path = :proof_path,' : '';

            $sql =
                'UPDATE payments
                 SET gcash_reference = :reference,
                     ' . $proofClause . '
                      submitted_at = NOW(),
                      payment_status = \'PENDING\',
                      rejection_reason = NULL,
                      rejected_at = NULL,
                      verified_by = NULL,
                      verified_at = NULL,
                      revision = revision + 1
                 WHERE order_id = :order_id
                   AND payment_method = \'GCASH\'
                   AND revision = :revision
                   AND (payment_status = \'PENDING\'
                        OR (payment_status = \'FAILED\' AND rejected_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)))';

            $update = $pdo->prepare($sql);
            $params = [':reference' => $reference, ':order_id' => $orderId, ':revision' => (int) $payment['revision']];
            if ($hasProof) {
                $params[':proof_path'] = $storedProofPath;
            }
            $update->execute($params);

            if ($update->rowCount() !== 1) {
                // Nothing changed under the lock -> contradictory/duplicate state.
                $pdo->rollBack();
                if ($storedProofPath !== null) {
                    payment_proof_delete($storedProofPath);
                }
                return $fail('conflict', 409);
            }

            $pdo->commit();

            // Replaced proof is removed only after a successful commit.
            if ($oldProofToDelete !== null) {
                payment_proof_delete($oldProofToDelete);
            }

            return [
                'ok' => true,
                'state' => 'AWAITING',
                'reference' => $reference,
                'proof_stored' => $storedProofPath !== null,
                'resubmitted' => $resubmitted,
                'status' => 200,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($storedProofPath !== null) {
                payment_proof_delete($storedProofPath);
            }
            return $fail('error', 500);
        }
    }
}

if (!function_exists('payment_gcash_switch_to_cash')) {
    /** Switch the existing payment only; its GCash-specific evidence is retained. */
    function payment_gcash_switch_to_cash(PDO $pdo, int $customerId, int $orderId, string $method, ?int $expectedRevision = null): array
    {
        $fail = static function (string $reason, int $status = 409) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'reason' => $reason, 'message' => payment_message($reason), 'status' => $status];
        };

        try {
            $pdo->beginTransaction();
            $order = payment_gcash_lock_order($pdo, $orderId, $customerId);
            if ($order === false) {
                return $fail('not_found', 404);
            }
            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false) {
                return $fail('not_gcash');
            }
            $shipment = payment_gcash_lock_shipment($pdo, $orderId);
            $reason = payment_gcash_recovery_reason($order, $payment, $shipment);
            if ($reason !== null) {
                return $fail($reason);
            }
            if ($expectedRevision !== null && (int) $payment['revision'] !== $expectedRevision) {
                return $fail('conflict');
            }
            $eligibleMethod = payment_gcash_cash_method($shipment);
            if ($eligibleMethod === null || $method !== $eligibleMethod) {
                return $fail('cash_method_invalid', 422);
            }

            $update = $pdo->prepare(
                'UPDATE payments
                 SET payment_method = :method, payment_status = \'PENDING\', revision = revision + 1
                 WHERE order_id = :order_id AND payment_method = \'GCASH\'
                   AND payment_status = \'FAILED\' AND verified_at IS NULL AND verified_by IS NULL
                   AND revision = :revision'
            );
            $update->execute([':method' => $method, ':order_id' => $orderId, ':revision' => (int) $payment['revision']]);
            if ($update->rowCount() !== 1) {
                return $fail('conflict');
            }
            $pdo->commit();
            return ['ok' => true, 'payment_method' => $method, 'status' => 200];
        } catch (Throwable $e) {
            return $fail('error', 500);
        }
    }
}

if (!function_exists('payment_gcash_verify')) {
    /**
     * Admin confirmation that a submitted GCash payment is genuinely received,
     * atomically. Sets payment_status = 'PAID' with verifier attribution. It
     * requires a submitted reference and a PENDING status, so it can never run
     * on a not-yet-submitted, cancelled, already-paid, or rejected payment.
     *
     * It changes ONLY the payments row: orders.status, shipments,
     * products.status, and sales_transactions are untouched, so confirming a
     * payment never fabricates a completed sale or collected revenue.
     *
     * The conditional UPDATE (payment_status = 'PENDING' in the WHERE) makes a
     * duplicate/racing confirmation a no-op reported as 'conflict'.
     */
    function payment_gcash_verify(PDO $pdo, int $adminId, int $orderId, ?int $expectedRevision = null): array
    {
        $fail = static function (string $reason, int $status = 200): array {
            return ['ok' => false, 'reason' => $reason, 'message' => payment_message($reason), 'status' => $status];
        };

        try {
            $pdo->beginTransaction();

            $order = payment_gcash_lock_order($pdo, $orderId, null);
            if ($order === false) {
                $pdo->rollBack();
                return $fail('not_found', 404);
            }
            if ((string) $order['status'] === 'CANCELLED') {
                $pdo->rollBack();
                return $fail('order_cancelled', 409);
            }

            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false || (string) $payment['payment_method'] !== 'GCASH') {
                $pdo->rollBack();
                return $fail('not_gcash', 409);
            }
            if ((string) $payment['payment_status'] === 'PAID') {
                $pdo->rollBack();
                return $fail('already_paid', 409);
            }
            if ((string) $payment['payment_status'] !== 'PENDING') {
                $pdo->rollBack();
                return $fail('not_pending', 409);
            }
            if ($expectedRevision !== null && (int) $payment['revision'] !== $expectedRevision) {
                $pdo->rollBack();
                return $fail('conflict', 409);
            }

            $referenceSubmitted = trim((string) ($payment['gcash_reference'] ?? '')) !== ''
                && $payment['submitted_at'] !== null;
            if (!$referenceSubmitted) {
                $pdo->rollBack();
                return $fail('not_submitted', 409);
            }

            $update = $pdo->prepare(
                'UPDATE payments
                 SET payment_status = \'PAID\',
                      verified_by = :admin_id,
                      verified_at = NOW(),
                      rejection_reason = NULL,
                      rejected_at = NULL,
                      revision = revision + 1
                 WHERE order_id = :order_id
                   AND payment_method = \'GCASH\'
                   AND payment_status = \'PENDING\''
            );
            $update->execute([':admin_id' => $adminId, ':order_id' => $orderId]);

            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return $fail('conflict', 409);
            }

            $pdo->commit();

            return ['ok' => true, 'state' => 'PAID', 'status' => 200];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $fail('error', 500);
        }
    }
}

if (!function_exists('payment_gcash_reject')) {
    /**
     * Admin rejection / correction request for a submitted GCash payment,
     * atomically. Sets payment_status = 'FAILED' with a required reason, so
     * the customer sees why and can resubmit (which returns it to PENDING via
     * payment_gcash_submit). Requires a submitted, PENDING payment on a
     * non-cancelled order; never acts on an already-paid payment.
     */
    function payment_gcash_reject(PDO $pdo, int $adminId, int $orderId, string $reason, ?int $expectedRevision = null): array
    {
        $fail = static function (string $reason, int $status = 200): array {
            return ['ok' => false, 'reason' => $reason, 'message' => payment_message($reason), 'status' => $status];
        };

        $reason = trim($reason);
        if ($reason === '') {
            return $fail('reason_required', 422);
        }
        if (strlen($reason) > 255) {
            return $fail('reason_too_long', 422);
        }

        try {
            $pdo->beginTransaction();

            $order = payment_gcash_lock_order($pdo, $orderId, null);
            if ($order === false) {
                $pdo->rollBack();
                return $fail('not_found', 404);
            }
            if ((string) $order['status'] === 'CANCELLED') {
                $pdo->rollBack();
                return $fail('order_cancelled', 409);
            }

            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false || (string) $payment['payment_method'] !== 'GCASH') {
                $pdo->rollBack();
                return $fail('not_gcash', 409);
            }
            if ((string) $payment['payment_status'] === 'PAID') {
                $pdo->rollBack();
                return $fail('already_paid', 409);
            }
            if ((string) $payment['payment_status'] !== 'PENDING') {
                $pdo->rollBack();
                return $fail('not_pending', 409);
            }
            if ($expectedRevision !== null && (int) $payment['revision'] !== $expectedRevision) {
                $pdo->rollBack();
                return $fail('conflict', 409);
            }

            $referenceSubmitted = trim((string) ($payment['gcash_reference'] ?? '')) !== ''
                && $payment['submitted_at'] !== null;
            if (!$referenceSubmitted) {
                $pdo->rollBack();
                return $fail('not_submitted', 409);
            }

            $update = $pdo->prepare(
                'UPDATE payments
                  SET payment_status = \'FAILED\',
                      rejection_reason = :reason,
                      rejected_at = NOW(),
                      verified_by = NULL,
                      verified_at = NULL,
                      revision = revision + 1
                  WHERE order_id = :order_id
                    AND payment_method = \'GCASH\'
                    AND payment_status = \'PENDING\''
            );
            $update->execute([':reason' => $reason, ':order_id' => $orderId]);

            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return $fail('conflict', 409);
            }

            $pdo->commit();

            return ['ok' => true, 'state' => 'REJECTED', 'reason' => $reason, 'status' => 200];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $fail('error', 500);
        }
    }
}

if (!function_exists('payment_collect_cash_on_pickup')) {
    /**
     * Admin recording that the ACTUAL CASH for a Cash on Pickup order was
     * received at the store, atomically. This is the ONE explicit collection
     * action for CASH_ON_PICKUP payments; nothing else ever sets a Cash on
     * Pickup payment to PAID (a pickup advance never touches payment_status,
     * so Ready for Pickup or the handover itself never imply payment).
     *
     * Guards (revalidated under row locks, in the order-then-payment lock
     * order shared with the GCash paths):
     *   - the order must exist and not be CANCELLED;
     *   - the order must be fulfilled by STORE_PICKUP (courier COD is a
     *     different payment method and stays on its own workflow);
     *   - the payments row must be CASH_ON_PICKUP;
     *   - the pickup lifecycle must already be terminal (PICKED_UP), so
     *     cash is recorded only after the handover actually happened;
     *   - the payment must still be PENDING (a duplicate submission is a
     *     conflict, never a second collection; a PAID/FAILED/REFUNDED
     *     payment is rejected).
     *
     * It changes ONLY the payments row (payment_status, verified_by,
     * verified_at): orders, shipments, products, and sales_transactions are
     * untouched, so collecting cash never fabricates or completes a sale.
     * Attribution uses verified_by/verified_at, the same existing columns
     * the GCash verification path uses - no new payment ledger or status
     * field is introduced.
     *
     * Returns ['ok'=>true,'state'=>'PAID','status'=>200] or
     * ['ok'=>false,'reason','message','status'].
     */
    function payment_collect_cash_on_pickup(PDO $pdo, int $adminId, int $orderId): array
    {
        $fail = static function (string $reason, int $status = 200): array {
            return ['ok' => false, 'reason' => $reason, 'message' => payment_message($reason), 'status' => $status];
        };

        try {
            $pdo->beginTransaction();

            $order = payment_gcash_lock_order($pdo, $orderId, null);
            if ($order === false) {
                $pdo->rollBack();
                return $fail('not_found', 404);
            }
            if ((string) $order['status'] === 'CANCELLED') {
                $pdo->rollBack();
                return $fail('order_cancelled', 409);
            }

            /* Store Pickup fulfillment check (shipments.delivery_method)
               under the order lock. */
            $shipmentStmt = $pdo->prepare(
                'SELECT delivery_method FROM shipments WHERE order_id = :order_id LIMIT 1'
            );
            $shipmentStmt->execute([':order_id' => $orderId]);
            if ((string) $shipmentStmt->fetchColumn() !== 'STORE_PICKUP') {
                $pdo->rollBack();
                return $fail('not_pickup_order', 409);
            }

            /* The pickup lifecycle must be terminal before cash can be
               recorded as collected. Re-read under the order lock. */
            $pickupStmt = $pdo->prepare(
                'SELECT pickup_status FROM orders WHERE id = :order_id LIMIT 1'
            );
            $pickupStmt->execute([':order_id' => $orderId]);
            if ((string) $pickupStmt->fetchColumn() !== 'PICKED_UP') {
                $pdo->rollBack();
                return $fail('not_picked_up', 409);
            }

            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false || (string) $payment['payment_method'] !== 'CASH_ON_PICKUP') {
                $pdo->rollBack();
                return $fail('not_cash_on_pickup', 409);
            }
            if ((string) $payment['payment_status'] === 'PAID') {
                $pdo->rollBack();
                return $fail('already_paid', 409);
            }
            if ((string) $payment['payment_status'] !== 'PENDING') {
                $pdo->rollBack();
                return $fail('not_pending', 409);
            }

            /* Conditional UPDATE: only a PENDING Cash on Pickup row can
               become PAID, so a duplicate or racing submission can never
               record a second collection. Retain any historical GCash fields
               from a payment-method switch; they are not cash payment state. */
            $update = $pdo->prepare(
                'UPDATE payments
                  SET payment_status = \'PAID\',
                      verified_by = :admin_id,
                      verified_at = NOW(),
                      revision = revision + 1
                 WHERE order_id = :order_id
                   AND payment_method = \'CASH_ON_PICKUP\'
                   AND payment_status = \'PENDING\''
            );
            $update->execute([':admin_id' => $adminId, ':order_id' => $orderId]);

            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return $fail('conflict', 409);
            }

            $pdo->commit();

            return ['ok' => true, 'state' => 'PAID', 'status' => 200];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $fail('error', 500);
        }
    }
}

if (!function_exists('payment_cod_sale_linkage_error')) {
    /**
     * Admin-only diagnostics: either unique item key can block a new sale.
     * The locked call requires the caller's order/payment transaction; the
     * read-only call is advisory for the admin page. Never repair sale history.
     */
    function payment_cod_sale_linkage_error(PDO $pdo, int $orderId, bool $forUpdate = false): ?array
    {
        if ($forUpdate && !$pdo->inTransaction()) {
            throw new LogicException('COD linkage validation requires an active transaction and order lock.');
        }

        // Separate unique-key lookups keep locks local; prefer an order-item
        // conflict when both keys point to existing sale details.
        $stmt = $pdo->prepare(
            'SELECT oi.id AS order_item_id, oi.product_id,
                    COALESCE(item_link.id, product_link.id) AS sale_item_id,
                    COALESCE(item_link.order_item_id, product_link.order_item_id) AS linked_order_item_id,
                    COALESCE(item_link.sale_id, product_link.sale_id) AS sale_id,
                    st.sale_channel, st.order_id AS sale_order_id
             FROM order_items oi
             LEFT JOIN sales_transaction_items item_link ON item_link.order_item_id = oi.id
             LEFT JOIN sales_transaction_items product_link ON product_link.product_id = oi.product_id
             LEFT JOIN sales_transactions st ON st.id = COALESCE(item_link.sale_id, product_link.sale_id)
             WHERE oi.order_id = :order_id
               AND (item_link.id IS NOT NULL OR product_link.id IS NOT NULL)
             ORDER BY oi.id
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':order_id' => $orderId]);
        $conflict = $stmt->fetch();
        if ($conflict === false) {
            return null;
        }

        $subject = (int) $conflict['linked_order_item_id'] === (int) $conflict['order_item_id']
            ? 'Order item #' . (int) $conflict['order_item_id']
            : 'Product #' . (int) $conflict['product_id'] . ' on order item #' . (int) $conflict['order_item_id'];
        $source = ($conflict['sale_channel'] === 'WALKIN' ? 'walk-in sale #' : 'sale #') . (int) $conflict['sale_id'];
        if ($conflict['sale_order_id'] !== null) {
            $source .= ' for order #' . (int) $conflict['sale_order_id'];
        }
        $link = 'sale item #' . (int) $conflict['sale_item_id'];
        if ($conflict['linked_order_item_id'] !== null) {
            $link .= ', order item #' . (int) $conflict['linked_order_item_id'];
        }

        return [
            'ok' => false,
            'reason' => 'cod_sale_linkage_conflict',
            'message' => $subject . ' is already recorded in ' . $source . ' (' . $link . '). '
                . 'Sale finalization is blocked. Manual review is required; no records were changed.',
            'status' => 409,
        ];
    }
}

if (!function_exists('payment_cop_pickup_finalization_reason')) {
    /**
     * Shared by the admin controls and the locked Store Pickup COP
     * finalization path. PURE decision from already-loaded order, payment,
     * and shipment rows; the locked caller re-reads them under row locks.
     *
     * Returns null only when ALL of the following hold:
     *   - fulfillment is STORE_PICKUP;
     *   - the payment method is CASH_ON_PICKUP;
     *   - no canonical completed sale exists yet;
     *   - no cash-collection attribution has been recorded;
     *   - the order is not cancelled and has no pending/approved cancellation;
     *   - the payment is still PENDING;
     *   - the pickup lifecycle is exactly READY_FOR_PICKUP;
     *   - the order has not already been handed over/finalized.
     *
     * Barcode/preparation completion and the canonical sale writer's item
     * requirements are revalidated by the locked write path.
     */
    function payment_cop_pickup_finalization_reason(array $order, array $payment, ?array $shipment): ?string
    {
        if ($shipment === null || ($shipment['delivery_method'] ?? '') !== 'STORE_PICKUP') {
            return 'cop_not_pickup';
        }
        if (($payment['payment_method'] ?? '') !== 'CASH_ON_PICKUP') {
            return 'cop_not_cash_on_pickup';
        }
        if (!empty($order['has_completed_sale'])) {
            return ($payment['payment_status'] ?? '') === 'PAID'
                ? 'cop_already_completed'
                : 'cop_sale_without_collection';
        }
        if (($payment['payment_status'] ?? '') === 'PAID'
            || ($payment['verified_at'] ?? null) !== null
            || ($payment['verified_by'] ?? null) !== null) {
            return 'cop_collection_conflict';
        }
        if (($order['status'] ?? '') === 'CANCELLED'
            || in_array($order['cancellation_status'] ?? '', ['REQUESTED', 'APPROVED'], true)) {
            return 'cop_cancellation_locked';
        }
        if (($payment['payment_status'] ?? '') !== 'PENDING') {
            return 'cop_not_pending';
        }
        if (($order['received_at'] ?? null) !== null || ($order['picked_up_at'] ?? null) !== null) {
            return 'cop_already_completed';
        }
        if (($order['pickup_status'] ?? null) !== 'READY_FOR_PICKUP') {
            return 'cop_not_ready';
        }

        return null;
    }
}

if (!function_exists('payment_confirm_pickup_and_collect_cash')) {
    /**
     * Phase 9: the ONE atomic admin action for a Store Pickup Cash on Pickup
     * order at READY_FOR_PICKUP. Inside a single caller-owned transaction it:
     *   1. locks the order, payments, shipment, and sale header rows in the
     *      established order-then-payment lock order;
     *   2. revalidates fulfillment, payment method/state, pickup stage,
     *      cancellation, completed-sale state, collection attribution,
     *      barcode-verification completion, and sale-item linkage;
     *   3. records the cash collection on the existing payments row
     *      (payment_status, verified_by, verified_at, revision);
     *   4. moves the pickup lifecycle to PICKED_UP and sets picked_up_at;
     *   5. creates the canonical ONLINE completed sale through
     *      sales_complete_pickup_online_locked(), which also persists
     *      orders.received_at.
     *
     * Any failure rolls back every mutation, so a handover can never commit
     * without its cash record and canonical sale (or vice versa). A duplicate
     * or racing submission is rejected by the locked state checks, the
     * conditional UPDATEs, and the canonical sale's UNIQUE(order_id) key.
     */
    function payment_confirm_pickup_and_collect_cash(PDO $pdo, int $adminId, int $orderId, int $expectedRevision): array
    {
        if ($pdo->inTransaction()) {
            throw new LogicException('Store Pickup COP finalization must own its transaction.');
        }

        $fail = static function (string $reason, int $status = 409) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'reason' => $reason, 'message' => payment_message($reason), 'status' => $status];
        };

        if ($adminId < 1) {
            return $fail('cop_admin_required', 403);
        }
        if ($orderId < 1 || $expectedRevision < 0) {
            return $fail('bad_request', 422);
        }

        try {
            require_once __DIR__ . '/sales.php';
            if (!function_exists('order_verify_progress')) {
                require_once __DIR__ . '/order_verify.php';
            }

            $pdo->beginTransaction();

            $order = payment_gcash_lock_order($pdo, $orderId, null);
            if ($order === false) {
                return $fail('not_found', 404);
            }

            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false) {
                return $fail('cop_not_cash_on_pickup');
            }

            /* Locks the shipment row and the canonical sale header (if any). */
            $shipment = payment_gcash_lock_shipment($pdo, $orderId);
            $order['has_completed_sale'] = !empty($shipment['has_completed_sale']);

            $reason = payment_cop_pickup_finalization_reason($order, $payment, $shipment);
            if ($reason !== null) {
                return $fail($reason);
            }

            if ((int) $payment['revision'] !== $expectedRevision) {
                return $fail('cop_conflict');
            }

            /* Never repair or delete a conflicting sale history. */
            $linkageError = payment_cod_sale_linkage_error($pdo, $orderId, true);
            if ($linkageError !== null) {
                $pdo->rollBack();
                return $linkageError;
            }

            /* Defense in depth: preparation already required this gate. */
            $verification = order_verify_progress($pdo, $orderId);
            if (empty($verification['complete'])) {
                return $fail('cop_verification_incomplete');
            }

            /* Use the database clock from the locked payment read so the
               collection, handover, and sale share one timestamp. */
            $collectedAt = (string) $payment['checked_at'];

            $update = $pdo->prepare(
                'UPDATE payments
                 SET payment_status = \'PAID\', verified_by = :admin_id,
                     verified_at = :collected_at, revision = revision + 1
                 WHERE order_id = :order_id AND payment_method = \'CASH_ON_PICKUP\'
                   AND payment_status = \'PENDING\'
                   AND verified_at IS NULL AND verified_by IS NULL
                   AND revision = :revision'
            );
            $update->execute([
                ':admin_id' => $adminId,
                ':collected_at' => $collectedAt,
                ':order_id' => $orderId,
                ':revision' => $expectedRevision,
            ]);
            if ($update->rowCount() !== 1) {
                return $fail('cop_conflict');
            }

            $handover = $pdo->prepare(
                'UPDATE orders
                 SET pickup_status = \'PICKED_UP\',
                     picked_up_at = COALESCE(picked_up_at, :picked_up_at)
                 WHERE id = :order_id
                   AND pickup_status = \'READY_FOR_PICKUP\'
                   AND status <> \'CANCELLED\'
                   AND cancellation_status NOT IN (\'REQUESTED\', \'APPROVED\')'
            );
            $handover->execute([
                ':picked_up_at' => $collectedAt,
                ':order_id' => $orderId,
            ]);
            if ($handover->rowCount() !== 1) {
                return $fail('cop_conflict');
            }

            /* Canonical ONLINE sale + item snapshots + received_at. This
               helper deliberately does not own a transaction. */
            $saleResult = sales_complete_pickup_online_locked($pdo, $orderId, $collectedAt);
            if (empty($saleResult['ok'])) {
                $pdo->rollBack();
                return $saleResult;
            }

            /*
             * Phase 14: record the customer's Handed Over notification in the
             * SAME transaction that committed the handover, so the handover
             * (and its cash record + canonical sale) and the notification
             * commit or roll back together. It is informational only; it does
             * not confirm receipt, collect payment, or finalize a sale beyond
             * the existing action's effects.
             */
            if (!function_exists('customer_notifications_create')) {
                require_once __DIR__ . '/customer_notifications.php';
            }
            customer_notifications_create($pdo, $orderId, 'PICKUP_HANDED_OVER');

            $pdo->commit();

            return [
                'ok' => true,
                'state' => 'PAID',
                'sale_id' => (int) $saleResult['sale_id'],
                'status' => 200,
            ];
        } catch (Throwable $e) {
            return $fail('cop_error', 500);
        }
    }
}

if (!function_exists('payment_pickup_gcash_finalization_reason')) {
    /**
     * Phase 10: shared by the admin controls and the locked Store Pickup GCash
     * finalization path. PURE decision from already-loaded order, payment, and
     * shipment rows; the locked caller re-reads them under row locks.
     *
     * Returns null only when ALL of the following hold:
     *   - fulfillment is STORE_PICKUP;
     *   - the payment method is GCASH;
     *   - the GCash payment is already verified/paid (payment_status = 'PAID'
     *     with verified_by / verified_at set) - this action confirms the
     *     handover and completes the sale; it NEVER re-verifies or recollects;
     *   - the pickup lifecycle is exactly READY_FOR_PICKUP;
     *   - the order is not cancelled and has no pending/approved cancellation;
     *   - no canonical completed sale exists yet;
     *   - the order has not already been handed over/finalized
     *     (received_at / picked_up_at are null).
     *
     * Barcode/preparation completion and the canonical sale writer's item
     * requirements are revalidated by the locked write path.
     */
    function payment_pickup_gcash_finalization_reason(array $order, array $payment, ?array $shipment): ?string
    {
        if ($shipment === null || ($shipment['delivery_method'] ?? '') !== 'STORE_PICKUP') {
            return 'pg_not_pickup';
        }
        if (($payment['payment_method'] ?? '') !== 'GCASH') {
            return 'pg_not_gcash';
        }
        if (!empty($order['has_completed_sale'])) {
            return 'pg_already_completed';
        }
        if (($order['status'] ?? '') === 'CANCELLED'
            || in_array($order['cancellation_status'] ?? '', ['REQUESTED', 'APPROVED'], true)) {
            return 'pg_cancellation_locked';
        }
        // GCash must already be verified/paid by an admin.
        if (($payment['payment_status'] ?? '') !== 'PAID'
            || ($payment['verified_at'] ?? null) === null
            || ($payment['verified_by'] ?? null) === null) {
            return 'pg_not_verified';
        }
        if (($order['received_at'] ?? null) !== null || ($order['picked_up_at'] ?? null) !== null) {
            return 'pg_already_completed';
        }
        if (($order['pickup_status'] ?? null) !== 'READY_FOR_PICKUP') {
            return 'pg_not_ready';
        }

        return null;
    }
}

if (!function_exists('payment_confirm_pickup_gcash_and_complete_sale')) {
    /**
     * Phase 10: the ONE atomic admin action for a Store Pickup GCash order at
     * READY_FOR_PICKUP whose GCash payment is already verified/paid. Inside a
     * single caller-owned transaction it:
     *   1. locks the order, payments, shipment, and sale header rows in the
     *      established order-then-payment lock order;
     *   2. revalidates fulfillment, GCash method, verified payment state,
     *      pickup stage (READY_FOR_PICKUP), cancellation, completed-sale
     *      state, barcode-verification completion, and sale-item linkage;
     *   3. moves the pickup lifecycle to PICKED_UP and sets picked_up_at;
     *   4. creates the canonical ONLINE completed sale (which also persists
     *      orders.received_at) through sales_complete_pickup_online_locked().
     *
     * Unlike Phase 9 COP, this action does NOT record cash collection, does
     * NOT re-verify or recollect the GCash payment, and does NOT change the
     * verified GCash payment row. The already-verified GCash payment record
     * and its verification attribution (verified_by / verified_at) are
     * preserved untouched.
     *
     * Any failure rolls back every mutation, so a handover can never commit
     * without its canonical sale (or vice versa). A duplicate or racing
     * submission is rejected by the locked state checks, the conditional
     * UPDATEs, and the canonical sale's UNIQUE(order_id) key.
     */
    function payment_confirm_pickup_gcash_and_complete_sale(PDO $pdo, int $adminId, int $orderId, int $expectedRevision): array
    {
        if ($pdo->inTransaction()) {
            throw new LogicException('Store Pickup GCash finalization must own its transaction.');
        }

        $fail = static function (string $reason, int $status = 409) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'reason' => $reason, 'message' => payment_message($reason), 'status' => $status];
        };

        if ($adminId < 1) {
            return $fail('pg_admin_required', 403);
        }
        if ($orderId < 1 || $expectedRevision < 0) {
            return $fail('bad_request', 422);
        }

        try {
            require_once __DIR__ . '/sales.php';
            if (!function_exists('order_verify_progress')) {
                require_once __DIR__ . '/order_verify.php';
            }

            $pdo->beginTransaction();

            $order = payment_gcash_lock_order($pdo, $orderId, null);
            if ($order === false) {
                return $fail('not_found', 404);
            }

            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false) {
                return $fail('pg_not_gcash');
            }

            /* Locks the shipment row and the canonical sale header (if any). */
            $shipment = payment_gcash_lock_shipment($pdo, $orderId);
            $order['has_completed_sale'] = !empty($shipment['has_completed_sale']);

            $reason = payment_pickup_gcash_finalization_reason($order, $payment, $shipment);
            if ($reason !== null) {
                return $fail($reason);
            }

            /* The verified GCash payment row must not have been mutated by a
               concurrent verification/recovery; its revision is the guard. */
            if ((int) $payment['revision'] !== $expectedRevision) {
                return $fail('pg_conflict');
            }

            /* Never repair or delete a conflicting sale history. */
            $linkageError = payment_cod_sale_linkage_error($pdo, $orderId, true);
            if ($linkageError !== null) {
                $pdo->rollBack();
                return $linkageError;
            }

            /* Defense in depth: preparation already required this gate. */
            $verification = order_verify_progress($pdo, $orderId);
            if (empty($verification['complete'])) {
                return $fail('pg_verification_incomplete');
            }

            /* Use the database clock from the locked payment read so the
               handover and sale share one timestamp. */
            $handoverAt = (string) $payment['checked_at'];

            /* Confirm the handover: move READY_FOR_PICKUP -> PICKED_UP and
               stamp picked_up_at exactly once. The GCash payment row is NOT
               touched, so its verified state and attribution are preserved. */
            $handover = $pdo->prepare(
                'UPDATE orders
                 SET pickup_status = \'PICKED_UP\',
                     picked_up_at = COALESCE(picked_up_at, :picked_up_at)
                 WHERE id = :order_id
                   AND pickup_status = \'READY_FOR_PICKUP\'
                   AND status <> \'CANCELLED\'
                   AND cancellation_status NOT IN (\'REQUESTED\', \'APPROVED\')'
            );
            $handover->execute([
                ':picked_up_at' => $handoverAt,
                ':order_id' => $orderId,
            ]);
            if ($handover->rowCount() !== 1) {
                return $fail('pg_conflict');
            }

            /* Canonical ONLINE sale + item snapshots + received_at. This
               helper deliberately does not own a transaction. A failed sale
               aborts the whole handover (the UPDATE above rolls back too). */
            $saleResult = sales_complete_pickup_online_locked($pdo, $orderId, $handoverAt);
            if (empty($saleResult['ok'])) {
                $pdo->rollBack();
                return $saleResult;
            }

            /*
             * Phase 14: record the customer's Handed Over notification in the
             * SAME transaction that committed the handover. It is
             * informational only; it does not confirm receipt, collect
             * payment, or finalize a sale beyond the existing action's
             * effects. A failure aborts the whole handover.
             */
            if (!function_exists('customer_notifications_create')) {
                require_once __DIR__ . '/customer_notifications.php';
            }
            customer_notifications_create($pdo, $orderId, 'PICKUP_HANDED_OVER');

            $pdo->commit();

            return [
                'ok' => true,
                'state' => 'PAID',
                'sale_id' => (int) $saleResult['sale_id'],
                'status' => 200,
            ];
        } catch (Throwable $e) {
            return $fail('pg_error', 500);
        }
    }
}

/** Caller holds the courier order/payment/shipment locks in READ COMMITTED. */
function payment_courier_sale_inventory_error(PDO $pdo, int $orderId): ?array
{
    if (!$pdo->inTransaction()) throw new LogicException('Sale inventory checks require the order transaction.');
    require_once __DIR__ . '/inventory_guard.php';
    require_once __DIR__ . '/sales.php';
    $blocked = static fn (string $message): array => ['ok' => false, 'reason' => 'sale_inventory_blocked',
        'message' => $message . ' Sale finalization is blocked; no records were changed.', 'status' => 409];
    $stmt = $pdo->prepare('SELECT id, product_id, quantity, unit_price FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE');
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    if ($items === []) return $blocked('This order has no item identities.');
    $productIds = [];
    foreach ($items as $item) {
        if ((int) $item['quantity'] !== 1 || (int) $item['product_id'] < 1
            || in_array((int) $item['product_id'], $productIds, true)) {
            return $blocked('Each garment requires a unique, existing product identity and quantity one.');
        }
        $productIds[] = (int) $item['product_id'];
    }
    sort($productIds);
    $stmt = $pdo->prepare('SELECT id, status, retired_at FROM products WHERE id IN ('
        . implode(',', array_fill(0, count($productIds), '?')) . ') ORDER BY id FOR UPDATE');
    $stmt->execute($productIds);
    $products = $stmt->fetchAll();
    if (count($products) !== count($productIds)) return $blocked('A current garment record is missing.');
    foreach ($products as $product) {
        if ($product['status'] !== 'SOLD') return $blocked('Product #' . (int) $product['id'] . ' is not in this order\'s unavailable inventory state.');
        if ($product['retired_at'] !== null) return $blocked('Product #' . (int) $product['id'] . ' is retired or disposed of.');
        if (inventory_has_other_hold_locked($pdo, (int) $product['id'], $orderId)) {
            return $blocked('Another unresolved order holds product #' . (int) $product['id'] . '.');
        }
    }
    // Independent key lookups preserve existing committed-sale protections
    // without reopening an already finalized garment or taking inverse locks.
    foreach ($items as $item) {
        foreach (['product_id' => $item['product_id'], 'order_item_id' => $item['id']] as $key => $value) {
            $stmt = $pdo->prepare('SELECT id FROM sales_transaction_items WHERE ' . $key . ' = ? FOR UPDATE');
            $stmt->execute([$value]);
            if ($stmt->fetchColumn() !== false) return $blocked('An item or product already belongs to a committed sale.');
        }
    }
    $itemTotal = sales_sum_price_cents(array_column($items, 'unit_price'));
    $stmt = $pdo->prepare('SELECT subtotal FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $subtotal = sales_money_to_cents($stmt->fetchColumn());
    if ($itemTotal === null || $subtotal === null || $itemTotal !== $subtotal) {
        return $blocked('Merchandise item snapshots do not match the order subtotal. Shipping cannot be included in the sale.');
    }
    return null;
}

if (!function_exists('payment_cod_collection_reason')) {
    /** Shared by the admin control and the locked COD finalization path. */
    function payment_cod_collection_reason(array $order, array $payment, ?array $shipment): ?string
    {
        // A legacy NULL method on an existing shipment means Local Courier.
        if ($shipment === null || !in_array($shipment['delivery_method'] ?? null, [null, 'LOCAL_COURIER'], true)
            || ($order['pickup_status'] ?? null) !== null || ($order['picked_up_at'] ?? null) !== null) {
            return 'cod_not_courier';
        }
        if (($payment['payment_method'] ?? '') !== 'COD') {
            return 'not_cod';
        }
        if (!empty($order['has_completed_sale'])) {
            return ($payment['payment_status'] ?? '') === 'PAID'
                ? 'cod_already_completed'
                : 'cod_sale_without_collection';
        }
        if (($payment['payment_status'] ?? '') === 'PAID'
            || ($payment['verified_at'] ?? null) !== null
            || ($payment['verified_by'] ?? null) !== null) {
            return 'cod_collection_conflict';
        }
        if (($order['status'] ?? '') === 'CANCELLED'
            || !in_array($order['cancellation_status'] ?? '', ['NONE', 'REJECTED'], true)) {
            return 'cod_cancellation_locked';
        }
        if (($payment['payment_status'] ?? '') !== 'PENDING') {
            return 'cod_not_pending';
        }
        if (($order['status'] ?? '') !== 'DELIVERED' || ($shipment['shipment_status'] ?? '') !== 'DELIVERED') {
            return 'cod_not_delivered';
        }
        if (($order['received_at'] ?? null) === null) {
            return 'cod_receipt_pending';
        }

        return null;
    }
}

if (!function_exists('payment_collect_cod_and_complete_sale')) {
    /**
     * Owns ONE transaction for COD collection and the canonical ONLINE sale.
     * Receipt, fulfillment and inventory are not changed. Existing sales or
     * collection discrepancies are rejected, never repaired automatically.
     * validateOnly is internal UI advice: the same locked eligibility checks
     * roll back before collection or sale writes. HTTP never forwards this flag.
     */
    function payment_collect_cod_and_complete_sale(PDO $pdo, int $adminId, int $orderId, int $expectedRevision, bool $validateOnly = false): array
    {
        if ($pdo->inTransaction()) {
            throw new LogicException('COD finalization must own its transaction.');
        }

        $fail = static function (string $reason, int $status = 409) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'reason' => $reason, 'message' => payment_message($reason), 'status' => $status];
        };
        if ($adminId < 1) {
            return $fail('cod_admin_required', 403);
        }
        if ($orderId < 1 || $expectedRevision < 0) {
            return $fail('bad_request', 422);
        }

        try {
            require_once __DIR__ . '/sales.php';
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            $admin = $pdo->prepare('SELECT id FROM admins WHERE id = ? LOCK IN SHARE MODE');
            $admin->execute([$adminId]);
            if ($admin->fetchColumn() === false) return $fail('cod_admin_required', 403);
            $order = payment_gcash_lock_order($pdo, $orderId, null);
            if ($order === false) {
                return $fail('not_found', 404);
            }
            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false) {
                return $fail('not_cod');
            }
            $shipment = payment_gcash_lock_shipment($pdo, $orderId);
            $order['has_completed_sale'] = !empty($shipment['has_completed_sale']);
            $reason = payment_cod_collection_reason($order, $payment, $shipment);
            if ($reason !== null) {
                return $fail($reason);
            }
            if ((int) $payment['revision'] !== $expectedRevision) {
                return $fail('conflict');
            }

            $linkageError = payment_courier_sale_inventory_error($pdo, $orderId);
            if ($linkageError !== null) {
                $pdo->rollBack();
                return $linkageError;
            }

            if ($validateOnly) {
                $pdo->rollBack();
                return ['ok' => true, 'status' => 200];
            }

            // Both records use the database clock from the locked payment read.
            $collectedAt = (string) $payment['checked_at'];
            $update = $pdo->prepare(
                'UPDATE payments
                 SET payment_status = \'PAID\', verified_by = :admin_id,
                     verified_at = :collected_at, revision = revision + 1
                 WHERE order_id = :order_id AND payment_method = \'COD\'
                   AND payment_status = \'PENDING\'
                   AND verified_at IS NULL AND verified_by IS NULL
                   AND revision = :revision'
            );
            $update->execute([
                ':admin_id' => $adminId,
                ':collected_at' => $collectedAt,
                ':order_id' => $orderId,
                ':revision' => $expectedRevision,
            ]);
            if ($update->rowCount() !== 1) {
                return $fail('conflict');
            }

            $saleResult = sales_create_online_locked($pdo, $orderId, $collectedAt, $adminId);
            if (!$saleResult['ok']) {
                $pdo->rollBack();
                return $saleResult;
            }

            $pdo->commit();
            return ['ok' => true, 'state' => 'PAID', 'sale_id' => (int) $saleResult['sale_id'], 'status' => 200];
        } catch (Throwable $e) {
            return $fail('cod_error', 500);
        }
    }
}

if (!function_exists('payment_gcash_completion_reason')) {
    /** Shared by the admin control and the locked GCash finalization path. */
    function payment_gcash_completion_reason(array $order, array $payment, ?array $shipment): ?string
    {
        // A legacy NULL method on an existing shipment means Local Courier.
        if ($shipment === null || !in_array($shipment['delivery_method'] ?? null, [null, 'LOCAL_COURIER'], true)
            || ($order['pickup_status'] ?? null) !== null || ($order['picked_up_at'] ?? null) !== null) {
            return 'gcash_not_courier';
        }
        if (($payment['payment_method'] ?? '') !== 'GCASH') {
            return 'not_gcash';
        }
        if (!empty($order['has_completed_sale'])) {
            return 'gcash_already_completed';
        }
        if (($order['status'] ?? '') === 'CANCELLED'
            || !in_array($order['cancellation_status'] ?? '', ['NONE', 'REJECTED'], true)) {
            return 'gcash_cancellation_locked';
        }
        // GCash must already be admin-verified/paid before sale finalization.
        if (($payment['payment_status'] ?? '') !== 'PAID'
            || ($payment['verified_at'] ?? null) === null
            || ($payment['verified_by'] ?? null) === null) {
            return 'gcash_not_verified';
        }
        if (($order['status'] ?? '') !== 'DELIVERED' || ($shipment['shipment_status'] ?? '') !== 'DELIVERED') {
            return 'gcash_not_delivered';
        }
        if (($order['received_at'] ?? null) === null) {
            return 'gcash_receipt_pending';
        }

        return null;
    }
}

if (!function_exists('payment_finalize_gcash_sale')) {
    /**
     * Owns ONE transaction for finalizing a verified Local Courier GCash sale.
     *
     * The customer has already paid and the admin has already verified the
     * payment (payments.payment_status = 'PAID' with verified_by / verified_at
     * set), so there is NO physical cash collection or rider remittance. This
     * action records the completed transaction only: it re-checks every
     * eligibility condition under lock, confirms no cancellation conflict and
     * no existing completed sale / item linkage, and writes the canonical
     * ONLINE completed sale via sales_create_online_locked (which snapshots
     * order-item prices, excludes shipping, and uses the deterministic
     * request key). The sale header carries admin_id so the finalizing admin
     * is attributed. Receipt, fulfillment, inventory, and the already-PAID
     * payment row are not changed.
     *
     * Idempotency / duplicate prevention: UNIQUE(order_id) on
     * sales_transactions, the item product/order_item unique keys, and the
     * linkage guard prevent a second sale; an already-completed order returns
     * 'gcash_already_completed' without touching any records.
     * validateOnly is internal UI advice and rolls back before every sale write;
     * HTTP never forwards it. The committed writer remains the sole sale path.
     */
    function payment_finalize_gcash_sale(PDO $pdo, int $adminId, int $orderId, int $expectedRevision, bool $validateOnly = false): array
    {
        if ($pdo->inTransaction()) {
            throw new LogicException('GCash sale finalization must own its transaction.');
        }

        $fail = static function (string $reason, int $status = 409) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'reason' => $reason, 'message' => payment_message($reason), 'status' => $status];
        };
        if ($adminId < 1) {
            return $fail('gcash_admin_required', 403);
        }
        if ($orderId < 1 || $expectedRevision < 0) {
            return $fail('bad_request', 422);
        }

        try {
            require_once __DIR__ . '/sales.php';
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            $admin = $pdo->prepare('SELECT id FROM admins WHERE id = ? LOCK IN SHARE MODE');
            $admin->execute([$adminId]);
            if ($admin->fetchColumn() === false) return $fail('gcash_admin_required', 403);
            $order = payment_gcash_lock_order($pdo, $orderId, null);
            if ($order === false) {
                return $fail('not_found', 404);
            }
            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false) {
                return $fail('not_gcash');
            }
            $shipment = payment_gcash_lock_shipment($pdo, $orderId);
            $order['has_completed_sale'] = !empty($shipment['has_completed_sale']);
            $reason = payment_gcash_completion_reason($order, $payment, $shipment);
            if ($reason !== null) {
                return $fail($reason);
            }
            if ((int) $payment['revision'] !== $expectedRevision) {
                return $fail('conflict');
            }

            $linkageError = payment_courier_sale_inventory_error($pdo, $orderId);
            if ($linkageError !== null) {
                $pdo->rollBack();
                return $linkageError;
            }

            if ($validateOnly) {
                $pdo->rollBack();
                return ['ok' => true, 'status' => 200];
            }

            // The customer already paid and the admin already verified; use the
            // verification timestamp so the completed transaction is recorded
            // once, at the actual payment reconciliation time.
            $completedAt = (string) $payment['verified_at'];
            $saleResult = sales_create_online_locked($pdo, $orderId, $completedAt, $adminId);
            if (!$saleResult['ok']) {
                $pdo->rollBack();
                return $saleResult;
            }

            $pdo->commit();
            return ['ok' => true, 'sale_id' => (int) $saleResult['sale_id'], 'status' => 200];
        } catch (Throwable $e) {
            return $fail('gcash_error', 500);
        }
    }
}
