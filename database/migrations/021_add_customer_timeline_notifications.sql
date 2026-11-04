-- Add customer notification types for the existing Local Courier timeline actions.
-- Existing ENUM ordinals and historical notification rows remain unchanged.
-- Apply once after 020_add_in_transit_delivery_event.sql.
ALTER TABLE customer_order_notifications
    MODIFY COLUMN notification_type ENUM(
        'ORDER_SHIPPED','ORDER_DELIVERED','PICKUP_READY','PICKUP_HANDED_OVER','ORDER_CANCELLED',
        'OUT_FOR_DELIVERY','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP',
        'DELIVERY_CYCLE_AUTHORIZED','ORDER_CONFIRMED','ORDER_PACKED','IN_TRANSIT'
    ) NOT NULL;
