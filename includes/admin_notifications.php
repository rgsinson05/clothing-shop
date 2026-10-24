<?php

require_once __DIR__ . '/shipping.php';

/** Called only by customer checkout, after its dependent writes, before commit. */
function admin_notifications_create(PDO $pdo, int $orderId): void
{
    if (!$pdo->inTransaction() || $orderId <= 0) {
        throw new LogicException('New-order notifications require the order transaction.');
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO admin_order_notifications (order_id, notification_type, delivery_method, payment_method)
             SELECT o.id, 'NEW_ORDER', s.delivery_method, p.payment_method
             FROM orders o
             INNER JOIN shipments s ON s.order_id = o.id
             INNER JOIN payments p ON p.order_id = o.id
             WHERE o.id = :order_id
             ON DUPLICATE KEY UPDATE id = admin_order_notifications.id"
        );
        $stmt->execute([':order_id' => $orderId]);

        // A no-op retry is valid; a missing payment/shipment must fail the checkout.
        $exists = $pdo->prepare("SELECT id FROM admin_order_notifications WHERE order_id = ? AND notification_type = 'NEW_ORDER'");
        $exists->execute([$orderId]);
        if ($exists->fetchColumn() === false) {
            throw new RuntimeException('The new-order notification could not be recorded.');
        }
    } catch (PDOException $e) {
        /*
         * Fail open ONLY when the inbox table is absent (migration 014 not
         * yet applied). Checkout must still succeed so a real customer can
         * place an order before the additive admin-inbox migration is run;
         * the missing table is a deployment dependency, not an order failure.
         * SQLSTATE 42S02 is MySQL's "base table or view not found" (1146).
         * Every other database failure still propagates and rolls the order
         * transaction back, preserving integrity, stock, and payment writes.
         */
        if ($e->getCode() === '42S02') {
            error_log('Admin new-order notification skipped: migration 014 not applied '
                . '(admin_order_notifications missing) for order ' . $orderId . '.');
            return;
        }
        throw $e;
    }
}

function admin_notifications_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM admin_order_notifications WHERE read_at IS NULL')->fetchColumn();
}

function admin_notifications_badge(int $count): string
{
    return $count >= 10 ? '9+' : (string) max(0, $count);
}

/** Shared presentation for the bell and history; no current order status inferred. */
function admin_notifications_present(array $row): array
{
    $fulfillments = shipping_fulfillment_methods();
    $payments = ['COD' => 'COD', 'GCASH' => 'GCash', 'CASH_ON_PICKUP' => 'Cash on Pickup', 'CARD' => 'Card'];
    $delivery = $fulfillments[$row['delivery_method']] ?? 'Unknown fulfillment';
    $payment = $payments[$row['payment_method']] ?? 'Unknown payment';
    $created = new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('Asia/Manila'));
    return [
        'id' => (int) $row['id'],
        'order_id' => (int) $row['order_id'],
        'message' => 'New Order #' . (int) $row['order_id'] . ' - ' . $delivery . ' / ' . $payment,
        'created_at' => $created->format(DateTimeInterface::ATOM),
        'time_label' => $created->format('M j, Y, g:i A') . ' PHT',
        'unread' => $row['read_at'] === null,
        'url' => 'order-view.php?id=' . (int) $row['order_id'],
    ];
}

/** Five for the dropdown, at most 21 (20 + next-page sentinel) for history. */
function admin_notifications_latest(PDO $pdo, int $limit = 5, int $offset = 0): array
{
    $stmt = $pdo->prepare(
        'SELECT id, order_id, delivery_method, payment_method, created_at, read_at
         FROM admin_order_notifications
         ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', max(1, min(21, $limit)), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    return array_map('admin_notifications_present', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Server-captured IDs, never a browser-supplied cutoff or order ID. */
function admin_notifications_unread_ids(PDO $pdo): array
{
    return $pdo->query('SELECT id FROM admin_order_notifications WHERE read_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}

function admin_notifications_mark_read(PDO $pdo, array $ids): void
{
    foreach (array_chunk($ids, 500) as $batch) {
        $stmt = $pdo->prepare(
            'UPDATE admin_order_notifications SET read_at = NOW()
             WHERE read_at IS NULL AND id IN (' . implode(',', array_fill(0, count($batch), '?')) . ')'
        );
        $stmt->execute($batch);
    }
}

function admin_notifications_open(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        // Exact snapshot membership also excludes a late-committing lower ID,
        // which a simple MAX(id) cutoff could accidentally mark as read.
        $ids = admin_notifications_unread_ids($pdo);
        admin_notifications_mark_read($pdo, $ids);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
