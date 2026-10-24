<?php

declare(strict_types=1);

// CLI only. Queued PDO doubles never construct a driver or permit application DML.
// No bootstrap, .env, database fixture, HTTP harness, or existing order is used.
if (PHP_SAPI !== 'cli' || $argc !== 1) exit(2);
require_once __DIR__ . '/../../includes/admin_delivery.php';

$checks = $failures = 0;
function guardCheck(string $label, mixed $actual, mixed $expected): void
{
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo 'FAIL ' . $label . ': ' . var_export($actual, true) . ' != ' . var_export($expected, true) . PHP_EOL;
    } else echo 'ok ' . $label . PHP_EOL;
}

final class DeliveryGuardPDO extends PDO
{
    public array $events = [];
    public array $queries = [];
    public array $violations = [];
    private bool $active = false;
    public function __construct(public array $queue = [], public array $state = []) {}
    public function inTransaction(): bool { return $this->active; }
    public function exec(string $statement): int|false
    {
        if ($this->active || $statement !== 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') {
            $this->violations[] = 'Unexpected exec: ' . $statement;
            throw new LogicException(end($this->violations));
        }
        $this->events[] = 'READ COMMITTED';
        return 0;
    }
    public function beginTransaction(): bool
    {
        if ($this->active) throw new LogicException('Nested memory transaction');
        $this->active = true;
        $this->events[] = 'begin';
        return true;
    }
    public function commit(): bool
    {
        if (!$this->active) throw new LogicException('Commit without memory transaction');
        $this->active = false;
        $this->events[] = 'commit';
        return true;
    }
    public function rollBack(): bool
    {
        if (!$this->active) throw new LogicException('Rollback without memory transaction');
        $this->active = false;
        $this->events[] = 'rollback';
        return true;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new DeliveryGuardStatement($this, preg_replace('/\s+/', ' ', trim($query)));
    }
}

final class DeliveryGuardStatement extends PDOStatement
{
    private array $rows = [];
    public function __construct(private DeliveryGuardPDO $pdo, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->pdo->queries[] = [$this->sql, $params];
        $step = array_shift($this->pdo->queue);
        if (!str_starts_with($this->sql, 'SELECT ') || !$this->pdo->inTransaction()
            || $step === null || preg_match('~' . $step[0] . '~', $this->sql) !== 1 || $params !== $step[1]) {
            $this->pdo->violations[] = 'Unexpected SQL/bindings or nontransactional/write query: ' . $this->sql . ' ' . json_encode($params);
            throw new LogicException(end($this->pdo->violations));
        }
        if ($step[2] instanceof Throwable) throw $step[2];
        $this->rows = $step[2];
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : array_values($row)[$column];
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $mode === PDO::FETCH_COLUMN ? array_map(static fn ($row) => array_values($row)[0], $rows) : $rows;
    }
}

$order = ['id' => 801, 'status' => 'SHIPPED', 'cancellation_status' => 'NONE', 'received_at' => null,
    'picked_up_at' => null, 'pickup_status' => null];
$payment = ['payment_method' => 'COD', 'payment_status' => 'PENDING', 'verified_by' => null, 'verified_at' => null];
$shipment = ['delivery_method' => 'LOCAL_COURIER', 'shipment_status' => 'RETURNED_TO_SHOP', 'delivery_cycle' => 1,
    'delivery_revision' => 8, 'failed_attempts' => 3, 'shipped_at' => '2026-10-22 10:00:00',
    'tracking_number' => 'SYNTHETIC-ONLY', 'delivered_at' => null];
$item = ['id' => 901, 'product_id' => 1001, 'quantity' => 1];
$product = ['id' => 1001, 'status' => 'SOLD', 'retired_at' => null, 'barcode_value' => 'SYNTHETIC-1001'];
$returned = ['record_type' => 'RETURNED', 'event_type' => 'RETURNED_TO_SHOP', 'outcome' => 'RETURNED',
    'reason' => 'Every physical garment arrived', 'admin_id' => 7, 'created_at' => '2026-10-22 15:00:00',
    'delivery_revision' => 7, 'previous_state' => 'RETURN_TO_SELLER', 'resulting_state' => 'RETURNED_TO_SHOP'];
$inspected = array_replace($returned, ['record_type' => 'INSPECTED', 'event_type' => 'RETURN_INSPECTED',
    'outcome' => 'RETRY_APPROVED', 'reason' => 'Condition checked', 'delivery_revision' => 8, 'previous_state' => 'RETURNED_TO_SHOP']);
$key = str_repeat('a', 32);
$reason = 'Checked item for a separate stock disposition';
$state = compact('order', 'payment', 'shipment', 'item', 'product', 'returned', 'inspected');
$prefix = [
    ['^SELECT id FROM admins WHERE id = \? LOCK IN SHARE MODE$', [7], [['id' => 7]]],
    ['^SELECT .* FROM orders o WHERE o\.id = :order_id LIMIT 1 FOR UPDATE$', [':order_id' => 801], [$order]],
    ['^SELECT .* revision, NOW\(\) AS checked_at FROM payments WHERE order_id = :order_id LIMIT 1 FOR UPDATE$', [':order_id' => 801], [$payment]],
    ['^SELECT \* FROM shipments WHERE order_id = \? FOR UPDATE$', [801], [$shipment]],
    ['^SELECT id FROM sales_transactions WHERE order_id = \? FOR UPDATE$', [801], []],
    ['^SELECT \* FROM delivery_events WHERE action_key = \? ORDER BY event_index DESC FOR UPDATE$', [$key], []],
];
$releaseReads = [
    ['^SELECT \* FROM order_items WHERE order_id = \? ORDER BY id FOR UPDATE$', [801], [$item]],
    ['^SELECT id, status, retired_at FROM products WHERE id = \? FOR UPDATE$', [1001], [$product]],
    ['^SELECT id FROM sales_transaction_items WHERE product_id = \? OR order_item_id = \? FOR UPDATE$', [1001, 901], []],
    ['^SELECT oi\.id FROM order_items oi .* WHERE oi\.product_id = \? AND oi\.order_id <> \? AND \( .* \) LIMIT 1$', [1001, 801], []],
    ['^SELECT i\.\*, e\.event_type, e\.reason, e\.admin_id, e\.created_at, e\.delivery_revision, e\.previous_state, e\.resulting_state .* '
        . 'WHERE i\.order_id = \? AND i\.delivery_cycle = \? AND i\.order_item_id = \? AND i\.product_id = \? ORDER BY e\.id FOR UPDATE$', [801, 1, 901, 1001], [$returned, $inspected]],
];
$releaseCall = static fn (DeliveryGuardPDO $pdo, bool $preview = false, int $revision = 8, string $note = 'Checked item for a separate stock disposition',
    ?int $itemId = 901, ?string $outcome = null): array => delivery_action($pdo, 7, 801, 'make_available',
        1, $revision, 'RETURNED_TO_SHOP', $key, reason: $note, orderItemId: $itemId, inspectionOutcome: $outcome, validateOnly: $preview);

foreach ([false, true] as $preview) {
    $pdo = new DeliveryGuardPDO(array_merge($prefix, $releaseReads), $state);
    $before = hash('sha256', serialize($pdo->state));
    $result = $releaseCall($pdo, $preview);
    guardCheck('release ' . ($preview ? 'preview' : 'POST helper') . ' ALWAYS fails closed',
        [$result['ok'], $result['reason'], $result['status']], [false, 'stock_release_blocked', 409]);
    guardCheck('release uses ordered admin/order/payment/shipment/sale locks and rolls back',
        [$pdo->events, $pdo->queue, $pdo->violations, $pdo->inTransaction()], [['READ COMMITTED', 'begin', 'rollback'], [], [], false]);
    guardCheck('release preserves every synthetic record and history', hash('sha256', serialize($pdo->state)), $before);
    guardCheck('release explains unresolved own-order hold', str_contains(implode(' ', $result['blockers']), 'originating returned order'), true);
    guardCheck('schema explicitly lacks independent passed inspection and append-only disposition', str_contains($result['message'], 'independent passed stock-release inspection')
        && str_contains($result['message'], 'append-only inventory disposition'), true);
    guardCheck('unmigrated release requires the independent reservation/disposition ledger', str_contains($result['message'], 'additive inspection, reservation-resolution and stock-disposition ledger'), true);
    guardCheck('historical inspection evidence is preserved without treating it as stock approval', str_contains($result['message'], 'Valid item-level inspection'), false);
}

foreach ([
    ['physical state not confirmed', 'shipment', ['shipment_status' => 'RETURN_TO_SELLER'], 'Physical return must be explicitly confirmed'],
    ['delivered timestamp contradicts return', 'shipment', ['delivered_at' => '2026-10-22 16:00:00'], 'Physical return must be explicitly confirmed'],
    ['missing physical evidence', 'evidence', [$inspected], 'physical return evidence is missing or invalid'],
    ['missing item inspection', 'evidence', [$returned], 'Valid item-level inspection'],
    ['manual review', 'evidence', [$returned, array_replace($inspected, ['outcome' => 'MANUAL_REVIEW'])], 'under MANUAL_REVIEW'],
    ['invalid inspection outcome', 'evidence', [$returned, array_replace($inspected, ['outcome' => 'PASSED'])], 'Valid item-level inspection'],
    ['inspection before custody', 'evidence', [$returned, array_replace($inspected, ['delivery_revision' => 6])], 'Valid item-level inspection'],
    ['same revision as custody', 'evidence', [$returned, array_replace($inspected, ['delivery_revision' => 7])], 'Valid item-level inspection'],
    ['future inspection revision', 'evidence', [$returned, array_replace($inspected, ['delivery_revision' => 9])], 'Valid item-level inspection'],
    ['zero inspection revision', 'evidence', [$returned, array_replace($inspected, ['delivery_revision' => 0])], 'Valid item-level inspection'],
    ['inspection lacks administrator', 'evidence', [$returned, array_replace($inspected, ['admin_id' => 0])], 'Valid item-level inspection'],
    ['inspection has invalid date', 'evidence', [$returned, array_replace($inspected, ['created_at' => '2026-02-30 15:00:00'])], 'Valid item-level inspection'],
    ['inspection lacks note', 'evidence', [$returned, array_replace($inspected, ['reason' => '  '])], 'Valid item-level inspection'],
    ['inspection wrong predecessor', 'evidence', [$returned, array_replace($inspected, ['previous_state' => 'SHIPPED'])], 'Valid item-level inspection'],
    ['return wrong predecessor', 'evidence', [array_replace($returned, ['previous_state' => 'SHIPPED']), $inspected], 'physical return evidence is missing or invalid'],
    ['retired garment', 'product', ['retired_at' => '2026-10-22 16:00:00'], 'retired or disposed'],
    ['non-SOLD garment', 'product', ['status' => 'AVAILABLE'], 'not in the expected unavailable SOLD hold'],
    ['other active hold', 'hold', [['id' => 88]], 'Another unresolved order holds'],
    ['order sale header', 'sale', [['id' => 77]], 'A committed sale exists'],
    ['product or item sale membership', 'membership', [['id' => 99]], 'A committed sale exists'],
    ['paid GCash obligations', 'payment', ['payment_method' => 'GCASH', 'payment_status' => 'PAID', 'verified_by' => 7, 'verified_at' => '2026-10-22 09:00:00'], 'Payment obligations are unresolved'],
    ['collected COD obligations', 'payment', ['payment_status' => 'PAID', 'verified_by' => 7, 'verified_at' => '2026-10-22 09:00:00'], 'Payment obligations are unresolved'],
    ['missing payment', 'payment', false, 'Payment obligations are unresolved'],
] as [$label, $kind, $changes, $blocker]) {
    $locked = $prefix;
    $release = $releaseReads;
    switch ($kind) {
        case 'shipment': $locked[3][2] = [array_replace($shipment, $changes)]; break;
        case 'payment': $locked[2][2] = $changes === false ? [] : [array_replace($payment, $changes)]; break;
        case 'product': $release[1][2] = [array_replace($product, $changes)]; break;
        case 'hold': $release[3][2] = $changes; break;
        case 'sale': $locked[4][2] = $changes; break;
        case 'membership': $release[2][2] = $changes; break;
        case 'evidence': $release[4][2] = $changes; break;
    }
    $pdo = new DeliveryGuardPDO(array_merge($locked, $release), $state);
    $result = $kind === 'shipment'
        ? delivery_action($pdo, 7, 801, 'make_available', 1, 8, $locked[3][2][0]['shipment_status'], $key, reason: $reason, orderItemId: 901)
        : $releaseCall($pdo);
    guardCheck($label . ' blocks release with actual explanation',
        [$result['ok'], $result['reason'], $result['status'], str_contains($result['message'], $blocker)], [false, 'stock_release_blocked', 409, true]);
    guardCheck($label . ' consumes only read guards and rolls back without mutations',
        [$pdo->events, $pdo->queue, $pdo->violations, $pdo->inTransaction(), $pdo->state],
        [['READ COMMITTED', 'begin', 'rollback'], [], [], false, $state]);
}

// Even an artificially resolved cancellation and uncollected payment cannot
// supply the schema's absent independent release inspection/disposition ledger.
$resolved = $prefix;
$resolved[1][2] = [array_replace($order, ['status' => 'CANCELLED', 'cancellation_status' => 'APPROVED'])];
$pdo = new DeliveryGuardPDO(array_merge($resolved, $releaseReads), $state);
$result = $releaseCall($pdo, true);
guardCheck('apparently resolved order still cannot manufacture successful stock release', [$result['ok'], $result['reason'], count($result['blockers'])], [false, 'stock_release_blocked', 1]);
guardCheck('resolved-order preview still ends in rollback with no writes', [$pdo->events, $pdo->violations, $pdo->queue], [['READ COMMITTED', 'begin', 'rollback'], [], []]);

foreach ([
    ['missing order item', 0, []],
    ['multi-quantity item', 0, [array_replace($item, ['quantity' => 2])]],
    ['null product identity', 0, [array_replace($item, ['product_id' => null])]],
    ['duplicate physical identity', 0, [$item, array_replace($item, ['id' => 902])]],
    ['missing current product', 1, []],
] as [$label, $at, $rows]) {
    $release = array_slice($releaseReads, 0, $at + 1);
    $release[$at][2] = $rows;
    $pdo = new DeliveryGuardPDO(array_merge($prefix, $release), $state);
    guardCheck($label . ' requires legacy identity review without release', $releaseCall($pdo)['reason'], 'legacy_item_review');
    guardCheck($label . ' never reaches history or stock writer', [$pdo->events, $pdo->queue, $pdo->violations, $pdo->inTransaction()], [['READ COMMITTED', 'begin', 'rollback'], [], [], false]);
}
foreach (['' , " \t\r\n", "\u{00a0}\u{2003}"] as $blank) {
    $pdo = new DeliveryGuardPDO();
    $result = $releaseCall($pdo, note: $blank);
    guardCheck('blank stock disposition reason rejected before DB access', [$result['ok'], $result['reason'], $result['status']], [false, 'reason_required', 422]);
    guardCheck('blank reason cannot start transaction or read records', [$pdo->events, $pdo->queries, $pdo->violations], [[], [], []]);
}
foreach ([[null, null], [0, null], [901, 'RETRY_APPROVED']] as [$id, $outcome]) {
    $pdo = new DeliveryGuardPDO();
    guardCheck('release requires a valid item identity and no retry outcome input', $releaseCall($pdo, itemId: $id, outcome: $outcome)['reason'], 'bad_request');
    guardCheck('malformed release request is rejected without DB access', [$pdo->events, $pdo->queries], [[], []]);
}
foreach ([false, true] as $preview) {
    $pdo = new DeliveryGuardPDO($prefix, $state);
    guardCheck('stale release revision is rejected before any item read', $releaseCall($pdo, $preview, 7)['reason'], 'conflict');
    guardCheck('stale release closes transaction and preserves state', [$pdo->events, $pdo->queue, $pdo->violations, $pdo->state], [['READ COMMITTED', 'begin', 'rollback'], [], [], $state]);
}
$missingAdmin = $prefix;
$missingAdmin[0][2] = [];
$pdo = new DeliveryGuardPDO([$missingAdmin[0]]);
guardCheck('release checks administrator exists before reading order', $releaseCall($pdo)['reason'], 'admin_required');
guardCheck('nonexistent administrator cannot read or write an order', [$pdo->events, $pdo->queue, $pdo->violations], [['READ COMMITTED', 'begin', 'rollback'], [], []]);
$collision = $prefix;
$collision[5][2] = [['order_id' => 801, 'request_hash' => str_repeat('b', 64)]];
$pdo = new DeliveryGuardPDO($collision, $state);
guardCheck('duplicate key with different payload fails before release guards', $releaseCall($pdo)['reason'], 'key_conflict');
guardCheck('duplicate key conflict rolls back without stock or history changes', [$pdo->events, $pdo->queue, $pdo->violations, $pdo->state], [['READ COMMITTED', 'begin', 'rollback'], [], [], $state]);
$historical = $prefix;
$historical[5][2] = [
    ['id' => 45, 'order_id' => 801, 'request_hash' => hash('sha256', json_encode(
        [7, 801, 'fail_attempt', 1, 5, 'OUT_FOR_DELIVERY', 3, 'Historical failure', null, null], JSON_THROW_ON_ERROR)),
        'resulting_state' => 'RETURN_TO_SELLER', 'delivery_cycle' => 1, 'delivery_revision' => 6],
];
$historical[5][2][] = array_replace($historical[5][2][0], ['id' => 44, 'resulting_state' => 'DELIVERY_ATTEMPT_FAILED']);
$historicalState = $state + ['historical_events' => $historical[5][2]];
$pdo = new DeliveryGuardPDO($historical, $historicalState);
$result = delivery_action($pdo, 7, 801, 'fail_attempt', 1, 5, 'OUT_FOR_DELIVERY', $key,
    attempt: 3, reason: 'Historical failure');
guardCheck('historical automatic-return replay preserves its original committed result',
    [$result['ok'], $result['idempotent'], $result['event_id'], $result['state']], [true, true, 45, 'RETURN_TO_SELLER']);
guardCheck('historical replay commits no writes and never rewrites the earlier automatic events',
    [$pdo->events, $pdo->queue, $pdo->violations, $pdo->state], [['READ COMMITTED', 'begin', 'commit'], [], [], $historicalState]);
$pdo = new DeliveryGuardPDO($historical, $historicalState);
$result = delivery_action($pdo, 7, 801, 'fail_attempt', 1, 5, 'OUT_FOR_DELIVERY', $key,
    attempt: 3, reason: 'Historical failure', validateOnly: true);
guardCheck('read-only preview does not treat old committed key as current eligibility', $result['reason'], 'conflict');
guardCheck('stale historical preview rolls back and leaves old events intact',
    [$pdo->events, $pdo->queue, $pdo->violations, $pdo->state], [['READ COMMITTED', 'begin', 'rollback'], [], [], $historicalState]);
$pdo = new DeliveryGuardPDO();
$error = null;
try { delivery_stock_release_check_locked($pdo, $order, $payment, $shipment, 901, false); } catch (LogicException $e) { $error = $e->getMessage(); }
guardCheck('stock release checker requires caller-owned transaction', $error, 'Stock disposition checks require the order transaction.');
guardCheck('missing release transaction performs no reads', $pdo->queries, []);

// Full guard previews validate failure exhaustion and explicit return separately.
foreach (['fail_attempt', 'return_to_seller'] as $action) {
    $current = array_replace($shipment, ['shipment_status' => $action === 'fail_attempt' ? 'OUT_FOR_DELIVERY' : 'DELIVERY_ATTEMPT_FAILED',
        'delivery_revision' => $action === 'fail_attempt' ? 5 : 6, 'failed_attempts' => $action === 'fail_attempt' ? 2 : 3]);
    $locked = $prefix;
    $locked[3][2] = [$current];
    $guard = [
        ['^SELECT delivery_cycle, delivery_revision, resulting_state FROM delivery_events WHERE order_id = \? ORDER BY id DESC LIMIT 1 FOR UPDATE$', [801], [
            ['delivery_cycle' => 1, 'delivery_revision' => $current['delivery_revision'], 'resulting_state' => $current['shipment_status']]]],
        ["^SELECT attempt_number FROM delivery_events WHERE order_id = \\? AND delivery_cycle = \\? AND event_type = 'DELIVERY_ATTEMPT_FAILED' ORDER BY attempt_number FOR UPDATE$", [801, 1],
            array_map(static fn ($n) => [$n], range(1, $current['failed_attempts']))],
        $releaseReads[0],
        ['^SELECT id, status, retired_at, barcode_value FROM products WHERE id IN \(\?\) ORDER BY id FOR UPDATE$', [1001], [$product]],
        ['^SELECT id FROM sales_transaction_items WHERE product_id IN \(\?\) FOR UPDATE$', [1001], []],
        ['^SELECT id FROM sales_transaction_items WHERE order_item_id = \? FOR UPDATE$', [901], []],
        $releaseReads[3],
        ['^SELECT \* FROM order_item_verifications WHERE order_id = \? ORDER BY order_item_id FOR UPDATE$', [801], [
            ['verified_by' => 7, 'verified_at' => '2026-10-22 10:00:00', 'method' => 'MANUAL', 'reason' => 'Identified garment', 'product_id' => 1001, 'order_item_id' => 901]]],
        ['^SELECT i\.\*, e\.order_id AS event_order_id, .* WHERE i\.order_id = \? AND i\.delivery_cycle = \? FOR UPDATE$', [801, 1], []],
    ];
    $pdo = new DeliveryGuardPDO(array_merge($locked, $guard), $state);
    $result = delivery_action($pdo, 7, 801, $action, 1, $current['delivery_revision'], $current['shipment_status'], $key,
        attempt: $action === 'fail_attempt' ? 3 : null, reason: 'Actual courier evidence', validateOnly: true);
    guardCheck('full locked preview ' . $action . ' predicts only its explicit transition',
        [$result['ok'], $result['state'] ?? null, $result['failed_attempts'] ?? null],
        [true, $action === 'fail_attempt' ? 'DELIVERY_ATTEMPT_FAILED' : 'RETURN_TO_SELLER', 3]);
    guardCheck($action . ' preview rolls back BEFORE shipment/event/notification writes', [$pdo->events, $pdo->queue, $pdo->violations, $pdo->state], [['READ COMMITTED', 'begin', 'rollback'], [], [], $state]);
}

echo "{$checks} checks, {$failures} failures. Memory-only guards; no DB, successful release, or real concurrency claim.\n";
exit($failures === 0 ? 0 : 1);
