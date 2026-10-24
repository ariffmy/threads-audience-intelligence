CREATE TABLE memberships (
    organization_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(16) NOT NULL CHECK (role IN ('owner','analyst','viewer')),
    status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active','disabled')),
    joined_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,user_id),
    KEY ix_membership_user (user_id,status),
    CONSTRAINT fk_membership_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT fk_membership_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
