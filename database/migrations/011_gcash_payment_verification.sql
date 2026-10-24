-- ============================================================
-- Migration 011: Manual GCash payment + admin verification (Phase 2)
-- ============================================================
--
-- Scope (Phase 2 ONLY):
--   This migration is ADDITIVE. It extends the EXISTING payments
--   table with the metadata needed to record a MANUAL GCash
--   transfer and its admin verification, and touches no other
--   table, column, index, trigger, order, checkout, shipment,
--   cancellation, sale, or Phase A/B/C/1 logic.
--
--   It does NOT create a second payment system: a GCash order
--   keeps its single existing payments row (one per order,
--   UNIQUE(order_id)). The payment_method ENUM already carries
--   'GCASH' (present since schema.sql), so NO ENUM change is made.
--   The payment_status ENUM ('PENDING','PAID','FAILED','REFUNDED')
--   already expresses every state this phase needs, so NO status
--   column and NO status ENUM value are added:
--
--     * PENDING + no reference submitted  -> "not yet submitted"
--     * PENDING + reference submitted     -> "awaiting verification"
--     * PAID (+ verified_by / verified_at)-> admin-verified paid
--     * FAILED (+ rejection_reason)       -> rejected / needs correction
--
--   A GCash payment NEVER becomes PAID automatically; only the
--   admin verification path sets PAID. Submitting a reference or a
--   screenshot keeps the row PENDING.
--
-- Columns added to payments (all nullable, no backfill, so every
-- existing COD / CASH_ON_PICKUP / historical row is unchanged and
-- keeps NULL for all six):
--
--   gcash_reference  VARCHAR(100) NULL
--       The customer-submitted GCash reference / transaction number.
--       NULL until the owning customer submits one. Validated and
--       length-bounded in PHP (includes/payment.php).
--
--   proof_path       VARCHAR(255) NULL
--       Relative path to the OPTIONAL uploaded payment screenshot,
--       stored OUTSIDE the public image flow under storage/ and
--       served only through an authenticated streaming endpoint.
--       NULL when no screenshot was provided.
--
--   submitted_at     DATETIME NULL
--       When the customer last submitted / resubmitted their GCash
--       details. NULL means "not yet submitted". DATETIME (not
--       TIMESTAMP) matches shipped_at / delivered_at / received_at
--       and avoids the 2038 cap under the +08:00 session.
--
--   verified_by      INT UNSIGNED NULL
--       The admin who CONFIRMED the payment as PAID. NULL until a
--       confirmation happens (and NULL again if a prior confirmation
--       is superseded by a rejection). FK -> admins(id).
--
--   verified_at      DATETIME NULL
--       When the admin confirmed the payment as PAID. NULL otherwise.
--
--   rejection_reason VARCHAR(255) NULL
--       The admin's reason when rejecting / requesting correction of
--       the submitted evidence (payment_status = 'FAILED'). NULL
--       otherwise. Cleared when the customer resubmits.
--
-- Referential action (Error 3823 note):
--   No CHECK constraint inspects verified_by, so its foreign key may
--   declare an explicit referential action. fk_payments_verified_by
--   uses ON DELETE RESTRICT (preserve verification attribution: an
--   admin who verified a payment cannot be hard-deleted) and
--   ON UPDATE CASCADE, mirroring fk_oiv_admin (migration 008) and
--   fk_sales_tx_admin (migration 007). RESTRICT only applies to
--   non-NULL values, so unverified rows are unaffected.
--
-- Compatibility: MySQL 8.4 (local) and MariaDB 11.4 (hosting).
--   * IF NOT EXISTS is intentionally NOT used, matching schema.sql
--     and migrations 001-010. Apply exactly once (see end).
--   * CHARSET / COLLATE are intentionally omitted (house style).
--   * The new columns are plain scalars; the single FK joins
--     INT UNSIGNED to admins(id), so no collation has to match.
--
-- schema.sql is intentionally NOT edited to mirror this migration,
-- matching the approach of migrations 005-010.
-- ============================================================

-- 1. Add the six nullable Phase 2 columns to payments.
ALTER TABLE payments
    ADD COLUMN gcash_reference VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN proof_path VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN submitted_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN verified_by INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN verified_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN rejection_reason VARCHAR(255) NULL DEFAULT NULL;

-- 2. Attribute an admin verification without allowing that admin to
--    be hard-deleted while the attribution survives (history-preserving,
--    like the other admin-attributed tables).
ALTER TABLE payments
    ADD CONSTRAINT fk_payments_verified_by
        FOREIGN KEY (verified_by)
        REFERENCES admins(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE;

-- ============================================================
-- END OF MIGRATION 011
-- ============================================================
--
-- APPLY (review / test database ONLY, after manual review and a
-- fresh backup, exactly once):
--
--   mysql -u <user> -p clothing_shop_bundle_test < database/migrations/011_gcash_payment_verification.sql
--
-- Do NOT apply this to the original local clothing_shop database or
-- the hosted (Hestia) database as part of Phase 2 development. As of
-- this migration, clothing_shop_bundle_test already carries
-- migrations 001-010; clothing_shop (local dev) is still on an older
-- schema (no received_at, no STORE_PICKUP / CASH_ON_PICKUP, no
-- sales_transactions) and must be migrated deliberately, not here.
-- ============================================================
