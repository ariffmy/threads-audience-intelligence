CREATE TABLE deletion_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_kind VARCHAR(32) NOT NULL CHECK (target_kind='synthetic_scope'),
    scope_epoch BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL CHECK (status IN ('blocked','completed')),
    requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    primary_completed_at DATETIME(6) NULL,
    backup_expiry_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_deletion_org_id (organization_id,id),
    UNIQUE KEY uq_deletion_request (organization_id,request_key),
    KEY ix_deletion_status (organization_id,status,id),
    CONSTRAINT fk_deletion_scope FOREIGN KEY (organization_id) REFERENCES privacy_scopes(organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
