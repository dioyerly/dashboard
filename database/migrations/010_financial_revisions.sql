-- Commercial revision history for sales and expenses. Append-only by permission: the application user gets INSERT and SELECT only.
-- Cash movements are never stored here; compensations use fin_movements.reversal_of (existing column, migration 007).
CREATE TABLE fin_sale_revisions (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, sale_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 revision_no INT UNSIGNED NOT NULL, operation_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, kind VARCHAR(24) NOT NULL,
 previous_total DECIMAL(20,2) NULL, new_total DECIMAL(20,2) NULL, amount DECIMAL(20,2) NULL,
 status_before VARCHAR(20) NOT NULL, status_after VARCHAR(20) NOT NULL, collected_at_revision DECIMAL(20,2) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,sale_id,revision_no), UNIQUE(environment_id,operation_id),
 FOREIGN KEY(environment_id,sale_id) REFERENCES fin_sales(environment_id,id),
 CONSTRAINT fin_sale_rev_kind CHECK(kind IN ('edit','void','refund_commercial')),
 CONSTRAINT fin_sale_rev_amount CHECK(amount IS NULL OR amount>0),
 CONSTRAINT fin_sale_rev_status CHECK(status_before IN ('valida','anulada') AND status_after IN ('valida','anulada')),
 CONSTRAINT fin_sale_rev_collected CHECK(collected_at_revision>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fin_expense_revisions (
 environment_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, expense_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 revision_no INT UNSIGNED NOT NULL, operation_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, kind VARCHAR(24) NOT NULL,
 previous_total DECIMAL(20,2) NOT NULL, new_total DECIMAL(20,2) NOT NULL,
 status_before VARCHAR(20) NOT NULL, status_after VARCHAR(20) NOT NULL, paid_at_revision DECIMAL(20,2) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(environment_id,expense_id,revision_no), UNIQUE(environment_id,operation_id),
 FOREIGN KEY(environment_id,expense_id) REFERENCES fin_expenses(environment_id,id),
 CONSTRAINT fin_exp_rev_kind CHECK(kind IN ('edit')),
 CONSTRAINT fin_exp_rev_status CHECK(status_before IN ('pendiente','vencido','pagado','anulado') AND status_after IN ('pendiente','vencido','pagado','anulado')),
 CONSTRAINT fin_exp_rev_paid CHECK(paid_at_revision>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
