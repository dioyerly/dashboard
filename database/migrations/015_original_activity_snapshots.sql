CREATE TABLE original_activity_snapshots (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 collection VARCHAR(32) NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 ordinal INT UNSIGNED NOT NULL,
 source_path VARCHAR(255) NOT NULL,
 payload JSON NOT NULL,
 content_hash CHAR(64) NOT NULL,
 import_status VARCHAR(16) NOT NULL DEFAULT 'historical',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,collection,id), UNIQUE(environment_id,collection,content_hash),
 KEY original_activity_order(environment_id,collection,ordinal),
 CONSTRAINT original_activity_status CHECK(import_status IN ('historical','conflict','rejected')),
 CONSTRAINT original_activity_hash CHECK(CHAR_LENGTH(content_hash)=64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
