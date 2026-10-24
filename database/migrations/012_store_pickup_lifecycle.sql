-- ============================================================
-- Migration 012: Store Pickup lifecycle (Phase 3)
-- ============================================================
--
-- Scope (Phase 3 ONLY):
--   This migration is ADDITIVE. It adds four nullable columns to the
--   EXISTING orders table so a Store Pickup order can carry its own
--   dedicated lifecycle, and touches no other table, column, index,
--   trigger, payment, shipment, sale, cancellation, or Phase A/B/C/1/2
--   logic.
--
-- Why new columns instead of reusing orders.status:
--   orders.status drives the COURIER workflow (PENDING -> CONFIRMED ->
--   PACKED -> SHIPPED -> DELIVERED) and is load-bearing for the existing
--   cancellation approval (PENDING + REQUESTED), the Phase B shipment
--   gate, and the Phase C receipt/completed-sale flow. Overloading it
--   with pickup stages would either break those rules or force pickup
--   orders through meaningless courier labels. A dedicated, persisted
--   pickup_status keeps the two fulfillment lifecycles cleanly separate:
--   the pickup lifecycle is displayed from pickup_status, the courier
--   lifecycle from status, and neither can drift into the other.
--
-- Columns added to orders (all nullable, no backfill):
--
--   pickup_status ENUM('ORDER_RECEIVED','PREPARING','READY_FOR_PICKUP','PICKED_UP') NULL
--       NULL  = not a Store Pickup order (courier orders, legacy rows).
--               Legacy STORE_PICKUP orders created before this migration
--               also stay NULL and are treated as ORDER_RECEIVED by the
--               PHP layer until first advanced.
--       The four-value lifecycle, advanced only by explicit admin action
--       through includes/pickup.php (one step at a time, never skipping).
--
--   ready_for_pickup_at DATETIME NULL
--       Set exactly once, when the admin advances the order to
--       READY_FOR_PICKUP. Never recalculated afterwards.
--
--   pickup_deadline_at DATETIME NULL
--       The 48-hour pickup window deadline, computed ONCE as
--       ready_for_pickup_at + 48 hours at the moment the order becomes
--       ready, and then stored. Page renders compare against it; nothing
--       recomputes it, so refreshes and unrelated updates cannot shift
--       the deadline. Passing it only highlights the order for admin
--       attention - it never cancels, refunds, marks paid, or restores
--       inventory by itself.
--
--   picked_up_at DATETIME NULL
--       Set once, when the admin records the customer collecting the
--       order (terminal pickup state).
--
--   DATETIME (not TIMESTAMP) matches received_at / shipped_at /
--   delivered_at and avoids the 2038 cap under the pinned +08:00
--   session timezone.
--
--   No CHECK constraint is added: the valid-transition rules depend on
--   the shipment's delivery_method and on cancellation state, which
--   cannot be expressed portably; they live in includes/pickup.php and
--   run inside a SELECT ... FOR UPDATE transaction (house style since
--   migration 008).
--
-- Compatibility: MySQL 8.4 (local) and MariaDB 11.4 (hosting).
--   * IF NOT EXISTS is intentionally NOT used, matching schema.sql and
--     migrations 001-011. Apply exactly once (see end).
--   * CHARSET / COLLATE are intentionally omitted (house style).
--   * No new foreign keys or indexes: pickup_status is always read via
--     the order's primary key or the pickup list query, which is served
--     by the existing PRIMARY KEY (id) and the shipments UNIQUE
--     (order_id) join.
--
-- schema.sql is intentionally NOT edited to mirror this migration,
-- matching the approach of migrations 005-011.
-- ============================================================

-- 1. Add the four nullable Phase 3 columns to orders.
ALTER TABLE orders
    ADD COLUMN pickup_status ENUM(
        'ORDER_RECEIVED',
        'PREPARING',
        'READY_FOR_PICKUP',
        'PICKED_UP'
    ) NULL DEFAULT NULL,
    ADD COLUMN ready_for_pickup_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN pickup_deadline_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN picked_up_at DATETIME NULL DEFAULT NULL;

-- ============================================================
-- END OF MIGRATION 012
-- ============================================================
--
-- APPLY (review / test database ONLY, after manual review and a
-- fresh backup, exactly once):
--
--   mysql -u <user> -p clothing_shop_bundle_test < database/migrations/012_store_pickup_lifecycle.sql
--
-- Do NOT apply this to the original local clothing_shop database or
-- the hosted (Hestia) database as part of Phase 3 development. As of
-- this migration, clothing_shop_bundle_test already carries migrations
-- 001-011; clothing_shop (local dev) is still on an older schema and
-- must be migrated deliberately, not here.
-- ============================================================
