-- Additive Google customer identity support. Review and apply once only.
-- This task does NOT authorize execution against any database.
-- Existing password hashes and customer records are preserved unchanged.
-- NULL password_hash represents a provider-only account and cannot pass password login.
ALTER TABLE customers
    MODIFY password_hash VARCHAR(255) NULL;

CREATE TABLE customer_identities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_identity_subject (provider, provider_subject),
    UNIQUE KEY uq_customer_identity_customer_provider (customer_id, provider),
    CONSTRAINT fk_customer_identity_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB;
