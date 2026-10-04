-- ============================================================
-- Migration 003: Create customer_feedback table
-- ============================================================
--
-- Hopia Fits customer feedback rules represented here:
--   * A feedback belongs to exactly one customer.
--   * A feedback belongs to exactly one order.
--   * A feedback belongs to exactly one order item.
--   * Each order item can have at most ONE feedback
--     (UNIQUE order_item_id).
--   * Rating must be between 1 and 5 (CHECK constraint).
--   * Photo is optional (photo_path is nullable).
--   * There is NO approval/moderation status.
--   * There is NO admin ownership field.
--   * Feedback is edited by UPDATING the existing row, so
--     created_at keeps the original submission time and
--     updated_at is maintained by ON UPDATE CURRENT_TIMESTAMP.
--
-- Foreign keys are RESTRICT on delete so historical feedback
-- is never silently removed by unrelated record deletion.
-- No CASCADE DELETE is introduced.
--
-- Application-layer rules (verified in code, not in the schema):
--   * customer_id belongs to the authenticated customer.
--   * order_id belongs to that customer.
--   * The order is DELIVERED.
--   * order_item_id belongs to that order.
--   * The order item does not already have a feedback row.
--
-- Note: the CHECK constraint is enforced on MySQL 8.0.16+.
-- On older MySQL versions it is parsed but ignored, so the
-- application layer must also validate the 1-5 range.
-- ============================================================

CREATE TABLE customer_feedback (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    customer_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,

    rating TINYINT UNSIGNED NOT NULL,
    feedback_text TEXT NOT NULL,
    photo_path VARCHAR(500) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_customer_feedback_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_customer_feedback_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_customer_feedback_order_item
        FOREIGN KEY (order_item_id)
        REFERENCES order_items(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT uq_customer_feedback_order_item
        UNIQUE (order_item_id),

    CONSTRAINT chk_customer_feedback_rating
        CHECK (rating BETWEEN 1 AND 5)
);
