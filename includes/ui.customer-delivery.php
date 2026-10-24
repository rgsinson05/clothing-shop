<?php

// Read-only customer history within the existing order-status card.
if ($courierHistory === null || $courierHistory['state'] === 'legacy') return;
if ($courierHistory['state'] !== 'ready' || $courierHistory['stage'] === null): ?>
    <p class="od-status-note" role="status"><?= hopia_e($courierHistory['error'] ?? 'Detailed courier history is currently unavailable. Your available shipment details are shown below.') ?></p>
<?php return; endif;

?>
<section class="od-courier" aria-labelledby="courier-history-heading">
    <p class="od-courier__parent"><?= $orderTimeline['returned_closed'] ? 'In Transit delivery outcome' : 'In Transit details' ?></p>
    <h3 id="courier-history-heading" class="order-detail-card__title" tabindex="-1">Courier delivery history</h3>
    <p class="od-courier__note">Detailed updates within the In Transit stage, oldest first. Times are Philippine time (UTC+8). Earlier shipment history is not backfilled.</p>
    <p class="od-courier__note">Previous cycles remain visible as history. The current cycle and its highlighted public update identify the present delivery state.</p>
    <?php if ($courierHistory['error'] !== null): ?>
        <p class="od-status-note" role="status"><?= hopia_e($courierHistory['error']) ?></p>
    <?php endif; ?>
    <?php if ($courierHistory['cycles'] === [] && $courierHistory['error'] === null): ?>
        <p class="od-courier__note">No courier delivery events have been recorded for this order yet. Available tracking details remain below.</p>
    <?php endif; ?>
    <?php foreach ($courierHistory['cycles'] as $courierCycle => $courierEvents): ?>
        <?php $courierCycleIsCurrent = $courierHistory['current_cycle'] !== null && (int) $courierCycle === $courierHistory['current_cycle']; ?>
        <section class="od-courier__cycle<?= $courierCycleIsCurrent ? ' is-current-cycle' : '' ?><?= $courierCycleIsCurrent && $orderTimeline['returned_closed'] ? ' is-closed' : '' ?>" aria-labelledby="courier-cycle-<?= (int) $courierCycle ?>">
            <h4 id="courier-cycle-<?= (int) $courierCycle ?>" class="od-courier__cycle-title">
                Delivery cycle <?= (int) $courierCycle ?>
                <?php if ($courierHistory['current_cycle'] !== null): ?>
                    <span class="badge <?= $courierCycleIsCurrent ? 'badge-info' : 'badge' ?>"><?= $courierCycleIsCurrent ? 'Current cycle' : 'Previous cycle' ?></span>
                <?php endif; ?>
            </h4>
            <ol class="od-courier__history" role="list">
                <?php foreach ($courierEvents as $courierEvent): ?>
                    <?php
                    $courierIsCurrent = $courierCycleIsCurrent && $courierEvent['id'] === $courierHistory['current_event']
                        && in_array($orderTimeline['stage'], ['SHIPPED', 'IN_TRANSIT', 'DELIVERED'], true);
                    $courierDate = is_string($courierEvent['created_at'])
                        ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $courierEvent['created_at'], new DateTimeZone('Asia/Manila')) : false;
                    if ($courierDate !== false && $courierDate->format('Y-m-d H:i:s') !== $courierEvent['created_at']) $courierDate = false;
                    $courierIcon = match ($courierEvent['type']) {
                        'DELIVERY_ATTEMPT_FAILED' => 'x-circle',
                        'DELIVERED', 'RETURNED_TO_SHOP' => 'check-circle',
                        default => 'truck',
                    };
                    ?>
                    <li class="od-courier__event<?= $courierIsCurrent ? ' is-current' : '' ?>"<?= $courierIsCurrent ? ' aria-current="step"' : '' ?>>
                        <span class="od-progress__node" aria-hidden="true"><?= hopia_order_icon($courierIcon) ?></span>
                        <div class="od-courier__event-copy">
                            <p class="od-courier__event-title">
                                <?= hopia_e($courierIsCurrent && $courierEvent['type'] === 'DELIVERED'
                                    ? ($orderTimeline['courier_label'] ?? $courierEvent['label']) : $courierEvent['label']) ?><?= $courierEvent['type'] === 'DELIVERY_ATTEMPT_FAILED' && $courierEvent['attempt'] !== null ? ' - Attempt ' . (int) $courierEvent['attempt'] . ' of 3' : '' ?>
                                <?php if ($courierIsCurrent): ?><span class="badge badge-info"><?= $orderTimeline['returned_closed'] ? 'Final courier outcome' : 'Latest courier update' ?></span><?php endif; ?>
                            </p>
                            <?php if ($courierDate !== false): ?>
                                <div class="od-courier__meta">
                                    <time class="od-courier__time" datetime="<?= hopia_e($courierDate->format('Y-m-d\TH:i:sP')) ?>"><?= hopia_e($courierDate->format('M j, Y g:i A')) ?></time>
                                </div>
                            <?php endif; ?>
                            <?php if ($courierEvent['reason'] !== null && trim($courierEvent['reason']) !== ''): ?>
                                <p class="od-courier__reason"><strong>Recorded reason:</strong> <?= hopia_e($courierEvent['reason']) ?></p>
                            <?php endif; ?>
                            <?php if ($courierEvent['type'] === 'RETURN_TO_SELLER'): ?>
                                <p class="od-courier__note">Three failed attempts have exhausted this delivery cycle. No further delivery attempt starts automatically in this cycle.</p>
                            <?php elseif ($courierEvent['type'] === 'RETURNED_TO_SHOP'): ?>
                                <p class="od-courier__note"><?= $courierIsCurrent
                                    ? 'Your parcel has returned to the shop. Your delivery journey with Hopia Fits has ended.'
                                    : 'Your parcel returned to the shop during this delivery cycle.' ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endforeach; ?>
</section>
