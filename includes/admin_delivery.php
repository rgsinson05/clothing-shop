<?php

declare(strict_types=1);

require_once __DIR__ . '/delivery.php';
require_once __DIR__ . '/return_stock.php';
require_once __DIR__ . '/customer_delivery.php';

function admin_delivery_labels(): array
{
    return [
        'NOT_SHIPPED' => 'Not Shipped', 'READY_TO_SHIP' => 'Ready to Ship',
        'SHIPPED' => 'Shipped', 'IN_TRANSIT' => 'In Transit',
        'OUT_FOR_DELIVERY' => 'Out for Delivery', 'DELIVERY_ATTEMPT_FAILED' => 'Delivery Attempt Failed',
        'RETURN_TO_SELLER' => 'Return to Seller', 'RETURNED_TO_SHOP' => 'Returned to Shop',
        'DELIVERED' => 'Delivered', 'RETURN_INSPECTED' => 'Returned Item Checked',
        'DELIVERY_CYCLE_AUTHORIZED' => 'Historical Delivery Cycle Authorized',
        'RETURNED' => 'Physically Returned', 'RETRY_APPROVED' => 'Historical Inspection: Retry Approved',
        'MANUAL_REVIEW' => 'MANUAL_REVIEW',
    ];
}

function admin_delivery_badge(string $state): string
{
    return match ($state) {
        'DELIVERED', 'RETRY_APPROVED' => 'badge-success',
        'DELIVERY_ATTEMPT_FAILED', 'RETURN_TO_SELLER', 'RETURNED_TO_SHOP', 'MANUAL_REVIEW' => 'badge-warning',
        'NOT_SHIPPED' => 'badge-pending',
        default => 'badge-info',
    };
}

/** Use the accepted customer stage mapping, with matching recorded courier evidence. */
function admin_delivery_progress(array $order, ?array $payment, ?array $shipment, array $events = []): array
{
    $currentEvent = null;
    $currentType = null;
    $cycles = [];
    foreach ($events as $event) $cycles[(int) $event['delivery_cycle']][] = ['type' => $event['event_type']];
    if (isset($shipment['delivery_cycle'], $shipment['delivery_revision'])) {
        foreach ($events as $event) {
            if ((int) $event['delivery_cycle'] === (int) $shipment['delivery_cycle']
                && (int) $event['delivery_revision'] === (int) $shipment['delivery_revision']
                && $event['resulting_state'] === $shipment['shipment_status']) {
                $currentEvent = (string) $event['id'];
                $currentType = $event['event_type'];
            }
        }
    }
    $progress = customer_order_timeline($order, $payment, $shipment, [
        'state' => 'ready', 'stage' => $shipment['shipment_status'] ?? null, 'current_type' => $currentType,
        'current_cycle' => isset($shipment['delivery_cycle']) ? (int) $shipment['delivery_cycle'] : null,
        'cycles' => $cycles,
    ]) + ['current_event' => $currentEvent];
    if ($progress['stage'] === 'DELIVERED') {
        $progress['courier_label'] = empty($order['received_at'])
            ? 'Delivered - Awaiting Customer Order Received'
            : 'Delivered - Customer Order Received Confirmed';
    }
    if (($shipment['shipment_status'] ?? '') === 'RETURNED_TO_SHOP' && $currentType === 'RETURN_INSPECTED') {
        // Inspection remains a distinct internal ledger event, not a new
        // physical courier outcome after the parcel has arrived at the shop.
        $progress['courier_stage'] = 'RETURNED_TO_SHOP';
        $progress['courier_label'] = 'Returned to Shop';
    }
    if ($progress['stage'] === 'DELIVERED' && !empty($order['received_at'])
        && !empty($order['has_completed_sale']) && ($payment['payment_status'] ?? '') === 'PAID'
        && in_array($order['cancellation_status'] ?? '', ['NONE', 'REJECTED'], true)) {
        $progress['stage'] = 'COMPLETED';
    }
    return $progress;
}

function admin_delivery_actions(): array
{
    return [
        'out_for_delivery' => [
            'label' => 'Mark Out for Delivery',
            'confirmation' => 'Confirm that the courier is making the next delivery attempt. This records Out for Delivery within the In Transit stage. No failure, customer receipt or payment collection will be recorded.',
            'reason_label' => null,
        ],
        'fail_attempt' => [
            'label' => 'Record Failed Attempt',
            'confirmation' => 'Record only a confirmed failed attempt. After the third failure this cycle is exhausted. Return to Seller requires a separate explicit action and return reason; no fourth attempt is permitted.',
            'reason_label' => 'Failure reason',
        ],
        'return_to_seller' => [
            'label' => 'Record Return to Seller',
            'confirmation' => 'Confirm the decision to return this parcel after three failed attempts and record the return reason. This does not confirm physical arrival at the shop or release inventory.',
            'reason_label' => 'Return reason',
        ],
        'confirm_return' => [
            'label' => 'Confirm Physical Return to Shop',
            'confirmation' => 'Confirm only after EVERY listed physical item has arrived at the shop. This records custody only, not inspection, refund, inventory release or sale completion. Missing or partially returned items require manual review.',
            'reason_label' => 'Physical return evidence',
        ],
        'inspect_return' => [
            'label' => 'Record Returned Item Checked',
            'confirmation' => 'Record this item\'s condition evidence for MANUAL_REVIEW. This immutable review blocks stock release; it is not a passed stock-condition inspection. No inventory, reservation or payment is changed.',
            'reason_label' => 'Inspection evidence',
        ],
        'delivered' => [
            'label' => 'Mark Delivered',
            'confirmation' => 'Record courier delivery only. The customer must still select Order Received. COD collection and GCash sale finalization remain separate actions in the existing Payment section.',
            'reason_label' => null,
        ],
    ];
}

function admin_return_stock_actions(): array
{
    return [
        'inspect_stock' => ['label' => 'Record Stock-Release Inspection', 'reason_label' => 'Condition inspection evidence',
            'confirmation' => 'Record this item\'s independent, immutable condition inspection. Passing does not release stock or resolve its reservation. MANUAL_REVIEW blocks stock release.'],
        'resolve_hold' => ['label' => 'Resolve Returned Item Reservation', 'reason_label' => 'Reason to abandon this reservation',
            'confirmation' => 'Explicitly and irreversibly abandon only this returned item\'s reservation. The garment remains unavailable until a separate stock release passes. Other items, order status, payment and shipping fees do not change.'],
        'make_available' => ['label' => 'Make Item Available Again', 'reason_label' => 'Stock disposition reason',
            'confirmation' => 'Release only this passed, inspected garment after its reservation was explicitly abandoned and every inventory/payment guard passes. This does not cancel the order, collect COD, refund money or record a sale.'],
    ];
}

/** Read-only advisory view. Eligibility uses the write path's full locked guards. */
function admin_delivery_view(PDO $pdo, int $adminId, array $order, $payment, array $shipment): array
{
    if ($pdo->inTransaction()) throw new LogicException('Delivery views must run outside a caller transaction.');
    $view = ['actions' => [], 'inspection_actions' => [], 'stock_actions' => [], 'stock_decisions' => [],
        'stock_events' => [], 'stock_records' => [], 'stock_schema' => null, 'items' => [], 'events' => [],
        'evidence' => [], 'inspections' => [], 'returns' => [], 'blockers' => [], 'error' => null];
    try {
        $orderId = (int) $order['id'];
        $stmt = $pdo->prepare('SELECT oi.*, p.id AS current_product_id, p.status AS product_status,
            p.barcode_value, p.retired_at FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ? ORDER BY oi.id');
        $stmt->execute([$orderId]);
        $view['items'] = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT e.*, a.name AS admin_name FROM delivery_events e
            LEFT JOIN admins a ON a.id = e.admin_id WHERE e.order_id = ? ORDER BY e.id ASC');
        $stmt->execute([$orderId]);
        $view['events'] = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT i.*, e.reason, e.created_at, e.admin_id, e.delivery_cycle AS event_cycle
            FROM delivery_event_items i JOIN delivery_events e ON e.id = i.event_id
            WHERE i.order_id = ? AND e.order_id = ? ORDER BY e.id, i.order_item_id');
        $stmt->execute([$orderId, $orderId]);
        foreach ($stmt->fetchAll() as $row) {
            $view['evidence'][(int) $row['event_id']][] = $row;
            if ((int) $row['delivery_cycle'] !== (int) $shipment['delivery_cycle']
                || (int) $row['event_cycle'] !== (int) $shipment['delivery_cycle']) continue;
            foreach ($view['items'] as $item) {
                if ((int) $item['id'] !== (int) $row['order_item_id']
                    || (int) $item['product_id'] !== (int) $row['product_id']) continue;
                if ($row['record_type'] === 'INSPECTED') $view['inspections'][(int) $item['id']] = $row;
                if ($row['record_type'] === 'RETURNED') $view['returns'][(int) $item['id']] = $row;
            }
        }
        $cycle = (int) $shipment['delivery_cycle'];
        $revision = (int) $shipment['delivery_revision'];
        $stage = (string) $shipment['shipment_status'];
        $view['stock_schema'] = return_stock_schema_cached_status($pdo);
        if ($view['stock_schema']['state'] === 'ready') {
            $stmt = $pdo->prepare('SELECT e.*, a.name AS admin_name FROM return_stock_events e
                LEFT JOIN admins a ON a.id = e.admin_id WHERE e.order_id = ? ORDER BY e.id');
            $stmt->execute([$orderId]);
            $view['stock_events'] = $stmt->fetchAll();
            foreach ($view['stock_events'] as $row) {
                if ((int) $row['delivery_cycle'] === $cycle) {
                    $view['stock_records'][(int) $row['order_item_id']][$row['event_kind']] = $row;
                }
            }
        }
        $attempt = (int) $shipment['failed_attempts'] + 1;
        foreach (admin_delivery_actions() as $action => $definition) {
            if ($action === 'inspect_return') continue;
            $decision = delivery_transition($order, $payment === false ? [] : $payment, $shipment, $action,
                $cycle, $revision, $stage, $action === 'fail_attempt' ? $attempt : null, 'Eligibility check only');
            if (!$decision['ok']) {
                $view['actions'][$action] = $decision;
                continue;
            }
            $view['actions'][$action] = delivery_action($pdo, $adminId, $orderId, $action,
                $cycle, $revision, $stage, bin2hex(random_bytes(16)),
                $action === 'fail_attempt' ? $attempt : null, 'Eligibility check only', validateOnly: true);
        }
        if ($stage === 'RETURNED_TO_SHOP') {
            foreach ($view['items'] as $item) {
                $view['inspection_actions'][(int) $item['id']] = delivery_action($pdo, $adminId, $orderId,
                    'inspect_return', $cycle, $revision, $stage, bin2hex(random_bytes(16)),
                    reason: 'Eligibility check only', orderItemId: (int) $item['id'],
                    inspectionOutcome: 'MANUAL_REVIEW', validateOnly: true);
                foreach (admin_return_stock_actions() as $stockAction => $definition) {
                    $view['stock_decisions'][(int) $item['id']][$stockAction] = return_stock_action($pdo,
                        $adminId, $orderId, $stockAction, $cycle, $revision, $stage, bin2hex(random_bytes(16)),
                        (int) $item['id'], 'Eligibility check only', $stockAction === 'inspect_stock' ? 'PASSED' : null, true);
                }
                $view['stock_actions'][(int) $item['id']] = $view['stock_decisions'][(int) $item['id']]['make_available'];
            }
        }
        // These are explanations, never permissions. Only the validated results above enable actions.
        if (!empty($order['has_completed_sale'])) $view['blockers'][] = 'A committed sale exists. Delivery and stock disposition cannot reopen a completed sale.';
        if ($payment === false) $view['blockers'][] = 'The payment record is missing. Physical return cannot resolve a missing payment.';
        elseif (($payment['payment_method'] === 'COD' && $payment['payment_status'] !== 'PENDING')
            || ($payment['payment_method'] === 'GCASH' && $payment['payment_status'] !== 'PAID')) {
            $view['blockers'][] = 'This shipped-return payment state is unsupported. COD must remain uncollected Pending; GCash must remain verified Paid. No refund or payment-resolution action is provided here.';
        }
        $productIds = [];
        foreach ($view['items'] as $item) {
            if ((int) $item['quantity'] !== 1 || empty($item['current_product_id'])
                || in_array((int) $item['product_id'], $productIds, true)) {
                $view['blockers'][] = 'Each physical item needs quantity one and a unique, existing product link. Snapshots cannot establish custody for missing, duplicate or multi-quantity identities.';
            }
            $productIds[] = (int) $item['product_id'];
            if ($item['current_product_id'] !== null && $item['product_status'] !== 'SOLD'
                && !isset($view['stock_records'][(int) $item['id']]['STOCK_RELEASED'])) {
                $view['blockers'][] = 'An item is not held as SOLD (unavailable). An AVAILABLE or otherwise incompatible item cannot be approved through this workflow.';
            }
            if ($item['retired_at'] !== null) $view['blockers'][] = 'A retired item may be recorded in physical custody only if the backend accepts it. Inspection and stock release remain blocked.';
        }
        $view['blockers'] = array_values(array_unique($view['blockers']));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $view['actions'] = $view['inspection_actions'] = $view['stock_actions'] = $view['stock_decisions'] = [];
        $view['error'] = 'Delivery records could not be safely loaded. Reload the order; no delivery actions are available from this view.';
        error_log('Admin delivery view failed for order ' . (int) $order['id'] . ': ' . get_class($e));
    }
    return $view;
}
