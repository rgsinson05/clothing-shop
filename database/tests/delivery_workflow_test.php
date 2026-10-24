<?php

declare(strict_types=1);

// Default: DB-free decisions. --isolated-temporary: a NEW connection shadows ALL
// accessed application tables with empty InnoDB TEMPORARY tables before any DML.
// No existing record is read/mutated, no persistent DDL/migration or cleanup DELETE.
// Temporary tables disappear on disconnect, including errors. Not a concurrency,
// foreign-key, trigger, HTTP, migration, or browser test. Never run via the web.
if (PHP_SAPI !== 'cli') exit(1);
if ($argc > 2 || ($argc === 2 && $argv[1] !== '--isolated-temporary')) exit(2);
require_once __DIR__ . '/../../includes/delivery.php';
require_once __DIR__ . '/../../includes/inventory_guard.php';
require_once __DIR__ . '/../../includes/admin_delivery.php';
$checks = 0;
$failures = 0;
function check(string $name, $actual, $expected): void
{
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo 'FAIL ' . $name . ': ' . var_export($actual, true) . ' != ' . var_export($expected, true) . PHP_EOL;
    } else echo 'ok ' . $name . PHP_EOL;
}
$order = ['status' => 'SHIPPED', 'cancellation_status' => 'NONE', 'received_at' => null, 'picked_up_at' => null, 'pickup_status' => null];
$payment = ['payment_method' => 'COD', 'payment_status' => 'PENDING', 'verified_by' => null, 'verified_at' => null];
$shipment = ['delivery_method' => 'LOCAL_COURIER', 'shipment_status' => 'SHIPPED', 'shipped_at' => '2026-10-22 10:00:00',
    'tracking_number' => 'TEST-ONLY', 'delivered_at' => null, 'delivery_cycle' => 1, 'delivery_revision' => 0, 'failed_attempts' => 0];
$decide = static fn ($action, $s, $attempt = null, $reason = '') => delivery_transition($order, $payment, $s, $action,
    (int) $s['delivery_cycle'], (int) $s['delivery_revision'], $s['shipment_status'], $attempt, $reason);
check('valid out for delivery', $decide('out_for_delivery', $shipment)['state'], 'OUT_FOR_DELIVERY');
for ($i = 1; $i <= 3; $i++) {
    $s = array_replace($shipment, ['shipment_status' => 'OUT_FOR_DELIVERY', 'failed_attempts' => $i - 1]);
    $r = $decide('fail_attempt', $s, $i, 'Customer unavailable');
    check('failed attempt ' . $i, [$r['ok'], $r['failed_attempts'], $r['state']],
        [true, $i, 'DELIVERY_ATTEMPT_FAILED']);
}
$exhausted = array_replace($shipment, ['shipment_status' => 'DELIVERY_ATTEMPT_FAILED', 'failed_attempts' => 3]);
check('third failure requires explicit return decision', $decide('return_to_seller', $exhausted, null,
    'Three attempts exhausted; return parcel to seller')['state'], 'RETURN_TO_SELLER');
check('return decision requires a reason', $decide('return_to_seller', $exhausted)['reason'], 'reason_required');
check('third failure is not physical return', $decide('confirm_return', $exhausted, null, 'Parcel arrived')['reason'], 'invalid_state');
check('exhausted cycle cannot have a fourth outing', $decide('out_for_delivery', $exhausted)['reason'], 'attempt_limit');
check('exhausted cycle cannot record failure four', $decide('fail_attempt', $exhausted, 4, 'Reason')['reason'], 'attempt_limit');
foreach ([0, 1, 2] as $failed) {
    check('return decision requires three failures, not ' . $failed, $decide('return_to_seller',
        array_replace($exhausted, ['failed_attempts' => $failed]), null, 'Return reason')['reason'], 'invalid_state');
}
foreach (['SHIPPED', 'OUT_FOR_DELIVERY', 'RETURN_TO_SELLER', 'RETURNED_TO_SHOP', 'DELIVERED'] as $stage) {
    check('return decision rejects predecessor ' . $stage, $decide('return_to_seller',
        array_replace($exhausted, ['shipment_status' => $stage]), null, 'Return reason')['reason'], 'invalid_state');
}
check('return decision cannot bypass stale revision', delivery_transition($order, $payment, $exhausted,
    'return_to_seller', 1, 9, 'DELIVERY_ATTEMPT_FAILED', null, 'Return reason')['reason'], 'conflict');
$out = array_replace($shipment, ['shipment_status' => 'OUT_FOR_DELIVERY']);
check('blank failure rejected', $decide('fail_attempt', $out, 1)['reason'], 'reason_required');
check('out of order rejected', $decide('fail_attempt', $out, 2, 'Reason')['reason'], 'attempt_sequence');
check('failure requires out for delivery', $decide('fail_attempt', $shipment, 1, 'Reason')['reason'], 'invalid_state');
$return = array_replace($shipment, ['shipment_status' => 'RETURN_TO_SELLER', 'failed_attempts' => 3]);
$returnNote = 'Received every listed item at the shop; product identities checked.';
check('attempt four rejected', $decide('fail_attempt', $return, 4, 'Reason')['reason'], 'attempt_limit');
$missingReturnNote = $decide('confirm_return', $return);
check('physical return requires an evidence note',
    [$missingReturnNote['ok'], $missingReturnNote['reason'] ?? null, $missingReturnNote['status'] ?? null],
    [false, 'reason_required', 422]);
check('physical return transition', $decide('confirm_return', $return, null, $returnNote)['state'], 'RETURNED_TO_SHOP');
check('physical return wrong predecessor', $decide('confirm_return', $out, null, $returnNote)['reason'], 'invalid_state');
check('unsupported refunded custody fails closed', delivery_transition($order,
    array_replace($payment, ['payment_status' => 'REFUNDED']), $return, 'confirm_return', 1, 0,
    'RETURN_TO_SELLER', null, $returnNote)['reason'], 'manual_review');
$verifiedGcash = ['payment_method' => 'GCASH', 'payment_status' => 'PAID', 'verified_by' => 1,
    'verified_at' => '2026-10-22 09:00:00', 'submitted_at' => '2026-10-22 08:00:00',
    'gcash_reference' => '1234567890123', 'rejection_reason' => null, 'rejected_at' => null];
foreach (['COD' => $payment, 'GCASH' => $verifiedGcash] as $method => $evidence) {
    foreach (['PENDING', 'PAID', 'FAILED', 'REFUNDED'] as $status) {
        $p = array_replace($evidence, ['payment_status' => $status]);
        check("return payment matrix {$method}/{$status}", delivery_transition($order, $p, $return,
            'confirm_return', 1, 0, 'RETURN_TO_SELLER', null, $returnNote)['ok'],
            ($method === 'COD' && $status === 'PENDING') || ($method === 'GCASH' && $status === 'PAID'));
    }
}
foreach (['received_at' => '2026-10-22 10:00:00', 'picked_up_at' => '2026-10-22 10:00:00',
    'pickup_status' => 'PICKED_UP', 'cancellation_status' => 'APPROVED', 'status' => 'CANCELLED'] as $field => $value) {
    check('return rejects terminal ' . $field, delivery_transition(array_replace($order, [$field => $value]),
        $payment, $return, 'confirm_return', 1, 0, 'RETURN_TO_SELLER', null, $returnNote)['reason'], 'manual_review');
}
foreach (['received_at', 'picked_up_at', 'pickup_status'] as $field) {
    $missing = $order;
    unset($missing[$field]);
    check('missing terminal evidence ' . $field, delivery_transition($missing, $payment, $return,
        'confirm_return', 1, 0, 'RETURN_TO_SELLER', null, $returnNote)['reason'], 'manual_review');
}
foreach (['verified_by', 'verified_at', 'submitted_at', 'gcash_reference', 'rejection_reason', 'rejected_at'] as $field) {
    $missing = $verifiedGcash;
    unset($missing[$field]);
    check('return missing GCash evidence ' . $field, delivery_transition($order, $missing, $return,
        'confirm_return', 1, 0, 'RETURN_TO_SELLER', null, $returnNote)['reason'], 'manual_review');
}
foreach (['verified_by' => 0, 'verified_at' => 'invalid', 'submitted_at' => '2026-02-30 08:00:00',
    'gcash_reference' => ['invalid'], 'rejection_reason' => 'Rejected', 'rejected_at' => '2026-10-22 09:00:00'] as $field => $value) {
    check('return contradictory GCash evidence ' . $field, delivery_transition($order,
        array_replace($verifiedGcash, [$field => $value]), $return,
        'confirm_return', 1, 0, 'RETURN_TO_SELLER', null, $returnNote)['reason'], 'manual_review');
}
check('optional proof_path is not custody evidence', delivery_transition($order, $verifiedGcash, $return,
    'confirm_return', 1, 0, 'RETURN_TO_SELLER', null, $returnNote)['ok'], true);
check('delivery cannot bypass out for delivery', $decide('delivered', $shipment)['reason'], 'invalid_state');
check('stale revision rejected', delivery_transition($order, $payment, $shipment, 'out_for_delivery', 1, 9, 'SHIPPED', null, '')['reason'], 'conflict');
check('stale cycle rejected', delivery_transition($order, $payment, $shipment, 'out_for_delivery', 2, 0, 'SHIPPED', null, '')['reason'], 'conflict');
foreach (['PENDING', 'DELIVERED', 'CANCELLED'] as $state) {
    check('order ' . $state . ' fails closed', delivery_transition(array_replace($order, ['status' => $state]), $payment, $shipment,
        'out_for_delivery', 1, 0, 'SHIPPED', null, '')['reason'], 'manual_review');
}
check('pickup never enters courier flow', $decide('out_for_delivery', array_replace($shipment, ['delivery_method' => 'STORE_PICKUP']))['reason'], 'manual_review');
check('legacy unknown method fails closed', $decide('out_for_delivery', array_replace($shipment, ['delivery_method' => null]))['reason'], 'manual_review');
foreach (['FAILED', 'REFUNDED', 'PAID'] as $state) {
    check('COD payment ' . $state . ' rejected', delivery_transition($order, array_replace($payment, ['payment_status' => $state]), $shipment,
        'out_for_delivery', 1, 0, 'SHIPPED', null, '')['reason'], 'manual_review');
}
$gcash = array_replace($payment, ['payment_method' => 'GCASH', 'payment_status' => 'PAID']);
check('PAID alone is not GCash verification', delivery_transition($order, $gcash, $shipment,
    'out_for_delivery', 1, 0, 'SHIPPED', null, '')['reason'], 'manual_review');
check('GCash fulfillment gate unchanged', payment_gcash_fulfillment_reason(['payment_method' => 'GCASH', 'payment_status' => 'PENDING']), 'gcash_awaiting_verification');
check('COD finalizer rejects failed shipment', payment_cod_collection_reason($order, $payment, $return), 'cod_not_delivered');
check('GCash finalizer retains attribution guard', payment_gcash_completion_reason($order, $gcash, $return), 'gcash_not_verified');
check('anonymous action rejected without DB', delivery_action(new class extends PDO {
    public function __construct() {}
    public function inTransaction(): bool { return false; }
}, 0, 1, 'out_for_delivery', 1, 0, 'SHIPPED', str_repeat('a', 32))['reason'], 'admin_required');
$noDatabase = new class extends PDO {
    public function __construct() {}
    public function inTransaction(): bool { return false; }
    public function exec(string $statement): int|false { throw new LogicException('Removed actions must not execute SQL.'); }
    public function prepare(string $query, array $options = []): PDOStatement|false { throw new LogicException('Removed actions must not read evidence.'); }
};
foreach (['RETURN_TO_SELLER', 'RETURNED_TO_SHOP', 'SHIPPED', 'IN_TRANSIT', 'DELIVERED'] as $stage) {
    $s = array_replace($shipment, ['shipment_status' => $stage]);
    check('removed cycle transition rejected for ' . $stage,
        $decide('authorize_cycle', $s, null, 'Saved historical request')['reason'], 'bad_request');
    foreach ([false, true] as $preview) {
        $denied = delivery_action($noDatabase, 7, 1, 'authorize_cycle', 1, 8, $stage, str_repeat('a', 32),
            reason: 'Saved historical request', validateOnly: $preview);
        check('removed action has no read, replay or write path: ' . $stage . '/' . (int) $preview,
            [$denied['ok'], $denied['reason'], $denied['status']], [false, 'bad_request', 422]);
    }
}
check('obsolete retry inspection approval cannot be recorded', delivery_action($noDatabase, 7, 1, 'inspect_return',
    1, 8, 'RETURNED_TO_SHOP', str_repeat('b', 32), reason: 'Saved old approval', orderItemId: 1,
    inspectionOutcome: 'RETRY_APPROVED')['reason'], 'bad_request');
$returnedState = array_replace($return, ['shipment_status' => 'RETURNED_TO_SHOP']);
check('manual inspection preserves the recorded delivery cycle', $decide('inspect_return', $returnedState, null, 'Condition requires review')['cycle'], 1);
check('returned journey cannot restart outing', $decide('out_for_delivery', $returnedState)['reason'], 'attempt_limit');
check('removed authorization notification is unsupported', customer_notifications_is_supported_event('DELIVERY_CYCLE_AUTHORIZED'), false);
$unsupportedMessage = false;
try { customer_notifications_message('DELIVERY_CYCLE_AUTHORIZED', 1); } catch (InvalidArgumentException $e) { $unsupportedMessage = true; }
check('removed authorization message cannot be generated', $unsupportedMessage, true);
$deliverySource = file_get_contents(__DIR__ . '/../../includes/delivery.php');
check('retained delivery writer never changes the cycle counter', preg_match('/UPDATE\s+shipments\s+SET(?:(?!\bWHERE\b).)*\bdelivery_cycle\s*=/s', $deliverySource), 0);
check('removed authorization is not an application action or UI definition',
    [in_array('authorize_cycle', delivery_action_names(), true), isset(admin_delivery_actions()['authorize_cycle'])], [false, false]);
check('notification is customer-safe, excludes failure reason', customer_notifications_message('DELIVERY_ATTEMPT_FAILED', 1),
    'A delivery attempt for your order #1 was unsuccessful. The shop will review the next delivery step.');
check('returned notification closes journey without sale or inspection claims', customer_notifications_message('RETURNED_TO_SHOP', 1),
    'The parcel for your order #1 has returned to Hopia Fits and its delivery journey has ended. Please contact the shop if you need assistance.');
$notificationSource = file(__DIR__ . '/../../includes/customer_notifications.php');
foreach (['unread_count', 'latest', 'for_order', 'mark_read', 'mark_all_read'] as $boundary) {
    $reflection = new ReflectionFunction('customer_notifications_' . $boundary);
    $body = implode('', array_slice($notificationSource, $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1));
    check('source contract: historical private authorization is filtered at notification ' . $boundary . ' boundary (not SQL execution)',
        substr_count($body, "notification_type <> \\'DELIVERY_CYCLE_AUTHORIZED\\'") === ($boundary === 'latest' ? 2 : 1), true);
    check('source contract: notification ' . $boundary . ' retains authenticated customer ownership (not SQL execution)',
        str_contains($body, 'customer_id = :customer_id'), true);
}
$orderViewSource = file_get_contents(__DIR__ . '/../../admin/order-view.php');
check('source contract: schema cutoff is inside Delivered action before payment gate (not HTTP execution)',
    preg_match('/if \(\$targetStatus === \'DELIVERED\'\) \{\s*\$deliverySchema = delivery_schema_status\(\$pdo\);\s*if \(\$deliverySchema\[\'state\'\] !== \'legacy\'\) \{\s*throw new OrderActionException/s', $orderViewSource) === 1, true);
check('source contract: UI uses the same schema guard and denies nonlegacy Delivered fallback (not browser execution)',
    preg_match('/\$courierSchema = .*?delivery_schema_status\(\$pdo\).*?\$courierView = .*?\$courierSchema\[\'state\'\] === \'ready\'.*?admin_delivery_view\(.*?if \(\$nextOrderStatus === \'DELIVERED\' && \(\$courierSchema\[\'state\'\] \?\? \'blocked\'\) !== \'legacy\'\) \{\s*\$nextOrderStatus = null;/s', $orderViewSource) === 1, true);

if ($argc === 1) {
    echo "{$checks} checks, {$failures} failures. DB integration not run.\n";
    exit($failures === 0 ? 0 : 1);
}

$db = null;
try {
    require_once __DIR__ . '/delivery_temporary_fixture.php';
    $db = delivery_test_connection();
    $fixture = static fn (int $id, string $method = 'COD') => delivery_test_fixture($db, $id, $method);
    $snapshot = static function (bool $fingerprint = false) use ($db): array {
        $state = [];
        foreach (['admins', 'customers', 'orders', 'payments', 'shipments', 'order_items', 'products',
            'order_item_verifications', 'sales_transactions', 'sales_transaction_items',
            'delivery_events', 'delivery_event_items', 'customer_order_notifications'] as $table) {
            $rows = $db->query('SELECT * FROM ' . $table)->fetchAll();
            usort($rows, static fn ($a, $b) => strcmp(serialize($a), serialize($b)));
            $state[$table] = $fingerprint ? ['count' => count($rows), 'rows' => hash('sha256', serialize($rows))] : $rows;
        }
        return $state;
    };
    check('preview fingerprint covers all 13 proven temporary shadows', count($snapshot(true)), 13);
    $call = static function (int $id, string $action, ?int $attempt = null, string $reason = '', ?int $item = null,
        ?string $outcome = null, ?array $token = null, ?string $key = null, int $admin = 1,
        bool $validateOnly = false) use ($db, $snapshot): array {
        $stmt = $db->prepare('SELECT * FROM shipments WHERE order_id = ?');
        $stmt->execute([$id]);
        $s = $token ?? $stmt->fetch();
        $before = $validateOnly ? $snapshot(true) : null;
        $result = delivery_action($db, $admin, $id, $action, (int) $s['delivery_cycle'], (int) $s['delivery_revision'],
            $s['shipment_status'], $key ?? bin2hex(random_bytes(16)), $attempt, $reason, $item, $outcome,
            validateOnly: $validateOnly);
        if ($validateOnly) {
            $name = "preview {$id}/{$action}/{$s['shipment_status']}/{$s['delivery_cycle']}:{$s['delivery_revision']}"
                . ($item === null ? '' : "/item {$item}/{$outcome}");
            check($name . ' closes transaction', $db->inTransaction(), false);
            check($name . ' preserves all 13 table counts and row fingerprints', $snapshot(true), $before);
        }
        return $result;
    };
    $view = static function (string $name, int $id, array $enabled, array $inspectionStates = [],
        array $shipmentChanges = []) use ($db, $snapshot, $call): array {
        $stmt = $db->prepare('SELECT o.*, EXISTS(SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id)
            AS has_completed_sale FROM orders o WHERE o.id = ?');
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        $stmt = $db->prepare('SELECT * FROM payments WHERE order_id = ?');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        $stmt = $db->prepare('SELECT * FROM shipments WHERE order_id = ?');
        $stmt->execute([$id]);
        $s = array_replace($stmt->fetch(), $shipmentChanges);
        $before = $snapshot(true);
        $result = admin_delivery_view($db, 1, $o, $p, $s);
        check($name . ' view closes transaction', $db->inTransaction(), false);
        check($name . ' view preserves all 13 table counts and row fingerprints', $snapshot(true), $before);
        check($name . ' view loads without error', $result['error'], null);
        check($name . ' available actions', array_keys(array_filter($result['actions'], static fn ($r) => $r['ok'])), $enabled);
        check($name . ' inspection eligibility keyed by order item', array_map(
            static fn ($r) => $r['ok'] ? 'allowed' : $r['reason'], $result['inspection_actions']), $inspectionStates);
        // Compare every action with an individually fingerprinted locked preview.
        // Impossible transitions may be prefiltered with a different denial reason.
        $actions = $inspections = [];
        foreach (['out_for_delivery', 'fail_attempt', 'return_to_seller', 'confirm_return', 'delivered'] as $action) {
            $actions[$action] = $call($id, $action,
                attempt: $action === 'fail_attempt' ? (int) $s['failed_attempts'] + 1 : null,
                reason: 'Eligibility check only', token: $s, validateOnly: true);
        }
        if ($s['shipment_status'] === 'RETURNED_TO_SHOP') {
            foreach ($result['items'] as $item) {
                $inspections[(int) $item['id']] = $call($id, 'inspect_return', reason: 'Eligibility check only',
                    item: (int) $item['id'], outcome: 'MANUAL_REVIEW', token: $s, validateOnly: true);
            }
        }
        check($name . ' availability and allowed results match full locked previews',
            array_map(static fn ($r) => $r['ok'] ? $r : ['ok' => false], $result['actions']),
            array_map(static fn ($r) => $r['ok'] ? $r : ['ok' => false], $actions));
        check($name . ' inspection results match full locked previews', $result['inspection_actions'], $inspections);
        return $result;
    };
    $fixture(1);
    $shippedView = $view('SHIPPED', 1, ['out_for_delivery']);
    check('admin view result contract', array_keys($shippedView),
        ['actions', 'inspection_actions', 'stock_actions', 'stock_decisions', 'stock_events', 'stock_records', 'stock_schema',
            'items', 'events', 'evidence', 'inspections', 'returns', 'blockers', 'error']);
    check('SHIPPED preview creates no history or approvals',
        [$shippedView['events'], $shippedView['evidence'], $shippedView['returns'], $shippedView['inspections']], [[], [], [], []]);
    $original = $db->query('SELECT * FROM shipments WHERE order_id = 1')->fetch();
    $key = bin2hex(random_bytes(16));
    $first = $call(1, 'out_for_delivery', token: $original, key: $key);
    check('persisted out for delivery', [$first['ok'], $first['state']], [true, 'OUT_FOR_DELIVERY']);
    $replay = $call(1, 'out_for_delivery', token: $original, key: $key);
    check('identical replay returns original committed result', [$replay['ok'], $replay['idempotent'], $replay['event_id']], [true, true, $first['event_id']]);
    check('different payload same key rejected', $call(1, 'fail_attempt', 1, 'Reason', token: $original, key: $key)['reason'], 'key_conflict');
    check('competing stale token rejected (sequential, NOT concurrency)', $call(1, 'out_for_delivery', token: $original)['reason'], 'conflict');
    check('preview never treats a committed old key as current eligibility',
        $call(1, 'out_for_delivery', token: $original, key: $key, validateOnly: true)['reason'], 'conflict');
    foreach (['stage' => ['shipment_status' => 'SHIPPED'], 'revision' => ['delivery_revision' => 0]] as $field => $changes) {
        $staleView = $view('stale ' . $field . ' (sequential)', 1, [], shipmentChanges: $changes);
        check('stale ' . $field . ' rejects the otherwise eligible shipment action',
            $staleView['actions'][$field === 'stage' ? 'out_for_delivery' : 'delivered']['reason'], 'conflict');
    }
    check('nonexistent admin rejected', $call(1, 'fail_attempt', 1, 'Reason', admin: 999)['reason'], 'admin_required');
    check('blank failure causes no event', $call(1, 'fail_attempt', 1, '  ')['reason'], 'reason_required');
    check('Unicode-only whitespace reason rejected', $call(1, 'fail_attempt', 1, "\u{00a0}\u{2003}")['reason'], 'reason_required');
    check('second attempt before first rejected', $call(1, 'fail_attempt', 2, 'Reason')['reason'], 'attempt_sequence');
    for ($i = 1; $i <= 3; $i++) {
        if ($i > 1) check('next outing ' . $i, $call(1, 'out_for_delivery')['ok'], true);
        $outingView = $view('OUT_FOR_DELIVERY attempt ' . $i, 1, ['fail_attempt', 'delivered']);
        check('view derives next failed attempt ' . $i . ' from shipment',
            [$outingView['actions']['fail_attempt']['failed_attempts'], $outingView['actions']['fail_attempt']['state']],
            [$i, 'DELIVERY_ATTEMPT_FAILED']);
        $result = $call(1, 'fail_attempt', $i, 'Temporary failure ' . $i);
        check('persisted attempt ' . $i, [$result['ok'], $result['failed_attempts'], $result['state']],
            [true, $i, 'DELIVERY_ATTEMPT_FAILED']);
        $failedView = $view('after failure ' . $i, 1, [$i === 3 ? 'return_to_seller' : 'out_for_delivery']);
    }
    check('manual return decision rejects blank reason', $call(1, 'return_to_seller')['reason'], 'reason_required');
    check('manual return decision explicitly commits', $call(1, 'return_to_seller', reason: 'Third failure exhausted; return parcel')['state'], 'RETURN_TO_SELLER');
    $failedView = $view('manual return to seller awaits physical custody', 1, ['confirm_return']);
    check('third failure view separates return-to-seller history from physical custody',
        [array_column($failedView['events'], 'event_type'), $failedView['returns'], $failedView['inspections']],
        [['OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED', 'OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED',
            'OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED', 'RETURN_TO_SELLER'], [], []]);
    check('fourth failure never recorded', $call(1, 'fail_attempt', 4, 'Reason')['reason'], 'attempt_limit');
    check('return state cannot be delivered', $call(1, 'delivered')['reason'], 'invalid_state');
    $evidenceToken = $db->query('SELECT * FROM shipments WHERE order_id=1')->fetch();
    $evidenceKey = bin2hex(random_bytes(16));
    $beforeEvidence = $snapshot(true);
    foreach (['missing' => null, 'empty' => '', 'ASCII whitespace' => " \t\r\n",
        'Unicode whitespace' => "\u{00a0}\u{2003}\u{2028}"] as $name => $note) {
        $result = $note === null
            ? delivery_action($db, 1, 1, 'confirm_return', (int) $evidenceToken['delivery_cycle'],
                (int) $evidenceToken['delivery_revision'], $evidenceToken['shipment_status'], $evidenceKey)
            : $call(1, 'confirm_return', reason: $note, token: $evidenceToken, key: $evidenceKey);
        check($name . ' physical-return note rejected', [$result['ok'], $result['reason'] ?? null, $result['status']],
            [false, 'reason_required', 422]);
        check($name . ' return note leaves all 13 tables unchanged', $snapshot(true), $beforeEvidence);
        check($name . ' return note leaves no open transaction', $db->inTransaction(), false);
    }
    check('invalid notes create no physical-return event', $db->query("SELECT COUNT(*) FROM delivery_events
        WHERE order_id=1 AND event_type='RETURNED_TO_SHOP'")->fetchColumn(), 0);
    check('invalid notes create no physical-return notification', $db->query("SELECT COUNT(*) FROM customer_order_notifications
        WHERE order_id=1 AND notification_type='RETURNED_TO_SHOP'")->fetchColumn(), 0);
    foreach (['stage' => ['shipment_status' => 'OUT_FOR_DELIVERY'],
        'revision' => ['delivery_revision' => (int) $evidenceToken['delivery_revision'] - 1]] as $field => $changes) {
        check('valid return note cannot bypass stale ' . $field, $call(1, 'confirm_return', reason: $returnNote,
            token: array_replace($evidenceToken, $changes))['reason'], 'conflict');
        check('stale return ' . $field . ' changes no rows', $snapshot(true), $beforeEvidence);
    }
    $returnConfirmation = $call(1, 'confirm_return', reason: " \t\u{00a0}" . $returnNote . "\u{2003}\r\n",
        token: $evidenceToken, key: $evidenceKey);
    check('physical return commits with a trimmed note', [$returnConfirmation['ok'], $returnConfirmation['state']],
        [true, 'RETURNED_TO_SHOP']);
    check('ledger stores the normalized administrator note', $db->query("SELECT reason FROM delivery_events
        WHERE order_id=1 AND event_type='RETURNED_TO_SHOP'")->fetchColumn(), $returnNote);
    $db->exec("UPDATE customer_order_notifications SET read_at='2026-10-22 12:00:00'
        WHERE order_id=1 AND notification_type='RETURNED_TO_SHOP'");
    $beforeReturnReplay = $snapshot(true);
    $returnReplay = $call(1, 'confirm_return', reason: $returnNote, token: $evidenceToken, key: $evidenceKey);
    check('trim-equivalent return replay reports the original event',
        [$returnReplay['ok'], $returnReplay['idempotent'], $returnReplay['event_id']],
        [true, true, $returnConfirmation['event_id']]);
    check('return replay changes no events, notifications, read states or other rows', $snapshot(true), $beforeReturnReplay);
    check('return replay keeps exactly one custody event', $db->query("SELECT COUNT(*) FROM delivery_events
        WHERE order_id=1 AND event_type='RETURNED_TO_SHOP'")->fetchColumn(), 1);
    check('return replay keeps exactly one custody notification', $db->query("SELECT COUNT(*) FROM customer_order_notifications
        WHERE order_id=1 AND notification_type='RETURNED_TO_SHOP'")->fetchColumn(), 1);
    check('new return key cannot reuse consumed tokens', $call(1, 'confirm_return', reason: $returnNote,
        token: $evidenceToken)['reason'], 'conflict');
    check('new return key cannot reconfirm the returned state', $call(1, 'confirm_return', reason: $returnNote)['reason'], 'invalid_state');
    check('new return keys do not alter existing custody', $snapshot(true), $beforeReturnReplay);
    $returnedView = $view('RETURNED_TO_SHOP before inspection', 1, [], [1 => 'allowed']);
    check('physical custody is not condition inspection',
        [$returnedView['returns'][1]['outcome'], $returnedView['inspections']], ['RETURNED', []]);
    check('MANUAL_REVIEW inspection can be previewed without recording a decision',
        $call(1, 'inspect_return', reason: 'Temporary inspection preview', item: 1,
            outcome: 'MANUAL_REVIEW', validateOnly: true)['ok'], true);
    check('return records exact item identity', $db->query("SELECT COUNT(*) FROM delivery_event_items WHERE order_item_id=1 AND product_id=1 AND record_type='RETURNED'")->fetchColumn(), 1);
    check('no automatic inventory release', $db->query('SELECT status FROM products WHERE id=1')->fetchColumn(), 'SOLD');
    check('no sale after third failure or physical return', $db->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn(), 0);
    check('no payment collection', $db->query('SELECT payment_status FROM payments WHERE order_id=1')->fetchColumn(), 'PENDING');
    check('explicit manual inspection commits', $call(1, 'inspect_return', reason: 'Condition needs administrative review', item: 1, outcome: 'MANUAL_REVIEW')['ok'], true);
    check('inspection does not release item', $db->query('SELECT status FROM products WHERE id=1')->fetchColumn(), 'SOLD');
    $view('current item inspection is immutable', 1, [], [1 => 'invalid_state']);
    check('all three failure notifications have distinct occurrences', $db->query("SELECT COUNT(DISTINCT occurrence_key) FROM customer_order_notifications WHERE notification_type='DELIVERY_ATTEMPT_FAILED'")->fetchColumn(), 3);
    check('all three failures retained by cycle', $db->query("SELECT COUNT(*) FROM delivery_events WHERE event_type='DELIVERY_ATTEMPT_FAILED'")->fetchColumn(), 3);
    check('third failure has separate Return to Seller event', $db->query("SELECT COUNT(*) FROM delivery_events WHERE event_type='RETURN_TO_SELLER'")->fetchColumn(), 1);
    $db->beginTransaction();
    customer_notifications_create($db, 1, 'ORDER_SHIPPED');
    $db->exec("UPDATE customer_order_notifications SET read_at='2026-10-22 12:00:00' WHERE notification_type='ORDER_SHIPPED'");
    customer_notifications_create($db, 1, 'ORDER_SHIPPED');
    $db->commit();
    check('legacy notification replay preserves read timestamp', $db->query("SELECT read_at FROM customer_order_notifications WHERE notification_type='ORDER_SHIPPED'")->fetchColumn(), '2026-10-22 12:00:00');
    $db->beginTransaction();
    $db->query('SELECT id FROM products WHERE id=1 FOR UPDATE');
    $blocked = false;
    try { inventory_assert_unlinked_locked($db, 1); } catch (RuntimeException $e) { $blocked = true; }
    $db->rollBack();
    check('generic edit/delete cannot release a held return', $blocked, true);
    // Forced notification failure must roll back shipment and event writes.
    $fixture(2);
    $db->exec("ALTER TABLE customer_order_notifications ADD CONSTRAINT test_notification_block CHECK (order_id <> 2)");
    $view('preview stops before the deliberately failing notification write', 2, ['out_for_delivery']);
    check('notification failure rolls action back', $call(2, 'out_for_delivery')['reason'], 'error');
    check('shipment rollback', $db->query('SELECT shipment_status FROM shipments WHERE order_id=2')->fetchColumn(), 'SHIPPED');
    check('event rollback', $db->query('SELECT COUNT(*) FROM delivery_events WHERE order_id=2')->fetchColumn(), 0);
    $fixture(3, 'GCASH');
    $db->exec("UPDATE payments SET payment_status='PAID' WHERE order_id=3");
    $view('GCash PAID without verification', 3, []);
    check('persisted PAID without attribution rejected', $call(3, 'out_for_delivery')['reason'], 'manual_review');
    $db->exec("UPDATE payments SET verified_by=1,verified_at=NOW(),submitted_at=NOW(),gcash_reference='1234567890123' WHERE order_id=3");
    check('verified GCash delivery permitted', $call(3, 'out_for_delivery')['ok'], true);
    $view('verified GCash OUT_FOR_DELIVERY', 3, ['fail_attempt', 'delivered']);
    check('delivery is separate from financial completion', $call(3, 'delivered')['ok'], true);
    $deliveredView = $view('DELIVERED has no courier actions', 3, []);
    check('delivered view retains delivery history without approvals',
        [array_column($deliveredView['events'], 'event_type'), $deliveredView['returns'], $deliveredView['inspections']],
        [['OUT_FOR_DELIVERY', 'DELIVERED'], [], []]);
    check('delivered does not create sale', $db->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn(), 0);
    check('delivered does not confirm customer receipt', $db->query('SELECT received_at FROM orders WHERE id=3')->fetchColumn(), null);
    $fixture(4);
    $db->exec("UPDATE shipments SET delivery_method='STORE_PICKUP' WHERE order_id=4");
    $view('pickup never enables courier previews', 4, []);
    check('persisted pickup rejected without mutation', $call(4, 'out_for_delivery')['reason'], 'manual_review');
    check('pickup remains untouched', $db->query('SELECT pickup_status FROM orders WHERE id=4')->fetchColumn(), null);
    $fixture(5);
    $db->exec('DELETE FROM order_item_verifications WHERE order_id=5');
    $view('missing item verification', 5, []);
    check('missing verification fails closed', $call(5, 'out_for_delivery')['reason'], 'manual_review');
    // Retained history from a proven pending cancellation is not an active hold.
    $fixture(6);
    $db->exec("UPDATE orders SET status='CANCELLED',cancellation_status='APPROVED' WHERE id=6");
    $db->exec("UPDATE shipments SET shipment_status='NOT_SHIPPED',shipped_at=NULL WHERE order_id=6");
    $db->exec('UPDATE order_items SET product_id=1 WHERE order_id=6');
    $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->beginTransaction();
    $db->query('SELECT id FROM products WHERE id=1 FOR UPDATE');
    check('resolved pending cancellation is not another hold', inventory_has_other_hold_locked($db, 1, 1), false);
    $db->commit();
    $db->exec("UPDATE payments SET payment_status='REFUNDED' WHERE order_id=6");
    $db->beginTransaction();
    $db->query('SELECT id FROM products WHERE id=1 FOR UPDATE');
    check('unsupported historical refund remains held', inventory_has_other_hold_locked($db, 1, 1), true);
    $db->commit();
    // Unsupported resolution does not become authoritative physical custody.
    $fixture(7);
    $call(7, 'out_for_delivery');
    for ($i = 1; $i <= 3; $i++) {
        if ($i > 1) $call(7, 'out_for_delivery');
        $call(7, 'fail_attempt', $i, 'Temporary failure');
    }
    $call(7, 'return_to_seller', reason: 'Failed cycle explicitly returned to seller');
    $db->exec("UPDATE payments SET payment_status='REFUNDED' WHERE order_id=7");
    $db->exec("UPDATE products SET retired_at=NOW() WHERE id=7");
    check('unsupported payment resolution blocks return custody', $call(7, 'confirm_return', reason: $returnNote)['reason'], 'manual_review');
    check('rejected custody never releases retired item', $db->query('SELECT status FROM products WHERE id=7')->fetchColumn(), 'SOLD');
    // A counter/state without matching append-only events cannot be advanced.
    $fixture(8);
    $db->exec("UPDATE shipments SET failed_attempts=1,shipment_status='DELIVERY_ATTEMPT_FAILED' WHERE order_id=8");
    check('fabricated counter without failure history rejected', $call(8, 'out_for_delivery')['reason'], 'manual_review');
    $duplicateRejected = false;
    try {
        $db->exec("INSERT INTO delivery_events(order_id,delivery_cycle,delivery_revision,event_type,admin_id,
            attempt_number,reason,previous_state,resulting_state,action_key,event_index,request_hash)
            VALUES(1,1,999,'DELIVERY_ATTEMPT_FAILED',1,1,'Duplicate fixture','OUT_FOR_DELIVERY',
                'DELIVERY_ATTEMPT_FAILED','temporary-duplicate-failure',0,REPEAT('a',64))");
    } catch (PDOException $e) { $duplicateRejected = $e->getCode() === '23000'; }
    check('unique order cycle attempt rejects duplicate failure', $duplicateRejected, true);
    $rejectUnchanged = static function (string $name, int $id, string $action, string $expected = 'manual_review',
        ?int $item = null, ?string $outcome = null) use ($call, $snapshot): void {
        $before = $snapshot();
        check($name . ' rejected', $call($id, $action, reason: 'Temporary review', item: $item, outcome: $outcome)['reason'], $expected);
        check($name . ' changes no rows (controls/history/payment/inventory/notifications)', $snapshot(), $before);
    };
    $atReturn = static function (int $id, string $method = 'COD') use ($fixture, $call, $db): void {
        $fixture($id, $method);
        if ($method === 'GCASH') {
            $db->prepare("UPDATE payments SET payment_status='PAID',verified_by=1,verified_at=NOW(),
                submitted_at=NOW(),gcash_reference='1234567890123' WHERE order_id=?")->execute([$id]);
        }
        for ($i = 1; $i <= 3; $i++) {
            if (!$call($id, 'out_for_delivery')['ok'] || !$call($id, 'fail_attempt', $i, 'Temporary failure')['ok']) {
                throw new RuntimeException('Eligible return fixture did not exhaust its delivery attempts.');
            }
        }
        if (!$call($id, 'return_to_seller', reason: 'Explicit return decision after three failures')['ok']) {
            throw new RuntimeException('Eligible return fixture did not record the manual return decision.');
        }
    };
    $atReturn(10);
    $db->exec("INSERT INTO sales_transactions(order_id,sale_channel,admin_id,total_amount,request_key)
        VALUES(10,'ONLINE',1,100,'temporary-completed-sale-10')");
    $saleView = $view('committed sale blocks physical return', 10, []);
    check('committed-sale view explains the blocker', str_contains(implode(' ', $saleView['blockers']), 'committed sale'), true);
    $rejectUnchanged('completed-sale order physical return', 10, 'confirm_return');
    foreach (['received_at' => "received_at=NOW()", 'picked_up_at' => "picked_up_at=NOW()",
        'pickup_handover' => "pickup_status='PICKED_UP'", 'cancelled_order' => "status='CANCELLED',cancellation_status='APPROVED'",
        'requested_cancellation' => "cancellation_status='REQUESTED'", 'approved_cancellation' => "cancellation_status='APPROVED'"] as $name => $change) {
        $id = 11 + array_search($name, ['received_at','picked_up_at','pickup_handover','cancelled_order','requested_cancellation','approved_cancellation'], true);
        $atReturn($id);
        $db->exec('UPDATE orders SET ' . $change . ' WHERE id=' . $id);
        $rejectUnchanged($name . ' physical return', $id, 'confirm_return');
    }
    $id = 20;
    foreach (['COD', 'GCASH'] as $method) {
        foreach (['PENDING', 'PAID', 'FAILED', 'REFUNDED'] as $status) {
            if (($method === 'COD' && $status === 'PENDING') || ($method === 'GCASH' && $status === 'PAID')) continue;
            $atReturn($id, $method);
            $db->prepare('UPDATE payments SET payment_status=? WHERE order_id=?')->execute([$status, $id]);
            $rejectUnchanged("persisted return {$method}/{$status}", $id, 'confirm_return');
            $id++;
        }
    }
    $atReturn(27, 'GCASH');
    $db->exec('UPDATE payments SET verified_by=999 WHERE order_id=27');
    $rejectUnchanged('return missing verifier identity', 27, 'confirm_return');
    $atReturn(28, 'GCASH');
    check('eligible GCash custody with optional proof absent', $call(28, 'confirm_return', reason: $returnNote)['ok'], true);
    $atReturn(29);
    $db->exec('UPDATE products SET retired_at=NOW() WHERE id=29');
    $retiredView = $view('retired SOLD item permits custody only', 29, ['confirm_return']);
    check('retired view explains the inspection and stock-release restriction', str_contains(implode(' ', $retiredView['blockers']), 'retired item'), true);
    check('identifiable retired SOLD item may record custody only', $call(29, 'confirm_return', reason: $returnNote)['ok'], true);
    $view('returned retired item cannot be inspected for stock', 29, [], [29 => 'manual_review']);
    $rejectUnchanged('retired item inspection', 29, 'inspect_return', item: 29, outcome: 'MANUAL_REVIEW');
    foreach (['quantity' => 'quantity=2', 'null_product' => 'product_id=NULL', 'duplicate_product' => 'product_id=30'] as $name => $change) {
        $fixture(30 + array_search($name, ['quantity', 'null_product', 'duplicate_product'], true));
        $id = 30 + array_search($name, ['quantity', 'null_product', 'duplicate_product'], true);
        // The first row is valid; the second row must not cause partial effects.
        $db->prepare("INSERT INTO order_items(id,order_id,product_id,product_name,unit_price,quantity,subtotal)
            VALUES(?,?,?,'Historical review snapshot',100,1,100)")->execute([$id + 1000, $id, $id]);
        $db->exec('UPDATE order_items SET ' . ($name === 'duplicate_product' ? 'product_id=' . $id : $change) . ' WHERE id=' . ($id + 1000));
        $legacyView = $view('legacy ' . $name . ' outing', $id, []);
        check('legacy ' . $name . ' view uses physical identity guard', $legacyView['actions']['out_for_delivery']['reason'], 'legacy_item_review');
        $rejectUnchanged('legacy ' . $name . ' outing', $id, 'out_for_delivery', 'legacy_item_review');
    }
    $atReturn(33);
    $db->exec('UPDATE order_items SET quantity=2 WHERE id=33');
    $quantityView = $view('multi-quantity blocks physical return', 33, []);
    check('multi-quantity view explains missing per-unit identity', str_contains(implode(' ', $quantityView['blockers']), 'quantity one'), true);
    $rejectUnchanged('legacy quantity physical return', 33, 'confirm_return', 'legacy_item_review');
    $atReturn(34);
    $db->exec('UPDATE order_items SET product_id=NULL WHERE id=34');
    $rejectUnchanged('legacy null-product physical return', 34, 'confirm_return', 'legacy_item_review');
    $atReturn(35);
    check('eligible return for manual inspection', $call(35, 'confirm_return', reason: $returnNote)['ok'], true);
    check('MANUAL_REVIEW inspection recorded without release', $call(35, 'inspect_return', reason: 'Custody disputed', item: 35, outcome: 'MANUAL_REVIEW')['ok'], true);
    $manualView = $view('manual-review inspection cannot be overwritten', 35, [], [35 => 'invalid_state']);
    check('view retains manual review rather than treating it as approval',
        $manualView['inspections'][35]['outcome'], 'MANUAL_REVIEW');
    $rejectUnchanged('manual inspection cannot be silently overwritten', 35, 'inspect_return', 'invalid_state', 35, 'MANUAL_REVIEW');
    $atReturn(36);
    check('valid return before shared inspection guard cases', $call(36, 'confirm_return', reason: $returnNote)['ok'], true);
    foreach (["UPDATE orders SET received_at=NOW() WHERE id=36",
        "UPDATE orders SET picked_up_at=NOW() WHERE id=36",
        "UPDATE orders SET pickup_status='PICKED_UP' WHERE id=36",
        "UPDATE orders SET status='CANCELLED',cancellation_status='APPROVED' WHERE id=36",
        "UPDATE payments SET payment_status='REFUNDED' WHERE order_id=36",
        "UPDATE products SET retired_at=NOW() WHERE id=36"] as $index => $change) {
        $db->exec($change);
        $rejectUnchanged('inspection terminal guard ' . $index, 36, 'inspect_return', item: 36, outcome: 'MANUAL_REVIEW');
        // Restore only this test's deliberately changed temporary fields.
        $db->exec("UPDATE orders SET status='SHIPPED',cancellation_status='NONE',received_at=NULL,picked_up_at=NULL,pickup_status=NULL WHERE id=36");
        $db->exec("UPDATE payments SET payment_status='PENDING' WHERE order_id=36");
        $db->exec('UPDATE products SET retired_at=NULL WHERE id=36');
    }
    $fixture(37);
    $db->exec('UPDATE order_items SET product_id=36 WHERE order_id=37');
    $heldView = $view('other active hold blocks inspection (sequential)', 36, [], [36 => 'manual_review']);
    check('preview inspection rechecks other-order hold', $heldView['inspection_actions'][36]['reason'], 'manual_review');
    $rejectUnchanged('competing active order hold prevents inspection (sequential)', 36, 'inspect_return', item: 36, outcome: 'MANUAL_REVIEW');
    $db->exec('UPDATE order_items SET product_id=37 WHERE order_id=37');
    $db->exec("INSERT INTO sales_transactions(order_id,sale_channel,admin_id,total_amount,request_key)
        VALUES(36,'ONLINE',1,100,'temporary-completed-sale-36')");
    $view('committed sale blocks inspected return', 36, [], [36 => 'manual_review']);
    $rejectUnchanged('committed sale prevents inspection', 36, 'inspect_return', item: 36, outcome: 'MANUAL_REVIEW');
    $atReturn(38);
    $fixture(39);
    $db->exec('UPDATE order_items SET product_id=38 WHERE order_id=39');
    $view('other active hold blocks physical custody (sequential)', 38, []);
    $rejectUnchanged('competing hold prevents custody (sequential)', 38, 'confirm_return');
    $db->exec('UPDATE order_items SET product_id=39 WHERE order_id=39');
    check('eligible COD return still commits', $call(38, 'confirm_return', reason: $returnNote)['ok'], true);
    // Temporary tables deliberately have no production append-only triggers.
    // Corrupt evidence only here to prove inspection fails closed without writes.
    $db->exec("UPDATE delivery_event_items SET outcome='MANUAL_REVIEW' WHERE order_id=38 AND record_type='RETURNED'");
    $rejectUnchanged('contradictory physical-return outcome', 38, 'inspect_return', item: 38, outcome: 'MANUAL_REVIEW');
    $atReturn(40);
    $call(40, 'confirm_return', reason: $returnNote);
    $db->exec('DELETE FROM delivery_event_items WHERE order_id=40');
    $missingReturnView = $view('missing current physical-return evidence', 40, [], [40 => 'inspection_required']);
    check('missing custody is not manufactured by the view', [$missingReturnView['returns'], $missingReturnView['inspections']], [[], []]);
    $rejectUnchanged('missing physical return evidence blocks inspection', 40, 'inspect_return', 'inspection_required', 40, 'MANUAL_REVIEW');
    $atReturn(41);
    $call(41, 'confirm_return', reason: $returnNote);
    $call(41, 'inspect_return', reason: 'Inspected item requiring review', item: 41, outcome: 'MANUAL_REVIEW');
    $db->exec('UPDATE order_items SET quantity=2 WHERE id=41');
    $view('multi-quantity blocks already-inspected item', 41, [], [41 => 'legacy_item_review']);
    $rejectUnchanged('legacy quantity inspection', 41, 'inspect_return', 'legacy_item_review', 41, 'MANUAL_REVIEW');
    $atReturn(42);
    $db->exec("INSERT INTO sales_transaction_items(sale_id,product_id,product_name,unit_price,order_item_id)
        SELECT id,42,'Temporary sale membership',100,NULL FROM sales_transactions WHERE order_id=10");
    $view('sale product membership blocks physical custody', 42, []);
    $rejectUnchanged('sale product membership blocks physical return', 42, 'confirm_return');
    $atReturn(43);
    $db->exec("INSERT INTO sales_transaction_items(sale_id,product_id,product_name,unit_price,order_item_id)
        SELECT id,NULL,'Temporary item membership',100,43 FROM sales_transactions WHERE order_id=10");
    $view('sale order-item membership blocks physical custody', 43, []);
    $rejectUnchanged('sale order-item membership blocks physical return', 43, 'confirm_return');
    $atReturn(45);
    $call(45, 'confirm_return', reason: $returnNote);
    $db->exec('UPDATE delivery_event_items SET product_id=44 WHERE order_id=45');
    $rejectUnchanged('wrong physical identity blocks inspection', 45, 'inspect_return', item: 45, outcome: 'MANUAL_REVIEW');
    $atReturn(46);
    $db->exec("UPDATE products SET status='AVAILABLE' WHERE id=46");
    $availableView = $view('AVAILABLE inventory cannot record courier custody', 46, []);
    check('available-product view explains incompatible inventory', str_contains(implode(' ', $availableView['blockers']), 'not held as SOLD'), true);
    $rejectUnchanged('available inventory contradicts courier custody', 46, 'confirm_return');
    $atReturn(47);
    $db->exec('DELETE FROM payments WHERE order_id=47');
    $missingPaymentView = $view('missing payment blocks every courier action', 47, []);
    check('missing-payment view explains the blocker', str_contains(implode(' ', $missingPaymentView['blockers']), 'payment record is missing'), true);
    $rejectUnchanged('missing payment evidence blocks custody', 47, 'confirm_return');
    $atReturn(48);
    $call(48, 'confirm_return', reason: $returnNote);
    $call(48, 'inspect_return', reason: 'Identifiable item requires review', item: 48, outcome: 'MANUAL_REVIEW');
    $db->exec('UPDATE order_items SET product_id=NULL WHERE id=48');
    $unlinkedView = $view('missing product link blocks inspected item', 48, [], [48 => 'legacy_item_review']);
    check('mismatched product link does not display old custody or approval',
        [$unlinkedView['returns'], $unlinkedView['inspections']], [[], []]);
    $rejectUnchanged('legacy null-product inspection', 48, 'inspect_return', 'legacy_item_review', 48, 'MANUAL_REVIEW');
    $atReturn(49);
    $returnToken = $db->query('SELECT * FROM shipments WHERE order_id=49')->fetch();
    $returnKey = bin2hex(random_bytes(16));
    $returnResult = $call(49, 'confirm_return', reason: $returnNote, token: $returnToken, key: $returnKey);
    check('return action records original custody', $returnResult['ok'], true);
    $db->exec("INSERT INTO sales_transactions(order_id,sale_channel,admin_id,total_amount,request_key)
        VALUES(49,'ONLINE',1,100,'temporary-later-sale-49')");
    $beforeReplay = $snapshot();
    $returnReplay = $call(49, 'confirm_return', reason: $returnNote, token: $returnToken, key: $returnKey);
    check('return replay reports original result even after later incompatible state',
        [$returnReplay['ok'], $returnReplay['idempotent'], $returnReplay['event_id']],
        [true, true, $returnResult['event_id']]);
    check('return replay never records new custody or mutates later state', $snapshot(), $beforeReplay);
    $rejectUnchanged('new return key cannot bypass later committed sale', 49, 'confirm_return');
    $fixture(50);
    $db->exec("INSERT INTO products(id,name,category,condition_label,price,status)
        VALUES(1050,'Second temporary item','SHIRTS','Test',100,'SOLD')");
    $db->exec("INSERT INTO order_items(id,order_id,product_id,product_name,unit_price,quantity,subtotal)
        VALUES(1050,50,1050,'Second temporary snapshot',100,1,100)");
    $db->exec("INSERT INTO order_item_verifications(order_id,order_item_id,product_id,verified_by,method,reason)
        VALUES(50,1050,1050,1,'MANUAL','Second physical item verified')");
    for ($i = 1; $i <= 3; $i++) {
        check('multi-item outing ' . $i, $call(50, 'out_for_delivery')['ok'], true);
        check('multi-item failed attempt ' . $i, $call(50, 'fail_attempt', $i, 'Temporary failure')['ok'], true);
    }
    check('multi-item manual return decision commits', $call(50, 'return_to_seller', reason: 'Explicit return after third failure')['ok'], true);
    check('multi-item physical custody commits', $call(50, 'confirm_return', reason: $returnNote)['ok'], true);
    check('multi-item return preserves both physical identities', $db->query("SELECT order_item_id,product_id FROM delivery_event_items
        WHERE order_id=50 AND record_type='RETURNED' ORDER BY order_item_id")->fetchAll(),
        [['order_item_id' => 50, 'product_id' => 50], ['order_item_id' => 1050, 'product_id' => 1050]]);
    $multiReturnView = $view('multi-item return awaits both inspections', 50, [], [50 => 'allowed', 1050 => 'allowed']);
    check('view lists every physical item rather than only the first', array_map(
        static fn ($item) => [(int) $item['id'], (int) $item['product_id'], (int) $item['quantity']], $multiReturnView['items']),
        [[50, 50, 1], [1050, 1050, 1]]);
    check('view maps custody to both order-item identities',
        [array_keys($multiReturnView['returns']), $multiReturnView['inspections']], [[50, 1050], []]);
    $multiReturnToken = $db->query('SELECT * FROM shipments WHERE order_id=50')->fetch();
    check('first item inspection commits', $call(50, 'inspect_return', reason: 'First item inspected for manual review', item: 50, outcome: 'MANUAL_REVIEW')['ok'], true);
    $oneInspectedView = $view('one of two current items inspected', 50, [], [50 => 'invalid_state', 1050 => 'allowed']);
    check('one inspection does not inspect the other item', array_keys($oneInspectedView['inspections']), [50]);
    $staleInspectionView = $view('stale inspection revision (sequential)', 50, [], [50 => 'conflict', 1050 => 'conflict'],
        shipmentChanges: ['delivery_revision' => $multiReturnToken['delivery_revision']]);
    check('stale inspection view cannot accept the second inspection', $staleInspectionView['inspection_actions'][1050]['reason'], 'conflict');
    $priorHistory = $db->query('SELECT * FROM delivery_events WHERE order_id=50 ORDER BY id')->fetchAll();
    $priorItems = $db->query('SELECT * FROM delivery_event_items WHERE order_id=50 ORDER BY event_id,order_item_id')->fetchAll();
    check('second-item MANUAL_REVIEW preview records no evidence', $call(50, 'inspect_return', reason: 'Review preview only',
        item: 1050, outcome: 'MANUAL_REVIEW', validateOnly: true)['ok'], true);
    check('second item manual review commits', $call(50, 'inspect_return', reason: 'Second item requires review', item: 1050, outcome: 'MANUAL_REVIEW')['ok'], true);
    $mixedInspectionView = $view('both current item inspections are immutable', 50, [], [50 => 'invalid_state', 1050 => 'invalid_state']);
    check('view displays only the current-cycle inspection outcomes', array_map(
        static fn ($row) => [(int) $row['delivery_cycle'], (int) $row['event_cycle'], $row['outcome']], $mixedInspectionView['inspections']),
        [50 => [1, 1, 'MANUAL_REVIEW'], 1050 => [1, 1, 'MANUAL_REVIEW']]);
    check('second inspection preserves previous event history', array_slice($db->query('SELECT * FROM delivery_events WHERE order_id=50 ORDER BY id')->fetchAll(), 0, count($priorHistory)), $priorHistory);
    check('second inspection preserves previous item evidence', array_slice($db->query('SELECT * FROM delivery_event_items WHERE order_id=50 ORDER BY event_id,order_item_id')->fetchAll(), 0, count($priorItems)), $priorItems);
    check('multi-item return never releases either held product', $db->query('SELECT id,status FROM products WHERE id IN (50,1050) ORDER BY id')->fetchAll(),
        [['id' => 50, 'status' => 'SOLD'], ['id' => 1050, 'status' => 'SOLD']]);
    $atReturn(51);
    $db->exec('DELETE FROM products WHERE id=51');
    $missingProductView = $view('missing current product blocks physical custody', 51, []);
    check('snapshot name cannot replace missing physical product identity',
        [$missingProductView['items'][0]['product_id'], $missingProductView['items'][0]['current_product_id'],
            $missingProductView['actions']['confirm_return']['reason']], [51, null, 'legacy_item_review']);
    check('missing-product view explains the identity blocker', str_contains(implode(' ', $missingProductView['blockers']), 'existing product link'), true);
    $rejectUnchanged('missing current product physical return', 51, 'confirm_return', 'legacy_item_review');
    // Disconnect is the only cleanup, so append-only history has no deletion path.
    $db = null;
} catch (Throwable $e) {
    if ($db !== null && $db->inTransaction()) $db->rollBack();
    $db = null;
    check('isolated test exception: ' . $e->getMessage(), true, false);
}
echo "{$checks} checks, {$failures} failures. Temporary connection closed; no persistent fixtures or migration.\n";
exit($failures === 0 ? 0 : 1);
