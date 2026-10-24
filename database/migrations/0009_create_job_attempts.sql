CREATE TABLE job_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    attempt_no SMALLINT UNSIGNED NOT NULL,
    claim_generation BIGINT UNSIGNED NOT NULL,
    correlation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    finished_at DATETIME(6) NULL,
    status VARCHAR(16) NOT NULL CHECK (status IN ('running','succeeded','retry_wait','failed','cancelled')),
    safe_error_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    duration_ms INT UNSIGNED NULL,
    peak_memory_bytes BIGINT UNSIGNED NULL,
    expires_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_attempt_generation (organization_id,job_id,claim_generation),
    KEY ix_attempt_expiry (organization_id,expires_at,id),
    CONSTRAINT fk_attempt_job FOREIGN KEY (organization_id,job_id) REFERENCES jobs(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
