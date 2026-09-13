ALTER TABLE orders
    ADD COLUMN cancellation_status ENUM(
        'NONE',
        'REQUESTED',
        'APPROVED',
        'REJECTED'
    ) NOT NULL DEFAULT 'NONE';

ALTER TABLE payments
    MODIFY COLUMN payment_method ENUM(
        'COD',
        'GCASH'
    ) NOT NULL;
