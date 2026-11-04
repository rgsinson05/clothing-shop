-- Restore the distinct Shipped -> In Transit courier action and its append-only history event.
-- Review and apply once after 016_courier_delivery_workflow.sql. No order, payment, pickup,
-- cancellation, receipt, inventory, or customer-notification schema is changed.
ALTER TABLE delivery_events
    MODIFY COLUMN event_type ENUM(
        'OUT_FOR_DELIVERY','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP',
        'RETURN_INSPECTED','DELIVERY_CYCLE_AUTHORIZED','DELIVERED','IN_TRANSIT'
    ) NOT NULL;
