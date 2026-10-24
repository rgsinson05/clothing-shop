<?php

declare(strict_types=1);

require_once __DIR__ . '/delivery_schema.php';

/** Display-only labels, not transition or retry eligibility rules. */
function customer_delivery_labels(): array
{
    return [
        'NOT_SHIPPED' => 'Not Shipped', 'READY_TO_SHIP' => 'Ready to Ship',
        'SHIPPED' => 'Shipped', 'IN_TRANSIT' => 'In Transit',
        'OUT_FOR_DELIVERY' => 'Out for Delivery',
        'DELIVERY_ATTEMPT_FAILED' => 'Delivery Attempt Failed',
        'RETURN_TO_SELLER' => 'Return to Seller', 'RETURNED_TO_SHOP' => 'Returned to Shop',
        'DELIVERED' => 'Delivered',
    ];
}

/** Returned-cycle presentation only; earlier cycles and stored SHIPPED are not dispatch evidence. */
function customer_returned_timeline_steps(array $order, ?array $shipment, ?array $history, bool $closed): array
{
    $shippedAt = $shipment['shipped_at'] ?? null;
    $shippedDate = is_string($shippedAt) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $shippedAt)
        ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $shippedAt, new DateTimeZone('Asia/Manila')) : false;
    $dispatched = $shippedDate !== false && $shippedDate->format('Y-m-d H:i:s') === $shippedAt;
    $currentCycle = ($history['state'] ?? '') === 'ready' ? ($history['current_cycle'] ?? null) : null;
    foreach ($currentCycle !== null ? ($history['cycles'][$currentCycle] ?? []) : [] as $event) {
        if (in_array($event['type'] ?? '', ['SHIPPED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY',
            'DELIVERY_ATTEMPT_FAILED', 'DELIVERED'], true)) {
            $dispatched = true;
        }
    }

    // Confirmation and packing logically precede known physical dispatch.
    return [
        'PENDING' => 'complete',
        'CONFIRMED' => $dispatched || in_array($order['status'] ?? '', ['CONFIRMED', 'PACKED'], true) ? 'complete' : 'unreached',
        'PACKED' => $dispatched || ($order['status'] ?? '') === 'PACKED' ? 'complete' : 'unreached',
        'SHIPPED' => $dispatched ? 'complete' : 'unreached',
        'IN_TRANSIT' => $closed ? 'complete' : 'current',
        'DELIVERED' => 'unreached',
        'COMPLETED' => 'unreached',
    ];
}

/** Presentation only: receipt closes the customer journey, not a sale or payment. */
function customer_order_timeline(array $order, ?array $payment, ?array $shipment, ?array $history): array
{
    $orderStatus = $order['status'] ?? '';
    $stage = match ($orderStatus) {
        'PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED' => $orderStatus,
        default => null,
    };
    $courierStage = ($history['state'] ?? '') === 'ready' && ($history['stage'] ?? null) !== null
        ? $history['stage'] : ($shipment['shipment_status'] ?? null);

    if ($orderStatus === 'CANCELLED' || ($order['cancellation_status'] ?? '') === 'APPROVED') {
        return ['stage' => 'CANCELLED', 'badge_stage' => 'CANCELLED', 'courier_stage' => null,
            'courier_label' => null, 'closure' => null, 'message' => 'This order has been cancelled.',
            'returned_step_states' => null, 'returned_closed' => false];
    }

    // Return custody is authoritative even when the order retains an earlier stage.
    if (in_array($courierStage, ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'], true)) {
        $stage = 'IN_TRANSIT';
    } elseif (in_array($orderStatus, ['SHIPPED', 'IN_TRANSIT', 'DELIVERED'], true)) {
        if (in_array($courierStage, ['SHIPPED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY',
            'DELIVERY_ATTEMPT_FAILED', 'RETURN_TO_SELLER', 'RETURNED_TO_SHOP'], true)) {
            $stage = $courierStage === 'SHIPPED' ? 'SHIPPED' : 'IN_TRANSIT';
        } elseif ($courierStage === 'DELIVERED' && $orderStatus !== 'DELIVERED') {
            $stage = null;
        } elseif ($courierStage !== 'DELIVERED' && $orderStatus === 'DELIVERED') {
            $stage = null;
        }
    }

    $messages = [
        'PENDING' => 'Order received; awaiting shop confirmation.',
        'CONFIRMED' => 'The shop confirmed your order.',
        'PACKED' => 'Your order is prepared for shipment.',
        'SHIPPED' => 'Your order has been shipped.',
        'IN_TRANSIT' => 'Your parcel is in transit. Check the delivery updates below.',
        'OUT_FOR_DELIVERY' => 'Your order is out for delivery! Please be ready to receive your parcel.',
        'DELIVERY_ATTEMPT_FAILED' => "We couldn't complete the delivery attempt. Please check the delivery update for details.",
        'RETURN_TO_SELLER' => 'Delivery was unsuccessful after three attempts. Your parcel is being returned to the shop.',
        'RETURNED_TO_SHOP' => 'Your parcel has returned to the shop. Your delivery journey with Hopia Fits has ended.',
        'DELIVERED' => "Your order has been delivered. Please confirm once you've received it.",
    ];
    $message = $messages[($stage === 'IN_TRANSIT' ? $courierStage : $stage) ?? '']
        ?? 'The shop is reviewing your order status. Please check the order details below.';

    $closure = $courierStage === 'RETURNED_TO_SHOP' ? 'RETURNED_TO_SHOP' : null;
    if ($stage === 'DELIVERED' && !empty($order['received_at'])) {
        $closure = 'RECEIVED';
        $message = 'Your receipt confirmation is recorded. Thank you for shopping with Hopia Fits!';
    }

    $courierLabel = customer_delivery_labels()[$courierStage ?? ''] ?? null;
    if ($stage === 'DELIVERED') {
        $courierLabel = empty($order['received_at'])
            ? 'Delivered - Awaiting Receipt Confirmation' : 'Delivered - Receipt Confirmed';
    }
    $badgeStage = in_array($courierStage, ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'], true) ? $courierStage : $stage;
    $returnedClosed = $closure === 'RETURNED_TO_SHOP' && ($shipment['delivery_method'] ?? '') !== 'STORE_PICKUP';
    $returnedSteps = in_array($courierStage, ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'], true)
        && ($shipment['delivery_method'] ?? '') !== 'STORE_PICKUP'
        ? customer_returned_timeline_steps($order, $shipment, $history, $returnedClosed) : null;
    return ['stage' => $stage, 'badge_stage' => $badgeStage, 'courier_stage' => $courierStage,
        'courier_label' => $courierLabel, 'closure' => $closure, 'message' => $message,
        'returned_step_states' => $returnedSteps, 'returned_closed' => $returnedClosed];
}

/**
 * Called after the page's ownership check and schema gate. Every data read also
 * scopes ownership and courier fulfillment in SQL. Cycle authorization and
 * revisions are read internally; only allowlisted public events leave this helper.
 */
function customer_delivery_history(PDO $pdo, int $orderId, int $customerId, array $schema): array
{
    $view = ['state' => $schema['state'], 'stage' => null, 'current_cycle' => null,
        'cycles' => [], 'current_event' => null, 'current_type' => null, 'error' => null];
    if ($schema['state'] !== 'ready' || $orderId < 1 || $customerId < 1) return $view;

    try {
        // One statement gives shipment and event history a consistent read snapshot.
        $stmt = $pdo->prepare("SELECT s.shipment_status, s.delivery_cycle AS current_cycle,
                s.delivery_revision AS current_revision,
                e.id, e.delivery_cycle, e.delivery_revision, e.event_type, e.attempt_number, e.resulting_state, e.created_at,
                CASE WHEN e.event_type = 'DELIVERY_ATTEMPT_FAILED' THEN e.reason ELSE NULL END AS reason
            FROM orders o
            INNER JOIN shipments s ON s.order_id = o.id AND s.delivery_method = 'LOCAL_COURIER'
            LEFT JOIN delivery_events e ON e.order_id = o.id
            WHERE o.id = :order_id AND o.customer_id = :customer_id
            ORDER BY e.created_at ASC, e.id ASC");
        $stmt->execute([':order_id' => $orderId, ':customer_id' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return $view;

        $view['stage'] = $rows[0]['shipment_status'];
        $currentCycle = (int) $rows[0]['current_cycle'];
        $authorized = [1 => true];
        $labels = customer_delivery_labels();
        $publicEvents = ['OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED', 'RETURN_TO_SELLER', 'RETURNED_TO_SHOP', 'DELIVERED'];
        foreach ($rows as $row) {
            if ($row['id'] === null) continue;
            $cycle = (int) $row['delivery_cycle'];
            if ($row['event_type'] === 'DELIVERY_CYCLE_AUTHORIZED' && $cycle > 1) {
                $authorized[$cycle] = true;
            }
            if (!in_array($row['event_type'], $publicEvents, true)) continue;
            if ($cycle < 1 || !isset($authorized[$cycle])) {
                $view['error'] = 'Some delivery history is unavailable. Please contact the shop for assistance.';
                continue;
            }
            $view['cycles'][$cycle][] = [
                'id' => (string) $row['id'], 'type' => $row['event_type'],
                'label' => $labels[$row['event_type']], 'attempt' => $row['attempt_number'],
                'reason' => $row['event_type'] === 'DELIVERY_ATTEMPT_FAILED' ? $row['reason'] : null,
                'created_at' => $row['created_at'],
            ];
            // Private inspections advance revisions without changing return custody.
            // Keep the latest public return highlighted, never the inspection itself.
            if ($cycle === $currentCycle && $row['resulting_state'] === $view['stage']
                && (int) $row['delivery_revision'] <= (int) $row['current_revision']
                && ((int) $row['delivery_revision'] === (int) $row['current_revision']
                    || $view['stage'] === 'RETURNED_TO_SHOP')) {
                $view['current_event'] = (string) $row['id'];
                $view['current_type'] = $row['event_type'];
            }
        }
        // A counter alone never establishes authorization for a later cycle.
        if (isset($authorized[$currentCycle])) {
            $view['current_cycle'] = $currentCycle;
        } else {
            $view['error'] = 'Some delivery history is unavailable. Please contact the shop for assistance.';
        }
        return $view;
    } catch (Throwable $error) {
        $view['error'] = 'Delivery history is temporarily unavailable. Please try again later.';
        return $view;
    }
}
