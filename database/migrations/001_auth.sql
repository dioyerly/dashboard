-- PREPARED ONLY. No execution authorized on Hostinger.
-- MySQL 8.0 / MariaDB: verify actual version, CHECK enforcement, charset and privileges before applying.
CREATE TABLE schema_migrations (
    version VARCHAR(64) NOT NULL PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_users (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    session_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY auth_users_email (email),
    CONSTRAINT auth_single_admin CHECK (id = 1),
    CONSTRAINT auth_active_boolean CHECK (active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_login_limits (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    window_start BIGINT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    KEY auth_limits_expiry (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Sessions deliberately use PHP private files in this phase: no session table needed.
-- No default administrator or passwords are inserted by this migration.
