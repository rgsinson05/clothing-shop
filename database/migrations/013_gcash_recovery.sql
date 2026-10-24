-- Phase 6: rejected GCash recovery and manual expired-deadline cancellation.
-- Apply once, after migrations 001-012. This file is not run by the application.
-- Existing rejection times are deliberately left NULL: updated_at is not
-- evidence of when a payment was rejected. Such rows cannot use timed GCash
-- resubmission or expired-deadline cancellation; eligible cash recovery remains.

ALTER TABLE payments
    ADD COLUMN rejected_at DATETIME NULL DEFAULT NULL AFTER rejection_reason,
    ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER rejected_at;

-- revision prevents stale review/recovery forms from acting on a newer
-- submission, including multiple corrections within the same second.
-- GCash-specific fields remain on the same payment row as historical evidence
-- when its method changes to cash; they are not active cash-payment state.

ALTER TABLE orders
    ADD COLUMN cancellation_reason VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN cancelled_by INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN cancelled_at DATETIME NULL DEFAULT NULL,
    ADD CONSTRAINT fk_orders_cancelled_by
        FOREIGN KEY (cancelled_by) REFERENCES admins (id) ON DELETE SET NULL;
