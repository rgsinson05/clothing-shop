<?php

/*
 * Hopia Fits - Phase 14 customer order notification shared helpers.
 *
 * Include-only: this file declares functions and performs no output and no
 * side effects, matching the convention of includes/admin_notifications.php,
 * includes/sales.php, and includes/pickup.php. It is the SINGLE SOURCE OF
 * TRUTH for:
 *   - the supported customer notification event types and their labels,
 *   - the consistent, customer-safe message generation,
 *   - creating a notification inside an order-action transaction,
 *   - per-customer retrieval, unread counting, and read-state marking, and
 *   - deriving the safe internal destination for each event.
 *
 * It is required by the admin order-action integration points (the courier
 * status advance, the Store Pickup lifecycle advance, the Store Pickup
 * handover/finalization paths, and the admin cancellation paths) and by the
 * customer notification API endpoint.
 *
 * Storage: customer_order_notifications (migration 015). This is a SEPARATE
 * table from the Phase 13 admin_order_notifications inbox. Customer
 * notifications are keyed to a customer (recipient) and carry their own
 * read state and access control; they never share records or read state
 * with the admin new-order inbox.
 *
 * CRITICAL invariants:
 *   - A notification is created ONLY from a function that is already inside
 *     the authoritative order-action transactionais. It must be called after
 *     the order transition has been validated and applied but before that
 *     transaction commits, so the order change and the notification either
 *     both commit or both roll back. A caller outside a transaction throws.
 *   - Duplicate protection is enforced by the UNIQUE key
 *     (customer_id, order_id, notification_type, occurrence_key) and made safe in
 *     application logic with an idempotent INSERT ... ON DUPLICATE KEY
 *     UPDATE no-op. Legacy events keep the default 'legacy' identity;
 *     new courier events use their authoritative delivery-event identity.
 *   - The customer_id is derived from the order row inside the transaction
 *     (server-side), never trusted from request input.
 *   - Messages are generated here so the text stays consistent across all
 *     integration points. They are informational only: creating, fetching,
 *     or reading a notification never changes payment, collection, sale,
 *     inventory, barcode, fulfillment, or cancellation state.
 *   - The destination is derived from the event type + order id into the
 *     existing customer order detail page. No stored path, so no customer
 *     can manipulate metadata into an arbitrary external URL.
 *
 * If migration 015 has not been applied, the INSERT fails and the enclosing
 * order transaction rolls back (the established house pattern: checkout
 * fails when its Phase 13 admin notification cannot be recorded). This
 * surfaces the migration dependency rather than silently pretending
 * notifications work.
 */

if (!function_exists('customer_notifications_event_labels')) {
    /**
     * Customer-facing label for each supported notification event type.
     * Keys are the stored notification_type values. Used for presentation
     * so the event id and its human label never drift.
     */
    function customer_notifications_event_labels(): array
    {
        return [
            'ORDER_SHIPPED' => 'Order Shipped',
            'ORDER_DELIVERED' => 'Order Delivered',
            'PICKUP_READY' => 'Ready for Pickup',
            'PICKUP_HANDED_OVER' => 'Pickup Handed Over',
            'ORDER_CANCELLED' => 'Order Cancelled',
            'OUT_FOR_DELIVERY' => 'Out for Delivery',
            'DELIVERY_ATTEMPT_FAILED' => 'Delivery Attempt Failed',
            'RETURN_TO_SELLER' => 'Return to Seller',
            'RETURNED_TO_SHOP' => 'Returned to Shop',
        ];
    }
}

if (!function_exists('customer_notifications_is_supported_event')) {
    /** True when the string is one of the supported notification events. */
    function customer_notifications_is_supported_event(string $type): bool
    {
        return isset(customer_notifications_event_labels()[$type]);
    }
}

if (!function_exists('customer_notifications_message')) {
    /**
     * Generate the consistent, customer-safe message for one supported
     * event. $trackingNumber is optional and used only for the shipped
     * event; every other event ignores it. No raw order data beyond the id
     * is embeddedholly; the returned string contains no payment, GCash, or
     * proof-of-payment material.
     */
    function customer_notifications_message(string $type, int $orderId, ?string $trackingNumber = null): string
    {
        switch ($type) {
            case 'ORDER_SHIPPED':
                $tracking = $trackingNumber !== null && trim($trackingNumber) !== ''
                    ? ' Your tracking number is ' . trim($trackingNumber) . '.'
                    : '';
                return 'Your order #' . $orderId . ' has been shipped.' . $tracking;
            case 'ORDER_DELIVERED':
                return 'Your order #' . $orderId . ' has been delivered. Please confirm receipt of your order.';
            case 'PICKUP_READY':
                return 'Your order #' . $orderId . ' is ready for pickup at the store.';
            case 'PICKUP_HANDED_OVER':
                return 'Your pickup order #' . $orderId . ' has been handed over to you.';
            case 'ORDER_CANCELLED':
                return 'Your order #' . $orderId . ' has been cancelled.';
            case 'OUT_FOR_DELIVERY':
                return 'Your order #' . $orderId . ' is out for delivery.';
            case 'DELIVERY_ATTEMPT_FAILED':
                return 'A delivery attempt for your order #' . $orderId . ' was unsuccessful. The shop will review the next delivery step.';
            case 'RETURN_TO_SELLER':
                return 'Your order #' . $orderId . ' is being returned to the seller after unsuccessful delivery attempts.';
            case 'RETURNED_TO_SHOP':
                return 'The parcel for your order #' . $orderId . ' has returned to Hopia Fits and its delivery journey has ended. Please contact the shop if you need assistance.';
            default:
                throw new InvalidArgumentException('Unsupported customer notification event.');
        }
    }
}

if (!function_exists('customer_notifications_destination')) {
    /**
     * The safe internal destination for one notification. Every supported
     * event routes to the existing customer order detail page, which
     * re-verifies order ownership server-side. No arbitrary path is ever
     * accepted, so a customer cannot navigate a notification outside the
     * customer order area.
     */
    function customer_notifications_destination(int $orderId): string
    {
        return 'order-detail.php?id=' . max(1, $orderId);
    }
}

if (!function_exists('customer_notifications_create')) {
    /**
     * Create one customer notification for an order event, inside the
     * caller's transaction. Requires an active transaction and a valid
     * supported event. The customer_id is resolved from the order row
     * (server-side) and the recipient must exist; if the order or its
     * customer cannot be resolved, the whole transaction fails.
     *
     * Migration 016 adds occurrence identity without rewriting legacy events.
     * An event-specific identity permits multiple legitimate courier attempts
     * while a repeated invocation remains an idempotent no-op.
     *
     * Throws when not inside a transaction or when the notification cannot
     * be recorded; the caller's rollback then aborts the order action too,
     * so the two commit together or not at all.
     */
    function customer_notifications_create(
        PDO $pdo,
        int $orderId,
        string $type,
        ?string $trackingNumber = null,
        string $occurrenceKey = 'legacy'
    ): void {
        if (!$pdo->inTransaction() || $orderId <= 0) {
            throw new LogicException('Customer notifications require the order transaction.');
        }
        if (!customer_notifications_is_supported_event($type)) {
            throw new InvalidArgumentException('Unsupported customer notification event.');
        }
        if ($occurrenceKey !== 'legacy' && preg_match('/^delivery-event-[1-9][0-9]{0,19}$/D', $occurrenceKey) !== 1) {
            throw new InvalidArgumentException('Invalid customer notification occurrence.');
        }

        $message = customer_notifications_message($type, $orderId, $trackingNumber);
        if ($type === 'ORDER_DELIVERED') {
            $receipt = $pdo->prepare('SELECT received_at FROM orders WHERE id = ?');
            $receipt->execute([$orderId]);
            $receivedAt = $receipt->fetchColumn();
            if ($receivedAt !== false && $receivedAt !== null) {
                $message = 'Your order #' . $orderId . ' has been delivered and your receipt confirmation is recorded. Thank you!';
            }
        }
        if (function_exists('mb_substr') && mb_strlen($message, 'UTF-8') > 255) {
            $message = mb_substr($message, 0, 255, 'UTF-8');
        } elseif (strlen($message) > 255) {
            $message = substr($message, 0, 255);
        }

        // Legacy creators retain their old SQL contract and default identity.
        // New delivery occurrences require migration 016, in the event transaction.
        $occurrenceColumn = $occurrenceKey === 'legacy' ? '' : ', occurrence_key';
        $occurrenceValue = $occurrenceKey === 'legacy' ? '' : ', :occurrence';
        $stmt = $pdo->prepare(
            "INSERT INTO customer_order_notifications
                (customer_id, order_id, notification_type, message{$occurrenceColumn})
             SELECT o.customer_id, o.id, :type, :message{$occurrenceValue}
             FROM orders o
             WHERE o.id = :order_id
             ON DUPLICATE KEY UPDATE id = customer_order_notifications.id"
        );
        $params = [
            ':type' => $type,
            ':message' => $message,
            ':order_id' => $orderId,
        ];
        if ($occurrenceKey !== 'legacy') $params[':occurrence'] = $occurrenceKey;
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare(
                'SELECT id FROM customer_order_notifications
                 WHERE customer_id = (
                     SELECT customer_id FROM orders WHERE id = :order_id LIMIT 1
                 )
                  AND order_id = :order_id2 AND notification_type = :type2'
                 . ($occurrenceKey === 'legacy' ? '' : ' AND occurrence_key = :occurrence')
            );
            $params = [
                ':order_id' => $orderId,
                ':order_id2' => $orderId,
                ':type2' => $type,
            ];
            if ($occurrenceKey !== 'legacy') $params[':occurrence'] = $occurrenceKey;
            $exists->execute($params);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('The customer notification could not be recorded.');
            }
        }
    }
}

if (!function_exists('customer_notifications_present')) {
    /**
     * Safe presentation of one customer notification row. Returns only
     * customer-safe fields: id, order id, event type + label, read/unread,
     * the message, the creation time in the frontend ATOM convention, a
     * local display label, and the derived internal destination. No
     * payment, GCash, or proof-of-payment material is ever exposed.
     */
    function customer_notifications_present(array $row): array
    {
        $type = (string) ($row['notification_type'] ?? '');
        $orderId = (int) ($row['order_id'] ?? 0);
        $message = (string) ($row['message'] ?? '');
        // Public copy only; the original notification and its history stay stored.
        if ($type === 'RETURNED_TO_SHOP') $message = customer_notifications_message($type, $orderId);
        $created = new DateTimeImmutable((string) ($row['created_at'] ?? 'now'), new DateTimeZone('Asia/Manila'));
        return [
            'id' => (int) ($row['id'] ?? 0),
            'order_id' => $orderId,
            'type' => $type,
            'label' => customer_notifications_event_labels()[$type] ?? $type,
            'message' => $message,
            'read' => ($row['read_at'] ?? null) !== null,
            'created_at' => $created->format(DateTimeInterface::ATOM),
            'time_label' => $created->format('M j, Y, g:i A') . ' PHT',
            'url' => customer_notifications_destination($orderId),
        ];
    }
}

if (!function_exists('customer_notifications_unread_count')) {
    /** The authenticated customer's unread notification count. */
    function customer_notifications_unread_count(PDO $pdo, int $customerId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM customer_order_notifications
             WHERE customer_id = :customer_id AND read_at IS NULL
               AND notification_type <> \'DELIVERY_CYCLE_AUTHORIZED\''
        );
        $stmt->execute([':customer_id' => $customerId]);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('customer_notifications_latest')) {
    /**
     * The customer's newest notifications, newest first, bounded to a
     * sensible page size (default 10, hard cap 50). Returns the safe
     * presentation rows. $hasMore is set to true when another page exists
     * beyond the returned slice, so pagination is stable and explicit.
     */
    function customer_notifications_latest(
        PDO $pdo,
        int $customerId,
        int $limit = 10,
        int $offset = 0
    ): array {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $stmt = $pdo->prepare(
            'SELECT id, order_id, notification_type, message, created_at, read_at
             FROM customer_order_notifications
             WHERE customer_id = :customer_id
               AND notification_type <> \'DELIVERY_CYCLE_AUTHORIZED\'
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $notifications = array_map('customer_notifications_present', $stmt->fetchAll(PDO::FETCH_ASSOC));

        $hasMore = false;
        if (count($notifications) === $limit) {
            $next = $pdo->prepare(
                'SELECT 1 FROM customer_order_notifications
                  WHERE customer_id = :customer_id
                    AND notification_type <> \'DELIVERY_CYCLE_AUTHORIZED\'
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1 OFFSET :offset'
            );
            $next->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $next->bindValue(':offset', $offset + $limit, PDO::PARAM_INT);
            $next->execute();
            $hasMore = $next->fetchColumn() !== false;
        }

        return ['notifications' => $notifications, 'has_more' => $hasMore];
    }
}

if (!function_exists('customer_notifications_for_order')) {
    /**
     * The customer's notifications for one specific order, newest first.
     * Ownership is enforced by the WHERE on customer_id, so a customer can
     * never retrieve another customer's notifications for an order.
     */
    function customer_notifications_for_order(PDO $pdo, int $customerId, int $orderId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, order_id, notification_type, message, created_at, read_at
             FROM customer_order_notifications
             WHERE customer_id = :customer_id AND order_id = :order_id
               AND notification_type <> \'DELIVERY_CYCLE_AUTHORIZED\'
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':customer_id' => $customerId, ':order_id' => $orderId]);
        return array_map('customer_notifications_present', $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('customer_notifications_mark_read')) {
    /**
     * Mark one of the customer's notifications as read, idempotently.
     * Ownership is enforced in the WHERE clause (customer_id AND id), so
     * guessing another customer's id is a safe no-op. The original read
     * timestamp is preserved once set: the UPDATE only writes read_at when
     * it is still NULLting.
     *
     * Returns true when a row was (newly) marked read, false when the id
     * was not found for this customer or was already read. The caller may
     * treat a false return as "already read or not yours" without leaking
     * which one.
     */
    function customer_notifications_mark_read(PDO $pdo, int $customerId, int $notificationId): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE customer_order_notifications
             SET read_at = NOW()
             WHERE customer_id = :customer_id
                AND id = :notification_id
                AND notification_type <> \'DELIVERY_CYCLE_AUTHORIZED\'
               AND read_at IS NULL'
        );
        $stmt->execute([
            ':customer_id' => $customerId,
            ':notification_id' => $notificationId,
        ]);
        return $stmt->rowCount() === 1;
    }
}

if (!function_exists('customer_notifications_mark_all_read')) {
    /**
     * Mark all of the customer's notifications as read, idempotently. The
     * WHERE on customer_id keeps this strictly within the authenticated
     * customer's records; already-read rows are untouched so their original
     * read timestamps are preserved. Returns the number of newly-read rows.
     */
    function customer_notifications_mark_all_read(PDO $pdo, int $customerId): int
    {
        $stmt = $pdo->prepare(
            'UPDATE customer_order_notifications
             SET read_at = NOW()
              WHERE customer_id = :customer_id AND read_at IS NULL
                AND notification_type <> \'DELIVERY_CYCLE_AUTHORIZED\''
        );
        $stmt->execute([':customer_id' => $customerId]);
        return $stmt->rowCount();
    }
}
