<?php

declare(strict_types=1);

// CLI-only, DB-free. PDO doubles do not connect or read .env. Render the real
// partial and order-detail body; no HTTP requests, sessions or business writes.
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../../includes/customer_delivery.php';
require_once __DIR__ . '/../../includes/ui.php';
require_once __DIR__ . '/../../includes/pickup.php';
require_once __DIR__ . '/../../includes/shipping.php';
require_once __DIR__ . '/../../includes/gcash.php';
require_once __DIR__ . '/../../includes/payment.php';
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
$failures = 0;
function customerDeliveryCheck(string $label, bool $pass): void
{
    global $checks, $failures;
    $checks++;
    if (!$pass) $failures++;
    echo ($pass ? 'ok ' : 'FAIL ') . $label . PHP_EOL;
}

class CustomerDeliveryPDO extends PDO
{
    public array $queries = [];
    public array $parameters = [];
    public bool $failRead = false;
    public function __construct(public array $rows, public int $owner = 7, public string $method = 'LOCAL_COURIER') {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        if ($this->failRead) throw new RuntimeException('PRIVATE SQL ERROR: credentials and schema');
        if (!str_contains($query, 'o.id = :order_id AND o.customer_id = :customer_id')
            || !str_contains($query, "s.delivery_method = 'LOCAL_COURIER'")
            || !str_contains($query, 'ORDER BY e.created_at ASC, e.id ASC')) {
            throw new RuntimeException('Missing scope or chronological SQL');
        }
        return new class($this) extends PDOStatement {
            public array $rows = [];
            public function __construct(private CustomerDeliveryPDO $pdo) {}
            public function execute(?array $params = null): bool
            {
                $this->pdo->parameters[] = $params;
                $this->rows = ($params[':order_id'] === 801 && $params[':customer_id'] === $this->pdo->owner
                    && $this->pdo->method === 'LOCAL_COURIER') ? $this->pdo->rows : [];
                usort($this->rows, static fn ($a, $b) => [$a['created_at'], (int) $a['id']] <=> [$b['created_at'], (int) $b['id']]);
                return true;
            }
            public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
        };
    }
}

function customerDeliveryRow(int $id, string $type, int $cycle = 1, ?int $attempt = null, ?string $date = null): array
{
    return ['id' => $id, 'shipment_status' => 'RETURNED_TO_SHOP', 'current_cycle' => 1,
        'current_revision' => 9, 'delivery_revision' => $id,
        'delivery_cycle' => $cycle, 'event_type' => $type, 'attempt_number' => $attempt,
        'reason' => $type === 'DELIVERY_ATTEMPT_FAILED' ? 'Recipient unavailable' : 'PRIVATE ADMIN NOTE',
        'resulting_state' => $type === 'DELIVERY_CYCLE_AUTHORIZED' ? 'SHIPPED'
            : ($type === 'RETURN_INSPECTED' ? 'RETURNED_TO_SHOP' : $type),
        'created_at' => $date ?? '2026-10-22 10:' . str_pad((string) $id, 2, '0', STR_PAD_LEFT) . ':00'];
}

function customerDeliveryRender(array $history, array $orderOverrides = []): string
{
    $courierHistory = $history;
    $order = array_replace(['id' => 801, 'status' => 'SHIPPED', 'received_at' => null, 'cancellation_status' => 'NONE'], $orderOverrides);
    $orderTimeline = customer_order_timeline($order, null, ['shipment_status' => $history['stage']], $history);
    $shipmentStatusBadgeClasses = ['OUT_FOR_DELIVERY' => 'badge-warning', 'DELIVERED' => 'badge-success'];
    ob_start();
    require __DIR__ . '/../../includes/ui.customer-delivery.php';
    return (string) ob_get_clean();
}

function customerDeliveryXPath(string $html): DOMXPath
{
    $dom = new DOMDocument();
    $prior = libxml_use_internal_errors(true);
    $dom->loadHTML('<meta charset="utf-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prior);
    return new DOMXPath($dom);
}

$ready = delivery_schema_result('ready');
$rows = [
    customerDeliveryRow(1, 'OUT_FOR_DELIVERY'), customerDeliveryRow(2, 'DELIVERY_ATTEMPT_FAILED', 1, 1),
    customerDeliveryRow(3, 'OUT_FOR_DELIVERY'), customerDeliveryRow(4, 'DELIVERY_ATTEMPT_FAILED', 1, 2),
    customerDeliveryRow(5, 'OUT_FOR_DELIVERY'), customerDeliveryRow(6, 'DELIVERY_ATTEMPT_FAILED', 1, 3),
    customerDeliveryRow(7, 'RETURN_TO_SELLER', 1, null, '2026-10-22 10:06:00'),
    customerDeliveryRow(8, 'RETURNED_TO_SHOP'), customerDeliveryRow(9, 'RETURN_INSPECTED'),
];
$pdo = new CustomerDeliveryPDO(array_reverse($rows));
$history = customer_delivery_history($pdo, 801, 7, $ready);
$html = customerDeliveryRender($history);
$xpath = customerDeliveryXPath($html);
$renderedTitles = [];
foreach ($xpath->query('//p[@class="od-courier__event-title"]') as $title) {
    $renderedTitles[] = trim($title->firstChild->textContent);
}
$publicRows = array_values(array_filter($rows, static fn ($row) => isset(customer_delivery_labels()[$row['event_type']])));
$expectedTitles = array_map(static fn ($row) => customer_delivery_labels()[$row['event_type']]
    . ($row['attempt_number'] !== null ? ' - Attempt ' . $row['attempt_number'] . ' of 3' : ''), $publicRows);
customerDeliveryCheck('events read and rendered chronologically, ID breaks equal-timestamp ties',
    array_column($history['cycles'][1], 'id') === array_map('strval', range(1, 8))
    && $renderedTitles === $expectedTitles);
customerDeliveryCheck('query binds authenticated owner and validated order', $pdo->parameters === [[':order_id' => 801, ':customer_id' => 7]]);
customerDeliveryCheck('one read snapshot for shipment and all event cycles', count($pdo->queries) === 1 && str_contains($pdo->queries[0], 'LEFT JOIN delivery_events'));
customerDeliveryCheck('only public projection, no admin identity or tokens',
    !preg_match('/SELECT\s+\*|admin_id|action_key|request_hash|event_index|delivery_event_items/i', $pdo->queries[0])
    && str_contains($pdo->queries[0], "CASE WHEN e.event_type = 'DELIVERY_ATTEMPT_FAILED' THEN e.reason ELSE NULL END"));
foreach ([1, 2, 3] as $attempt) {
    customerDeliveryCheck('persisted failed attempt ' . $attempt . ' has numbering and reason',
        str_contains($html, 'Delivery Attempt Failed - Attempt ' . $attempt . ' of 3') && str_contains($html, 'Recipient unavailable'));
}
customerDeliveryCheck('return decision and physical return remain separate entries',
    $xpath->query('//li[contains(@class,"od-courier__event")]')->length === 8
    && array_search('Return to Seller', $renderedTitles, true) < array_search('Returned to Shop', $renderedTitles, true));
customerDeliveryCheck('physical return current marker is not moved to an item inspection',
    $history['current_event'] === '8' && $xpath->query('//li[@aria-current="step"]')->length === 1
    && str_contains($xpath->evaluate('string(//li[@aria-current="step"])'), 'Returned to Shop'));
customerDeliveryCheck('return closure ends the Hopia Fits delivery journey without financial claims',
    str_contains($html, 'Your delivery journey with Hopia Fits has ended.')
    && !preg_match('/refund|payment resolution|inventory|inspection|finalization|sale/i', $html));
customerDeliveryCheck('private inspection evidence and revisions never appear',
    !str_contains($html, 'Returned Item Checked') && !str_contains($html, 'Revision')
    && !str_contains(json_encode($history), 'RETURN_INSPECTED'));
customerDeliveryCheck('private custody/inspection notes never enter public view', !str_contains($html, 'PRIVATE ADMIN NOTE'));
customerDeliveryCheck('no admin forms or raw internal enums in customer partial',
    $xpath->query('//form|//input|//script')->length === 0 && !str_contains($html, 'RETURNED_TO_SHOP'));
customerDeliveryCheck('timestamps have explicit Philippine offset and readable dates',
    $xpath->evaluate('string(//time/@datetime)') === '2026-10-22T10:01:00+08:00' && str_contains($html, 'Oct 22, 2026 10:01 AM'));

foreach (['OUT_FOR_DELIVERY', 'DELIVERY_ATTEMPT_FAILED', 'RETURN_TO_SELLER', 'RETURNED_TO_SHOP', 'DELIVERED'] as $stage) {
    $stageRows = [customerDeliveryRow(1, $stage, 1, $stage === 'DELIVERY_ATTEMPT_FAILED' ? 1 : null)];
    $stageRows[0]['shipment_status'] = $stage;
    $stageRows[0]['current_revision'] = 1;
    $view = customer_delivery_history(new CustomerDeliveryPDO($stageRows), 801, 7, $ready);
    $rendered = customerDeliveryRender($view, ['status' => $stage === 'DELIVERED' ? 'DELIVERED' : 'SHIPPED']);
    customerDeliveryCheck($stage . ' current stage follows shipment and event evidence',
        str_contains(customerDeliveryXPath($rendered)->evaluate('string(//li[@aria-current="step"])'), customer_delivery_labels()[$stage]));
    if ($stage === 'RETURN_TO_SELLER') customerDeliveryCheck('return-to-seller is not returned-to-shop closure', !str_contains($rendered, 'journey with Hopia Fits has ended'));
    if ($stage === 'DELIVERED') {
        customerDeliveryCheck('delivered awaits existing receipt confirmation', str_contains($rendered, 'Delivered - Awaiting Receipt Confirmation'));
        customerDeliveryCheck('receipt confirmation only changes messaging after persisted receipt',
            str_contains(customerDeliveryRender($view, ['status' => 'DELIVERED', 'received_at' => '2026-10-22 11:00:00']), 'Delivered - Receipt Confirmed')
            && !str_contains(customerDeliveryRender($view, ['status' => 'DELIVERED', 'received_at' => '2026-10-22 11:00:00']), 'Awaiting Receipt Confirmation'));
    }
}

$retryRows = array_merge($rows, [customerDeliveryRow(10, 'DELIVERY_CYCLE_AUTHORIZED', 2),
    customerDeliveryRow(11, 'OUT_FOR_DELIVERY', 2), customerDeliveryRow(12, 'DELIVERY_ATTEMPT_FAILED', 2, 1)]);
foreach ($retryRows as &$row) { $row['current_cycle'] = 2; $row['shipment_status'] = 'DELIVERY_ATTEMPT_FAILED'; $row['current_revision'] = 12; }
unset($row);
$retry = customer_delivery_history(new CustomerDeliveryPDO(array_reverse($retryRows)), 801, 7, $ready);
$html = customerDeliveryRender($retry);
customerDeliveryCheck('authorized retry retains complete previous cycle', array_keys($retry['cycles']) === [1, 2]
    && count($retry['cycles'][1]) === 8 && count($retry['cycles'][2]) === 2 && str_contains($html, 'Previous cycle'));
customerDeliveryCheck('new cycle uses authorization internally without displaying the private decision', !str_contains($html, 'New Delivery Cycle Authorized')
    && !str_contains(json_encode($retry), 'DELIVERY_CYCLE_AUTHORIZED')
    && strpos($html, 'courier-cycle-1') < strpos($html, 'courier-cycle-2'));
customerDeliveryCheck('attempt numbering restarts only within separately recorded cycle',
    substr_count($html, 'Attempt 1 of 3') === 2 && $retry['current_event'] === '12');
$authorizedOnlyRows = array_slice($retryRows, 0, 10);
foreach ($authorizedOnlyRows as &$row) { $row['shipment_status'] = 'SHIPPED'; $row['current_revision'] = 10; }
unset($row);
$authorizedOnly = customer_delivery_history(new CustomerDeliveryPDO($authorizedOnlyRows), 801, 7, $ready);
customerDeliveryCheck('authorization-only new cycle invents no public event or current marker',
    $authorizedOnly['current_cycle'] === 2 && $authorizedOnly['current_event'] === null
    && !isset($authorizedOnly['cycles'][2])
    && customerDeliveryXPath(customerDeliveryRender($authorizedOnly))->query('//li[@aria-current="step"]')->length === 0);
$unauthorizedRows = array_values(array_filter($retryRows, static fn ($row) => $row['event_type'] !== 'DELIVERY_CYCLE_AUTHORIZED'));
$unauthorizedCycle = customer_delivery_history(new CustomerDeliveryPDO($unauthorizedRows), 801, 7, $ready);
customerDeliveryCheck('cycle counter without authorization never creates a second-cycle display',
    array_keys($unauthorizedCycle['cycles']) === [1] && $unauthorizedCycle['current_cycle'] === null
    && !str_contains(customerDeliveryRender($unauthorizedCycle), 'courier-cycle-2'));

$malicious = [customerDeliveryRow(1, 'DELIVERY_ATTEMPT_FAILED', 1, 1)];
$malicious[0]['shipment_status'] = 'DELIVERY_ATTEMPT_FAILED';
$malicious[0]['reason'] = '<script>alert("x")</script><img src=x onerror=alert(1)> " &';
$escaped = customerDeliveryRender(customer_delivery_history(new CustomerDeliveryPDO($malicious), 801, 7, $ready));
customerDeliveryCheck('persisted reason is escaped as text, never HTML', str_contains($escaped, '&lt;script&gt;')
    && customerDeliveryXPath($escaped)->query('//script|//img')->length === 0 && str_contains($escaped, '&quot; &amp;'));
foreach ([null, '2026-02-30 10:00:00', 'not-a-date'] as $invalidDate) {
    $malicious[0]['created_at'] = $invalidDate;
    customerDeliveryCheck('missing/invalid timestamp is not fabricated: ' . var_export($invalidDate, true),
        customerDeliveryXPath(customerDeliveryRender(customer_delivery_history(new CustomerDeliveryPDO($malicious), 801, 7, $ready)))->query('//time')->length === 0);
}

$emptyRow = array_fill_keys(array_keys($rows[0]), null);
$emptyRow['shipment_status'] = 'SHIPPED';
$emptyRow['current_cycle'] = 1;
$empty = customer_delivery_history(new CustomerDeliveryPDO([$emptyRow]), 801, 7, $ready);
customerDeliveryCheck('ready order with no events keeps true shipment stage and no invented history',
    $empty['stage'] === 'SHIPPED' && $empty['cycles'] === [] && $empty['current_event'] === null
    && str_contains(customerDeliveryRender($empty), 'No courier delivery events have been recorded'));
$unmatchedRows = [customerDeliveryRow(1, 'OUT_FOR_DELIVERY')];
$unmatchedRows[0]['shipment_status'] = 'SHIPPED';
$unmatched = customer_delivery_history(new CustomerDeliveryPDO($unmatchedRows), 801, 7, $ready);
customerDeliveryCheck('history without matching current shipment state never gets a misleading current marker',
    $unmatched['current_event'] === null
    && customerDeliveryXPath(customerDeliveryRender($unmatched))->query('//li[@aria-current="step"]')->length === 0);
customerDeliveryCheck('unconfirmed delivery never appears as the current courier stage',
    !str_contains(customerDeliveryXPath(customerDeliveryRender($empty))->evaluate('string(//*[contains(@class,"od-courier__current")])'), 'Delivered'));
foreach (['PENDING', 'CONFIRMED', 'PACKED'] as $status) {
    customerDeliveryCheck($status . ' remains the current pre-shipment stage',
        customer_order_timeline(['status' => $status], null, ['shipment_status' => 'SHIPPED'], $empty)['stage'] === $status
        && customerDeliveryXPath(customerDeliveryRender($empty, ['status' => $status]))->query('//li[@aria-current="step"]')->length === 0);
}
foreach (['legacy', 'blocked'] as $state) {
    $db = new CustomerDeliveryPDO($rows);
    $view = customer_delivery_history($db, 801, 7, delivery_schema_result($state));
    $render = customerDeliveryRender($view);
    customerDeliveryCheck($state . ' schema performs no event or new-column query', $db->queries === [] && $view['cycles'] === []);
    customerDeliveryCheck($state . ' has no fabricated history', $state === 'legacy' ? trim($render) === ''
        : str_contains($render, 'currently unavailable') && !str_contains($render, 'od-courier__event'));
}
$blockedDB = new class extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { throw new RuntimeException('PRIVATE metadata error'); }
};
customerDeliveryCheck('actual readiness helper contract handles metadata failure safely',
    delivery_schema_status($blockedDB)['state'] === 'blocked');
foreach ([[801, 8], [802, 7], [0, 7], [801, 0]] as [$orderId, $customerId]) {
    $view = customer_delivery_history(new CustomerDeliveryPDO($rows), $orderId, $customerId, $ready);
    customerDeliveryCheck('unauthorized/invalid owner-order pair ' . $orderId . '/' . $customerId . ' reveals no events', $view['cycles'] === [] && $view['stage'] === null);
}
customerDeliveryCheck('Store Pickup excluded by ownership-scoped courier query',
    customer_delivery_history(new CustomerDeliveryPDO($rows, 7, 'STORE_PICKUP'), 801, 7, $ready)['cycles'] === []);
$badDB = new CustomerDeliveryPDO($rows);
$badDB->failRead = true;
$failureHtml = customerDeliveryRender(customer_delivery_history($badDB, 801, 7, $ready));
customerDeliveryCheck('query failure produces safe message, never raw SQL error',
    str_contains($failureHtml, 'temporarily unavailable') && !str_contains($failureHtml, 'PRIVATE'));

// Render the actual page body, isolating only its DB/bootstrap boundary. Its
// real conditional markup, includes, receipt form and pickup branch are executed.
$pageSource = file_get_contents(__DIR__ . '/../../customer/order-detail.php');
$bodyStart = strpos($pageSource, '<section class="order-detail-page">');
$bodyEnd = strpos($pageSource, '<?php require __DIR__ . \'/../includes/ui.footer.php\'; ?>');
if ($bodyStart === false || $bodyEnd === false) throw new RuntimeException('Order-detail template boundary changed');
$pageBody = substr($pageSource, $bodyStart, $bodyEnd - $bodyStart);
$pageBody = str_replace('__DIR__', var_export(dirname(__DIR__, 2) . '/customer', true), $pageBody);
function customerDeliveryAssignment(string $source, string $variable): string
{
    if (preg_match('/\$' . preg_quote($variable, '/') . '\s*=\s*\[.*?\];/s', $source, $match) !== 1) {
        throw new RuntimeException('Missing customer presentation assignment: ' . $variable);
    }
    return $match[0];
}

function customerDeliveryPage(string $body, array $view, array $overrides = [], string $method = 'LOCAL_COURIER',
    array $paymentOverrides = [], array $shipmentOverrides = [], bool $receivedFlag = false): string
{
    global $pageSource;
    $order = array_replace(['id' => 801, 'status' => 'DELIVERED', 'received_at' => null, 'cancellation_status' => 'NONE',
        'subtotal' => '500.00', 'shipping_fee' => '80.00', 'total_amount' => '580.00', 'created_at' => '2026-10-22 09:00:00',
        'shipping_name' => 'Customer <name>', 'shipping_phone' => '09123456789', 'shipping_address' => 'Test address',
        'pickup_status' => null, 'ready_for_pickup_at' => null, 'pickup_deadline_at' => null, 'picked_up_at' => null], $overrides);
    $shipment = array_replace(['shipment_status' => $view['stage'] ?? 'DELIVERED', 'delivery_method' => $method], $shipmentOverrides);
    $courierHistory = $method === 'LOCAL_COURIER' ? $view : null;
    $pickupProgress = pickup_progress($order, $shipment);
    $pickupPickupDetails = shipping_pickup_details();
    $payment = array_replace(['payment_method' => 'COD', 'payment_status' => 'PENDING'], $paymentOverrides);
    $isGcashOrder = $payment['payment_method'] === 'GCASH';
    $gcashState = $isGcashOrder ? payment_gcash_state($payment) : 'NA';
    $gcashConfig = ['configured' => false];
    $gcashFlash = $gcashFlashMessage = '';
    $orderItems = [];
    foreach (['orderWorkflow', 'orderStepIcons', 'orderStatusLabels', 'orderStatusBadgeClasses',
        'shipmentStatusBadgeClasses', 'paymentStatusBadgeClasses'] as $variable) {
        eval(customerDeliveryAssignment($pageSource, $variable));
    }
    $shipmentStatusLabels = customer_delivery_labels();
    $orderStatusLabel = $orderStatusLabels[$order['status']] ?? '';
    $shipmentStatusLabel = $shipmentStatusLabels[$shipment['shipment_status']] ?? '';
    $timelineStart = strpos($pageSource, '$orderTimeline = (');
    $timelineEnd = strpos($pageSource, '$pickupPickupDetails =');
    eval(substr($pageSource, $timelineStart, $timelineEnd - $timelineStart));
    $receiptStart = strpos($pageSource, '$receiptDeliveryEligible =');
    $receiptEnd = strpos($pageSource, ';', $receiptStart);
    eval(substr($pageSource, $receiptStart, $receiptEnd - $receiptStart + 1));
    $cancellationStatus = $order['cancellation_status'];
    $paymentMethodLabel = 'Cash on Delivery';
    $paymentStatusLabel = 'Pending';
    $trackingNumber = 'TRACK-TEST-<&';
    $currentStepIndex = array_search($order['status'], $orderWorkflow, true);
    $itemCountLabel = '0 ITEMS';
    $receivedErrorFlag = $cancelRequestedFlag = $cancelErrorFlag = false;
    $showFeedbackAction = $showCancelForm = $showCancelInfo = false;
    $csrfToken = 'TEST-CSRF-<&';
    ob_start();
    eval('?>' . $body);
    return (string) ob_get_clean();
}
$deliveredRow = customerDeliveryRow(1, 'DELIVERED');
$deliveredRow['shipment_status'] = 'DELIVERED';
$deliveredRow['current_revision'] = 1;
$delivered = customer_delivery_history(new CustomerDeliveryPDO([$deliveredRow]), 801, 7, $ready);
foreach (['ready' => $delivered, 'legacy' => customer_delivery_history(new CustomerDeliveryPDO([]), 801, 7, delivery_schema_result('legacy'))] as $state => $view) {
    $pageHtml = customerDeliveryPage($pageBody, $view);
    $pageXPath = customerDeliveryXPath($pageHtml);
    customerDeliveryCheck($state . ' page preserves Order Received POST with escaped CSRF and order identity',
        $pageXPath->query('//form[@action="order-received.php"][@method="POST"]')->length === 1
        && $pageXPath->evaluate('string(//form[@action="order-received.php"]/input[@name="order_id"]/@value)') === '801'
        && $pageXPath->evaluate('string(//form[@action="order-received.php"]/input[@name="csrf_token"]/@value)') === 'TEST-CSRF-<&');
    customerDeliveryCheck($state . ' page preserves escaped tracking details and external tracking link',
        str_contains($pageHtml, 'TRACK-TEST-&lt;&amp;') && str_contains($pageHtml, 'waybillNo=TRACK-TEST-%3C%26'));
    customerDeliveryCheck($state . ' page hides receipt form after recorded customer receipt',
        customerDeliveryXPath(customerDeliveryPage($pageBody, $view, ['received_at' => '2026-10-22 12:00:00']))->query('//form[@action="order-received.php"]')->length === 0);
    customerDeliveryCheck($state . ' page includes courier history only with ready schema',
        str_contains($pageHtml, 'courier-history-heading') === ($state === 'ready'));
    customerDeliveryCheck($state . ' detail displays explicit awaiting courier badge and no premature closure section',
        trim($pageXPath->evaluate('string(//div[@class="od-timeline-status"]//span[contains(@class,"badge")])')) === 'Delivered - Awaiting Receipt Confirmation'
        && trim($pageXPath->evaluate('string(//section[contains(@class,"order-detail-card--shipment")]//span[contains(@class,"badge")])')) === 'Delivered - Awaiting Receipt Confirmation'
        && $pageXPath->query('//section[contains(@class,"order-detail-card--closure")]')->length === 0);
}
$pickupHtml = customerDeliveryPage($pageBody, $delivered, ['status' => 'CONFIRMED', 'pickup_status' => 'PREPARING'], 'STORE_PICKUP');
customerDeliveryCheck('real Store Pickup page has no courier history, external tracking or receipt action',
    str_contains($pickupHtml, 'od-progress--pickup') && str_contains($pickupHtml, 'Where to pick up')
    && !str_contains($pickupHtml, 'courier-history-heading') && !str_contains($pickupHtml, 'Track with J&amp;T')
    && customerDeliveryXPath($pickupHtml)->query('//form[@action="order-received.php"]')->length === 0
    && customerDeliveryXPath($pickupHtml)->query('//section[contains(@class,"order-detail-card--closure")]')->length === 0);
customerDeliveryCheck('page source loads history only inside verified order and local-courier branches',
    strpos($pageSource, 'if ($order !== false) {') < strpos($pageSource, 'delivery_schema_status($pdo)')
    && str_contains($pageSource, "if (\$shipment !== false && \$shipment['delivery_method'] === 'LOCAL_COURIER')")
    && str_contains($pageSource, 'AND customer_id = :customer_id')
    && str_contains($pageSource, "if (!isset(\$_SESSION['customer_id']))"));
customerDeliveryCheck('receipt action and verified-GCash guard remain in actual template',
    str_contains($pageSource, "(!\$isGcashOrder || \$gcashState === 'PAID')")
    && str_contains($pageSource, "\$order['received_at'] === null")
    && str_contains($pageSource, 'action="order-received.php"'));

$receivedAt = '2026-10-22 12:00:00';
$receivedClosureCopy = 'Order received successfully. Thank you for shopping with Hopia Fits! Your delivery journey is complete.';
$returnedClosureCopy = 'This parcel has been returned to Hopia Fits, and its delivery journey has ended. If you need assistance regarding your order, please contact the shop.';
$closurePath = '//section[contains(@class,"order-detail-card--closure")]';
foreach (['PENDING', 'PAID', 'FAILED', 'REFUNDED'] as $paymentStatus) {
    foreach ([false, true] as $saleRecorded) {
        $view = customer_order_timeline(['status' => 'DELIVERED', 'received_at' => $receivedAt,
            'cancellation_status' => 'NONE', 'has_completed_sale' => $saleRecorded],
            ['payment_status' => $paymentStatus], ['shipment_status' => 'DELIVERED'], null);
        customerDeliveryCheck('receipt stays Delivered regardless ' . $paymentStatus . ' payment / sale ' . (int) $saleRecorded,
            $view['stage'] === 'DELIVERED' && $view['badge_stage'] === 'DELIVERED' && $view['closure'] === 'RECEIVED'
            && $view['courier_label'] === 'Delivered - Receipt Confirmed'
            && str_contains($view['message'], 'receipt confirmation is recorded')
            && !preg_match('/completed|finalization|sale|pending/i', $view['message']));
    }
}
$receivedHtml = customerDeliveryPage($pageBody, $delivered, ['received_at' => $receivedAt, 'has_completed_sale' => true]);
$receivedXPath = customerDeliveryXPath($receivedHtml);
$primaryLabels = [];
foreach ($receivedXPath->query('//ol[contains(@class,"od-progress--courier")]/li//span[@class="od-progress__label"]') as $label) {
    $primaryLabels[] = trim($label->textContent);
}
customerDeliveryCheck('real page has exactly six customer primary stages, never Completed',
    $primaryLabels === ['Pending', 'Confirmed', 'Packed', 'Shipped', 'In Transit', 'Delivered']
    && !str_contains($receivedHtml, 'Completed')
    && $receivedXPath->evaluate('string(//ol[contains(@class,"od-progress--courier")]/li[@aria-current="step"]//span[@class="od-progress__label"])') === 'Delivered');
customerDeliveryCheck('received delivery has positive receipt closure and no return or admin finalization message',
    str_contains($receivedHtml, 'Delivery received') && str_contains($receivedHtml, 'receipt confirmation is recorded')
    && !preg_match('/delivery journey with Hopia Fits has ended|finalization|completed sale/i', $receivedHtml));
customerDeliveryCheck('received detail has exactly one labeled closure section with exact received copy after progress',
    $receivedXPath->query($closurePath)->length === 1
    && trim($receivedXPath->evaluate('string(' . $closurePath . '/p)')) === $receivedClosureCopy
    && $receivedXPath->evaluate('string(' . $closurePath . '/@aria-labelledby)') === 'delivery-journey-heading'
    && trim($receivedXPath->evaluate('string(' . $closurePath . '//h2[@id="delivery-journey-heading"])')) === 'Delivery journey complete'
    && $receivedXPath->query('//section[contains(@class,"order-detail-card--progress")]/following-sibling::*[1][contains(@class,"order-detail-card--closure")]')->length === 1
    && !str_contains($receivedHtml, $returnedClosureCopy));
customerDeliveryCheck('received detail courier badges explicitly confirm receipt, never awaiting',
    trim($receivedXPath->evaluate('string(//div[@class="od-timeline-status"]//span[contains(@class,"badge")])')) === 'Delivered - Receipt Confirmed'
    && trim($receivedXPath->evaluate('string(//section[contains(@class,"order-detail-card--shipment")]//span[contains(@class,"badge")])')) === 'Delivered - Receipt Confirmed'
    && !str_contains($receivedHtml, 'Delivered - Awaiting Receipt Confirmation'));
customerDeliveryCheck('recorded Store Pickup handover never renders a courier journey closure',
    customerDeliveryXPath(customerDeliveryPage($pageBody, $delivered, ['status' => 'PENDING', 'pickup_status' => 'PICKED_UP',
        'picked_up_at' => $receivedAt, 'received_at' => $receivedAt], 'STORE_PICKUP'))->query($closurePath)->length === 0);

$returnedPage = customerDeliveryXPath(customerDeliveryPage($pageBody, $history, ['status' => 'SHIPPED']));
$courierStepsPath = '//ol[contains(@class,"od-progress--courier")]/li';
customerDeliveryCheck('current-cycle dispatch completes reached customer stages and never Delivered',
    $returnedPage->query($courierStepsPath . '[contains(@class,"is-complete")]')->length === 5
    && $returnedPage->query($courierStepsPath . '[@aria-disabled="true"][contains(@class,"is-unreached")][.//span[normalize-space(.)="Delivered"]]')->length === 1
    && $returnedPage->query($courierStepsPath . '[@aria-current]')->length === 0);
customerDeliveryCheck('closed returned customer cycle identifies only its final public outcome',
    $returnedPage->query('//section[contains(@class,"od-courier__cycle")][contains(@class,"is-current-cycle")][contains(@class,"is-closed")]')->length === 1
    && trim($returnedPage->evaluate('string(//li[@aria-current="step"]//span[contains(@class,"badge")])')) === 'Final courier outcome'
    && !str_contains($returnedPage->evaluate('string(' . $courierStepsPath . ')'), 'Completed'));

$sparseReturn = ['state' => 'ready', 'stage' => 'RETURNED_TO_SHOP', 'current_cycle' => 2,
    'current_event' => '22', 'current_type' => 'RETURNED_TO_SHOP', 'error' => null,
    'cycles' => [1 => [['type' => 'OUT_FOR_DELIVERY'], ['type' => 'DELIVERED']], 2 => []]];
foreach ([
    'stored SHIPPED and previous-cycle delivery only' => [null, [], false],
    'known physical shipment timestamp' => ['2026-10-22 09:00:00', [], true],
    'empty physical shipment timestamp' => ['', [], false],
    'malformed physical shipment timestamp' => ['not-a-date', [], false],
    'impossible physical shipment timestamp' => ['2026-02-30 09:00:00', [], false],
    'current-cycle outgoing courier event' => [null, [['type' => 'OUT_FOR_DELIVERY']], true],
    'current-cycle failed attempt' => [null, [['type' => 'DELIVERY_ATTEMPT_FAILED']], true],
    'current-cycle dispatch event' => [null, [['type' => 'SHIPPED']], true],
    'private authorization without physical dispatch' => [null, [['type' => 'DELIVERY_CYCLE_AUTHORIZED']], false],
    'standalone physical return' => [null, [['type' => 'RETURNED_TO_SHOP']], false],
] as $label => [$shippedAt, $currentEvents, $dispatched]) {
    $returnHistory = $sparseReturn;
    $returnHistory['cycles'][2] = $currentEvents;
    $timeline = customer_order_timeline(['status' => 'SHIPPED', 'received_at' => $receivedAt, 'has_completed_sale' => true],
        ['payment_status' => 'PAID'], ['shipment_status' => 'RETURNED_TO_SHOP', 'shipped_at' => $shippedAt], $returnHistory);
    customerDeliveryCheck('returned step evidence: ' . $label,
        $timeline['returned_closed'] && $timeline['returned_step_states'] === [
            'PENDING' => 'complete', 'CONFIRMED' => $dispatched ? 'complete' : 'unreached',
            'PACKED' => $dispatched ? 'complete' : 'unreached', 'SHIPPED' => $dispatched ? 'complete' : 'unreached',
            'IN_TRANSIT' => 'complete', 'DELIVERED' => 'unreached', 'COMPLETED' => 'unreached']);
}
foreach (['PENDING' => 0, 'CONFIRMED' => 1, 'PACKED' => 2] as $status => $reachedBeforeDispatch) {
    $timeline = customer_order_timeline(['status' => $status], null, ['shipment_status' => 'RETURN_TO_SELLER'], null);
    customerDeliveryCheck('predispatch returned ' . $status . ' retains only recorded preparation',
        count(array_filter(array_intersect_key($timeline['returned_step_states'], array_flip(['CONFIRMED', 'PACKED'])),
            static fn ($state) => $state === 'complete')) === $reachedBeforeDispatch
        && $timeline['returned_step_states']['SHIPPED'] === 'unreached'
        && $timeline['returned_step_states']['IN_TRANSIT'] === 'current' && !$timeline['returned_closed']);
}
$returningHistory = $history;
$returningHistory['stage'] = 'RETURN_TO_SELLER';
$returningHistory['current_event'] = '7';
$returningHistory['current_type'] = 'RETURN_TO_SELLER';
$returningHistory['cycles'][1] = array_slice($returningHistory['cycles'][1], 0, 7);
$returningPage = customerDeliveryXPath(customerDeliveryPage($pageBody, $returningHistory, ['status' => 'SHIPPED']));
customerDeliveryCheck('Return to Seller keeps In Transit current without a shop-arrival closure',
    $returningPage->query($courierStepsPath . '[contains(@class,"is-complete")]')->length === 4
    && trim($returningPage->evaluate('string(' . $courierStepsPath . '[@aria-current="step"]//span[@class="od-progress__label"])')) === 'In Transit'
    && $returningPage->query($courierStepsPath . '[@aria-disabled="true"]')->length === 1
    && $returningPage->query($closurePath)->length === 0
    && $returningPage->query('//section[contains(@class,"od-courier__cycle")][contains(@class,"is-closed")]')->length === 0);
foreach (['SHIPPED', 'OUT_FOR_DELIVERY', 'DELIVERED'] as $stage) {
    $timeline = customer_order_timeline(['status' => $stage === 'DELIVERED' ? 'DELIVERED' : 'SHIPPED'], null,
        ['shipment_status' => $stage, 'shipped_at' => '2026-10-22 09:00:00'], null);
    customerDeliveryCheck('normal ' . $stage . ' timeline has no returned-step overrides',
        $timeline['returned_step_states'] === null && !$timeline['returned_closed']);
}
customerDeliveryCheck('Store Pickup and cancellation have no returned-step overrides',
    customer_order_timeline(['status' => 'CONFIRMED'], null,
        ['shipment_status' => 'RETURNED_TO_SHOP', 'delivery_method' => 'STORE_PICKUP'], null)['returned_step_states'] === null
    && customer_order_timeline(['status' => 'CANCELLED'], null,
        ['shipment_status' => 'RETURNED_TO_SHOP'], null)['returned_step_states'] === null);

foreach (['PENDING', 'CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED'] as $orderStatus) {
    foreach (['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'] as $returnStage) {
        $returnRow = customerDeliveryRow(1, $returnStage);
        $returnRow['shipment_status'] = $returnStage;
        $returnRow['current_revision'] = 1;
        $view = customer_delivery_history(new CustomerDeliveryPDO([$returnRow]), 801, 7, $ready);
        $page = customerDeliveryPage($pageBody, $view, ['status' => $orderStatus, 'received_at' => $receivedAt]);
        $pageXPath = customerDeliveryXPath($page);
        customerDeliveryCheck($returnStage . ' overrides differing order ' . $orderStatus . ' without receipt action',
            trim($pageXPath->evaluate('string(//header[contains(@class,"order-detail-hero")]//span[contains(@class,"badge")])')) === customer_delivery_labels()[$returnStage]
            && $pageXPath->query('//form[@action="order-received.php"]')->length === 0
            && !str_contains($page, 'Delivery received') && !str_contains($page, '<dt>Receipt confirmed</dt>')
             && str_contains($page, 'delivery journey with Hopia Fits has ended') === ($returnStage === 'RETURNED_TO_SHOP'));
        customerDeliveryCheck($returnStage . ' order ' . $orderStatus . ' renders only the exact appropriate closure section',
            $pageXPath->query($closurePath)->length === ($returnStage === 'RETURNED_TO_SHOP' ? 1 : 0)
            && ($returnStage !== 'RETURNED_TO_SHOP'
                || (trim($pageXPath->evaluate('string(' . $closurePath . '/p)')) === $returnedClosureCopy
                    && trim($pageXPath->evaluate('string(' . $closurePath . '//h2[@id="delivery-journey-heading"])')) === 'Delivery journey ended'
                    && $pageXPath->query('//section[contains(@class,"order-detail-card--progress")]/following-sibling::*[1][contains(@class,"order-detail-card--closure")]')->length === 1))
            && !str_contains($page, $receivedClosureCopy)
            && !preg_match('/Completed|refund|financial|payment|sale|inventory|inspection/i', $pageXPath->evaluate('string(' . $closurePath . ')')));
    }
}
$privateRows = array_merge($rows, [customerDeliveryRow(10, 'RETRY_APPROVED'), customerDeliveryRow(11, 'MANUAL_REVIEW'),
    customerDeliveryRow(12, 'UNKNOWN_PRIVATE_DECISION')]);
foreach ($privateRows as &$row) $row['current_revision'] = 12;
unset($row);
$privateHistory = customer_delivery_history(new CustomerDeliveryPDO($privateRows), 801, 7, $ready);
customerDeliveryCheck('internal decisions are quietly allowlisted out and physical return stays current after private revisions',
    $privateHistory['current_event'] === '8' && $privateHistory['current_type'] === 'RETURNED_TO_SHOP'
    && $privateHistory['error'] === null && count($privateHistory['cycles'][1]) === 8
    && !preg_match('/RETURN_INSPECTED|RETRY_APPROVED|MANUAL_REVIEW|UNKNOWN_PRIVATE_DECISION|PRIVATE ADMIN NOTE/', json_encode($privateHistory)));

foreach ([['status' => 'CANCELLED'], ['cancellation_status' => 'APPROVED'], ['cancellation_status' => 'REQUESTED']] as $overrides) {
    $page = customerDeliveryPage($pageBody, $delivered, $overrides);
    $pageXPath = customerDeliveryXPath($page);
    customerDeliveryCheck('receipt form rejects cancellation ' . json_encode($overrides),
        $pageXPath->query('//form[@action="order-received.php"]')->length === 0);
    if (($overrides['cancellation_status'] ?? '') !== 'REQUESTED') {
        customerDeliveryCheck('authoritative cancellation controls hero and shipment badges',
            trim($pageXPath->evaluate('string(//header[contains(@class,"order-detail-hero")]//span[contains(@class,"badge-cancelled")])')) === 'Cancelled'
            && trim($pageXPath->evaluate('string(//section[contains(@class,"order-detail-card--shipment")]//span[contains(@class,"badge-cancelled")])')) === 'Cancelled'
             && !str_contains($page, 'Delivery received') && !str_contains($page, 'journey with Hopia Fits has ended'));
        customerDeliveryCheck('cancelled delivered order suppresses recorded-receipt closure ' . json_encode($overrides),
            customerDeliveryXPath(customerDeliveryPage($pageBody, $delivered, array_replace($overrides, ['received_at' => $receivedAt])))
                ->query($closurePath)->length === 0);
    }
}
foreach (['PENDING', 'PAID', 'FAILED'] as $paymentStatus) {
    $page = customerDeliveryPage($pageBody, $delivered, [], 'LOCAL_COURIER', ['payment_method' => 'GCASH', 'payment_status' => $paymentStatus]);
    customerDeliveryCheck('real receipt form retains verified GCash gate for ' . $paymentStatus,
        customerDeliveryXPath($page)->query('//form[@action="order-received.php"]')->length === ($paymentStatus === 'PAID' ? 1 : 0));
}
customerDeliveryCheck('receipt form checks actual shipment, not a newer history display alone',
    customerDeliveryXPath(customerDeliveryPage($pageBody, $delivered, [], 'LOCAL_COURIER', [], ['shipment_status' => 'SHIPPED']))
        ->query('//form[@action="order-received.php"]')->length === 0);
customerDeliveryCheck('receipt query flag alone cannot manufacture positive confirmation',
    !str_contains(customerDeliveryPage($pageBody, $delivered, [], 'LOCAL_COURIER', [], [], true), 'Your order receipt has been confirmed.')
    && str_contains(customerDeliveryPage($pageBody, $delivered, ['received_at' => $receivedAt], 'LOCAL_COURIER', [], [], true), 'Your order receipt has been confirmed.'));
customerDeliveryCheck('existing legacy NULL shipment method preserves actual delivered receipt action',
    customerDeliveryXPath(customerDeliveryPage($pageBody, $delivered, [], 'LOCAL_COURIER', [], ['delivery_method' => null]))
        ->query('//form[@action="order-received.php"]')->length === 1);
customerDeliveryCheck('sale evidence remains internal for existing GCash recovery guards, not customer completion',
    str_contains($pageSource, 'EXISTS(SELECT 1 FROM sales_transactions st WHERE st.order_id = orders.id) AS has_completed_sale')
    && payment_gcash_submission_reason(['status' => 'PENDING', 'cancellation_status' => 'NONE', 'has_completed_sale' => true],
        ['payment_method' => 'GCASH', 'payment_status' => 'PENDING'],
        ['delivery_method' => 'LOCAL_COURIER', 'shipment_status' => 'NOT_SHIPPED']) === 'order_incompatible');

// Execute the actual list template and its filters, with in-memory rows only.
$ordersSource = file_get_contents(__DIR__ . '/../../customer/orders.php');
$listStart = strpos($ordersSource, '<section class="orders-page">');
$listEnd = strpos($ordersSource, '<?php require __DIR__ . \'/../includes/ui.footer.php\'; ?>');
if ($listStart === false || $listEnd === false) throw new RuntimeException('Orders template boundary changed');
$ordersBody = substr($ordersSource, $listStart, $listEnd - $listStart);
function customerDeliveryOrders(string $body, array $rows, string $filter = 'ALL'): string
{
    global $ordersSource;
    $orders = $rows;
    $orderFilter = $filter;
    foreach (['orderStatusLabels', 'cancellationStatusLabels', 'orderStatusBadgeClasses', 'orderStatusIconKeys'] as $variable) {
        eval(customerDeliveryAssignment($ordersSource, $variable));
    }
    $orderItemsMap = [];
    foreach ($rows as $row) {
        $orderItemsMap[(int) $row['id']] = ['order_item_id' => 1, 'product_name' => 'Customer item',
            'size' => 'M', 'color' => 'Blue', 'category' => '', 'image_path' => ''];
    }
    $feedbackByItem = [];
    ob_start();
    eval('?>' . $body);
    return (string) ob_get_clean();
}
$listOrder = ['id' => 801, 'status' => 'SHIPPED', 'cancellation_status' => 'NONE', 'total_amount' => '580.00',
    'created_at' => '2026-10-22 09:00:00', 'received_at' => null, 'delivery_method' => 'LOCAL_COURIER',
    'shipment_status' => 'OUT_FOR_DELIVERY', 'payment_method' => 'COD', 'payment_status' => 'PENDING'];
$listHtml = customerDeliveryOrders($ordersBody, [$listOrder]);
customerDeliveryCheck('actual list shows primary In Transit badge and specific courier detail instead of stale Shipped',
    customerDeliveryXPath($listHtml)->evaluate('string(//span[@class="badge__label"])') === 'In Transit'
    && str_contains($listHtml, 'Out for Delivery'));
foreach (['SHIPPED', 'CONFIRMED', 'DELIVERED'] as $orderStatus) {
    $returnOrder = array_replace($listOrder, ['status' => $orderStatus, 'shipment_status' => 'RETURNED_TO_SHOP']);
    $listHtml = customerDeliveryOrders($ordersBody, [$returnOrder]);
    customerDeliveryCheck('actual list return overrides ' . $orderStatus . ' and is closed, not delivered or in progress',
         customerDeliveryXPath($listHtml)->evaluate('string(//span[@class="badge__label"])') === 'Returned to Shop'
         && str_contains($listHtml, 'journey with Hopia Fits has ended') && !str_contains($listHtml, 'CONFIRM RECEIPT')
         && !str_contains($listHtml, 'Delivered - Receipt Confirmed') && !str_contains($listHtml, 'Delivered - Awaiting Receipt Confirmation')
        && !str_contains(customerDeliveryOrders($ordersBody, [$returnOrder], 'IN PROGRESS'), 'order-card__number')
        && !str_contains(customerDeliveryOrders($ordersBody, [$returnOrder], 'DELIVERED'), 'order-card__number'));
}
foreach (['LOCAL_COURIER', 'STORE_PICKUP'] as $method) {
    $cancelledOrder = array_replace($listOrder, ['cancellation_status' => 'APPROVED', 'delivery_method' => $method, 'pickup_status' => 'READY_FOR_PICKUP']);
    $listHtml = customerDeliveryOrders($ordersBody, [$cancelledOrder], 'CANCELLED');
    customerDeliveryCheck('actual list approved cancellation takes precedence for ' . $method,
         str_contains($listHtml, 'badge-cancelled') && customerDeliveryXPath($listHtml)->evaluate('string(//span[@class="badge__label"])') === 'Cancelled'
         && !str_contains($listHtml, 'Delivered - Receipt Confirmed') && !str_contains($listHtml, 'Delivered - Awaiting Receipt Confirmation')
        && !str_contains(customerDeliveryOrders($ordersBody, [$cancelledOrder], 'IN PROGRESS'), 'order-card__number'));
}
foreach (['PENDING', 'PAID', 'FAILED'] as $paymentStatus) {
    $gcashOrder = array_replace($listOrder, ['status' => 'DELIVERED', 'shipment_status' => 'DELIVERED', 'payment_method' => 'GCASH', 'payment_status' => $paymentStatus]);
    $listHtml = customerDeliveryOrders($ordersBody, [$gcashOrder]);
    customerDeliveryCheck('actual list receipt link retains GCash verification gate for ' . $paymentStatus,
        str_contains($listHtml, 'CONFIRM RECEIPT') === ($paymentStatus === 'PAID'));
    customerDeliveryCheck('actual list awaiting status is an adjacent badge even when GCash receipt action is unavailable: ' . $paymentStatus,
        customerDeliveryXPath($listHtml)->query('//div[@class="order-card__status"]/span[contains(@class,"badge")][1]/following-sibling::span[contains(@class,"badge")][normalize-space(.)="Delivered - Awaiting Receipt Confirmation"]')->length === 1
        && !str_contains($listHtml, 'Delivered - Receipt Confirmed'));
}
$listReceived = array_replace($listOrder, ['status' => 'DELIVERED', 'shipment_status' => 'DELIVERED', 'received_at' => $receivedAt]);
$listHtml = customerDeliveryOrders($ordersBody, [$listReceived]);
customerDeliveryCheck('actual list confirmed receipt remains Delivered with positive receipt message',
    customerDeliveryXPath($listHtml)->evaluate('string(//span[@class="badge__label"])') === 'Delivered'
    && str_contains($listHtml, 'receipt confirmation is recorded') && !str_contains($listHtml, 'Completed')
    && !str_contains($listHtml, 'CONFIRM RECEIPT'));
customerDeliveryCheck('actual list receipt-confirmed indication is an adjacent rendered badge, never awaiting',
    customerDeliveryXPath($listHtml)->query('//div[@class="order-card__status"]/span[contains(@class,"badge")][1]/following-sibling::span[contains(@class,"badge")][normalize-space(.)="Delivered - Receipt Confirmed"]')->length === 1
    && !str_contains($listHtml, 'Delivered - Awaiting Receipt Confirmation'));
customerDeliveryCheck('actual list remains authenticated and owner-scoped without history N+1 reads',
    str_contains($ordersSource, "if (!isset(\$_SESSION['customer_id']))")
    && str_contains($ordersSource, 'WHERE o.customer_id = :customer_id')
    && !str_contains($ordersSource, 'customer_delivery_history('));

echo $checks . ' checks, ' . $failures . ' failures' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
