<?php

/*
 * Hopia Fits - Bundle profitability metrics (shared helpers).
 *
 * Include-only: no output, no side effects. Required by admin/bundles.php,
 * admin/index.php, and the bundle profitability tests so the per-bundle
 * financial rules have exactly ONE source of truth and cannot drift.
 *
 * Financial rules (mirroring includes/sales.php conventions):
 *   1. Revenue comes ONLY from committed sales_transactions rows. A product
 *      with status = 'SOLD' is NOT necessarily a completed sale: online
 *      checkout sets SOLD as a reversible availability hold.
 *   2. Completed finances use sales_monitor_source_sql() with
 *      reporting_issue IS NULL, including item/header integrity and all
 *      canonical online payment, fulfillment, receipt and cancellation checks.
 *      Recorded sale-item membership remains a separate inventory fact.
 *   3. Historical item revenue uses the saved sale-item price snapshots
 *      (sales_transaction_items.unit_price), never current product prices.
 *   4. Cost per garment = capital_total / original_quantity, fixed at
 *      purchase time. COGS is allocated per completed garment at this unit
 *      cost; capital is NEVER divided by remaining inventory.
 *   5. Expected remaining revenue/profit is a PROJECTION from registered
 *      AVAILABLE garments at their CURRENT selling price. Unregistered
 *      bundle quantity contributes no projection (no price exists yet).
 *   6. Online-held stock (SOLD without a recorded sale item) is shown
 *      separately and never counted as revenue or available inventory.
 *   7. Legacy products (bundle_id IS NULL) are reported separately and are
 *      never silently allocated to any bundle.
 *   8. Payment diagnostics retain receipt-confirmed recorded ONLINE items,
 *      including financially ineligible sales, and read payments AS STORED.
 *      GCash/COD count as collected ONLY with payment_status = 'PAID'.
 *      Walk-in sales carry no payment row (cash at point of sale), so
 *      walk-in revenue is reported as walk-in cash by flow definition.
 *
 * Money: integer centavos via sales_money_to_cents() (round(value * 100)),
 * the same approach includes/sales.php uses. Unit cost is rounded to the
 * nearest centavo ONCE (round(capital_cents / original_quantity)) and that
 * fixed value allocates every garment, so per-bundle COGS is always
 * unit_cost x completed_item_count.
 */

if (!function_exists('bundle_metrics_rows')) {
    /**
     * Fetch one raw metrics row per purchase bundle. Completed finances use
     * the canonical eligible sales source; recorded sold counts protect
     * inventory independently. Prices are per-item snapshots, never current
     * products.price. $category '' = all bundles.
     */
    function bundle_metrics_rows(PDO $pdo, string $category = ''): array
    {
        $eligibleSalesSql = 'SELECT * FROM (' . sales_monitor_source_sql() . ') sales'
            . ' WHERE reporting_issue IS NULL';
        $params = [];
        $where = '';
        if (in_array($category, ['SHIRTS', 'PANTS', 'SHORTS'], true)) {
            $where = ' WHERE pb.category = :category';
            $params[':category'] = $category;
        }

        $sql = '
            SELECT
                pb.id                AS bundle_id,
                pb.category          AS category,
                pb.capital_total     AS capital_total,
                pb.original_quantity AS original_quantity,
                pb.purchase_date     AS purchase_date,
                pb.created_at        AS created_at,

                COALESCE(p.registered_count, 0)     AS registered_count,
                COALESCE(p.available_count, 0)      AS available_count,
                COALESCE(p.online_held_count, 0)    AS online_held_count,
                COALESCE(p.recorded_sold_count, 0)  AS recorded_sold_count,
                COALESCE(p.retired_count, 0)        AS retired_count,

                COALESCE(s.walkin_revenue, 0)  AS walkin_revenue,
                COALESCE(s.walkin_items, 0)    AS walkin_items,
                COALESCE(s.online_revenue, 0)  AS online_revenue,
                COALESCE(s.online_items, 0)    AS online_items,

                COALESCE(pay.gcash_paid_revenue, 0)       AS gcash_paid_revenue,
                COALESCE(pay.gcash_paid_items, 0)         AS gcash_paid_items,
                COALESCE(pay.cod_paid_revenue, 0)         AS cod_paid_revenue,
                COALESCE(pay.cod_paid_items, 0)           AS cod_paid_items,
                COALESCE(pay.online_unrecorded_items, 0)  AS online_unrecorded_items,
                COALESCE(pay.online_uncollected_items, 0) AS online_uncollected_items,

                COALESCE(e.remaining_revenue, 0)   AS remaining_revenue,
                COALESCE(e.remaining_items, 0)      AS remaining_items

            FROM purchase_bundles pb

            /* Registered products and status breakdown.
               recorded_sold = product has sale-item membership, independent
               of financial eligibility and products.status. Financially
               ineligible history must never make a garment available again. */
            LEFT JOIN (
                SELECT
                    pr.bundle_id,
                    COUNT(*) AS registered_count,
                    SUM(CASE WHEN pr.status = \'AVAILABLE\'
                                  AND pr.retired_at IS NULL
                                  AND NOT EXISTS (
                                      SELECT 1 FROM sales_transaction_items sx
                                      WHERE sx.product_id = pr.id
                                  )
                             THEN 1 ELSE 0 END) AS available_count,
                    SUM(CASE WHEN pr.status = \'SOLD\'
                                  AND NOT EXISTS (
                                      SELECT 1 FROM sales_transaction_items sx
                                      WHERE sx.product_id = pr.id
                                  )
                             THEN 1 ELSE 0 END) AS online_held_count,
                    SUM(CASE WHEN EXISTS (
                                  SELECT 1 FROM sales_transaction_items sx
                                  WHERE sx.product_id = pr.id
                             )
                             THEN 1 ELSE 0 END) AS recorded_sold_count,
                    SUM(CASE WHEN pr.retired_at IS NOT NULL
                                  AND pr.status = \'AVAILABLE\'
                                  AND NOT EXISTS (
                                      SELECT 1 FROM sales_transaction_items sx
                                      WHERE sx.product_id = pr.id
                                  )
                             THEN 1 ELSE 0 END) AS retired_count
                FROM products pr
                WHERE pr.bundle_id IS NOT NULL
                GROUP BY pr.bundle_id
            ) p ON p.bundle_id = pb.id
';

        $sql .= '
            /* Eligible completed-sale item revenue per bundle. Revenue = SUM of
               per-item price snapshots, so a multi-bundle transaction is
               split correctly per bundle. Both channels pass the same
               canonical eligibility checks as Sales Monitoring. */
            LEFT JOIN (
                SELECT
                    pr.bundle_id,
                    SUM(CASE WHEN st.sale_channel = \'WALKIN\'
                             THEN sti.unit_price ELSE 0 END) AS walkin_revenue,
                    SUM(CASE WHEN st.sale_channel = \'WALKIN\'
                             THEN 1 ELSE 0 END)              AS walkin_items,
                    SUM(CASE WHEN st.sale_channel = \'ONLINE\'
                              THEN sti.unit_price ELSE 0 END) AS online_revenue,
                    SUM(CASE WHEN st.sale_channel = \'ONLINE\'
                              THEN 1 ELSE 0 END)              AS online_items
                FROM sales_transaction_items sti
                INNER JOIN products pr ON pr.id = sti.product_id
                INNER JOIN (' . $eligibleSalesSql . ') st ON st.id = sti.sale_id
                GROUP BY pr.bundle_id
            ) s ON s.bundle_id = pb.id
';

        $sql .= '
            /* Online payment collection, read AS STORED in payments.
               PAID is the only collected state; a DELIVERED or
               receipt-confirmed order never implies collection. Orders
               with no payments row at all are counted as unrecorded. */
            LEFT JOIN (
                SELECT
                    pr.bundle_id,
                    SUM(CASE WHEN pay.payment_method = \'GCASH\'
                                  AND pay.payment_status = \'PAID\'
                             THEN sti.unit_price ELSE 0 END) AS gcash_paid_revenue,
                    SUM(CASE WHEN pay.payment_method = \'GCASH\'
                                  AND pay.payment_status = \'PAID\'
                             THEN 1 ELSE 0 END)              AS gcash_paid_items,
                    SUM(CASE WHEN pay.payment_method = \'COD\'
                                  AND pay.payment_status = \'PAID\'
                             THEN sti.unit_price ELSE 0 END) AS cod_paid_revenue,
                    SUM(CASE WHEN pay.payment_method = \'COD\'
                                  AND pay.payment_status = \'PAID\'
                             THEN 1 ELSE 0 END)              AS cod_paid_items,
                    SUM(CASE WHEN pay.id IS NULL
                             THEN 1 ELSE 0 END)              AS online_unrecorded_items,
                    SUM(CASE WHEN pay.id IS NOT NULL
                                  AND pay.payment_status <> \'PAID\'
                             THEN 1 ELSE 0 END)              AS online_uncollected_items
                FROM sales_transaction_items sti
                INNER JOIN products pr ON pr.id = sti.product_id
                INNER JOIN sales_transactions st
                    ON st.id = sti.sale_id
                   AND st.sale_channel = \'ONLINE\'
                INNER JOIN orders o
                    ON o.id = st.order_id
                   AND o.received_at IS NOT NULL
                LEFT JOIN payments pay ON pay.order_id = st.order_id
                GROUP BY pr.bundle_id
            ) pay ON pay.bundle_id = pb.id

            /* Remaining projection: registered AVAILABLE garments only,
               valued at CURRENT selling prices. Retired, online-held and
               recorded sold items are excluded. Unregistered quantity
               (no product row) contributes nothing. */
            LEFT JOIN (
                SELECT
                    pr.bundle_id,
                    SUM(pr.price) AS remaining_revenue,
                    COUNT(*)      AS remaining_items
                FROM products pr
                WHERE pr.bundle_id IS NOT NULL
                  AND pr.status = \'AVAILABLE\'
                  AND pr.retired_at IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM sales_transaction_items sx
                      WHERE sx.product_id = pr.id
                  )
                GROUP BY pr.bundle_id
            ) e ON e.bundle_id = pb.id
';

        $sql .= $where
            . ' ORDER BY pb.created_at DESC, pb.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}

if (!function_exists('bundle_metrics_derive')) {
    /**
     * Pure derivation of displayed bundle metrics from one raw
     * bundle_metrics_rows() row. All money values are integer centavos.
     *
     * Formulas (all in centavos):
     *   unit_cost         = round(capital_total * 100 / original_quantity)
     *   completed_revenue = walkin_revenue + online_revenue (snapshots)
     *   completed_items   = eligible walkin_items + eligible online_items
     *   recorded_sold_count = physical products with recorded sale items
     *   completed_cogs    = unit_cost * completed_items
     *   gross_profit      = completed_revenue - completed_cogs
     *   remaining_revenue = SUM(current prices of AVAILABLE registered items)
     *   remaining_profit  = remaining_revenue - unit_cost * remaining_items
     *   potential_revenue = completed_revenue + remaining_revenue
     *   potential_profit  = gross_profit + remaining_profit
     *   unregistered_qty  = original_quantity - registered_count
     */
    function bundle_metrics_derive(array $row): array
    {
        $capitalCents = sales_money_to_cents($row['capital_total'] ?? 0);
        if ($capitalCents === null) {
            $capitalCents = 0;
        }

        $originalQty = max(1, (int) ($row['original_quantity'] ?? 1));

        /* Fixed at purchase time; NEVER capital / remaining inventory. */
        $unitCostCents = (int) round($capitalCents / $originalQty);

        $walkinRevenueCents = sales_money_to_cents($row['walkin_revenue'] ?? 0) ?? 0;
        $onlineRevenueCents = sales_money_to_cents($row['online_revenue'] ?? 0) ?? 0;

        $walkinItems = (int) ($row['walkin_items'] ?? 0);
        $onlineItems = (int) ($row['online_items'] ?? 0);

        $completedItems   = $walkinItems + $onlineItems;
        $completedRevenue = $walkinRevenueCents + $onlineRevenueCents;
        $walkinCogs       = $unitCostCents * $walkinItems;
        $onlineCogs       = $unitCostCents * $onlineItems;
        $completedCogs    = $unitCostCents * $completedItems;
        $grossProfit      = $completedRevenue - $completedCogs;

        $remainingRevenueCents = sales_money_to_cents($row['remaining_revenue'] ?? 0) ?? 0;
        $remainingItems        = (int) ($row['remaining_items'] ?? 0);
        $remainingCogs         = $unitCostCents * $remainingItems;
        $remainingProfit       = $remainingRevenueCents - $remainingCogs;

        $registeredCount = (int) ($row['registered_count'] ?? 0);

        return [
            'bundle_id'   => (int) ($row['bundle_id'] ?? 0),
            'category'    => (string) ($row['category'] ?? ''),
            'purchase_date' => $row['purchase_date'] ?? null,
            'created_at'    => $row['created_at'] ?? null,

            'capital_cents'    => $capitalCents,
            'original_qty'     => (int) ($row['original_quantity'] ?? 0),
            'unit_cost_cents'  => $unitCostCents,

            'registered_count'     => $registeredCount,
            'unregistered_qty'     => max(0, $originalQty - $registeredCount),
            'available_count'      => (int) ($row['available_count'] ?? 0),
            'online_held_count'     => (int) ($row['online_held_count'] ?? 0),
            'recorded_sold_count'   => (int) ($row['recorded_sold_count'] ?? 0),
            'retired_count'         => (int) ($row['retired_count'] ?? 0),

            'walkin_revenue_cents' => $walkinRevenueCents,
            'walkin_items'         => $walkinItems,
            'walkin_cogs_cents'    => $walkinCogs,
            'walkin_profit_cents'  => $walkinRevenueCents - $walkinCogs,

            'online_revenue_cents' => $onlineRevenueCents,
            'online_items'         => $onlineItems,
            'online_cogs_cents'    => $onlineCogs,
            'online_profit_cents'  => $onlineRevenueCents - $onlineCogs,

            'completed_revenue_cents' => $completedRevenue,
            'completed_items'          => $completedItems,
            'completed_cogs_cents'     => $completedCogs,
            'gross_profit_cents'       => $grossProfit,

            'gcash_paid_revenue_cents' => sales_money_to_cents($row['gcash_paid_revenue'] ?? 0) ?? 0,
            'gcash_paid_items'         => (int) ($row['gcash_paid_items'] ?? 0),
            'cod_paid_revenue_cents'   => sales_money_to_cents($row['cod_paid_revenue'] ?? 0) ?? 0,
            'cod_paid_items'           => (int) ($row['cod_paid_items'] ?? 0),
            'online_unrecorded_items'  => (int) ($row['online_unrecorded_items'] ?? 0),
            'online_uncollected_items' => (int) ($row['online_uncollected_items'] ?? 0),

            'remaining_revenue_cents' => $remainingRevenueCents,
            'remaining_items'         => $remainingItems,
            'remaining_cogs_cents'    => $remainingCogs,
            'remaining_profit_cents'  => $remainingProfit,

            'potential_revenue_cents' => $completedRevenue + $remainingRevenueCents,
            'potential_profit_cents' => $grossProfit + $remainingProfit,
        ];
    }
}

if (!function_exists('bundle_metrics_is_low_stock')) {
    /**
     * The low-stock rule shared by the dashboard Needs Attention card and the
     * bundles page filter, so the card count and the filtered list agree.
     *
     * A bundle qualifies only when:
     *   - at least one item is registered,
     *   - every purchased item is registered (no unregistered quantity),
     *   - at least one product has recorded sale history (eligible or not), and
     *   - at most 2 registered items remain available (and fewer than the
     *     registered total).
     *
     * $metrics is the array returned by bundle_metrics_derive().
     */
    function bundle_metrics_is_low_stock(array $metrics): bool
    {
        return (int) ($metrics['registered_count'] ?? 0) > 0
            && (int) ($metrics['unregistered_qty'] ?? 0) === 0
            && (int) ($metrics['recorded_sold_count'] ?? 0) > 0
            && (int) ($metrics['available_count'] ?? 0) <= 2
            && (int) ($metrics['available_count'] ?? 0) < (int) ($metrics['registered_count'] ?? 0);
    }
}

if (!function_exists('bundle_metrics_completed_items')) {
    /**
     * Per-garment detail rows for the "Eligible Completed Sales" breakdown of one
     * bundle. Uses the SAME completed-sale predicate as the summary
     * revenue in bundle_metrics_rows(): sales_monitor_source_sql() with
     * reporting_issue IS NULL. Prices are the stored per-item sale snapshots,
     * so SUM(price_cents) always reconciles exactly with
     * completed_revenue_cents from bundle_metrics_derive().
     *
     * Each item: product_id, name, barcode, price_cents, channel
     * ('WALKIN'|'ONLINE'), sale_id, order_id (?int), sold_at.
     */
    function bundle_metrics_completed_items(PDO $pdo, int $bundleId): array
    {
        $eligibleSalesSql = 'SELECT * FROM (' . sales_monitor_source_sql() . ') sales'
            . ' WHERE reporting_issue IS NULL';
        $stmt = $pdo->prepare(
            "SELECT sti.product_id,
                    sti.product_name,
                    sti.barcode_value,
                    sti.unit_price,
                    st.sale_channel,
                    st.id        AS sale_id,
                    st.order_id  AS order_id,
                    st.sold_at   AS sold_at
             FROM sales_transaction_items sti
             INNER JOIN products pr ON pr.id = sti.product_id
             INNER JOIN (" . $eligibleSalesSql . ") st ON st.id = sti.sale_id
             WHERE pr.bundle_id = :bundle_id
             ORDER BY st.sold_at ASC, sti.id ASC"
        );
        $stmt->execute([':bundle_id' => $bundleId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
                'name'       => (string) $row['product_name'],
                'barcode'    => is_string($row['barcode_value']) && $row['barcode_value'] !== '' ? $row['barcode_value'] : null,
                'price_cents'=> (int) (sales_money_to_cents($row['unit_price']) ?? 0),
                'channel'    => (string) $row['sale_channel'],
                'sale_id'    => (int) $row['sale_id'],
                'order_id'   => $row['order_id'] !== null ? (int) $row['order_id'] : null,
                'sold_at'    => (string) $row['sold_at'],
            ];
        }

        return $items;
    }
}

if (!function_exists('bundle_metrics_active_order_items')) {
    /**
     * Per-garment detail rows for the "Items in Active Orders" breakdown
     * of one bundle: garments currently RESERVED by an active (not
     * CANCELLED) online order - Local Courier or Store Pickup - and not
     * yet included in any recorded sale. This is the same population as
     * the summary's online_held_count, so the counts reconcile.
     *
     * A garment SOLD outside any recorded sale may historically appear
     * in several order_items rows (e.g. an older cancelled order before
     * inventory restoration); only the LATEST non-cancelled order item is
     * the active hold, which is what the returned row per garment is.
     *
     * Amounts use the stored order_items.unit_price snapshots (the price
     * recorded when the order was placed). Garments with NO active order
     * row cannot be attributed a reliable amount; they are counted
     * separately as 'orphaned_count' and never given an invented price.
     *
     * Returns ['items' => [...], 'orphaned_count' => int]. Each item:
     * product_id, name, order_id, order_status, pickup_status,
     * cancellation_status, delivery_method (?string), price_cents.
     */
    function bundle_metrics_active_order_items(PDO $pdo, int $bundleId): array
    {
        /* Population = online-held garments for this bundle (identical
           predicate to the summary's online_held_count). */
        $heldStmt = $pdo->prepare(
            "SELECT pr.id, pr.name
             FROM products pr
             WHERE pr.bundle_id = :bundle_id
               AND pr.status = 'SOLD'
               AND NOT EXISTS (
                   SELECT 1 FROM sales_transaction_items sx
                   WHERE sx.product_id = pr.id
               )
             ORDER BY pr.bundle_item_number ASC, pr.id ASC"
        );
        $heldStmt->execute([':bundle_id' => $bundleId]);
        $held = $heldStmt->fetchAll();

        if (count($held) === 0) {
            return ['items' => [], 'orphaned_count' => 0];
        }

        /* Candidate order items for exactly those garments, from
           non-cancelled orders only. */
        $stmt = $pdo->prepare(
            "SELECT oi.product_id,
                    oi.unit_price,
                    oi.id  AS order_item_id,
                    o.id   AS order_id,
                    o.status AS order_status,
                    o.pickup_status,
                    o.cancellation_status,
                    s.delivery_method
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
                  AND o.status <> 'CANCELLED'
             LEFT JOIN shipments s ON s.order_id = o.id
             WHERE oi.product_id = :product_id
             ORDER BY oi.id ASC"
        );

        $items = [];
        $orphaned = 0;
        foreach ($held as $product) {
            $productId = (int) $product['id'];
            $stmt->execute([':product_id' => $productId]);
            $rows = $stmt->fetchAll();

            if (count($rows) === 0) {
                /* SOLD without a recorded sale and without any active
                   order row: report the anomaly, never a fabricated
                   amount. */
                $orphaned++;
                continue;
            }

            /* The latest non-cancelled order item is the active hold. */
            $row = $rows[count($rows) - 1];

            $items[] = [
                'product_id' => $productId,
                'name'       => (string) $product['name'],
                'order_id'   => (int) $row['order_id'],
                'order_status' => (string) $row['order_status'],
                'pickup_status' => $row['pickup_status'] !== null ? (string) $row['pickup_status'] : null,
                'cancellation_status' => (string) $row['cancellation_status'],
                'delivery_method' => is_string($row['delivery_method']) && $row['delivery_method'] !== '' ? $row['delivery_method'] : null,
                'price_cents' => (int) (sales_money_to_cents($row['unit_price']) ?? 0),
            ];
        }

        return ['items' => $items, 'orphaned_count' => $orphaned];
    }
}

if (!function_exists('bundle_metrics_available_items')) {
    /**
     * Per-garment detail rows for the "Available Items - Potential
     * Sales" breakdown of one bundle. Uses EXACTLY the summary's
     * remaining-projection predicate (registered, AVAILABLE, not
     * retired, not in any recorded sale) valued at CURRENT selling
     * prices, so SUM(price_cents) reconciles exactly with
     * remaining_revenue_cents from bundle_metrics_derive().
     *
     * Each item: product_id, name, barcode, price_cents.
     */
    function bundle_metrics_available_items(PDO $pdo, int $bundleId): array
    {
        $stmt = $pdo->prepare(
            "SELECT pr.id, pr.name, pr.barcode_value, pr.price
             FROM products pr
             WHERE pr.bundle_id = :bundle_id
               AND pr.status = 'AVAILABLE'
               AND pr.retired_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM sales_transaction_items sx
                   WHERE sx.product_id = pr.id
               )
             ORDER BY pr.bundle_item_number ASC, pr.id ASC"
        );
        $stmt->execute([':bundle_id' => $bundleId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'product_id' => (int) $row['id'],
                'name'       => (string) $row['name'],
                'barcode'    => is_string($row['barcode_value']) && $row['barcode_value'] !== '' ? $row['barcode_value'] : null,
                'price_cents'=> (int) (sales_money_to_cents($row['price']) ?? 0),
            ];
        }

        return $items;
    }
}

if (!function_exists('bundle_metrics_payment_items')) {
    /**
     * Per-garment payment-collection diagnostics for RECORDED
     * ONLINE sales of one bundle, including ineligible sales (the summary's
     * payment subquery covers: sale_channel ONLINE with the persisted
     * receipt confirmation). Walk-in sales have no payments row by
     * design (cash at point of sale) and are not returned here.
     *
     * The payments row is read AS STORED: payment_status = 'PAID' is the
     * only collected state, and a missing payments row is reported as a
     * missing record - a completed, delivered, or picked-up order never
     * implies collection. GCash sub-states (not submitted / awaiting
     * verification / paid / rejected) are derived by the caller through
     * payment_gcash_state() using the gcash_reference / submitted_at
     * fields returned here.
     *
     * Each item: sti_id, name, price_cents, order_id, payment_method
     * (?string), payment_status (?string), gcash_reference (?string),
     * submitted_at (?string).
     */
    function bundle_metrics_payment_items(PDO $pdo, int $bundleId): array
    {
        $stmt = $pdo->prepare(
            "SELECT sti.id       AS sti_id,
                    sti.product_name,
                    sti.unit_price,
                    st.order_id  AS order_id,
                    pay.payment_method,
                    pay.payment_status,
                    pay.gcash_reference,
                    pay.submitted_at
             FROM sales_transaction_items sti
             INNER JOIN products pr ON pr.id = sti.product_id
             INNER JOIN sales_transactions st
                 ON st.id = sti.sale_id
                AND st.sale_channel = 'ONLINE'
             INNER JOIN orders o
                 ON o.id = st.order_id
                AND o.received_at IS NOT NULL
             LEFT JOIN payments pay ON pay.order_id = st.order_id
             WHERE pr.bundle_id = :bundle_id
             ORDER BY st.sold_at ASC, sti.id ASC"
        );
        $stmt->execute([':bundle_id' => $bundleId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'sti_id'       => (int) $row['sti_id'],
                'name'         => (string) $row['product_name'],
                'price_cents'  => (int) (sales_money_to_cents($row['unit_price']) ?? 0),
                'order_id'     => $row['order_id'] !== null ? (int) $row['order_id'] : null,
                'payment_method' => is_string($row['payment_method']) && $row['payment_method'] !== '' ? $row['payment_method'] : null,
                'payment_status' => is_string($row['payment_status']) && $row['payment_status'] !== '' ? $row['payment_status'] : null,
                'gcash_reference' => is_string($row['gcash_reference']) && trim($row['gcash_reference']) !== '' ? $row['gcash_reference'] : null,
                'submitted_at' => $row['submitted_at'] !== null && $row['submitted_at'] !== '' ? (string) $row['submitted_at'] : null,
            ];
        }

        return $items;
    }
}

if (!function_exists('bundle_metrics_legacy_summary')) {
    /**
     * Counts for legacy UNASSIGNED products (bundle_id IS NULL).
     * Kept separate from per-bundle metrics; never allocated to a bundle.
     * recorded_sold_count uses the same inventory/history predicate as bundles;
     * it is not a financially eligible completed-sale count.
     */
    function bundle_metrics_legacy_summary(PDO $pdo): array
    {
        $row = $pdo->query('
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = \'AVAILABLE\'
                              AND retired_at IS NULL
                              AND NOT EXISTS (
                                  SELECT 1 FROM sales_transaction_items sx
                                  WHERE sx.product_id = products.id
                              )
                         THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN status = \'SOLD\'
                              AND NOT EXISTS (
                                  SELECT 1 FROM sales_transaction_items sx
                                  WHERE sx.product_id = products.id
                              )
                         THEN 1 ELSE 0 END) AS online_held,
                SUM(CASE WHEN EXISTS (
                                  SELECT 1 FROM sales_transaction_items sx
                                  WHERE sx.product_id = products.id
                             )
                         THEN 1 ELSE 0 END) AS recorded_sold_count,
                SUM(CASE WHEN retired_at IS NOT NULL
                              AND status = \'AVAILABLE\'
                              AND NOT EXISTS (
                                  SELECT 1 FROM sales_transaction_items sx
                                  WHERE sx.product_id = products.id
                              )
                         THEN 1 ELSE 0 END) AS retired
            FROM products
            WHERE bundle_id IS NULL
        ')->fetch();

        return [
            'total'          => (int) ($row['total'] ?? 0),
            'available'      => (int) ($row['available'] ?? 0),
            'online_held'    => (int) ($row['online_held'] ?? 0),
            'recorded_sold_count' => (int) ($row['recorded_sold_count'] ?? 0),
            'retired'        => (int) ($row['retired'] ?? 0),
        ];
    }
}

if (!function_exists('bundle_metrics_overview')) {
    /**
     * Store-wide aggregate of the per-bundle derived metrics, plus the
     * legacy unassigned product count. Used by admin/index.php so the
     * dashboard and the bundles page can never disagree.
     */
    function bundle_metrics_overview(PDO $pdo): array
    {
        $overview = [
            'bundle_count'         => 0,
            'capital_cents'        => 0,
            'original_quantity'    => 0,
            'registered_count'     => 0,
            'unregistered_count'   => 0,
            'available_count'      => 0,
            'online_held_count'    => 0,
            'recorded_sold_count'  => 0,
            /* Existing dashboard field: eligible completed units, not history. */
            'completed_sold_count' => 0,
            'walkin_revenue_cents' => 0,
            'online_revenue_cents' => 0,
            'gross_profit_cents'   => 0,
            'remaining_revenue_cents' => 0,
            'remaining_items'      => 0,
            'legacy_unassigned'    => 0,
        ];

        foreach (bundle_metrics_rows($pdo) as $rawRow) {
            $m = bundle_metrics_derive($rawRow);

            $overview['bundle_count']++;
            $overview['capital_cents']        += $m['capital_cents'];
            $overview['original_quantity']    += $m['original_qty'];
            $overview['registered_count']     += $m['registered_count'];
            $overview['unregistered_count']   += $m['unregistered_qty'];
            $overview['available_count']      += $m['available_count'];
            $overview['online_held_count']    += $m['online_held_count'];
            $overview['recorded_sold_count']  += $m['recorded_sold_count'];
            $overview['completed_sold_count'] += $m['completed_items'];
            $overview['walkin_revenue_cents'] += $m['walkin_revenue_cents'];
            $overview['online_revenue_cents'] += $m['online_revenue_cents'];
            $overview['gross_profit_cents']   += $m['gross_profit_cents'];
            $overview['remaining_revenue_cents'] += $m['remaining_revenue_cents'];
            $overview['remaining_items']      += $m['remaining_items'];
        }

        $legacy = bundle_metrics_legacy_summary($pdo);
        $overview['legacy_unassigned'] = $legacy['total'];

        return $overview;
    }
}
