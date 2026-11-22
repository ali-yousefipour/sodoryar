-- Sodoryar 009: idempotent, tenant-scoped trash metadata for manually entered bill records.
-- Back up the database first. Existing records remain visible; this does NOT delete data.
-- Suitable for MySQL installations that support PREPARE/EXECUTE.
SET @stmt_sql = IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bill_payments' AND COLUMN_NAME='deleted_at')=0,
 'ALTER TABLE bill_payments ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL',
 'SELECT 1'
);
PREPARE sod_stmt FROM @stmt_sql; EXECUTE sod_stmt; DEALLOCATE PREPARE sod_stmt;

SET @stmt_sql = IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bill_payments' AND COLUMN_NAME='deleted_by')=0,
 'ALTER TABLE bill_payments ADD COLUMN deleted_by BIGINT UNSIGNED NULL DEFAULT NULL',
 'SELECT 1'
);
PREPARE sod_stmt FROM @stmt_sql; EXECUTE sod_stmt; DEALLOCATE PREPARE sod_stmt;

SET @stmt_sql = IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bill_payments' AND INDEX_NAME='idx_bill_trash')=0,
 'ALTER TABLE bill_payments ADD INDEX idx_bill_trash (company_id, deleted_at, id)',
 'SELECT 1'
);
PREPARE sod_stmt FROM @stmt_sql; EXECUTE sod_stmt; DEALLOCATE PREPARE sod_stmt;

SET @stmt_sql = IF(
 (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='bill_payments' AND CONSTRAINT_NAME='fk_bill_deleted_by')=0,
 'ALTER TABLE bill_payments ADD CONSTRAINT fk_bill_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id)',
 'SELECT 1'
);
PREPARE sod_stmt FROM @stmt_sql; EXECUTE sod_stmt; DEALLOCATE PREPARE sod_stmt;
