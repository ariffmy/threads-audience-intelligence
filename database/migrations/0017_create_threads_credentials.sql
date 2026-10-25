CREATE TABLE threads_credentials (
    organization_id BIGINT UNSIGNED NOT NULL,
    threads_account_id BIGINT UNSIGNED NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(8) NOT NULL CHECK (kind IN ('short','long')),
    envelope JSON NOT NULL CHECK (OCTET_LENGTH(envelope)<=16384),
    issued_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    verified_at DATETIME(6) NULL,
    PRIMARY KEY (organization_id,threads_account_id),
    KEY ix_threads_credential_expiry (organization_id,expires_at),
    CONSTRAINT fk_threads_credential_account FOREIGN KEY (organization_id,threads_account_id) REFERENCES threads_accounts(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
