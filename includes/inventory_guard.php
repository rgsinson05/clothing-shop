<?php

require_once __DIR__ . '/return_stock_schema.php';

/**
 * Caller holds the product lock in a READ COMMITTED transaction. Membership
 * writers (checkout) also lock products before inserting a hold. Non-locking
 * reads are fresh at this isolation and avoid product -> item/order inversion.
 * Generic editing/deletion is never a returned-item resolution path.
 */
function inventory_assert_unlinked_locked(PDO $pdo, int $productId): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Inventory checks require the product transaction.');
    foreach (['order_items', 'sales_transaction_items'] as $table) {
        $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE product_id = ? LIMIT 1');
        $stmt->execute([$productId]);
        if ($stmt->fetchColumn() !== false) {
            throw new RuntimeException('An ordered or sold item cannot be released or deleted through generic product management.');
        }
    }
}

/** Ignore resolved cancellations and strictly audited, actually released returns. */
function inventory_has_other_hold_locked(PDO $pdo, int $productId, int $orderId): bool
{
    if (!$pdo->inTransaction()) throw new LogicException('Inventory checks require the product transaction.');
    $sql = "SELECT oi.id FROM order_items oi
        LEFT JOIN orders o ON o.id = oi.order_id
        LEFT JOIN payments pm ON pm.order_id = oi.order_id
        LEFT JOIN shipments sh ON sh.order_id = oi.order_id
        LEFT JOIN sales_transactions st ON st.order_id = oi.order_id
        WHERE oi.product_id = ? AND oi.order_id <> ? AND (
            o.id IS NULL OR o.status <> 'CANCELLED' OR o.cancellation_status <> 'APPROVED'
            OR o.received_at IS NOT NULL OR o.picked_up_at IS NOT NULL
            OR o.pickup_status = 'PICKED_UP' OR st.id IS NOT NULL
            OR pm.id IS NULL OR pm.payment_status NOT IN ('PENDING','FAILED')
            OR pm.payment_method NOT IN ('COD','GCASH','CASH_ON_PICKUP')
            OR pm.verified_at IS NOT NULL OR pm.verified_by IS NOT NULL
            OR sh.id IS NULL OR sh.shipment_status NOT IN ('NOT_SHIPPED','READY_TO_SHIP')
            OR (sh.delivery_method IS NOT NULL AND sh.delivery_method NOT IN ('LOCAL_COURIER','STORE_PICKUP'))
            OR sh.shipped_at IS NOT NULL OR sh.delivered_at IS NOT NULL
        ) LIMIT 1";
    if (return_stock_schema_cached_status($pdo)['state'] === 'ready') {
        // HOLD_RESOLVED alone never releases the historical reservation. The
        // garment may later be SOLD again, so do not require current AVAILABLE.
        $disposed = "o.status = 'SHIPPED' AND o.cancellation_status IN ('NONE','REJECTED')
            AND o.received_at IS NULL AND o.picked_up_at IS NULL AND o.pickup_status IS NULL
            AND pm.payment_method = 'COD' AND pm.payment_status = 'PENDING'
            AND pm.verified_by IS NULL AND pm.verified_at IS NULL AND st.id IS NULL
            AND sh.delivery_method = 'LOCAL_COURIER' AND sh.shipment_status = 'RETURNED_TO_SHOP'
            AND sh.delivery_cycle >= 1 AND sh.delivery_revision >= 1 AND sh.failed_attempts = 3
            AND sh.delivered_at IS NULL AND sh.shipped_at >= '1970-01-01 00:00:00'
            AND sh.tracking_number IS NOT NULL AND CHAR_LENGTH(TRIM(sh.tracking_number)) > 0
            AND oi.quantity = 1
            AND NOT EXISTS (SELECT 1 FROM order_items du WHERE du.order_id = oi.order_id AND du.product_id = oi.product_id AND du.id <> oi.id)
            AND NOT EXISTS (SELECT 1 FROM sales_transaction_items si WHERE si.order_item_id = oi.id)
            AND EXISTS (SELECT 1 FROM return_stock_events rs
                JOIN return_stock_events ins ON ins.order_id = rs.order_id AND ins.delivery_cycle = rs.delivery_cycle
                    AND ins.order_item_id = rs.order_item_id AND ins.product_id = rs.product_id
                    AND ins.physical_return_event_id = rs.physical_return_event_id AND ins.event_kind = 'INSPECTION'
                    AND ins.outcome = 'PASSED' AND ins.previous_inventory_state = 'SOLD' AND ins.resulting_inventory_state = 'SOLD'
                JOIN return_stock_events hr ON hr.order_id = rs.order_id AND hr.delivery_cycle = rs.delivery_cycle
                    AND hr.order_item_id = rs.order_item_id AND hr.product_id = rs.product_id
                    AND hr.physical_return_event_id = rs.physical_return_event_id AND hr.event_kind = 'HOLD_RESOLVED'
                    AND hr.outcome = 'RESOLVED' AND hr.previous_inventory_state = 'SOLD' AND hr.resulting_inventory_state = 'SOLD'
                JOIN delivery_events de ON de.id = rs.physical_return_event_id AND de.order_id = rs.order_id
                    AND de.delivery_cycle = rs.delivery_cycle AND de.event_type = 'RETURNED_TO_SHOP'
                    AND de.previous_state = 'RETURN_TO_SELLER' AND de.resulting_state = 'RETURNED_TO_SHOP'
                JOIN delivery_event_items di ON di.event_id = de.id AND di.order_id = oi.order_id
                    AND di.delivery_cycle = sh.delivery_cycle AND di.order_item_id = oi.id AND di.product_id = oi.product_id
                    AND di.record_type = 'RETURNED' AND di.outcome = 'RETURNED'
                WHERE rs.order_id = oi.order_id AND rs.delivery_cycle = sh.delivery_cycle
                    AND rs.order_item_id = oi.id AND rs.product_id = oi.product_id AND rs.event_kind = 'STOCK_RELEASED'
                    AND rs.outcome = 'AVAILABLE' AND rs.previous_inventory_state = 'SOLD' AND rs.resulting_inventory_state = 'AVAILABLE'
                    AND rs.delivery_revision BETWEEN de.delivery_revision AND sh.delivery_revision
                    AND ins.delivery_revision BETWEEN de.delivery_revision AND rs.delivery_revision
                    AND hr.delivery_revision BETWEEN de.delivery_revision AND rs.delivery_revision
                    AND ins.id < rs.id AND hr.id < rs.id AND de.delivery_revision >= 1
                    AND de.created_at >= sh.shipped_at AND de.created_at >= '1970-01-01 00:00:00'
                    AND rs.admin_id > 0 AND ins.admin_id > 0 AND hr.admin_id > 0 AND de.admin_id > 0
                    AND CHAR_LENGTH(TRIM(rs.reason)) > 0 AND CHAR_LENGTH(TRIM(ins.reason)) > 0
                    AND CHAR_LENGTH(TRIM(hr.reason)) > 0 AND CHAR_LENGTH(TRIM(de.reason)) > 0
                    AND rs.created_at >= ins.created_at AND rs.created_at >= hr.created_at
                    AND ins.created_at >= de.created_at AND hr.created_at >= de.created_at
                    AND (SELECT COUNT(*) FROM delivery_events seller_count WHERE seller_count.order_id = oi.order_id
                        AND seller_count.delivery_cycle = sh.delivery_cycle AND seller_count.event_type = 'RETURN_TO_SELLER') = 1
                    AND EXISTS (SELECT 1 FROM delivery_events seller WHERE seller.order_id = oi.order_id
                        AND seller.delivery_cycle = sh.delivery_cycle AND seller.event_type = 'RETURN_TO_SELLER'
                        AND seller.previous_state = 'DELIVERY_ATTEMPT_FAILED' AND seller.resulting_state = 'RETURN_TO_SELLER'
                        AND seller.admin_id > 0 AND CHAR_LENGTH(TRIM(seller.reason)) > 0
                        AND seller.id < de.id AND seller.delivery_revision < de.delivery_revision
                        AND seller.created_at >= '1970-01-01 00:00:00' AND seller.created_at <= de.created_at
                        AND seller.id > (SELECT MAX(failed.id) FROM delivery_events failed WHERE failed.order_id = oi.order_id
                            AND failed.delivery_cycle = sh.delivery_cycle AND failed.event_type = 'DELIVERY_ATTEMPT_FAILED')
                        AND seller.delivery_revision >= (SELECT MAX(failed_revision.delivery_revision) FROM delivery_events failed_revision
                            WHERE failed_revision.order_id = oi.order_id AND failed_revision.delivery_cycle = sh.delivery_cycle
                            AND failed_revision.event_type = 'DELIVERY_ATTEMPT_FAILED'))
                    AND EXISTS (SELECT 1 FROM delivery_events latest WHERE latest.order_id = oi.order_id
                        AND latest.delivery_cycle = sh.delivery_cycle AND latest.delivery_revision = sh.delivery_revision
                        AND latest.resulting_state = 'RETURNED_TO_SHOP'
                        AND latest.id = (SELECT MAX(newest.id) FROM delivery_events newest WHERE newest.order_id = oi.order_id))
                    AND (SELECT COUNT(*) FROM delivery_events df WHERE df.order_id = oi.order_id
                        AND df.delivery_cycle = sh.delivery_cycle AND df.event_type = 'DELIVERY_ATTEMPT_FAILED'
                        AND df.attempt_number BETWEEN 1 AND 3 AND df.delivery_revision < de.delivery_revision
                        AND df.previous_state = 'OUT_FOR_DELIVERY' AND df.resulting_state = 'DELIVERY_ATTEMPT_FAILED'
                        AND df.admin_id > 0 AND df.created_at >= '1970-01-01 00:00:00'
                        AND CHAR_LENGTH(TRIM(df.reason)) > 0) = 3
                    AND NOT EXISTS (SELECT 1 FROM delivery_event_items mi WHERE mi.order_id = oi.order_id
                        AND mi.delivery_cycle = sh.delivery_cycle AND mi.order_item_id = oi.id
                        AND mi.record_type = 'INSPECTED' AND mi.outcome = 'MANUAL_REVIEW'))";
        try {
            $stmt = $pdo->prepare(substr($sql, 0, -strlen(' LIMIT 1')) . ' AND NOT COALESCE((' . $disposed . '), 0) LIMIT 1');
            $stmt->execute([$productId, $orderId]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable $error) {
            // Missing/unreadable ledger never weakens the original hold guard.
        }
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$productId, $orderId]);
    return $stmt->fetchColumn() !== false;
}
