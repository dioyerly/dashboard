-- Catalog expansion only. No frontend activation and no financial operations.
CREATE TABLE product_categories (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 brand_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, name VARCHAR(160) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,brand_id) REFERENCES brands(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,brand_id,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE formats (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 UNIQUE(environment_id,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE languages (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 UNIQUE(environment_id,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_methods (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 UNIQUE(environment_id,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expense_groups (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 UNIQUE(environment_id,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expense_categories (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 group_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, name VARCHAR(160) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,group_id) REFERENCES expense_groups(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,group_id,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plans (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 project_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, name VARCHAR(160) NOT NULL, status VARCHAR(16) NOT NULL, trial_days INT UNSIGNED NOT NULL DEFAULT 0, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,project_id) REFERENCES projects(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,project_id,name), CHECK(status IN ('activa','retirada'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plan_prices (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 plan_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, valid_from DATE NULL, valid_to DATE NULL, price DECIMAL(20,2) NOT NULL, currency_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, period VARCHAR(16) NOT NULL, provenance VARCHAR(16) NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,plan_id) REFERENCES plans(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,currency_id) REFERENCES currencies(environment_id,id) ON DELETE RESTRICT,
 INDEX(environment_id,plan_id,valid_from), CHECK(price>=0), CHECK(period IN ('mensual','anual')), CHECK(provenance IN ('known','reconstructed','unknown')), CHECK(valid_to IS NULL OR (valid_from IS NOT NULL AND valid_to>valid_from)), CHECK(valid_from IS NOT NULL OR provenance='unknown')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 brand_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, project_id VARCHAR(64) COLLATE utf8mb4_bin NULL, category_id VARCHAR(64) COLLATE utf8mb4_bin NULL, category_label_snapshot VARCHAR(160) NOT NULL, name VARCHAR(160) NOT NULL, status VARCHAR(32) NOT NULL, launch_date DATE NULL, pending_updates TEXT NOT NULL, source_created_at DATETIME(6) NULL, source_updated_at DATETIME(6) NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,brand_id) REFERENCES brands(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,project_id) REFERENCES projects(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,category_id) REFERENCES product_categories(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,brand_id,name), CHECK(status IN ('borrador','publicado','actualizar','pausado','retirado'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_versions (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 product_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, format_id VARCHAR(64) COLLATE utf8mb4_bin NULL, format_label VARCHAR(160) NOT NULL, language_id VARCHAR(64) COLLATE utf8mb4_bin NULL, language_label VARCHAR(160) NOT NULL, version_label VARCHAR(64) NOT NULL, price DECIMAL(20,2) NOT NULL, currency_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,product_id) REFERENCES products(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,format_id) REFERENCES formats(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,language_id) REFERENCES languages(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,currency_id) REFERENCES currencies(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,product_id,format_label,language_label,version_label), CHECK(price>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_links (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 product_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, link_value TEXT NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,product_id) REFERENCES products(environment_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bundle_items (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 bundle_product_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, component_product_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,bundle_product_id) REFERENCES products(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,component_product_id) REFERENCES products(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,bundle_product_id,component_product_id), CHECK(bundle_product_id<>component_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_channels (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 product_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, channel_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,product_id) REFERENCES products(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,channel_id) REFERENCES channels(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,product_id,channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_version_channels (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 version_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, channel_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,version_id) REFERENCES product_versions(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,channel_id) REFERENCES channels(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,version_id,channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_user_stats (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 project_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, business_date DATE NOT NULL, sequence_no INT UNSIGNED NOT NULL, registered BIGINT UNSIGNED NOT NULL, active BIGINT UNSIGNED NOT NULL, origin VARCHAR(32) NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,project_id) REFERENCES projects(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,project_id,business_date,sequence_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_version_history (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 version_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, revision BIGINT UNSIGNED NOT NULL, product_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, format_id VARCHAR(64) COLLATE utf8mb4_bin NULL, format_label VARCHAR(160) NOT NULL, language_id VARCHAR(64) COLLATE utf8mb4_bin NULL, language_label VARCHAR(160) NOT NULL, version_label VARCHAR(64) NOT NULL, price DECIMAL(20,2) NOT NULL, currency_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, sort_order INT UNSIGNED NOT NULL, version_archived_at DATETIME(6) NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CHECK (row_version > 0),
 FOREIGN KEY(environment_id,version_id) REFERENCES product_versions(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,product_id) REFERENCES products(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,format_id) REFERENCES formats(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,language_id) REFERENCES languages(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,currency_id) REFERENCES currencies(environment_id,id) ON DELETE RESTRICT,
 UNIQUE(environment_id,version_id,revision), CHECK(price>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE business_page_cursors (
 id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 query_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision BIGINT UNSIGNED NOT NULL,
 last_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE environments ADD COLUMN catalog_revision BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE projects ADD COLUMN source_created_at DATETIME(6) NULL, ADD COLUMN source_updated_at DATETIME(6) NULL;

