-- Phase 13: Admin New-Order Notification Bell (migration number != phase).
-- Apply once, manually, after migration 013 and BEFORE deploying checkout.
-- The application and tests never apply this migration automatically.
-- Shared admin inbox: read_at NULL means unread for all authorized admins.
-- Snapshot methods preserve placement history after GCash/cash recovery.
-- Inherit database charset/collation, as in the existing migrations.
-- No backfill: older orders must not be presented as newly placed orders.

CREATE TABLE admin_order_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    notification_type ENUM('NEW_ORDER') NOT NULL DEFAULT 'NEW_ORDER',
    delivery_method VARCHAR(30) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_admin_notification_order_type (order_id, notification_type),
    KEY idx_admin_notification_newest (created_at, id),
    KEY idx_admin_notification_unread (read_at, id),
    CONSTRAINT fk_admin_notification_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
