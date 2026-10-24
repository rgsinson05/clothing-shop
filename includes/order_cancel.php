<?php

require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/customer_notifications.php';
require_once __DIR__ . '/inventory_guard.php';

if (!function_exists('order_cancel_pending_locked')) {
    /**
     * Shared cancellation/hold release extracted from admin/order-view.php.
     * The caller owns the transaction and the PENDING order lock, and checks
     * its action-specific eligibility before calling. Cart items stay removed;
     * only this order's existing SOLD holds become AVAILABLE.
     */
    function order_cancel_pending_locked(PDO $pdo, array $order, ?int $adminId = null, ?string $reason = null): void
    {
        if (!$pdo->inTransaction() || ($order['status'] ?? '') !== 'PENDING'
            || !in_array($order['cancellation_status'] ?? '', ['NONE', 'REQUESTED', 'REJECTED'], true)) {
            throw new RuntimeException('The order cannot be cancelled in its current state.');
        }

        $orderId = (int) $order['id'];
        // Revalidate rather than trusting a caller's cached PENDING row. A
        // physical return cannot be disguised as a pending hold cancellation.
        $fresh = payment_gcash_lock_order($pdo, $orderId, null);
        $payment = payment_gcash_lock_payment($pdo, $orderId);
        $shipment = payment_gcash_lock_shipment($pdo, $orderId);
        if ($fresh === false || $fresh['status'] !== 'PENDING'
            || $fresh['cancellation_status'] !== $order['cancellation_status']
            || $fresh['received_at'] !== null || $fresh['picked_up_at'] !== null || $fresh['pickup_status'] === 'PICKED_UP'
            || $payment === false || !in_array($payment['payment_status'], ['PENDING', 'FAILED'], true)
            || !in_array($payment['payment_method'], ['COD', 'GCASH', 'CASH_ON_PICKUP'], true)
            || $payment['verified_by'] !== null || $payment['verified_at'] !== null
            || $shipment === null || !empty($shipment['has_completed_sale'])
            || !in_array($shipment['delivery_method'], [null, 'LOCAL_COURIER', 'STORE_PICKUP'], true)
            || !in_array($shipment['shipment_status'], ['NOT_SHIPPED', 'READY_TO_SHIP'], true)
            || $shipment['shipped_at'] !== null || $shipment['delivered_at'] !== null
            || payment_cod_sale_linkage_error($pdo, $orderId, true) !== null) {
            throw new RuntimeException('This hold cannot be released safely. Administrative review is required.');
        }
        $soldProductsStmt = $pdo->prepare(
            'SELECT p.id
             FROM order_items oi
             INNER JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :order_id AND p.status = \'SOLD\'
             ORDER BY p.id ASC FOR UPDATE'
        );
        $soldProductsStmt->execute([':order_id' => $orderId]);
        $soldProductIds = array_values(array_unique(array_map('intval', $soldProductsStmt->fetchAll(PDO::FETCH_COLUMN))));
        foreach ($soldProductIds as $productId) {
            $retirement = $pdo->prepare('SELECT retired_at FROM products WHERE id = ? FOR UPDATE');
            $retirement->execute([$productId]);
            if ($retirement->fetchColumn() !== null) {
                throw new RuntimeException('An ineligible item cannot be restored. Administrative review is required.');
            }
            // Caller transactions use READ COMMITTED for a fresh non-locking
            // check of other orders, without inverse product -> order locks.
            if (inventory_has_other_hold_locked($pdo, $productId, $orderId)) {
                throw new RuntimeException('Another order holds an item. No inventory was released.');
            }
        }

        $attribution = $reason !== null
            ? ', cancellation_reason = :reason, cancelled_by = :admin_id, cancelled_at = NOW()'
            : '';
        $cancelStmt = $pdo->prepare(
            'UPDATE orders SET status = \'CANCELLED\', cancellation_status = \'APPROVED\' ' . $attribution . '
             WHERE id = :order_id AND status = \'PENDING\' AND cancellation_status = :cancellation_status'
        );
        $params = [':order_id' => $orderId, ':cancellation_status' => $order['cancellation_status']];
        if ($reason !== null) {
            $params[':reason'] = $reason;
            $params[':admin_id'] = $adminId;
        }
        $cancelStmt->execute($params);
        if ($cancelStmt->rowCount() !== 1) {
            throw new RuntimeException('The order could not be cancelled.');
        }

        if (count($soldProductIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($soldProductIds), '?'));
            $restoreStmt = $pdo->prepare(
                'UPDATE products SET status = \'AVAILABLE\' WHERE status = \'SOLD\' AND id IN (' . $placeholders . ')'
            );
            $restoreStmt->execute($soldProductIds);
            if ($restoreStmt->rowCount() !== count($soldProductIds)) {
                throw new RuntimeException('The ordered products could not be restored.');
            }
        }
    }
}

if (!function_exists('order_cancel_expired_gcash')) {
    /** Manual Phase 6 action. Expiry alone never mutates an order. */
    function order_cancel_expired_gcash(PDO $pdo, int $adminId, int $orderId, string $reason, ?int $expectedRevision = null): array
    {
        $fail = static function (string $code, int $status = 409) use ($pdo): array {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'reason' => $code, 'message' => payment_message($code), 'status' => $status];
        };

        $reason = trim($reason);
        if ($reason === '') {
            return $fail('cancellation_reason_required', 422);
        }
        if (strlen($reason) > 255) {
            return $fail('reason_too_long', 422);
        }

        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            $adminStmt = $pdo->prepare('SELECT id FROM admins WHERE id = :admin_id LIMIT 1');
            $adminStmt->execute([':admin_id' => $adminId]);
            if ($adminId <= 0 || $adminStmt->fetchColumn() === false) {
                return $fail('admin_required', 403);
            }

            $order = payment_gcash_lock_order($pdo, $orderId, null);
            if ($order === false) {
                return $fail('not_found', 404);
            }
            $payment = payment_gcash_lock_payment($pdo, $orderId);
            if ($payment === false) {
                return $fail('not_gcash');
            }
            $shipment = payment_gcash_lock_shipment($pdo, $orderId);
            $block = payment_gcash_expired_cancel_reason($order, $payment, $shipment);
            if ($block !== null) {
                return $fail($block);
            }
            if ($expectedRevision !== null && (int) $payment['revision'] !== $expectedRevision) {
                return $fail('conflict');
            }

            order_cancel_pending_locked($pdo, $order, $adminId, $reason);

            /*
             * Phase 14: record the customer's Cancelled notification in the
             * SAME transaction that cancelled the order. The existing GCash
             * expiry-cancellation rules are unchanged and the cancellation has
             * already succeeded here. A failure rolls the whole cancellation
             * back together with the notification.
             */
            customer_notifications_create($pdo, $orderId, 'ORDER_CANCELLED');

            $pdo->commit();
            return ['ok' => true, 'status' => 200];
        } catch (Throwable $e) {
            return $fail('error', 500);
        }
    }
}
