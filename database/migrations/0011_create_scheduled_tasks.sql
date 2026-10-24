CREATE TABLE scheduled_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    schedule_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    interval_seconds INT UNSIGNED NOT NULL CHECK (interval_seconds BETWEEN 60 AND 86400),
    next_due_at DATETIME(6) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_schedule_org (organization_id,schedule_key),
    KEY ix_schedule_due (organization_id,enabled,next_due_at,id),
    CONSTRAINT fk_schedule_org FOREIGN KEY (organization_id) REFERENCES privacy_scopes(organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
