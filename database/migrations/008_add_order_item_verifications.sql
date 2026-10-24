-- ============================================================
-- Migration 008: Add online-order item verifications (Phase B)
-- ============================================================
--
-- Scope (Phase B ONLY):
--   This migration adds the durable record of a per-item
--   VERIFICATION of an ONLINE order before it is shipped. One
--   row here means: one physical garment on a specific online
--   order was checked by an authenticated admin and confirmed as
--   present in the parcel, either by SCANNING its barcode or by
--   an explicit MANUAL confirmation for an unscannable/legacy item.
--
--   This migration is ADDITIVE. It creates ONE new table and
--   touches no existing table, column, index, trigger, order,
--   checkout, payment, cancellation, reservation, or Phase A
--   walk-in sales logic. It is fully independent of the Phase A
--   sales_transactions / sales_transaction_items tables from
--   migration 007.
--
-- What a verification is NOT:
--   * It is NOT a sale. Verifying an online order never creates a
--     sales_transactions / sales_transaction_items row and never
--     changes products.status. Online checkout already flips the
--     ordered garments to SOLD as an availability HOLD at order
--     placement; Phase B must NOT reuse the Phase A AVAILABLE
--     eligibility predicate and must NOT alter that status.
--   * It does NOT advance the order by itself. It only records that
--     an item was checked. The single shipment gate in
--     admin/order-view.php (PACKED -> SHIPPED) reads these rows to
--     decide whether every expected item has been verified.
--
-- Eligibility (enforced in PHP, inside a locked transaction):
--   Verification is allowed only while orders.status is CONFIRMED
--   or PACKED and the order is NOT in a pending/approved
--   cancellation state (cancellation_status NOT IN
--   ('REQUESTED','APPROVED')). PENDING, SHIPPED, DELIVERED,
--   CANCELLED and cancellation-locked orders are rejected. These
--   lifecycle rules cannot be expressed as a portable table
--   constraint and live in includes/order_verify.php.
--
-- Compatibility: MySQL 8.4 (local) and MariaDB 11.4 (hosting).
--   * ENGINE=InnoDB is stated EXPLICITLY so the verification write
--     path can rely on transactions and SELECT ... FOR UPDATE row
--     locking, independent of any server default.
--   * CHECK constraints are supported and enforced on both engines.
--   * IF NOT EXISTS is intentionally NOT used for CREATE TABLE, to
--     match schema.sql and migrations 001-007, which use plain
--     statements. A developer applies this file ONCE, by hand, to
--     the review database (see the apply command at the end).
--   * CHARSET / COLLATION are intentionally NOT declared, matching
--     schema.sql and migrations 001-007. The new table inherits the
--     same database default as the existing orders/order_items/
--     products/admins tables, so joins and foreign keys agree by
--     construction. Every foreign key below joins INT UNSIGNED
--     columns, so no string collation has to match across tables.
--     barcode_value and reason are plain stored strings, not
--     foreign keys, so they need no cross-table collation agreement.
--   * No MySQL-only collation (e.g. utf8mb4_0900_ai_ci) is pinned,
--     because MariaDB 11.4 would reject it.
--
-- IMPORTANT (MySQL 8.4 / MariaDB 11.4 Error 3823):
--   A CHECK constraint may not inspect a column that also
--   participates in a foreign key declared with an explicit
--   referential action (ON DELETE/UPDATE CASCADE/SET NULL). The
--   only CHECK below, chk_oiv_method_reason, inspects ONLY the
--   non-foreign-key columns `method` and `reason`. It touches none
--   of the foreign-key columns (order_id, order_item_id,
--   product_id, verified_by), so all four foreign keys are free to
--   declare the explicit referential actions chosen below WITHOUT
--   triggering Error 3823.
--
-- Why no request_key / idempotency column:
--   UNIQUE(order_item_id) IS the natural idempotency protection: a
--   given order item can receive AT MOST ONE verification row, ever.
--   A retried or racing second scan/manual-verify collapses onto the
--   same unique key and is reported as 'already_verified' by the
--   PHP layer. Adding a separate request_key would only duplicate
--   that guarantee, so it is intentionally omitted.
--
-- schema.sql is intentionally NOT edited to mirror this migration,
--   matching migrations 005-007 which are maintained separately from
--   the base schema.sql snapshot.
-- ============================================================


-- ============================================================
-- ORDER ITEM VERIFICATIONS (one row per verified order item)
-- ============================================================
--
-- Columns:
--   id            : permanent surrogate identity.
--   order_id      : the online order the verified item belongs to.
--                   Indexed (idx_oiv_order) for the progress query
--                   that counts verified items per order.
--   order_item_id : the exact order_items row that was verified.
--                   UNIQUE so one order item can never be verified
--                   twice. The PHP layer additionally proves that
--                   this order_item_id actually belongs to order_id
--                   (ordinary separate foreign keys do not, on their
--                   own, guarantee that relationship).
--   product_id    : the resolved product for a SCANNED verification;
--                   may be NULL for a MANUAL verification of a legacy
--                   order item that has no product linkage. SET NULL
--                   on delete so verification history survives a
--                   later product deletion (mirrors
--                   order_items.product_id).
--   barcode_value : snapshot of the barcode that was actually
--                   scanned (SCANNED). NULL for MANUAL.
--   verified_by   : the authenticated admin who performed the
--                   verification. RESTRICT on delete so an admin with
--                   verification history cannot be hard-deleted
--                   (mirrors the history-preserving intent of
--                   fk_sales_tx_admin in migration 007).
--   verified_at   : server/database commit-time timestamp
--                   (Asia/Manila via the +08:00 session set in
--                   includes/database.php). Immutable: no
--                   ON UPDATE CURRENT_TIMESTAMP.
--   method        : 'SCANNED' (barcode matched an expected item) or
--                   'MANUAL' (explicit admin confirmation of an
--                   unscannable item).
--   reason        : required free-text justification for a MANUAL
--                   verification; NULL for a SCANNED one. The
--                   NULL/NOT-NULL relationship is enforced by
--                   chk_oiv_method_reason; the "non-empty after
--                   trimming / not merely whitespace" rule is
--                   enforced in PHP (order_verify_record_manual),
--                   consistent with how migrations 005-007 push
--                   non-portable rules into the transactional server
--                   code.
--
-- Referential actions (consistent with the existing order model):
--   fk_oiv_order       ON DELETE CASCADE  — deleting an order removes
--                      its verification rows, exactly like
--                      order_items / payments / shipments.
--   fk_oiv_order_item  ON DELETE CASCADE  — deleting an order item
--                      removes its verification row (order_items
--                      itself cascades from orders).
--   fk_oiv_product     ON DELETE SET NULL — preserve verification
--                      history if the product is later deleted
--                      (mirrors order_items.product_id).
--   fk_oiv_admin       ON DELETE RESTRICT — preserve attribution; an
--                      admin with verifications cannot be deleted.
--   All four use ON UPDATE CASCADE to match the house style, though
--   the referenced ids are permanent AUTO_INCREMENT identities.
-- ============================================================

CREATE TABLE order_item_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id INT UNSIGNED NOT NULL,

    order_item_id INT UNSIGNED NOT NULL,

    product_id INT UNSIGNED NULL,

    barcode_value VARCHAR(64) NULL,

    verified_by INT UNSIGNED NOT NULL,

    verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    method ENUM('SCANNED','MANUAL') NOT NULL,

    reason VARCHAR(255) NULL,

    -- One verification per order item, ever. This UNIQUE key is the
    -- natural idempotency guard against duplicate / racing verifies.
    CONSTRAINT uq_oiv_order_item
        UNIQUE (order_item_id),

    CONSTRAINT fk_oiv_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_oiv_order_item
        FOREIGN KEY (order_item_id)
        REFERENCES order_items(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_oiv_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_oiv_admin
        FOREIGN KEY (verified_by)
        REFERENCES admins(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    -- method/reason relationship ONLY (references no FK column, so it
    -- is safe from Error 3823). MANUAL requires a reason; SCANNED must
    -- not carry one. The non-whitespace reason rule and all
    -- product/barcode-dependent rules are enforced server-side.
    CONSTRAINT chk_oiv_method_reason
        CHECK (
            (method = 'MANUAL' AND reason IS NOT NULL)
            OR
            (method = 'SCANNED' AND reason IS NULL)
        )
) ENGINE=InnoDB;

-- Progress query helper: count verified items for one order quickly.
CREATE INDEX idx_oiv_order
    ON order_item_verifications (order_id);


-- ============================================================
-- END OF MIGRATION 008
-- ============================================================
--
-- APPLY (review / test database ONLY, after manual review and a
-- fresh backup, exactly once):
--
--   mysql -u <user> -p clothing_shop_bundle_test < database/migrations/008_add_order_item_verifications.sql
--
-- Do NOT apply this to the original local clothing_shop database or
-- the hosted (Hestia) database as part of Phase B.
-- ============================================================
