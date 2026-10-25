ALTER TABLE jobs
    ADD COLUMN threads_account_id BIGINT UNSIGNED NULL,
    ADD COLUMN account_privacy_epoch BIGINT UNSIGNED NULL,
    ADD COLUMN credential_generation BIGINT UNSIGNED NULL,
    ADD COLUMN grant_revision BIGINT UNSIGNED NULL,
    ADD KEY ix_job_threads_account (organization_id,threads_account_id,status,id),
    ADD CONSTRAINT fk_job_threads_account FOREIGN KEY (organization_id,threads_account_id) REFERENCES threads_accounts(organization_id,id),
    ADD CONSTRAINT ck_job_threads_fences CHECK (
        (threads_account_id IS NULL AND account_privacy_epoch IS NULL AND credential_generation IS NULL AND grant_revision IS NULL)
        OR (threads_account_id IS NOT NULL AND account_privacy_epoch IS NOT NULL AND credential_generation IS NOT NULL AND grant_revision IS NOT NULL)
    );
