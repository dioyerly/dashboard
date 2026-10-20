-- Opt-in operational state bridge for the existing browser model.
-- This is current state, not a historical snapshot or a financial ledger.
CREATE TABLE business_state (
    environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    state_json JSON NOT NULL,
    state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_by TINYINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES auth_users(id) ON DELETE RESTRICT,
    CONSTRAINT business_state_hash CHECK (CHAR_LENGTH(state_hash)=64),
    CONSTRAINT business_state_version CHECK (row_version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
