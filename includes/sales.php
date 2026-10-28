<?php

/*
 * Hopia Fits - Phase A walk-in sales shared helpers.
 *
 * Include-only: this file declares functions and performs no output and no
 * side effects, matching the convention of includes/ui.php. It is required by
 * admin/api/barcode-lookup.php, admin/api/walkin-sale.php, admin/sales.php,
 * admin/labels.php, and the Phase A test scripts, so the sale-eligibility
 * rules, the money math, and the bundle-lock predicate have exactly one
 * source of truth and cannot drift between preview and confirmation.
 *
 * NOTE on money: all authoritative totals are computed in integer centavos
 * using round(value * 100), the same approach customer/checkout.php uses, so
 * floating-point drift can never change a stored total.
 */

if (!function_exists('sales_money_to_cents')) {
    /**
     * Convert a money value (a numeric string such as "250.00", or an int /
     * float) into an integer number of centavos. Returns null for anything
     * that is not a usable, finite, non-negative number so callers can treat
     * a null as "reject", never as zero.
     */
    function sales_money_to_cents($value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '' || !is_numeric($value)) {
                return null;
            }

            $value = (float) $value;
        } elseif (is_int($value) || is_float($value)) {
            $value = (float) $value;
        } else {
            return null;
        }

        if (!is_finite($value) || $value < 0.0) {
            return null;
        }

        return (int) round($value * 100);
    }
}

if (!function_exists('sales_cents_to_string')) {
    /**
     * Format an integer number of centavos as a canonical DECIMAL(10,2)
     * string such as "800.00" for storage and exact comparison.
     */
    function sales_cents_to_string(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

if (!function_exists('sales_sum_price_cents')) {
    /**
     * Sum a list of money values into total centavos. Returns null if any
     * single value is unusable, so a bad element rejects the whole total
     * rather than silently contributing zero.
     */
    function sales_sum_price_cents(array $values): ?int
    {
        $total = 0;

        foreach ($values as $value) {
            $cents = sales_money_to_cents($value);

            if ($cents === null) {
                return null;
            }

            $total += $cents;
        }

        return $total;
    }
}

if (!function_exists('sales_normalize_barcode')) {
    /**
     * Normalize a scanned or typed barcode. A keyboard-wedge (USB/Bluetooth)
     * scanner typically appends a carriage return / newline; manual typing may
     * add stray spaces. Trimming here means camera scans, manual entry, and a
     * future wedge scanner all resolve to the same canonical value.
     */
    function sales_normalize_barcode($code): string
    {
        if (!is_string($code)) {
            return '';
        }

        return trim($code);
    }
}

if (!function_exists('sales_is_plausible_barcode')) {
    /**
     * Cheap structural sanity check for a barcode BEFORE it touches the
     * database. This is NOT an eligibility check (that is always done against
     * the database): it only rejects empty, over-long (the barcode_value
     * column is VARCHAR(64)), or control-character-bearing input so a lookup
     * is never attempted with obviously malformed data.
     */
    function sales_is_plausible_barcode($code): bool
    {
        if (!is_string($code)) {
            return false;
        }

        $code = trim($code);

        if ($code === '' || strlen($code) > 64) {
            return false;
        }

        // Reject ASCII control characters (0x00-0x1F and 0x7F).
        if (preg_match('/[\x00-\x1F\x7F]/', $code) === 1) {
            return false;
        }

        return true;
    }
}

if (!function_exists('sales_is_valid_request_key')) {
    /**
     * Validate the idempotency / request key supplied by the confirm request.
     * Accepts the characters produced by crypto.randomUUID() and by hex
     * tokens, 16 to 64 characters. Any other shape is rejected before it is
     * used, so a malformed key never reaches the UNIQUE column.
     */
    function sales_is_valid_request_key($key): bool
    {
        if (!is_string($key)) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._-]{16,64}$/', $key) === 1;
    }
}

if (!function_exists('sales_product_eligibility_error')) {
    /**
     * Pure, DB-free structural eligibility check for one product row. Returns
     * null when the row is structurally sellable through the walk-in flow, or
     * a stable machine-readable reason code otherwise:
     *
     *   'not_available' - products.status is not exactly 'AVAILABLE'
     *                     (this includes the online SOLD hold).
     *   'retired'       - products.retired_at is set.
     *   'unassigned'    - the product has no bundle link or no issued barcode,
     *                     so it is a legacy/invalid row that must never be
     *                     grouped or sold with a fabricated barcode.
     *
     * The "already in a completed sale" rule is intentionally NOT here because
     * it requires a database read; callers add it with sales_product_id_sold()
     * (or the equivalent locked query) during confirmation.
     *
     * The row must provide: status, retired_at, bundle_id, barcode_value.
     */
    function sales_product_eligibility_error(array $product): ?string
    {
        $status = $product['status'] ?? null;
        $retiredAt = $product['retired_at'] ?? null;
        $bundleId = $product['bundle_id'] ?? null;
        $barcode = $product['barcode_value'] ?? null;

        if ($retiredAt !== null && $retiredAt !== '') {
            return 'retired';
        }

        if ($status !== 'AVAILABLE') {
            return 'not_available';
        }

        $hasBundle = $bundleId !== null && $bundleId !== '' && (int) $bundleId > 0;
        $hasBarcode = is_string($barcode) && trim($barcode) !== '';

        if (!$hasBundle || !$hasBarcode) {
            return 'unassigned';
        }

        return null;
    }
}

if (!function_exists('sales_eligibility_message')) {
    /**
     * Map an eligibility reason code (from sales_product_eligibility_error, or
     * 'already_sold' / 'not_found' / 'duplicate') to a short staff-facing
     * message. Centralized so every surface phrases a rejection identically.
     */
    function sales_eligibility_message(string $reason): string
    {
        switch ($reason) {
            case 'not_found':
                return 'No product matches that barcode.';
            case 'not_available':
                return 'That item is not available for sale.';
            case 'retired':
                return 'That item has been retired and cannot be sold.';
            case 'unassigned':
                return 'That product has no valid bundle barcode and cannot be sold.';
            case 'already_sold':
                return 'That item has already been sold.';
            case 'duplicate':
                return 'That item is already in the current sale.';
            default:
                return 'That item cannot be added to the sale.';
        }
    }
}

if (!function_exists('sales_online_request_key')) {
    /**
     * Deterministic, server-controlled idempotency key for an ONLINE
     * completed-sale header. The order ID is a permanent surrogate key,
     * so the same order always produces the same key. No client input
     * ever reaches this function.
     *
     * Format: 'online-order-NNNNNN' (19 chars, passes
     * sales_is_valid_request_key's /^[A-Za-z0-9._-]{16,64}$/).
     */
    function sales_online_request_key(int $orderId): string
    {
        return 'online-order-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('sales_create_online_locked')) {
    /**
     * Write the canonical ONLINE header and item snapshots. The caller owns
     * the transaction and order lock, checks completion eligibility, and must
     * roll back on failure. This writer never changes receipt, payment or stock.
     */
    function sales_create_online_locked(PDO $pdo, int $orderId, string $soldAt, ?int $adminId = null): array
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('Online sale creation requires an active transaction and order lock.');
        }

        $fail = static function (string $reason, string $message, int $status = 409): array {
            return ['ok' => false, 'reason' => $reason, 'message' => $message, 'status' => $status];
        };

        $itemsStmt = $pdo->prepare(
            'SELECT oi.id AS order_item_id, oi.product_id, oi.product_name,
                    oi.unit_price, oi.quantity, p.barcode_value AS product_barcode
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC
             FOR UPDATE'
        );
        $itemsStmt->execute([':order_id' => $orderId]);
        $items = $itemsStmt->fetchAll();

        if (count($items) === 0) {
            return $fail('empty_order', 'This order has no items and cannot be confirmed.');
        }

        $authoritativeTotalCents = 0;
        foreach ($items as $item) {
            if ((int) $item['quantity'] !== 1) {
                return $fail('invalid_quantity', 'One or more items in this order have an invalid quantity and cannot be confirmed.');
            }
            $cents = sales_money_to_cents($item['unit_price']);
            if ($cents === null) {
                return $fail('error', 'An item price is invalid.', 500);
            }
            $authoritativeTotalCents += $cents;
        }

        // Merchandise comes from order-item price snapshots, never shipping or current prices.
        $subtotalStmt = $pdo->prepare('SELECT subtotal FROM orders WHERE id = :order_id LIMIT 1');
        $subtotalStmt->execute([':order_id' => $orderId]);
        $storedCents = sales_money_to_cents($subtotalStmt->fetchColumn());
        if ($storedCents === null || $authoritativeTotalCents !== $storedCents) {
            return $fail('data_integrity', 'Order total mismatch. Please contact support.', 500);
        }

        try {
            $headerInsert = $pdo->prepare(
                "INSERT INTO sales_transactions
                     (sale_channel, order_id, admin_id, total_amount, sold_at, request_key)
                 VALUES ('ONLINE', :order_id, :admin_id, :total, :sold_at, :request_key)"
            );
            $headerInsert->execute([
                ':order_id' => $orderId,
                ':admin_id' => $adminId,
                ':total' => sales_cents_to_string($authoritativeTotalCents),
                ':sold_at' => $soldAt,
                ':request_key' => sales_online_request_key($orderId),
            ]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $fail('sale_conflict', 'A completed-sale record already exists or conflicts with this order. Reload the order; existing sales will not be changed.');
            }
            throw $e;
        }

        $saleId = (int) $pdo->lastInsertId();
        $itemInsert = $pdo->prepare(
            'INSERT INTO sales_transaction_items
                 (sale_id, product_id, product_name, barcode_value, unit_price, order_item_id)
             VALUES (:sale_id, :product_id, :product_name, :barcode_value, :unit_price, :order_item_id)'
        );

        foreach ($items as $item) {
            $productIdParam = ($item['product_id'] !== null && (int) $item['product_id'] > 0)
                ? (int) $item['product_id']
                : null;
            $barcodeParam = isset($item['product_barcode']) && trim((string) $item['product_barcode']) !== ''
                ? (string) $item['product_barcode']
                : null;

            try {
                $itemInsert->execute([
                    ':sale_id' => $saleId,
                    ':product_id' => $productIdParam,
                    ':product_name' => (string) $item['product_name'],
                    ':barcode_value' => $barcodeParam,
                    ':unit_price' => sales_cents_to_string((int) sales_money_to_cents($item['unit_price'])),
                    ':order_item_id' => (int) $item['order_item_id'],
                ]);
            } catch (PDOException $e) {
                // Product and order-item uniqueness are independent; other
                // integrity failures must not be reported as duplicate sales.
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    return $fail('item_conflict', 'An order item or product is already recorded in a completed sale. Manual review is required.');
                }
                throw $e;
            }
        }

        return ['ok' => true, 'sale_id' => $saleId, 'status' => 200];
    }
}

if (!function_exists('sales_confirm_receipt_online')) {
    /**
     * Persist customer receipt confirmation for an Out for Delivery or legacy
     * delivered Local Courier order. Receipt also completes courier delivery.
     * COD and GCash do not collect payment or create a sale here; collection / payment
     * reconciliation and the completed sale are a later admin transaction.
     * Other payments retain their existing sale behavior and GCash
     * verification guard.
     *
     * Owns its transaction. Returns 'ok', HTTP-style 'status', and on failure
     * 'reason' / 'message'; success has 'idempotent', 'received_at', and 'sale'
     * (null for COD and GCash, since receipt is not evidence of a completed
     * sale).
     */
    function sales_confirm_receipt_online(PDO $pdo, int $customerId, int $orderId): array
    {
        if ($pdo->inTransaction()) throw new LogicException('Receipt confirmation must own its transaction.');
        $abort = static function (array $payload) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!isset($payload['status'])) {
                $payload['status'] = 200;
            }
            return $payload;
        };

        try {
            $pdo->beginTransaction();

            /* Lock the order row and verify ownership before choosing the receipt path.
               One query handles both; unknown/foreign order returns the same
               generic error so order existence and ownership are never leaked. */
            $orderStmt = $pdo->prepare(
                'SELECT id, status, cancellation_status, received_at
                 FROM orders
                 WHERE id = :order_id AND customer_id = :customer_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $orderStmt->execute([':order_id' => $orderId, ':customer_id' => $customerId]);
            $order = $orderStmt->fetch();

            if ($order === false) {
                return $abort([
                    'ok' => false,
                    'reason' => 'not_found',
                    'message' => 'Order not found or access denied.',
                    'status' => 404,
                ]);
            }

            if (!in_array((string) $order['status'], ['SHIPPED', 'DELIVERED'], true)) {
                return $abort([
                    'ok' => false,
                    'reason' => 'not_delivered',
                    'message' => 'Receipt can only be confirmed when a courier order is Out for Delivery or already Delivered.',
                    'status' => 409,
                ]);
            }

            /* Phase 5 — a completed sale must never be created for a GCash
               order whose payment is still awaiting verification or was
               rejected. The payments row is locked AFTER the order row,
               matching the order-then-payment lock order used by the
               verification helpers. COD keeps its established
               unpaid-before-collection workflow (the gate returns null). */
            if (!function_exists('payment_gcash_lock_payment')) {
                require_once __DIR__ . '/payment.php';
            }

            $payment = payment_gcash_lock_payment($pdo, $orderId);
            $paymentBlock = $payment !== false ? payment_gcash_fulfillment_reason($payment) : null;
            if ($paymentBlock !== null) {
                return $abort([
                    'ok' => false,
                    'reason' => $paymentBlock,
                    'message' => payment_gcash_fulfillment_message($paymentBlock),
                    'status' => 409,
                ]);
            }

            $shipment = payment_gcash_lock_shipment($pdo, $orderId);
            if ($shipment === null || !in_array($shipment['delivery_method'], [null, 'LOCAL_COURIER'], true)) {
                return $abort([
                    'ok' => false,
                    'reason' => 'not_courier_order',
                    'message' => 'Receipt confirmation is only available for Local Courier orders with a shipment record.',
                    'status' => 409,
                ]);
            }
            if ($order['status'] === 'SHIPPED') {
                if ($shipment['delivery_method'] !== 'LOCAL_COURIER' || $shipment['shipment_status'] !== 'OUT_FOR_DELIVERY') {
                    return $abort(['ok' => false, 'reason' => 'not_delivered',
                        'message' => 'Receipt confirmation is not available in this delivery state.', 'status' => 409]);
                }
                $stmt = $pdo->prepare('SELECT * FROM shipments WHERE order_id = ? FOR UPDATE');
                $stmt->execute([$orderId]);
                $courier = $stmt->fetch();
                if (!isset($courier['delivery_cycle'], $courier['delivery_revision'])) {
                    return $abort(['ok' => false, 'reason' => 'order_incompatible',
                        'message' => 'The courier workflow is unavailable. Please contact the shop.', 'status' => 409]);
                }
                // The existing delivery writer owns the atomic completion and rechecks
                // ownership, current tokens, attempts, verification and all item holds.
                $pdo->rollBack();
                require_once __DIR__ . '/delivery.php';
                return delivery_action($pdo, 0, $orderId, 'confirm_receipt', (int) $courier['delivery_cycle'],
                    (int) $courier['delivery_revision'], 'OUT_FOR_DELIVERY', bin2hex(random_bytes(16)),
                    customerId: $customerId);
            }
            if ($shipment['shipment_status'] !== 'DELIVERED'
                || in_array($order['cancellation_status'], ['REQUESTED', 'APPROVED'], true)) {
                return $abort([
                    'ok' => false,
                    'reason' => 'order_incompatible',
                    'message' => 'Receipt can only be confirmed for delivered orders without an active cancellation.',
                    'status' => 409,
                ]);
            }

            if ($payment !== false && in_array($payment['payment_method'], ['COD', 'GCASH'], true)) {
                $alreadyReceived = $order['received_at'] !== null;
                $receivedAt = $alreadyReceived ? (string) $order['received_at'] : date('Y-m-d H:i:s');

                if (!$alreadyReceived) {
                    $quantitiesStmt = $pdo->prepare(
                        'SELECT quantity FROM order_items WHERE order_id = :order_id ORDER BY id FOR UPDATE'
                    );
                    $quantitiesStmt->execute([':order_id' => $orderId]);
                    $quantities = $quantitiesStmt->fetchAll(PDO::FETCH_COLUMN);
                    if (count($quantities) === 0 || count(array_filter($quantities, static fn ($qty): bool => (int) $qty !== 1)) > 0) {
                        return $abort([
                            'ok' => false,
                            'reason' => 'invalid_items',
                            'message' => 'This order has missing items or invalid quantities and cannot be confirmed.',
                            'status' => 409,
                        ]);
                    }

                    $receiptUpdate = $pdo->prepare(
                        'UPDATE orders SET received_at = :received_at
                         WHERE id = :order_id AND received_at IS NULL'
                    );
                    $receiptUpdate->execute([':received_at' => $receivedAt, ':order_id' => $orderId]);
                    if ($receiptUpdate->rowCount() !== 1) {
                        return $abort([
                            'ok' => false,
                            'reason' => 'concurrent_update',
                            'message' => 'This order was updated by another request. Please reload the page.',
                            'status' => 409,
                        ]);
                    }
                }

                $pdo->commit();
                return ['ok' => true, 'idempotent' => $alreadyReceived, 'sale' => null, 'received_at' => $receivedAt, 'status' => 200];
            }

            /* STEP 2 — idempotent success: received_at already set.
               Verify the matching ONLINE completed-sale header and required
               details exist and correspond to this order. Inconsistent state
               produces an explicit error. */
            if ($order['received_at'] !== null) {
                $existingHeaderStmt = $pdo->prepare(
                    'SELECT id FROM sales_transactions
                     WHERE order_id = :order_id AND sale_channel = \'ONLINE\'
                     LIMIT 1
                     FOR UPDATE'
                );
                $existingHeaderStmt->execute([':order_id' => $orderId]);
                $existingHeader = $existingHeaderStmt->fetch();

                if ($existingHeader === false) {
                    return $abort([
                        'ok' => false,
                        'reason' => 'inconsistent_state',
                        'message' => 'This order is marked as received but the completed-sale record is missing. Please contact support.',
                        'status' => 500,
                    ]);
                }

                $existingSale = sales_load_transaction($pdo, (int) $existingHeader['id']);
                if ($existingSale === null) {
                    return $abort([
                        'ok' => false,
                        'reason' => 'inconsistent_state',
                        'message' => 'This order is marked as received but the completed-sale record could not be loaded. Please contact support.',
                        'status' => 500,
                    ]);
                }

                /* Count required detail rows from order_items. */
                $requiredStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM order_items WHERE order_id = :order_id'
                );
                $requiredStmt->execute([':order_id' => $orderId]);
                $requiredCount = (int) $requiredStmt->fetchColumn();

                $actualDetailStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM sales_transaction_items WHERE sale_id = :sale_id'
                );
                $actualDetailStmt->execute([':sale_id' => (int) $existingHeader['id']]);
                $actualDetailCount = (int) $actualDetailStmt->fetchColumn();

                if ($actualDetailCount !== $requiredCount) {
                    return $abort([
                        'ok' => false,
                        'reason' => 'inconsistent_state',
                        'message' => 'This order is marked as received but the completed-sale record has an incorrect item count. Please contact support.',
                        'status' => 500,
                    ]);
                }

                $pdo->commit();
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'sale' => $existingSale,
                    'received_at' => (string) $order['received_at'],
                    'status' => 200,
                ];
            }

            $receivedAt = date('Y-m-d H:i:s');
            $saleResult = sales_create_online_locked($pdo, $orderId, $receivedAt);
            if (!$saleResult['ok']) {
                return $abort($saleResult);
            }
            $saleId = (int) $saleResult['sale_id'];

            /* STEP 9 — persist the receipt confirmation. Conditional UPDATE
               ensures exactly one row is updated. rowCount must be 1. */
            $receiptUpdate = $pdo->prepare(
                'UPDATE orders SET received_at = :received_at
                 WHERE id = :order_id AND received_at IS NULL'
            );
            $receiptUpdate->execute([
                ':received_at' => $receivedAt,
                ':order_id' => $orderId,
            ]);

            if ($receiptUpdate->rowCount() !== 1) {
                return $abort([
                    'ok' => false,
                    'reason' => 'concurrent_update',
                    'message' => 'This order was confirmed by another request. Please reload the page.',
                    'status' => 409,
                ]);
            }

            /* STEP 10 — commit. */
            $pdo->commit();

            $sale = sales_load_transaction($pdo, $saleId);
            if ($sale === null) {
                /* The sale was committed but could not be loaded. This should
                   not happen but the receipt confirmation IS persisted, so
                   report partial success with a clear message. */
                return [
                    'ok' => true,
                    'idempotent' => false,
                    'sale' => null,
                    'received_at' => $receivedAt,
                    'status' => 200,
                    'message' => 'Order received confirmation saved. Completed-sale record could not be loaded.',
                ];
            }

            return [
                'ok' => true,
                'idempotent' => false,
                'sale' => $sale,
                'received_at' => $receivedAt,
                'status' => 200,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'ok' => false,
                'reason' => 'error',
                'message' => 'The order could not be confirmed. Please try again.',
                'status' => 500,
            ];
        }
    }
}

if (!function_exists('sales_complete_pickup_online_locked')) {
    /**
     * Create the ONLINE completed sale for a Store Pickup order at the
     * moment its handover is recorded, INSIDE the caller's already-open
     * transaction (the orders row is already locked FOR UPDATE by the
     * caller). Shared by includes/pickup.php pickup_advance_status() and
     * the Phase 3 tests so the courier path
     * (sales_confirm_receipt_online) and the pickup path use identical
     * snapshot, merchandise-total, and exactly-once rules and can never
     * drift.
     *
     * The caller MUST already have verified, under its lock, that the
     * order is fulfilled by STORE_PICKUP, is not CANCELLED, and is
     * transitioning to PICKED_UP. On failure this function does NOT roll
     * back: it returns ['ok'=>false,...] and the caller rolls its own
     * transaction back, so a failed completion can never leave a
     * half-recorded handover.
     *
     * Mirrors sales_confirm_receipt_online: rejects empty orders and any
     * item quantity that is not exactly 1, computes the authoritative
     * merchandise-only total from order_items snapshots (shipping fees
     * excluded), cross-checks orders.subtotal, inserts one ONLINE header
     * (deterministic request key sales_online_request_key) plus one
     * detail row per order item, and persists the completion timestamp
     * on orders.received_at exactly once (the handover IS the customer
     * receipt event for a pickup order). A header duplicate key
     * (UNIQUE(order_id)) is treated as an idempotent prior completion
     * whose header is reused; an item duplicate key aborts the handover.
     *
     * Returns ['ok'=>true,'idempotent'=>bool,'sale_id'=>int,
     * 'completed_at'=>string] or ['ok'=>false,'reason','message','status'].
     */
    function sales_complete_pickup_online_locked(PDO $pdo, int $orderId, string $completedAt): array
    {
        $fail = static function (string $reason, string $message, int $status): array {
            return ['ok' => false, 'reason' => $reason, 'message' => $message, 'status' => $status];
        };

        try {
            /* Phase 5 defense in depth: the pickup lifecycle helper already
               gates the transition, but the completed-sale helper refuses an
               unverified/rejected GCash order on its own so no future caller
               can create a sale from it. The caller holds the order row lock;
               this locks the payments row in the same order-then-payment
               sequence used everywhere else. COD is unaffected. */
            if (!function_exists('payment_gcash_fulfillment_block')) {
                require_once __DIR__ . '/payment.php';
            }

            $paymentBlock = payment_gcash_fulfillment_block($pdo, $orderId);
            if ($paymentBlock !== null) {
                return $fail($paymentBlock, payment_gcash_fulfillment_message($paymentBlock), 409);
            }

            /* Idempotency: an already-completed order (received_at set)
               must carry its ONLINE header; a mismatch is an explicit
               inconsistent-state error, never a silent second sale. */
            $orderStmt = $pdo->prepare(
                'SELECT received_at FROM orders WHERE id = :order_id LIMIT 1'
            );
            $orderStmt->execute([':order_id' => $orderId]);
            $existingReceivedAt = $orderStmt->fetchColumn();

            if ($existingReceivedAt !== false && $existingReceivedAt !== null) {
                $existingHeaderStmt = $pdo->prepare(
                    'SELECT id FROM sales_transactions
                     WHERE order_id = :order_id AND sale_channel = \'ONLINE\'
                     LIMIT 1'
                );
                $existingHeaderStmt->execute([':order_id' => $orderId]);
                $existingId = $existingHeaderStmt->fetchColumn();

                if ($existingId === false) {
                    return $fail(
                        'inconsistent_state',
                        'This order is marked as completed but the completed-sale record is missing.',
                        500
                    );
                }

                return [
                    'ok' => true,
                    'idempotent' => true,
                    'sale_id' => (int) $existingId,
                    'completed_at' => (string) $existingReceivedAt,
                ];
            }

            /* Read all order_items under the caller's lock. */
            $itemsStmt = $pdo->prepare(
                'SELECT oi.id AS order_item_id,
                        oi.product_id,
                        oi.product_name,
                        oi.unit_price,
                        oi.quantity,
                        p.barcode_value AS product_barcode
                 FROM order_items oi
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = :order_id
                 ORDER BY oi.id ASC
                 FOR UPDATE'
            );
            $itemsStmt->execute([':order_id' => $orderId]);
            $items = $itemsStmt->fetchAll();

            if (count($items) === 0) {
                return $fail('empty_order', 'This order has no items and cannot be completed.', 409);
            }

            foreach ($items as $item) {
                if ((int) $item['quantity'] !== 1) {
                    return $fail(
                        'invalid_quantity',
                        'One or more items in this order have an invalid quantity and cannot be completed.',
                        409
                    );
                }
            }

            /* Authoritative merchandise-only total from snapshots
               (shipping fees excluded, matching the courier path). */
            $authoritativeTotalCents = 0;
            foreach ($items as $item) {
                $cents = sales_money_to_cents($item['unit_price']);
                if ($cents === null) {
                    return $fail('error', 'An item price is invalid.', 500);
                }
                $authoritativeTotalCents += $cents;
            }

            $subtotalCheckStmt = $pdo->prepare('SELECT subtotal FROM orders WHERE id = :order_id LIMIT 1');
            $subtotalCheckStmt->execute([':order_id' => $orderId]);
            $storedCents = sales_money_to_cents($subtotalCheckStmt->fetchColumn());
            if ($storedCents === null || $authoritativeTotalCents !== $storedCents) {
                return $fail('data_integrity', 'Order total mismatch.', 500);
            }

            $totalStr = sales_cents_to_string($authoritativeTotalCents);

            /* One ONLINE header; a UNIQUE(order_id) collision means a
               completion already exists (race/legacy), which is reused
               idempotently after its detail rows are verified. */
            $requestKey = sales_online_request_key($orderId);
            $saleId = null;
            $idempotent = false;

            try {
                $headerInsert = $pdo->prepare(
                    "INSERT INTO sales_transactions
                         (sale_channel, order_id, admin_id, total_amount, sold_at, request_key)
                     VALUES
                         ('ONLINE', :order_id, NULL, :total, :sold_at, :request_key)"
                );
                $headerInsert->execute([
                    ':order_id' => $orderId,
                    ':total' => $totalStr,
                    ':sold_at' => $completedAt,
                    ':request_key' => $requestKey,
                ]);
                $saleId = (int) $pdo->lastInsertId();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $byOrderStmt = $pdo->prepare(
                        'SELECT id FROM sales_transactions
                         WHERE order_id = :order_id AND sale_channel = \'ONLINE\'
                         LIMIT 1'
                    );
                    $byOrderStmt->execute([':order_id' => $orderId]);
                    $raceId = $byOrderStmt->fetchColumn();
                    if ($raceId !== false) {
                        $saleId = (int) $raceId;
                        $idempotent = true;
                    }
                }
                if ($saleId === null) {
                    throw $e;
                }
            }

            if ($idempotent) {
                /* The reused header must already carry the full detail
                   set; anything else is an explicit inconsistency. */
                $requiredStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id');
                $requiredStmt->execute([':order_id' => $orderId]);
                $requiredCount = (int) $requiredStmt->fetchColumn();

                $actualStmt = $pdo->prepare('SELECT COUNT(*) FROM sales_transaction_items WHERE sale_id = :sale_id');
                $actualStmt->execute([':sale_id' => $saleId]);
                if ((int) $actualStmt->fetchColumn() !== $requiredCount) {
                    return $fail(
                        'inconsistent_state',
                        'This order is already completed but its completed-sale record has an incorrect item count.',
                        500
                    );
                }
            } else {
                $itemInsert = $pdo->prepare(
                    'INSERT INTO sales_transaction_items
                         (sale_id, product_id, product_name, barcode_value, unit_price, order_item_id)
                     VALUES
                         (:sale_id, :product_id, :product_name, :barcode_value, :unit_price, :order_item_id)'
                );

                foreach ($items as $item) {
                    $productIdParam = ($item['product_id'] !== null && (int) $item['product_id'] > 0)
                        ? (int) $item['product_id']
                        : null;
                    $barcodeParam = isset($item['product_barcode']) && trim((string) $item['product_barcode']) !== ''
                        ? (string) $item['product_barcode']
                        : null;

                    try {
                        $itemInsert->execute([
                            ':sale_id' => $saleId,
                            ':product_id' => $productIdParam,
                            ':product_name' => (string) $item['product_name'],
                            ':barcode_value' => $barcodeParam,
                            ':unit_price' => sales_cents_to_string(
                                (int) sales_money_to_cents($item['unit_price'])
                            ),
                            ':order_item_id' => (int) $item['order_item_id'],
                        ]);
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') {
                            return $fail(
                                'item_conflict',
                                'An order item is already linked to another completed sale.',
                                409
                            );
                        }
                        throw $e;
                    }
                }
            }

            /* Persist the completion timestamp exactly once. Under the
               caller's order-row lock this can never race. */
            $receiptUpdate = $pdo->prepare(
                'UPDATE orders SET received_at = :completed_at
                 WHERE id = :order_id AND received_at IS NULL'
            );
            $receiptUpdate->execute([
                ':completed_at' => $completedAt,
                ':order_id' => $orderId,
            ]);

            if ($receiptUpdate->rowCount() !== 1) {
                return $fail('concurrent_update', 'This order was completed by another request.', 409);
            }

            return [
                'ok' => true,
                'idempotent' => $idempotent,
                'sale_id' => $saleId,
                'completed_at' => $completedAt,
            ];
        } catch (Throwable $e) {
            return $fail('error', 'The completed sale could not be created.', 500);
        }
    }
}

if (!function_exists('sales_product_id_sold')) {
    /**
     * Returns true when the given product id already appears in a committed
     * completed-sale detail row. This is the authoritative "already sold"
     * check and is independent of products.status (which the online checkout
     * sets as a hold before a sale is actually completed).
     */
    function sales_product_id_sold(PDO $pdo, int $productId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT EXISTS (
                 SELECT 1 FROM sales_transaction_items WHERE product_id = :pid
             )'
        );
        $stmt->execute([':pid' => $productId]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('sales_bundle_is_locked')) {
    /**
     * Canonical Phase A bundle-lock predicate.
     *
     * A bundle is LOCKED when, and only when, at least one COMMITTED completed
     * sale contains a product that belongs to that bundle. There is no stored
     * is_locked flag; this derived predicate is the single source of truth and
     * cannot drift. It deliberately does NOT consult products.status, because
     * the online checkout flips products to SOLD as a hold before an online
     * order is completed.
     *
     * A future bundle capital/quantity edit feature (none exists today) must
     * call this inside its own transaction, AFTER taking SELECT ... FOR UPDATE
     * on the purchase_bundles row, and refuse the edit when it returns true.
     */
    function sales_bundle_is_locked(PDO $pdo, int $bundleId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT EXISTS (
                 SELECT 1
                 FROM sales_transaction_items sti
                 INNER JOIN products p ON p.id = sti.product_id
                 WHERE p.bundle_id = :bundle_id
             )'
        );
        $stmt->execute([':bundle_id' => $bundleId]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('sales_lookup_barcode')) {
    /**
     * Preview-only barcode lookup (NO writes). Resolves one exact barcode to a
     * product and returns a preview payload, or a rejection reason. Shared by
     * admin/api/barcode-lookup.php and the Phase A tests so the preview rules
     * never diverge from the page.
     *
     * $draftBarcodes are the barcodes already in the browser draft; a repeat
     * is rejected as 'duplicate'. They are used ONLY for that comparison.
     *
     * Returns:
     *   ['ok'=>true, 'product'=>[...]]
     *   ['ok'=>false, 'reason'=>'<code>', 'message'=>'<text>']
     */
    function sales_lookup_barcode(PDO $pdo, string $barcode, array $draftBarcodes = []): array
    {
        $barcode = sales_normalize_barcode($barcode);

        if (!sales_is_plausible_barcode($barcode)) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => sales_eligibility_message('not_found')];
        }

        foreach ($draftBarcodes as $draftBarcode) {
            if (sales_normalize_barcode($draftBarcode) === $barcode) {
                return ['ok' => false, 'reason' => 'duplicate', 'message' => sales_eligibility_message('duplicate')];
            }
        }

        $stmt = $pdo->prepare(
            'SELECT id, name, category, size, color, price, status,
                    bundle_id, barcode_value, retired_at
             FROM products
             WHERE barcode_value = :barcode
             LIMIT 1'
        );
        $stmt->execute([':barcode' => $barcode]);
        $product = $stmt->fetch();

        if ($product === false) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => sales_eligibility_message('not_found')];
        }

        $reason = sales_product_eligibility_error($product);
        if ($reason !== null) {
            return ['ok' => false, 'reason' => $reason, 'message' => sales_eligibility_message($reason)];
        }

        if (sales_product_id_sold($pdo, (int) $product['id'])) {
            return ['ok' => false, 'reason' => 'already_sold', 'message' => sales_eligibility_message('already_sold')];
        }

        return [
            'ok' => true,
            'product' => [
                'id' => (int) $product['id'],
                'name' => (string) $product['name'],
                'category' => (string) $product['category'],
                'size' => $product['size'] !== null ? (string) $product['size'] : '',
                'color' => $product['color'] !== null ? (string) $product['color'] : '',
                'barcode' => (string) $product['barcode_value'],
                'price' => sales_cents_to_string((int) sales_money_to_cents($product['price'])),
                'status' => (string) $product['status'],
            ],
        ];
    }
}

if (!function_exists('sales_load_transaction')) {
    /**
     * Load a committed sale (header + item snapshots) as a plain array, or
     * null if it does not exist. Used to return the authoritative stored data
     * for both a fresh sale and an idempotent replay.
     *
     * Phase C extension: the header query now exposes order_id (nullable for
     * WALKIN) and admin_id (nullable for ONLINE customer-confirmed sales),
     * and item barcodes are returned as null when the stored snapshot is
     * NULL (legacy/unlinked ONLINE items) or when the item's product_id is
     * NULL (the product was later deleted). Each item also carries its
     * nullable product_id. WALKIN items always carry a non-null product_id
     * and barcode, so existing Phase A callers are unaffected.
     */
    function sales_load_transaction(PDO $pdo, int $saleId): ?array
    {
        $headerStmt = $pdo->prepare(
            'SELECT st.id, st.total_amount, st.sold_at, st.sale_channel, st.order_id,
                    st.admin_id, a.name AS cashier
             FROM sales_transactions st
             LEFT JOIN admins a ON a.id = st.admin_id
             WHERE st.id = :id
             LIMIT 1'
        );
        $headerStmt->execute([':id' => $saleId]);
        $header = $headerStmt->fetch();

        if ($header === false) {
            return null;
        }

        $itemStmt = $pdo->prepare(
            'SELECT product_id, product_name, barcode_value, unit_price
             FROM sales_transaction_items
             WHERE sale_id = :id
             ORDER BY id ASC'
        );
        $itemStmt->execute([':id' => $saleId]);

        $items = [];
        foreach ($itemStmt->fetchAll() as $row) {
            /* A NULL product_id (legacy item, or a historically-sold product
               that was later hard-deleted) means the barcode no longer
               resolves to a product record; the detail is still returned
               with its name/price snapshots, but without a barcode. */
            $productId = $row['product_id'] !== null ? (int) $row['product_id'] : null;
            $barcode = ($productId !== null && $row['barcode_value'] !== null)
                ? (string) $row['barcode_value']
                : null;
            $items[] = [
                'name' => (string) $row['product_name'],
                'barcode' => $barcode,
                'price' => sales_cents_to_string((int) sales_money_to_cents($row['unit_price'])),
                'product_id' => $productId,
            ];
        }

        return [
            'id' => (int) $header['id'],
            'total' => sales_cents_to_string((int) sales_money_to_cents($header['total_amount'])),
            'sold_at' => (string) $header['sold_at'],
            'cashier' => $header['cashier'] !== null ? (string) $header['cashier'] : '',
            'channel' => (string) $header['sale_channel'],
            'order_id' => $header['order_id'] !== null ? (int) $header['order_id'] : null,
            'admin_id' => $header['admin_id'] !== null ? (int) $header['admin_id'] : null,
            'items' => $items,
        ];
    }
}

if (!function_exists('sales_monitor_like_param')) {
    /**
     * Escape LIKE wildcards (% and _) in a user-supplied search term and
     * wrap it in percent signs for a substring match. Literal % or _ in
     * the typed query must match themselves, so they are escaped with a
     * backslash (the default escape character for LIKE in MySQL). The
     * term is also trimmed and capped at 120 characters.
     */
    function sales_monitor_like_param(string $term): string
    {
        $term = trim($term);

        if (mb_strlen($term) > 120) {
            $term = mb_substr($term, 0, 120);
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return '%' . $escaped . '%';
    }
}

if (!function_exists('sales_monitor_source_sql')) {
    /**
     * Read-only projection of canonical sale headers and recorded snapshots.
     * The completion writers remain authoritative; order/payment state can
     * flag conflicting history, but can NEVER substitute for a sale record.
     * Checks below reflect their receipt, handover, payment and cancellation
     * safeguards. Conflicts stay available for review, not repair or counting.
     *
     * Payments and shipments each have UNIQUE(order_id). Aggregate items
     * before joining so this source always has exactly one row per sale.
     * An existing shipment with a legacy NULL method means Local Courier,
     * as in payment_cod_collection_reason/payment_gcash_completion_reason;
     * a missing shipment never supplies a guessed channel.
     */
    function sales_monitor_source_sql(): string
    {
        return "SELECT st.id, st.sale_channel, st.total_amount, st.sold_at,
                       st.order_id, st.admin_id, a.name AS cashier,
                       o.id AS order_number, o.customer_id,
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), ''),
                                NULLIF(TRIM(o.shipping_name), '')) AS customer_name,
                       o.shipping_name, o.shipping_phone, o.shipping_address,
                       o.shipping_fee, o.total_amount AS order_total_amount,
                       o.received_at, o.picked_up_at,
                       sh.delivery_method,
                       CASE
                           WHEN st.sale_channel = 'WALKIN' THEN 'WALKIN'
                           WHEN sh.delivery_method = 'STORE_PICKUP' THEN 'STORE_PICKUP'
                           WHEN sh.id IS NOT NULL AND
                                (sh.delivery_method = 'LOCAL_COURIER' OR sh.delivery_method IS NULL)
                               THEN 'LOCAL_COURIER'
                           ELSE NULL
                       END AS reporting_channel,
                       pm.payment_method, pm.payment_status, pm.gcash_reference,
                       pm.submitted_at, pm.verified_at, pm.verified_by,
                       verifier.name AS payment_admin,
                       COALESCE(items.item_count, 0) AS item_count,
                       CASE
                           WHEN items.item_count IS NULL THEN 'Canonical sale items are missing.'
                           WHEN items.item_total <> st.total_amount
                               THEN 'The sale total differs from its recorded item prices.'
                           WHEN st.sale_channel = 'WALKIN' THEN NULL
                           WHEN o.id IS NULL THEN 'The linked online order is missing.'
                           WHEN o.status = 'CANCELLED' OR o.cancellation_status IN ('REQUESTED', 'APPROVED')
                               THEN 'The linked order has a cancellation conflict.'
                           WHEN o.received_at IS NULL
                               THEN 'Customer receipt or pickup handover has not been recorded.'
                           WHEN pm.id IS NULL THEN 'The online payment record is missing.'
                           WHEN pm.payment_status <> 'PAID'
                               THEN 'The online payment is not verified or collected as paid.'
                           WHEN pm.payment_method = 'GCASH' AND
                                (pm.verified_at IS NULL OR pm.verified_by IS NULL)
                               THEN 'GCash verification attribution is incomplete.'
                           WHEN sh.id IS NULL THEN 'The fulfillment record is missing.'
                           WHEN sh.delivery_method = 'STORE_PICKUP' AND
                                (o.pickup_status IS NULL OR o.pickup_status <> 'PICKED_UP' OR o.picked_up_at IS NULL)
                               THEN 'Store Pickup handover has not been completed.'
                           WHEN (sh.delivery_method = 'LOCAL_COURIER' OR sh.delivery_method IS NULL) AND
                                (o.status <> 'DELIVERED' OR sh.shipment_status <> 'DELIVERED')
                               THEN 'Local Courier delivery has not been completed.'
                           WHEN sh.delivery_method NOT IN ('LOCAL_COURIER', 'STORE_PICKUP')
                               THEN 'The recorded fulfillment method is not supported by this report.'
                           ELSE NULL
                       END AS reporting_issue
                FROM sales_transactions st
                LEFT JOIN admins a ON a.id = st.admin_id
                LEFT JOIN orders o ON o.id = st.order_id
                LEFT JOIN customers c ON c.id = o.customer_id
                LEFT JOIN shipments sh ON sh.order_id = st.order_id
                LEFT JOIN payments pm ON pm.order_id = st.order_id
                LEFT JOIN admins verifier ON verifier.id = pm.verified_by
                LEFT JOIN (
                    SELECT sale_id, COUNT(*) AS item_count, SUM(unit_price) AS item_total
                    FROM sales_transaction_items
                    GROUP BY sale_id
                ) items ON items.sale_id = st.id";
    }
}

if (!function_exists('sales_monitor_today_cents')) {
    /**
     * Global Today cards, independent of list filters. Uses the canonical
     * sold_at in the Asia/Manila timezone pinned by includes/database.php.
     * Online categories combine the existing reporting channel and stored
     * payment method; the combined keys below are report keys, not DB enums.
     * Revenue is the writer's merchandise-only total, never the checkout total.
     */
    function sales_monitor_today_cents(PDO $pdo): array
    {
        $day = new DateTimeImmutable('today');
        $start = $day->format('Y-m-d H:i:s');
        $end = $day->modify('+1 day')->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            "SELECT reporting_channel, payment_method,
                    COUNT(*) AS tx_count,
                    COALESCE(SUM(total_amount), 0) AS tx_total
             FROM (" . sales_monitor_source_sql() . ") sales
             WHERE reporting_issue IS NULL AND sold_at >= :start AND sold_at < :end
             GROUP BY reporting_channel, payment_method"
        );
        $stmt->execute([':start' => $start, ':end' => $end]);

        $result = [
            'WALKIN' => ['count' => 0, 'cents' => 0],
            'LOCAL_COURIER_COD' => ['count' => 0, 'cents' => 0],
            'LOCAL_COURIER_GCASH' => ['count' => 0, 'cents' => 0],
            'STORE_PICKUP_CASH_ON_PICKUP' => ['count' => 0, 'cents' => 0],
            'STORE_PICKUP_GCASH' => ['count' => 0, 'cents' => 0],
            'unclassified_count' => 0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $channel = (string) $row['reporting_channel'];
            $category = $channel === 'WALKIN' ? 'WALKIN' : $channel . '_' . (string) $row['payment_method'];
            if (!isset($result[$category])) {
                // Never relabel an unsupported historical payment combination.
                $result['unclassified_count'] += (int) $row['tx_count'];
                continue;
            }
            $result[$category]['count'] += (int) $row['tx_count'];
            $cents = sales_money_to_cents($row['tx_total']);
            $result[$category]['cents'] += $cents !== null ? $cents : 0;
        }

        return $result;
    }
}

if (!function_exists('sales_monitor_list')) {
    /**
     * Query completed sale transactions for the Sales Monitoring list,
     * with combined search + channel + date-range filtering.
     *
     * $filters accepts:
     *   'search'     => free-text term. Matched (case-insensitively,
     *                   via the utf8mb4 collation) against the padded
     *                   SALE-XXXXXX reference, the raw numeric id, the
     *                   linked online order number, the walk-in
     *                   cashier's name, or the customer's full name.
     *   'channel'    => '' (all) | 'WALKIN' | 'LOCAL_COURIER' | 'STORE_PICKUP'.
     *   'date_from'  => 'YYYY-MM-DD' or '' (inclusive, local day start).
     *   'date_to'    => 'YYYY-MM-DD' or '' (inclusive, local day end).
     *
     * One row per canonical transaction, with snapshot item counts and the
     * actual stored payment fields. No product status or current price is used.
     *
     * $totalOut (optional) is set to the TOTAL number of matching rows
     * ignoring limit/offset, so pagination stays correct under filters.
     * $summaryOut adds filtered merchandise cents and read-only conflict
     * diagnostics. Inconsistent historical headers are not silently counted.
     *
     * Values are validated by the caller; this function only binds.
     */
    function sales_monitor_list(PDO $pdo, array $filters, ?int &$totalOut = null, int $limit = 100, int $offset = 0, ?array &$summaryOut = null): array
    {
        $where = [];
        $params = [];

        $channel = (string) ($filters['channel'] ?? '');
        if (in_array($channel, ['WALKIN', 'LOCAL_COURIER', 'STORE_PICKUP'], true)) {
            $where[] = 'sales.reporting_channel = :channel';
            $params[':channel'] = $channel;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'sales.sold_at >= :from';
            $params[':from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'sales.sold_at < :to';
            $params[':to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = "(CONCAT('SALE-', LPAD(sales.id, GREATEST(6, CHAR_LENGTH(sales.id)), '0')) LIKE :sale_code
                         OR sales.id LIKE :sale_id
                         OR sales.order_number LIKE :order_num
                         OR CONCAT('Order #', sales.order_number) LIKE :order_reference
                         OR sales.cashier LIKE :cashier
                         OR sales.customer_name LIKE :customer
                         OR sales.shipping_name LIKE :recipient)";
            $like = sales_monitor_like_param($search);
            foreach ([':sale_code', ':sale_id', ':order_num', ':order_reference', ':cashier', ':customer', ':recipient'] as $key) {
                $params[$key] = $like;
            }
        }

        $whereClause = count($where) > 0 ? implode(' AND ', $where) . ' AND ' : '';
        $source = ' FROM (' . sales_monitor_source_sql() . ') sales WHERE ' . $whereClause;

        /* Count and total use the same filtered source as the paged rows. */
        $countSql = 'SELECT COUNT(*) AS tx_count, COALESCE(SUM(total_amount), 0) AS tx_total'
            . $source . 'reporting_issue IS NULL';
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totals = $countStmt->fetch();
        $totalOut = (int) $totals['tx_count'];
        $summaryOut = ['cents' => sales_money_to_cents($totals['tx_total']) ?? 0, 'conflicts' => []];

        $conflictStmt = $pdo->prepare(
            'SELECT id, order_id, reporting_issue' . $source
            . 'reporting_issue IS NOT NULL ORDER BY sold_at DESC, id DESC LIMIT 5'
        );
        $conflictStmt->execute($params);
        $summaryOut['conflicts'] = $conflictStmt->fetchAll();

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT sales.*' . $source . 'reporting_issue IS NULL
                ORDER BY sold_at DESC, id DESC
                LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}

if (!function_exists('sales_monitor_detail')) {
    /**
     * Load one completed sale for the Sales Monitoring receipt view.
     *
     * Shares the report's canonical source, including any conflict warning.
     * Keeps shipping/order amounts separate from merchandise revenue and
     * exposes real verification/collection metadata. Quantity is one physical
     * garment per canonical detail; both online writers reject other quantities.
     *
     * Item names/prices are the STORED SNAPSHOTS (product_name /
     * unit_price), so a product's later rename or reprice can never
     * rewrite history. barcodes are omitted for rows whose snapshot is
     * NULL (legacy/unlinked online items) — callers show a fallback.
     *
     * Returns null when the sale id does not exist.
     */
    function sales_monitor_detail(PDO $pdo, int $saleId): ?array
    {
        $headerStmt = $pdo->prepare(
            'SELECT sales.* FROM (' . sales_monitor_source_sql() . ') sales WHERE id = :id LIMIT 1'
        );
        $headerStmt->execute([':id' => $saleId]);
        $sale = $headerStmt->fetch();

        if ($sale === false) {
            return null;
        }

        $itemStmt = $pdo->prepare(
            'SELECT sti.id, sti.product_id, sti.product_name,
                    sti.barcode_value, sti.unit_price, sti.order_item_id
             FROM sales_transaction_items sti
             WHERE sti.sale_id = :id
             ORDER BY sti.id ASC'
        );
        $itemStmt->execute([':id' => $saleId]);

        $items = [];
        foreach ($itemStmt->fetchAll() as $row) {
            $unitCents = (int) sales_money_to_cents($row['unit_price']);
            $items[] = [
                'id' => (int) $row['id'],
                'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
                'name' => (string) $row['product_name'],
                'barcode' => is_string($row['barcode_value']) && $row['barcode_value'] !== '' ? $row['barcode_value'] : null,
                'quantity' => 1,
                'unit_price_cents' => $unitCents,
                'line_total_cents' => $unitCents,
                'order_item_id' => $row['order_item_id'] !== null ? (int) $row['order_item_id'] : null,
            ];
        }

        return [
            'id' => (int) $sale['id'],
            'channel' => (string) $sale['sale_channel'],
            'total_cents' => (int) sales_money_to_cents($sale['total_amount']),
            'sold_at' => (string) $sale['sold_at'],
            'order_id' => $sale['order_id'] !== null ? (int) $sale['order_id'] : null,
            'admin_id' => $sale['admin_id'] !== null ? (int) $sale['admin_id'] : null,
            'cashier' => is_string($sale['cashier']) && $sale['cashier'] !== '' ? $sale['cashier'] : null,
            'order_number' => $sale['order_number'] !== null ? (int) $sale['order_number'] : null,
            'customer_name' => is_string($sale['customer_name']) && trim($sale['customer_name']) !== '' ? trim($sale['customer_name']) : null,
            'payment_method' => is_string($sale['payment_method']) && $sale['payment_method'] !== '' ? $sale['payment_method'] : null,
            'payment_status' => is_string($sale['payment_status']) && $sale['payment_status'] !== '' ? $sale['payment_status'] : null,
            'shipping_cents' => sales_money_to_cents($sale['shipping_fee']),
            'order_total_cents' => sales_money_to_cents($sale['order_total_amount']),
            'items' => $items,
        ] + $sale;
    }
}

if (!function_exists('sales_complete_walkin')) {
    /**
     * Create ONE completed walk-in sale, atomically. This is the single
     * authoritative sale path, shared by admin/api/walkin-sale.php and the
     * Phase A tests. It assumes its inputs were already shape-validated by the
     * caller (barcodes normalized, plausible, unique and non-empty; a valid
     * request key; an integer expected total in centavos), and re-validates
     * everything that matters against the locked database state.
     *
     * The thirteen-step sequence (idempotency, lock, revalidate eligibility,
     * revalidate per-item price staleness, validate aggregate total, lock
     * bundles, conditional SOLD update with row-count check, insert header +
     * items, commit) runs inside one PDO transaction.  Any rule violation or
     * error rolls the whole transaction back so no partial sale remains.
     *
     * Per-item stale-price detection: $previewPricesCents must contain one
     * integer-cent value per barcode in the same order. Each is compared
     * against the current database price under row locks. If any individual
     * line differs the server returns HTTP 409 with fresh prices; the cashier
     * must review and resubmit. The aggregate-total guard is a secondary
     * sanity check. Database prices are the only source of truth for the
     * stored total and per-item unit_price snapshots; submitted prices are
     * used only for comparison, never stored.
     *
     * Returns an array that always carries 'ok', 'status' (HTTP-style), and on
     * failure 'reason' and 'message'; on success 'idempotent' and 'sale'.
     */
    function sales_complete_walkin(PDO $pdo, int $adminId, array $barcodes, int $expectedTotalCents, array $previewPricesCents, string $requestKey): array
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

        /* Defensive re-validation of inputs (the HTTP layer also checks). */
        if (!sales_is_valid_request_key($requestKey)) {
            return ['ok' => false, 'reason' => 'bad_request', 'message' => 'Missing or invalid request key.', 'status' => 400];
        }

        $normalized = [];
        foreach ($barcodes as $raw) {
            $code = sales_normalize_barcode($raw);
            if (!sales_is_plausible_barcode($code)) {
                return ['ok' => false, 'reason' => 'bad_request', 'message' => 'One of the submitted items is malformed.', 'status' => 400];
            }
            $normalized[] = $code;
        }
        if (count($normalized) === 0) {
            return ['ok' => false, 'reason' => 'bad_request', 'message' => 'No items to sell.', 'status' => 400];
        }
        if (count(array_unique($normalized)) !== count($normalized)) {
            return ['ok' => false, 'reason' => 'bad_request', 'message' => 'The sale contains a duplicated item.', 'status' => 400];
        }
        $barcodes = $normalized;

        /* Validate and index per-item preview prices.
           $previewPricesCents must have one integer-cent entry per barcode,
           in the same order. Index by barcode for O(1) per-row lookup. */
        if (!is_array($previewPricesCents) || count($previewPricesCents) !== count($barcodes)) {
            return ['ok' => false, 'reason' => 'bad_request', 'message' => 'Item prices are missing or mismatched.', 'status' => 400];
        }
        $previewByBarcode = [];
        foreach ($barcodes as $i => $code) {
            $cents = $previewPricesCents[$i] ?? null;
            if (!is_int($cents) || $cents < 0) {
                return ['ok' => false, 'reason' => 'bad_request', 'message' => 'One of the item prices is invalid.', 'status' => 400];
            }
            $previewByBarcode[$code] = $cents;
        }

        try {
            $pdo->beginTransaction();

            /* STEP 1 - idempotency: reuse an existing sale for this key.
               Verify the barcode set matches to prevent a key from being
               reused for a completely different sale. */
            $existingStmt = $pdo->prepare('SELECT id FROM sales_transactions WHERE request_key = :key LIMIT 1');
            $existingStmt->execute([':key' => $requestKey]);
            $existingId = $existingStmt->fetchColumn();

            if ($existingId !== false) {
                /* Same key → same item set must hold. Load items and compare barcodes. */
                $existingItemsStmt = $pdo->prepare('SELECT barcode_value FROM sales_transaction_items WHERE sale_id = :sid ORDER BY id ASC');
                $existingItemsStmt->execute([':sid' => (int) $existingId]);
                $existingBarcodes = $existingItemsStmt->fetchAll(PDO::FETCH_COLUMN);
                if (count($existingBarcodes) !== count($barcodes) || count(array_diff($existingBarcodes, $barcodes)) !== 0) {
                    $pdo->rollBack();
                    return [
                        'ok' => false,
                        'reason' => 'key_conflict',
                        'message' => 'This request key was already used for a different item set. Please reload the scan page to get a fresh draft.',
                        'status' => 409,
                    ];
                }
                $sale = sales_load_transaction($pdo, (int) $existingId);
                $pdo->commit();
                if ($sale === null) {
                    return ['ok' => false, 'reason' => 'error', 'message' => 'The existing sale could not be loaded.', 'status' => 500];
                }
                return ['ok' => true, 'idempotent' => true, 'sale' => $sale, 'status' => 200];
            }

            /* STEP 2 & 3 - resolve + lock product rows (ordered by id). */
            $inProducts = implode(',', array_fill(0, count($barcodes), '?'));
            $lockStmt = $pdo->prepare(
                'SELECT id, name, price, status, bundle_id, barcode_value, retired_at
                 FROM products
                 WHERE barcode_value IN (' . $inProducts . ')
                 ORDER BY id ASC
                 FOR UPDATE'
            );
            $lockStmt->execute($barcodes);
            $rows = $lockStmt->fetchAll();

            /* STEP 4 - every barcode must resolve exactly once. */
            $rowByBarcode = [];
            foreach ($rows as $row) {
                $rowByBarcode[(string) $row['barcode_value']] = $row;
            }
            $missing = [];
            foreach ($barcodes as $code) {
                if (!isset($rowByBarcode[$code])) {
                    $missing[] = $code;
                }
            }
            if (count($missing) > 0) {
                return $abort(['ok' => false, 'reason' => 'not_found', 'message' => 'One or more items are no longer valid. Please review the draft.', 'barcodes' => $missing]);
            }

            /* STEP 5 - revalidate eligibility + not-already-sold, under lock. */
            $productIds = [];
            foreach ($rows as $row) {
                $reason = sales_product_eligibility_error($row);
                if ($reason !== null) {
                    return $abort(['ok' => false, 'reason' => $reason, 'message' => sales_eligibility_message($reason), 'barcodes' => [(string) $row['barcode_value']]]);
                }
                $productIds[] = (int) $row['id'];
            }

            $inIds = implode(',', array_fill(0, count($productIds), '?'));
            $soldStmt = $pdo->prepare('SELECT product_id FROM sales_transaction_items WHERE product_id IN (' . $inIds . ')');
            $soldStmt->execute($productIds);
            if (count($soldStmt->fetchAll(PDO::FETCH_COLUMN)) > 0) {
                return $abort(['ok' => false, 'reason' => 'already_sold', 'message' => sales_eligibility_message('already_sold')]);
            }

            /* STEP 6 - per-item staleness: compare each submitted preview price
               against the current database price while rows are locked.
               This catches compensating per-item changes that preserve the total.
               Only database prices are stored; client prices are never trusted. */
            $prices = [];
            $authoritativeTotalCents = 0;
            foreach ($rows as $row) {
                $dbCents = (int) sales_money_to_cents($row['price']);
                if ($dbCents === null) {
                    return $abort(['ok' => false, 'reason' => 'error', 'message' => 'A product price is invalid.', 'status' => 500]);
                }
                $barcode = (string) $row['barcode_value'];
                $previewCents = $previewByBarcode[$barcode] ?? null;
                if ($previewCents === null) {
                    return $abort(['ok' => false, 'reason' => 'error', 'message' => 'Missing preview price for an item.', 'status' => 500]);
                }
                if ($dbCents !== $previewCents) {
                    $freshItems = [];
                    foreach ($rows as $r) {
                        $freshItems[] = [
                            'barcode' => (string) $r['barcode_value'],
                            'price' => sales_cents_to_string((int) sales_money_to_cents($r['price'])),
                        ];
                    }
                    $itemTotal = 0;
                    foreach ($rows as $r) {
                        $itemTotal += (int) sales_money_to_cents($r['price']);
                    }
                    return $abort([
                        'ok' => false,
                        'reason' => 'price_changed',
                        'message' => 'Prices changed since you reviewed the draft. Please check the updated total before completing the sale.',
                        'items' => $freshItems,
                        'total' => sales_cents_to_string($itemTotal),
                        'status' => 409,
                    ]);
                }
                $prices[] = $row['price'];
                $authoritativeTotalCents += $dbCents;
            }

            /* STEP 6b - aggregate total guard: ensure the client total matches the
               authoritative sum. This is a secondary sanity check. */
            if ($authoritativeTotalCents !== $expectedTotalCents) {
                $freshItems = [];
                foreach ($rows as $row) {
                    $freshItems[] = [
                        'barcode' => (string) $row['barcode_value'],
                        'price' => sales_cents_to_string((int) sales_money_to_cents($row['price'])),
                    ];
                }
                return $abort([
                    'ok' => false,
                    'reason' => 'price_changed',
                    'message' => 'Prices changed since you reviewed the draft. Please check the updated total before completing the sale.',
                    'items' => $freshItems,
                    'total' => sales_cents_to_string($authoritativeTotalCents),
                    'status' => 409,
                ]);
            }

            /* STEP 7 - lock bundle rows (distinct, ordered). */
            $bundleIds = [];
            foreach ($rows as $row) {
                $bundleIds[(int) $row['bundle_id']] = true;
            }
            $bundleIds = array_keys($bundleIds);
            sort($bundleIds);
            if (count($bundleIds) > 0) {
                $inBundles = implode(',', array_fill(0, count($bundleIds), '?'));
                $pdo->prepare('SELECT id FROM purchase_bundles WHERE id IN (' . $inBundles . ') ORDER BY id ASC FOR UPDATE')->execute($bundleIds);
            }

            /* STEP 8 & 9 - conditional AVAILABLE -> SOLD + row-count check. */
            $updateStmt = $pdo->prepare('UPDATE products SET status = ? WHERE status = ? AND id IN (' . $inIds . ')');
            $updateStmt->execute(array_merge(['SOLD', 'AVAILABLE'], $productIds));
            if ($updateStmt->rowCount() !== count($productIds)) {
                return $abort(['ok' => false, 'reason' => 'unavailable', 'message' => 'One or more items became unavailable. No sale was recorded.']);
            }

            /* STEP 10 - insert header; duplicate key means a same-key race.
               sold_at is set explicitly using PHP's date() (which uses the
               Asia/Manila timezone set in database.php) rather than relying
               on MySQL DEFAULT CURRENT_TIMESTAMP, to ensure PHP and MySQL
               always agree on the timezone of committed timestamps. */
            $totalStr = sales_cents_to_string($authoritativeTotalCents);
            $soldAt = date('Y-m-d H:i:s');
            try {
                $headerInsert = $pdo->prepare(
                    "INSERT INTO sales_transactions (sale_channel, order_id, admin_id, total_amount, sold_at, request_key)
                     VALUES ('WALKIN', NULL, ?, ?, ?, ?)"
                );
                $headerInsert->execute([$adminId, $totalStr, $soldAt, $requestKey]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $byKeyStmt = $pdo->prepare('SELECT id FROM sales_transactions WHERE request_key = :key LIMIT 1');
                    $byKeyStmt->execute([':key' => $requestKey]);
                    $raceId = $byKeyStmt->fetchColumn();
                    if ($raceId !== false) {
                        $sale = sales_load_transaction($pdo, (int) $raceId);
                        if ($sale !== null) {
                            return ['ok' => true, 'idempotent' => true, 'sale' => $sale, 'status' => 200];
                        }
                    }
                }
                throw $e;
            }

            $saleId = (int) $pdo->lastInsertId();

            /* STEP 11 - insert one snapshot detail per garment. */
            $itemInsert = $pdo->prepare(
                'INSERT INTO sales_transaction_items (sale_id, product_id, product_name, barcode_value, unit_price)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($rows as $row) {
                try {
                    $itemInsert->execute([
                        $saleId,
                        (int) $row['id'],
                        (string) $row['name'],
                        (string) $row['barcode_value'],
                        sales_cents_to_string((int) sales_money_to_cents($row['price'])),
                    ]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        return $abort(['ok' => false, 'reason' => 'already_sold', 'message' => sales_eligibility_message('already_sold'), 'barcodes' => [(string) $row['barcode_value']]]);
                    }
                    throw $e;
                }
            }

            /* STEP 12 - commit. */
            $pdo->commit();

            $sale = sales_load_transaction($pdo, $saleId);
            if ($sale === null) {
                return ['ok' => false, 'reason' => 'error', 'message' => 'The sale was recorded but could not be loaded.', 'status' => 500];
            }
            return ['ok' => true, 'idempotent' => false, 'sale' => $sale, 'status' => 200];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'reason' => 'error', 'message' => 'The sale could not be completed. Please try again.', 'status' => 500];
        }
    }
}
