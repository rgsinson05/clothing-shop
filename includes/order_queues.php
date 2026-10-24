<?php

require_once __DIR__ . '/delivery.php';

/*
 * Hopia Fits - Admin order-queue definitions shared by the dashboard Needs
 * Attention area and the filtered Orders / Store Pickup lists.
 *
 * Include-only: declares functions and performs no output and no side effects,
 * matching includes/pickup.php, includes/order_verify.php, and
 * includes/payment.php.
 *
 * WHY THIS FILE EXISTS
 *   The dashboard card count and the page a card opens MUST agree. Both the
 *   COUNT query and the list-page WHERE clause therefore come from the same
 *   definition below; no page re-implements a status rule.
 *
 * SOURCE OF TRUTH (verified against the applied migrations, not schema.sql)
 *   - orders.status: PENDING -> CONFIRMED -> PACKED -> SHIPPED -> DELIVERED,
 *     plus CANCELLED (admin/order-view.php $orderStatusFlow).
 *   - shipments.delivery_method: LOCAL_COURIER | STORE_PICKUP. A NULL value is
 *     a legacy shipment created before migration 010 and is treated as a
 *     LOCAL_COURIER order, exactly like pickup_is_pickup_order() does.
 *   - Store Pickup orders keep orders.status = PENDING for their whole pickup
 *     lifecycle (migration 012 / includes/pickup.php), so the courier queues
 *     MUST exclude delivery_method = 'STORE_PICKUP' or every pickup order
 *     would be miscounted as an unconfirmed courier order.
 *   - orders.pickup_status: ORDER_RECEIVED | PREPARING | READY_FOR_PICKUP |
 *     PICKED_UP. A NULL pickup_status on a STORE_PICKUP order is a legacy row
 *     and resolves to ORDER_RECEIVED, matching pickup_effective_stage().
 *   - GCash "awaiting verification" is derived from the existing payments
 *     columns exactly like payment_gcash_state(): PENDING + (reference or
 *     submitted_at). No extra payment-status column exists or is invented.
 *
 * QUEUES ARE MUTUALLY EXCLUSIVE
 *   An order appears in at most one fulfillment queue. Cancellation requests
 *   are their own queue, so active fulfillment queues exclude
 *   cancellation_status IN ('REQUESTED','APPROVED'). Store Pickup and courier
 *   queues never overlap because they key off shipments.delivery_method.
 */

if (!function_exists('order_queue_definitions')) {
    /**
     * All order-driven Needs Attention queues, keyed by a stable machine name.
     *
     * Each definition carries:
     *   section  - dashboard group: 'online' or 'pickup'
     *   title    - card title
     *   desc     - approved short English card description
     *   tone     - existing dashboard attention tone
     *   icon     - existing dashboard line-art icon key
     *   href     - pre-filtered destination (server-side allowlisted there)
     *   where    - SQL predicate using the fixed aliases o (orders),
     *              s (shipments, LEFT JOIN), p (payments, LEFT JOIN)
     *   params   - bound parameters for the predicate (currently none)
     */
    function order_queue_definitions(): array
    {
        $courier = "(s.delivery_method IS NULL OR s.delivery_method = 'LOCAL_COURIER')";
        $pickup = "s.delivery_method = 'STORE_PICKUP'";
        $activeCancellation = "o.cancellation_status NOT IN ('REQUESTED', 'APPROVED')";
        $gcashAwaiting =
            "NOT (COALESCE(p.payment_method, '') = 'GCASH'"
            . " AND COALESCE(p.payment_status, '') = 'PENDING'"
            . " AND (TRIM(COALESCE(p.gcash_reference, '')) <> '' OR p.submitted_at IS NOT NULL))";
        $pickupStage = "COALESCE(o.pickup_status, 'ORDER_RECEIVED')";

        /* Every order_items row for the order must have a committed
           barcode-verification row, mirroring order_verify_progress()'s
           "complete" definition (expected > 0 AND verified >= expected). */
        $itemsVerified = "EXISTS (SELECT 1 FROM order_items oi0 WHERE oi0.order_id = o.id)"
            . " AND (SELECT COUNT(*) FROM order_items oi1 WHERE oi1.order_id = o.id)"
            . " = (SELECT COUNT(DISTINCT v.order_item_id) FROM order_item_verifications v"
            . "     INNER JOIN order_items oi2 ON oi2.id = v.order_item_id WHERE oi2.order_id = o.id)";

        return [
            /* ---------------- ONLINE ORDERS (Local Courier) ---------------- */
            'online_pending' => [
                'section' => 'online',
                'title' => 'Orders Awaiting Confirmation',
                'desc' => 'New online orders that need admin review and confirmation.',
                'tone' => 'warn',
                'icon' => 'clock',
                'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_pending',
                'where' => $courier
                    . " AND o.status = 'PENDING'"
                    . " AND COALESCE(s.shipment_status, '') NOT IN ('RETURN_TO_SELLER','RETURNED_TO_SHOP')"
                    . ' AND ' . $activeCancellation,
                'params' => [],
            ],
            'online_pack_dispatch' => [
                'section' => 'online',
                'title' => 'Orders to Pack / Dispatch',
                'desc' => 'Orders that need to continue fulfillment based on their current status and item-verification requirements.',
                'tone' => 'info',
                'icon' => 'package',
                'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_pack_dispatch',
                /*
                 * CONFIRMED orders still need packing; PACKED orders still need
                 * verification/tracking and dispatch. SHIPPED and DELIVERED are
                 * already dispatched, PENDING is the separate confirmation
                 * queue, and cancelled/cancellation-requested orders are not
                 * fulfillment work. GCash payments submitted and awaiting
                 * manual verification are held back from this queue.
                 */
                'where' => $courier
                    . " AND o.status IN ('CONFIRMED', 'PACKED')"
                    . ' AND ' . $activeCancellation
                    . ' AND ' . $gcashAwaiting,
                'params' => [],
            ],
            'online_cancellation' => [
                'section' => 'online',
                'title' => 'Cancellation Requests',
                'desc' => 'Customer cancellation requests awaiting admin review.',
                'tone' => 'danger',
                'icon' => 'cancel',
                'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_cancellation',
                'where' => $courier
                    . " AND o.cancellation_status = 'REQUESTED'"
                    . " AND o.status <> 'CANCELLED'",
                'params' => [],
            ],
            'online_delivered_receipt' => [
                'section' => 'online',
                'title' => 'Delivered — Awaiting Receipt',
                'desc' => 'Delivered orders awaiting customer receipt confirmation.',
                'tone' => 'info',
                'icon' => 'truck',
                'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_delivered_receipt',
                'where' => $courier
                    . " AND o.status = 'DELIVERED'"
                    . " AND s.shipment_status = 'DELIVERED'"
                    . ' AND o.received_at IS NULL'
                    . ' AND ' . $activeCancellation,
                'params' => [],
            ],
            'online_cod_collection' => [
                'section' => 'online',
                'title' => 'COD — Collection Pending',
                'desc' => 'COD orders awaiting admin collection confirmation and sale finalization.',
                'tone' => 'warn',
                'icon' => 'peso',
                'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_cod_collection',
                'where' => $courier
                    . ' AND s.order_id IS NOT NULL'
                    . " AND s.shipment_status = 'DELIVERED'"
                    . " AND o.status = 'DELIVERED'"
                    . ' AND o.received_at IS NOT NULL'
                    . ' AND ' . $activeCancellation
                    . " AND p.payment_method = 'COD' AND p.payment_status = 'PENDING'"
                    . ' AND p.verified_at IS NULL AND p.verified_by IS NULL'
                    . ' AND NOT EXISTS (SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id)',
                'params' => [],
            ],
            'online_gcash_completion' => [
                'section' => 'online',
                'title' => 'GCash — Collection / Sale Completion',
                'desc' => 'Verified GCash orders that are delivered with confirmed receipt, awaiting sale finalization.',
                'tone' => 'warn',
                'icon' => 'peso',
                'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_gcash_completion',
                'where' => $courier
                    . ' AND s.order_id IS NOT NULL'
                    . " AND s.shipment_status = 'DELIVERED'"
                    . " AND o.status = 'DELIVERED'"
                    . ' AND o.received_at IS NOT NULL'
                    . ' AND ' . $activeCancellation
                    . " AND p.payment_method = 'GCASH' AND p.payment_status = 'PAID'"
                    . ' AND p.verified_at IS NOT NULL AND p.verified_by IS NOT NULL'
                    . ' AND NOT EXISTS (SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id)',
                'params' => [],
            ],

            /* ---------------- PICK-UP ORDERS (Store Pickup) ---------------- */
            'pickup_to_prepare' => [
                'section' => 'pickup',
                'title' => 'Orders to Prepare',
                'desc' => 'Store Pickup orders that have not started preparation.',
                'tone' => 'warn',
                'icon' => 'clock',
                'href' => 'pickups.php?queue=pickup_to_prepare',
                'where' => $pickup
                    . " AND o.status <> 'CANCELLED'"
                    . ' AND ' . $activeCancellation
                    . " AND {$pickupStage} = 'ORDER_RECEIVED'",
                'params' => [],
            ],
            'pickup_preparing' => [
                'section' => 'pickup',
                'title' => 'Orders Preparing',
                'desc' => 'Store Pickup orders currently being prepared for collection.',
                'tone' => 'info',
                'icon' => 'package',
                'href' => 'pickups.php?queue=pickup_preparing',
                'where' => $pickup
                    . " AND o.status <> 'CANCELLED'"
                    . ' AND ' . $activeCancellation
                    . " AND {$pickupStage} = 'PREPARING'",
                'params' => [],
            ],
            'pickup_ready' => [
                'section' => 'pickup',
                'title' => 'Ready for Pickup',
                'desc' => 'Store Pickup orders waiting to be collected; overdue ones are highlighted.',
                'tone' => 'info',
                'icon' => 'pickup',
                'href' => 'pickups.php?queue=pickup_ready',
                'where' => $pickup
                    . " AND o.status <> 'CANCELLED'"
                    . ' AND ' . $activeCancellation
                    . " AND {$pickupStage} = 'READY_FOR_PICKUP'",
                'params' => [],
            ],
            /* Store Pickup completion queues. These mirror the Phase 9 (COP)
               and Phase 10 (GCash) finalization actions shown on the Ready for
               Pickup queue: STORE_PICKUP, not cancelled, no active cancellation,
               READY_FOR_PICKUP, not yet handed over, every item barcode-verified,
               no canonical completed sale yet, and no item linked to another
               completed sale. The dashboard card and the filtered tab share this
               exact predicate via order_queue_where(), so the counts cannot drift. */
            'pickup_cop_completion' => [
                'section' => 'pickup',
                'title' => 'Store Pickup — Cash Completion',
                'desc' => 'Store pickup cash orders awaiting handover confirmation and sale completion.',
                'tone' => 'warn',
                'icon' => 'peso',
                'href' => 'pickups.php?queue=pickup_cop_completion',
                'where' => $pickup
                    . " AND o.status <> 'CANCELLED'"
                    . ' AND ' . $activeCancellation
                    . " AND {$pickupStage} = 'READY_FOR_PICKUP'"
                    . ' AND o.received_at IS NULL AND o.picked_up_at IS NULL'
                    . " AND p.payment_method = 'CASH_ON_PICKUP' AND p.payment_status = 'PENDING'"
                    . ' AND p.verified_at IS NULL AND p.verified_by IS NULL'
                    . ' AND ' . $itemsVerified
                    . ' AND NOT EXISTS (SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id)'
                    . ' AND NOT EXISTS ('
                    . 'SELECT 1 FROM order_items oi'
                    . ' INNER JOIN sales_transaction_items sl ON (sl.order_item_id = oi.id OR sl.product_id = oi.product_id)'
                    . ' WHERE oi.order_id = o.id)',
                'params' => [],
            ],
            'pickup_gcash_completion' => [
                'section' => 'pickup',
                'title' => 'Store Pickup — GCash Completion',
                'desc' => 'Store pickup GCash orders awaiting handover confirmation and sale completion.',
                'tone' => 'warn',
                'icon' => 'peso',
                'href' => 'pickups.php?queue=pickup_gcash_completion',
                'where' => $pickup
                    . " AND o.status <> 'CANCELLED'"
                    . ' AND ' . $activeCancellation
                    . " AND {$pickupStage} = 'READY_FOR_PICKUP'"
                    . ' AND o.received_at IS NULL AND o.picked_up_at IS NULL'
                    . " AND p.payment_method = 'GCASH' AND p.payment_status = 'PAID'"
                    . ' AND p.verified_at IS NOT NULL AND p.verified_by IS NOT NULL'
                    . ' AND ' . $itemsVerified
                    . ' AND NOT EXISTS (SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id)'
                    . ' AND NOT EXISTS ('
                    . 'SELECT 1 FROM order_items oi'
                    . ' INNER JOIN sales_transaction_items sl ON (sl.order_item_id = oi.id OR sl.product_id = oi.product_id)'
                    . ' WHERE oi.order_id = o.id)',
                'params' => [],
            ],
            'pickup_cancellation' => [
                'section' => 'pickup',
                'title' => 'Pickup Cancellation Requests',
                'desc' => 'Store Pickup cancellation requests awaiting admin review.',
                'tone' => 'danger',
                'icon' => 'cancel',
                'href' => 'pickups.php?queue=pickup_cancellation',
                'where' => $pickup
                    . " AND o.cancellation_status = 'REQUESTED'"
                    . " AND o.status <> 'CANCELLED'",
                'params' => [],
            ],
        ];
    }
}

if (!function_exists('order_queue_definition')) {
    /**
     * One queue definition, or null for an unknown key. The key is always an
     * allowlist match, never interpolated into SQL.
     */
    function order_queue_definition(string $key): ?array
    {
        $definitions = order_queue_definitions();

        return $definitions[$key] ?? null;
    }
}

if (!function_exists('order_queue_where')) {
    /**
     * The SQL predicate and bound parameters for one queue, or null for an
     * unknown key. Callers must already use the aliases o, s, and p and join
     * shipments/payments with LEFT JOIN.
     */
    function order_queue_where(string $key): ?array
    {
        $definition = order_queue_definition($key);

        if ($definition === null) {
            return null;
        }

        return [
            'sql' => (string) $definition['where'],
            'params' => $definition['params'] ?? [],
        ];
    }
}

if (!function_exists('order_list_filter_definitions')) {
    /**
     * Ordered navigation filters for the two order-management list pages.
     *
     * These are presentation filters for the pages themselves, not additional
     * Needs Attention cards. A filter that already has a shared queue
     * definition delegates to it ('queue'), so the dashboard count and the
     * filtered list can never drift. The few filters that have no dashboard
     * card (Shipped / In Transit, Completed, Picked Up) carry their own fixed
     * predicate here, still using the same aliases and the same
     * cancellation/fulfillment rules as the shared queues.
     *
     * Each definition: label, href, icon, and either a queue key or a fixed
     * SQL predicate ('where' + 'params'). The 'all' entry has no predicate.
     */
    function order_list_filter_definitions(string $page): array
    {
        $courier = "(s.delivery_method IS NULL OR s.delivery_method = 'LOCAL_COURIER')";
        $pickup = "s.delivery_method = 'STORE_PICKUP'";
        $activeCancellation = "o.cancellation_status NOT IN ('REQUESTED', 'APPROVED')";
        $pickupStage = "COALESCE(o.pickup_status, 'ORDER_RECEIVED')";

        if ($page === 'orders') {
            return [
                'all' => [
                    'label' => 'All Local Courier Orders',
                    'href' => 'orders.php',
                    'icon' => 'status',
                ],
                'online_pending' => [
                    'label' => 'Pending',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_pending',
                    'icon' => 'clock',
                    'queue' => 'online_pending',
                ],
                'online_confirmed' => [
                    'label' => 'Confirmed',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_confirmed',
                    'icon' => 'package',
                    'where' => $courier . " AND o.status = 'CONFIRMED' AND " . $activeCancellation
                        . " AND COALESCE(s.shipment_status, '') NOT IN ('RETURN_TO_SELLER','RETURNED_TO_SHOP')",
                    'params' => [],
                ],
                'online_packed' => [
                    'label' => 'Packed',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_packed',
                    'icon' => 'package',
                    'where' => $courier . " AND o.status = 'PACKED' AND " . $activeCancellation
                        . " AND COALESCE(s.shipment_status, '') NOT IN ('RETURN_TO_SELLER','RETURNED_TO_SHOP')",
                    'params' => [],
                ],
                'online_shipped' => [
                    'label' => 'Shipped',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_shipped',
                    'icon' => 'truck',
                    'where' => $courier
                        . " AND o.status = 'SHIPPED'"
                        . ' AND ' . $activeCancellation
                        . ' AND ' . delivery_phase_predicate('SHIPPED'),
                    'params' => [],
                ],
                'online_in_transit' => [
                    'label' => 'In Transit',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_in_transit',
                    'icon' => 'truck',
                    'where' => $courier . " AND o.status = 'SHIPPED' AND " . $activeCancellation
                        . ' AND ' . delivery_phase_predicate('IN_TRANSIT'),
                    'params' => [],
                    'empty' => 'No orders currently in transit.',
                ],
                'online_returns' => [
                    'label' => 'Returns',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_returns',
                    'icon' => 'truck',
                    'where' => $courier . ' AND ' . delivery_phase_predicate('RETURNS'),
                    'params' => [],
                    'empty' => 'No returned orders.',
                ],
                'online_return_decision' => [
                    'label' => 'Return Decision Pending',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_return_decision',
                    'icon' => 'clock',
                    'where' => $courier . " AND o.status = 'SHIPPED' AND " . $activeCancellation
                        . ' AND ' . delivery_phase_predicate('RETURN_PENDING'),
                    'params' => [],
                    'empty' => 'No exhausted delivery cycles awaiting a return decision.',
                ],
                'online_pack_dispatch' => [
                    'label' => 'Orders to Pack / Dispatch',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_pack_dispatch',
                    'icon' => 'package',
                    'queue' => 'online_pack_dispatch',
                ],
                'online_delivered_receipt' => [
                    'label' => 'Delivered — Awaiting Receipt',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_delivered_receipt',
                    'icon' => 'check-circle',
                    'queue' => 'online_delivered_receipt',
                ],
                'online_cod_collection' => [
                    'label' => 'COD — Collection Pending',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_cod_collection',
                    'icon' => 'payment',
                    'queue' => 'online_cod_collection',
                ],
                'online_gcash_completion' => [
                    'label' => 'GCash — Collection / Sale Completion',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_gcash_completion',
                    'icon' => 'payment',
                    'queue' => 'online_gcash_completion',
                ],
                'online_completed' => [
                    'label' => 'Completed',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_completed',
                    'icon' => 'check',
                    'where' => $courier
                         . " AND o.status = 'DELIVERED'"
                         . ' AND o.received_at IS NOT NULL'
                        . " AND s.shipment_status = 'DELIVERED' AND p.payment_status = 'PAID'"
                        . " AND EXISTS (SELECT 1 FROM sales_transactions st WHERE st.order_id = o.id AND st.sale_channel = 'ONLINE')"
                        . ' AND ' . $activeCancellation,
                    'params' => [],
                ],
                'online_cancellation' => [
                    'label' => 'Cancellation Requests',
                    'href' => 'orders.php?fulfillment=LOCAL_COURIER&queue=online_cancellation',
                    'icon' => 'x-circle',
                    'queue' => 'online_cancellation',
                ],
            ];
        }

        if ($page === 'pickups') {
            return [
                'all' => [
                    'label' => 'All Store Pickup Orders',
                    'href' => 'pickups.php',
                    'icon' => 'purchase',
                ],
                'pickup_to_prepare' => [
                    'label' => 'Orders to Prepare',
                    'href' => 'pickups.php?queue=pickup_to_prepare',
                    'icon' => 'clock',
                    'queue' => 'pickup_to_prepare',
                ],
                'pickup_preparing' => [
                    'label' => 'Preparing for Pickup',
                    'href' => 'pickups.php?queue=pickup_preparing',
                    'icon' => 'package',
                    'queue' => 'pickup_preparing',
                ],
                'pickup_ready' => [
                    'label' => 'Ready for Pickup',
                    'href' => 'pickups.php?queue=pickup_ready',
                    'icon' => 'location',
                    'queue' => 'pickup_ready',
                ],
                'pickup_cop_completion' => [
                    'label' => 'COP — Cash Completion',
                    'href' => 'pickups.php?queue=pickup_cop_completion',
                    'icon' => 'peso',
                    'queue' => 'pickup_cop_completion',
                ],
                'pickup_gcash_completion' => [
                    'label' => 'GCash — Completion',
                    'href' => 'pickups.php?queue=pickup_gcash_completion',
                    'icon' => 'peso',
                    'queue' => 'pickup_gcash_completion',
                ],
                'pickup_picked_up' => [
                    'label' => 'Picked Up',
                    'href' => 'pickups.php?queue=pickup_picked_up',
                    'icon' => 'check-circle',
                    'where' => $pickup
                        . " AND o.status <> 'CANCELLED'"
                        . ' AND ' . $activeCancellation
                        . " AND {$pickupStage} = 'PICKED_UP'",
                    'params' => [],
                ],
                'pickup_cancellation' => [
                    'label' => 'Cancellation Requests',
                    'href' => 'pickups.php?queue=pickup_cancellation',
                    'icon' => 'x-circle',
                    'queue' => 'pickup_cancellation',
                ],
            ];
        }

        return [];
    }
}

if (!function_exists('order_list_filter_definition')) {
    /**
     * One list filter definition, or null for an unknown page/key. Keys are
     * always allowlist matches, never interpolated into SQL.
     */
    function order_list_filter_definition(string $page, string $key): ?array
    {
        $definitions = order_list_filter_definitions($page);

        return $definitions[$key] ?? null;
    }
}

if (!function_exists('order_list_filter_where')) {
    /**
     * The SQL predicate and bound parameters for one list filter, or null for
     * 'all' or an unknown key. Queue-backed filters reuse order_queue_where()
     * so there is exactly one definition of every operational queue.
     */
    function order_list_filter_where(string $page, string $key): ?array
    {
        $definition = order_list_filter_definition($page, $key);

        if ($definition === null) {
            return null;
        }

        if (!empty($definition['queue'])) {
            return order_queue_where((string) $definition['queue']);
        }

        if (!empty($definition['where'])) {
            return [
                'sql' => (string) $definition['where'],
                'params' => $definition['params'] ?? [],
            ];
        }

        return null;
    }
}

if (!function_exists('order_queue_count')) {
    /**
     * The exact number of orders in one queue. This is the ONLY count used by
     * the dashboard cards, so a card can never disagree with the filtered list
     * that uses order_queue_where() with the same key.
     */
    function order_queue_count(PDO $pdo, string $key): ?int
    {
        $where = order_queue_where($key);

        if ($where === null) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM orders o
             LEFT JOIN shipments s ON s.order_id = o.id
             LEFT JOIN payments p ON p.order_id = o.id
             WHERE ' . $where['sql']
        );
        $stmt->execute($where['params']);

        return (int) $stmt->fetchColumn();
    }
}
