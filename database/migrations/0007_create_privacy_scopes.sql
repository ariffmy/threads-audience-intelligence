CREATE TABLE privacy_scopes (
    organization_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    privacy_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1,
    blocked_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_scope_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
