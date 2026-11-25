-- 026: additive, re-runnable expense attribution; no financial postings. Apply after 025.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND COLUMN_NAME='expense_branch_id')=0,
 'ALTER TABLE cash_shift_adjustments ADD COLUMN expense_branch_id BIGINT UNSIGNED NULL','SELECT 1');
PREPARE expense_stmt FROM @ddl; EXECUTE expense_stmt; DEALLOCATE PREPARE expense_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND COLUMN_NAME='expense_token')=0,
 'ALTER TABLE cash_shift_adjustments ADD COLUMN expense_token CHAR(64) NULL','SELECT 1');
PREPARE expense_stmt FROM @ddl; EXECUTE expense_stmt; DEALLOCATE PREPARE expense_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND INDEX_NAME='uq_cash_expense_request')=0,
 'ALTER TABLE cash_shift_adjustments ADD UNIQUE KEY uq_cash_expense_request(company_id,expense_token)','SELECT 1');
PREPARE expense_stmt FROM @ddl; EXECUTE expense_stmt; DEALLOCATE PREPARE expense_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND INDEX_NAME='idx_cash_expense_branch')=0,
 'ALTER TABLE cash_shift_adjustments ADD INDEX idx_cash_expense_branch(company_id,expense_branch_id,created_at)','SELECT 1');
PREPARE expense_stmt FROM @ddl; EXECUTE expense_stmt; DEALLOCATE PREPARE expense_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND CONSTRAINT_NAME='fk_cash_expense_branch')=0,
 'ALTER TABLE cash_shift_adjustments ADD CONSTRAINT fk_cash_expense_branch FOREIGN KEY(expense_branch_id,company_id) REFERENCES branches(id,company_id)','SELECT 1');
PREPARE expense_stmt FROM @ddl; EXECUTE expense_stmt; DEALLOCATE PREPARE expense_stmt;
