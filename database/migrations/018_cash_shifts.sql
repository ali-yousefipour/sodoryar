-- 018: tenant-isolated daily cashier sessions, cash adjustments and receipt attribution.
-- Apply after backup and verification of invoice payment migration 017.
CREATE TABLE IF NOT EXISTS cash_registers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_cash_register_name (company_id,name),
 UNIQUE KEY uq_cash_register_tenant (id,company_id),
 CONSTRAINT fk_cash_register_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_shifts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 register_id BIGINT UNSIGNED NOT NULL,
 operator_id BIGINT UNSIGNED NOT NULL,
 opening_cash DECIMAL(20,0) NOT NULL DEFAULT 0,
 declared_cash DECIMAL(20,0) NULL,
 expected_cash DECIMAL(20,0) NULL,
 difference_cash DECIMAL(20,0) NULL,
 started_at DATETIME NOT NULL,
 closed_at DATETIME NULL,
 status ENUM('open','pending','approved','rejected') NOT NULL DEFAULT 'open',
 close_note VARCHAR(500) NULL,
 manager_note VARCHAR(500) NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_cash_shift_tenant(id,company_id),
 INDEX idx_cash_shift_register(company_id,register_id,status,id),
 INDEX idx_cash_shift_operator(company_id,operator_id,status,id),
 CONSTRAINT fk_cash_shift_register FOREIGN KEY(register_id,company_id) REFERENCES cash_registers(id,company_id),
 CONSTRAINT fk_cash_shift_operator FOREIGN KEY(operator_id,company_id) REFERENCES users(id,company_id),
 CONSTRAINT fk_cash_shift_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id),
 CONSTRAINT chk_cash_shift_opening CHECK(opening_cash>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_shift_adjustments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 shift_id BIGINT UNSIGNED NOT NULL,
 direction ENUM('in','out') NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 reason VARCHAR(500) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_cash_adjust_shift(company_id,shift_id),
 CONSTRAINT fk_cash_adjust_shift FOREIGN KEY(shift_id,company_id) REFERENCES cash_shifts(id,company_id),
 CONSTRAINT fk_cash_adjust_actor FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT chk_cash_adjust_positive CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @stmt_sql=IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND COLUMN_NAME='cash_shift_id')=0,
 'ALTER TABLE invoice_payments ADD COLUMN cash_shift_id BIGINT UNSIGNED NULL',
 'SELECT 1'
);
PREPARE sod_stmt FROM @stmt_sql; EXECUTE sod_stmt; DEALLOCATE PREPARE sod_stmt;

SET @stmt_sql=IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND INDEX_NAME='idx_invoice_payment_shift')=0,
 'ALTER TABLE invoice_payments ADD INDEX idx_invoice_payment_shift (company_id,cash_shift_id,payment_method,voided_at)',
 'SELECT 1'
);
PREPARE sod_stmt FROM @stmt_sql; EXECUTE sod_stmt; DEALLOCATE PREPARE sod_stmt;

SET @stmt_sql=IF(
 (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND CONSTRAINT_NAME='fk_invoice_payment_shift')=0,
 'ALTER TABLE invoice_payments ADD CONSTRAINT fk_invoice_payment_shift FOREIGN KEY (cash_shift_id,company_id) REFERENCES cash_shifts(id,company_id)',
 'SELECT 1'
);
PREPARE sod_stmt FROM @stmt_sql; EXECUTE sod_stmt; DEALLOCATE PREPARE sod_stmt;
