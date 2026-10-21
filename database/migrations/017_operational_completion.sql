-- Internal dashboard evidence and descriptive fields. Financial totals stay in fin_*.
CREATE TABLE fin_entity_metadata (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, entity_kind VARCHAR(16) NOT NULL,
 entity_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, record_json JSON NOT NULL,
 PRIMARY KEY(environment_id,entity_kind,entity_id),
 FOREIGN KEY(environment_id) REFERENCES environments(id), CHECK(entity_kind IN ('sale','expense'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fin_subscription_evidence (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, event_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 price_snapshot_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, operation_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(environment_id,event_id), UNIQUE(environment_id,operation_id),
 FOREIGN KEY(environment_id,event_id) REFERENCES subscription_events(environment_id,id),
 FOREIGN KEY(environment_id,price_snapshot_id) REFERENCES plan_prices(environment_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
