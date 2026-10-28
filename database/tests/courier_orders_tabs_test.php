<?php

declare(strict_types=1);

// CLI-only, DB-free. Execute the real list controller/body, excluding session,
// database/bootstrap and shared head/header/footer. Fixtures declare query results;
// this is a SQL/binding/rendering contract test, NOT a SQL-engine membership test.
if (PHP_SAPI !== 'cli') exit(1);
if ($argc !== 1) exit(2);
require_once __DIR__ . '/../../includes/order_queues.php';
require_once __DIR__ . '/../../includes/order_verify.php';
require_once __DIR__ . '/../../includes/admin_delivery.php';
require_once __DIR__ . '/../../includes/ui.php';
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = $failures = 0;
function courierTabsCheck(string $label, bool $pass, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if (!$pass) $failures++;
    echo ($pass ? 'ok ' : 'FAIL ') . $label . ($pass || $detail === '' ? '' : ': ' . $detail) . PHP_EOL;
}

function courierTabsSql(string $sql): string
{
    return trim((string) preg_replace('/\s+/', ' ', $sql));
}

/** Finite, ordered reads only. No fallback rows, implicit metadata or PDO connection. */
final class CourierTabsPDO extends PDO
{
    public array $reads = [];
    public array $transactions = [];
    private int $position = 0;
    private bool $active = false;
    private int $settings = 0;

    public function __construct(private array $steps) {}

    public function exec(string $statement): int|false
    {
        $allowed = ['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'SET TRANSACTION READ ONLY'];
        if ($this->active || ($allowed[$this->settings] ?? null) !== $statement) {
            throw new RuntimeException('Unexpected transaction setting/write: ' . $statement);
        }
        $this->settings++;
        $this->transactions[] = $statement;
        return 0;
    }

    public function beginTransaction(): bool
    {
        if ($this->active || $this->settings !== 2) throw new RuntimeException('Read-only settings must precede snapshot');
        $this->active = true;
        $this->transactions[] = 'BEGIN';
        return true;
    }

    public function inTransaction(): bool { return $this->active; }

    public function commit(): bool
    {
        if (!$this->active || count($this->reads) !== $this->position
            || ($this->steps[$this->position]['transaction'] ?? false)) {
            throw new RuntimeException('Snapshot committed before declared count/data reads completed');
        }
        $this->active = false;
        $this->transactions[] = 'COMMIT';
        return true;
    }

    public function rollBack(): bool { throw new RuntimeException('Unexpected rollback'); }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        throw new RuntimeException('Undeclared direct/metadata query: ' . $query);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $step = $this->steps[$this->position] ?? null;
        if ($step === null || $options !== [] || !str_starts_with(courierTabsSql($query), 'SELECT ')
            || !in_array(courierTabsSql($query), array_map('courierTabsSql', (array) $step['sql']), true)
            || $this->active !== $step['transaction'] || count($this->reads) !== $this->position) {
            throw new RuntimeException('Undeclared SQL/snapshot at read ' . $this->position . ': ' . courierTabsSql($query));
        }
        $index = $this->position++;
        return new class($this, $step, $query, $index) extends PDOStatement {
            private array $bound = [];
            private array $types = [];
            private bool $executed = false;
            private int $cursor = 0;
            public function __construct(private CourierTabsPDO $pdo, private array $step, private string $sql, private int $index) {}
            public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
            {
                if ($this->executed || array_key_exists($param, $this->bound)) throw new RuntimeException('Repeated/late binding');
                $this->bound[$param] = $value;
                $this->types[$param] = $type;
                return true;
            }
            public function execute(?array $params = null): bool
            {
                $actual = $params ?? $this->bound;
                $expected = $this->step['params'];
                ksort($actual);
                ksort($expected);
                $types = $this->types;
                $expectedTypes = $this->step['types'] ?? [];
                ksort($types);
                ksort($expectedTypes);
                if ($this->executed || ($params !== null && $this->bound !== []) || $actual !== $expected || $types !== $expectedTypes) {
                    throw new RuntimeException('Unexpected bindings/types at read ' . $this->index . ': ' . json_encode([$actual, $types]));
                }
                $this->executed = true;
                $this->pdo->reads[] = ['sql' => $this->sql, 'params' => $actual, 'types' => $types];
                return true;
            }
            private function rows(): array
            {
                if (!$this->executed) throw new RuntimeException('Fetch before execute');
                return $this->step['rows'];
            }
            public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
            {
                if ($mode !== PDO::FETCH_DEFAULT || $cursorOrientation !== PDO::FETCH_ORI_NEXT || $cursorOffset !== 0) throw new RuntimeException('Undeclared fetch mode');
                return $this->rows()[$this->cursor++] ?? false;
            }
            public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
            {
                if ($mode !== PDO::FETCH_DEFAULT || $args !== []) throw new RuntimeException('Undeclared fetchAll mode');
                $rows = array_slice($this->rows(), $this->cursor);
                $this->cursor = count($this->rows());
                return $rows;
            }
            public function fetchColumn(int $column = 0): mixed
            {
                if ($column !== 0) throw new RuntimeException('Undeclared column');
                $row = $this->rows()[$this->cursor++] ?? null;
                return $row === null ? false : array_values($row)[0];
            }
        };
    }

    public function assertConsumed(): void
    {
        if ($this->active || $this->position !== count($this->steps) || count($this->reads) !== count($this->steps)) {
            throw new RuntimeException('Unconsumed fixture query or open snapshot');
        }
    }
}

$phaseStates = [
    'SHIPPED' => ['SHIPPED'],
    'IN_TRANSIT' => ['IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED'],
    'RETURNS' => ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'],
    'RETURN_PENDING' => ['DELIVERY_ATTEMPT_FAILED'],
];
$phaseSql = [
    'SHIPPED' => "s.shipment_status IN ('SHIPPED') AND s.failed_attempts = 0",
    'IN_TRANSIT' => "s.shipment_status IN ('IN_TRANSIT','OUT_FOR_DELIVERY','DELIVERY_ATTEMPT_FAILED') AND s.failed_attempts BETWEEN 0 AND 2 AND (s.shipment_status <> 'DELIVERY_ATTEMPT_FAILED' OR s.failed_attempts >= 1)",
    'RETURNS' => "s.shipment_status IN ('RETURN_TO_SELLER','RETURNED_TO_SHOP') AND s.failed_attempts = 3",
    'RETURN_PENDING' => "s.shipment_status IN ('DELIVERY_ATTEMPT_FAILED') AND s.failed_attempts = 3",
];
foreach ($phaseStates as $phase => $states) {
    courierTabsCheck($phase . ' fixed persisted machine states', delivery_phase_states($phase) === $states);
    courierTabsCheck($phase . ' actual fixed-s SQL/counter predicate', delivery_phase_predicate($phase) === $phaseSql[$phase]);
}
foreach (['In Transit', "SHIPPED') OR 1=1 --", 'UNKNOWN'] as $invalid) {
    foreach (['delivery_phase_states', 'delivery_phase_predicate'] as $helper) {
        try { $helper($invalid); $rejected = false; } catch (InvalidArgumentException $error) { $rejected = true; }
        courierTabsCheck($helper . ' rejects non-machine phase ' . $invalid, $rejected);
    }
}

$courier = "(s.delivery_method IS NULL OR s.delivery_method = 'LOCAL_COURIER')";
$active = "o.cancellation_status NOT IN ('REQUESTED', 'APPROVED')";
$notReturned = "COALESCE(s.shipment_status, '') NOT IN ('RETURN_TO_SELLER','RETURNED_TO_SHOP')";
$predicates = [
    'online_pending' => $courier . " AND o.status = 'PENDING' AND " . $notReturned . ' AND ' . $active,
    'online_confirmed' => $courier . " AND o.status = 'CONFIRMED' AND " . $active . ' AND ' . $notReturned,
    'online_packed' => $courier . " AND o.status = 'PACKED' AND " . $active . ' AND ' . $notReturned,
    'online_shipped' => $courier . " AND o.status = 'SHIPPED' AND " . $active . ' AND ' . $phaseSql['SHIPPED'],
    'online_in_transit' => $courier . " AND o.status = 'SHIPPED' AND " . $active . ' AND ' . $phaseSql['IN_TRANSIT'],
    'online_returns' => $courier . ' AND ' . $phaseSql['RETURNS'],
    'online_return_decision' => $courier . " AND o.status = 'SHIPPED' AND " . $active . ' AND ' . $phaseSql['RETURN_PENDING'],
    'online_pack_dispatch' => $courier . " AND o.status IN ('CONFIRMED', 'PACKED') AND " . $active
        . " AND NOT (COALESCE(p.payment_method, '') = 'GCASH' AND COALESCE(p.payment_status, '') = 'PENDING' AND (TRIM(COALESCE(p.gcash_reference, '')) <> '' OR p.submitted_at IS NOT NULL))",
    'online_delivered_receipt' => $courier . " AND o.status = 'DELIVERED' AND s.shipment_status = 'DELIVERED' AND o.received_at IS NULL AND " . $active,
    'online_cod_collection' => $courier . " AND s.order_id IS NOT NULL AND s.shipment_status = 'DELIVERED' AND o.status = 'DELIVERED' AND o.received_at IS NOT NULL AND " . $active
        . " AND p.payment_method = 'COD' AND p.payment_status = 'PENDING' AND p.verified_at IS NULL AND p.verified_by IS NULL AND NOT EXISTS (SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id)",
    'online_gcash_completion' => $courier . " AND s.order_id IS NOT NULL AND s.shipment_status = 'DELIVERED' AND o.status = 'DELIVERED' AND o.received_at IS NOT NULL AND " . $active
        . " AND p.payment_method = 'GCASH' AND p.payment_status = 'PAID' AND p.verified_at IS NOT NULL AND p.verified_by IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id)",
    'online_completed' => $courier . " AND o.status = 'DELIVERED' AND o.received_at IS NOT NULL AND s.shipment_status = 'DELIVERED' AND p.payment_status = 'PAID' AND EXISTS (SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id AND st.sale_channel = 'ONLINE') AND " . $active,
    'online_cancellation' => $courier . " AND o.cancellation_status = 'REQUESTED' AND o.status <> 'CANCELLED'",
];
$definitions = order_list_filter_definitions('orders');
$labels = ['online_pending' => 'Pending', 'online_confirmed' => 'Confirmed', 'online_packed' => 'Packed',
    'online_shipped' => 'Shipped', 'online_in_transit' => 'In Transit', 'online_returns' => 'Returns'];
courierTabsCheck('primary tabs follow All in fixed lifecycle order', array_slice(array_keys($definitions), 1, 6) === array_keys($labels));
foreach ($labels + ['online_return_decision' => 'Return Decision Pending', 'online_pack_dispatch' => 'Orders to Pack / Dispatch', 'online_completed' => 'Completed'] as $key => $label) {
    courierTabsCheck($key . ' retains distinct friendly tab label', ($definitions[$key]['label'] ?? null) === $label);
}
foreach ($predicates as $key => $sql) {
    $actual = order_list_filter_where('orders', $key);
    courierTabsCheck($key . ' exact actual predicate and bindings', $actual !== null
        && courierTabsSql($actual['sql']) === courierTabsSql($sql) && $actual['params'] === [], $actual['sql'] ?? 'missing');
    parse_str((string) parse_url($definitions[$key]['href'] ?? '', PHP_URL_QUERY), $link);
    courierTabsCheck($key . ' links to canonical allowlisted courier tab', ($link['queue'] ?? null) === $key
        && ($link['fulfillment'] ?? null) === 'LOCAL_COURIER' && parse_url($definitions[$key]['href'], PHP_URL_PATH) === 'orders.php');
    if (!empty($definitions[$key]['queue'])) {
        courierTabsCheck($key . ' preserves shared supplemental/dashboard rule', $actual === order_queue_where($key));
    }
}
foreach (['all', 'online_shipped_in_transit', 'pickup_to_prepare', "online_returns' OR 1=1", 'unknown'] as $key) {
    courierTabsCheck('unknown/nonpredicate courier filter rejected: ' . $key, order_list_filter_where('orders', $key) === null);
}

/** Tabs and SQL result columns are explicit fixture metadata, never inferred from production SQL. */
function courierTabsFixture(int $id, string $status, string $shipment, int $failed, array $tabs, array $overrides = [], ?string $saleChannel = null): array
{
    return ['row' => array_replace([
        'id' => $id, 'status' => $status, 'cancellation_status' => 'NONE', 'subtotal' => '500.00',
        'shipping_fee' => '80.00', 'total_amount' => '580.00', 'created_at' => '2026-10-22 09:00:00',
        'received_at' => null, 'has_completed_sale' => 0, 'delivery_method' => 'LOCAL_COURIER',
        'shipment_status' => $shipment, 'delivery_cycle' => 1, 'delivery_revision' => 8, 'failed_attempts' => $failed,
        'customer_name' => 'Offline Customer <name>', 'payment_method' => 'COD', 'payment_status' => 'PENDING',
        'gcash_reference' => null, 'submitted_at' => null,
    ], $overrides), 'tabs' => $tabs, 'sale_channel' => $saleChannel,
        'verification' => ['expected' => 2, 'verified' => 1]];
}

$fixtures = [
    courierTabsFixture(101, 'PENDING', 'NOT_SHIPPED', 0, ['all', 'online_pending'], ['delivery_method' => null]),
    courierTabsFixture(102, 'PENDING', 'NOT_SHIPPED', 0, ['all', 'online_pending']),
    courierTabsFixture(103, 'CONFIRMED', 'READY_TO_SHIP', 0, ['all', 'online_confirmed', 'online_pack_dispatch']),
    courierTabsFixture(104, 'PACKED', 'READY_TO_SHIP', 0, ['all', 'online_packed', 'online_pack_dispatch']),
    courierTabsFixture(105, 'CONFIRMED', 'READY_TO_SHIP', 0, ['all', 'online_confirmed'],
        ['payment_method' => 'GCASH', 'gcash_reference' => 'SUBMITTED-TEST']),
    courierTabsFixture(106, 'SHIPPED', 'SHIPPED', 0, ['all', 'online_shipped']),
    courierTabsFixture(107, 'SHIPPED', 'SHIPPED', 1, ['all']),
    courierTabsFixture(108, 'SHIPPED', 'SHIPPED', 0, ['all', 'online_cancellation'], ['cancellation_status' => 'REQUESTED']),
    courierTabsFixture(109, 'SHIPPED', 'OUT_FOR_DELIVERY', 1, ['all'], ['cancellation_status' => 'APPROVED']),
    courierTabsFixture(110, 'DELIVERED', 'DELIVERED', 0, ['all', 'online_delivered_receipt']),
    courierTabsFixture(111, 'DELIVERED', 'DELIVERED', 0, ['all', 'online_cod_collection'], ['received_at' => '2026-10-22 12:00:00']),
    courierTabsFixture(112, 'DELIVERED', 'DELIVERED', 0, ['all', 'online_gcash_completion'],
        ['received_at' => '2026-10-22 12:00:00', 'payment_method' => 'GCASH', 'payment_status' => 'PAID']),
    courierTabsFixture(113, 'DELIVERED', 'DELIVERED', 0, ['all', 'online_completed'],
        ['received_at' => '2026-10-22 12:00:00', 'payment_status' => 'PAID'], 'ONLINE'),
    courierTabsFixture(114, 'DELIVERED', 'DELIVERED', 0, ['all'],
        ['received_at' => '2026-10-22 12:00:00', 'payment_status' => 'PAID'], 'WALK_IN'),
    courierTabsFixture(115, 'DELIVERED', 'DELIVERED', 0, ['all', 'online_delivered_receipt'], ['payment_status' => 'PAID'], 'ONLINE'),
    courierTabsFixture(116, 'SHIPPED', 'DELIVERED', 0, ['all'], ['received_at' => '2026-10-22 12:00:00', 'payment_status' => 'PAID'], 'ONLINE'),
    courierTabsFixture(117, 'DELIVERED', 'OUT_FOR_DELIVERY', 0, ['all'], ['received_at' => '2026-10-22 12:00:00', 'payment_status' => 'PAID'], 'ONLINE'),
    courierTabsFixture(118, 'DELIVERED', 'DELIVERED', 0, ['all'], ['received_at' => '2026-10-22 12:00:00'], 'ONLINE'),
    courierTabsFixture(119, 'CANCELLED', 'RETURNED_TO_SHOP', 3, ['all', 'online_returns'], ['cancellation_status' => 'APPROVED']),
    courierTabsFixture(120, 'PENDING', 'NOT_SHIPPED', 0, [], ['delivery_method' => 'STORE_PICKUP']),
    courierTabsFixture(121, 'SHIPPED', 'OUT_FOR_DELIVERY', 0, [], ['delivery_method' => 'STORE_PICKUP']),
    courierTabsFixture(122, 'SHIPPED', 'RETURNED_TO_SHOP', 3, [], ['delivery_method' => 'STORE_PICKUP']),
    courierTabsFixture(123, 'DELIVERED', 'DELIVERED', 0, [], ['delivery_method' => 'STORE_PICKUP', 'received_at' => '2026-10-22 12:00:00', 'payment_status' => 'PAID'], 'ONLINE'),
];
$id = 130;
foreach (['IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED'] as $state) {
    for ($failed = 0; $failed <= 4; $failed++) {
        $tabs = ['all'];
        if ($failed <= 2 && ($state !== 'DELIVERY_ATTEMPT_FAILED' || $failed >= 1)) $tabs[] = 'online_in_transit';
        if ($state === 'DELIVERY_ATTEMPT_FAILED' && $failed === 3) $tabs[] = 'online_return_decision';
        $fixtures[] = courierTabsFixture($id++, 'SHIPPED', $state, $failed, $tabs);
    }
}
foreach (['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'] as $state) {
    foreach (['PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED'] as $oldStatus) {
        $tabs = ['all', 'online_returns'];
        // The old supplemental pack/dispatch predicate is intentionally preserved.
        if (in_array($oldStatus, ['CONFIRMED', 'PACKED'], true)) $tabs[] = 'online_pack_dispatch';
        $fixtures[] = courierTabsFixture($id++, $oldStatus, $state, 3, $tabs,
            ['received_at' => '2026-10-22 12:00:00', 'payment_status' => 'PAID']);
    }
    $fixtures[] = courierTabsFixture($id++, 'SHIPPED', $state, 2, ['all']);
    $fixtures[] = courierTabsFixture($id++, 'SHIPPED', $state, 4, ['all']);
}

$source = file_get_contents(__DIR__ . '/../../admin/orders.php');
$controllerStart = strpos($source, '$orderListFilters =');
$controllerEnd = strpos($source, '$page_title =');
$bodyStart = strpos($source, '<div class="admin-orders"');
$bodyEnd = strpos($source, "<?php require __DIR__ . '/../includes/ui.footer.php'; ?>");
if ($controllerStart === false || $controllerEnd === false || $bodyStart === false || $bodyEnd === false) {
    throw new RuntimeException('Actual admin Orders controller/body boundary changed');
}
$controller = substr($source, $controllerStart, $controllerEnd - $controllerStart);
$body = substr($source, $bodyStart, $bodyEnd - $bodyStart);
// Fail closed if future code adds bootstrap/includes or side effects inside either slice.
if (preg_match('/\b(?:require|include)(?:_once)?\b|session_start\s*\(|new\s+PDO\b|file_put_contents\s*\(/i', $controller . $body)) {
    throw new RuntimeException('Unexpected include/bootstrap/side effect inside isolated renderer');
}

function courierTabsDocument(string $html): DOMXPath
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<meta charset="utf-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}

function courierTabsIds(DOMXPath $xpath): array
{
    $ids = [];
    foreach ($xpath->query('//article[@class="admin-order-card"]//h2') as $node) $ids[] = (int) ltrim(trim($node->textContent), '#');
    return $ids;
}

function courierTabsRender(array $fixtures, array $get, string $selected = 'all', ?string $status = null,
    string $search = '', int $expectedPage = 1): array
{
    global $predicates, $controller, $body;
    $counts = ['all_count' => 0];
    foreach ($predicates as $key => $sql) $counts[$key] = 0;
    $selectedFixtures = [];
    foreach ($fixtures as $fixture) {
        foreach ($fixture['tabs'] as $key) {
            if ($key === 'all') $counts['all_count']++;
            else $counts[$key]++;
        }
        if (in_array($selected, $fixture['tabs'], true)
            && ($status === null || ($fixture['row']['status'] === $status
                && ($status !== 'DELIVERED' || $fixture['row']['shipment_status'] === 'DELIVERED')))) $selectedFixtures[] = $fixture;
    }
    usort($selectedFixtures, static fn ($a, $b) => [$b['row']['created_at'], $b['row']['id']] <=> [$a['row']['created_at'], $a['row']['id']]);
    $total = count($selectedFixtures);
    $offset = ($expectedPage - 1) * 24;
    $pageFixtures = array_slice($selectedFixtures, $offset, 24);
    $from = ' FROM orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN shipments s ON s.order_id = o.id LEFT JOIN payments p ON p.order_id = o.id';
    $base = '(s.delivery_method IS NULL OR s.delivery_method = :fulfillment)';
    $params = [':fulfillment' => 'LOCAL_COURIER'];
    if ($search !== '') {
        $base .= " AND (CAST(o.id AS CHAR) LIKE :search_order OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE :search_customer)";
        $params[':search_order'] = $params[':search_customer'] = sales_monitor_like_param($search);
    }
    $columns = ['COUNT(*) AS all_count'];
    foreach ($predicates as $key => $sql) $columns[] = 'SUM(CASE WHEN (' . $sql . ') THEN 1 ELSE 0 END) AS ' . $key;
    $aggregate = $counts;
    if ($counts['all_count'] === 0) {
        foreach ($predicates as $key => $sql) $aggregate[$key] = null;
    }
    $steps = [['sql' => 'SELECT ' . implode(', ', $columns) . $from . ' WHERE ' . $base,
        'params' => $params, 'rows' => [$aggregate], 'transaction' => true]];
    $where = $base;
    if ($selected !== 'all') $where .= ' AND (' . $predicates[$selected] . ')';
    elseif ($status !== null) {
        $where .= ' AND o.status = :status';
        $params[':status'] = $status;
        if ($status === 'DELIVERED') $where .= " AND s.shipment_status = 'DELIVERED'";
        $steps[] = ['sql' => 'SELECT COUNT(*)' . $from . ' WHERE ' . $where,
            'params' => $params, 'rows' => [['count' => $total]], 'transaction' => true];
    }
    $projection = 'SELECT o.id, o.status, o.cancellation_status, o.subtotal, o.shipping_fee, o.total_amount, o.created_at, o.received_at, %s AS has_completed_sale, s.delivery_method, s.shipment_status, s.delivery_cycle, s.delivery_revision, s.failed_attempts, CONCAT(c.first_name, \' \', c.last_name) AS customer_name, p.payment_method, p.payment_status, p.gcash_reference, p.submitted_at'
        . $from . ' WHERE ' . $where . ' ORDER BY o.created_at DESC, o.id DESC LIMIT :limit OFFSET :offset';
    $onlineSale = "EXISTS(SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id AND st.sale_channel = 'ONLINE')";
    $rows = [];
    foreach ($pageFixtures as $fixture) {
        $row = $fixture['row'];
        $row['has_completed_sale'] = (int) ($fixture['sale_channel'] === 'ONLINE');
        $rows[] = $row;
    }
    $dataParams = $params + [':limit' => 24, ':offset' => $offset];
    $types = array_fill_keys(array_keys($params), PDO::PARAM_STR) + [':limit' => PDO::PARAM_INT, ':offset' => PDO::PARAM_INT];
    $steps[] = ['sql' => sprintf($projection, $onlineSale),
        'params' => $dataParams, 'types' => $types, 'rows' => $rows, 'transaction' => true];
    foreach ($pageFixtures as $fixture) {
        $row = $fixture['row'];
        if (!in_array($row['status'], ['CONFIRMED', 'PACKED'], true)
            || in_array($row['cancellation_status'], ['REQUESTED', 'APPROVED'], true)) continue;
        $verification = $fixture['verification'];
        foreach ([
            'expected' => 'SELECT COUNT(*) FROM order_items WHERE order_id = :order_id',
            'verified' => 'SELECT COUNT(*) FROM order_item_verifications v INNER JOIN order_items oi ON oi.id = v.order_item_id WHERE oi.order_id = :order_id',
        ] as $column => $sql) {
            $steps[] = ['sql' => $sql, 'params' => [':order_id' => $row['id']],
                'rows' => [['count' => $verification[$column]]], 'transaction' => false];
        }
    }
    $pdo = new CourierTabsPDO($steps);
    $_GET = $get;
    $_SESSION = ['admin_id' => 7, 'admin_name' => 'Offline Admin <name>'];
    $orderLiveRequest = false;
    ob_start();
    try {
        eval($controller);
        $orderLiveVersion = hash('sha256', serialize([$orders, $orderTabCounts, $verifyProgressByOrder, $gcashStateByOrder]));
        eval('?>' . $body);
        $html = (string) ob_get_clean();
    } catch (Throwable $error) {
        ob_end_clean();
        throw $error;
    }
    $pdo->assertConsumed();
    return ['html' => $html, 'xpath' => courierTabsDocument($html), 'pdo' => $pdo, 'counts' => $counts,
        'ids' => array_column($rows, 'id'), 'total' => $total];
}

function courierTabsRun(string $label, callable $test): void
{
    try { $test(); } catch (Throwable $error) {
        courierTabsCheck($label . ' controller executes guarded fixture', false,
            $error->getMessage() . ' (' . $error->getFile() . ':' . $error->getLine() . ')');
    }
}

foreach (array_merge(['all'], array_keys($predicates)) as $key) {
    courierTabsRun($key, static function () use ($key, $fixtures, $definitions): void {
        $result = courierTabsRender($fixtures, $key === 'all' ? [] : ['queue' => $key], $key);
        $xpath = $result['xpath'];
        courierTabsCheck($key . ' renders declared machine-value membership, 24-row maximum', courierTabsIds($xpath) === $result['ids'] && count($result['ids']) <= 24);
        courierTabsCheck($key . ' highlights only selected tab', $xpath->query('//a[contains(@class,"orders-filter")][@aria-current="page"]')->length === 1
            && trim($xpath->evaluate('string(//a[@aria-current="page"]/span[@class="orders-filter__label"])')) === $definitions[$key]['label']);
        foreach ($definitions as $tab => $definition) {
            $expected = $tab === 'all' ? $result['counts']['all_count'] : $result['counts'][$tab];
            $position = array_search($tab, array_keys($definitions), true) + 1;
            courierTabsCheck($key . ' aggregate badge ' . $tab . ' matches exact fixture count',
                (int) $xpath->evaluate('string((//nav[contains(@class,"orders-filters")]//a[@role="listitem"])[' . $position . ']/span[@class="badge"])') === $expected);
        }
        courierTabsCheck($key . ' count/data share one read-only repeatable-read transaction', $result['pdo']->transactions === [
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'SET TRANSACTION READ ONLY', 'BEGIN', 'COMMIT']);
        courierTabsCheck($key . ' uses aggregate exact selected count, no separate selected COUNT',
            str_contains($result['html'], 'of ' . $result['total'] . ' Local Courier order') && str_contains($result['pdo']->reads[1]['sql'], 'LIMIT :limit OFFSET :offset'));
        $canonicalLinks = true;
        foreach ($result['ids'] as $id) {
            $canonicalLinks = $canonicalLinks && $xpath->query('//article[.//h2[normalize-space(.)="#' . $id . '"]]//a[@href="order-view.php?id=' . $id . '"]')->length === 1;
        }
        courierTabsCheck($key . ' canonical order detail links carry actual IDs',
            $canonicalLinks
            && $xpath->query('//article[@class="admin-order-card"]//a[contains(@href,"orders.php?id=") or contains(@href,"order-detail.php")]')->length === 0);
        if (in_array($key, ['online_pending', 'online_confirmed', 'online_packed', 'online_shipped', 'online_in_transit'], true)) {
            courierTabsCheck($key . ' actual primary card badge matches lifecycle tab',
                $xpath->query('//div[@class="admin-order-card__statuses"]/span[normalize-space(.)="' . $definitions[$key]['label'] . '"]')->length === count($result['ids']));
        }
        if ($key === 'online_return_decision') {
            courierTabsCheck('third failure remains failed delivery, not a manufactured return event',
                trim($xpath->evaluate('string(//dl//div[dt="Shipment status"]/dd/span)')) === 'Delivery Attempt Failed'
                && $xpath->query('//div[@class="admin-order-card__statuses"]/span[normalize-space(.)="Return to Seller" or normalize-space(.)="Returned to Shop"]')->length === 0);
        }
    });
}

foreach (['online_in_transit' => 'No orders currently in transit.', 'online_returns' => 'No returned orders.'] as $key => $copy) {
    courierTabsRun('empty ' . $key, static function () use ($key, $copy): void {
        $result = courierTabsRender([], ['queue' => $key], $key);
        courierTabsCheck($key . ' real empty tab copy and clear link', str_contains($result['html'], $copy)
            && $result['xpath']->query('//div[@class="empty-state"]//a[@href="orders.php"]')->length === 1
            && courierTabsIds($result['xpath']) === []);
        courierTabsCheck($key . ' empty aggregates normalize every badge to zero', $result['xpath']->query('//a[contains(@class,"orders-filter")]/span[@class="badge"][normalize-space(.)!="0"]')->length === 0);
    });
}

$paginationFixtures = [];
for ($id = 200; $id <= 248; $id++) $paginationFixtures[] = courierTabsFixture($id, 'SHIPPED', 'OUT_FOR_DELIVERY', 1, ['all', 'online_in_transit']);
$search = "  O'Neil 50%_\\ <&  ";
$trimmedSearch = trim($search);
courierTabsCheck('existing sales LIKE helper escapes literals independently', sales_monitor_like_param($search) === "%O'Neil 50\\%\\_\\\\ <&%");
courierTabsCheck('existing sales LIKE helper caps oversized search binding at 120 characters', sales_monitor_like_param(str_repeat('x', 140)) === '%' . str_repeat('x', 120) . '%');
courierTabsRun('searched page two', static function () use ($paginationFixtures, $search, $trimmedSearch, $definitions): void {
    // Fixtures are declared results for this search, not evaluated by the double.
    $result = courierTabsRender($paginationFixtures, ['queue' => 'online_in_transit', 'q' => $search, 'page' => '2'], 'online_in_transit', null, $trimmedSearch, 2);
    courierTabsCheck('search page two is exact 25-48 of 49 with stable ID tie-break', courierTabsIds($result['xpath']) === range(224, 201, -1)
        && str_contains($result['html'], 'Showing 25-48 of 49 Local Courier orders'));
    $read = $result['pdo']->reads[1];
    courierTabsCheck('search is bound separately to order/customer, never SQL-interpolated',
        $read['params'][':search_order'] === sales_monitor_like_param($search)
        && $read['params'][':search_customer'] === sales_monitor_like_param($search)
        && !str_contains($read['sql'], "O'Neil") && $read['params'][':limit'] === 24 && $read['params'][':offset'] === 24);
    $xpath = $result['xpath'];
    courierTabsCheck('search form preserves selected tab and resets page', $xpath->evaluate('string(//form/input[@name="queue"]/@value)') === 'online_in_transit'
        && $xpath->evaluate('string(//input[@name="q"]/@value)') === $trimmedSearch && $xpath->query('//form//input[@name="page"]')->length === 0);
    foreach ($definitions as $key => $definition) {
        $position = array_search($key, array_keys($definitions), true) + 1;
        $href = $xpath->evaluate('string((//nav[contains(@class,"orders-filters")]//a[@role="listitem"])[' . $position . ']/@href)');
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        courierTabsCheck('searched tab link ' . $key . ' preserves q and resets pagination', ($query['q'] ?? null) === $trimmedSearch
            && !isset($query['page']) && ($key === 'all' ? !isset($query['queue']) : ($query['queue'] ?? null) === $key));
    }
    foreach (['prev' => 1, 'next' => 3] as $rel => $page) {
        parse_str((string) parse_url($xpath->evaluate('string(//nav[@class="inv-pagination"]//a[@rel="' . $rel . '"]/@href)'), PHP_URL_QUERY), $query);
        courierTabsCheck('existing inv-pagination ' . $rel . ' keeps selected tab/search', $query === ['queue' => 'online_in_transit', 'q' => $trimmedSearch, 'page' => (string) $page]);
    }
    courierTabsCheck('search and customer/admin values are escaped text', str_contains($result['html'], 'O&#039;Neil') && str_contains($result['html'], 'Offline Customer &lt;name&gt;')
        && str_contains($result['html'], 'Offline Admin &lt;name&gt;') && $xpath->query('//script|//img')->length === 0);
});
foreach (['999' => 3, '0' => 1, '-1' => 1, 'nonnumeric' => 1] as $requested => $page) {
    courierTabsRun('page ' . $requested, static function () use ($paginationFixtures, $requested, $page): void {
        $result = courierTabsRender($paginationFixtures, ['queue' => 'online_in_transit', 'page' => $requested], 'online_in_transit', null, '', $page);
        courierTabsCheck('requested page ' . $requested . ' clamps to ' . $page, $result['pdo']->reads[1]['params'][':offset'] === ($page - 1) * 24
            && str_contains($result['html'], 'Page ' . $page . ' of 3'));
    });
}
foreach (['IN_TRANSIT' => 'online_in_transit', 'RETURNS' => 'online_returns', 'COMPLETED' => 'online_completed', 'SHIPPED' => 'online_shipped'] as $status => $key) {
    courierTabsRun('legacy status ' . $status, static function () use ($fixtures, $status, $key): void {
        $result = courierTabsRender($fixtures, ['status' => strtolower($status)], $key);
        courierTabsCheck('legacy status ' . $status . ' selects canonical tab without writing invented order state',
            str_contains($result['html'], 'name="queue" value="' . $key . '"') && !str_contains($result['html'], 'name="status"'));
    });
}
courierTabsRun('raw delivered status', static function () use ($fixtures): void {
    $result = courierTabsRender($fixtures, ['status' => 'DELIVERED'], 'all', 'DELIVERED');
    courierTabsCheck('raw Delivered compatibility uses exact separate scoped COUNT and data predicate', count($result['pdo']->reads) === 3
        && str_contains($result['pdo']->reads[1]['sql'], "o.status = :status AND s.shipment_status = 'DELIVERED'")
        && $result['pdo']->reads[1]['params'][':status'] === 'DELIVERED'
        && $result['xpath']->query('//a[contains(@class,"orders-filter")][@aria-current="page"]')->length === 0);
});
foreach ([['queue' => "x' OR 1=1 --", 'status' => 'UNKNOWN', 'fulfillment' => 'STORE_PICKUP'],
    ['queue' => [], 'status' => [], 'q' => [], 'fulfillment' => []]] as $get) {
    courierTabsRun('invalid request', static function () use ($get, $fixtures): void {
        $result = courierTabsRender($fixtures, $get);
        courierTabsCheck('invalid/array filters fall back safely to Local Courier all', $result['pdo']->reads[0]['params'] === [':fulfillment' => 'LOCAL_COURIER']
            && trim($result['xpath']->evaluate('string(//a[@aria-current="page"]/span[@class="orders-filter__label"])')) === 'All Local Courier Orders');
    });
}
courierTabsRun('searched empty returns', static function () use ($trimmedSearch): void {
    $result = courierTabsRender([], ['queue' => 'online_returns', 'q' => $trimmedSearch, 'page' => 9], 'online_returns', null, $trimmedSearch);
    courierTabsCheck('searched empty selected tab uses search-specific message and resets offset', str_contains($result['html'], 'No orders match this search in the selected tab.')
        && $result['pdo']->reads[1]['params'][':offset'] === 0 && !str_contains($result['html'], 'inv-pagination'));
});

foreach ($fixtures as $fixture) {
    $row = $fixture['row'];
    if (!in_array('online_returns', $fixture['tabs'], true) || $row['status'] === 'CANCELLED') continue;
    courierTabsRun('return badge ' . $row['id'], static function () use ($fixture, $row): void {
        $result = courierTabsRender([$fixture], ['queue' => 'online_returns'], 'online_returns');
        $label = $row['shipment_status'] === 'RETURN_TO_SELLER' ? 'Return to Seller' : 'Returned to Shop';
        courierTabsCheck($label . ' actual card overrides stale ' . $row['status'] . ' without Completed',
            $result['xpath']->query('//div[@class="admin-order-card__statuses"]/span[contains(@class,"badge-warning")][normalize-space(.)="' . $label . '"]')->length === 1
            && !str_contains($result['xpath']->evaluate('string(//div[@class="admin-order-card__statuses"])'), 'Completed'));
        courierTabsCheck($label . ' actual shipment detail retains return-specific label',
            trim($result['xpath']->evaluate('string(//dl//div[dt="Shipment status"]/dd/span)')) === $label);
    });
}
courierTabsRun('completed evidence', static function () use ($fixtures): void {
    $subset = array_values(array_filter($fixtures, static fn ($fixture) => in_array($fixture['row']['id'], [113, 114, 118], true)));
    $result = courierTabsRender($subset, []);
    courierTabsCheck('actual list completion projection requires ONLINE header like Completed tab',
        str_contains($result['pdo']->reads[1]['sql'], "st.sale_channel = 'ONLINE') AS has_completed_sale"));
    foreach ([113 => true, 114 => false, 118 => false] as $id => $completed) {
        $xpath = $result['xpath'];
        courierTabsCheck('actual card #' . $id . ' Completed only with ONLINE header and PAID receipt',
            ($xpath->query('//article[.//h2[normalize-space(.)="#' . $id . '"]]//div[@class="admin-order-card__statuses"]/span[normalize-space(.)="Completed"]')->length === 1) === $completed);
    }
});

foreach (['UPDATE orders SET status = \'SHIPPED\'', 'SELECT * FROM orders', 'SHOW COLUMNS FROM shipments'] as $sql) {
    $pdo = new CourierTabsPDO([]);
    try { $pdo->prepare($sql); $rejected = false; } catch (RuntimeException $error) { $rejected = true; }
    courierTabsCheck('memory guard rejects undeclared SQL/write/metadata: ' . $sql, $rejected);
}
$included = array_map(static fn ($file) => str_replace('\\', '/', $file), get_included_files());
courierTabsCheck('no database/env/vendor/bootstrap/head/header/footer loaded', count(array_filter($included,
    static fn ($file) => preg_match('~/(?:database\.php|ui\.(?:head|header|footer)\.php|vendor/)|/\.env(?:$|\.)~i', $file))) === 0);
echo "{$checks} checks, {$failures} failures. DB-free SQL/binding/real-renderer contracts; fixture membership is declared, not SQL-engine evaluation. No DB/env/harness/order writes.\n";
exit($failures === 0 ? 0 : 1);
