-- Additive only. Review and apply once only under separate migration approval.
-- This task does NOT authorize execution. Requires the complete migration 016.
-- No existing table, order, cancellation, payment, shipping fee or data is changed.
-- COD HOLD_RESOLVED abandons an inventory reservation, NOT a financial obligation.
-- No redelivery action is supported; hold resolution is item-level only.
-- Paid/collected COD and all GCash remain unsupported: no refund model exists.
-- Application transactions alone enforce locked financial/custody eligibility.
-- DDL implicitly commits; inspect complete definitions after any partial failure.
CREATE TABLE return_stock_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    delivery_cycle INT UNSIGNED NOT NULL,
    delivery_revision INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    physical_return_event_id BIGINT UNSIGNED NOT NULL,
    event_kind ENUM('INSPECTION','HOLD_RESOLVED','STOCK_RELEASED') NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    outcome ENUM('PASSED','MANUAL_REVIEW','RESOLVED','AVAILABLE') NOT NULL,
    previous_inventory_state ENUM('SOLD','AVAILABLE') NOT NULL,
    resulting_inventory_state ENUM('SOLD','AVAILABLE') NOT NULL,
    action_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_return_stock_action (action_key),
    UNIQUE KEY uq_return_stock_item_kind (order_id, delivery_cycle, order_item_id, event_kind),
    KEY idx_return_stock_product (product_id, order_id, delivery_cycle),
    CONSTRAINT fk_return_stock_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT fk_return_stock_item FOREIGN KEY (order_item_id) REFERENCES order_items (id),
    CONSTRAINT fk_return_stock_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_return_stock_admin FOREIGN KEY (admin_id) REFERENCES admins (id),
    CONSTRAINT fk_return_stock_event FOREIGN KEY (physical_return_event_id, order_id, delivery_cycle)
        REFERENCES delivery_events (id, order_id, delivery_cycle),
    CONSTRAINT fk_return_stock_custody FOREIGN KEY (physical_return_event_id, order_item_id)
        REFERENCES delivery_event_items (event_id, order_item_id),
    CONSTRAINT chk_return_stock_cycle CHECK (delivery_cycle >= 1 AND delivery_revision >= 1),
    CONSTRAINT chk_return_stock_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
    CONSTRAINT chk_return_stock_action CHECK (CHAR_LENGTH(action_key) BETWEEN 16 AND 64 AND CHAR_LENGTH(request_hash) = 64),
    CONSTRAINT chk_return_stock_outcome CHECK (
        previous_inventory_state = 'SOLD' AND (
            (event_kind = 'INSPECTION' AND outcome IN ('PASSED','MANUAL_REVIEW') AND resulting_inventory_state = 'SOLD')
            OR (event_kind = 'HOLD_RESOLVED' AND outcome = 'RESOLVED' AND resulting_inventory_state = 'SOLD')
            OR (event_kind = 'STOCK_RELEASED' AND outcome = 'AVAILABLE' AND resulting_inventory_state = 'AVAILABLE')
        )
    )
) ENGINE=InnoDB;

CREATE TRIGGER return_stock_events_no_update BEFORE UPDATE ON return_stock_events
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return stock history is append-only';
CREATE TRIGGER return_stock_events_no_delete BEFORE DELETE ON return_stock_events
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return stock history is append-only';
