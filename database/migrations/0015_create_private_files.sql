CREATE TABLE private_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    storage_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    privacy_epoch BIGINT UNSIGNED NOT NULL,
    is_ready BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_file_scope (organization_id,storage_key),
    KEY ix_file_expiry (organization_id,expires_at,id),
    KEY ix_file_created (organization_id,created_at,id),
    CONSTRAINT fk_file_scope FOREIGN KEY (organization_id) REFERENCES privacy_scopes(organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
