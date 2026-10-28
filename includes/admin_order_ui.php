<?php

require_once __DIR__ . '/ui.php';

/** Presentation only: each category opens a specific existing filter, not a new lifecycle. */
function admin_order_filter_nav(string $page, array $filters, ?string $active, string $search = '', ?array $counts = null, ?array $direct = null): void
{
    $groups = $page === 'orders' ? [
        'delivery' => ['label' => 'Delivery Stages', 'icon' => 'truck', 'filters' => [
            'online_pending' => 'Pending', 'online_confirmed' => 'Confirmed', 'online_packed' => 'Packed',
            'online_shipped' => 'Shipped', 'online_transit_stage' => 'In Transit',
            'online_out_for_delivery' => 'Out for Delivery', 'online_delivered_receipt' => 'Delivered',
            'online_receipt_confirmed' => 'Completed',
        ]],
        'payments' => ['label' => 'Payments', 'icon' => 'payment', 'filters' => [
            'online_gcash_review' => 'GCash Awaiting Verification', 'online_gcash_failed' => 'GCash Verification Failed',
            'online_cod_collection' => 'COD Collection Pending', 'online_gcash_completion' => 'GCash Sale Completion Pending',
            'online_completed' => 'Finalized Sales',
        ]],
        'exceptions' => ['label' => 'Exceptions', 'icon' => 'x-circle', 'filters' => [
            'online_failed_attempt' => 'Delivery Attempt Failed', 'online_return_decision' => 'Return Decision Pending',
            'online_returns' => 'Returns', 'online_cancellation' => 'Cancellation Requests', 'online_cancelled' => 'Cancelled',
        ]],
        'work' => ['label' => 'Work Queues', 'icon' => 'package', 'filters' => [
            'online_pack_dispatch' => 'Orders to Pack / Dispatch', 'online_in_transit' => 'Transit Activity (Including Retries)',
        ]],
    ] : [
        'pickup' => ['label' => 'Pickup Stages', 'icon' => 'purchase', 'filters' => [
            'pickup_to_prepare' => 'Order Received', 'pickup_preparing' => 'Preparing',
            'pickup_ready' => 'Ready for Pickup', 'pickup_picked_up' => 'Picked Up',
        ]],
        'payments' => ['label' => 'Payments', 'icon' => 'payment', 'filters' => [
            'pickup_gcash_review' => 'GCash Awaiting Verification', 'pickup_gcash_failed' => 'GCash Verification Failed',
            'pickup_cop_completion' => 'Cash on Pickup Completion', 'pickup_gcash_completion' => 'GCash Completion',
        ]],
        'exceptions' => ['label' => 'Exceptions', 'icon' => 'x-circle', 'filters' => [
            'pickup_overdue' => 'Overdue Pickups', 'pickup_cancellation' => 'Cancellation Requests', 'pickup_cancelled' => 'Cancelled',
        ]],
    ];
    $selectedGroup = 'all';
    foreach ($groups as $key => $group) {
        if (isset($group['filters'][$active ?? ''])) $selectedGroup = $key;
    }
    if ($direct !== null) {
        $selectedGroup = $page === 'orders'
            ? ($direct['value'] === 'CANCELLED' ? 'exceptions' : 'delivery')
            : 'pickup';
    }
    $searchSuffix = $search !== '' ? ['q' => $search] : [];
    ?>
    <nav class="om-navigation" aria-label="<?= $page === 'orders' ? 'Online Orders' : 'Store Pickup' ?> status navigation">
        <div class="om-navigation__categories">
            <a class="om-category<?= $selectedGroup === 'all' ? ' is-active' : '' ?>" href="<?= hopia_e($page . '.php' . ($search !== '' ? '?' . http_build_query($searchSuffix) : '')) ?>"<?= $selectedGroup === 'all' ? ' aria-current="page"' : '' ?>>
                <?= hopia_order_icon('status') ?><span>All Orders</span><?php if ($counts !== null): ?><span class="badge"><?= (int) ($counts['all'] ?? 0) ?></span><?php endif; ?>
            </a>
            <?php foreach ($groups as $key => $group): ?>
                <?php $entry = $selectedGroup === $key && isset($filters[$active ?? '']) ? $active : array_key_first($group['filters']); ?>
                <a class="om-category<?= $selectedGroup === $key ? ' is-active' : '' ?>" href="<?= hopia_e($page . '.php?' . http_build_query(['queue' => $entry] + $searchSuffix)) ?>"<?= $selectedGroup === $key ? ' aria-current="page"' : '' ?>><?= hopia_order_icon($group['icon']) ?><span><?= hopia_e($group['label']) ?></span></a>
            <?php endforeach; ?>
        </div>
        <?php if ($selectedGroup !== 'all'): ?>
            <form class="om-navigation__selector" method="GET" action="<?= hopia_e($page) ?>.php">
                <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= hopia_e($search) ?>"><?php endif; ?>
                <label for="om-status-select"><?= in_array($selectedGroup, ['delivery', 'pickup'], true) ? 'Select stage' : 'Select filter' ?></label>
                <select id="om-status-select" name="queue" data-order-filter-select>
                    <?php if ($direct !== null): ?><option value="" selected><?= hopia_e($direct['label']) ?> (direct filter)</option><?php endif; ?>
                    <?php foreach ($groups[$selectedGroup]['filters'] as $key => $label): ?>
                        <option value="<?= hopia_e($key) ?>"<?= $active === $key ? ' selected' : '' ?>><?= hopia_e($label) ?><?= $counts !== null ? ' (' . (int) ($counts[$key] ?? 0) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($direct !== null): ?><input type="hidden" name="<?= hopia_e($direct['name']) ?>" value="<?= hopia_e($direct['value']) ?>" data-order-direct-filter><?php endif; ?>
                <button class="btn btn-secondary btn-sm" type="submit">Apply</button>
                <p class="om-navigation__hint"><?= $selectedGroup === 'delivery' ? 'Delivered awaits Order Received. Completed confirms customer receipt; payment finalization remains separate.' : ($selectedGroup === 'pickup' ? 'The 48-hour deadline starts when marked Ready for Pickup.' : 'These filters are separate from fulfillment stages.') ?></p>
            </form>
        <?php endif; ?>
    </nav>
    <?php
}

/** Batch-read existing snapshots for expandable list details without changing the list query. */
function admin_order_list_details(PDO $pdo, array $ids, bool $courier = false): array
{
    if ($ids === []) return [];
    $ids = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT o.id, o.shipping_name, o.shipping_phone, o.shipping_address,
        c.email AS customer_email, c.phone AS customer_phone, p.proof_path, s.tracking_number
        FROM orders o JOIN customers c ON c.id = o.customer_id
        LEFT JOIN payments p ON p.order_id = o.id LEFT JOIN shipments s ON s.order_id = o.id
        WHERE o.id IN (' . $placeholders . ')');
    $stmt->execute($ids);
    $details = [];
    foreach ($stmt->fetchAll() as $row) $details[(int) $row['id']] = $row + ['items' => [], 'item_count' => 0, 'events' => []];
    $stmt = $pdo->prepare('SELECT order_id, product_name, size, color, quantity, subtotal
        FROM order_items WHERE order_id IN (' . $placeholders . ') ORDER BY id');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $item) {
        $id = (int) $item['order_id'];
        $details[$id]['items'][] = $item;
        $details[$id]['item_count'] += (int) $item['quantity'];
    }
    if ($courier) {
        $stmt = $pdo->prepare('SELECT order_id, delivery_cycle, event_type, attempt_number, reason, created_at
            FROM delivery_events WHERE order_id IN (' . $placeholders . ') ORDER BY id');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $event) $details[(int) $event['order_id']]['events'][] = $event;
    }
    return $details;
}

function admin_order_expanded_details(array $order, array $details, bool $pickup = false): void
{
    ?>
    <section class="om-products" aria-label="Ordered products">
        <h3>Ordered Products</h3>
        <?php if (empty($details['items'])): ?><p class="muted text-small">No items recorded.</p><?php else: ?>
            <ul>
            <?php foreach ($details['items'] as $item): ?>
                <li><div><strong><?= hopia_e($item['product_name']) ?></strong><span><?= hopia_e(implode(' / ', array_filter([$item['size'], $item['color']], static fn ($value) => $value !== null && $value !== ''))) ?> &middot; Qty <?= (int) $item['quantity'] ?></span></div><strong>&#8369;<?= number_format((float) $item['subtotal'], 2) ?></strong></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <dl class="om-extra-details">
        <div><dt>Customer contact</dt><dd><?= hopia_e($details['customer_email'] ?? '') ?><br><?= hopia_e($details['customer_phone'] ?? '') ?></dd></div>
        <div><dt><?= $pickup ? 'Pickup information' : 'Delivery address' ?></dt><dd><?= hopia_e($details['shipping_name'] ?? '') ?><br><?= nl2br(hopia_e($details['shipping_address'] ?? '')) ?><br><?= hopia_e($details['shipping_phone'] ?? '') ?></dd></div>
        <?php if (!$pickup && !empty($details['tracking_number'])): ?><div><dt>Tracking number</dt><dd><?= hopia_e($details['tracking_number']) ?></dd></div><?php endif; ?>
        <?php if (($order['payment_method'] ?? '') === 'GCASH'): ?>
            <div><dt>GCash reference</dt><dd><?= hopia_e($order['gcash_reference'] ?? 'Not submitted') ?><?php if (!empty($details['proof_path'])): ?><br><a href="payment-proof.php?order=<?= (int) $order['id'] ?>" target="_blank" rel="noopener noreferrer">View payment proof</a><?php endif; ?><br><a href="order-view.php?id=<?= (int) $order['id'] ?>#payment-heading">Review payment details</a></dd></div>
        <?php endif; ?>
    </dl>
    <?php if (!$pickup && !empty($details['events'])): ?>
        <section class="om-history" aria-label="Delivery attempt and status history">
            <h3>Delivery History</h3><ol>
            <?php foreach ($details['events'] as $event): ?>
                <li><strong><?= hopia_e(admin_delivery_labels()[$event['event_type']] ?? $event['event_type']) ?></strong><span>Cycle <?= (int) $event['delivery_cycle'] ?><?= $event['attempt_number'] !== null ? ' / Attempt ' . (int) $event['attempt_number'] : '' ?> &middot; <?= hopia_e(date('M j, Y g:i A', strtotime($event['created_at']))) ?></span><?php if (!empty($event['reason'])): ?><p><?= hopia_e($event['reason']) ?></p><?php endif; ?></li>
            <?php endforeach; ?>
            </ol>
        </section>
    <?php endif;
}

function admin_order_desktop_table(array $rows, bool $pickup = false): void
{
    ?>
    <div class="om-table-scroll" tabindex="0" role="region" aria-label="<?= $pickup ? 'Store Pickup' : 'Online Orders' ?> table, scroll for more orders" data-order-table-scroll>
        <table class="om-table">
            <caption class="sr-only"><?= $pickup ? 'Store Pickup' : 'Online Orders' ?></caption>
            <thead><tr><th scope="col">Order / Customer</th><th scope="col">Date / Items</th><?php if ($pickup): ?><th scope="col">Pickup Deadline</th><?php endif; ?><th scope="col">Status</th><th scope="col">Payment</th><th scope="col">Total</th><th scope="col">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr<?= !empty($row['overdue']) ? ' class="is-overdue"' : '' ?>>
                    <td><a class="om-table__order" href="order-view.php?id=<?= (int) $row['order']['id'] ?>">#<?= (int) $row['order']['id'] ?></a><span class="om-table__sub"><?= hopia_e($row['order']['customer_name']) ?></span></td>
                    <td><?= hopia_e(date('M j, Y', strtotime($row['order']['created_at']))) ?><span class="om-table__sub"><?= hopia_e(date('g:i A', strtotime($row['order']['created_at']))) ?> &middot; <?= (int) $row['item_count'] ?> items</span></td>
                    <?php if ($pickup): ?><td><?= hopia_e($row['deadline']) ?></td><?php endif; ?>
                    <td class="om-table__statuses"><?= $row['statuses'] ?></td>
                    <td><?= hopia_e($row['payment_method']) ?><span class="om-table__sub"><?= hopia_e($row['order']['payment_status'] ?? 'No payment record') ?></span></td>
                    <td class="om-table__total">&#8369;<?= number_format((float) $row['order']['total_amount'], 2) ?><?php if (!$pickup): ?><span class="om-table__sub">Items: &#8369;<?= number_format((float) $row['order']['subtotal'], 2) ?></span><span class="om-table__sub">Shipping: &#8369;<?= number_format((float) $row['order']['shipping_fee'], 2) ?></span><?php endif; ?></td>
                    <td class="om-table__actions"><?= $row['actions'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
