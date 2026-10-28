-- Add secure, single-use customer password reset tokens. Review and apply once only.
-- This migration must be applied after 018_google_customer_identities.sql.
-- Only SHA-256 token hashes are stored; plaintext reset tokens are never persisted.
CREATE TABLE customer_password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_password_reset_token (token_hash),
    KEY idx_customer_password_reset_customer_created (customer_id, created_at),
    KEY idx_customer_password_reset_expiry (expires_at),
    CONSTRAINT fk_customer_password_reset_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB;
