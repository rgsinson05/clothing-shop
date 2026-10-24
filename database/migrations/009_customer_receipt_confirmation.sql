-- ============================================================
-- Migration 009: Customer receipt confirmation + ONLINE sales (Phase C)
-- ============================================================
--
-- Scope (Phase C ONLY):
--   This migration is ADDITIVE. It:
--     (1) adds one nullable column to orders,
--     (2) changes three columns and one FK on sales_transaction_items
--         to support legacy/unlinked items in ONLINE completed sales,
--     and touches no other existing table, column, index, trigger,
--     order, checkout, payment, cancellation, or Phase A/B logic.
--
-- The two-part schema change:
--
--   A. orders.received_at  – the single persistent record of the
--      customer's "Order Received" confirmation event. NULL = not yet
--      confirmed. Set exactly once by the receipt-confirmation
--      endpoint inside the same transaction that creates the ONLINE
--      completed-sale header and details.
--
--   B. sales_transaction_items product_id / barcode_value  – allow
--      NULL so legacy order items (NULL product_id, or a product
--      that was later deleted) can be recorded in an ONLINE completed
--      sale without triggering a FK violation. The UNIQUE(product_id)
--      constraint permits many NULLs on both MySQL and MariaDB, so
--      multiple unlinked items are not blocked. The product FK is
--      changed to ON DELETE SET NULL (matching order_items.product_id)
--      so deletion of a historically-sold product preserves the
--      sale detail's snapshot of name/barcode/price.
--
-- What is NOT changed:
--   * orders.status still becomes DELIVERED when the admin advances
--     the shipment. received_at is set only by the customer's
--     explicit confirmation, never automatically.
--   * sales_transaction_items.unit_price remains NOT NULL (every
--     item has a snapshot unit price in order_items).
--   * Phase A WALKIN details always have non-NULL product_id and
--     barcode_value because walk-in products are freshly bundled/
--     barcode-assigned and are never deleted mid-sale.
--
-- Compatibility: MySQL 8.4 (local) and MariaDB 11.4 (hosting).
--   * ENGINE=InnoDB is stated explicitly for transaction and
--     FOR UPDATE row-lock safety.
--   * IF NOT EXISTS is intentionally NOT used, matching
--     migrations 001-008 and schema.sql.
--   * CHARSET / COLLATE are intentionally omitted, matching the
--     house style.
--   * No MySQL-only collation is pinned.
--
-- Error 3823 (CHECK + FK with explicit referential action):
--   The two ALTER TABLE ... MODIFY statements below change only
--   the NULLability of product_id and barcode_value. Neither
--   column is inspected by any CHECK constraint, so Error 3823
--   does not apply. The FK action change (RESTRICT -> SET NULL)
--   is safe for the same reason.
--
-- schema.sql is intentionally NOT edited to mirror this migration,
-- matching the approach of migrations 005-008.
-- ============================================================


-- ============================================================
-- 1. ORDERS.RECEIVED_AT
-- ============================================================
--
-- One nullable DATETIME column. NULL = customer has not yet confirmed
-- receipt; a non-NULL value = confirmed at that exact timestamp.
--
-- DATETIME (not TIMESTAMP) avoids the 2038 problem, matches the
-- precision of shipped_at / delivered_at on shipments, and is large
-- enough for the Asia/Manila session's +08:00 offset.
--
-- No index is needed: received_at is always read via the order's
-- primary key (customer_id + order_id), which the existing
-- PRIMARY KEY (id) satisfies directly.
-- ============================================================

ALTER TABLE orders
    ADD COLUMN received_at DATETIME NULL DEFAULT NULL;


-- ============================================================
-- 2. SALES_TRANSACTION_ITEMS – allow NULL product_id / barcode_value
--    and align product FK to ON DELETE SET NULL
-- ============================================================
--
-- 2a. Drop the existing RESTRICT FK, change product_id to NULL.
-- 2b. Change barcode_value to NULL.
-- 2c. Re-add the FK with ON DELETE SET NULL.
--
-- Steps 2a and 2c are two separate ALTER TABLE statements because
-- MySQL does not allow a DROP CONSTRAINT + ADD CONSTRAINT in a
-- single ALTER TABLE on the same named constraint. Doing them as
-- separate statements avoids Error 1060 (duplicate column name) and
-- Error 1517 (duplicate constraint) on re-run.
--
-- The UNIQUE(product_id) key survives: it permits multiple NULL
-- values, so many unlinked items in one sale are not blocked.
-- UNIQUE(order_item_id) is unchanged; its RESTRICT FK protects
-- against deleting an order item that has a completed-sale detail.
-- ============================================================

-- 2a. Change product_id to NULL and drop the RESTRICT FK.
ALTER TABLE sales_transaction_items
    MODIFY COLUMN product_id INT UNSIGNED NULL,
    DROP FOREIGN KEY fk_sales_tx_items_product;

-- 2b. Change barcode_value to NULL.
ALTER TABLE sales_transaction_items
    MODIFY COLUMN barcode_value VARCHAR(64) NULL;

-- 2c. Re-add the product FK with ON DELETE SET NULL.
ALTER TABLE sales_transaction_items
    ADD CONSTRAINT fk_sales_tx_items_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE;


-- ============================================================
-- END OF MIGRATION 009
-- ============================================================
--
-- APPLY (review / test database ONLY, after manual review and a
-- fresh backup, exactly once):
--
--   mysql -u <user> -p clothing_shop_bundle_test < database/migrations/009_customer_receipt_confirmation.sql
--
-- Do NOT apply this to the original local clothing_shop database or
-- the hosted (Hestia) database as part of Phase C.
-- ============================================================
