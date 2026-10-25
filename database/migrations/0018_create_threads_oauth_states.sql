CREATE TABLE threads_oauth_states (
    state_hash BINARY(32) NOT NULL PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    threads_account_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    auth_session_id BIGINT UNSIGNED NOT NULL,
    session_hash BINARY(32) NOT NULL,
    redirect_uri VARCHAR(512) NOT NULL,
    expected_generation BIGINT UNSIGNED NOT NULL,
    expected_epoch BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    KEY ix_threads_state_expiry (organization_id,expires_at),
    CONSTRAINT fk_threads_state_account FOREIGN KEY (organization_id,threads_account_id) REFERENCES threads_accounts(organization_id,id),
    CONSTRAINT fk_threads_state_member FOREIGN KEY (organization_id,user_id) REFERENCES memberships(organization_id,user_id),
    CONSTRAINT fk_threads_state_session FOREIGN KEY (auth_session_id) REFERENCES auth_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
