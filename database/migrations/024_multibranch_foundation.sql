-- 024: additive multi-branch foundation (no financial postings).
-- Prerequisites: migrations 001-023; verified backup and isolated restore test.
-- Re-runnable: existing IDs, invoice numbers, amounts and payment rows are NEVER rewritten.
-- New invoices remain on the legacy path until branch-aware application validation is deployed.

CREATE TABLE IF NOT EXISTS branches (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id BIGINT UNSIGNED NOT NULL,
 branch_number INT UNSIGNED NOT NULL,
 branch_code VARCHAR(24) NOT NULL,
 name VARCHAR(180) NOT NULL,
 manager_name VARCHAR(180) NULL,
 phone VARCHAR(30) NULL,
 mobile VARCHAR(30) NULL,
 province VARCHAR(100) NULL,
 city VARCHAR(100) NULL,
 address TEXT NULL,
 postal_code VARCHAR(20) NULL,
 notes TEXT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_branches_company_number(company_id,branch_number),
 UNIQUE KEY uq_branches_company_code(company_id,branch_code),
 UNIQUE KEY uq_branches_id_company(id,company_id),
 CONSTRAINT fk_branches_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serializing this migration across deploy nodes is required; unique keys prevent duplicate branch 1.
INSERT INTO branches(company_id,branch_number,branch_code,name,is_default,is_active)
SELECT c.id,1,'001','شعبه مرکزی',1,1 FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM branches b WHERE b.company_id=c.id AND b.branch_number=1);

CREATE TABLE IF NOT EXISTS user_branch_assignments (
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,user_id,branch_id),
 INDEX idx_uba_branch(company_id,branch_id,user_id),
 CONSTRAINT fk_uba_user FOREIGN KEY(user_id,company_id) REFERENCES users(id,company_id),
 CONSTRAINT fk_uba_branch FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO user_branch_assignments(company_id,user_id,branch_id,is_default,is_active)
SELECT u.company_id,u.id,b.id,1,1 FROM users u
JOIN branches b ON b.company_id=u.company_id AND b.branch_number=1
WHERE u.company_id IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM user_branch_assignments a
                WHERE a.company_id=u.company_id AND a.user_id=u.id AND a.branch_id=b.id);

CREATE TABLE IF NOT EXISTS banks (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL,
 bank_code VARCHAR(32) NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_banks_name(name),
 UNIQUE KEY uq_banks_code(bank_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_branch_assignments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 terminal_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 effective_from DATETIME NOT NULL,
 effective_until DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pba_identity(company_id,terminal_id,effective_from),
 INDEX idx_pba_valid(company_id,branch_id,effective_from,effective_until),
 CONSTRAINT fk_pba_terminal FOREIGN KEY(terminal_id) REFERENCES pos_settings(id),
 CONSTRAINT fk_pba_branch FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id),
 CONSTRAINT chk_pba_interval CHECK(effective_until IS NULL OR effective_until>effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historical attribution of existing terminals starts at a known migration boundary;
-- never assert invented earlier assignment intervals.
INSERT INTO pos_branch_assignments(company_id,terminal_id,branch_id,effective_from)
SELECT p.company_id,p.id,b.id,'2026-11-25 00:00:00' FROM pos_settings p
JOIN branches b ON b.company_id=p.company_id AND b.branch_number=1
WHERE NOT EXISTS (SELECT 1 FROM pos_branch_assignments a
                  WHERE a.company_id=p.company_id AND a.terminal_id=p.id);

-- Additive nullable columns allow old application code to continue operating during rollout.
SET @ddl=IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='branch_id')=0,
 'ALTER TABLE invoices ADD COLUMN branch_id BIGINT UNSIGNED NULL','SELECT 1');
PREPARE branch_stmt FROM @ddl; EXECUTE branch_stmt; DEALLOCATE PREPARE branch_stmt;

SET @ddl=IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND COLUMN_NAME='branch_id')=0,
 'ALTER TABLE invoice_payments ADD COLUMN branch_id BIGINT UNSIGNED NULL','SELECT 1');
PREPARE branch_stmt FROM @ddl; EXECUTE branch_stmt; DEALLOCATE PREPARE branch_stmt;

SET @ddl=IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_registers' AND COLUMN_NAME='branch_id')=0,
 'ALTER TABLE cash_registers ADD COLUMN branch_id BIGINT UNSIGNED NULL','SELECT 1');
PREPARE branch_stmt FROM @ddl; EXECUTE branch_stmt; DEALLOCATE PREPARE branch_stmt;

-- Only missing branch attribution is filled; no invoice/payment cash movement is created.
UPDATE invoices i JOIN branches b ON b.company_id=i.company_id AND b.branch_number=1
SET i.branch_id=b.id WHERE i.branch_id IS NULL;
UPDATE invoice_payments p JOIN branches b ON b.company_id=p.company_id AND b.branch_number=1
SET p.branch_id=b.id WHERE p.branch_id IS NULL;
UPDATE cash_registers r JOIN branches b ON b.company_id=r.company_id AND b.branch_number=1
SET r.branch_id=b.id WHERE r.branch_id IS NULL;

-- Enforce same-company branch references, including old records.
SET @ddl=IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_invoices_branch')=0,
 'ALTER TABLE invoices ADD INDEX idx_invoices_branch(company_id,branch_id,created_at)','SELECT 1');
PREPARE branch_stmt FROM @ddl; EXECUTE branch_stmt; DEALLOCATE PREPARE branch_stmt;
SET @ddl=IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND INDEX_NAME='idx_payments_branch')=0,
 'ALTER TABLE invoice_payments ADD INDEX idx_payments_branch(company_id,branch_id,paid_at)','SELECT 1');
PREPARE branch_stmt FROM @ddl; EXECUTE branch_stmt; DEALLOCATE PREPARE branch_stmt;
SET @ddl=IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_registers' AND INDEX_NAME='idx_registers_branch')=0,
 'ALTER TABLE cash_registers ADD INDEX idx_registers_branch(company_id,branch_id)','SELECT 1');
PREPARE branch_stmt FROM @ddl; EXECUTE branch_stmt; DEALLOCATE PREPARE branch_stmt;

SET @ddl=IF(
 (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND CONSTRAINT_NAME='fk_invoice_branch')=0,
 'ALTER TABLE invoices ADD CONSTRAINT fk_invoice_branch FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id)','SELECT 1');
PREPARE branch_stmt FROM @ddl; EXECUTE branch_stmt; DEALLOCATE PREPARE branch_stmt;
SET @ddl=IF(
 (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND CONSTRAINT_NAME='fk_payment_branch')=0,
 'ALTER TABLE invoice_payments ADD CONSTRAINT fk_payment_branch FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id)','SELECT 1');
PREPARE branch_stmt FROM @ddl; EXECUTE branch_stmt; DEALLOCATE PREPARE branch_stmt;
SET @ddl=IF(
 (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='cash_registers' AND CONSTRAINT_NAME='fk_cash_register_branch')=0,
 'ALTER TABLE cash_registers ADD CONSTRAINT fk_cash_register_branch FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id)','SELECT 1');
PREPARE branch_stmt FROM @ddl; EXECUTE branch_stmt; DEALLOCATE PREPARE branch_stmt;

-- IMPORTANT: historical invoice_payments.branch_id denotes the receiving branch.
-- Application must set branch_id explicitly for new cross-branch collections.
-- After deploying branch-aware writes, audit NULL branch_ids and reconcile before NOT NULL enforcement.
