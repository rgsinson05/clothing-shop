<?php

declare(strict_types=1);

require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/sales.php';
require_once __DIR__ . '/customer_notifications.php';
require_once __DIR__ . '/inventory_guard.php';

function delivery_action_names(): array
{
    return ['out_for_delivery', 'fail_attempt', 'return_to_seller', 'confirm_return', 'inspect_return', 'delivered', 'make_available'];
}

/** Read-only courier classification; these are not additional order stages. */
function delivery_phase_states(string $phase): array
{
    return match ($phase) {
        'SHIPPED' => ['SHIPPED'],
        'IN_TRANSIT' => ['IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED'],
        'RETURNS' => ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'],
        'RETURN_PENDING' => ['DELIVERY_ATTEMPT_FAILED'],
        default => throw new InvalidArgumentException('Unknown courier phase.'),
    };
}

function delivery_phase_predicate(string $phase): string
{
    $states = "s.shipment_status IN ('" . implode("','", delivery_phase_states($phase)) . "')";
    return $states . match ($phase) {
        'SHIPPED' => ' AND s.failed_attempts = 0',
        'IN_TRANSIT' => " AND s.failed_attempts BETWEEN 0 AND 2 AND (s.shipment_status <> 'DELIVERY_ATTEMPT_FAILED' OR s.failed_attempts >= 1)",
        'RETURNS', 'RETURN_PENDING' => ' AND s.failed_attempts = 3',
    };
}

function delivery_failure(string $reason, int $status = 409): array
{
    $messages = [
        'admin_required' => 'An authorized administrator is required.',
        'bad_request' => 'The delivery request is invalid.',
        'not_found' => 'The order or shipment could not be found.',
        'conflict' => 'The delivery state changed. Reload and review the order.',
        'key_conflict' => 'This action identity was already used for a different request.',
        'reason_required' => 'Enter a nonblank reason for this action.',
        'attempt_limit' => 'Three failed attempts have already been recorded for this delivery cycle.',
        'attempt_sequence' => 'Delivery attempts must be recorded in sequence.',
        'invalid_state' => 'This action is not permitted in the current delivery state.',
        'manual_review' => 'The order, payment or item records require administrative review. No inventory was released.',
        'legacy_item_review' => 'Manual review required: each physical item must have a unique current product identity and quantity one. Historical snapshots cannot establish custody. No records were changed; an explicitly approved resolution workflow is required.',
        'inspection_required' => 'Current-cycle item-level physical-return evidence is required before inspection.',
        'error' => 'The delivery action could not be saved. Reload the order before retrying.',
    ];
    return ['ok' => false, 'reason' => $reason, 'message' => $messages[$reason] ?? $messages['manual_review'], 'status' => $status];
}

/** Pure decision; callers must provide fresh, locked persisted rows. */
function delivery_transition(array $order, array $payment, array $shipment, string $action,
    int $cycle, int $revision, string $stage, ?int $attempt, string $reason): array
{
    if (!in_array($action, delivery_action_names(), true)) return delivery_failure('bad_request', 422);
    foreach (['received_at', 'picked_up_at', 'pickup_status'] as $field) {
        if (!array_key_exists($field, $order) || $order[$field] !== null) {
            return delivery_failure('manual_review');
        }
    }
    if (($order['status'] ?? '') !== 'SHIPPED'
        || !in_array($order['cancellation_status'] ?? '', ['NONE', 'REJECTED'], true)
        || ($shipment['delivery_method'] ?? '') !== 'LOCAL_COURIER'
        || !array_key_exists('delivered_at', $shipment) || $shipment['delivered_at'] !== null
        || !is_string($shipment['shipped_at'] ?? null)
        || !delivery_valid_timestamp($shipment['shipped_at'])
        || !is_string($shipment['tracking_number'] ?? null) || trim($shipment['tracking_number']) === '') {
        return delivery_failure('manual_review');
    }
    foreach (['delivery_cycle', 'delivery_revision', 'failed_attempts'] as $field) {
        if (filter_var($shipment[$field] ?? null, FILTER_VALIDATE_INT) === false
            || !isset($shipment[$field]) || (int) $shipment[$field] < 0) {
            return delivery_failure('manual_review');
        }
    }
    $failures = (int) $shipment['failed_attempts'];
    if ((int) $shipment['delivery_cycle'] < 1 || $failures > 3) {
        return delivery_failure('manual_review');
    }
    if ((int) $shipment['delivery_cycle'] !== $cycle || (int) $shipment['delivery_revision'] !== $revision
        || ($shipment['shipment_status'] ?? '') !== $stage) {
        return delivery_failure('conflict');
    }
    // SHIPPED courier returns have no separate refund/rejection resolution in
    // this lifecycle. Custody cannot legitimize an unsupported financial state.
    if (!array_key_exists('verified_by', $payment) || !array_key_exists('verified_at', $payment)
        || payment_gcash_fulfillment_reason($payment) !== null) {
        return delivery_failure('manual_review');
    }
    if (($payment['payment_method'] ?? '') === 'COD') {
        if (($payment['payment_status'] ?? '') !== 'PENDING'
            || $payment['verified_by'] !== null || $payment['verified_at'] !== null) {
            return delivery_failure('manual_review');
        }
    } elseif (($payment['payment_method'] ?? '') === 'GCASH') {
        if (($payment['payment_status'] ?? '') !== 'PAID'
            || filter_var($payment['verified_by'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !delivery_valid_timestamp($payment['verified_at'])
            || !delivery_valid_timestamp($payment['submitted_at'] ?? null)
            || !is_string($payment['gcash_reference'] ?? null)
            || !payment_is_valid_reference($payment['gcash_reference'])
            || !array_key_exists('rejection_reason', $payment) || $payment['rejection_reason'] !== null
            || !array_key_exists('rejected_at', $payment) || $payment['rejected_at'] !== null) {
            return delivery_failure('manual_review');
        }
    } else {
        return delivery_failure('manual_review');
    }
    if (in_array($action, ['fail_attempt', 'return_to_seller', 'confirm_return', 'inspect_return'], true) && $reason === '') {
        return delivery_failure('reason_required', 422);
    }
    $next = null;
    switch ($action) {
        case 'out_for_delivery':
            if ($failures >= 3) return delivery_failure('attempt_limit');
            if (($failures === 0 && in_array($stage, ['SHIPPED', 'IN_TRANSIT'], true))
                || ($failures > 0 && $stage === 'DELIVERY_ATTEMPT_FAILED')) {
                $next = 'OUT_FOR_DELIVERY';
            }
            break;
        case 'fail_attempt':
            if ($failures >= 3) return delivery_failure('attempt_limit');
            if ($attempt !== $failures + 1) return delivery_failure('attempt_sequence');
            if ($stage === 'OUT_FOR_DELIVERY') {
                $failures++;
                $next = 'DELIVERY_ATTEMPT_FAILED';
            }
            break;
        case 'return_to_seller':
            if ($stage === 'DELIVERY_ATTEMPT_FAILED' && $failures === 3) $next = 'RETURN_TO_SELLER';
            break;
        case 'confirm_return':
            if ($stage === 'RETURN_TO_SELLER' && $failures === 3) $next = 'RETURNED_TO_SHOP';
            break;
        case 'inspect_return':
            if ($stage === 'RETURNED_TO_SHOP' && $failures === 3) $next = $stage;
            break;
        case 'delivered':
            if ($stage === 'OUT_FOR_DELIVERY' && $failures < 3) $next = 'DELIVERED';
            break;
    }
    if ($next === null) return delivery_failure('invalid_state');
    return ['ok' => true, 'state' => $next, 'cycle' => $cycle, 'revision' => $revision + 1, 'failed_attempts' => $failures];
}

function delivery_valid_timestamp($value): bool
{
    if (!is_string($value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    return $date !== false && $date->format('Y-m-d H:i:s') === $value && $value >= '1970-01-01 00:00:00';
}

/**
 * Self-owned transaction, matching the payment finalizers. Lock order:
 * admin attribution -> order -> payment -> shipment -> sale header -> action/events -> order items
 * -> products ascending -> item evidence/verification/sale membership.
 * READ COMMITTED makes non-locking other-order membership checks fresh without
 * taking inverse product -> order locks. Product reservations lock products.
 * No financial or inventory writer is called. Tokens are mandatory.
 * validateOnly is an internal, read-only admin eligibility check. It executes
 * the same locked guards and rolls back BEFORE every write. The HTTP endpoint
 * never accepts or forwards this flag; presentation cannot authorize a write.
 */
function delivery_action(PDO $pdo, int $adminId, int $orderId, string $action, int $expectedCycle,
    int $expectedRevision, string $expectedStage, string $actionKey, ?int $attempt = null,
    string $reason = '', ?int $orderItemId = null, ?string $inspectionOutcome = null,
    bool $validateOnly = false): array
{
    if ($pdo->inTransaction()) throw new LogicException('Delivery actions must own their transaction.');
    if ($adminId < 1) return delivery_failure('admin_required', 403);
    $reason = preg_replace('/^\s+|\s+$/u', '', $reason);
    if ($orderId < 1 || $expectedCycle < 1 || $expectedRevision < 0 || !in_array($action, delivery_action_names(), true)
        || !in_array($expectedStage, ['SHIPPED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED',
            'RETURN_TO_SELLER', 'RETURNED_TO_SHOP', 'DELIVERED'], true)
        || !sales_is_valid_request_key($actionKey) || $reason === null || strlen($reason) > 255
        || ($action !== 'fail_attempt' && $attempt !== null)
        || (!in_array($action, ['inspect_return', 'make_available'], true) && ($orderItemId !== null || $inspectionOutcome !== null))
        || ($action === 'make_available' && ($orderItemId === null || $orderItemId < 1 || $inspectionOutcome !== null))
        || ($action === 'inspect_return' && ($orderItemId === null || $orderItemId < 1
            || $inspectionOutcome !== 'MANUAL_REVIEW'))) {
        return delivery_failure('bad_request', 422);
    }
    if ($action === 'make_available' && $reason === '') return delivery_failure('reason_required', 422);
    if ($action === 'make_available' && return_stock_schema_cached_status($pdo)['state'] === 'ready') {
        require_once __DIR__ . '/return_stock.php';
        // The additive ledger owns the only stock writer. Until installed, the
        // original guarded preview below continues to explain legacy blockers.
        return return_stock_action($pdo, $adminId, $orderId, $action, $expectedCycle, $expectedRevision,
            $expectedStage, $actionKey, $orderItemId, $reason, null, $validateOnly);
    }
    $hash = hash('sha256', json_encode([$adminId, $orderId, $action, $expectedCycle, $expectedRevision,
        $expectedStage, $attempt, $reason, $orderItemId, $inspectionOutcome], JSON_THROW_ON_ERROR));
    $fail = static function (array $result) use ($pdo): array {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return $result;
    };
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id FROM admins WHERE id = ? LOCK IN SHARE MODE');
        $stmt->execute([$adminId]);
        if ($stmt->fetchColumn() === false) return $fail(delivery_failure('admin_required', 403));
        $order = payment_gcash_lock_order($pdo, $orderId, null);
        if ($order === false) return $fail(delivery_failure('not_found', 404));
        $payment = payment_gcash_lock_payment($pdo, $orderId);
        $stmt = $pdo->prepare('SELECT * FROM shipments WHERE order_id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $shipment = $stmt->fetch();
        if ($shipment === false) return $fail(delivery_failure('not_found', 404));
        $stmt = $pdo->prepare('SELECT id FROM sales_transactions WHERE order_id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $hasSale = $stmt->fetchColumn() !== false;
        // Read committed action evidence before checking old tokens. Same payload
        // returns the original result, even after later actions; never re-applies it.
        $stmt = $pdo->prepare('SELECT * FROM delivery_events WHERE action_key = ? ORDER BY event_index DESC FOR UPDATE');
        $stmt->execute([$actionKey]);
        $existing = $stmt->fetchAll();
        if ($existing !== [] && !$validateOnly) {
            foreach ($existing as $event) {
                if ((int) $event['order_id'] !== $orderId || !hash_equals($event['request_hash'], $hash)) {
                    return $fail(delivery_failure('key_conflict'));
                }
            }
            $pdo->commit();
            return ['ok' => true, 'idempotent' => true, 'event_id' => (int) $existing[0]['id'],
                'state' => $existing[0]['resulting_state'], 'cycle' => (int) $existing[0]['delivery_cycle'],
                'revision' => (int) $existing[0]['delivery_revision'], 'status' => 200];
        }
        if ($action === 'make_available') {
            if ((int) $shipment['delivery_cycle'] !== $expectedCycle
                || (int) $shipment['delivery_revision'] !== $expectedRevision
                || $shipment['shipment_status'] !== $expectedStage) return $fail(delivery_failure('conflict'));
            return $fail(delivery_stock_release_check_locked($pdo, $order, $payment === false ? [] : $payment,
                $shipment, $orderItemId, $hasSale));
        }
        $custody = $action === 'confirm_return';
        if ($payment === false || $hasSale) return $fail(delivery_failure('manual_review'));
        $decision = delivery_transition($order, $payment, $shipment, $action, $expectedCycle,
            $expectedRevision, $expectedStage, $attempt, $reason);
        if (!$decision['ok']) return $fail($decision);
        $stmt = $pdo->prepare('SELECT delivery_cycle, delivery_revision, resulting_state FROM delivery_events
            WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$orderId]);
        $lastEvent = $stmt->fetch();
        if (($expectedRevision > 0 && ($lastEvent === false
            || (int) $lastEvent['delivery_cycle'] !== $expectedCycle
            || (int) $lastEvent['delivery_revision'] !== $expectedRevision
            || $lastEvent['resulting_state'] !== $expectedStage))
            || ($expectedRevision === 0 && $lastEvent !== false)) {
            return $fail(delivery_failure('manual_review'));
        }
        if ($payment['payment_method'] === 'GCASH') {
            $stmt = $pdo->prepare('SELECT id FROM admins WHERE id = ? LOCK IN SHARE MODE');
            $stmt->execute([$payment['verified_by']]);
            if ($stmt->fetchColumn() === false) return $fail(delivery_failure('manual_review'));
        }
        // Every attempt must agree with append-only evidence, not just a counter.
        $stmt = $pdo->prepare("SELECT attempt_number FROM delivery_events
            WHERE order_id = ? AND delivery_cycle = ? AND event_type = 'DELIVERY_ATTEMPT_FAILED'
            ORDER BY attempt_number FOR UPDATE");
        $stmt->execute([$orderId, $expectedCycle]);
        $attempts = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $count = (int) $shipment['failed_attempts'];
        if ($attempts !== ($count === 0 ? [] : range(1, $count))) return $fail(delivery_failure('manual_review'));
        if ($expectedCycle > 1) {
            // Historical cycles remain valid evidence; no new cycle is created.
            $stmt = $pdo->prepare("SELECT id FROM delivery_events WHERE order_id = ? AND delivery_cycle = ?
                AND event_type = 'DELIVERY_CYCLE_AUTHORIZED' FOR UPDATE");
            $stmt->execute([$orderId, $expectedCycle]);
            if ($stmt->fetchColumn() === false) return $fail(delivery_failure('manual_review'));
        }
        $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
        if ($items === []) return $fail(delivery_failure('manual_review'));
        $productIds = [];
        foreach ($items as $item) {
            // No persisted per-unit mapping exists for legacy quantity rows.
            // Names/sizes/prices are review snapshots, never physical identity.
            if (filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT) !== 1
                || filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || in_array((int) $item['product_id'], $productIds, true)) return $fail(delivery_failure('legacy_item_review'));
            $productIds[] = (int) $item['product_id'];
        }
        sort($productIds);
        $in = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $pdo->prepare('SELECT id, status, retired_at, barcode_value FROM products WHERE id IN (' . $in . ') ORDER BY id FOR UPDATE');
        $stmt->execute($productIds);
        $products = $stmt->fetchAll();
        if (count($products) !== count($items)) return $fail(delivery_failure('legacy_item_review'));
        foreach ($products as $product) {
            if ($product['status'] !== 'SOLD' || (!$custody && $product['retired_at'] !== null)) return $fail(delivery_failure('manual_review'));
        }
        $stmt = $pdo->prepare('SELECT id FROM sales_transaction_items WHERE product_id IN (' . $in . ') FOR UPDATE');
        $stmt->execute($productIds);
        if ($stmt->fetchColumn() !== false) {
            return $fail(delivery_failure('manual_review'));
        }
        // Separate item-key lookup avoids the existing diagnostic helper's
        // double alias of the sale-item table (also testable in temporary tables).
        foreach ($items as $item) {
            $stmt = $pdo->prepare('SELECT id FROM sales_transaction_items WHERE order_item_id = ? FOR UPDATE');
            $stmt->execute([$item['id']]);
            if ($stmt->fetchColumn() !== false
                || inventory_has_other_hold_locked($pdo, (int) $item['product_id'], $orderId)) {
                return $fail(delivery_failure('manual_review'));
            }
        }
        $stmt = $pdo->prepare('SELECT * FROM order_item_verifications WHERE order_id = ? ORDER BY order_item_id FOR UPDATE');
        $stmt->execute([$orderId]);
        $verified = [];
        foreach ($custody ? [] : $stmt->fetchAll() as $row) {
            if ((int) $row['verified_by'] < 1 || !delivery_valid_timestamp($row['verified_at'])
                || !in_array($row['method'], ['SCANNED', 'MANUAL'], true)
                || ($row['method'] === 'MANUAL' && trim((string) $row['reason']) === '')) {
                return $fail(delivery_failure('manual_review'));
            }
            $verified[(int) $row['order_item_id']] = $row;
        }
        foreach ($custody ? [] : $items as $item) {
            $v = $verified[(int) $item['id']] ?? null;
            if ($v === null || (int) $v['product_id'] !== (int) $item['product_id']) return $fail(delivery_failure('manual_review'));
            if ($v['method'] === 'SCANNED') {
                $product = array_values(array_filter($products, static fn ($p) => (int) $p['id'] === (int) $item['product_id']))[0];
                if (!is_string($v['barcode_value']) || !sales_is_plausible_barcode($v['barcode_value'])
                    || $v['barcode_value'] !== $product['barcode_value'] || $v['reason'] !== null) {
                    return $fail(delivery_failure('manual_review'));
                }
            }
        }
        $stmt = $pdo->prepare('SELECT i.*, e.order_id AS event_order_id, e.delivery_cycle AS event_cycle, e.event_type,
            e.delivery_revision AS event_revision, e.previous_state, e.resulting_state, e.created_at
            FROM delivery_event_items i INNER JOIN delivery_events e ON e.id = i.event_id
            WHERE i.order_id = ? AND i.delivery_cycle = ? FOR UPDATE');
        $stmt->execute([$orderId, $expectedCycle]);
        $evidence = $stmt->fetchAll();
        foreach ($evidence as $row) {
            $matchingItems = array_filter($items, static fn ($item) => (int) $item['id'] === (int) $row['order_item_id']
                && (int) $item['product_id'] === (int) $row['product_id']);
            if ((int) $row['event_order_id'] !== $orderId || (int) $row['event_cycle'] !== $expectedCycle
                || count($matchingItems) !== 1 || !in_array($row['record_type'], ['RETURNED', 'INSPECTED'], true)
                || (int) $row['event_revision'] < 1 || (int) $row['event_revision'] > $expectedRevision
                || !delivery_valid_timestamp($row['created_at'])
                || ($row['record_type'] === 'RETURNED' && $row['outcome'] !== 'RETURNED')
                || ($row['record_type'] === 'INSPECTED' && !in_array($row['outcome'], ['RETRY_APPROVED', 'MANUAL_REVIEW'], true))
                || ($row['record_type'] === 'RETURNED' && ($row['event_type'] !== 'RETURNED_TO_SHOP'
                    || $row['previous_state'] !== 'RETURN_TO_SELLER' || $row['resulting_state'] !== 'RETURNED_TO_SHOP'))
                || ($row['record_type'] === 'INSPECTED' && ($row['event_type'] !== 'RETURN_INSPECTED'
                    || $row['previous_state'] !== 'RETURNED_TO_SHOP' || $row['resulting_state'] !== 'RETURNED_TO_SHOP'))) {
                return $fail(delivery_failure('manual_review'));
            }
        }
        if ($action === 'inspect_return') {
            foreach ($items as $item) {
                $returnedRevision = null;
                foreach ($evidence as $row) {
                    if ((int) $row['order_item_id'] === (int) $item['id'] && (int) $row['product_id'] === (int) $item['product_id']) {
                        if ($row['record_type'] === 'RETURNED') $returnedRevision = (int) $row['event_revision'];
                    }
                }
                if ($returnedRevision === null) return $fail(delivery_failure('inspection_required'));
            }
        }
        if ($action === 'inspect_return') {
            $selected = array_values(array_filter($items, static fn ($item) => (int) $item['id'] === $orderItemId));
            if (count($selected) !== 1) return $fail(delivery_failure('bad_request', 422));
            foreach ($evidence as $row) {
                if ((int) $row['order_item_id'] === $orderItemId && $row['record_type'] === 'INSPECTED') {
                    return $fail(delivery_failure('invalid_state'));
                }
            }
        }
        if ($validateOnly) {
            $pdo->rollBack();
            return $decision + ['status' => 200];
        }
        $stmt = $pdo->prepare('UPDATE shipments SET shipment_status = ?, delivery_revision = ?, failed_attempts = ?,
            delivered_at = CASE WHEN ? = \'DELIVERED\' THEN COALESCE(delivered_at, NOW()) ELSE delivered_at END
            WHERE order_id = ? AND delivery_cycle = ? AND delivery_revision = ? AND shipment_status = ?');
        $stmt->execute([$decision['state'], $decision['revision'], $decision['failed_attempts'],
            $decision['state'], $orderId, $expectedCycle, $expectedRevision, $expectedStage]);
        if ($stmt->rowCount() !== 1) return $fail(delivery_failure('conflict'));
        if ($action === 'delivered') {
            $stmt = $pdo->prepare("UPDATE orders SET status = 'DELIVERED' WHERE id = ? AND status = 'SHIPPED'");
            $stmt->execute([$orderId]);
            if ($stmt->rowCount() !== 1) return $fail(delivery_failure('conflict'));
        }
        $types = ['out_for_delivery' => 'OUT_FOR_DELIVERY', 'fail_attempt' => 'DELIVERY_ATTEMPT_FAILED',
            'return_to_seller' => 'RETURN_TO_SELLER',
            'confirm_return' => 'RETURNED_TO_SHOP', 'inspect_return' => 'RETURN_INSPECTED',
            'delivered' => 'DELIVERED'];
        $events = [[$types[$action], $expectedStage, $decision['state']]];
        foreach ($events as $index => [$type, $previous, $resulting]) {
            $stmt = $pdo->prepare('INSERT INTO delivery_events (order_id, delivery_cycle, delivery_revision,
                event_type, admin_id, attempt_number, reason, previous_state, resulting_state, action_key, event_index, request_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$orderId, $decision['cycle'], $decision['revision'], $type, $adminId,
                $type === 'DELIVERY_ATTEMPT_FAILED' ? $attempt : null, $reason === '' ? null : $reason,
                $previous, $resulting, $actionKey, $index, $hash]);
            $eventId = (int) $pdo->lastInsertId();
            if (in_array($action, ['confirm_return', 'inspect_return'], true)) {
                foreach ($action === 'confirm_return' ? $items : $selected as $item) {
                    $stmt = $pdo->prepare('INSERT INTO delivery_event_items (event_id, order_id, delivery_cycle,
                        order_item_id, product_id, record_type, outcome) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$eventId, $orderId, $expectedCycle, $item['id'], $item['product_id'],
                        $action === 'confirm_return' ? 'RETURNED' : 'INSPECTED',
                        $action === 'confirm_return' ? 'RETURNED' : $inspectionOutcome]);
                }
            }
            if ($type !== 'RETURN_INSPECTED') {
                customer_notifications_create($pdo, $orderId, $type === 'DELIVERED' ? 'ORDER_DELIVERED' : $type,
                    null, 'delivery-event-' . $eventId);
            }
        }
        $pdo->commit();
        return $decision + ['idempotent' => false, 'event_id' => $eventId, 'status' => 200];
    } catch (Throwable $e) {
        error_log('Delivery action failed for order ' . $orderId . ': ' . get_class($e));
        return $fail(delivery_failure('error', 500));
    }
}

/**
 * Fail-closed disposition preview and POST guard. The existing schema records
 * custody and historical inspection decisions, not a release inspection, resolved return hold,
 * or inventory disposition ledger. Never repurpose immutable courier evidence
 * or exclude the originating SHIPPED hold to manufacture release eligibility.
 * Caller owns admin -> order -> payment -> shipment -> sale locks.
 */
function delivery_stock_release_check_locked(PDO $pdo, array $order, array $payment,
    array $shipment, int $orderItemId, bool $hasSale): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Stock disposition checks require the order transaction.');
    $blockers = [];
    if (($shipment['delivery_method'] ?? '') !== 'LOCAL_COURIER'
        || ($shipment['shipment_status'] ?? '') !== 'RETURNED_TO_SHOP'
        || !empty($shipment['delivered_at'])) {
        $blockers[] = 'Physical return must be explicitly confirmed as Returned to Shop in the current courier cycle.';
    }
    $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE');
    $stmt->execute([$order['id']]);
    $items = $stmt->fetchAll();
    $selected = array_values(array_filter($items, static fn ($item) => (int) $item['id'] === $orderItemId));
    if (count($selected) !== 1 || (int) $selected[0]['quantity'] !== 1 || (int) $selected[0]['product_id'] < 1) {
        return delivery_failure('legacy_item_review');
    }
    $item = $selected[0];
    if (count(array_filter($items, static fn ($row) => $row['product_id'] === $item['product_id'])) !== 1) {
        return delivery_failure('legacy_item_review');
    }
    $stmt = $pdo->prepare('SELECT id, status, retired_at FROM products WHERE id = ? FOR UPDATE');
    $stmt->execute([$item['product_id']]);
    $product = $stmt->fetch();
    if ($product === false) return delivery_failure('legacy_item_review');
    if ($product['status'] !== 'SOLD') $blockers[] = 'The item is not in the expected unavailable SOLD hold state. Availability alone is not proof of an audited release.';
    if ($product['retired_at'] !== null) $blockers[] = 'The item is retired or disposed of and cannot return to available stock.';
    $stmt = $pdo->prepare('SELECT id FROM sales_transaction_items WHERE product_id = ? OR order_item_id = ? FOR UPDATE');
    $stmt->execute([$item['product_id'], $orderItemId]);
    if ($hasSale || $stmt->fetchColumn() !== false) $blockers[] = 'A committed sale exists for this order or garment. Stock release is prohibited.';
    if (inventory_has_other_hold_locked($pdo, (int) $item['product_id'], (int) $order['id'])) {
        $blockers[] = 'Another unresolved order holds this garment. Stock release is prohibited.';
    }
    $stmt = $pdo->prepare('SELECT i.*, e.event_type, e.reason, e.admin_id, e.created_at,
        e.delivery_revision, e.previous_state, e.resulting_state FROM delivery_event_items i
        JOIN delivery_events e ON e.id = i.event_id AND e.order_id = i.order_id AND e.delivery_cycle = i.delivery_cycle
        WHERE i.order_id = ? AND i.delivery_cycle = ? AND i.order_item_id = ? AND i.product_id = ?
        ORDER BY e.id FOR UPDATE');
    $stmt->execute([$order['id'], $shipment['delivery_cycle'], $orderItemId, $item['product_id']]);
    $returned = null;
    $inspected = null;
    foreach ($stmt->fetchAll() as $row) {
        if ((int) $row['admin_id'] < 1 || !delivery_valid_timestamp($row['created_at'])
            || (int) $row['delivery_revision'] < 1
            || (int) $row['delivery_revision'] > (int) $shipment['delivery_revision']
            || $row['resulting_state'] !== 'RETURNED_TO_SHOP' || trim((string) $row['reason']) === '') continue;
        if ($row['record_type'] === 'RETURNED' && $row['event_type'] === 'RETURNED_TO_SHOP'
            && $row['previous_state'] === 'RETURN_TO_SELLER' && $row['outcome'] === 'RETURNED') $returned = $row;
        if ($row['record_type'] === 'INSPECTED' && $row['event_type'] === 'RETURN_INSPECTED'
            && $row['previous_state'] === 'RETURNED_TO_SHOP'
            && in_array($row['outcome'], ['RETRY_APPROVED', 'MANUAL_REVIEW'], true)) $inspected = $row;
    }
    if ($returned === null) $blockers[] = 'Current-cycle item-level physical return evidence is missing or invalid.';
    if ($inspected === null || $returned === null
        || (int) $inspected['delivery_revision'] <= (int) $returned['delivery_revision']) {
        $blockers[] = 'Valid item-level inspection with recorded evidence after physical return is required.';
    } elseif ($inspected['outcome'] === 'MANUAL_REVIEW') {
        $blockers[] = 'This item is under MANUAL_REVIEW. Unresolved inspection issues block stock release.';
    }
    if (($order['status'] ?? '') !== 'CANCELLED' || ($order['cancellation_status'] ?? '') !== 'APPROVED'
        || !empty($order['received_at']) || !empty($order['picked_up_at'])) {
        $blockers[] = 'The originating returned order still has an unresolved inventory hold/order obligation. Physical return and inspection do not resolve it; no returned-order hold-resolution workflow exists.';
    }
    if (!in_array($payment['payment_method'] ?? '', ['COD', 'GCASH'], true)
        || !in_array($payment['payment_status'] ?? '', ['PENDING', 'FAILED'], true)
        || !array_key_exists('verified_at', $payment) || $payment['verified_at'] !== null
        || !array_key_exists('verified_by', $payment) || $payment['verified_by'] !== null) {
        $blockers[] = 'Payment obligations are unresolved or unsupported. Paid/collected GCash or COD cannot be released without an independently recorded financial resolution.';
    }
    $blockers[] = 'The legacy inspection schema cannot record an independent passed stock-release inspection or append-only inventory disposition with previous/new stock state. The additive inspection, reservation-resolution and stock-disposition ledger must be installed and verified.';
    return ['ok' => false, 'reason' => 'stock_release_blocked', 'status' => 409,
        'message' => implode(' ', $blockers), 'blockers' => $blockers];
}
