CREATE TABLE threads_sync_checkpoints (
    organization_id BIGINT UNSIGNED NOT NULL,
    threads_account_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    cursor_envelope JSON NULL CHECK (OCTET_LENGTH(cursor_envelope)<=8192),
    cursor_hashes JSON NOT NULL CHECK (OCTET_LENGTH(cursor_hashes)<=2048),
    PRIMARY KEY (organization_id,threads_account_id,run_id),
    CONSTRAINT fk_threads_checkpoint_run FOREIGN KEY (organization_id,threads_account_id,run_id) REFERENCES threads_sync_runs(organization_id,threads_account_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
