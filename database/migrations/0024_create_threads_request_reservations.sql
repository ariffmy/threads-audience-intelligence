CREATE TABLE threads_request_reservations (
    organization_id BIGINT UNSIGNED NOT NULL,
    threads_account_id BIGINT UNSIGNED NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    credential_generation BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'unknown' CHECK (status IN ('unknown','succeeded','failed')),
    safe_error_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id,threads_account_id,request_key),
    KEY ix_threads_request_expiry (organization_id,expires_at),
    CONSTRAINT fk_threads_request_account FOREIGN KEY (organization_id,threads_account_id) REFERENCES threads_accounts(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
