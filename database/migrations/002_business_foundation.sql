-- Isolated business foundation. Does not activate dashboard storage.
CREATE TABLE environments (
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
 code VARCHAR(32) NOT NULL UNIQUE,
 label VARCHAR(120) NOT NULL,
 CONSTRAINT environment_code CHECK (code IN ('produccion','pruebas'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE currencies (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 symbol VARCHAR(16) NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CONSTRAINT currency_code CHECK (id REGEXP '^[A-Z]{3,5}$'),
 CONSTRAINT currency_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE brands (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL,
 color VARCHAR(7) NOT NULL,
 dark VARCHAR(7) NOT NULL,
 alt VARCHAR(7) NOT NULL,
 description TEXT NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id), UNIQUE(environment_id,name),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CONSTRAINT brand_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE channels (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL,
 commission_pct DECIMAL(9,6) NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id), UNIQUE(environment_id,name),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CONSTRAINT channel_commission CHECK (commission_pct IS NULL OR (commission_pct >= 0 AND commission_pct < 100)),
 CONSTRAINT channel_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projects (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 brand_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL,
 kind VARCHAR(32) NOT NULL,
 status VARCHAR(32) NOT NULL,
 launch_date DATE NULL,
 url VARCHAR(2048) NOT NULL,
 description TEXT NOT NULL,
 domain VARCHAR(255) NOT NULL,
 repo VARCHAR(2048) NOT NULL,
 hosting VARCHAR(255) NOT NULL,
 database_description VARCHAR(255) NOT NULL,
 tech VARCHAR(255) NOT NULL,
 milestone VARCHAR(255) NOT NULL,
 initial_investment DECIMAL(20,2) NULL,
 investment_currency VARCHAR(64) COLLATE utf8mb4_bin NULL,
 notes TEXT NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id), UNIQUE(environment_id,name),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,brand_id) REFERENCES brands(environment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,investment_currency) REFERENCES currencies(environment_id,id) ON DELETE RESTRICT,
 CONSTRAINT project_investment CHECK (initial_investment IS NULL OR (initial_investment >= 0 AND investment_currency IS NOT NULL)),
 CONSTRAINT project_kind CHECK (kind IN ('descargable','pago_unico','suscripcion','desarrollo','interna')),
 CONSTRAINT project_status CHECK (status IN ('idea','desarrollo','pruebas','publicada','pausada','retirada')),
 CONSTRAINT project_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL,
 email VARCHAR(254) NULL,
 country VARCHAR(120) NOT NULL,
 business_date DATE NULL,
 consent TINYINT UNSIGNED NOT NULL,
 notes TEXT NOT NULL,
 origin VARCHAR(32) NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id), UNIQUE(environment_id,email),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 CONSTRAINT customer_consent CHECK (consent IN (0,1)),
 CONSTRAINT customer_origin CHECK (origin IN ('manual','importado','integracion','estimado')),
 CONSTRAINT customer_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL,
 currency_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id), UNIQUE(environment_id,name),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,currency_id) REFERENCES currencies(environment_id,id) ON DELETE RESTRICT,
 CONSTRAINT account_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE business_settings (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(160) NOT NULL,
 owner VARCHAR(160) NOT NULL,
 site VARCHAR(2048) NOT NULL,
 base_currency VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 num_format VARCHAR(2) NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 archived_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 FOREIGN KEY(environment_id,base_currency) REFERENCES currencies(environment_id,id) ON DELETE RESTRICT,
 CONSTRAINT singleton_settings CHECK (id='main'),
 CONSTRAINT settings_format CHECK (num_format IN ('es','en')),
 CONSTRAINT settings_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE business_operations (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 operation_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 actor_user_id TINYINT UNSIGNED NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json LONGTEXT NOT NULL,
 committed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,operation_id),
 FOREIGN KEY(environment_id) REFERENCES environments(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_user_id) REFERENCES auth_users(id) ON DELETE RESTRICT,
 CONSTRAINT operation_result_json CHECK (JSON_VALID(result_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE business_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 operation_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 actor_user_id TINYINT UNSIGNED NOT NULL,
 command_json LONGTEXT NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE(environment_id,operation_id),
 FOREIGN KEY(environment_id,operation_id) REFERENCES business_operations(environment_id,operation_id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_user_id) REFERENCES auth_users(id) ON DELETE RESTRICT,
 CONSTRAINT audit_command_json CHECK (JSON_VALID(command_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
