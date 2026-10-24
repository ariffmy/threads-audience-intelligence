CREATE TABLE worker_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    correlation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    finished_at DATETIME(6) NULL,
    status VARCHAR(16) NOT NULL CHECK (status IN ('running','succeeded','failed')),
    processed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NULL,
    peak_memory_bytes BIGINT UNSIGNED NULL,
    safe_error_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expires_at DATETIME(6) NOT NULL,
    KEY ix_worker_org_time (organization_id,started_at,id),
    KEY ix_worker_expiry (organization_id,expires_at,id),
    CONSTRAINT fk_worker_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
