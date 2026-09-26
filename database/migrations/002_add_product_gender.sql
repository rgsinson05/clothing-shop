-- ============================================================
-- Migration 002: Add product gender classification
-- ============================================================
--
-- Hopia Fits classifies every product as exactly one of:
--   MEN
--   WOMEN
--
-- Design notes (migration-safe by design):
--   * The column is NULLABLE with NO default on purpose.
--   * Existing product rows are preserved untouched.
--   * Legacy rows created before this migration keep gender = NULL,
--     which means "not yet classified".
--   * No gender value is fabricated or guessed for existing rows.
--   * NULL is not a new gender category; it only marks rows that
--     still require manual classification by an admin.
--
-- Newly created products always receive a gender because the admin
-- product form requires MEN or WOMEN.
--
-- To count products that still need classification after running
-- this migration:
--   SELECT COUNT(*) FROM products WHERE gender IS NULL;
-- ============================================================

ALTER TABLE products
    ADD COLUMN gender ENUM('MEN','WOMEN') NULL DEFAULT NULL AFTER category;
