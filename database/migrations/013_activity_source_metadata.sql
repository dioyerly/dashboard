ALTER TABLE subscriptions ADD COLUMN source_origin VARCHAR(255) NULL, ADD COLUMN source_origin_present TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN source_created_at DATETIME(6) NULL, ADD COLUMN source_created_at_present TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE integrations ADD COLUMN source_stats JSON NULL, ADD COLUMN source_stats_present TINYINT(1) NOT NULL DEFAULT 0;
