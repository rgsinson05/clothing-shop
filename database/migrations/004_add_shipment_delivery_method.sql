-- ============================================================
-- Migration 004: Add shipment delivery method
-- ============================================================
--
-- Hopia Fits delivery options represented here:
--   * LOCAL_COURIER
--
-- Delivery-method scope for this phase:
--   * LOCAL_COURIER is currently the ONLY available delivery
--     option, and the checkout form always stores it.
--   * No shipping fee, courier fee, or delivery fee is added by
--     this migration. orders.subtotal and orders.total_amount are
--     unchanged; this phase is delivery method only.
--
-- Design notes (migration-safe by design):
--   * The column is added to the EXISTING shipments table.
--     There is intentionally NO delivery_methods table and no
--     foreign key to one.
--   * The column is NULLABLE with NO default on purpose.
--   * Existing shipment rows are preserved untouched.
--   * Legacy rows created before this migration keep
--     delivery_method = NULL, which means "not yet recorded".
--   * No delivery method is fabricated or backfilled for existing
--     rows, because the method they actually used was never
--     captured at checkout.
--
-- Every shipment created from now on stores delivery_method =
-- 'LOCAL_COURIER' in the same INSERT that creates the shipment.
--
-- Further values (for example 'COURIER_PARTNER') are added by
-- a later migration that extends this ENUM; this migration is
-- the only one that touches delivery_method.
-- ============================================================

ALTER TABLE shipments
    ADD COLUMN delivery_method ENUM('LOCAL_COURIER') NULL DEFAULT NULL
    AFTER shipment_status;