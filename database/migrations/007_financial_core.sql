-- Financial core. Isolated SQL capability; dashboard remains IndexedDB-backed.
CREATE TABLE fin_sales (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 customer_id VARCHAR(64) COLLATE utf8mb4_bin NULL, currency_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 total DECIMAL(20,2) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'valida', row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 issued_on DATE NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,id), FOREIGN KEY(environment_id,customer_id) REFERENCES customers(environment_id,id), FOREIGN KEY(environment_id,currency_id) REFERENCES currencies(environment_id,id),
 CONSTRAINT fin_sale_total CHECK(total>=0), CONSTRAINT fin_sale_status CHECK(status IN ('valida','anulada')), CONSTRAINT fin_sale_version CHECK(row_version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fin_sale_lines (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, sale_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, line_no INT UNSIGNED NOT NULL,
 product_id VARCHAR(64) COLLATE utf8mb4_bin NULL, description VARCHAR(255) NOT NULL, quantity DECIMAL(20,6) NOT NULL, unit_price DECIMAL(20,2) NOT NULL, line_total DECIMAL(20,2) NOT NULL,
 PRIMARY KEY(environment_id,sale_id,line_no), FOREIGN KEY(environment_id,sale_id) REFERENCES fin_sales(environment_id,id), CONSTRAINT fin_line_values CHECK(quantity>0 AND unit_price>=0 AND line_total>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fin_expenses (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, currency_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 supplier_id VARCHAR(64) COLLATE utf8mb4_bin NULL, total DECIMAL(20,2) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pendiente', row_version BIGINT UNSIGNED NOT NULL DEFAULT 1, incurred_on DATE NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(environment_id,id),
 FOREIGN KEY(environment_id,currency_id) REFERENCES currencies(environment_id,id), CONSTRAINT fin_exp_total CHECK(total>=0), CONSTRAINT fin_exp_status CHECK(status IN ('pendiente','vencido','pagado','anulado')), CONSTRAINT fin_exp_version CHECK(row_version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fin_movements (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, account_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 kind VARCHAR(24) NOT NULL, direction VARCHAR(6) NOT NULL, amount DECIMAL(20,2) NOT NULL, currency_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, movement_on DATE NOT NULL,
 sale_id VARCHAR(64) COLLATE utf8mb4_bin NULL, expense_id VARCHAR(64) COLLATE utf8mb4_bin NULL, reference_id VARCHAR(64) COLLATE utf8mb4_bin NULL, reversal_of VARCHAR(64) COLLATE utf8mb4_bin NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(environment_id,id), UNIQUE(environment_id,kind,reference_id),
 FOREIGN KEY(environment_id,account_id) REFERENCES accounts(environment_id,id), FOREIGN KEY(environment_id,currency_id) REFERENCES currencies(environment_id,id), FOREIGN KEY(environment_id,sale_id) REFERENCES fin_sales(environment_id,id), FOREIGN KEY(environment_id,expense_id) REFERENCES fin_expenses(environment_id,id), FOREIGN KEY(environment_id,reversal_of) REFERENCES fin_movements(environment_id,id),
 CONSTRAINT fin_move_direction CHECK(direction IN ('in','out')), CONSTRAINT fin_move_amount CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fin_sale_allocations (environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, sale_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, movement_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, amount DECIMAL(20,2) NOT NULL, PRIMARY KEY(environment_id,sale_id,movement_id), FOREIGN KEY(environment_id,sale_id) REFERENCES fin_sales(environment_id,id), FOREIGN KEY(environment_id,movement_id) REFERENCES fin_movements(environment_id,id), CONSTRAINT fin_alloc_amount CHECK(amount>0)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fin_expense_allocations (environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, expense_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, movement_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, amount DECIMAL(20,2) NOT NULL, PRIMARY KEY(environment_id,expense_id,movement_id), FOREIGN KEY(environment_id,expense_id) REFERENCES fin_expenses(environment_id,id), FOREIGN KEY(environment_id,movement_id) REFERENCES fin_movements(environment_id,id), CONSTRAINT fin_exp_alloc_amount CHECK(amount>0)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fin_operation_receipts (environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, operation_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, actor_user_id TINYINT UNSIGNED NOT NULL, payload_hash CHAR(64) NOT NULL, result_json JSON NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(environment_id,operation_id), FOREIGN KEY(actor_user_id) REFERENCES auth_users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fin_audit (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, operation_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, actor_user_id TINYINT UNSIGNED NOT NULL, event VARCHAR(64) NOT NULL, payload_json JSON NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), UNIQUE(environment_id,operation_id,event), FOREIGN KEY(actor_user_id) REFERENCES auth_users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
