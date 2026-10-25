CREATE TABLE threads_account_grants (
    organization_id BIGINT UNSIGNED NOT NULL,
    threads_account_id BIGINT UNSIGNED NOT NULL,
    permission VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL CHECK (permission IN ('threads_basic','threads_read_replies')),
    revision BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    verified_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    evidence_kind VARCHAR(16) NOT NULL DEFAULT 'fixture' CHECK (evidence_kind='fixture'),
    PRIMARY KEY (organization_id,threads_account_id,permission),
    KEY ix_threads_grant_expiry (organization_id,expires_at),
    CONSTRAINT fk_threads_grant_account FOREIGN KEY (organization_id,threads_account_id) REFERENCES threads_accounts(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
