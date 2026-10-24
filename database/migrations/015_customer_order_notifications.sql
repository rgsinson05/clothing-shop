-- ============================================================
-- Migration 015: Customer Order Notifications (Phase 14)
-- ============================================================
--
-- Scope (Phase 14 ONLY):
--   This migration is ADDITIVE. It creates ONE new table,
--   customer_order_notifications, that persistently records
--   customer-facing notifications whenever a supported admin action
--   successfully changes an order's status or fulfillment state.
--   It touches no existing table, column, index, trigger, order,
--   shipment, payment, sale, cancellation, or Phase 13 admin
--   notification logic.
--
-- Purpose:
--   The backend records these notifications so they remain available
--   after page refreshes, browser restarts, and subsequent customer
--   sessions. Phase 15 will add the customer bell / badge / dropdown /
--   history UI, and Phase 16 live polling. This table is the
--   notification foundation those later phases read from.
--
-- Design notes (migration-safe by design):
--   * A NEW dedicated table is used, deliberately SEPARATE from the
--     Phase 13 admin_order_notifications table: customer notifications
--     carry their own recipient (customer_id), read state, and access
--     control KEYED to a customer, never to an admin. The two
--     notification systems are logically and physically distinct.
--   * customer_id is stored explicitly (not merely derived from the
--     order) so per-customer retrieval and unread counting are direct
--     index lookups and cannot drift if order ownership changes.
--     A foreign key keeps it consistent with the real customer
--     identity model (customers.id).
--   * notification_type is a fixed ENUM matching the five supported
--     Phase 14 events. Each of these events happens at most once per
--     order under the existing order lifecycle, so a UNIQUE key on
--     (customer_id, order_id, notification_type) is a correct and
--     sufficient duplicate-protection strategy. A repeated request,
--     double-click, or retry of the same committed event is a no-op.
--   * message stores a readable, customer-safe sentence generated
--     consistently by the PHP helper from the event type + order id
--     (+ tracking number where appropriate).
--   * read_at is a nullable DATETIME: NULL means unread; a non-NULL
--     value is the persisted read timestamp and is preserved once set.
--   * Navigation is NOT stored here. The destination is derived at
--     read time by trusted application logic from the event type and
--     order id, so a customer can never manipulate stored metadata into
--     an arbitrary external URL. All five events route to the existing
--     customer order detail page (customer/order-detail.php?id=...).
--   * Foreign keys are RESTRICT on delete so a customer or order that
--     owns notifications is never silently removed.
--   * Inheritance of database charset/collation and the existing
--     primary-key conventions matches schema.sql and migrations 001-014.
--     InnoDB is used for referential integrity.
--
-- Indexes:
--   * uq_customer_notification_order_type enforces duplicate protection
--     and also serves the "notifications for one customer + one order"
--     lookup.
--   * idx_customer_notification_newest serves newest-first fetching for
--     one customer.
--   * idx_customer_notification_unread serves the unread count for one
--     customer.
--
-- Compatibility: MySQL 8.4 (local) and MariaDB 11.4 (hosting).
--   * IF NOT EXISTS is intentionally NOT used, matching schema.sql and
--     migrations 001-014. Apply exactly once (see end).
--   * CHARSET / COLLATE are intentionally omitted (house style).
--
-- schema.sql is intentionally NOT edited to mirror this migration,
-- matching the approach of migrations 005-014.
-- ============================================================

CREATE TABLE customer_order_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    notification_type ENUM(
        'ORDER_SHIPPED',
        'ORDER_DELIVERED',
        'PICKUP_READY',
        'PICKUP_HANDED_OVER',
        'ORDER_CANCELLED'
    ) NOT NULL,
    message VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_customer_notification_order_type (customer_id, order_id, notification_type),
    KEY idx_customer_notification_newest (customer_id, created_at, id),
    KEY idx_customer_notification_unread (customer_id, read_at),
    CONSTRAINT fk_customer_notification_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_customer_notification_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- END OF MIGRATION 015
-- ============================================================
--
-- APPLY (review / test database ONLY, after manual review and a
-- fresh backup, exactly once):
--
--   mysql -u <user> -p clothing_shop_bundle_test < database/migrations/015_customer_order_notifications.sql
--
-- Do NOT apply this to the original local clothing_shop database or
-- the hosted (Hestia) database as part of Phase 14 development. The
-- application and tests never apply this migration automatically.
-- ============================================================