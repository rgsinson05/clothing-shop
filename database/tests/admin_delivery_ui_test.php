<?php

declare(strict_types=1);

// CLI only. Synthetic rendering and rejected API requests, with NO DB connection.
// --browser-fixtures writes only a new directory under sys_temp_dir for offline Edge tests.
// --browser also runs installed Edge against those synthetic local files, never a live site.
if (PHP_SAPI !== 'cli') exit(1);
if ($argc > 2 || ($argc === 2 && !in_array($argv[1], ['--browser-fixtures', '--browser'], true))) exit(2);
require_once __DIR__ . '/../../includes/admin_delivery.php';
require_once __DIR__ . '/../../includes/ui.php';
$checks = 0;
$failures = 0;
function deliveryUiCheck(string $name, bool $pass): void
{
    global $checks, $failures;
    $checks++;
    $failures += $pass ? 0 : 1;
    echo ($pass ? 'ok ' : 'FAIL ') . $name . PHP_EOL;
}

function deliveryUiFixture(string $stage, int $failed = 0, int $cycle = 1): array
{
    $order = ['id' => 801, 'status' => $stage === 'DELIVERED' ? 'DELIVERED' : 'SHIPPED',
        'cancellation_status' => 'NONE', 'received_at' => null, 'has_completed_sale' => 0];
    $shipment = ['shipment_status' => $stage, 'delivery_cycle' => $cycle,
        'delivery_revision' => 8, 'failed_attempts' => $failed];
    $view = ['actions' => [], 'inspection_actions' => [], 'stock_actions' => [], 'items' => [], 'events' => [],
        'evidence' => [], 'inspections' => [], 'returns' => [], 'blockers' => [], 'error' => null];
    foreach (admin_delivery_actions() as $action => $definition) {
        if ($action !== 'inspect_return') $view['actions'][$action] = delivery_failure('invalid_state');
    }
    // Synthetic availability is input to the renderer, not a second production state machine.
    $allowed = match ($stage) {
        'SHIPPED', 'IN_TRANSIT' => ['out_for_delivery'],
        'DELIVERY_ATTEMPT_FAILED' => $failed === 3 ? ['return_to_seller'] : ['out_for_delivery'],
        'OUT_FOR_DELIVERY' => ['fail_attempt'],
        'RETURN_TO_SELLER' => ['confirm_return'],
        default => [],
    };
    foreach ($allowed as $action) $view['actions'][$action] = ['ok' => true];
    foreach ([901, 902] as $id) {
        $view['items'][] = ['id' => $id, 'product_id' => $id + 100, 'current_product_id' => $id + 100,
            'product_name' => $id === 901 ? 'Classic cotton shirt' : 'Straight leg denim jeans',
            'size' => 'Medium', 'color' => 'Blue', 'quantity' => 1, 'product_status' => 'SOLD',
            'barcode_value' => 'TEST-ONLY-' . $id, 'retired_at' => null];
        if ($stage === 'RETURNED_TO_SHOP') {
            $view['returns'][$id] = ['created_at' => '2026-10-22 15:00:00'];
            $view['inspection_actions'][$id] = ['ok' => true];
            $view['stock_actions'][$id] = ['ok' => false, 'reason' => 'stock_release_blocked', 'status' => 409,
                'message' => 'Synthetic item-specific release blockers', 'blockers' => [
                    'Item #' . $id . ': independent passed release inspection is missing.',
                    'Item #' . $id . ': originating returned-order hold is unresolved.',
                    'Item #' . $id . ': append-only inventory disposition schema is missing.',
                ]];
        }
    }
    $payment = ['payment_method' => 'COD', 'payment_status' => 'PENDING'];
    return ['order' => $order, 'shipment' => $shipment, 'payment' => $payment,
        'orderStatusLabel' => $order['status'], 'courierProgress' => admin_delivery_progress($order, $payment, $shipment), 'courierView' => $view,
        'courierSchema' => ['state' => 'ready', 'message' => 'Structurally ready.']];
}

function deliveryUiRender(array $fixture): string
{
    extract($fixture, EXTR_SKIP);
    $adminCsrfToken = 'synthetic-csrf-"<&';
    $orderStatusLabels = ['PENDING' => 'Pending', 'CONFIRMED' => 'Confirmed', 'PACKED' => 'Packed',
        'SHIPPED' => 'Shipped', 'DELIVERED' => 'Delivered', 'CANCELLED' => 'Cancelled'];
    $_SESSION = ['admin_id' => 7, 'admin_name' => 'Offline UI Test'];
    ob_start();
    require __DIR__ . '/../../includes/ui.admin-delivery.php';
    return ob_get_clean();
}

function deliveryUiDocument(string $html): DOMXPath
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<meta charset="utf-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}

/** Execute the real primary-timeline markup without the page's DB/bootstrap boundary. */
function deliveryUiTimelineRender(array $fixture): string
{
    extract($fixture, EXTR_SKIP);
    $source = file_get_contents(__DIR__ . '/../../admin/order-view.php');
    foreach (['orderStatusLabels', 'courierMainStages'] as $variable) {
        if (preg_match('/\$' . $variable . '\s*=\s*\[.*?\];/s', $source, $assignment) !== 1) {
            throw new RuntimeException('Missing admin timeline presentation assignment: ' . $variable);
        }
        eval($assignment[0]);
    }
    $start = strpos($source, '<ol class="admin-order-progress"');
    $end = strpos($source, '</ol>', $start);
    if ($start === false || $end === false) throw new RuntimeException('Admin primary timeline boundary changed');
    $courierProgress = admin_delivery_progress($order, $payment, $shipment, $courierView['events']);
    ob_start();
    eval('?>' . substr($source, $start, $end + strlen('</ol>') - $start));
    return (string) ob_get_clean();
}

function deliveryUiActions(string $html): array
{
    $actions = [];
    foreach (deliveryUiDocument($html)->query('//form[@data-delivery-form]/input[@name="action"]') as $input) {
        $actions[] = $input->getAttribute('value');
    }
    sort($actions);
    return $actions;
}

$stockFixture = deliveryUiFixture('RETURNED_TO_SHOP', 3);
$stockFixture['courierView']['stock_schema'] = ['state' => 'ready', 'message' => 'Verified additive ledger'];
foreach ([901, 902] as $id) {
    $stockFixture['courierView']['inspection_actions'][$id] = delivery_failure('invalid_state');
    $stockFixture['courierView']['stock_decisions'][$id] = ['inspect_stock' => ['ok' => true],
        'resolve_hold' => ['ok' => true], 'make_available' => return_stock_failure('inspection_required')];
    $stockFixture['courierView']['stock_actions'][$id] = return_stock_failure('inspection_required');
}
$html = deliveryUiRender($stockFixture);
$xpath = deliveryUiDocument($html);
deliveryUiCheck('independent stock inspection and reservation decisions use the shared renderer',
    deliveryUiActions($html) === ['inspect_stock', 'inspect_stock', 'resolve_hold', 'resolve_hold']);
deliveryUiCheck('all stock forms use the guarded stock API, not courier transitions',
    $xpath->query('//form[@data-delivery-form][@action="api/return-stock-action.php"]')->length === 4
    && $xpath->query('//form[@data-delivery-form][@action="api/delivery-action.php"]')->length === 0);
deliveryUiCheck('stock inspection has PASSED evidence without obsolete approval choices',
    $xpath->query('//select[@name="outcome"]/option[@value="PASSED"]')->length === 2
    && $xpath->query('//option[@value="RETRY_APPROVED"]')->length === 0);
deliveryUiCheck('stock and reservation forms carry required reasons and actual item identities',
    $xpath->query('//form[@data-delivery-form]/input[@name="order_item_id"][@value="901"]')->length === 2
    && $xpath->query('//form[@data-delivery-form]/input[@name="order_item_id"][@value="902"]')->length === 2
    && $xpath->query('//form[@data-delivery-form]//textarea[@name="reason"][@required]')->length === 4);
deliveryUiCheck('all independent actions preserve returned courier revision rather than inventing stock stages',
    $xpath->query('//form[@data-delivery-form]/input[@name="stage"][@value="RETURNED_TO_SHOP"]')->length === 4
    && $xpath->query('//form[@data-delivery-form]/input[@name="revision"][@value="8"]')->length === 4);
deliveryUiCheck('reservation abandonment is item-specific and not immediate stock release',
    str_contains($html, 'abandon only this returned item') && str_contains($html, 'Other items, order status, payment and shipping fees do not change')
    && str_contains($html, 'The garment remains unavailable until a separate stock release passes'));
deliveryUiCheck('unmet independent prerequisites do not expose any stock-release submit action',
    $xpath->query('//form[input[@name="action"][@value="make_available"]]')->length === 0
    && str_contains($html, 'A separate PASSED stock inspection'));

foreach ([901, 902] as $id) {
    foreach (['INSPECTION' => 'PASSED', 'HOLD_RESOLVED' => 'RESOLVED'] as $kind => $outcome) {
        $stockFixture['courierView']['stock_records'][$id][$kind] = ['event_kind' => $kind, 'outcome' => $outcome,
            'previous_inventory_state' => 'SOLD', 'resulting_inventory_state' => 'SOLD',
            'created_at' => '2026-10-22 16:00:00', 'admin_id' => 7, 'reason' => 'Independent evidence <script>private</script>'];
    }
    $stockFixture['courierView']['stock_actions'][$id] = ['ok' => true];
}
$html = deliveryUiRender($stockFixture);
$xpath = deliveryUiDocument($html);
deliveryUiCheck('completed prerequisites leave only the explicit primary stock-release action',
    deliveryUiActions($html) === ['make_available', 'make_available']);
deliveryUiCheck('release uses the stock writer without overlapping decision dropdowns',
    $xpath->query('//form[@action="api/return-stock-action.php"][input[@name="action"][@value="make_available"]]')->length === 2
    && $xpath->query('//select[@name="outcome"]')->length === 0);
$blockedStockFixture = $stockFixture;
$blockedStockFixture['courierView']['stock_schema'] = ['state' => 'blocked', 'message' => 'Incomplete additive ledger'];
$blockedStockHtml = deliveryUiRender($blockedStockFixture);
deliveryUiCheck('unverified additive schema disables even supplied successful stock eligibility results',
    deliveryUiActions($blockedStockHtml) === [] && str_contains($blockedStockHtml, 'Stock release blocked.')
    && str_contains($blockedStockHtml, 'Incomplete additive ledger'));
deliveryUiCheck('immutable stock evidence retains actor, timestamp and escaped reason',
    str_contains($html, '2026-10-22 16:00:00, Admin #7') && str_contains($html, '&lt;script&gt;private&lt;/script&gt;')
    && $xpath->query('//script')->length === 0);

foreach ([901, 902] as $index => $id) {
    $stockFixture['courierView']['stock_records'][$id]['STOCK_RELEASED'] = array_replace(
        $stockFixture['courierView']['stock_records'][$id]['HOLD_RESOLVED'],
        ['event_kind' => 'STOCK_RELEASED', 'outcome' => 'AVAILABLE', 'resulting_inventory_state' => 'AVAILABLE']);
    $stockFixture['courierView']['items'][$index]['product_status'] = $index === 0 ? 'AVAILABLE' : 'SOLD';
    $stockFixture['courierView']['stock_actions'][$id] = return_stock_failure('already_recorded');
}
$html = deliveryUiRender($stockFixture);
$xpath = deliveryUiDocument($html);
deliveryUiCheck('audited released items expose no repeat release or reservation submit action', deliveryUiActions($html) === []);
deliveryUiCheck('release confirmation does not claim an already-resold item is currently available',
    $xpath->query('//strong[normalize-space(.)="Item available again."]')->length === 1
    && $xpath->query('//strong[normalize-space(.)="Stock release was recorded."]')->length === 1);
deliveryUiCheck('both completed release buttons stay disabled',
    $xpath->query('//button[@disabled][normalize-space(.)="Stock release already recorded"]')->length === 2);
deliveryUiCheck('operational summary distinguishes completed stock dispositions from financial state',
    str_contains($html, '2 of 2 stock dispositions complete') && str_contains($html, 'COD / PENDING')
    && str_contains($html, 'physical return does not establish financial resolution'));

foreach (['PENDING', 'CONFIRMED', 'PACKED'] as $status) {
    $fixture = deliveryUiFixture('NOT_SHIPPED');
    $fixture['order']['status'] = $status;
    $html = deliveryUiRender($fixture);
    deliveryUiCheck($status . ' keeps early fulfillment outside delivery API', deliveryUiActions($html) === [] && str_contains($html, 'Use the order controls above'));
}
foreach (['SHIPPED', 'IN_TRANSIT', 'DELIVERY_ATTEMPT_FAILED'] as $stage) {
    deliveryUiCheck($stage . ' renders only validated outing', deliveryUiActions(deliveryUiRender(deliveryUiFixture($stage))) === ['out_for_delivery']);
}
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $html = deliveryUiRender(deliveryUiFixture('OUT_FOR_DELIVERY', $attempt - 1));
    $xpath = deliveryUiDocument($html);
    deliveryUiCheck('attempt ' . $attempt . ' offers failure only; receipt is customer-driven', deliveryUiActions($html) === ['fail_attempt']
        && str_contains($html, 'Awaiting Customer Order Received.') && !str_contains($html, 'Mark Delivered'));
    deliveryUiCheck('attempt ' . $attempt . ' is a server-rendered token', $xpath->evaluate('string(//input[@name="attempt"]/@value)') === (string) $attempt);
    deliveryUiCheck('attempt ' . $attempt . ' requires failure evidence', $xpath->query('//textarea[@name="reason"][@required]')->length === 1);
}
$html = deliveryUiRender(deliveryUiFixture('DELIVERY_ATTEMPT_FAILED', 3));
deliveryUiCheck('third failure offers explicit return decision only', deliveryUiActions($html) === ['return_to_seller']);
deliveryUiCheck('third failure explains no automatic return or fourth attempt', str_contains($html, 'Record Return to Seller explicitly with a return reason') && str_contains($html, 'a fourth attempt is not allowed'));
deliveryUiCheck('explicit return decision requires its own reason', deliveryUiDocument($html)->query(
    '//form[@data-delivery-form][input[@name="action"][@value="return_to_seller"]]//textarea[@name="reason"][@required][@maxlength="255"]')->length === 1);
$html = deliveryUiRender(deliveryUiFixture('RETURN_TO_SELLER', 3));
deliveryUiCheck('return decision never offers a fourth outing/failure', deliveryUiActions($html) === ['confirm_return']);
deliveryUiCheck('return decision is not custody', str_contains($html, 'Return to Seller is not physical return.') && str_contains($html, 'every listed item has actually arrived'));
deliveryUiCheck('all items shown before physical custody confirmation', deliveryUiDocument($html)->query('//article[contains(@class,"admin-delivery__item")]')->length === 2);
deliveryUiCheck('custody requires explicit evidence without release', str_contains($html, 'Physical return evidence') && str_contains($html, 'Inventory disposition is separate from financial resolution.'));
deliveryUiCheck('physical return uses the required existing reason field', deliveryUiDocument($html)->query(
    '//form[@data-delivery-form][input[@name="action"][@value="confirm_return"]]//textarea[@name="reason"][@required][@maxlength="255"]')->length === 1);

$returned = deliveryUiFixture('RETURNED_TO_SHOP', 3);
$codUnmigrated = deliveryUiRender($returned);
deliveryUiCheck('COD has one stock-condition path, not an overlapping legacy inspection decision',
    deliveryUiActions($codUnmigrated) === [] && str_contains($codUnmigrated, 'Use the independent stock-condition inspection below.')
    && str_contains($codUnmigrated, 'Audited stock disposition is unavailable.'));
$returned['payment'] = ['payment_method' => 'GCASH', 'payment_status' => 'PAID'];
$html = deliveryUiRender($returned);
$xpath = deliveryUiDocument($html);
deliveryUiCheck('GCash condition evidence remains recordable per item while disposition is blocked', deliveryUiActions($html) === ['inspect_return', 'inspect_return']);
deliveryUiCheck('each manual inspection requires evidence and a fixed safe outcome', $xpath->query('//textarea[@name="reason"][@required]')->length === 2 && $xpath->query('//input[@name="outcome"][@value="MANUAL_REVIEW"]')->length === 2);
deliveryUiCheck('all item forms include their real order-item identity', $xpath->evaluate('string(//input[@name="order_item_id"]/@value)') === '901' && str_contains($html, 'value="902"'));
deliveryUiCheck('no whole-order resolution inferred from inspections', str_contains($html, '0 of 2 order lines have a recorded legacy inspection') && str_contains($html, 'does not resolve other items or the entire order'));
deliveryUiCheck('inspection records only manual review, never new redelivery approval', $xpath->query('//input[@name="outcome"][@value="MANUAL_REVIEW"]')->length === 2 && $xpath->query('//option[@value="RETRY_APPROVED"]')->length === 0);
deliveryUiCheck('manual review keeps inventory and payment unchanged', str_contains($html, 'blocks stock release; it is not a passed stock-condition inspection') && str_contains($html, 'No inventory, reservation or payment is changed'));
deliveryUiCheck('each returned item has a primary disabled stock disposition button', $xpath->query(
    '//article[contains(@class,"admin-delivery__item")]//button[@type="button"][@disabled][contains(@class,"btn-primary")][normalize-space(.)="Make Item Available Again"]')->length === 2);
deliveryUiCheck('blocked stock release renders no submit form or action tokens', $xpath->query(
    '//form[@data-delivery-form][input[@name="action"][@value="make_available"]]')->length === 0);
foreach ([901, 902] as $id) {
    deliveryUiCheck('item ' . $id . ' button points to actual item blockers', $xpath->query(
        '//button[@disabled][@aria-describedby="stock-blocker-' . $id . '"]')->length === 1
        && str_contains($xpath->evaluate('string(//*[@id="stock-blocker-' . $id . '"])'), 'Item #' . $id . ': independent passed release inspection is missing.')
        && str_contains($xpath->evaluate('string(//*[@id="stock-blocker-' . $id . '"])'), 'Item #' . $id . ': originating returned-order hold is unresolved.'));
}
deliveryUiCheck('returned summary closes journey without receipt or sale fabrication', str_contains($html, 'Return journey complete; order resolution is separate.')
    && str_contains($html, 'Not confirmed; no receipt action expected for this returned parcel')
    && str_contains($html, 'Not finalized; return/order safeguards block sale'));
$keys = [];
foreach ($xpath->query('//form[@data-delivery-form]') as $form) {
    $inputs = [];
    foreach ($form->getElementsByTagName('input') as $input) $inputs[$input->getAttribute('name')] = $input->getAttribute('value');
    deliveryUiCheck('form submits only actual API tokens for item ' . $inputs['order_item_id'],
        $inputs['order_id'] === '801' && $inputs['cycle'] === '1' && $inputs['revision'] === '8'
        && $inputs['stage'] === 'RETURNED_TO_SHOP' && $inputs['csrf_token'] === 'synthetic-csrf-"<&'
        && sales_is_valid_request_key($inputs['action_key']) && !isset($inputs['validateOnly']));
    $keys[] = $inputs['action_key'];
}
deliveryUiCheck('different item actions get distinct stable identities', count(array_unique($keys)) === 2);
deliveryUiCheck('no JavaScript never sends a raw API form', $xpath->query('//button[@data-delivery-submit][@disabled]')->length === 2 && str_contains($html, '<noscript>'));

$decision = ['outcome' => 'RETRY_APPROVED', 'reason' => 'Seams and fabric checked', 'created_at' => '2026-10-22 16:00:00', 'admin_id' => 7];
$returned['courierView']['inspections'][901] = $decision;
$returned['courierView']['inspection_actions'][901] = delivery_failure('invalid_state');
$html = deliveryUiRender($returned);
deliveryUiCheck('partial inspection leaves remaining item form only', deliveryUiActions($html) === ['inspect_return'] && str_contains($html, '1 of 2 order lines have a recorded legacy inspection'));
deliveryUiCheck('prior decision cannot be overwritten', deliveryUiDocument($html)->query('//input[@name="order_item_id"][@value="901"]')->length === 0);
$returned['courierView']['inspections'][902] = $decision;
$returned['courierView']['inspection_actions'][902] = delivery_failure('invalid_state');
$returned['courierView']['actions']['authorize_cycle'] = ['ok' => true];
deliveryUiCheck('stale supplied authorization eligibility cannot render a removed action', deliveryUiActions(deliveryUiRender($returned)) === []);
$approvedHtml = deliveryUiRender($returned);
deliveryUiCheck('historical approval never enables stock release', deliveryUiDocument($approvedHtml)->query('//button[@disabled][starts-with(@aria-describedby,"stock-blocker-")]')->length === 2
    && !in_array('make_available', deliveryUiActions($approvedHtml), true));
deliveryUiCheck('redelivery form, reason and explanatory section are absent', deliveryUiDocument($approvedHtml)->query(
    '//form[@data-delivery-form][input[@name="action"][@value="authorize_cycle"]]')->length === 0
    && !str_contains($approvedHtml, 'new-delivery-cycle authorization') && !str_contains($approvedHtml, 'Authorize New Delivery Cycle')
    && !str_contains($approvedHtml, 'Reason for authorizing another cycle') && !str_contains($approvedHtml, 'retry is optional')
    && !str_contains($approvedHtml, 'new-cycle authorization') && !str_contains($approvedHtml, 'Another delivery cycle')
    && str_contains($approvedHtml, 'no further delivery attempts'));
$returned['courierView']['inspections'][902]['outcome'] = 'MANUAL_REVIEW';
$html = deliveryUiRender($returned);
deliveryUiCheck('manual-review item has an item-specific stock-release blocker', deliveryUiActions($html) === [] && str_contains($html, 'MANUAL_REVIEW evidence blocks its stock release.'));
deliveryUiCheck('manual review uses shared warning pattern', str_contains($html, 'badge badge-warning">MANUAL_REVIEW') && str_contains($html, 'No override or automatic stock release'));

$historyFixture = deliveryUiFixture('RETURNED_TO_SHOP', 3, 2);
$historyFixture['payment'] = ['payment_method' => 'GCASH', 'payment_status' => 'PAID'];
$historyFixture['courierView']['events'][] = ['id' => 1, 'delivery_cycle' => 1, 'delivery_revision' => 8,
    'event_type' => 'RETURN_INSPECTED', 'attempt_number' => null, 'previous_state' => 'RETURNED_TO_SHOP',
    'resulting_state' => 'RETURNED_TO_SHOP', 'reason' => '<script>alert("unsafe")</script>',
    'created_at' => '2026-10-21 15:00:00', 'admin_id' => 7, 'admin_name' => 'Inspector <name>'];
$historyFixture['courierView']['evidence'][1][] = ['order_item_id' => 901, 'product_id' => 1001, 'outcome' => 'RETRY_APPROVED'];
$historyFixture['courierView']['items'][0]['product_name'] = 'Garment <img src=x onerror=alert(1)>';
$html = deliveryUiRender($historyFixture);
deliveryUiCheck('earlier-cycle approval remains in history, not current decisions', str_contains($html, 'Cycle 1 / revision 8') && str_contains($html, '0 of 2 order lines have a recorded legacy inspection in cycle 2'));
deliveryUiCheck('all current-cycle items still require inspection', deliveryUiActions($html) === ['inspect_return', 'inspect_return']);
deliveryUiCheck('history and item names are escaped', deliveryUiDocument($html)->query('//script|//img')->length === 0 && str_contains($html, '&lt;script&gt;') && str_contains($html, 'Inspector &lt;name&gt;'));
deliveryUiCheck('history includes state, item, timestamp and attribution', str_contains($html, 'Order item #901 / Product #1001') && str_contains($html, '2026-10-21 15:00:00') && str_contains($html, 'Admin #7'));

foreach (['legacy', 'blocked'] as $schemaState) {
    $fixture = deliveryUiFixture('OUT_FOR_DELIVERY');
    $fixture['courierSchema'] = ['state' => $schemaState, 'message' => 'Controlled rollout message.'];
    $html = deliveryUiRender($fixture);
    deliveryUiCheck($schemaState . ' schema never renders API forms, even with supplied eligibility', deliveryUiActions($html) === []);
    deliveryUiCheck($schemaState . ' explains safe migration path', str_contains($html, $schemaState === 'legacy' ? 'existing fulfillment controls above remain available' : 'Do not fall back to legacy delivery confirmation'));
}
$fixture = deliveryUiFixture('SHIPPED');
$fixture['courierView']['actions']['out_for_delivery'] = delivery_failure('manual_review');
$fixture['courierView']['blockers'][] = 'The payment record is missing.';
deliveryUiCheck('missing payment remains a manual-review state', deliveryUiActions(deliveryUiRender($fixture)) === [] && str_contains(deliveryUiRender($fixture), 'payment record is missing'));
$fixture['courierView']['error'] = 'Delivery records could not be safely loaded.';
deliveryUiCheck('read failures never enable actions', deliveryUiActions(deliveryUiRender($fixture)) === []);
$fixture = deliveryUiFixture('DELIVERED');
$html = deliveryUiRender($fixture);
deliveryUiCheck('delivered awaits customer receipt, not automatic collection', deliveryUiActions($html) === [] && str_contains($html, 'Delivered - Awaiting Customer Order Received.') && str_contains($html, 'href="#payment-heading"'));
$fixture['order']['received_at'] = '2026-10-24 15:00:00';
$fixture['courierView']['events'] = [['id' => 1, 'delivery_cycle' => 1, 'delivery_revision' => 7,
    'event_type' => 'OUT_FOR_DELIVERY', 'attempt_number' => null, 'previous_state' => 'SHIPPED',
    'resulting_state' => 'OUT_FOR_DELIVERY', 'reason' => null, 'created_at' => '2026-10-24 14:00:00',
    'admin_id' => 7, 'admin_name' => 'Offline reviewer']];
$receiptHtml = deliveryUiRender($fixture);
deliveryUiCheck('customer receipt appears once in admin history with its authoritative timestamp',
    deliveryUiDocument($receiptHtml)->query('//*[@data-customer-receipt-event]')->length === 1
    && str_contains($receiptHtml, '2026-10-24 15:00:00 (Philippine time), confirmed by the customer.'));
deliveryUiCheck('customer receipt enables no additional admin delivery path or inferred payment collection',
    deliveryUiActions($receiptHtml) === [] && str_contains($receiptHtml, 'Customer Order Received Confirmed.')
    && str_contains($receiptHtml, 'Payment collection and sale finalization are separate.'));
$fixture['order']['received_at'] = '2026-10-22 16:00:00';
deliveryUiCheck('recorded receipt directs to unchanged payment section', str_contains(deliveryUiRender($fixture), 'existing payment collection or sale finalization controls separately'));

$adminReturnClosure = 'This parcel has been returned to Hopia Fits, and the current courier delivery journey has ended. This is a return outcome, not delivery to the customer or a completed sale. Item inspection, stock disposition and order/payment resolution remain separate.';
$adminClosurePath = '//section[contains(@class,"admin-delivery__closure")]';
foreach (['SHIPPED', 'OUT_FOR_DELIVERY', 'RETURN_TO_SELLER', 'RETURNED_TO_SHOP', 'DELIVERED'] as $stage) {
    $fixture = deliveryUiFixture($stage, 3);
    $html = deliveryUiRender($fixture);
    $xpath = deliveryUiDocument($html);
    deliveryUiCheck($stage . ' has an explicit return closing statement only after shop custody',
        $xpath->query($adminClosurePath)->length === ($stage === 'RETURNED_TO_SHOP' ? 1 : 0)
        && ($stage !== 'RETURNED_TO_SHOP'
            || (trim($xpath->evaluate('string(' . $adminClosurePath . '/p)')) === $adminReturnClosure
                && $xpath->evaluate('string(' . $adminClosurePath . '/@aria-labelledby)') === 'admin-return-journey-heading'
                && $xpath->query('//details[summary/strong[contains(.,"Delivery history")]]/following-sibling::*[1][contains(@class,"admin-delivery__closure")]')->length === 1)));
}

$timelineFixture = deliveryUiFixture('RETURNED_TO_SHOP', 3, 2);
$timelineFixture['order']['received_at'] = '2026-10-21 16:00:00';
$timelineFixture['order']['has_completed_sale'] = 1;
$timelineFixture['payment']['payment_status'] = 'PAID';
$timelineFixture['courierView']['events'] = [
    ['id' => 1, 'delivery_cycle' => 1, 'delivery_revision' => 8, 'event_type' => 'DELIVERED',
        'attempt_number' => null, 'previous_state' => 'OUT_FOR_DELIVERY', 'resulting_state' => 'DELIVERED',
        'reason' => null, 'created_at' => '2026-10-21 15:00:00', 'admin_id' => 7, 'admin_name' => 'Courier reviewer'],
    ['id' => 3, 'delivery_cycle' => 2, 'delivery_revision' => 8, 'event_type' => 'RETURNED_TO_SHOP',
        'attempt_number' => null, 'previous_state' => 'RETURN_TO_SELLER', 'resulting_state' => 'RETURNED_TO_SHOP',
        'reason' => 'All physical items returned', 'created_at' => '2026-10-22 15:00:00', 'admin_id' => 7, 'admin_name' => 'Return reviewer'],
];
$adminStepsPath = '//ol[@class="admin-order-progress"]/li';
$sparseTimeline = deliveryUiDocument(deliveryUiTimelineRender($timelineFixture));
deliveryUiCheck('admin prior-cycle Delivered and stored SHIPPED cannot complete current returned dispatch',
    $sparseTimeline->query($adminStepsPath . '[contains(@class,"is-complete")]')->length === 2
    && $sparseTimeline->query($adminStepsPath . '[contains(@class,"is-unreached")][@aria-disabled="true"]')->length === 5
    && $sparseTimeline->query($adminStepsPath . '[@aria-current]')->length === 0);
foreach (['Delivered', 'Completed'] as $label) {
    deliveryUiCheck('current returned admin ' . $label . ' is muted and disabled despite historical receipt/payment/sale',
        $sparseTimeline->query($adminStepsPath . '[contains(@class,"is-unreached")][@aria-disabled="true"][.//span[normalize-space(.)="' . $label . '"]]')->length === 1);
}
$timelineFixture['shipment']['shipped_at'] = '2026-10-22 09:00:00';
$dispatchedTimeline = deliveryUiDocument(deliveryUiTimelineRender($timelineFixture));
deliveryUiCheck('valid dispatch logically completes admin preparation but not Delivered or Completed',
    $dispatchedTimeline->query($adminStepsPath . '[contains(@class,"is-complete")]')->length === 5
    && $dispatchedTimeline->query($adminStepsPath . '[@aria-disabled="true"]')->length === 2);
$timelineFixture['shipment']['shipped_at'] = null;
$outgoingEvent = $timelineFixture['courierView']['events'][1];
$outgoingEvent['id'] = 2;
$outgoingEvent['delivery_revision'] = 7;
$outgoingEvent['event_type'] = $outgoingEvent['resulting_state'] = 'OUT_FOR_DELIVERY';
$outgoingEvent['previous_state'] = 'SHIPPED';
$outgoingEvent['created_at'] = '2026-10-22 10:00:00';
$outgoingEvent['reason'] = null;
array_splice($timelineFixture['courierView']['events'], 1, 0, [$outgoingEvent]);
$currentTimeline = deliveryUiDocument(deliveryUiTimelineRender($timelineFixture));
deliveryUiCheck('admin current-cycle courier evidence reaches dispatch independently of timestamp',
    $currentTimeline->query($adminStepsPath . '[contains(@class,"is-complete")]')->length === 5
    && $currentTimeline->query($adminStepsPath . '[@aria-disabled="true"]')->length === 2);
$timelineFixture['courierProgress'] = admin_delivery_progress($timelineFixture['order'], $timelineFixture['payment'],
    $timelineFixture['shipment'], $timelineFixture['courierView']['events']);
$historyHtml = deliveryUiRender($timelineFixture);
$historyXPath = deliveryUiDocument($historyHtml);
deliveryUiCheck('admin keeps prior Delivered ledger but identifies current return as final courier outcome',
    $historyXPath->query('//ol[@class="admin-delivery__history"]/li')->length === 3
    && trim($historyXPath->evaluate('string(//li[@aria-current="step"]//strong)')) === 'Returned to Shop'
    && str_contains($historyXPath->evaluate('string(//li[@aria-current="step"])'), 'Final courier outcome')
    && $historyXPath->query('//section[contains(@class,"admin-delivery__cycle")][contains(@class,"is-closed")][@aria-labelledby="admin-delivery-cycle-2"]')->length === 1);
$inspectionEvent = $timelineFixture['courierView']['events'][2];
$inspectionEvent['id'] = 4;
$inspectionEvent['delivery_revision'] = $timelineFixture['shipment']['delivery_revision'] = 9;
$inspectionEvent['event_type'] = 'RETURN_INSPECTED';
$inspectionEvent['previous_state'] = 'RETURNED_TO_SHOP';
$inspectionEvent['reason'] = 'Item-specific condition checked';
$inspectionEvent['created_at'] = '2026-10-22 16:00:00';
$timelineFixture['courierView']['events'][] = $inspectionEvent;
$timelineFixture['courierProgress'] = admin_delivery_progress($timelineFixture['order'], $timelineFixture['payment'],
    $timelineFixture['shipment'], $timelineFixture['courierView']['events']);
$inspectedXPath = deliveryUiDocument(deliveryUiRender($timelineFixture));
deliveryUiCheck('internal inspection does not replace the final physical courier outcome',
    admin_delivery_progress($timelineFixture['order'], $timelineFixture['payment'], $timelineFixture['shipment'],
        $timelineFixture['courierView']['events'])['courier_label'] === 'Returned to Shop');
deliveryUiCheck('private admin inspection remains in full ledger without reopening returned closure',
    $inspectedXPath->query('//ol[@class="admin-delivery__history"]/li')->length === 4
    && trim($inspectedXPath->evaluate('string(//li[@aria-current="step"]//strong)')) === 'Returned Item Checked'
    && str_contains($inspectedXPath->evaluate('string(//li[@aria-current="step"])'), 'Item-specific condition checked')
    && trim($inspectedXPath->evaluate('string(' . $adminClosurePath . '/p)')) === $adminReturnClosure);
$timelineFixture['shipment']['shipment_status'] = 'RETURN_TO_SELLER';
$returningTimeline = deliveryUiDocument(deliveryUiTimelineRender($timelineFixture));
deliveryUiCheck('admin Return to Seller remains In Transit, never delivered or completed',
    $returningTimeline->query($adminStepsPath . '[contains(@class,"is-complete")]')->length === 4
    && trim($returningTimeline->evaluate('string(' . $adminStepsPath . '[@aria-current="step"]//span[@class="admin-order-progress__label"])')) === 'In Transit'
    && $returningTimeline->query($adminStepsPath . '[@aria-disabled="true"]')->length === 2);
foreach (['SHIPPED', 'OUT_FOR_DELIVERY', 'DELIVERED'] as $stage) {
    $normalTimeline = deliveryUiDocument(deliveryUiTimelineRender(deliveryUiFixture($stage)));
    deliveryUiCheck('normal admin ' . $stage . ' timeline retains current-only rendering',
        $normalTimeline->query($adminStepsPath . '[@aria-current="step"]')->length === 1
        && $normalTimeline->query($adminStepsPath . '[contains(@class,"is-complete") or contains(@class,"is-unreached") or @aria-disabled]')->length === 0);
}

// Execute ONLY rejection paths. open_basedir forbids vendor/bootstrap access if
// a future controller change accidentally tries to connect before validation.
$root = dirname(__DIR__, 2);
$validSession = ['admin_id' => 7, 'admin_csrf_token' => 'test-token'];
$validPost = ['csrf_token' => 'test-token', 'order_id' => 801, 'cycle' => 1, 'revision' => 0,
    'action' => 'out_for_delivery', 'stage' => 'SHIPPED', 'action_key' => str_repeat('a', 32)];
$apiCases = [
    ['anonymous', [], 'POST', [], 401, 'auth'],
    ['GET', $validSession, 'GET', [], 405, 'method'],
    ['missing CSRF', $validSession, 'POST', [], 403, 'csrf'],
    ['array CSRF', $validSession, 'POST', ['csrf_token' => ['bad']], 403, 'csrf'],
    ['invalid order', $validSession, 'POST', array_replace($validPost, ['order_id' => 0]), 422, 'bad_request'],
    ['stale-shaped negative revision', $validSession, 'POST', array_replace($validPost, ['revision' => -1]), 422, 'bad_request'],
    ['array action', $validSession, 'POST', array_replace($validPost, ['action' => []]), 422, 'bad_request'],
    ['array stage', $validSession, 'POST', array_replace($validPost, ['stage' => []]), 422, 'bad_request'],
    ['array reason', $validSession, 'POST', array_replace($validPost, ['reason' => []]), 422, 'bad_request'],
    ['invalid attempt', $validSession, 'POST', array_replace($validPost, ['attempt' => 'x']), 422, 'bad_request'],
    ['array outcome', $validSession, 'POST', array_replace($validPost, ['outcome' => []]), 422, 'bad_request'],
    ['release array item identity', $validSession, 'POST', array_replace($validPost, ['action' => 'make_available', 'order_item_id' => []]), 422, 'bad_request'],
    ['release array evidence', $validSession, 'POST', array_replace($validPost, ['action' => 'make_available', 'order_item_id' => 901, 'reason' => []]), 422, 'bad_request'],
    ['return decision array evidence', $validSession, 'POST', array_replace($validPost, ['action' => 'return_to_seller', 'reason' => []]), 422, 'bad_request'],
    ['removed cycle authorization', $validSession, 'POST', array_replace($validPost, ['action' => 'authorize_cycle', 'stage' => 'RETURNED_TO_SHOP', 'reason' => 'Old saved request']), 422, 'bad_request'],
    ['manual Delivered removed', $validSession, 'POST', array_replace($validPost, ['action' => 'delivered', 'stage' => 'OUT_FOR_DELIVERY']), 422, 'bad_request'],
    ['customer receipt cannot use admin API', $validSession, 'POST', array_replace($validPost, ['action' => 'confirm_receipt', 'stage' => 'OUT_FOR_DELIVERY']), 422, 'bad_request'],
    ['removed inspection approval', $validSession, 'POST', array_replace($validPost, ['action' => 'inspect_return', 'stage' => 'RETURNED_TO_SHOP', 'order_item_id' => 901, 'outcome' => 'RETRY_APPROVED', 'reason' => 'Old saved approval']), 422, 'bad_request'],
];
$memorySession = <<<'PHP'
ini_set('session.use_cookies', '0');
session_set_save_handler(new class implements SessionHandlerInterface {
    private string $data = '';
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return $this->data; }
    public function write(string $id, string $data): bool { $this->data = $data; return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
}, true);
session_id('isolated-delivery-api-test');
session_start();
PHP;
foreach ($apiCases as [$name, $session, $method, $post, $status, $reason]) {
    $script = 'ini_set("open_basedir", ' . var_export($root . '/includes' . PATH_SEPARATOR . $root . '/admin/api', true) . ');'
        . $memorySession . '$_SESSION=' . var_export($session, true) . ';session_write_close();'
        . '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';$_POST=' . var_export($post, true) . ';'
        . 'register_shutdown_function(static function(){echo "\nSTATUS=".http_response_code();});'
        . 'require ' . var_export($root . '/admin/api/delivery-action.php', true) . ';';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $script],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated PHP rejection test');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    [$json, $actualStatus] = array_pad(explode("\nSTATUS=", $output), 2, '');
    $result = json_decode($json, true);
    $passed = $exit === 0 && $stderr === '' && (int) $actualStatus === $status
        && ($result['ok'] ?? null) === false && ($result['reason'] ?? '') === $reason;
    deliveryUiCheck('executed API rejection: ' . $name, $passed);
    if (!$passed) echo '  subprocess exit=' . $exit . ' stdout=' . $output . ' stderr=' . $stderr . PHP_EOL;
}

foreach ([
    ['anonymous customer', [], 'POST', []],
    ['receipt GET never confirms', ['customer_id' => 8, 'csrf_token' => 'receipt-token'], 'GET', []],
    ['missing customer CSRF', ['customer_id' => 8, 'csrf_token' => 'receipt-token'], 'POST', ['order_id' => 801]],
    ['forged customer CSRF', ['customer_id' => 8, 'csrf_token' => 'receipt-token'], 'POST', ['order_id' => 801, 'csrf_token' => 'forged']],
    ['array customer CSRF', ['customer_id' => 8, 'csrf_token' => 'receipt-token'], 'POST', ['order_id' => 801, 'csrf_token' => ['bad']]],
] as [$name, $session, $method, $post]) {
    $script = 'ini_set("open_basedir", ' . var_export($root . '/includes' . PATH_SEPARATOR . $root . '/customer', true) . ');'
        . $memorySession . '$_SESSION=' . var_export($session, true) . ';session_write_close();'
        . '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';$_POST=' . var_export($post, true) . ';'
        . 'register_shutdown_function(static function(){echo "DB_INITIALIZED=".(isset($GLOBALS["pdo"]) ? "1" : "0");});'
        . 'require ' . var_export($root . '/customer/order-received.php', true) . ';';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $script],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated receipt rejection test');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    deliveryUiCheck('executed customer receipt rejection before database bootstrap: ' . $name,
        $exit === 0 && $stderr === '' && $output === 'DB_INITIALIZED=0');
}

$js = file_get_contents($root . '/js/admin-delivery.js');
$api = file_get_contents($root . '/admin/api/delivery-action.php');
deliveryUiCheck('browser contains no delivery state machine or attempt counter', !str_contains($js, 'failed_attempts') && !str_contains($js, 'OUT_FOR_DELIVERY') && !str_contains($js, 'RETURNED_TO_SHOP'));
deliveryUiCheck('browser never regenerates action identity', !str_contains($js, 'randomUUID') && !str_contains($js, 'getRandomValues'));
deliveryUiCheck('error recovery forces a fresh document request instead of a fragment-only navigation',
    str_contains($js, "destination.searchParams.set('delivery_review', String(Date.now()))")
    && str_contains($js, 'window.location.replace(destination.href)')
    && preg_match('/function reloadWithError\(message\).*?freshOrderGet\(\);/s', $js) === 1);
deliveryUiCheck('restored browser pages reread persisted state instead of navigating only a fragment',
    str_contains($js, 'if (event.persisted) freshOrderGet();'));
deliveryUiCheck('HTTP API never forwards read-only preview flag', !str_contains($api, 'validateOnly'));
deliveryUiCheck('HTTP schema gate precedes transition', strpos($api, 'delivery_schema_status($pdo)') < strpos($api, '$result = delivery_action('));
deliveryUiCheck('server feedback is only created after successful result', preg_match('/if \(!empty\(\$result\[\'ok\'\]\)\).*hopia_admin_success_redirect/s', $api) === 1);

if ($argc === 2) {
    $directory = sys_get_temp_dir() . '/hopia-delivery-ui-' . bin2hex(random_bytes(4));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create isolated browser fixture directory');
    define('HUPIA_BASE', 'file:///' . str_replace('\\', '/', $root));
    require_once $root . '/includes/ui.php';
    $ui_section = 'admin';
    $ui_active = 'orders.php';
    $body_class = 'admin-order-detail-page';
    $page_title = 'Offline Courier UI Test';
    $_SERVER['SCRIPT_NAME'] = '/admin/order-view.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    // Only the real header's unread count is stubbed, no inbox or DB writes.
    $pdo = new class extends PDO {
        public function __construct() {}
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
            if ($query !== 'SELECT COUNT(*) FROM admin_order_notifications WHERE read_at IS NULL') throw new RuntimeException('Unexpected fixture query');
            return new class extends PDOStatement { public function fetchColumn(int $column = 0): mixed { return 0; } };
        }
    };
    ob_start();
    require $root . '/includes/ui.head.php';
    $head = ob_get_clean();
    $head = str_replace('</head>', '<link rel="stylesheet" href="' . hopia_e(hopia_asset('css/admin-delivery.css')) . '"></head>', $head);
    ob_start();
    require $root . '/includes/ui.header.php';
    $header = ob_get_clean();
    ob_start();
    require $root . '/includes/ui.footer.php';
    $footer = ob_get_clean();
    // Avoid background inbox polling even in these offline file:// pages.
    $header = preg_replace('~<script[^>]+admin-notifications\.js[^>]*></script>~', '', $header);
    $footer = preg_replace('~<script[^>]+admin-notifications\.js[^>]*></script>~', '', $footer);
    $driver = file_get_contents(__DIR__ . '/admin_delivery_browser_test.js');
    foreach (['success', 'conflict', 'network', 'storage-denied', 'return', 'render-dark', 'render-light', 'legacy', 'blocked', 'live'] as $mode) {
        $fixture = deliveryUiFixture(str_starts_with($mode, 'render') || $mode === 'return' ? 'RETURNED_TO_SHOP' : 'OUT_FOR_DELIVERY',
            str_starts_with($mode, 'render') || $mode === 'return' ? 3 : 0);
        if ($mode === 'return' || str_starts_with($mode, 'render')) {
            $fixture['courierView']['stock_schema'] = ['state' => 'ready', 'message' => 'Synthetic verified ledger'];
            foreach ([901, 902] as $itemId) {
                $fixture['courierView']['stock_decisions'][$itemId] = ['inspect_stock' => ['ok' => true],
                    'resolve_hold' => return_stock_failure('inspection_required')];
            }
        }
        if (in_array($mode, ['legacy', 'blocked'], true)) $fixture['courierSchema'] = ['state' => $mode, 'message' => 'Coordinated migration review required.'];
        $html = $head . $header . '<div class="admin-order-detail"><header class="admin-order-detail__header"><div><a class="admin-page-header__back" href="#">Back to Orders</a><p class="admin-page-header__eyebrow">Order management / offline fixture</p><h1>ORDER #801</h1></div></header><div class="admin-order-detail__layout"><div class="admin-order-detail__main">'
            . deliveryUiRender($fixture) . '</div><aside class="admin-order-detail__aside"><section class="admin-order-detail__card"><h2>Order summary</h2><p>Synthetic records only</p></section></aside></div></div>';
        $liveScripts = '';
        if ($mode === 'live') {
            $event = ['id' => 1, 'delivery_cycle' => 1, 'delivery_revision' => 8, 'event_type' => 'OUT_FOR_DELIVERY',
                'attempt_number' => null, 'previous_state' => 'SHIPPED', 'resulting_state' => 'OUT_FOR_DELIVERY',
                'reason' => null, 'created_at' => '2026-10-24 14:00:00', 'admin_id' => 7, 'admin_name' => 'Offline reviewer'];
            $fixture['courierView']['events'] = [$event];
            $fixture['orderStatusLabel'] = 'In Transit';
            $liveDocument = static function (array $state, string $version): string {
                return '<div class="admin-order-detail" data-order-live data-version="' . $version . '">'
                    . '<header class="admin-order-detail__header" data-order-live-region="header"><h1>ORDER #801</h1><span class="badge">' . hopia_e($state['orderStatusLabel']) . '</span></header>'
                    . '<section class="admin-order-detail__card" data-order-live-region="status"><h2>Order status</h2><span class="badge">' . hopia_e($state['orderStatusLabel']) . '</span>'
                    . deliveryUiTimelineRender($state) . '</section>' . deliveryUiRender($state) . '</div>';
            };
            $fresh = deliveryUiFixture('DELIVERED');
            $fresh['order']['received_at'] = '2026-10-24 15:00:00';
            $fresh['orderStatusLabel'] = 'Delivered';
            $fresh['shipment']['delivery_revision'] = 9;
            $fresh['courierView']['events'] = [$event];
            $html = $head . $header . $liveDocument($fixture, str_repeat('a', 64));
            $liveScripts = '<script type="text/plain" id="order-live-production-source">' . file_get_contents($root . '/js/order-live.js') . '</script>'
                . '<script type="text/plain" id="admin-notifications-production-source">' . file_get_contents($root . '/js/admin-notifications.js') . '</script>'
                . '<script type="text/plain" id="order-live-fresh-source">' . $liveDocument($fresh, str_repeat('b', 64)) . '</script>';
        }
        $scripts = '<output id="browser-test-results" style="display:block;white-space:pre-wrap;overflow-wrap:anywhere"></output>'
            . '<script type="text/plain" id="delivery-production-source">' . $js . '</script>'
            . $liveScripts
            . '<script>window.deliveryFixtureMode=' . json_encode($mode) . ';' . $driver . '</script>';
        $html .= str_replace('</body>', $scripts . '</body>', $footer);
        file_put_contents($directory . '/' . $mode . '.html', $html);
    }
    echo 'OFFLINE_BROWSER_FIXTURES=' . $directory . PHP_EOL;
    if ($argv[1] === '--browser') {
        $browser = (getenv('ProgramFiles(x86)') ?: 'C:/Program Files (x86)') . '/Microsoft/Edge/Application/msedge.exe';
        if (!is_file($browser)) throw new RuntimeException('Installed Edge is unavailable. Run the generated fixtures with an approved browser.');
        $browserChecks = $browserFailures = 0;
        foreach (['success', 'conflict', 'network', 'storage-denied', 'return', 'render-dark', 'render-light', 'render-dark-mobile', 'legacy', 'blocked', 'live', 'live-mobile'] as $case) {
            $mobile = in_array($case, ['return', 'render-dark-mobile', 'blocked', 'live-mobile'], true);
            $source = $case === 'render-dark-mobile' ? 'render-dark' : ($case === 'live-mobile' ? 'live' : $case);
            $file = $directory . '/' . $source . '.html';
            if ($mobile) {
                // A real 390px browsing context avoids Edge's minimum outer-window width.
                $file = $directory . '/' . $case . '-390.html';
                $wrapper = '<!doctype html><html><head><meta charset="utf-8"><title>390px offline viewport</title></head><body style="margin:0">'
                    . '<iframe id="mobile-frame" title="Mobile courier fixture" src="' . $source . '.html" style="width:390px;height:844px;border:0"></iframe>'
                    . '<output id="browser-test-results" style="display:block;white-space:pre-wrap"></output>'
                    . '<script>window.addEventListener("message", function(event) { if (event.source !== document.getElementById("mobile-frame").contentWindow || !event.data.deliveryBrowserResult) return; var result=document.getElementById("browser-test-results"); result.textContent=event.data.text; result.dataset.checks=event.data.checks; result.dataset.failures=event.data.failures; result.dataset.width=event.data.width; });</script></body></html>';
                file_put_contents($file, $wrapper);
            }
            $command = [$browser, '--headless', '--disable-gpu', '--disable-extensions', '--disable-background-networking',
                '--no-first-run', '--no-default-browser-check', '--allow-file-access-from-files', '--force-device-scale-factor=1',
                '--user-data-dir=' . $directory . '/profile-' . $case, '--window-size=1440,1000', '--virtual-time-budget=8000', '--timeout=15000', '--dump-dom'];
            if (str_starts_with($case, 'render')) $command[] = '--screenshot=' . $directory . '/' . $case . '.png';
            $command[] = 'file:///' . str_replace('\\', '/', $file);
            // Edge can fill a Windows stderr pipe while stdout is being read.
            // Capture both streams in owned temp files so neither can deadlock.
            $resultFile = $directory . '/' . $case . '-result.html';
            $process = proc_open($command, [['pipe', 'r'], ['file', $resultFile, 'w'],
                ['file', $directory . '/' . $case . '-browser.log', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start isolated browser');
            fclose($pipes[0]);
            $exit = proc_close($process);
            $domOutput = file_get_contents($resultFile);
            $node = deliveryUiDocument($domOutput)->query('//*[@id="browser-test-results"]')->item(0);
            $count = $node !== null ? (int) $node->getAttribute('data-checks') : 0;
            $failed = $node !== null ? (int) $node->getAttribute('data-failures') : 1;
            $width = $node !== null ? (int) $node->getAttribute('data-width') : 0;
            $passed = $exit === 0 && $count > 0 && $failed === 0 && (!$mobile || $width === 390);
            deliveryUiCheck('offline browser ' . $case . ': ' . $count . ' checks / ' . $failed . ' failures, viewport ' . $width, $passed);
            if (!$passed) echo ($node !== null ? $node->textContent : 'Browser returned no completed test result.') . PHP_EOL;
            $browserChecks += $count;
            $browserFailures += $failed;
        }
        echo "BROWSER_TOTAL: {$browserChecks} checks, {$browserFailures} failures. Fetch/navigation mocked; real DOM/CSS in Edge.\n";
    }
}
echo "{$checks} checks, {$failures} failures. DB-free rendering/rejection tests only; no positive HTTP or concurrency claim.\n";
exit($failures === 0 ? 0 : 1);
