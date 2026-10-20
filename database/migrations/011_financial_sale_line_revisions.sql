-- Immutable snapshots of sale lines replaced by a sale_edit operation.
CREATE TABLE fin_sale_line_revisions (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 sale_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 operation_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 lines_json JSON NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,sale_id,revision_no),
 UNIQUE(environment_id,operation_id),
 FOREIGN KEY(environment_id,sale_id) REFERENCES fin_sales(environment_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
