CREATE TABLE retention_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    source_days SMALLINT UNSIGNED NOT NULL CHECK (source_days BETWEEN 1 AND 90),
    export_days SMALLINT UNSIGNED NOT NULL CHECK (export_days BETWEEN 1 AND 7),
    log_days SMALLINT UNSIGNED NOT NULL CHECK (log_days BETWEEN 1 AND 30),
    audit_days SMALLINT UNSIGNED NOT NULL CHECK (audit_days BETWEEN 1 AND 90),
    backup_days SMALLINT UNSIGNED NOT NULL CHECK (backup_days BETWEEN 1 AND 30),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_policy_revision (organization_id,id),
    CONSTRAINT fk_policy_org FOREIGN KEY (organization_id) REFERENCES privacy_scopes(organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
