<?php

declare(strict_types=1);

require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/sales.php';
require_once __DIR__ . '/inventory_guard.php';
require_once __DIR__ . '/return_stock_schema.php';

function return_stock_failure(string $reason, int $status = 409): array
{
    $messages = [
        'admin_required' => 'An authorized administrator is required.',
        'bad_request' => 'The return-stock request is invalid.',
        'reason_required' => 'Enter a nonblank reason for this irreversible or inspection action.',
        'not_found' => 'The order, shipment or physical item could not be found.',
        'schema_unavailable' => 'Return-stock actions are unavailable: the complete additive ledger schema has not been verified.',
        'conflict' => 'The shipment cycle or revision changed. Reload and review the order.',
        'key_conflict' => 'This action identity was already used for a different request.',
        'already_recorded' => 'This immutable item/cycle action is already recorded. It cannot be replaced with another action identity.',
        'invalid_context' => 'Only an unreceived, uncancelled SHIPPED local-courier order with a valid current returned cycle is supported.',
        'financial_resolution_required' => 'Payment obligations are unresolved or unsupported. Paid/collected COD and GCash require a separate financial resolution; no refund or financial-resolution model exists. Inventory hold resolution is not financial resolution.',
        'physical_return_required' => 'Valid current-cycle item-level RETURNED_TO_SHOP custody and three failed-attempt records are required.',
        'legacy_item_review' => 'Manual review required: every order item must have a unique existing physical product identity and quantity one.',
        'inventory_state' => 'The physical item must still be SOLD and not retired or disposed of.',
        'committed_sale' => 'A committed sale exists for this order, product or item. Stock disposition is prohibited.',
        'other_hold' => 'Another unresolved order holds this garment. Stock disposition is prohibited.',
        'inspection_required' => 'A separate PASSED stock inspection for this physical item and current cycle is required.',
        'manual_review' => 'Immutable MANUAL_REVIEW inspection evidence blocks stock release. No stock was released.',
        'hold_resolution_required' => 'An explicit current-item inventory reservation abandonment must be recorded before stock release. This is not financial resolution.',
        'invalid_evidence' => 'The immutable return-stock or custody records require administrative review.',
        'error' => 'The return-stock action could not be saved. Reload before retrying.',
    ];
    $message = $messages[$reason] ?? $messages['invalid_evidence'];
    return ['ok' => false, 'reason' => $reason, 'status' => $status, 'message' => $message, 'blockers' => [$message]];
}

function return_stock_valid_timestamp($value): bool
{
    if (!is_string($value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    return $date !== false && $date->format('Y-m-d H:i:s') === $value && $value >= '1970-01-01 00:00:00';
}

/**
 * Own READ COMMITTED transaction. Locks admin -> order -> payment -> shipment ->
 * sale header -> action/header evidence -> order items -> products ascending ->
 * ledger/custody/sale-item evidence. Product locks serialize competing reservations.
 * HOLD_RESOLVED abandons only this item's reservation. Inspection, stock
 * disposition and financial resolution remain separate decisions.
 * validateOnly is INTERNAL: rollback before ANY DML, never an endpoint parameter.
 */
function return_stock_action(PDO $pdo, int $adminId, int $orderId, string $action, int $expectedCycle,
    int $expectedRevision, string $expectedStage, string $actionKey, int $orderItemId,
    string $reason = '', ?string $inspectionOutcome = null, bool $validateOnly = false): array
{
    $kinds = ['inspect_stock' => 'INSPECTION', 'resolve_hold' => 'HOLD_RESOLVED', 'make_available' => 'STOCK_RELEASED'];
    if (!isset($kinds[$action])) return return_stock_failure('bad_request', 422);
    if ($pdo->inTransaction()) throw new LogicException('Return-stock actions must own their transaction.');
    if ($adminId < 1) return return_stock_failure('admin_required', 403);
    $reason = preg_replace('/^\s+|\s+$/u', '', $reason);
    if ($orderId < 1 || $orderItemId < 1 || $expectedCycle < 1 || $expectedRevision < 1
        || $expectedStage !== 'RETURNED_TO_SHOP' || !sales_is_valid_request_key($actionKey)
        || $reason === null || strlen($reason) > 255
        || ($action === 'inspect_stock' ? !in_array($inspectionOutcome, ['PASSED','MANUAL_REVIEW'], true) : $inspectionOutcome !== null)) {
        return return_stock_failure('bad_request', 422);
    }
    if ($reason === '') return return_stock_failure('reason_required', 422);
    $schema = return_stock_schema_status($pdo);
    if ($schema['state'] !== 'ready') return return_stock_failure('schema_unavailable', 503);
    $hash = hash('sha256', json_encode([$adminId,$orderId,$action,$expectedCycle,$expectedRevision,
        $expectedStage,$orderItemId,$reason,$inspectionOutcome], JSON_THROW_ON_ERROR));
    $fail = static function (array $result) use ($pdo): array {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return $result;
    };
    $read = static function (string $sql, array $params) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $resultFor = static fn (array $event, bool $replay): array => [
        'ok' => true, 'status' => 200, 'idempotent' => $replay, 'event_id' => (int) $event['id'],
        'event_kind' => $event['event_kind'], 'outcome' => $event['outcome'],
        'state' => $event['resulting_inventory_state'], 'previous_inventory_state' => $event['previous_inventory_state'],
        'resulting_inventory_state' => $event['resulting_inventory_state'], 'cycle' => (int) $event['delivery_cycle'],
        'revision' => (int) $event['delivery_revision'], 'order_item_id' => (int) $event['order_item_id'],
        'product_id' => (int) $event['product_id'], 'physical_return_event_id' => (int) $event['physical_return_event_id'],
    ];
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        if ($read('SELECT id FROM admins WHERE id = ? LOCK IN SHARE MODE', [$adminId]) === []) return $fail(return_stock_failure('admin_required', 403));
        $order = payment_gcash_lock_order($pdo, $orderId, null);
        if ($order === false) return $fail(return_stock_failure('not_found', 404));
        $payment = payment_gcash_lock_payment($pdo, $orderId);
        $shipment = $read('SELECT * FROM shipments WHERE order_id = ? FOR UPDATE', [$orderId])[0] ?? null;
        if ($shipment === null) return $fail(return_stock_failure('not_found', 404));
        $hasSale = $read('SELECT id FROM sales_transactions WHERE order_id = ? FOR UPDATE', [$orderId]) !== [];
        $existing = $read('SELECT * FROM return_stock_events WHERE action_key = ? FOR UPDATE', [$actionKey]);
        if ($existing !== []) {
            $event = $existing[0];
            if (count($existing) !== 1 || (int) $event['order_id'] !== $orderId || !is_string($event['request_hash'])
                || !hash_equals($event['request_hash'], $hash)) return $fail(return_stock_failure('key_conflict'));
            $result = $resultFor($event, true);
            if ($validateOnly) { $pdo->rollBack(); return $result + ['validation_only' => true]; }
            $pdo->commit();
            return $result;
        }
        if ((int) ($shipment['delivery_cycle'] ?? 0) !== $expectedCycle || (int) ($shipment['delivery_revision'] ?? -1) !== $expectedRevision
            || ($shipment['shipment_status'] ?? '') !== $expectedStage) return $fail(return_stock_failure('conflict'));
        foreach (['received_at','picked_up_at','pickup_status'] as $field) {
            if (!array_key_exists($field, $order) || $order[$field] !== null) return $fail(return_stock_failure('invalid_context'));
        }
        if (($order['status'] ?? '') !== 'SHIPPED' || !in_array($order['cancellation_status'] ?? '', ['NONE','REJECTED'], true)
            || ($shipment['delivery_method'] ?? '') !== 'LOCAL_COURIER' || !array_key_exists('delivered_at', $shipment)
            || $shipment['delivered_at'] !== null || !return_stock_valid_timestamp($shipment['shipped_at'] ?? null)
            || !is_string($shipment['tracking_number'] ?? null) || trim($shipment['tracking_number']) === '') return $fail(return_stock_failure('invalid_context'));
        if ($payment === false || ($payment['payment_method'] ?? '') !== 'COD' || ($payment['payment_status'] ?? '') !== 'PENDING'
            || !array_key_exists('verified_by', $payment) || !array_key_exists('verified_at', $payment)
            || $payment['verified_by'] !== null || $payment['verified_at'] !== null) return $fail(return_stock_failure('financial_resolution_required'));
        if ($hasSale) return $fail(return_stock_failure('committed_sale'));
        $headers = $read('SELECT * FROM delivery_events WHERE order_id = ? ORDER BY id FOR UPDATE', [$orderId]);
        $last = $headers === [] ? null : $headers[count($headers) - 1];
        if ($last === null || (int) $last['delivery_cycle'] !== $expectedCycle || (int) $last['delivery_revision'] !== $expectedRevision
            || $last['resulting_state'] !== 'RETURNED_TO_SHOP') return $fail(return_stock_failure('physical_return_required'));
        $attempts = [];
        $returnedHeader = null;
        $returningHeader = null;
        $currentCycleEvidence = $expectedCycle === 1;
        $lastFailureRevision = 0;
        $lastFailureId = 0;
        foreach ($headers as $header) {
            if ((int) $header['delivery_cycle'] !== $expectedCycle) continue;
            if ((int) $header['admin_id'] < 1 || !return_stock_valid_timestamp($header['created_at'])
                || (int) $header['delivery_revision'] < 1 || (int) $header['delivery_revision'] > $expectedRevision) return $fail(return_stock_failure('physical_return_required'));
            if ($header['event_type'] === 'DELIVERY_CYCLE_AUTHORIZED') $currentCycleEvidence = true;
            if ($header['event_type'] === 'DELIVERY_ATTEMPT_FAILED') {
                if ($header['previous_state'] !== 'OUT_FOR_DELIVERY' || $header['resulting_state'] !== 'DELIVERY_ATTEMPT_FAILED'
                    || trim((string) $header['reason']) === '' || (int) $header['delivery_revision'] <= $lastFailureRevision) return $fail(return_stock_failure('physical_return_required'));
                $attempts[] = (int) $header['attempt_number'];
                $lastFailureRevision = (int) $header['delivery_revision'];
                $lastFailureId = (int) $header['id'];
            }
            if ($header['event_type'] === 'RETURN_TO_SELLER') {
                if ($returningHeader !== null || $header['previous_state'] !== 'DELIVERY_ATTEMPT_FAILED'
                    || $header['resulting_state'] !== 'RETURN_TO_SELLER' || trim((string) $header['reason']) === '') return $fail(return_stock_failure('physical_return_required'));
                $returningHeader = $header;
            }
            if ($header['event_type'] === 'RETURNED_TO_SHOP') {
                if ($returnedHeader !== null || $header['previous_state'] !== 'RETURN_TO_SELLER' || $header['resulting_state'] !== 'RETURNED_TO_SHOP'
                    || trim((string) $header['reason']) === '') return $fail(return_stock_failure('physical_return_required'));
                $returnedHeader = $header;
            }
        }
        if ((int) ($shipment['failed_attempts'] ?? -1) !== 3 || $attempts !== [1,2,3] || !$currentCycleEvidence || $returnedHeader === null || $returningHeader === null
            || (int) $returnedHeader['delivery_revision'] <= $lastFailureRevision
            || (int) $returningHeader['delivery_revision'] < $lastFailureRevision
            || (int) $returnedHeader['delivery_revision'] <= (int) $returningHeader['delivery_revision']
            || (int) $returningHeader['id'] <= $lastFailureId || (int) $returningHeader['id'] >= (int) $returnedHeader['id']
            || $returningHeader['created_at'] > $returnedHeader['created_at']
            || $returnedHeader['created_at'] < $shipment['shipped_at']) return $fail(return_stock_failure('physical_return_required'));
        $duplicate = $read('SELECT id FROM return_stock_events WHERE order_id = ? AND delivery_cycle = ? AND order_item_id = ? AND event_kind = ? FOR UPDATE',
            [$orderId,$expectedCycle,$orderItemId,$kinds[$action]]);
        if ($duplicate !== []) return $fail(return_stock_failure('already_recorded'));
        $items = $read('SELECT * FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE', [$orderId]);
        $ids = [];
        $selected = null;
        foreach ($items as $item) {
            if (filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT) !== 1
                || filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || in_array((int) $item['product_id'], $ids, true)) return $fail(return_stock_failure('legacy_item_review'));
            $ids[] = (int) $item['product_id'];
            if ((int) $item['id'] === $orderItemId) $selected = $item;
        }
        if ($selected === null) return $fail(return_stock_failure('legacy_item_review'));
        sort($ids);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $products = $read('SELECT id, status, retired_at FROM products WHERE id IN (' . $in . ') ORDER BY id FOR UPDATE', $ids);
        if (count($products) !== count($items)) return $fail(return_stock_failure('legacy_item_review'));
        $product = null;
        foreach ($products as $row) if ((int) $row['id'] === (int) $selected['product_id']) $product = $row;
        if ($product === null) return $fail(return_stock_failure('legacy_item_review'));
        if ($product['status'] !== 'SOLD' || !array_key_exists('retired_at', $product) || $product['retired_at'] !== null) return $fail(return_stock_failure('inventory_state'));
        $ledger = $read('SELECT * FROM return_stock_events WHERE order_id = ? AND delivery_cycle = ? ORDER BY id FOR UPDATE', [$orderId,$expectedCycle]);
        $custody = $read('SELECT i.*, e.event_type, e.delivery_revision AS event_revision, e.admin_id, e.reason, e.created_at,
            e.previous_state, e.resulting_state FROM delivery_event_items i JOIN delivery_events e
            ON e.id = i.event_id AND e.order_id = i.order_id AND e.delivery_cycle = i.delivery_cycle
            WHERE i.order_id = ? AND i.delivery_cycle = ? ORDER BY e.id, i.order_item_id FOR UPDATE', [$orderId,$expectedCycle]);
        $returnedItem = null;
        $oldManualReview = false;
        foreach ($custody as $row) {
            if ((int) $row['order_item_id'] !== $orderItemId) continue;
            if ((int) $row['product_id'] !== (int) $product['id'] || (int) $row['admin_id'] < 1
                || !return_stock_valid_timestamp($row['created_at']) || trim((string) $row['reason']) === ''
                || (int) $row['event_revision'] < 1 || (int) $row['event_revision'] > $expectedRevision) return $fail(return_stock_failure('invalid_evidence'));
            if ($row['record_type'] === 'RETURNED') {
                if ($returnedItem !== null || (int) $row['event_id'] !== (int) $returnedHeader['id'] || $row['outcome'] !== 'RETURNED'
                    || $row['event_type'] !== 'RETURNED_TO_SHOP' || $row['previous_state'] !== 'RETURN_TO_SELLER'
                    || $row['resulting_state'] !== 'RETURNED_TO_SHOP') return $fail(return_stock_failure('physical_return_required'));
                $returnedItem = $row;
            } elseif ($row['record_type'] === 'INSPECTED') {
                if ($row['event_type'] !== 'RETURN_INSPECTED' || !in_array($row['outcome'], ['RETRY_APPROVED','MANUAL_REVIEW'], true)
                    || $row['previous_state'] !== 'RETURNED_TO_SHOP' || $row['resulting_state'] !== 'RETURNED_TO_SHOP'
                    || (int) $row['event_revision'] <= (int) $returnedHeader['delivery_revision']) return $fail(return_stock_failure('invalid_evidence'));
                if ($row['outcome'] === 'MANUAL_REVIEW') $oldManualReview = true;
            } else return $fail(return_stock_failure('invalid_evidence'));
        }
        if ($returnedItem === null) return $fail(return_stock_failure('physical_return_required'));
        $inspection = $hold = null;
        foreach ($ledger as $row) {
            if ((int) $row['order_item_id'] !== $orderItemId) continue;
            if ((int) $row['product_id'] !== (int) $product['id'] || (int) $row['physical_return_event_id'] !== (int) $returnedHeader['id']
                || (int) $row['admin_id'] < 1 || !return_stock_valid_timestamp($row['created_at']) || trim((string) $row['reason']) === ''
                || $row['created_at'] < $returnedHeader['created_at'] || (int) $row['delivery_revision'] < (int) $returnedHeader['delivery_revision']
                || (int) $row['delivery_revision'] > $expectedRevision || $row['previous_inventory_state'] !== 'SOLD') return $fail(return_stock_failure('invalid_evidence'));
            if ($row['event_kind'] === 'INSPECTION' && in_array($row['outcome'], ['PASSED','MANUAL_REVIEW'], true) && $row['resulting_inventory_state'] === 'SOLD') $inspection = $row;
            elseif ($row['event_kind'] === 'HOLD_RESOLVED' && $row['outcome'] === 'RESOLVED' && $row['resulting_inventory_state'] === 'SOLD') $hold = $row;
            else return $fail(return_stock_failure('invalid_evidence'));
        }
        if ($read('SELECT id FROM sales_transaction_items WHERE product_id = ? OR order_item_id = ? FOR UPDATE', [$product['id'],$orderItemId]) !== []) return $fail(return_stock_failure('committed_sale'));
        if (inventory_has_other_hold_locked($pdo, (int) $product['id'], $orderId)) return $fail(return_stock_failure('other_hold'));
        if ($action === 'make_available') {
            if ($oldManualReview || ($inspection !== null && $inspection['outcome'] === 'MANUAL_REVIEW')) return $fail(return_stock_failure('manual_review'));
            if ($inspection === null || $inspection['outcome'] !== 'PASSED') return $fail(return_stock_failure('inspection_required'));
            if ($hold === null) return $fail(return_stock_failure('hold_resolution_required'));
        }
        $outcome = $action === 'inspect_stock' ? $inspectionOutcome : ($action === 'resolve_hold' ? 'RESOLVED' : 'AVAILABLE');
        $event = ['id' => 0, 'event_kind' => $kinds[$action], 'outcome' => $outcome, 'delivery_cycle' => $expectedCycle,
            'delivery_revision' => $expectedRevision, 'order_item_id' => $orderItemId, 'product_id' => (int) $product['id'],
            'physical_return_event_id' => (int) $returnedHeader['id'], 'previous_inventory_state' => 'SOLD',
            'resulting_inventory_state' => $action === 'make_available' ? 'AVAILABLE' : 'SOLD'];
        if ($validateOnly) { $pdo->rollBack(); return $resultFor($event, false) + ['validation_only' => true]; }
        if ($action === 'make_available') {
            $stmt = $pdo->prepare("UPDATE products SET status = 'AVAILABLE' WHERE id = ? AND status = 'SOLD' AND retired_at IS NULL");
            $stmt->execute([$product['id']]);
            if ($stmt->rowCount() !== 1) return $fail(return_stock_failure('conflict'));
        }
        $stmt = $pdo->prepare('INSERT INTO return_stock_events (order_id, delivery_cycle, delivery_revision, order_item_id,
            product_id, physical_return_event_id, event_kind, admin_id, reason, outcome, previous_inventory_state,
            resulting_inventory_state, action_key, request_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$orderId,$expectedCycle,$expectedRevision,$orderItemId,$product['id'],$returnedHeader['id'],
            $kinds[$action],$adminId,$reason,$outcome,'SOLD',$event['resulting_inventory_state'],$actionKey,$hash]);
        $event['id'] = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $resultFor($event, false);
    } catch (Throwable $error) {
        error_log('Return stock action failed for order ' . $orderId . ': ' . get_class($error));
        return $fail(return_stock_failure('error', 500));
    }
}
