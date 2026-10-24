<?php

/*
 * Hopia Fits - Phase B online-order verification shared helpers.
 *
 * Include-only: this file declares functions and performs no output and no
 * side effects, matching the convention of includes/sales.php and
 * includes/ui.php. It is the SINGLE SOURCE OF TRUTH for:
 *   - order verification eligibility (status + cancellation),
 *   - the expected-item checklist and progress counts,
 *   - barcode -> expected order-item resolution, and
 *   - the two transactional write paths (SCANNED and MANUAL).
 *
 * It is required by admin/order-verify.php, admin/api/order-verify-scan.php,
 * admin/api/order-verify-manual.php, and the PACKED -> SHIPPED shipment gate in
 * admin/order-view.php, so eligibility and progress rules can never drift
 * between the page, the APIs, and the single shipment gate.
 *
 * CRITICAL difference from Phase A (walk-in sales):
 *   Phase A rejects products whose status is not AVAILABLE because it SELLS
 *   walk-in garments. Phase B VERIFIES already-ordered online garments, which
 *   online checkout intentionally sets to SOLD as an availability hold. Phase B
 *   therefore MUST NOT reuse Phase A's AVAILABLE eligibility predicate
 *   (sales_product_eligibility_error) and MUST NOT change products.status.
 *   The security boundary for a scan is "this barcode resolves to a product
 *   that belongs to an order_items row IN THE SELECTED ORDER", never the
 *   product's sale status.
 *
 * Verification never creates a sale (sales_transactions /
 * sales_transaction_items) and never mutates product status, payment, shipment,
 * or order lifecycle fields. It only inserts rows into order_item_verifications.
 */

require_once __DIR__ . '/sales.php'; // reuse sales_normalize_barcode / sales_is_plausible_barcode

/*
 * The ONLY order statuses from which an item may be verified. Kept as a single
 * constant so the PHP predicate, the eligible-order listing, and the shipment
 * gate all agree.
 */
if (!defined('ORDER_VERIFY_ELIGIBLE_STATUSES')) {
    define('ORDER_VERIFY_ELIGIBLE_STATUSES', ['CONFIRMED', 'PACKED']);
}

/*
 * Cancellation states that BLOCK verification (pending or approved). NONE and
 * REJECTED are allowed to proceed.
 */
if (!defined('ORDER_VERIFY_BLOCKING_CANCELLATIONS')) {
    define('ORDER_VERIFY_BLOCKING_CANCELLATIONS', ['REQUESTED', 'APPROVED']);
}

if (!function_exists('order_is_verifiable')) {
    /**
     * Pure eligibility predicate. True only when the order status is CONFIRMED
     * or PACKED AND the order is not in a pending/approved cancellation state.
     * Server code calls this against the LOCKED order row for every scan,
     * manual verify, and the shipment gate; it never trusts the page state.
     */
    function order_is_verifiable($status, $cancellationStatus): bool
    {
        $status = is_string($status) ? $status : '';
        $cancellationStatus = is_string($cancellationStatus) ? $cancellationStatus : 'NONE';

        if (!in_array($status, ORDER_VERIFY_ELIGIBLE_STATUSES, true)) {
            return false;
        }

        if (in_array($cancellationStatus, ORDER_VERIFY_BLOCKING_CANCELLATIONS, true)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('order_verify_order_error')) {
    /**
     * Classify why an order is not verifiable, as a stable machine-readable
     * code, or null when it IS verifiable. $order is the fetched order row
     * (needs status + cancellation_status) or false/null when not found.
     *
     *   'order_not_found'      - no such order.
     *   'cancellation_locked'  - a pending/approved cancellation blocks it.
     *   'order_not_verifiable' - status is not CONFIRMED/PACKED.
     */
    function order_verify_order_error($order): ?string
    {
        if (!is_array($order) || !isset($order['status'])) {
            return 'order_not_found';
        }

        $cancellation = (string) ($order['cancellation_status'] ?? 'NONE');
        if (in_array($cancellation, ORDER_VERIFY_BLOCKING_CANCELLATIONS, true)) {
            return 'cancellation_locked';
        }

        if (!in_array((string) $order['status'], ORDER_VERIFY_ELIGIBLE_STATUSES, true)) {
            return 'order_not_verifiable';
        }

        return null;
    }
}

if (!function_exists('order_verify_message')) {
    /**
     * Map a machine-readable reason code to a short, admin-facing message.
     * Centralized so every surface (page, scan API, manual API, shipment gate)
     * phrases a rejection identically and never leaks a raw SQL error.
     */
    function order_verify_message(string $reason): string
    {
        switch ($reason) {
            case 'order_not_found':
                return 'That order could not be found.';
            case 'order_not_verifiable':
                return 'This order cannot be verified. Only confirmed or packed orders can be verified.';
            case 'cancellation_locked':
                return 'This order has a pending or approved cancellation and cannot be verified.';
            case 'not_found':
                return 'No product matches that barcode.';
            case 'unexpected_item':
                return 'That item does not belong to this order.';
            case 'item_not_found':
                return 'That order item could not be found for this order.';
            case 'already_verified':
                return 'That item has already been verified.';
            case 'reason_required':
                return 'Enter a reason to manually verify this item.';
            case 'reason_too_long':
                return 'The reason must be 255 characters or fewer.';
            case 'scannable_requires_scan':
                return 'This item has a valid barcode and must be verified by scanning, not manually.';
            case 'not_manual_item':
                return 'This item cannot be verified manually.';
            case 'bad_request':
                return 'The verification request was invalid. Please reload the page and try again.';
            case 'error':
                return 'The verification could not be completed. Please try again.';
            default:
                return 'That item could not be verified.';
        }
    }
}

if (!function_exists('order_verify_item_is_scannable')) {
    /**
     * Pure classifier: is this order item scannable? True only when it links to
     * a real product (product_id present) that carries a valid issued barcode.
     * Everything else (NULL product_id, a product with no/blank/invalid
     * barcode, or an unresolvable product) is UNSCANNABLE and requires an
     * explicit Manual Verify. $itemRow needs 'product_id' and 'product_barcode'
     * (the CURRENT products.barcode_value, which is NULL when the product row
     * does not exist because of the LEFT JOIN).
     */
    function order_verify_item_is_scannable(array $itemRow): bool
    {
        $productId = $itemRow['product_id'] ?? null;
        if ($productId === null || $productId === '' || (int) $productId <= 0) {
            return false;
        }

        $barcode = $itemRow['product_barcode'] ?? null;
        if (!is_string($barcode)) {
            return false;
        }

        return sales_is_plausible_barcode(sales_normalize_barcode($barcode));
    }
}

if (!function_exists('order_verify_progress')) {
    /**
     * Authoritative progress for one order, computed purely from the database.
     *
     *   expected  - COUNT of ALL order_items rows for the order. No item is
     *               ever excluded for a NULL product_id, a missing product, a
     *               missing/blank/invalid barcode, or being a legacy row.
     *               (Each order_items row is exactly one physical garment: the
     *               checkout flow always inserts quantity = 1 per unique
     *               garment - see customer/checkout.php.)
     *   verified  - COUNT of verification rows whose order_item actually
     *               belongs to this same order (proved by the join through
     *               order_items, not merely by the stored order_id).
     *   remaining - expected - verified, floored at 0.
     *   complete  - TRUE only when expected > 0 AND verified >= expected, i.e.
     *               every expected item has a committed verification. Zero
     *               expected items is NOT complete.
     *
     * Call this while the order row is locked (shipment gate / write paths) so
     * the counts cannot change between read and decision.
     */
    function order_verify_progress(PDO $pdo, int $orderId): array
    {
        $expectedStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM order_items WHERE order_id = :order_id'
        );
        $expectedStmt->execute([':order_id' => $orderId]);
        $expected = (int) $expectedStmt->fetchColumn();

        $verifiedStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM order_item_verifications v
             INNER JOIN order_items oi ON oi.id = v.order_item_id
             WHERE oi.order_id = :order_id'
        );
        $verifiedStmt->execute([':order_id' => $orderId]);
        $verified = (int) $verifiedStmt->fetchColumn();

        return [
            'expected' => $expected,
            'verified' => $verified,
            'remaining' => max(0, $expected - $verified),
            'complete' => ($expected > 0 && $verified >= $expected),
        ];
    }
}

if (!function_exists('order_verify_item_rows')) {
    /**
     * The full expected-item checklist for one order: EVERY order_items row,
     * including NULL-product and otherwise unscannable/legacy rows, each with
     * its snapshot, current product barcode, scannability, and verification
     * state (method, time, admin, reason) when present. Used by the page to
     * render the checklist and by the test harness.
     */
    function order_verify_item_rows(PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare(
            'SELECT oi.id AS order_item_id,
                    oi.product_id,
                    oi.product_name,
                    oi.size,
                    oi.color,
                    oi.unit_price,
                    oi.quantity,
                    p.barcode_value AS product_barcode,
                    v.id AS verification_id,
                    v.method,
                    v.barcode_value AS verified_barcode,
                    v.verified_at,
                    v.reason,
                    a.name AS verified_by_name
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             LEFT JOIN order_item_verifications v ON v.order_item_id = oi.id
             LEFT JOIN admins a ON a.id = v.verified_by
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC'
        );
        $stmt->execute([':order_id' => $orderId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['scannable'] = order_verify_item_is_scannable($row);
            $row['verified'] = $row['verification_id'] !== null;
            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('order_verify_lookup')) {
    /**
     * Read-only resolve of a scanned/typed barcode against ONE selected order.
     * Returns the matched expected order item, or a rejection reason. It never
     * writes and never consults products.status.
     *
     *   ['ok'=>true, 'order_item_id'=>int, 'product_id'=>int, 'barcode'=>string,
     *    'product_name'=>string, 'already_verified'=>bool]
     *   ['ok'=>false, 'reason'=>'<code>', 'message'=>'<text>']
     *
     * Rejections:
     *   'not_found'       - empty/implausible barcode, or no product carries it.
     *   'unexpected_item' - the product exists but no order_items row in THIS
     *                       order points to it (another order, or not ordered).
     */
    function order_verify_lookup(PDO $pdo, int $orderId, string $barcode): array
    {
        $barcode = sales_normalize_barcode($barcode);

        if (!sales_is_plausible_barcode($barcode)) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => order_verify_message('not_found')];
        }

        $productStmt = $pdo->prepare(
            'SELECT id FROM products WHERE barcode_value = :barcode LIMIT 1'
        );
        $productStmt->execute([':barcode' => $barcode]);
        $productId = $productStmt->fetchColumn();

        if ($productId === false) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => order_verify_message('not_found')];
        }
        $productId = (int) $productId;

        $itemStmt = $pdo->prepare(
            'SELECT oi.id AS order_item_id, oi.product_name,
                    v.id AS verification_id
             FROM order_items oi
             LEFT JOIN order_item_verifications v ON v.order_item_id = oi.id
             WHERE oi.order_id = :order_id
               AND oi.product_id = :product_id
             ORDER BY (v.id IS NOT NULL), oi.id ASC
             LIMIT 1'
        );
        $itemStmt->execute([':order_id' => $orderId, ':product_id' => $productId]);
        $item = $itemStmt->fetch();

        if ($item === false) {
            return ['ok' => false, 'reason' => 'unexpected_item', 'message' => order_verify_message('unexpected_item')];
        }

        return [
            'ok' => true,
            'order_item_id' => (int) $item['order_item_id'],
            'product_id' => $productId,
            'barcode' => $barcode,
            'product_name' => (string) $item['product_name'],
            'already_verified' => $item['verification_id'] !== null,
        ];
    }
}

if (!function_exists('order_verify_load_order_locked')) {
    /**
     * Lock the order row (SELECT ... FOR UPDATE) and return id/status/
     * cancellation_status, or false. Internal helper so both write paths and
     * the shipment gate lock the SAME single row first (consistent lock
     * ordering avoids deadlocks).
     */
    function order_verify_load_order_locked(PDO $pdo, int $orderId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, status, cancellation_status
             FROM orders
             WHERE id = :order_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':order_id' => $orderId]);

        return $stmt->fetch();
    }
}

if (!function_exists('order_verify_record')) {
    /**
     * Record ONE SCANNED verification, atomically. Shared by
     * admin/api/order-verify-scan.php and the Phase B tests.
     *
     * Transaction sequence:
     *   1. begin;
     *   2. lock the order row FOR UPDATE;
     *   3. revalidate status + cancellation against the locked row;
     *   4. resolve the barcode to a product and lock that product row;
     *   5. find & lock an expected order_items row in THIS order whose
     *      product_id is that product (the selected-order security boundary);
     *   6. choose the first not-yet-verified matching item; reject as
     *      already_verified if every match is verified;
     *   7. insert one row (method='SCANNED', barcode snapshot, product_id,
     *      verified_by, server verified_at, reason NULL);
     *   8. recompute progress from the database;
     *   9. commit.
     * Any rule violation or error rolls everything back; a unique-key race on
     * order_item_id is mapped to 'already_verified', never a raw SQL error.
     * products.status, payments, shipments, sales and order fields are never
     * touched.
     */
    function order_verify_record(PDO $pdo, int $adminId, int $orderId, string $barcode): array
    {
        $abort = static function (array $payload) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!isset($payload['status'])) {
                $payload['status'] = 200;
            }
            return $payload;
        };

        $barcode = sales_normalize_barcode($barcode);
        if (!sales_is_plausible_barcode($barcode)) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => order_verify_message('not_found'), 'status' => 200];
        }

        try {
            $pdo->beginTransaction();

            /* STEP 2 - lock the order row. */
            $order = order_verify_load_order_locked($pdo, $orderId);
            if ($order === false) {
                return $abort(['ok' => false, 'reason' => 'order_not_found', 'message' => order_verify_message('order_not_found'), 'status' => 404]);
            }

            /* STEP 3 - revalidate eligibility against the locked row. */
            $orderError = order_verify_order_error($order);
            if ($orderError !== null) {
                return $abort(['ok' => false, 'reason' => $orderError, 'message' => order_verify_message($orderError), 'status' => 409]);
            }

            /* STEP 4 - resolve + lock the product carrying this barcode. */
            $productStmt = $pdo->prepare(
                'SELECT id, barcode_value FROM products WHERE barcode_value = :barcode LIMIT 1 FOR UPDATE'
            );
            $productStmt->execute([':barcode' => $barcode]);
            $product = $productStmt->fetch();
            if ($product === false) {
                return $abort(['ok' => false, 'reason' => 'not_found', 'message' => order_verify_message('not_found'), 'status' => 200]);
            }
            $productId = (int) $product['id'];

            /* STEP 5 - find & lock the expected order_items row(s) in THIS order. */
            $itemStmt = $pdo->prepare(
                'SELECT id FROM order_items
                 WHERE order_id = :order_id AND product_id = :product_id
                 ORDER BY id ASC
                 FOR UPDATE'
            );
            $itemStmt->execute([':order_id' => $orderId, ':product_id' => $productId]);
            $candidateIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN));

            if (count($candidateIds) === 0) {
                /* The barcode belongs to a product that was not ordered here. */
                return $abort(['ok' => false, 'reason' => 'unexpected_item', 'message' => order_verify_message('unexpected_item'), 'status' => 200]);
            }

            /* STEP 6 - pick the first candidate that is not already verified. */
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
                return $abort(['ok' => false, 'reason' => 'already_verified', 'message' => order_verify_message('already_verified'), 'status' => 200]);
            }

            /* STEP 7 - insert exactly one SCANNED verification row. */
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
                if ($e->getCode() === '23000') {
                    /* Racing duplicate on UNIQUE(order_item_id) -> already verified. */
                    return $abort(['ok' => false, 'reason' => 'already_verified', 'message' => order_verify_message('already_verified'), 'status' => 200]);
                }
                throw $e;
            }

            $verificationId = (int) $pdo->lastInsertId();
            $display = order_verify_fetch_display($pdo, $verificationId);

            /* STEP 8 - recompute progress from the committed-to-be state. */
            $progress = order_verify_progress($pdo, $orderId);

            /* STEP 9 - commit. */
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
            ];
        } catch (Throwable $e) {
            return $abort(['ok' => false, 'reason' => 'error', 'message' => order_verify_message('error'), 'status' => 500]);
        }
    }
}

if (!function_exists('order_verify_record_manual')) {
    /**
     * Record ONE MANUAL verification, atomically, for an UNSCANNABLE expected
     * item. Shared by admin/api/order-verify-manual.php and the Phase B tests.
     *
     * A blank/whitespace-only reason is rejected BEFORE any transaction. Inside
     * the transaction it:
     *   1. locks the order row and revalidates eligibility;
     *   2. locks the specific order_items row and proves it belongs to THIS
     *      order (item_not_found otherwise);
     *   3. refuses an item that is actually scannable - a valid barcode must
     *      use the SCANNED path, so manual can never be a bypass;
     *   4. refuses a duplicate (already_verified);
     *   5. inserts one row (method='MANUAL', reason, verified_by, server
     *      verified_at; product_id from the order item which may be NULL;
     *      barcode_value NULL).
     * Nothing but the verification row is written; status/payment/shipment/sale
     * fields are untouched. Client-supplied product names, ids, status, method,
     * verification state, and admin ids are never trusted.
     */
    function order_verify_record_manual(PDO $pdo, int $adminId, int $orderId, int $orderItemId, string $reason): array
    {
        $abort = static function (array $payload) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!isset($payload['status'])) {
                $payload['status'] = 200;
            }
            return $payload;
        };

        /* Reason is required, trimmed, non-empty, and not merely whitespace. */
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'reason' => 'reason_required', 'message' => order_verify_message('reason_required'), 'status' => 400];
        }
        if (strlen($reason) > 255) {
            return ['ok' => false, 'reason' => 'reason_too_long', 'message' => order_verify_message('reason_too_long'), 'status' => 400];
        }

        try {
            $pdo->beginTransaction();

            /* STEP 1 - lock the order row and revalidate eligibility. */
            $order = order_verify_load_order_locked($pdo, $orderId);
            if ($order === false) {
                return $abort(['ok' => false, 'reason' => 'order_not_found', 'message' => order_verify_message('order_not_found'), 'status' => 404]);
            }
            $orderError = order_verify_order_error($order);
            if ($orderError !== null) {
                return $abort(['ok' => false, 'reason' => $orderError, 'message' => order_verify_message($orderError), 'status' => 409]);
            }

            /* STEP 2 - lock the order item and prove it belongs to THIS order. */
            $itemStmt = $pdo->prepare(
                'SELECT oi.id, oi.product_id, p.barcode_value AS product_barcode
                 FROM order_items oi
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE oi.id = :order_item_id AND oi.order_id = :order_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $itemStmt->execute([':order_item_id' => $orderItemId, ':order_id' => $orderId]);
            $item = $itemStmt->fetch();
            if ($item === false) {
                return $abort(['ok' => false, 'reason' => 'item_not_found', 'message' => order_verify_message('item_not_found'), 'status' => 404]);
            }

            /* STEP 3 - a scannable item must use the SCANNED path, not manual. */
            if (order_verify_item_is_scannable($item)) {
                return $abort(['ok' => false, 'reason' => 'scannable_requires_scan', 'message' => order_verify_message('scannable_requires_scan'), 'status' => 409]);
            }

            /* STEP 4 - reject a duplicate verification. */
            $existsStmt = $pdo->prepare('SELECT 1 FROM order_item_verifications WHERE order_item_id = :oiid LIMIT 1');
            $existsStmt->execute([':oiid' => $orderItemId]);
            if ($existsStmt->fetchColumn() !== false) {
                return $abort(['ok' => false, 'reason' => 'already_verified', 'message' => order_verify_message('already_verified'), 'status' => 200]);
            }

            /* STEP 5 - insert one MANUAL verification row. product_id is the
               order item's own linkage (may be NULL); barcode is NULL. */
            $productIdParam = ($item['product_id'] !== null && (int) $item['product_id'] > 0)
                ? (int) $item['product_id']
                : null;

            try {
                $insert = $pdo->prepare(
                    'INSERT INTO order_item_verifications
                        (order_id, order_item_id, product_id, barcode_value, verified_by, method, reason)
                     VALUES
                        (:order_id, :order_item_id, :product_id, NULL, :verified_by, \'MANUAL\', :reason)'
                );
                $insert->bindValue(':order_id', $orderId, PDO::PARAM_INT);
                $insert->bindValue(':order_item_id', $orderItemId, PDO::PARAM_INT);
                if ($productIdParam === null) {
                    $insert->bindValue(':product_id', null, PDO::PARAM_NULL);
                } else {
                    $insert->bindValue(':product_id', $productIdParam, PDO::PARAM_INT);
                }
                $insert->bindValue(':verified_by', $adminId, PDO::PARAM_INT);
                $insert->bindValue(':reason', $reason, PDO::PARAM_STR);
                $insert->execute();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    return $abort(['ok' => false, 'reason' => 'already_verified', 'message' => order_verify_message('already_verified'), 'status' => 200]);
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
                'method' => 'MANUAL',
                'order_item_id' => $orderItemId,
                'reason' => $reason,
                'verification' => $display,
                'progress' => $progress,
            ];
        } catch (Throwable $e) {
            return $abort(['ok' => false, 'reason' => 'error', 'message' => order_verify_message('error'), 'status' => 500]);
        }
    }
}

if (!function_exists('order_verify_fetch_display')) {
    /**
     * Load the display fields of a single verification row (method, server
     * verified_at, reason, and the admin name) so an API response can show the
     * committed row without a page reload. Returns a plain array.
     */
    function order_verify_fetch_display(PDO $pdo, int $verificationId): array
    {
        $stmt = $pdo->prepare(
            'SELECT v.id, v.order_item_id, v.method, v.barcode_value, v.verified_at, v.reason,
                    a.name AS verified_by_name
             FROM order_item_verifications v
             LEFT JOIN admins a ON a.id = v.verified_by
             WHERE v.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $verificationId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return [];
        }

        return [
            'order_item_id' => (int) $row['order_item_id'],
            'method' => (string) $row['method'],
            'barcode' => $row['barcode_value'] !== null ? (string) $row['barcode_value'] : '',
            'verified_at' => (string) $row['verified_at'],
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : '',
            'verified_by_name' => $row['verified_by_name'] !== null ? (string) $row['verified_by_name'] : '',
        ];
    }
}

if (!function_exists('order_verify_eligible_orders')) {
    /**
     * List orders that are currently eligible for verification (CONFIRMED or
     * PACKED, not in a pending/approved cancellation), each with its customer
     * name and verification progress. The SQL predicate below is the direct
     * counterpart of order_is_verifiable(); the per-order server re-check under
     * lock still uses order_is_verifiable() before any write.
     */
    function order_verify_eligible_orders(PDO $pdo): array
    {
        $statusPlaceholders = implode(',', array_fill(0, count(ORDER_VERIFY_ELIGIBLE_STATUSES), '?'));
        $cancelPlaceholders = implode(',', array_fill(0, count(ORDER_VERIFY_BLOCKING_CANCELLATIONS), '?'));

        $sql =
            'SELECT o.id,
                    o.status,
                    o.cancellation_status,
                    o.total_amount,
                    o.created_at,
                    CONCAT(c.first_name, \' \', c.last_name) AS customer_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS expected,
                    (SELECT COUNT(*)
                       FROM order_item_verifications v
                       INNER JOIN order_items oiv ON oiv.id = v.order_item_id
                      WHERE oiv.order_id = o.id) AS verified
             FROM orders o
             INNER JOIN customers c ON c.id = o.customer_id
             WHERE o.status IN (' . $statusPlaceholders . ')
               AND o.cancellation_status NOT IN (' . $cancelPlaceholders . ')
             ORDER BY o.created_at DESC, o.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge(
            array_values(ORDER_VERIFY_ELIGIBLE_STATUSES),
            array_values(ORDER_VERIFY_BLOCKING_CANCELLATIONS)
        ));

        return $stmt->fetchAll();
    }
}
