<?php

// Included only for courier orders. The view is advisory; forms use the delivery API.
$deliveryLabels = admin_delivery_labels();
$deliveryDefinitions = admin_delivery_actions();
$stockDefinitions = admin_return_stock_actions();
$deliveryEscape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$deliveryStage = (string) ($shipment['shipment_status'] ?? '');
$deliveryDisplayStage = $courierProgress['courier_stage'] ?? $deliveryStage;
$deliveryCycle = (int) ($shipment['delivery_cycle'] ?? 0);
$deliveryAttempts = filter_var($shipment['failed_attempts'] ?? null, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 3]]);
$deliveryIsReturn = in_array($deliveryStage, ['RETURN_TO_SELLER', 'RETURNED_TO_SHOP'], true);
$deliveryGuidance = match ($deliveryDisplayStage) {
    'DELIVERY_ATTEMPT_FAILED' => $deliveryAttempts === 3
        ? 'Three failed attempts are recorded. This delivery cycle is exhausted. Record Return to Seller explicitly with a return reason; a fourth attempt is not allowed.'
        : 'The latest attempt failed. Review the courier reason before explicitly starting the next permitted attempt.',
    'RETURN_TO_SELLER' => 'The current delivery cycle is exhausted and the parcel is being returned. Ordinary delivery is not active; physical arrival at the shop still needs confirmation.',
    'RETURNED_TO_SHOP' => 'Physical return is recorded and the delivery journey has ended. Review item inspection, reservation, payment and stock-disposition requirements separately.',
    'RETURN_INSPECTED' => 'Inspection is recorded. Review each item and any unresolved safeguards. Refund, inventory release and sale finalization remain separate.',
    'DELIVERED' => 'Courier delivery is closed. Earlier delivery cycles and return events remain historical records; customer receipt and applicable payment/sale finalization are separate.',
    default => 'Shipped records dispatch from the shop. The existing Out for Delivery action starts or continues In Transit. Attempts, returns and inspection stay inside this workflow; they are not additional main order stages.',
};
$deliveryHistoryCycles = [];
foreach ($courierView['events'] ?? [] as $deliveryHistoryEvent) {
    $deliveryHistoryCycles[(int) $deliveryHistoryEvent['delivery_cycle']][] = $deliveryHistoryEvent;
}
$deliveryReady = $courierSchema['state'] === 'ready' && $courierView !== null && $courierView['error'] === null;
$stockStructureReady = ($courierView['stock_schema']['state'] ?? 'legacy') === 'ready';
if ($deliveryStage === 'SHIPPED') {
    $deliveryDefinitions['out_for_delivery']['label'] = 'Start In Transit / Out for Delivery';
} elseif ($deliveryStage === 'DELIVERY_ATTEMPT_FAILED') {
    $deliveryDefinitions['out_for_delivery']['label'] = 'Next Permitted Attempt / Out for Delivery';
}
$deliveryForm = static function (string $action, ?array $item = null, bool $stock = false) use (
    $deliveryDefinitions, $stockDefinitions, $deliveryEscape, $order, $shipment, $adminCsrfToken, $deliveryCycle
): void {
    $definition = ($stock ? $stockDefinitions : $deliveryDefinitions)[$action];
    $formId = 'delivery-' . $action . ($item !== null ? '-' . (int) $item['id'] : '');
    ?>
    <form class="admin-delivery__form" method="POST" action="<?= $stock ? 'api/return-stock-action.php' : 'api/delivery-action.php' ?>" data-delivery-form
          data-draft-id="<?= $deliveryCycle ?>:<?= $deliveryEscape($action) ?>:<?= (int) ($item['id'] ?? 0) ?>"
          data-label="<?= $deliveryEscape($definition['label']) ?>"
          data-confirmation="<?= $deliveryEscape($definition['confirmation']) ?>"
          data-item-label="<?= $item !== null ? $deliveryEscape('Order item #' . (int) $item['id'] . ': ' . $item['product_name']) : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= $deliveryEscape($adminCsrfToken) ?>">
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <input type="hidden" name="action" value="<?= $deliveryEscape($action) ?>">
        <input type="hidden" name="cycle" value="<?= $deliveryCycle ?>">
        <input type="hidden" name="revision" value="<?= (int) $shipment['delivery_revision'] ?>">
        <input type="hidden" name="stage" value="<?= $deliveryEscape($shipment['shipment_status']) ?>">
        <input type="hidden" name="action_key" value="<?= bin2hex(random_bytes(16)) ?>">
        <?php if ($action === 'fail_attempt'): ?>
            <input type="hidden" name="attempt" value="<?= (int) $shipment['failed_attempts'] + 1 ?>">
            <p class="text-small">Recording attempt <strong><?= (int) $shipment['failed_attempts'] + 1 ?> of 3</strong>.
                <?= (int) $shipment['failed_attempts'] === 2 ? 'After this failure, record Return to Seller separately with its return reason. There is no fourth attempt in this cycle.' : 'Only record the actual courier-reported failure.' ?></p>
        <?php endif; ?>
        <?php if ($item !== null): ?>
            <input type="hidden" name="order_item_id" value="<?= (int) $item['id'] ?>">
        <?php endif; ?>
        <?php if ($stock && $action === 'inspect_stock'): ?>
            <div class="field">
                <label for="<?= $deliveryEscape($formId) ?>-outcome">Independent stock-release condition inspection</label>
                <select id="<?= $deliveryEscape($formId) ?>-outcome" name="outcome" required>
                    <option value="">Select after inspecting this garment</option>
                    <option value="PASSED">Passed - eligible condition for stock release</option>
                    <option value="MANUAL_REVIEW">MANUAL_REVIEW - block stock release</option>
                </select>
                <p class="hint">An immutable item-level condition record. Passing alone does not resolve the reservation or release stock.</p>
            </div>
        <?php endif; ?>
        <?php if ($item !== null && $action === 'inspect_return'): ?>
            <input type="hidden" name="outcome" value="MANUAL_REVIEW">
            <p class="hint">Records condition evidence for manual review using the existing inspection ledger. It blocks stock release and is not a passed stock-condition inspection. Eligible COD stock disposition uses the independent inspection below.</p>
        <?php endif; ?>
        <?php if ($definition['reason_label'] !== null): ?>
            <div class="field">
                <label for="<?= $deliveryEscape($formId) ?>-reason"><?= $deliveryEscape($definition['reason_label']) ?></label>
                <textarea id="<?= $deliveryEscape($formId) ?>-reason" name="reason" rows="3" maxlength="255" required
                          aria-describedby="<?= $deliveryEscape($formId) ?>-hint"></textarea>
                <p class="hint" id="<?= $deliveryEscape($formId) ?>-hint"><?= $action === 'fail_attempt'
                    ? 'Describe the courier-reported reason, for example customer unavailable or address not found. This reason is customer-visible: do not enter private admin notes or evidence.'
                    : 'Record what you checked and why this action is appropriate. Do not enter payment account details.' ?> Maximum 255 UTF-8 bytes.</p>
            </div>
        <?php endif; ?>
        <button class="btn <?= $action === 'fail_attempt' ? 'btn-secondary' : 'btn-primary' ?>" type="submit" data-delivery-submit disabled>
            <?= $deliveryEscape($definition['label']) ?>
        </button>
    </form>
    <?php
};
?>
<section class="admin-order-detail__card admin-delivery" id="courier-workflow" aria-labelledby="courier-workflow-heading"
         data-admin-delivery data-storage-key="hopia.admin.delivery.<?= (int) ($_SESSION['admin_id'] ?? 0) ?>.<?= (int) $order['id'] ?>"
         data-reload-url="order-view.php?id=<?= (int) $order['id'] ?>#courier-workflow">
    <div class="admin-order-detail__card-head">
        <div>
            <p class="admin-order-detail__eyebrow">In Transit / Local Courier</p>
            <h2 id="courier-workflow-heading" tabindex="-1">Courier delivery workflow</h2>
        </div>
        <span class="badge <?= $deliveryEscape(admin_delivery_badge($deliveryDisplayStage)) ?>">Current courier state: <?= $deliveryEscape($courierProgress['courier_label'] ?? $deliveryLabels[$deliveryDisplayStage] ?? 'No shipment record') ?></span>
    </div>
    <p class="text-small muted"><?= $deliveryEscape($deliveryGuidance) ?></p>
    <div class="alert alert-error" role="alert" tabindex="-1" data-delivery-feedback hidden></div>
    <p data-delivery-recovery hidden><a class="btn btn-secondary btn-sm" href="order-view.php?id=<?= (int) $order['id'] ?>#courier-workflow">Reload and Review Latest State</a></p>

    <?php if ($courierSchema['state'] !== 'ready'): ?>
        <div class="alert <?= $courierSchema['state'] === 'legacy' ? 'alert-info' : 'alert-warning' ?>" role="status">
            <strong><?= $courierSchema['state'] === 'legacy' ? 'Courier workflow is not active yet.' : 'Courier workflow is blocked for rollout review.' ?></strong>
            <?= $deliveryEscape($courierSchema['message']) ?>
            <?php if ($courierSchema['state'] === 'legacy'): ?>
                The existing fulfillment controls above remain available with their existing safeguards. Delivery attempts and return actions require the coordinated migration and UI rollout.
            <?php else: ?>
                Do not fall back to legacy delivery confirmation or change inventory manually. No schema changes are run by this page.
            <?php endif; ?>
        </div>
    <?php elseif (!$deliveryReady): ?>
        <p class="alert alert-warning" role="status"><?= $deliveryEscape($courierView['error'] ?? 'The shipment is missing. Manual review is required before any delivery or return action.') ?></p>
    <?php else: ?>
        <div class="admin-order-detail__status-meta">
            <div><span>Stored primary fulfillment stage</span><strong><?= $deliveryEscape($orderStatusLabels[$order['status']] ?? $order['status']) ?></strong></div>
            <div><span>Stored shipment state</span><strong><?= $deliveryEscape($deliveryLabels[$deliveryStage] ?? $deliveryStage) ?></strong></div>
            <div><span>Delivery cycle / revision</span><strong><?= $deliveryCycle ?> / <?= (int) $shipment['delivery_revision'] ?></strong></div>
            <div><span>Failed attempts in this cycle</span><strong><?= $deliveryAttempts !== false ? $deliveryAttempts . ' of 3' : 'Unknown - manual review' ?></strong></div>
            <div><span>Remaining attempt slots in this cycle</span><strong><?= $deliveryStage === 'DELIVERED' || $order['status'] === 'CANCELLED'
                ? 'Closed' : ($deliveryAttempts !== false ? 3 - $deliveryAttempts : 'Unknown') ?></strong></div>
            <?php if ($deliveryStage === 'OUT_FOR_DELIVERY' && $deliveryAttempts !== false && $deliveryAttempts < 3): ?>
                <div><span>Current attempt</span><strong><?= $deliveryAttempts + 1 ?> of 3</strong></div>
            <?php endif; ?>
        </div>
        <p class="text-small muted">Order status and shipment status are separate. There are at most three failed attempts in the current recorded delivery cycle. Return to Seller and physical return are explicit actions; previous events remain in history.</p>

        <?php if (in_array($order['status'], ['PENDING', 'CONFIRMED', 'PACKED'], true)): ?>
            <p class="alert alert-info" role="status">Use the order controls above to confirm, pack, verify every item and ship with a tracking number. The courier actions become available after shipment and backend validation.</p>
        <?php elseif ($order['status'] === 'DELIVERED' && $deliveryStage === 'DELIVERED'): ?>
            <div class="alert alert-info" role="status">
                <?php if ($order['received_at'] === null): ?>
                    <strong>Delivered - Awaiting Customer Order Received.</strong> Courier delivery does not confirm customer receipt, collect COD, or complete a sale.
                <?php else: ?>
                    <strong>Customer Order Received Confirmed.</strong> Delivery is closed; review the existing payment collection or sale finalization controls separately.
                <?php endif; ?>
                <a href="#payment-heading">Review payment and sale</a>.
            </div>
        <?php elseif ($order['status'] === 'CANCELLED'): ?>
            <p class="alert alert-warning" role="status">This order is cancelled. Delivery actions cannot revive it. Historical return evidence is reference only.</p>
        <?php endif; ?>

        <?php if ($deliveryStage === 'RETURN_TO_SELLER'): ?>
            <p class="alert alert-warning" role="status"><strong>Return to Seller is not physical return.</strong> Three failed attempts are recorded. No fourth attempt is available in this cycle. Confirm shop custody only when every listed item has actually arrived; partial or missing returns require manual review.</p>
        <?php elseif ($deliveryStage === 'RETURNED_TO_SHOP'): ?>
            <p class="alert alert-info" role="status"><strong>Return journey complete; order resolution is separate.</strong> The parcel is back at the shop. Review each item's inspection, reservation and stock disposition below. There are no further delivery attempts for this returned order.</p>
            <?php if ($deliveryDisplayStage === 'RETURN_INSPECTED'): ?>
                <p class="text-small"><strong>Returned Item Checked is recorded.</strong> Inspection alone does not authorize another delivery cycle. Further action remains pending; each item decision and all backend safeguards still apply.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        $deliveryBlock = null;
        if ($order['status'] === 'SHIPPED' || $deliveryIsReturn) {
            foreach ($courierView['actions'] as $deliveryResult) {
                if (!$deliveryResult['ok'] && !in_array($deliveryResult['reason'], ['invalid_state', 'attempt_limit'], true)) {
                    $deliveryBlock = $deliveryResult['message'];
                    break;
                }
            }
        }
        ?>
        <?php if ($deliveryBlock !== null || $courierView['blockers'] !== []): ?>
            <div class="alert alert-warning" role="status">
                <strong>Validation / manual review</strong>
                <?php if ($deliveryBlock !== null): ?><p><?= $deliveryEscape($deliveryBlock) ?></p><?php endif; ?>
                <?php if ($courierView['blockers'] !== []): ?>
                    <ul class="alert__list"><?php foreach ($courierView['blockers'] as $deliveryBlocker): ?><li><?= $deliveryEscape($deliveryBlocker) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($deliveryIsReturn): ?>
            <div class="admin-order-detail__actions" aria-labelledby="delivery-inspection-heading">
                <h3 id="delivery-inspection-heading">Physical items and current-cycle inspection</h3>
                <p class="text-small">Every order line is listed below. Multi-quantity or missing product links cannot establish physical identity. Earlier-cycle evidence is retained as history only.</p>
                <p class="text-small">Inspect each item, explicitly resolve its reservation and any applicable financial obligations, then release eligible stock separately. An item's disposition does not resolve other items or the entire order.</p>
                <p class="text-small"><strong><?= count($courierView['inspections']) ?> of <?= count($courierView['items']) ?> order lines have a recorded legacy inspection in cycle <?= $deliveryCycle ?>.</strong> Independent stock-condition inspections are shown below.</p>
                <?php if ($deliveryStage === 'RETURNED_TO_SHOP' && ($courierView['stock_schema']['state'] ?? 'legacy') !== 'ready'): ?>
                    <p class="alert alert-warning" role="status"><strong>Audited stock disposition is unavailable.</strong> <?= $deliveryEscape($courierView['stock_schema']['message'] ?? 'The additive return-stock ledger has not been installed and verified. No migration is applied automatically.') ?></p>
                <?php endif; ?>
                <?php if ($courierView['items'] === []): ?><p class="alert alert-warning">No physical item records are available. Manual review is required.</p><?php endif; ?>
                <?php foreach ($courierView['items'] as $deliveryItem): ?>
                    <?php $deliveryInspection = $courierView['inspections'][(int) $deliveryItem['id']] ?? null; ?>
                    <article class="admin-order-item admin-delivery__item">
                        <div class="admin-order-detail__card-head">
                            <div><h4><?= $deliveryEscape($deliveryItem['product_name']) ?></h4><p class="text-small muted">Order item #<?= (int) $deliveryItem['id'] ?> / Product <?= $deliveryItem['current_product_id'] !== null ? '#' . (int) $deliveryItem['current_product_id'] : 'link missing' ?></p></div>
                            <span class="badge <?= $deliveryInspection !== null ? $deliveryEscape(admin_delivery_badge($deliveryInspection['outcome'])) : 'badge-pending' ?>"><?= $deliveryInspection !== null ? $deliveryEscape($deliveryLabels[$deliveryInspection['outcome']] ?? $deliveryInspection['outcome']) : 'No legacy inspection recorded' ?></span>
                        </div>
                        <dl class="admin-order-detail__details admin-order-detail__details--inline">
                            <div><dt>Size / color (snapshot)</dt><dd><?= $deliveryEscape(($deliveryItem['size'] ?? '') . ' / ' . ($deliveryItem['color'] ?? '')) ?></dd></div>
                            <div><dt>Quantity</dt><dd><?= (int) $deliveryItem['quantity'] ?></dd></div>
                            <div><dt>Current barcode</dt><dd><?= $deliveryEscape($deliveryItem['barcode_value'] ?? 'Unavailable') ?></dd></div>
                            <div><dt>Inventory state</dt><dd><?= $deliveryEscape($deliveryItem['product_status'] ?? 'Missing product') ?><?= $deliveryItem['retired_at'] !== null ? ' / Retired' : '' ?></dd></div>
                            <div><dt>Current-cycle physical return</dt><dd><?= isset($courierView['returns'][(int) $deliveryItem['id']]) ? $deliveryEscape($courierView['returns'][(int) $deliveryItem['id']]['created_at']) : 'Not recorded' ?></dd></div>
                        </dl>
                        <details class="admin-order-detail__actions">
                        <summary><strong>Recorded item inspection / manual-review evidence</strong></summary>
                        <?php if ($deliveryInspection !== null): ?>
                            <p class="text-small"><strong>Recorded evidence:</strong> <?= $deliveryEscape($deliveryInspection['reason']) ?></p>
                            <p class="text-small muted"><?= $deliveryEscape($deliveryInspection['created_at']) ?> (Philippine time), Admin #<?= (int) $deliveryInspection['admin_id'] ?>. This decision is immutable for this cycle.</p>
                            <?php if ($deliveryInspection['outcome'] === 'MANUAL_REVIEW'): ?><p class="alert alert-warning">This item's unresolved MANUAL_REVIEW evidence blocks its stock release. No override or automatic stock release is available.</p><?php endif; ?>
                        <?php elseif (($payment['payment_method'] ?? '') === 'GCASH' && !empty($courierView['inspection_actions'][(int) $deliveryItem['id']]['ok'])): ?>
                            <?php $deliveryForm('inspect_return', $deliveryItem); ?>
                        <?php elseif (($payment['payment_method'] ?? '') === 'GCASH'): ?>
                            <p class="text-small muted"><?= $deliveryEscape($courierView['inspection_actions'][(int) $deliveryItem['id']]['message'] ?? 'Inspection is unavailable until physical return is confirmed and the backend safeguards pass.') ?></p>
                        <?php else: ?>
                            <p class="text-small muted">Use the independent stock-condition inspection below. Historical inspection evidence is retained here without creating another decision.</p>
                        <?php endif; ?>
                        </details>
                        <?php if ($deliveryStage === 'RETURNED_TO_SHOP'): ?>
                            <div class="admin-order-detail__actions">
                                <h4>Inventory disposition</h4>
                                <?php
                                $stockRecords = $courierView['stock_records'][(int) $deliveryItem['id']] ?? [];
                                $stockDecisions = $courierView['stock_decisions'][(int) $deliveryItem['id']] ?? [];
                                ?>
                                <?php if (isset($stockRecords['STOCK_RELEASED'])): ?>
                                    <p class="alert alert-success" role="status"><strong><?= $deliveryItem['product_status'] === 'AVAILABLE' ? 'Item available again.' : 'Stock release was recorded.' ?></strong>
                                        This original order no longer reserves this garment. Current inventory: <?= $deliveryEscape($deliveryItem['product_status']) ?>. No payment, cancellation or sale was finalized by that release.</p>
                                    <button class="btn btn-primary" type="button" disabled>Stock release already recorded</button>
                                <?php else: ?>
                                    <?php foreach (['inspect_stock' => 'INSPECTION', 'resolve_hold' => 'HOLD_RESOLVED'] as $stockAction => $recordKind): ?>
                                        <?php if (isset($stockRecords[$recordKind])): ?>
                                            <p class="text-small"><strong><?= $stockAction === 'inspect_stock' ? 'Stock inspection' : 'Returned reservation' ?>: <?= $deliveryEscape($stockRecords[$recordKind]['outcome']) ?>.</strong>
                                                <?= $stockAction === 'resolve_hold' ? 'Only this item reservation was abandoned. The garment stays unavailable until a separate stock release.' : 'The inspection does not release stock or resolve the reservation.' ?></p>
                                        <?php elseif ($stockStructureReady && !empty($stockDecisions[$stockAction]['ok'])): ?>
                                            <?php $deliveryForm($stockAction, $deliveryItem, true); ?>
                                        <?php else: ?>
                                            <p class="text-small"><strong><?= $deliveryEscape($stockDefinitions[$stockAction]['label']) ?> unavailable.</strong>
                                                <?= $deliveryEscape($stockDecisions[$stockAction]['message'] ?? 'The additive ledger and item eligibility must be verified first.') ?></p>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php $stockResult = $courierView['stock_actions'][(int) $deliveryItem['id']] ?? delivery_failure('manual_review'); ?>
                                <?php if (!$stockStructureReady && !empty($stockResult['ok'])) $stockResult = return_stock_failure('schema_unavailable', 503); ?>
                                <?php if ($stockStructureReady && !empty($stockResult['ok'])): ?>
                                    <?php $deliveryForm('make_available', $deliveryItem, true); ?>
                                <?php else: ?>
                                    <button class="btn btn-primary" type="button" disabled aria-describedby="stock-blocker-<?= (int) $deliveryItem['id'] ?>">Make Item Available Again</button>
                                    <div id="stock-blocker-<?= (int) $deliveryItem['id'] ?>" class="text-small" role="status">
                                        <strong>Stock release blocked. Item remains unavailable or requires review.</strong>
                                        <ul><?php foreach ($stockResult['blockers'] ?? [$stockResult['message']] as $stockBlocker): ?><li><?= $deliveryEscape($stockBlocker) ?></li><?php endforeach; ?></ul>
                                    </div>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($stockRecords !== []): ?>
                                    <details><summary>Audited current-cycle stock decisions</summary>
                                        <ul><?php foreach ($stockRecords as $stockRecord): ?><li><strong><?= $deliveryEscape($stockRecord['event_kind'] . ': ' . $stockRecord['outcome']) ?></strong>
                                            - <?= $deliveryEscape($stockRecord['previous_inventory_state'] . ' to ' . $stockRecord['resulting_inventory_state']) ?>;
                                            <?= $deliveryEscape($stockRecord['created_at']) ?>, Admin #<?= (int) $stockRecord['admin_id'] ?>;
                                            reason: <?= $deliveryEscape($stockRecord['reason']) ?>.</li><?php endforeach; ?></ul>
                                    </details>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="alert alert-warning" role="status">
                <strong>Inventory disposition is separate from financial resolution.</strong>
                Make Item Available Again is checked independently for each item. Only supported uncollected COD returns can use this reservation/disposition path. Paid GCash and collected-payment cases require a separate financial-resolution workflow, which is not provided here. Shipping fees and payment status remain unchanged. Do not use product editing, cancellation shortcuts or automatic refunds to bypass a blocker.
            </div>
        <?php endif; ?>

        <div class="admin-order-detail__actions">
            <h3><?= $deliveryStage === 'DELIVERED' || $order['status'] === 'CANCELLED'
                ? 'Courier delivery closed' : ($deliveryIsReturn ? 'Return handling actions' : 'Delivery attempt actions') ?></h3>
            <?php foreach ($deliveryDefinitions as $deliveryAction => $deliveryDefinition): ?>
                <?php if ($deliveryAction !== 'inspect_return' && !empty($courierView['actions'][$deliveryAction]['ok'])): ?>
                    <?php $deliveryForm($deliveryAction); ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <noscript><p class="alert alert-warning">JavaScript is required for the confirmation dialog and delivery API feedback. Delivery submissions are disabled; existing order and payment controls are unchanged.</p></noscript>
        </div>

        <div class="admin-order-detail__actions" aria-labelledby="courier-summary-heading">
            <h3 id="courier-summary-heading">Operational state summary</h3>
            <dl class="admin-order-detail__details admin-order-detail__details--inline">
                <div><dt>Courier journey</dt><dd><?= $deliveryStage === 'RETURNED_TO_SHOP' ? 'Return journey complete - physically back at the shop' : ($deliveryStage === 'DELIVERED' ? 'Delivery journey complete' : ($deliveryAttempts === 3 ? 'Delivery cycle exhausted - return handling pending' : 'Delivery in progress')) ?></dd></div>
                <div><dt>Item disposition</dt><dd><?= $deliveryStage === 'RETURNED_TO_SHOP'
                    ? count(array_filter($courierView['stock_records'] ?? [], static fn ($records) => isset($records['STOCK_RELEASED']))) . ' of ' . count($courierView['items']) . ' stock dispositions complete; see individual remaining safeguards'
                    : 'No stock release recorded by this workflow' ?></dd></div>
                <div><dt>Customer receipt</dt><dd><?= !empty($order['received_at']) ? 'Customer Order Received Confirmed' : ($deliveryIsReturn ? 'Not confirmed; no receipt action expected for this returned parcel' : 'Not confirmed') ?></dd></div>
                <div><dt>Payment / financial resolution</dt><dd><?= $deliveryEscape(($payment['payment_method'] ?? 'Missing payment') . ' / ' . ($payment['payment_status'] ?? 'Unknown')) ?><?= $deliveryIsReturn ? ' - physical return does not establish financial resolution' : '' ?></dd></div>
                <div><dt>Merchandise sale</dt><dd><?= !empty($order['has_completed_sale']) ? 'Committed sale recorded' : ($deliveryIsReturn ? 'Not finalized; return/order safeguards block sale' : 'Not finalized - separate admin payment/sale action') ?></dd></div>
            </dl>
        </div>

        <?php if (!empty($courierView['stock_events'])): ?>
            <details class="admin-order-detail__actions">
                <summary><strong>Inventory disposition audit - all delivery cycles</strong></summary>
                <ul><?php foreach ($courierView['stock_events'] as $stockEvent): ?><li>
                    <strong>Cycle <?= (int) $stockEvent['delivery_cycle'] ?> / Item #<?= (int) $stockEvent['order_item_id'] ?> / Product #<?= (int) $stockEvent['product_id'] ?>:
                        <?= $deliveryEscape($stockEvent['event_kind'] . ' - ' . $stockEvent['outcome']) ?></strong>
                    <?= $deliveryEscape($stockEvent['previous_inventory_state'] . ' to ' . $stockEvent['resulting_inventory_state']) ?>;
                    <?= $deliveryEscape($stockEvent['created_at']) ?>, Admin #<?= (int) $stockEvent['admin_id'] ?>;
                    <?= $deliveryEscape($stockEvent['reason']) ?>.
                </li><?php endforeach; ?></ul>
            </details>
        <?php endif; ?>

        <details class="admin-order-detail__actions" open>
            <summary><strong>Delivery history (<?= count($courierView['events']) ?> events)</strong></summary>
            <p class="text-small muted">Oldest first, across all cycles. Timestamps are Philippine time (UTC+8). Historical evidence is retained; initial cycle 1 / revision 0 does not fabricate earlier events.</p>
            <p class="text-small muted">Previous cycles are retained for reference. Only the highlighted latest update in the current recorded cycle describes the present courier state.</p>
            <?php if ($courierView['events'] === []): ?><p class="muted">No courier events have been recorded in this ledger. Earlier legacy shipment history was not backfilled.</p><?php endif; ?>
            <?php foreach ($deliveryHistoryCycles as $deliveryHistoryCycle => $deliveryHistoryEvents): ?>
                <?php $deliveryHistoryCycleIsCurrent = $deliveryCycle > 0 && $deliveryHistoryCycle === $deliveryCycle; ?>
                <section class="admin-delivery__cycle<?= $deliveryHistoryCycleIsCurrent ? ' is-current-cycle' : '' ?><?= $deliveryHistoryCycleIsCurrent && $deliveryStage === 'RETURNED_TO_SHOP' ? ' is-closed' : '' ?>" aria-labelledby="admin-delivery-cycle-<?= (int) $deliveryHistoryCycle ?>">
                    <h4 id="admin-delivery-cycle-<?= (int) $deliveryHistoryCycle ?>" class="admin-delivery__cycle-title">
                        Delivery cycle <?= (int) $deliveryHistoryCycle ?>
                        <span class="badge <?= $deliveryHistoryCycleIsCurrent ? 'badge-info' : 'badge' ?>"><?= $deliveryHistoryCycleIsCurrent ? 'Current cycle' : 'Previous cycle' ?></span>
                    </h4>
            <ol class="admin-delivery__history">
                <?php foreach ($deliveryHistoryEvents as $deliveryEvent): ?>
                    <?php $deliveryEventIsCurrent = $deliveryHistoryCycleIsCurrent && (string) $deliveryEvent['id'] === ($courierProgress['current_event'] ?? null)
                        && in_array($courierProgress['stage'] ?? null, ['SHIPPED', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true); ?>
                    <li<?= $deliveryEventIsCurrent ? ' class="is-current" aria-current="step"' : '' ?>>
                        <div class="admin-order-detail__card-head">
                            <strong><?= $deliveryEscape($deliveryEventIsCurrent && $deliveryEvent['event_type'] === 'DELIVERED'
                                ? ($courierProgress['courier_label'] ?? $deliveryLabels['DELIVERED'])
                                : ($deliveryLabels[$deliveryEvent['event_type']] ?? $deliveryEvent['event_type'])) ?><?= $deliveryEvent['event_type'] === 'DELIVERY_ATTEMPT_FAILED' && $deliveryEvent['attempt_number'] !== null ? ' - Attempt ' . (int) $deliveryEvent['attempt_number'] . ' of 3' : '' ?></strong>
                            <span class="badge badge-info">Cycle <?= (int) $deliveryEvent['delivery_cycle'] ?> / revision <?= (int) $deliveryEvent['delivery_revision'] ?></span>
                        </div>
                        <?php if ($deliveryEventIsCurrent): ?><p><span class="badge badge-info"><?= $deliveryEvent['event_type'] === 'RETURNED_TO_SHOP' ? 'Final courier outcome' : 'Latest courier update' ?></span></p><?php endif; ?>
                        <p class="text-small"><?= $deliveryEscape($deliveryLabels[$deliveryEvent['previous_state']] ?? $deliveryEvent['previous_state']) ?> &rarr; <?= $deliveryEscape($deliveryLabels[$deliveryEvent['resulting_state']] ?? $deliveryEvent['resulting_state']) ?></p>
                        <?php if ($deliveryEvent['reason'] !== null): ?><p class="text-small"><strong><?= $deliveryEvent['event_type'] === 'DELIVERY_ATTEMPT_FAILED' ? 'Failure reason:' : 'Recorded evidence:' ?></strong> <?= $deliveryEscape($deliveryEvent['reason']) ?></p><?php endif; ?>
                        <?php foreach ($courierView['evidence'][(int) $deliveryEvent['id']] ?? [] as $deliveryEvidence): ?>
                            <p class="text-small">Order item #<?= (int) $deliveryEvidence['order_item_id'] ?> / Product #<?= (int) $deliveryEvidence['product_id'] ?>:
                                <span class="badge <?= $deliveryEscape(admin_delivery_badge($deliveryEvidence['outcome'])) ?>"><?= $deliveryEscape($deliveryLabels[$deliveryEvidence['outcome']] ?? $deliveryEvidence['outcome']) ?></span>
                            </p>
                        <?php endforeach; ?>
                        <p class="text-small muted"><?= $deliveryEscape($deliveryEvent['created_at']) ?>, <?= $deliveryEscape($deliveryEvent['admin_name'] ?? 'Unavailable administrator') ?> (Admin #<?= (int) $deliveryEvent['admin_id'] ?>)</p>
                    </li>
                <?php endforeach; ?>
            </ol>
                </section>
            <?php endforeach; ?>
        </details>
        <?php if ($deliveryStage === 'RETURNED_TO_SHOP'): ?>
            <section class="admin-delivery__closure" aria-labelledby="admin-return-journey-heading">
                <h3 id="admin-return-journey-heading">Return delivery journey ended</h3>
                <p>This parcel has been returned to Hopia Fits, and the current courier delivery journey has ended. This is a return outcome, not delivery to the customer or a completed sale. Item inspection, stock disposition and order/payment resolution remain separate.</p>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <div class="admin-delivery__modal" data-delivery-modal hidden>
        <div class="admin-delivery__backdrop" data-delivery-cancel></div>
        <div class="admin-delivery__panel" role="dialog" aria-modal="true" aria-labelledby="delivery-confirm-title" aria-describedby="delivery-confirm-copy" tabindex="-1">
            <h2 id="delivery-confirm-title">Confirm delivery action</h2>
            <p class="text-small muted" data-delivery-confirm-item></p>
            <p id="delivery-confirm-copy"></p>
            <p class="text-small">Order #<?= (int) $order['id'] ?>, cycle <?= $deliveryCycle ?>. The backend rechecks the current records before saving.</p>
            <form data-delivery-confirm-form>
                <label class="admin-delivery__confirmation"><input type="checkbox" data-delivery-acknowledge required> I checked the physical facts, recorded evidence and consequences of this action.</label>
                <div class="admin-delivery__modal-actions">
                    <button class="btn btn-ghost" type="button" data-delivery-cancel>Cancel</button>
                    <button class="btn btn-primary" type="submit" data-delivery-confirm>Confirm Action</button>
                </div>
            </form>
        </div>
    </div>
</section>
