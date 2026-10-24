CREATE TABLE auth_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    session_id_hash BINARY(32) NOT NULL,
    session_version BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    absolute_expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    UNIQUE KEY uq_auth_session_hash (session_id_hash),
    KEY ix_auth_session_user (user_id,revoked_at),
    KEY ix_auth_session_expiry (absolute_expires_at,id),
    KEY ix_auth_session_membership (organization_id,user_id),
    CONSTRAINT fk_auth_session_membership FOREIGN KEY (organization_id,user_id) REFERENCES memberships(organization_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
