CREATE TABLE threads_deletions (
    organization_id BIGINT UNSIGNED NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_kind VARCHAR(24) NOT NULL CHECK (target_kind IN ('threads_account','threads_source','threads_organization')),
    scope_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'blocked' CHECK (status IN ('blocked','primary_complete')),
    requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    primary_completed_at DATETIME(6) NULL,
    PRIMARY KEY (organization_id,request_key),
    KEY ix_threads_suppression (organization_id,scope_key,target_kind,source_hash),
    KEY ix_threads_deletion_progress (organization_id,status,requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
