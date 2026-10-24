-- ============================================================
-- Migration 010: Fulfillment & shipping (Phase 1 checkout)
-- ============================================================
--
-- Scope (Phase 1 ONLY):
--   This migration is ADDITIVE. It:
--     (1) extends shipments.delivery_method ENUM with STORE_PICKUP,
--     (2) adds a CASH_ON_PICKUP value to payments.payment_method,
--   and touches no other table, column, index, trigger, order,
--   checkout, payment, cancellation, or Phase A/B/C logic.
--
-- Design notes (migration-safe by design):
--   * No data is rewritten, backfilled, or deleted. Existing
--     shipments keep LOCAL_COURIER; existing payments keep their
--     recorded method. No historical sale is altered.
--   * ENUM values are APPENDED after the existing ones so the
--     numeric representation of every stored value is unchanged
--     (MySQL maps ENUM members by list position).
--   * orders.subtotal / shipping_fee / total_amount are untouched:
--     they already store the merchandise subtotal, the shipping fee
--     charged at purchase, and the final payable amount.
--   * This migration only WIDENS what checkout may store; it does
--     not implement the pickup-status workflow (later phase).
--
-- (1) shipments.delivery_method gains STORE_PICKUP so a pickup order
--     can persist its fulfillment method exactly as chosen at
--     checkout. NULL ("not yet recorded") is preserved for legacy
--     rows; no row is backfilled.
--
-- (2) payments.payment_method gains CASH_ON_PICKUP so a pickup
--     order is not misleadingly recorded as courier COD. The value
--     is appended after CARD so existing COD/GCASH/CARD rows keep
--     their positions. Label maps in customer/admin pages treat it
--     as "Cash on Pickup".
--
-- Compatibility: MySQL 8.4 (local) and MariaDB 11.4 (hosting).
--   * IF NOT EXISTS is intentionally NOT used, matching
--     migrations 001-009 and schema.sql.
--   * CHARSET / COLLATE are intentionally omitted, matching the
--     house style.
--
-- schema.sql is intentionally NOT edited to mirror this migration,
-- matching the approach of migrations 005-009.
-- ============================================================

-- 1. shipments.delivery_method: append STORE_PICKUP.
ALTER TABLE shipments
    MODIFY COLUMN delivery_method ENUM('LOCAL_COURIER','STORE_PICKUP') NULL DEFAULT NULL;

-- 2. payments.payment_method: append CASH_ON_PICKUP.
ALTER TABLE payments
    MODIFY COLUMN payment_method ENUM('COD','GCASH','CARD','CASH_ON_PICKUP') NOT NULL;

-- ============================================================
-- END OF MIGRATION 010
-- ============================================================
--
-- APPLY (test database ONLY, after review, exactly once):
--
--   mysql -u <user> -p clothing_shop_bundle_test < database/migrations/010_fulfillment_shipping.sql
--
-- Do NOT apply this to the original local clothing_shop database or
-- the hosted (Hestia) database as part of Phase 1 development.
-- ============================================================
