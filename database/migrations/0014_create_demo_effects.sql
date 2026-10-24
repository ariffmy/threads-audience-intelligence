CREATE TABLE demo_effects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    effect_value INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_effect_job (organization_id,job_id),
    KEY ix_effect_expiry (organization_id,expires_at,id),
    KEY ix_effect_created (organization_id,created_at,id),
    CONSTRAINT fk_effect_job FOREIGN KEY (organization_id,job_id) REFERENCES jobs(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
