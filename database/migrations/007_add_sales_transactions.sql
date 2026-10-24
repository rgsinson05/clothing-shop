-- ============================================================
-- Migration 007: Add completed walk-in sales (Phase A)
-- ============================================================
--
-- Scope (Phase A ONLY):
--   This migration adds the durable record of a COMPLETED sale.
--   A completed sale is created ONLY after a cashier reviews a
--   walk-in draft and explicitly confirms it, and ONLY after the
--   PHP transaction commits successfully.
--
--   This migration is ADDITIVE. It creates two new tables and
--   touches no existing table, column, index, trigger, order,
--   checkout, payment, cancellation, or reservation logic.
--
-- What a completed sale is NOT:
--   products.status = 'SOLD' does NOT mean a completed sale.
--   The existing online checkout (customer/checkout.php) flips a
--   product from AVAILABLE to SOLD at the moment an ONLINE order is
--   PLACED, while that order may still be PENDING (and reversible).
--   That SOLD value is an availability HOLD for the online workflow,
--   not proof of a finished transaction. Phase A therefore records
--   its own completed-sale rows here and never infers completion
--   from products.status alone.
--
-- Channels:
--   sale_channel = 'WALKIN'  -> created by admin/api/walkin-sale.php
--                               in Phase A. order_id is always NULL.
--   sale_channel = 'ONLINE'  -> RESERVED for a later phase that will
--                               link a completed online sale to its
--                               orders row. No Phase A code writes it.
--
-- Admin attribution:
--   WALKIN sales always require an authenticated admin, enforced by
--   chk_sales_tx_channel_admin below. ONLINE sales are reserved for
--   a future customer-driven "Order Received" event; admin_id may be
--   NULL so no fake admin identity needs to be invented.
--
-- Returns / refunds / voids:
--   NOT implemented in Phase A. There is deliberately no void,
--   refund, or reversal column or table here. A completed sale is
--   treated as immutable for Phase A. Reversal handling is a future
--   decision and must be designed with its own migration.
--
-- Compatibility: MySQL 8.4 (local) and MariaDB 11.4 (hosting).
--   * ENGINE=InnoDB is stated EXPLICITLY so the completed-sale
--     write path can rely on transactions and SELECT ... FOR UPDATE
--     row locking, independent of any server default.
--   * CHECK constraints are supported and enforced on both engines.
--   * IF NOT EXISTS is intentionally NOT used for CREATE TABLE, to
--     match schema.sql and migrations 001-006, which use plain
--     statements. A developer applies this file once, by hand, to
--     the review database (see the apply command at the end).
--   * CHARSET / COLLATION are intentionally NOT declared, matching
--     schema.sql and migrations 001-006. The new tables inherit the
--     same database default as the existing products/orders/admins
--     tables, so joins and foreign keys agree by construction. Every
--     foreign key below joins INT UNSIGNED columns, so no string
--     collation has to match across tables. The snapshot columns
--     product_name and barcode_value are plain stored strings, not
--     foreign keys, so they need no cross-table collation agreement.
--   * No MySQL-only collation (e.g. utf8mb4_0900_ai_ci) is pinned,
--     because MariaDB 11.4 would reject it.
--
-- IMPORTANT (MySQL 8.4 / MariaDB 11.4 Error 3823):
--   A CHECK constraint may not inspect a column that also
--   participates in a foreign key declared with an explicit
--   referential action (such as ON DELETE SET NULL or ON UPDATE
--   CASCADE). chk_sales_tx_channel_order inspects order_id and
--   chk_sales_tx_channel_admin inspects admin_id, so BOTH of their
--   foreign keys (fk_sales_tx_order and fk_sales_tx_admin) are
--   declared with NO explicit referential action. Omitting the
--   clauses leaves InnoDB's implicit default of NO ACTION, which is
--   semantically RESTRICT (checked at statement time): an orders row
--   or admins row that a completed sale points to cannot be deleted,
--   which is exactly the history-preserving behaviour we want. This
--   mirrors fk_products_bundle in migration 005.
-- ============================================================


-- ============================================================
-- 1. SALES TRANSACTIONS (one row per COMPLETED transaction)
-- ============================================================
--
-- One customer visit that is confirmed as one completed sale
-- produces exactly ONE row here, regardless of how many garments
-- were scanned. The per-garment rows live in
-- sales_transaction_items.
--
-- Columns:
--   id            : permanent surrogate identity / human sale no.
--   sale_channel  : WALKIN now; ONLINE reserved for a later phase.
--   order_id      : NULL for walk-in. For a future ONLINE completed
--                   sale it links to the orders row. UNIQUE so the
--                   same online order can never spawn two completed
--                   headers; a UNIQUE index permits many NULLs on
--                   both engines, so unlimited walk-in rows coexist.
--   admin_id      : NULL for future customer-completed ONLINE sales
--                   (e.g. "Order Received" event). Required for WALKIN,
--                   enforced by chk_sales_tx_channel_admin below.
--                   NULL is permitted so ONLINE attribution does not need
--                   a fabricated admin identity.
--   total_amount  : authoritative total, computed server-side from
--                   database prices at confirmation (never from the
--                   browser). Equals SUM of the item snapshots.
--   sold_at       : commit-time timestamp (Asia/Manila via the +08:00
--                   session set in includes/database.php).
--   request_key   : random idempotency key supplied by the confirm
--                   request. UNIQUE so a retried/double-submitted
--                   confirmation collapses to the SAME single sale
--                   instead of creating another one.
--
-- chk_sales_tx_channel_order:
--   Keeps channel and order_id consistent:
--     WALKIN -> order_id IS NULL
--     ONLINE -> order_id IS NOT NULL
--
-- chk_sales_tx_channel_admin:
--   WALKIN must be admin-attributed; ONLINE may have NULL (customer
--   "Order Received") or a set admin_id (admin-assisted completion).
-- ============================================================

CREATE TABLE sales_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    sale_channel ENUM('WALKIN','ONLINE') NOT NULL DEFAULT 'WALKIN',

    order_id INT UNSIGNED NULL,

    admin_id INT UNSIGNED NULL,

    total_amount DECIMAL(10, 2) NOT NULL,

    sold_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    request_key VARCHAR(64) NOT NULL,

    CONSTRAINT uq_sales_tx_request_key
        UNIQUE (request_key),

    CONSTRAINT uq_sales_tx_order
        UNIQUE (order_id),

    -- No explicit referential action: order_id is inspected by the
    -- CHECK below, so an explicit action would raise Error 3823.
    -- NO ACTION (= RESTRICT) also preserves history: an order that a
    -- completed sale references cannot be deleted.
    CONSTRAINT fk_sales_tx_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id),

    -- No explicit referential action: admin_id is inspected by the
    -- CHECK below, so an explicit action would raise Error 3823.
    -- Implicit NO ACTION (= RESTRICT) still prevents deleting an admin
    -- who has completed sales. ON UPDATE CASCADE is harmless because
    -- admins.id is a permanent AUTO_INCREMENT identity.
    CONSTRAINT fk_sales_tx_admin
        FOREIGN KEY (admin_id)
        REFERENCES admins(id),

    CONSTRAINT chk_sales_tx_total_nonnegative
        CHECK (total_amount >= 0),

    CONSTRAINT chk_sales_tx_channel_order
        CHECK (
            (sale_channel = 'WALKIN' AND order_id IS NULL)
            OR
            (sale_channel = 'ONLINE' AND order_id IS NOT NULL)
        ),

    CONSTRAINT chk_sales_tx_channel_admin
        CHECK (sale_channel <> 'WALKIN' OR admin_id IS NOT NULL)
) ENGINE=InnoDB;

-- Reporting helper: Sales Monitoring lists completed sales by channel
-- and day. This composite index supports the "completed WALKIN sales,
-- newest first / within a day" queries used by admin/sales.php.
CREATE INDEX idx_sales_tx_channel_sold_at
    ON sales_transactions (sale_channel, sold_at);


-- ============================================================
-- 2. SALES TRANSACTION ITEMS (one row per physical garment)
-- ============================================================
--
-- One row per unique physical garment included in the completed
-- sale. The product/barcode/price are SNAPSHOT here so the sale's
-- historical detail never changes if the product is later edited,
-- retired, or re-priced (the same snapshot principle as the
-- existing order_items table).
--
--   sale_id        : parent completed sale. RESTRICT protects the
--                    details from accidental removal: a header cannot
--                    be deleted while its items exist. Phase A never
--                    deletes a completed sale.
--   product_id     : the physical garment sold. RESTRICT prevents a
--                    product that is part of a completed sale from
--                    being hard-deleted. (Bundle-linked products are
--                    retired, never deleted, by the existing admin
--                    flow, so this never blocks legitimate deletes of
--                    legacy unbundled products.)
--   UNIQUE(product_id):
--                    The database-level guarantee that one physical
--                    garment can appear in at most ONE completed sale
--                    detail, ever. This is the last line of defence
--                    against selling the same garment twice even if
--                    two confirmations race.
--   product_name   : snapshot of products.name at sale time.
--   barcode_value  : snapshot of products.barcode_value at sale time.
--   unit_price     : authoritative price at sale time (DECIMAL money).
--   order_item_id  : RESERVED for a future ONLINE completed sale to
--                    link to its order_items row. Always NULL for a
--                    walk-in. UNIQUE (many NULLs allowed) so a given
--                    order item can map to at most one completed-sale
--                    detail later.
-- ============================================================

CREATE TABLE sales_transaction_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    sale_id INT UNSIGNED NOT NULL,

    product_id INT UNSIGNED NOT NULL,

    product_name VARCHAR(255) NOT NULL,

    barcode_value VARCHAR(64) NOT NULL,

    unit_price DECIMAL(10, 2) NOT NULL,

    order_item_id INT UNSIGNED NULL,

    CONSTRAINT uq_sales_tx_items_product
        UNIQUE (product_id),

    CONSTRAINT uq_sales_tx_items_order_item
        UNIQUE (order_item_id),

    CONSTRAINT fk_sales_tx_items_sale
        FOREIGN KEY (sale_id)
        REFERENCES sales_transactions(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_sales_tx_items_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_sales_tx_items_order_item
        FOREIGN KEY (order_item_id)
        REFERENCES order_items(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT chk_sales_tx_items_price_nonnegative
        CHECK (unit_price >= 0)
) ENGINE=InnoDB;


-- ============================================================
-- 3. BUNDLE-LOCK PREDICATE (documentation; no schema change)
-- ============================================================
--
-- Phase A does NOT add an is_locked flag to purchase_bundles. A
-- stored flag can drift out of sync with reality. Instead, a bundle
-- is considered LOCKED when, and only when, at least one COMMITTED
-- completed sale contains a product that belongs to that bundle.
--
-- Canonical predicate (TRUE = locked):
--
--   SELECT EXISTS (
--       SELECT 1
--       FROM sales_transaction_items sti
--       INNER JOIN products p ON p.id = sti.product_id
--       WHERE p.bundle_id = :bundle_id
--   );
--
-- This is implemented once in PHP as sales_bundle_is_locked() in
-- includes/sales.php and is the single source of truth.
--
-- Because a walk-in sale inserts its sales_transaction_items rows
-- inside the same committed transaction as the header, the predicate
-- becomes TRUE immediately and durably once that sale commits.
--
-- Any FUTURE bundle capital/quantity editing feature (none exists in
-- the repository today, and this migration does not create one) MUST,
-- server-side and inside its own transaction:
--     1. SELECT ... FOR UPDATE the purchase_bundles row, and
--     2. re-check the predicate above,
-- and refuse the edit when the bundle is locked. Checking SOLD status
-- instead would be wrong, because online holds set SOLD before a sale
-- is actually completed.
-- ============================================================


-- ============================================================
-- END OF MIGRATION 007
-- ============================================================
--
-- APPLY (review database ONLY, after manual review):
--
--   mysql -u <user> -p clothing_shop_bundle_test < database/migrations/007_add_sales_transactions.sql
--
-- Do NOT apply this to the original local database or the hosted
-- (Hestia) database as part of Phase A.
-- ============================================================
