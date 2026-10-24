-- ============================================================
-- Migration 005: Add purchase bundles and per-item barcodes
-- ============================================================
--
-- Business model represented here:
--   * One purchase_bundles row = one actual clothing batch the
--     shop owner bought (one physical purchase/bundle).
--   * One products row = one unique physical garment.
--   * One bundle can contain many products (1 -> many).
--   * Each registered product carries a permanently unique
--     barcode that does not change when its selling price does.
--
-- Barcode text format (formatted and issued by the FUTURE PHP
-- backend, never by SQL):
--   HOPEBUNDLE-000005-01
--   ^ bundle id, 6-digit minimum pad  ^ item seq, 2-digit min pad
--   Both paddings are MINIMUMS only; the bundle id and the item
--   sequence expand naturally for larger values. No fixed bundle
--   size and no 99-item ceiling is implied.
--
-- Design notes (migration-safe by design):
--   * Additive only: no existing column or table is dropped or
--     renamed, and no unrelated definition is touched.
--   * The three new products columns are NULLABLE with NO default,
--     so every existing/legacy product stays unassigned
--     ("not yet grouped"). Nothing is auto-assigned and no barcode
--     is fabricated for any existing row.
--   * Bundles are NOT merged by matching category or capital; each
--     physical purchase receives its own distinct bundle id.
--   * No stored bundle_code column: the displayed code is derived
--     from the permanent id (HOPEBUNDLE-<id>), so no redundant,
--     drift-prone text column is added.
--
-- Enforced by the database (constraints below):
--   * Positive capital_total and positive original_quantity.
--   * Unique, non-null barcode values.
--   * Unique (bundle_id, bundle_item_number) pairing.
--   * bundle_id references purchase_bundles(id).
--   * All-or-nothing assignment: a product is either fully
--     assigned (bundle + item number + barcode) or fully
--     unassigned (all three NULL).
--   * A non-NULL bundle_item_number is at least 1.
--
-- Enforced by the FUTURE PHP backend (transactions + row locking),
-- because they are not expressible as portable table constraints:
--   * product.category must match purchase_bundles.category.
--   * The registered item count may not exceed original_quantity.
--   * Capital/quantity corrections are locked after a completed
--     sale.
--   * Sequential item-number allocation must be concurrency-safe.
--
-- No database trigger is added here for the completed-sale lock.
-- That lock must be driven by a reliable completed-sale event, NOT
-- by products.status = 'SOLD' alone, because the existing checkout
-- flow marks products SOLD at order placement while the order may
-- still be PENDING (and reversible). This migration does not touch
-- order, checkout, payment, reservation, or sales logic.
--
-- Compatibility: MySQL 8.4 and MariaDB 11.4.
--   * CHECK constraints are supported and enforced on both.
--   * IF NOT EXISTS is intentionally NOT used for ADD COLUMN:
--     MariaDB supports it but MySQL does not, and the existing
--     migrations use plain ALTER statements, so this one does too.
--   * ENGINE=InnoDB is set EXPLICITLY on purchase_bundles. Future
--     bundle-item registration must run inside a transaction and take a
--     SELECT ... FOR UPDATE lock on the bundle row to allocate sequential
--     item numbers safely, so the parent table has to be a transactional,
--     row-locking engine. InnoDB is the default engine on both MySQL 8.4
--     and MariaDB 11.4, but it is stated here so that guarantee does not
--     depend on an unverified server default.
--   * CHARSET / COLLATION are intentionally NOT set, matching schema.sql
--     and migrations 001-004, which declare none. The new table therefore
--     inherits the SAME database default as the existing products table,
--     whose charset/collation is likewise not declared in any available
--     SQL source (schema.sql or migrations 001-004), so the two agree by
--     construction instead of by a hard-coded guess. The utf8mb4 seen in
--     includes/database.php is the PDO CONNECTION charset, not a stored
--     table definition, so it is not treated as the products table's
--     declared collation. No MySQL-only collation such as
--     utf8mb4_0900_ai_ci is pinned, because MariaDB 11.4 rejects it. The
--     bundle_id -> products foreign key joins INT UNSIGNED columns, so no
--     string collation has to match across the two tables for it to work.
-- ============================================================


-- ============================================================
-- 1. PURCHASE BUNDLES
-- ============================================================
--
-- One row per actual purchased batch.
--
-- category        : the bundle's single garment category. The
--                   future PHP backend enforces that every linked
--                   product shares this exact category.
-- capital_total   : total purchase cost of the bundle (must be > 0).
-- original_quantity: how many items the bundle held at purchase
--                   (must be > 0). This is the purchase-time count,
--                   NOT a live stock counter.
-- purchase_date   : optional date the bundle was bought; NULL means
--                   "not recorded yet".
-- No bundle_code  : the display code is derived from id.
-- ENGINE=InnoDB   : stated explicitly so future item registration can
--                   rely on transactions and SELECT ... FOR UPDATE row
--                   locking on this table, independent of server default.
-- ============================================================

CREATE TABLE purchase_bundles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    category ENUM('SHIRTS','PANTS','SHORTS') NOT NULL,

    capital_total DECIMAL(10, 2) NOT NULL,

    original_quantity INT UNSIGNED NOT NULL,

    purchase_date DATE NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_purchase_bundles_capital_positive
        CHECK (capital_total > 0),

    CONSTRAINT chk_purchase_bundles_quantity_positive
        CHECK (original_quantity > 0)
) ENGINE=InnoDB;


-- ============================================================
-- 2. PRODUCTS: add bundle assignment + barcode columns
-- ============================================================
--
-- All three columns are NULLABLE with NO default so legacy rows
-- remain fully unassigned. Nothing is backfilled here.
--
-- split from the constraint step below so the columns exist before
-- any constraint references them (portable on both MySQL 8.4 and
-- MariaDB 11.4).
-- ============================================================

ALTER TABLE products
    ADD COLUMN bundle_id INT UNSIGNED NULL AFTER status,
    ADD COLUMN bundle_item_number INT UNSIGNED NULL AFTER bundle_id,
    ADD COLUMN barcode_value VARCHAR(64) NULL AFTER bundle_item_number;


-- ============================================================
-- 3. PRODUCTS: constraints, indexes, and bundle foreign key
-- ============================================================
--
-- fk_products_bundle
--     Links a product to its bundle. Omitting both referential-action
--     clauses leaves them at InnoDB's implicit default of NO ACTION,
--     which is semantically identical to RESTRICT (checked immediately,
--     deferred to transaction commit):
--       * ON DELETE NO ACTION  — deleting a referenced bundle is rejected
--         if any product row still holds that bundle_id, protecting
--         already-issued barcodes from becoming dangling.
--       * ON UPDATE NO ACTION  — changing a bundle's id is rejected if
--         any product row still references it.
--     Omitting ON UPDATE CASCADE also resolves MySQL 8.4 / MariaDB 11.4
--     Error 3823: a CHECK constraint that inspects a column (here
--     bundle_id) is rejected when that same column participates in a
--     foreign key with an explicit referential action such as CASCADE.
--     Removing the action makes the FK "inert" on UPDATE, which is the
--     correct behaviour regardless — bundle ids are permanent identities
--     and are never changed in this business model.
--
-- uq_products_barcode
--     Makes every issued barcode unique. Multiple NULLs are allowed
--     by a UNIQUE index on both engines, so legacy NULL barcodes
--     coexist without conflict. This is what keeps a barcode stable
--     and non-duplicable regardless of product price.
--
-- uq_products_bundle_item
--     Makes (bundle_id, bundle_item_number) unique, so one bundle
--     cannot hold two items with the same number. Multiple
--     (NULL, NULL) legacy rows are allowed for the same reason.
--
-- chk_products_bundle_assignment
--     Forces the three columns to move together: either all NULL
--     (unassigned) or all populated (assigned). Prevents half-set
--     rows such as a bundle_id with no barcode.
--
-- chk_products_bundle_item_number
--     Keeps a non-NULL item number at 1 or above; NULL stays
--     allowed for unassigned rows.
-- ============================================================

ALTER TABLE products
    ADD CONSTRAINT fk_products_bundle
        FOREIGN KEY (bundle_id)
        REFERENCES purchase_bundles(id),

    ADD CONSTRAINT uq_products_barcode
        UNIQUE (barcode_value),

    ADD CONSTRAINT uq_products_bundle_item
        UNIQUE (bundle_id, bundle_item_number),

    ADD CONSTRAINT chk_products_bundle_assignment
        CHECK (
            (bundle_id IS NULL AND bundle_item_number IS NULL AND barcode_value IS NULL)
            OR
            (bundle_id IS NOT NULL AND bundle_item_number IS NOT NULL AND barcode_value IS NOT NULL)
        ),

    ADD CONSTRAINT chk_products_bundle_item_number
        CHECK (bundle_item_number IS NULL OR bundle_item_number >= 1);


-- ============================================================
-- END OF MIGRATION
-- ============================================================
