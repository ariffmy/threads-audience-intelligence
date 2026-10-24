CREATE TABLE login_throttles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bucket_hash BINARY(32) NOT NULL,
    bucket_kind VARCHAR(16) NOT NULL CHECK (bucket_kind IN ('email','ip')),
    window_started_at DATETIME(6) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_login_bucket (bucket_hash,bucket_kind,window_started_at),
    KEY ix_login_throttle_expiry (expires_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
